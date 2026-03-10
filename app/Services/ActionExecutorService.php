<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

/**
 * Executes individual action configs produced by the flow builder.
 *
 * Schema changes from old version:
 *  - Takes Dialog instead of FlowNode
 *  - $conversation->setVariable() does NOT exist; variable persistence is
 *    delegated back to ChatbotFlowExecutor::setVariable() via a callback,
 *    or handled directly here via ConversationVariable.
 *  - Delay: resolves next dialog via $dialog->flowVersion->dialogs()->where('config->id', ...)
 *    NOT ->where('uuid', ...) and dispatches ContinueChatbotFlow with Dialog.
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

    /**
     * Execute a single action config array.
     *
     * @return string|null  config.id of the next dialog to navigate to, or null (side-effect only)
     */
    public function execute(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        $kind = $action['kind'] ?? $action['action_type'] ?? null;

        if (!$kind) {
            Log::warning('ActionExecutorService: action missing kind/action_type', [
                'dialog_id' => $dialog->id,
                'action'    => $action,
            ]);
            return null;
        }

        return match ($kind) {
            'navigation' => $this->executeNavigation($action),
            'condition'  => $this->executeCondition($conversation, $dialog, $action, $variables),
            'variable'   => $this->executeVariable($conversation, $action, $variables),
            'api'        => $this->executeApiCall($conversation, $dialog, $action, $variables),
            'function'   => $this->executeFunction($conversation, $action, $variables),
            'delay'      => $this->executeDelay($conversation, $dialog, $action),
            'handoff'    => $this->executeHandoff($conversation, $dialog, $action),
            default      => null,
        };
    }


    private function executeNavigation(array $action): ?string
    {
        return $action['goTo'] ?? null;
    }


    private function executeHandoff(Conversation $conversation, Dialog $dialog, array $action): ?string
    {
        $conversation->update([
            'status'   => 'handed_off',
            'metadata' => array_merge($conversation->metadata ?? [], [
                'handoff_source_dialog' => $dialog->id,
                'handoff_resume_at'     => $action['resumeAt'] ?? null,
            ]),
        ]);

        Log::info('Conversation handed off', [
            'conversation_id' => $conversation->id,
            'dialog_id'       => $dialog->id,
            'resume_at'       => $action['resumeAt'] ?? null,
        ]);

        return null;
    }


    private function executeCondition(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        foreach ($action['branches'] ?? [] as $branch) {
            $conditions = $branch['conditions'] ?? [];
            $logic      = $branch['conditionLogic'] ?? 'AND';

            $results = array_map(
                fn($condition) => $this->evaluateSingleCondition($condition, $variables, $conversation),
                $conditions
            );

            $matched = $logic === 'AND'
                ? !in_array(false, $results, true)
                : in_array(true,  $results, true);

            if ($matched) {
                foreach ($branch['actions'] ?? [] as $branchAction) {
                    $target = $this->execute($conversation, $dialog, $branchAction, $variables);
                    if ($target) return $target;
                }
                return null; // branch matched but had no navigation
            }
        }

        return null; // no branch matched
    }

    private function evaluateSingleCondition(
        array        $condition,
        array        $variables,
        Conversation $conversation
    ): bool {
        $type          = $condition['type']     ?? 'variable';
        $operator      = $condition['operator'] ?? 'equals';
        $expectedValue = $condition['value']    ?? '';

        $actualValue = match ($type) {
            'variable'        => $variables[$condition['source'] ?? ''] ?? null,
            'api_response'    => $this->getNestedValue(
                $conversation->metadata['last_api_response'] ?? [],
                $condition['responsePath'] ?? ''
            ),
            'option_selected' => $this->getLastSelectedOption($conversation),
            default           => null,
        };

        return $this->compareValues($actualValue, $expectedValue, $operator);
    }


    private function executeVariable(
        Conversation $conversation,
        array        $action,
        array        $variables
    ): ?string {
        $varName = $action['varName'] ?? null;
        if (!$varName) return null;

        $resolved = $this->variableResolver->resolve($action['varValue'] ?? '', $variables);

        $typed = match ($action['dataType'] ?? 'string') {
            'number'  => (float)  $resolved,
            'boolean' => filter_var($resolved, FILTER_VALIDATE_BOOLEAN),
            'json'    => json_decode($resolved, true),
            default   => $resolved,
        };

        // Persist via ConversationVariable model
        \App\Models\ConversationVariable::updateOrCreate(
            [
                'conversation_id' => $conversation->id,
                'key'             => $varName,
            ],
            ['value' => is_array($typed) ? json_encode($typed) : (string) $typed]
        );

        Log::info('Variable set via action', [
            'conversation_id' => $conversation->id,
            'variable'        => $varName,
            'value'           => $typed,
        ]);

        return null;
    }

    // ── API Call ──────────────────────────────────────────────────────────────

    private function executeApiCall(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $variables
    ): ?string {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $method   = strtoupper($action['method']   ?? 'GET');
            $endpoint = $this->variableResolver->resolve($action['endpoint'] ?? '', $variables);

            $options = [];

            if (in_array($method, ['POST', 'PUT', 'PATCH'], true) && !empty($action['bodyRaw'])) {
                $options['json'] = json_decode(
                    $this->variableResolver->resolve($action['bodyRaw'], $variables),
                    true
                );
            } elseif (!in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                $options['query'] = $variables; // pass resolved vars as query params for GET
            }

            if (!empty($action['headers'])) {
                $options['headers'] = $action['headers'];
            }

            $response     = $client->request($method, $endpoint, $options);
            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (!empty($action['apiResultVar'])) {
                \App\Models\ConversationVariable::updateOrCreate(
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

            return $this->executeResponseHandlers($conversation, $dialog, $action, $responseBody, $variables);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('API call action failed', [
                'dialog_id' => $dialog->id,
                'endpoint'  => $action['endpoint'] ?? '',
                'error'     => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $dialog, $action, $variables);
        }
    }

    private function executeResponseHandlers(
        Conversation $conversation,
        Dialog       $dialog,
        array        $action,
        array        $responseBody,
        array        $variables
    ): ?string {
        foreach ($action['responseHandlers'] ?? [] as $handler) {
            $allMatch = true;

            foreach ($handler['conditions'] ?? [] as $condition) {
                if (!$this->evaluateResponseCondition($condition, $responseBody)) {
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

    private function evaluateResponseCondition(array $condition, array $responseBody): bool
    {
        $actualValue = $this->getNestedValue($responseBody, $condition['responsePath'] ?? '');
        return $this->compareValues($actualValue, $condition['value'] ?? '', $condition['operator'] ?? 'equals');
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

            Log::info('Function action executed', [
                'conversation_id' => $conversation->id,
                'function_id'     => $fnId,
                'result_var'      => $resultVar,
            ]);
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
        $seconds        = $action['seconds'] ?? 3;
        $nextDialogId   = $action['goTo']    ?? null; // config.id (frontend UUID)

        if ($nextDialogId) {
            // Resolve next dialog via config->id (NOT uuid column)
            $nextDialog = $dialog->flowVersion
                ->dialogs()
                ->where('config->id', $nextDialogId)
                ->first();

            if ($nextDialog) {
                \App\Jobs\ContinueChatbotFlow::dispatch($conversation, $nextDialog)
                    ->delay(now()->addSeconds($seconds));

                Log::info('Delay scheduled', [
                    'conversation_id' => $conversation->id,
                    'delay_seconds'   => $seconds,
                    'next_dialog_id'  => $nextDialog->id,
                ]);
            } else {
                Log::warning('Delay: next dialog not found', [
                    'conversation_id'    => $conversation->id,
                    'next_dialog_config_id' => $nextDialogId,
                ]);
            }
        }

        return null; // flow paused — job will resume it
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getNestedValue(array $data, string $path): mixed
    {
        if (empty($path)) return null;

        $value = $data;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    private function getLastSelectedOption(Conversation $conversation): ?string
    {
        $lastMessage = $conversation->messages()
            ->where('direction', 'inbound')
            ->latest()
            ->first();

        if (!$lastMessage) return null;

        return match ($lastMessage->message_type) {
            'interactive' => $lastMessage->content['response']['title']
                ?? $lastMessage->content['response']['id']
                ?? null,
            'text'        => $lastMessage->content['text'] ?? null,
            default       => null,
        };
    }

    public function compareValues(mixed $left, mixed $right, string $operator): bool
    {
        return match ($operator) {
            'equals',                '=='  => $left == $right,
            'not_equals',            '!='  => $left != $right,
            'greater_than',          '>'   => $left > $right,
            'less_than',             '<'   => $left < $right,
            'greater_than_or_equal', '>='  => $left >= $right,
            'less_than_or_equal',    '<='  => $left <= $right,
            'contains'     => is_string($left) && str_contains($left, (string) $right),
            'not_contains' => is_string($left) && !str_contains($left, (string) $right),
            'starts_with'  => is_string($left) && str_starts_with($left, (string) $right),
            'ends_with'    => is_string($left) && str_ends_with($left, (string) $right),
            'is_empty'     => empty($left),
            'is_not_empty',
            'not_empty'    => !empty($left),
            'in_array'     => is_array($left) && in_array($right, $left),
            'not_in_array' => is_array($left) && !in_array($right, $left),
            default        => false,
        };
    }
}
