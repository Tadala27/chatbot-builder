<?php

namespace App\Services\Bot;

use App\Models\Api;
use App\Models\Conversation;
use App\Models\ConversationVariable;
use App\Models\CustomVariable;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

/**
 * Executes individual action configs produced by the flow builder.
 *
 * Actions are loaded from the dialog_actions table via getDialogActions() in
 * ChatbotFlowExecutor. Each action's config JSON is merged with ['kind' => action_type].
 *
 * Condition actions receive a '_db_conditions' key from ActionCondition rows.
 *
 * saved_response conditions:
 *   - Resolve via __dialog_{id}_selection conversation variables set by
 *     ChatbotFlowExecutor::processUserInput() when DialogOption.save_response = true.
 *   - The condition's 'source' field holds the option's external_id (UUID).
 *   - The condition's 'value' may be null; in that case 'source' is used as
 *     the expected value (i.e. "was THIS option selected?").
 */
class ActionExecutorService
{
    public function __construct(
        private VariableResolver $variableResolver,
        private FunctionExecutor $functionExecutor,
    ) {}

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function execute(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        $kind = $action['kind'] ?? $action['action_type'] ?? null;

        if (!$kind) {
            Log::warning('ActionExecutorService: action missing kind', [
                'dialog_id' => $dialog->id,
                'action'    => $action,
            ]);
            return null;
        }

        return match ($kind) {
            'navigation'    => $this->executeNavigation($action),
            'condition'     => $this->executeCondition($conversation, $dialog, $action, $variables),
            'variable'      => $this->executeVariable($conversation, $action, $variables),
            'api'           => $this->executeApiCall($conversation, $dialog, $action, $variables),
            'function'      => $this->executeFunction($conversation, $action, $variables),
            'delay'         => $this->executeDelay($conversation, $dialog, $action),
            'handoff'       => $this->executeHandoff($conversation, $dialog, $action),
            'start_flow'    => '__system:start_flow__',
            'go_home'       => '__system:go_home__',
            'go_back'       => '__system:go_back__',
            'talk_to_agent' => '__system:talk_to_agent__',
            default         => null,
        };
    }

    // =========================================================================
    // ACTION HANDLERS
    // =========================================================================

    // ── Navigation ────────────────────────────────────────────────────────────

    private function executeNavigation(array $action): ?string
    {
        return $action['goTo'] ?? null;
    }

    // ── Handoff ───────────────────────────────────────────────────────────────

    private function executeHandoff(Conversation $conversation, Dialog $dialog, array $action): ?string
    {
        $conversation->update([
            'status'   => 'handed_off',
            'metadata' => array_merge($conversation->metadata ?? [], [
                'handoff_source_dialog' => $dialog->id,
                'handoff_resume_at'     => $action['resumeAt'] ?? null,
            ]),
        ]);

        return null;
    }

    // ── Condition ─────────────────────────────────────────────────────────────

    private function executeCondition(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        // _db_conditions is a flat list from ActionCondition rows injected by
        // getDialogActions(). When present, use them for the first branch.
        // For multi-branch flows the branches array in config is authoritative.
        $dbConditions = $action['_db_conditions'] ?? null;

        foreach ($action['branches'] ?? [] as $branchIndex => $branch) {
            $logic = $branch['conditionLogic'] ?? 'AND';

            // Use DB conditions for branch 0 when available (single-branch common case).
            // Multi-branch: each branch uses its own config-embedded conditions.
            if ($dbConditions !== null && $branchIndex === 0) {
                $conditions = $dbConditions;
            } else {
                $conditions = $branch['conditions'] ?? [];
            }

            if (empty($conditions)) {
                continue;
            }

            $results = array_map(
                fn($cond) => $this->evaluateSingleCondition($cond, $variables, $conversation),
                $conditions
            );

            $matched = $logic === 'AND'
                ? !in_array(false, $results, true)
                : in_array(true, $results, true);

            Log::info('Condition branch evaluated', [
                'branch_index' => $branchIndex,
                'logic'        => $logic,
                'results'      => $results,
                'matched'      => $matched,
            ]);

            if ($matched) {
                foreach ($branch['actions'] ?? [] as $branchAction) {
                    $target = $this->execute($conversation, $dialog, $branchAction, $variables);
                    if ($target) return $target;
                }
                return null; // matched but no navigation action in branch
            }
        }

        // ── ELSE (defaultBranch) ──────────────────────────────────────────────
        foreach ($action['defaultBranch']['actions'] ?? [] as $defaultAction) {
            $target = $this->execute($conversation, $dialog, $defaultAction, $variables);
            if ($target) return $target;
        }

        return null;
    }

