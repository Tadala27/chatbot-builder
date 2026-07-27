<?php

namespace App\Services\Bot;

use App\Jobs\ContinueChatbotFlow;
use App\Models\Api;
use App\Models\Conversation;
use App\Models\ConversationVariable;
use App\Models\CustomVariable;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

class ActionExecutorService
{
    public function __construct(
        private VariableResolver $variableResolver,
        private FunctionExecutor $functionExecutor,
    ) {
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    public function execute(
        Conversation $conversation,
        Dialog $dialog,
        array $action,
        array $variables
    ): ?string {
        $kind = $action['kind'] ?? $action['action_type'] ?? null;

        if (!$kind) {
            Log::warning('ActionExecutorService: action missing kind', [
                'dialog_id' => $dialog->id,
                'action' => $action,
            ]);

            return null;
        }

        return match ($kind) {
            'navigation' => $this->executeNavigation($action),
            'condition' => $this->executeCondition($conversation, $dialog, $action, $variables),
            'variable' => $this->executeVariable($conversation, $action, $variables),
            'api' => $this->executeApiCall($conversation, $dialog, $action, $variables),
            'function' => $this->executeFunction($conversation, $action, $variables),
            'delay' => $this->executeDelay($conversation, $dialog, $action),
            'handoff' => $this->executeHandoff($conversation, $dialog, $action),
            'start_flow' => '__system:start_flow__',
            'go_home' => '__system:go_home__',
            'go_back' => '__system:go_back__',
            'talk_to_agent' => '__system:talk_to_agent__',
            default => null,
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
        $conversation->handOff(
            sourceDialogId: $dialog->id,
            resumeAt: $action['resumeAt'] ?? null,
        );

        return null;
    }

    // ── Condition ─────────────────────────────────────────────────────────────

    private function executeCondition(
        Conversation $conversation,
        Dialog $dialog,
        array $action,
        array $variables
    ): ?string {
        $dbConditions = $action['_db_conditions'] ?? null;

        foreach ($action['branches'] ?? [] as $branchIndex => $branch) {
            $logic = $branch['conditionLogic'] ?? 'AND';

            $conditions = ($dbConditions !== null && $branchIndex === 0)
                ? $dbConditions
                : ($branch['conditions'] ?? []);

            if (empty($conditions)) {
                continue;
            }

            $results = array_map(
                fn ($cond) => $this->evaluateSingleCondition($cond, $variables, $conversation),
                $conditions
            );

            $matched = $logic === 'AND'
                ? !in_array(false, $results, true)
                : in_array(true, $results, true);

            Log::info('Condition branch evaluated', [
                'branch_index' => $branchIndex,
                'logic' => $logic,
                'results' => $results,
                'matched' => $matched,
            ]);

            if ($matched) {
                foreach ($branch['actions'] ?? [] as $branchAction) {
                    $target = $this->execute($conversation, $dialog, $branchAction, $variables);
                    if ($target) {
                        return $target;
                    }
                }

                return null;
            }
        }

        // ELSE / defaultBranch
        foreach ($action['defaultBranch']['actions'] ?? [] as $defaultAction) {
            $target = $this->execute($conversation, $dialog, $defaultAction, $variables);
            if ($target) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Evaluate a single condition against the current variable state.
     *
     * Supported condition types:
     *   - variable        : compare a conversation variable's value
     *   - api_response    : compare a field from the last API response
     *   - saved_response  : compare a dialog option the user selected
     *   - option_selected : alias for saved_response
     *   - user_input      : compare the raw text of the last inbound message
     *
     * Accepts both camelCase (config-embedded) and snake_case (DB ActionCondition) keys.
     */
    private function evaluateSingleCondition(
        array $condition,
        array $variables,
        Conversation $conversation
    ): bool {
        $type = $condition['type'] ?? $condition['condition_type'] ?? 'variable';
        $operator = $condition['operator'] ?? $condition['condition_operator'] ?? 'equals';

        $expectedValue = $condition['value'] ?? $condition['condition_value'] ?? '';

        // For saved_response: if value is null/empty, fall back to 'source'
        // (the option's external_id) — standard pattern when checking "did the
        // user select THIS option?"
        if (
            in_array($type, ['saved_response', 'option_selected'], true)
            && ($expectedValue === null || $expectedValue === '')
        ) {
            $expectedValue = $condition['source'] ?? $condition['variable_key'] ?? '';
        }

        $actualValue = match ($type) {
            // Compare a conversation variable
            'variable' => $variables[$condition['source'] ?? $condition['variable_key'] ?? ''] ?? null,

            // Compare a field from the last API call response
            'api_response' => $this->getNestedValue(
                $conversation->metadata['last_api_response'] ?? [],
                $condition['responsePath'] ?? $condition['response_path'] ?? ''
            ),

            // Compare the option the user selected from a list or button set
            'saved_response',
            'option_selected' => $this->resolveOptionSelection(
                $condition,
                $variables,
                $conversation
            ),

            // Compare the raw text the user just typed.
            // Prefer __current_user_input injected by ChatbotFlowExecutor from
            // the actual in-flight Message object — this is always the message
            // that triggered the current job, regardless of DB ordering.
            // Falls back to querying the latest inbound message when the variable
            // is not present (e.g. when called outside the flow executor context).
            'user_input' => $variables['__current_user_input']
                         ?? $this->getLastUserInput($conversation),

            default => null,
        };

        Log::info('Evaluating condition', [
            'type' => $type,
            'operator' => $operator,
            'expected_value' => $expectedValue,
            'actual_value' => $actualValue,
            'result' => $this->compareValues($actualValue, $expectedValue, $operator),
        ]);

        return $this->compareValues($actualValue, $expectedValue, $operator);
    }

    /**
     * Resolve the saved selection for a saved_response / option_selected condition.
     *
     * Resolution order:
     * 1. option_id (DB ActionCondition row) → find DialogOption by PK
     * 2. source (config-embedded external_id UUID) → find DialogOption by external_id
     * 3. Fallback to last user input
     */
    private function resolveOptionSelection(
        array $condition,
        array $variables,
        Conversation $conversation
    ): ?string {
        // 1. DB option_id reference
        $optionId = $condition['option_id'] ?? null;
        if ($optionId) {
            $option = \App\Models\DialogOption::find($optionId);
            if ($option) {
                $selectedId = $variables["__dialog_{$option->dialog_id}_selection"] ?? null;
                Log::info('saved_response resolved via option_id', [
                    'option_id' => $optionId,
                    'dialog_id' => $option->dialog_id,
                    'selected_id' => $selectedId,
                ]);

                return $selectedId;
            }
        }

        // 2. Config source = option external_id UUID
        $externalId = $condition['source'] ?? null;
        if ($externalId) {
            $option = \App\Models\DialogOption::where('external_id', $externalId)->first();
            if ($option) {
                $selectedId = $variables["__dialog_{$option->dialog_id}_selection"] ?? null;
                Log::info('saved_response resolved via external_id', [
                    'external_id' => $externalId,
                    'dialog_id' => $option->dialog_id,
                    'selected_id' => $selectedId,
                    'match' => $selectedId === $externalId,
                ]);

                return $selectedId;
            }
        }

        // 3. Fallback
        Log::warning('saved_response: could not find DialogOption, falling back to last user input', [
            'option_id' => $optionId,
            'external_id' => $externalId,
        ]);

        return $this->getLastUserInput($conversation);
    }

    // ── Set Variable ──────────────────────────────────────────────────────────

    private function executeVariable(
        Conversation $conversation,
        array $action,
        array $variables
    ): ?string {
        $varName = $action['varName'] ?? null;

        if (!$varName) {
            Log::warning('Set Variable action has no varName', [
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

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
                'var' => $varName,
            ]);

            return null;
        }

        ConversationVariable::updateOrCreate(
            ['conversation_id' => $conversation->id, 'key' => $varName],
            ['value' => $value]
        );

        Log::info('Variable set', [
            'conversation_id' => $conversation->id,
            'variable' => $varName,
            'value' => $value,
        ]);

        return null;
    }

    // ── API Call ──────────────────────────────────────────────────────────────

    private function executeApiCall(
        Conversation $conversation,
        Dialog $dialog,
        array $action,
        array $variables
    ): ?string {
        try {
            $apiConfig = Api::where('name', $action['apiConfigId'])->first();

            if (!$apiConfig) {
                Log::error('API config not found', [
                    'dialog_id' => $dialog->id,
                    'api_config_id' => $action['apiConfigId'] ?? '',
                ]);

                return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
            }

            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $method = strtoupper($apiConfig->method);
            $endpoint = $this->variableResolver->resolve($apiConfig->url, $variables);
            $options = [];

            $headers = $this->safeJsonDecode($apiConfig->headers);
            $formData = $this->safeJsonDecode($apiConfig->form_data);
            $urlEncodedFields = $this->safeJsonDecode($apiConfig->url_encoded_fields);
            $bodyParameters = $this->safeJsonDecode($apiConfig->body_parameters);

            if (!empty($headers)) {
                $options['headers'] = $headers;
            }

            $isMultipart = $apiConfig->content_type === 'multipart/form-data';

            if ($apiConfig->content_type && !$isMultipart) {
                $options['headers']['Content-Type'] = $apiConfig->content_type;
            }

            if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                if ($isMultipart && !empty($formData)) {
                    $multipart = [];
                    foreach ($formData as $field) {
                        if (is_array($field) && isset($field['key'])) {
                            $multipart[] = [
                                'name' => $field['key'],
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
                    $rawBody = (string) $apiConfig->request_body;
                    $resolvedBody = $this->variableResolver->resolve($rawBody, $variables);

                    if (is_array($resolvedBody)) {
                        $options['json'] = $resolvedBody;
                    } else {
                        $decoded = json_decode($resolvedBody, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $options['json'] = $decoded;
                        } else {
                            Log::warning('API call: request_body is not valid JSON after variable resolution', [
                                'dialog_id' => $dialog->id,
                                'api_config' => $apiConfig->name,
                                'body' => $resolvedBody,
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
                $queryParams = [];
                foreach ($bodyParameters as $param) {
                    $key = is_array($param) ? ($param['key'] ?? reset($param)) : $param;
                    if (!empty($key) && is_string($key)) {
                        $queryParams[$key] = $this->variableResolver->resolve(
                            '{{'.$key.'}}',
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
                'method' => $method,
                'endpoint' => $endpoint,
                'options' => $this->sanitizeOptionsForLog($options),
            ]);

            $response = $client->request($method, $endpoint, $options);
            $statusCode = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            Log::info('API call executed', [
                'dialog_id' => $dialog->id,
                'api_config' => $apiConfig->name,
                'endpoint' => $endpoint,
                'method' => $method,
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
                    'last_api_status' => $statusCode,
                ]),
            ]);

            $allDeclaredParams = array_merge($bodyParameters, $this->safeJsonDecode($apiConfig->header_parameters));
            $this->storeResponseAsVariables(
                $conversation,
                $allDeclaredParams,
                is_array($responseBody) ? $responseBody : [],
                $apiConfig->bot_id
            );

            return $this->executeResponseHandlers(
                $conversation,
                $dialog,
                $action,
                $responseBody,
                $variables,
                $statusCode
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            if ($e->hasResponse()) {
                $statusCode = $e->getResponse()->getStatusCode();
                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);

                Log::error('API call HTTP error', [
                    'dialog_id' => $dialog->id,
                    'api_config_id' => $action['apiConfigId'] ?? '',
                    'status_code' => $statusCode,
                    'error' => $e->getMessage(),
                ]);

                return $this->executeResponseHandlers(
                    $conversation,
                    $dialog,
                    $action,
                    $responseBody,
                    $variables,
                    $statusCode
                );
            }

            Log::error('API call network error', [
                'dialog_id' => $dialog->id,
                'api_config_id' => $action['apiConfigId'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
        } catch (\Exception $e) {
            Log::error('API call unexpected error', [
                'dialog_id' => $dialog->id,
                'api_config_id' => $action['apiConfigId'] ?? '',
                'error' => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
        }
    }

    // ── Function ──────────────────────────────────────────────────────────────

    private function executeFunction(
        Conversation $conversation,
        array $action,
        array $variables
    ): ?string {
        $fnId = $action['fnId'] ?? null;
        $resultVar = $action['resultVar'] ?? null;

        if (!$fnId) {
            return null;
        }

        try {
            $paramsJson = $this->variableResolver->resolve($action['paramsRaw'] ?? '{}', $variables);
            $params = json_decode($paramsJson, true) ?? [];
            $result = $this->functionExecutor->execute($fnId, $params);

            if ($resultVar) {
                ConversationVariable::updateOrCreate(
                    ['conversation_id' => $conversation->id, 'key' => $resultVar],
                    ['value' => is_array($result) ? json_encode($result) : (string) $result]
                );
            }
        } catch (\Exception $e) {
            Log::error('Function action failed', [
                'function_id' => $fnId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ── Delay ─────────────────────────────────────────────────────────────────

    private function executeDelay(
        Conversation $conversation,
        Dialog $dialog,
        array $action
    ): ?string {
        $seconds = $action['seconds'] ?? 3;
        $nextDialogId = $action['goTo'] ?? null;

        if ($nextDialogId) {
            $nextDialog = $dialog->botVersion
                ?->dialogs()
                ->where('config->id', $nextDialogId)
                ->first();

            if ($nextDialog) {
                ContinueChatbotFlow::dispatchFor($conversation, $nextDialog, $seconds);
            }
        }

        return null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get the raw text value from the last inbound message.
     *
     * Handles all relevant WhatsApp message types:
     *   - text           : plain text the user typed
     *   - interactive    : button_reply or list_reply selection title
     *   - button         : quick-reply button tap (legacy Cloud API format)
     *
     * Returns null when there is no inbound message or the type is unrecognised.
     * Result is trimmed so leading/trailing whitespace never breaks comparisons.
     */
    private function getLastUserInput(Conversation $conversation): ?string
    {
        $lastMessage = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest('sent_at')
            ->first();

        if (!$lastMessage) {
            return null;
        }

        $content = $lastMessage->content;
        if (!is_array($content)) {
            $content = json_decode((string) $content, true) ?? [];
        }

        $raw = match ($lastMessage->message_type) {
            'text' => $content['text'] ?? null,

            'interactive' => $content['response']['title']
                ?? $content['response']['id']
                ?? $content['button_reply']['title']
                ?? $content['list_reply']['title']
                ?? null,

            // Legacy WhatsApp quick-reply button tap
            'button' => $content['button']['text']
                ?? $content['text']
                ?? null,

            default => null,
        };

        return $raw !== null ? trim((string) $raw) : null;
    }

    private function getNestedValue(array $data, string $path): mixed
    {
        if (empty($path)) {
            return null;
        }

        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Compare two values with the given operator.
     *
     * String comparisons for equals/not_equals/contains/starts_with/ends_with
     * are case-insensitive and trim-safe. This is intentional for user_input
     * conditions — "YES", "Yes", and "yes" should all match "yes".
     *
     * Numeric comparisons cast both sides to float via is_numeric() guard.
     */
    public function compareValues(mixed $left, mixed $right, string $operator): bool
    {
        return match ($operator) {
            'equals', '==' => is_string($left) && is_string($right)
                ? mb_strtolower(trim($left)) === mb_strtolower(trim($right))
                : $left == $right,

            'not_equals', '!=' => is_string($left) && is_string($right)
                ? mb_strtolower(trim($left)) !== mb_strtolower(trim($right))
                : $left != $right,

            'greater_than',          '>' => is_numeric($left) && is_numeric($right) ? (float) $left > (float) $right : false,
            'less_than',             '<' => is_numeric($left) && is_numeric($right) ? (float) $left < (float) $right : false,
            'greater_than_or_equal', '>=' => is_numeric($left) && is_numeric($right) ? (float) $left >= (float) $right : false,
            'less_than_or_equal',    '<=' => is_numeric($left) && is_numeric($right) ? (float) $left <= (float) $right : false,

            'contains' => is_string($left) && str_contains(mb_strtolower($left), mb_strtolower((string) $right)),
            'not_contains' => is_string($left) && !str_contains(mb_strtolower($left), mb_strtolower((string) $right)),
            'starts_with' => is_string($left) && str_starts_with(mb_strtolower($left), mb_strtolower((string) $right)),
            'ends_with' => is_string($left) && str_ends_with(mb_strtolower($left), mb_strtolower((string) $right)),

            'is_empty' => $left === null || $left === '' || $left === [],
            'is_not_empty', 'not_empty' => $left !== null && $left !== '' && $left !== [],

            'in_array' => is_array($left) && in_array($right, $left),
            'not_in_array' => is_array($left) && !in_array($right, $left),

            default => false,
        };
    }

    private function executeResponseHandlers(
        Conversation $conversation,
        Dialog $dialog,
        array $action,
        mixed $responseBody,
        array $variables,
        ?int $httpStatusCode = null
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
                    if ($target) {
                        return $target;
                    }
                }

                return null;
            }
        }

        return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
    }

    private function executeDefaultActions(
        Conversation $conversation,
        Dialog $dialog,
        array $action,
        array $variables
    ): ?string {
        foreach ($action['defaultActions'] ?? [] as $defaultAction) {
            $target = $this->execute($conversation, $dialog, $defaultAction, $variables);
            if ($target) {
                return $target;
            }
        }

        return null;
    }

    private function evaluateResponseCondition(
        array $condition,
        array $responseBody,
        ?int $httpStatusCode = null
    ): bool {
        $field = $condition['responseField'] ?? 'status';
        $path = $condition['responsePath'] ?? '';

        $actualValue = match ($field) {
            'status' => $httpStatusCode
                ?? $responseBody['status']
                ?? $responseBody['statusCode']
                ?? null,
            'body' => $this->getNestedValue($responseBody, $path),
            default => $this->getNestedValue($responseBody, $path),
        };

        return $this->compareValues(
            $actualValue !== null ? (string) $actualValue : null,
            $condition['value'] ?? '',
            $condition['operator'] ?? 'equals'
        );
    }

    private function storeResponseAsVariables(
        Conversation $conversation,
        array $declaredParams,
        array $responseBody,
        string $botId
    ): void {
        if (empty($declaredParams) || empty($responseBody)) {
            return;
        }

        $paramKeys = [];
        foreach ($declaredParams as $param) {
            $raw = is_array($param) ? ($param['key'] ?? reset($param)) : (string) $param;
            $key = trim((string) $raw, '{}');
            if ($key !== '') {
                $paramKeys[] = $key;
            }
        }

        if (empty($paramKeys)) {
            return;
        }

        $customVars = CustomVariable::where('bot_id', $botId)
            ->whereIn('key', $paramKeys)
            ->pluck('id', 'key');

        $rows = [];
        $now = now();

        foreach ($paramKeys as $key) {
            if (!array_key_exists($key, $responseBody)) {
                continue;
            }

            $value = $responseBody[$key];
            $scalarValue = is_array($value) || is_object($value)
                ? json_encode($value)
                : (string) $value;

            $rows[] = [
                'conversation_id' => $conversation->id,
                'key' => $key,
                'value' => $scalarValue,
                'custom_variable_id' => $customVars->get($key),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        ConversationVariable::upsert(
            $rows,
            uniqueBy: ['conversation_id', 'key'],
            update: ['value', 'custom_variable_id', 'updated_at']
        );

        Log::info('API response variables batched', [
            'conversation_id' => $conversation->id,
            'count' => count($rows),
            'keys' => array_column($rows, 'key'),
        ]);
    }

    private function safeJsonDecode(mixed $data): array
    {
        if (is_string($data) && !empty($data)) {
            $decoded = json_decode($data, true);

            return json_last_error() === JSON_ERROR_NONE ? ($decoded ?? []) : [];
        }

        return is_array($data) ? $data : [];
    }

    private function sanitizeOptionsForLog(array $options): array
    {
        $sanitized = $options;

        if (isset($sanitized['headers']['Authorization'])) {
            $sanitized['headers']['Authorization'] = '[REDACTED]';
        }
        if (isset($sanitized['headers']['api-key'])) {
            $sanitized['headers']['api-key'] = '[REDACTED]';
        }
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
}