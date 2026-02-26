<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\FlowNode;
use Illuminate\Support\Facades\Log;

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
     * @return string|null  UUID of the next node to navigate to, or null (side-effect only)
     */
    public function execute(
        Conversation $conversation,
        FlowNode     $node,
        array        $action,
        array        $variables
    ): ?string {
        $kind = $action['kind'] ?? null;

        if (!$kind) {
            Log::warning('ActionExecutorService: action missing kind', [
                'node_id' => $node->id,
                'action'  => $action,
            ]);
            return null;
        }

        return match ($kind) {
            'navigation' => $this->executeNavigation($action),
            'condition'  => $this->executeCondition($conversation, $node, $action, $variables),
            'variable'   => $this->executeVariable($conversation, $action, $variables),
            'api'        => $this->executeApiCall($conversation, $node, $action, $variables),
            'function'   => $this->executeFunction($conversation, $action, $variables),
            'delay'      => $this->executeDelay($conversation, $node, $action),
            'handoff'    => $this->executeHandoff($conversation, $node, $action),
            default      => null,
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

    private function executeHandoff(Conversation $conversation, FlowNode $node, array $action): ?string
    {
        $conversation->update([
            'status'   => 'handed_off',
            'metadata' => array_merge($conversation->metadata ?? [], [
                'handoff_source_node' => $node->id,
                'handoff_resume_at'   => $action['resumeAt'] ?? null,
            ]),
        ]);

        Log::info('Conversation handed off', [
            'conversation_id' => $conversation->id,
            'node_id'         => $node->id,
            'resume_at'       => $action['resumeAt'] ?? null,
        ]);

        return null;
    }

    // ── Multi-Branch Condition ────────────────────────────────────────────────

    private function executeCondition(
        Conversation $conversation,
        FlowNode     $node,
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
                    $target = $this->execute($conversation, $node, $branchAction, $variables);
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

    // ── Variable ──────────────────────────────────────────────────────────────

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

        $conversation->setVariable($varName, $typed);

        Log::info('Variable set', [
            'conversation_id' => $conversation->id,
            'variable'        => $varName,
            'value'           => $typed,
        ]);

        return null;
    }

    // ── API Call ──────────────────────────────────────────────────────────────

    private function executeApiCall(
        Conversation $conversation,
        FlowNode     $node,
        array        $action,
        array        $variables
    ): ?string {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 30]);
            $method   = $action['method']   ?? 'GET';
            $endpoint = $this->variableResolver->resolve($action['endpoint'] ?? '', $variables);

            $options = [];
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($action['bodyRaw'])) {
                $options['json'] = json_decode(
                    $this->variableResolver->resolve($action['bodyRaw'], $variables),
                    true
                );
            }

            $response     = $client->request($method, $endpoint, $options);
            $statusCode   = $response->getStatusCode();
            $responseBody = json_decode($response->getBody()->getContents(), true);

            if (!empty($action['apiResultVar'])) {
                $conversation->setVariable($action['apiResultVar'], $responseBody);
            }

            $conversation->update([
                'metadata' => array_merge($conversation->metadata ?? [], [
                    'last_api_response' => $responseBody,
                    'last_api_status'   => $statusCode,
                ]),
            ]);

            return $this->executeResponseHandlers(
                $conversation,
                $node,
                $action,
                $responseBody,
                $variables
            );
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('API call failed', [
                'endpoint' => $action['endpoint'] ?? '',
                'error'    => $e->getMessage(),
            ]);

            return $this->executeDefaultActions($conversation, $node, $action, $variables);
        }
    }

    private function executeResponseHandlers(
        Conversation $conversation,
        FlowNode     $node,
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
                    $target = $this->execute($conversation, $node, $handlerAction, $variables);
                    if ($target) return $target;
                }
                return null;
            }
        }

        return $this->executeDefaultActions($conversation, $node, $action, $variables);
    }

    private function executeDefaultActions(
        Conversation $conversation,
        FlowNode     $node,
        array        $action,
        array        $variables
    ): ?string {
        foreach ($action['defaultActions'] ?? [] as $defaultAction) {
            $target = $this->execute($conversation, $node, $defaultAction, $variables);
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
                $conversation->setVariable($resultVar, $result);
            }

            Log::info('Function executed', [
                'conversation_id' => $conversation->id,
                'function_id'     => $fnId,
                'result_var'      => $resultVar,
            ]);
        } catch (\Exception $e) {
            Log::error('Function execution failed', [
                'function_id' => $fnId,
                'error'       => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ── Delay ─────────────────────────────────────────────────────────────────

    private function executeDelay(
        Conversation $conversation,
        FlowNode     $node,
        array        $action
    ): ?string {
        $seconds      = $action['seconds'] ?? 3;
        $nextNodeUuid = $action['goTo']    ?? null;

        if ($nextNodeUuid) {
            $nextNode = $node->flowVersion
                ->nodes()
                ->where('uuid', $nextNodeUuid)
                ->first();

            if ($nextNode) {
                \App\Jobs\ContinueChatbotFlow::dispatch($conversation, $nextNode)
                    ->delay(now()->addSeconds($seconds));

                Log::info('Delay scheduled', [
                    'conversation_id' => $conversation->id,
                    'delay_seconds'   => $seconds,
                    'next_node_id'    => $nextNode->id,
                ]);
            }
        }

        return null; // flow paused — job will resume it
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Navigate a dot-notation path into a nested array.
     * e.g. 'data.user.name' into ['data' => ['user' => ['name' => 'Alice']]] → 'Alice'
     */
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
            'is_not_empty', 'not_empty' => !empty($left),
            'in_array'     => is_array($left) && in_array($right, $left),
            'not_in_array' => is_array($left) && !in_array($right, $left),
            default        => false,
        };
    }
}