    /**
     * Evaluate a single condition against the current variable state.
     *
     * Accepts both camelCase (config-embedded) and snake_case (DB ActionCondition) keys.
     */
    private function evaluateSingleCondition(
        array        $condition,
        array        $variables,
        Conversation $conversation
    ): bool {
        $type     = $condition['type']     ?? $condition['condition_type']     ?? 'variable';
        $operator = $condition['operator'] ?? $condition['condition_operator'] ?? 'equals';

        // For saved_response: if 'value' is null/empty, compare against 'source'
        // (the option's external_id). This is the standard pattern when the builder
        // sets up "did the user select THIS option?" conditions.
        $expectedValue = $condition['value'] ?? $condition['condition_value'] ?? '';
        if (
            in_array($type, ['saved_response', 'option_selected'], true) &&
            ($expectedValue === null || $expectedValue === '')
        ) {
            $expectedValue = $condition['source'] ?? $condition['variable_key'] ?? '';
        }

        $actualValue = match ($type) {
            'variable' => $variables[$condition['source'] ?? $condition['variable_key'] ?? ''] ?? null,

            'api_response' => $this->getNestedValue(
                $conversation->metadata['last_api_response'] ?? [],
                $condition['responsePath'] ?? $condition['response_path'] ?? ''
            ),

            'saved_response',
            'option_selected' => $this->resolveOptionSelection(
                $condition,
                $variables,
                $conversation
            ),

            default => null,
        };

        Log::info('Evaluating condition', [
            'type'           => $type,
            'operator'       => $operator,
            'expected_value' => $expectedValue,
            'actual_value'   => $actualValue,
            'result'         => $this->compareValues($actualValue, $expectedValue, $operator),
        ]);

        return $this->compareValues($actualValue, $expectedValue, $operator);
    }

    /**
     * Resolve the saved selection for a saved_response / option_selected condition.
     *
     * Resolution order:
     * 1. If condition has option_id (DB ActionCondition row): find DialogOption by PK,
     *    then read __dialog_{dialog_id}_selection from variables.
     * 2. If condition has source (config-embedded external_id UUID): find DialogOption
     *    by external_id, then read __dialog_{dialog_id}_selection from variables.
     * 3. Fallback to last user input (least reliable).
     *
     * The returned value is the stored selection's external_id, which compareValues
     * then checks against expectedValue (also the option's external_id).
     */
    private function resolveOptionSelection(
        array        $condition,
        array        $variables,
        Conversation $conversation
    ): ?string {
        // ── 1. DB option_id reference ─────────────────────────────────────────
        $optionId = $condition['option_id'] ?? null;
        if ($optionId) {
            $option = \App\Models\DialogOption::find($optionId);
            if ($option) {
                $selectedId = $variables["__dialog_{$option->dialog_id}_selection"] ?? null;

                Log::info('saved_response resolved via option_id', [
                    'option_id'   => $optionId,
                    'dialog_id'   => $option->dialog_id,
                    'selected_id' => $selectedId,
                ]);

                return $selectedId;
            }
        }

        // ── 2. Config source = option external_id UUID ────────────────────────
        $externalId = $condition['source'] ?? null;
        if ($externalId) {
            $option = \App\Models\DialogOption::where('external_id', $externalId)->first();
            if ($option) {
                $selectedId = $variables["__dialog_{$option->dialog_id}_selection"] ?? null;

                Log::info('saved_response resolved via external_id', [
                    'external_id' => $externalId,
                    'dialog_id'   => $option->dialog_id,
                    'selected_id' => $selectedId,
                    'match'       => $selectedId === $externalId,
                ]);

                return $selectedId;
            }
        }

        // ── 3. Fallback ───────────────────────────────────────────────────────
        Log::warning('saved_response: could not find DialogOption, falling back to last user input', [
            'option_id'   => $optionId,
            'external_id' => $externalId,
        ]);

        return $this->getLastUserInput($conversation);
    }

    // ── Set Variable ──────────────────────────────────────────────────────────

    private function executeVariable(
        Conversation $conversation,
        array        $action,
        array        $variables
    ): ?string {
        $varName = $action['varName'] ?? null;

        if (!$varName) {
            Log::warning('Set Variable action has no varName', [
                'conversation_id' => $conversation->id,
            ]);
            return null;
        }

        // Priority:
        // 1. _resolvedInput — injected by processUserInput with the current message text
        // 2. action['value'] — configured expression e.g. "$otherVar" or "f(x) today"
        // 3. getLastUserInput() — fallback
        if (isset($action['_resolvedInput'])) {
            $value = $action['_resolvedInput'];
        } elseif (isset($action['value']) && $action['value'] !== '') {
            $value = $this->variableResolver->resolve((string) $action['value'], $variables);
        } else {
            $value = $this->getLastUserInput($conversation);
        }

        if ($value === null) {
            Log::info('Set Variable: no value found', [
                'conversation_id' => $conversation->id,
                'var'             => $varName,
            ]);
            return null;
        }

        \App\Models\ConversationVariable::updateOrCreate(
            ['conversation_id' => $conversation->id, 'key' => $varName],
            ['value' => $value]
        );

        Log::info('Variable set', [
            'conversation_id' => $conversation->id,
            'variable'        => $varName,
            'value'           => $value,
        ]);

        return null;
    }

    private function executeApiCall(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        try {
            $apiConfig = Api::where('name', $action['apiConfigId'])->first();

            if (!$apiConfig) {
                Log::error('API config not found', [
                    'dialog_id'     => $dialog->id,
                    'api_config_id' => $action['apiConfigId'] ?? '',
                ]);
                return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
            }

            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $method   = strtoupper($apiConfig->method);
            $endpoint = $this->variableResolver->resolve($apiConfig->url, $variables);
            $options  = [];

            // Cast-safe decoding — model casts headers/form_data/etc. to array,
            // but request_body is a plain text column.
            $headers          = $this->safeJsonDecode($apiConfig->headers);
            $formData         = $this->safeJsonDecode($apiConfig->form_data);
            $urlEncodedFields = $this->safeJsonDecode($apiConfig->url_encoded_fields);
            $bodyParameters   = $this->safeJsonDecode($apiConfig->body_parameters);

            // ── Build headers ─────────────────────────────────────────────────────
            // For multipart, do NOT set Content-Type — Guzzle sets it with the
            // boundary automatically. For all other types, honour content_type.
            if (!empty($headers)) {
                $options['headers'] = $headers;
            }

            $isMultipart = $apiConfig->content_type === 'multipart/form-data';

            if ($apiConfig->content_type && !$isMultipart) {
                $options['headers']['Content-Type'] = $apiConfig->content_type;
            }

            // ── Build body ────────────────────────────────────────────────────────
            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {

                if ($isMultipart && !empty($formData)) {
                    $multipart = [];
                    foreach ($formData as $field) {
                        if (is_array($field) && isset($field['key'])) {
                            $multipart[] = [
                                'name'     => $field['key'],
                                'contents' => $this->variableResolver->resolve(
                                    (string) ($field['value'] ?? ''),
                                    $variables
                                ),
                            ];
                        }
                    }
                    if (!empty($multipart)) {
                        $options['multipart'] = $multipart;
                    }
                } elseif (!empty($apiConfig->request_body)) {
                    // request_body is a text column — always a string from the DB.
                    $rawBody     = (string) $apiConfig->request_body;
                    $resolvedBody = $this->variableResolver->resolve($rawBody, $variables);

                    // resolve() should return string, but guard defensively.
                    if (is_array($resolvedBody)) {
                        $options['json'] = $resolvedBody;
                    } else {
                        $decoded = json_decode($resolvedBody, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $options['json'] = $decoded;
                        } else {
                            Log::warning('API call: request_body is not valid JSON after variable resolution', [
                                'dialog_id'  => $dialog->id,
                                'api_config' => $apiConfig->name,
                                'body'       => $resolvedBody,
                            ]);
                        }
                    }
                } elseif (!empty($urlEncodedFields)) {
                    $formParams = [];
                    foreach ($urlEncodedFields as $field) {
                        if (is_array($field) && isset($field['key'])) {
                            $formParams[$field['key']] = $this->variableResolver->resolve(
                                (string) ($field['value'] ?? ''),
                                $variables
                            );
                        }
                    }
                    if (!empty($formParams)) {
                        $options['form_params'] = $formParams;
                    }
                }
            } else {
                // ── GET / DELETE / HEAD: query string ─────────────────────────────
                // Only send parameters explicitly declared in body_parameters.
                // Sending ALL conversation variables as query params is a data-leak risk.
                $queryParams = [];
                foreach ($bodyParameters as $param) {
                    $key = is_array($param) ? ($param['key'] ?? reset($param)) : $param;
                    if (!empty($key) && is_string($key)) {
                        $queryParams[$key] = $this->variableResolver->resolve(
                            '{{' . $key . '}}',
                            $variables
                        );
                    }
                }
                if (!empty($queryParams)) {
                    $options['query'] = $queryParams;
                }
            }

            Log::debug('API request details', [
                'dialog_id' => $dialog->id,
                'method'    => $method,
                'endpoint'  => $endpoint,
                'options'   => $this->sanitizeOptionsForLog($options),
            ]);

            $response     = $client->request($method, $endpoint, $options);
            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            Log::info('API call executed', [
                'dialog_id'   => $dialog->id,
                'api_config'  => $apiConfig->name,
                'endpoint'    => $endpoint,
                'method'      => $method,
                'status_code' => $statusCode,
            ]);

            if (!empty($action['apiResultVar'])) {
                ConversationVariable::updateOrCreate(
                    ['conversation_id' => $conversation->id, 'key' => $action['apiResultVar']],
                    ['value' => is_array($responseBody) ? json_encode($responseBody) : (string) $responseBody]
                );
            }

            $conversation->update([
                'metadata' => array_merge($conversation->metadata ?? [], [
                    'last_api_response' => $responseBody,
                    'last_api_status'   => $statusCode,
                ]),
            ]);

            // ── Store response fields as conversation variables ────────────────────
            // Merge body_parameters and header_parameters — both can declare mappable keys.
            $allDeclaredParams = array_merge($bodyParameters, $this->safeJsonDecode($apiConfig->header_parameters));
            $this->storeResponseAsVariables(
                $conversation,
                $allDeclaredParams,
                is_array($responseBody) ? $responseBody : [],
                $apiConfig->bot_id
            );
            // ──────────────────────────────────────────────────────────────────────

            return $this->executeResponseHandlers(
                $conversation,
                $dialog,
                $action,
                $responseBody,
                $variables,
                $statusCode
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $responseBody = null;
            $statusCode   = null;

            if ($e->hasResponse()) {
                // HTTP error path (RequestException with response)
                $statusCode   = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                Log::error('API call HTTP error', [
                    'dialog_id'     => $dialog->id,
                    'api_config_id' => $action['apiConfigId'] ?? '',
                    'status_code'   => $statusCode,
                    'error'         => $e->getMessage(),
                ]);

                return $this->executeResponseHandlers(
                    $conversation,
                    $dialog,
                    $action,
                    $responseBody,
                    $variables,
                    $statusCode    // ← pass it
                );
            }

            Log::error('API call network error', [
                'dialog_id'     => $dialog->id,
                'api_config_id' => $action['apiConfigId'] ?? '',
                'error'         => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
        } catch (\Exception $e) {
            Log::error('API call unexpected error', [
                'dialog_id'     => $dialog->id,
                'api_config_id' => $action['apiConfigId'] ?? '',
                'error'         => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
        }
    }

    /**
     * Safely decode JSON data that could be either string or array
     * 
     * @param mixed $data
     * @return array
     */
    private function safeJsonDecode($data): array
    {
        if (is_string($data) && !empty($data)) {
            $decoded = json_decode($data, true);
            return json_last_error() === JSON_ERROR_NONE ? ($decoded ?? []) : [];
        }

        return is_array($data) ? $data : [];
    }

    /**
     * Sanitize options array for logging (remove sensitive data)
     * 
     * @param array $options
     * @return array
     */
    private function sanitizeOptionsForLog(array $options): array
    {
        $sanitized = $options;

        // Remove sensitive headers
        if (isset($sanitized['headers']['Authorization'])) {
            $sanitized['headers']['Authorization'] = '[REDACTED]';
        }
        if (isset($sanitized['headers']['api-key'])) {
            $sanitized['headers']['api-key'] = '[REDACTED]';
        }

        // Remove sensitive form data
        if (isset($sanitized['multipart'])) {
            foreach ($sanitized['multipart'] as &$part) {
                if (in_array($part['name'], ['password', 'token', 'secret', 'api_key'])) {
                    $part['contents'] = '[REDACTED]';
                }
            }
        }

        if (isset($sanitized['form_params'])) {
            foreach (['password', 'token', 'secret', 'api_key'] as $sensitive) {
                if (isset($sanitized['form_params'][$sensitive])) {
                    $sanitized['form_params'][$sensitive] = '[REDACTED]';
                }
            }
        }

        return $sanitized;
    }

private function storeResponseAsVariables(
    Conversation $conversation,
    array                    $declaredParams,
    array                    $responseBody,
    int                      $botId
): void {
    if (empty($declaredParams) || empty($responseBody)) {
        return;
    }
 
    // Extract declared keys, stripping any {{ }} wrappers the builder adds.
    $paramKeys = [];
    foreach ($declaredParams as $param) {
        $raw = is_array($param) ? ($param['key'] ?? reset($param)) : (string) $param;
        $key = trim((string) $raw, '{}');
        if ($key !== '') {
            $paramKeys[] = $key;
        }
    }
 
    if (empty($paramKeys)) return;
 
    // Lookup custom variable IDs in one query
    $customVars = CustomVariable::where('bot_id', $botId)
        ->whereIn('key', $paramKeys)
        ->pluck('id', 'key'); // ['memberName' => 5, 'DoJ' => 7]
 
    // Build batch upsert rows
    $rows = [];
    $now  = now();
    foreach ($paramKeys as $key) {
        if (!array_key_exists($key, $responseBody)) continue;
 
        $value = $responseBody[$key];
        $scalarValue = is_array($value) || is_object($value)
            ? json_encode($value)
            : (string) $value;
 
        $rows[] = [
            'conversation_id'    => $conversation->id,
            'key'                => $key,
            'value'              => $scalarValue,
            'custom_variable_id' => $customVars->get($key),
            'created_at'         => $now,
            'updated_at'         => $now,
        ];
    }
 
    if (empty($rows)) return;
 
    // Single upsert — requires the unique index (conversation_id, key) from
    // the migration in file 04.
    ConversationVariable::upsert(
        $rows,
        uniqueBy: ['conversation_id', 'key'],
        update: ['value', 'custom_variable_id', 'updated_at']
    );
 
    Log::info('API response variables batched', [
        'conversation_id' => $conversation->id,
        'count'           => count($rows),
        'keys'            => array_column($rows, 'key'),
    ]);
}
    private function executeResponseHandlers(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        mixed        $responseBody,
        array        $variables,
        ?int         $httpStatusCode = null    // ← add this
    ): ?string {
        $body = is_array($responseBody) ? $responseBody : [];

        foreach ($action['responseHandlers'] ?? [] as $handler) {
            $allMatch = true;
            foreach ($handler['conditions'] ?? [] as $condition) {
                if (!$this->evaluateResponseCondition($condition, $body, $httpStatusCode)) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                foreach ($handler['actions'] ?? [] as $handlerAction) {
                    $target = $this->execute($conversation, $dialog, $handlerAction, $variables);
                    if ($target) return $target;
                }
                return null;
            }
        }

        return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
    }

    private function executeDefaultActions(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        foreach ($action['defaultActions'] ?? [] as $defaultAction) {
            $target = $this->execute($conversation, $dialog, $defaultAction, $variables);
            if ($target) return $target;
        }
        return null;
    }
    private function evaluateResponseCondition(
        array $condition,
        array $responseBody,
        ?int  $httpStatusCode = null     // ← add this
    ): bool {
        $field = $condition['responseField'] ?? 'status';
        $path  = $condition['responsePath'] ?? '';

        $actualValue = match ($field) {
            // Prefer the real HTTP status code; fall back to body fields
            'status' => $httpStatusCode
                ?? $responseBody['status']
                ?? $responseBody['statusCode']
                ?? null,
            'body'   => $this->getNestedValue($responseBody, $path),
            default  => $this->getNestedValue($responseBody, $path),
        };

        return $this->compareValues(
            // Cast to string so "404" == 404 works with strict-ish comparison
            $actualValue !== null ? (string) $actualValue : null,
            $condition['value']    ?? '',
            $condition['operator'] ?? 'equals'
        );
    }
    // ── Function ──────────────────────────────────────────────────────────────

    private function executeFunction(
        Conversation $conversation,
        array        $action,
        array        $variables
    ): ?string {
        $fnId      = $action['fnId']      ?? null;
        $resultVar = $action['resultVar'] ?? null;

        if (!$fnId) return null;

        try {
            $paramsJson = $this->variableResolver->resolve($action['paramsRaw'] ?? '{}', $variables);
            $params     = json_decode($paramsJson, true) ?? [];
            $result     = $this->functionExecutor->execute($fnId, $params);

            if ($resultVar) {
                \App\Models\ConversationVariable::updateOrCreate(
                    ['conversation_id' => $conversation->id, 'key' => $resultVar],
                    ['value' => is_array($result) ? json_encode($result) : (string) $result]
                );
            }
        } catch (\Exception $e) {
            Log::error('Function action failed', [
                'function_id' => $fnId,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ── Delay ─────────────────────────────────────────────────────────────────

    private function executeDelay(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action
    ): ?string {
        $seconds      = $action['seconds'] ?? 3;
        $nextDialogId = $action['goTo']    ?? null;

        if ($nextDialogId) {
            $nextDialog = $dialog->flowVersion
                ?->dialogs()
                ->where('config->id', $nextDialogId)
                ->first();

            if ($nextDialog) {
                \App\Jobs\ContinueChatbotFlow::dispatch($conversation, $nextDialog)
                    ->delay(now()->addSeconds($seconds));
            }
        }

        return null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getLastUserInput(Conversation $conversation): ?string
    {
        $lastMessage = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest()
            ->first();

        if (!$lastMessage) return null;

        return match ($lastMessage->message_type) {
            'text'        => $lastMessage->content['text'] ?? null,
            'interactive' => $lastMessage->content['response']['title']
                ?? $lastMessage->content['response']['id']
                ?? null,
            default       => null,
        };
    }

    private function getNestedValue(array $data, string $path): mixed
    {
        if (empty($path)) return null;

        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) return null;
            $value = $value[$key];
        }

        return $value;
    }

    public function compareValues(mixed $left, mixed $right, string $operator): bool
    {
        return match ($operator) {
            'equals',                '=='  => $left == $right,
            'not_equals',            '!='  => $left != $right,
            'greater_than', '>' => is_numeric($left) && is_numeric($right)? ((float) $left) > ((float) $right): false,
            'less_than',             '<'   => $left <  $right,
            'greater_than_or_equal', '>='  => $left >= $right,
            'less_than_or_equal',    '<='  => $left <= $right,
            'contains'     => is_string($left) && str_contains($left,    (string) $right),
            'not_contains' => is_string($left) && !str_contains($left,   (string) $right),
            'starts_with'  => is_string($left) && str_starts_with($left, (string) $right),
            'ends_with'    => is_string($left) && str_ends_with($left,   (string) $right),
            'is_empty'     => empty($left),
            'is_not_empty',
            'not_empty'    => !empty($left),
            'in_array'     => is_array($left) && in_array($right, $left),
            'not_in_array' => is_array($left) && !in_array($right, $left),
            default        => false,
        };
    }
}