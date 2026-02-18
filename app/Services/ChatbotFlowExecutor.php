<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChatbotFlowExecutor
{
    private WhatsAppMessageService $messageService;
    private FunctionExecutor $functionExecutor;
    private VariableResolver $variableResolver;

    public function __construct(
        WhatsAppMessageService $messageService,
        FunctionExecutor $functionExecutor,
        VariableResolver $variableResolver
    ) {
        $this->messageService = $messageService;
        $this->functionExecutor = $functionExecutor;
        $this->variableResolver = $variableResolver;
    }

    /**
     * Process incoming message and execute flow
     */
    public function processMessage(Conversation $conversation, Message $message): void
    {
        $flow = $conversation->flow;
        $version = $conversation->flowVersion; // ✅ NOW USING VERSION

        if (!$flow || $flow->status !== 'published' || !$version) {
            Log::warning('Flow not available', [
                'flow_id' => $flow?->id,
                'flow_status' => $flow?->status,
                'version_id' => $version?->id,
            ]);
            return;
        }

        try {
            // Get or create conversation variables
            $variables = $conversation->variables()->pluck('value', 'key')->toArray();

            // Get current node from conversation or start from entry point
            $currentNode = $this->getCurrentNode($version, $conversation);

            if (!$currentNode) {
                $currentNode = $this->getStartNode($version);
                if (!$currentNode) {
                    Log::warning('No start node found', ['version_id' => $version->id]);
                    return;
                }
            }

            // Process user input if this is an input node
            if ($message->direction === 'inbound' && $currentNode->requiresUserInput()) {
                $this->processUserInput($conversation, $currentNode, $message);
            }

            // Execute next node
            $nextNode = $this->getNextNode($version, $currentNode, $variables);
            if ($nextNode) {
                $this->executeNode($conversation, $nextNode);
            }
        } catch (\Exception $e) {
            Log::error('Flow execution error', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($flow->fallback_message) {
                $this->messageService->sendTextMessage(
                    $conversation->whatsappAccount,
                    $conversation->whatsapp_user_phone,
                    $flow->fallback_message
                );
            }
        }
    }

    /**
     * Execute a flow node
     */
    public function executeNode(Conversation $conversation, FlowNode $node): void
    {
        $startTime = microtime(true);

        // Log node entry
        \App\Models\FlowExecutionLog::logNodeEnter($conversation, $node);
        $node->incrementEntered();
        $this->logAnalytics($conversation, $node, 'node_entered');

        try {
            // Execute on_enter actions
            $this->executeActions($conversation, $node, 'on_enter');

            // Execute node based on type
            $result = match ($node->type) {
                'trigger' => $this->executeTriggerNode($conversation, $node),
                'message' => $this->executeMessageNode($conversation, $node),
                'input' => $this->executeInputNode($conversation, $node),
                'buttons' => $this->executeButtonsNode($conversation, $node),
                'list' => $this->executeListNode($conversation, $node),
                'condition' => $this->executeConditionNode($conversation, $node),
                'function' => $this->executeFunctionNode($conversation, $node),
                'api_call' => $this->executeApiCallNode($conversation, $node),
                'delay' => $this->executeDelayNode($conversation, $node),
                'webhook' => $this->executeWebhookNode($conversation, $node),
                'handoff' => $this->executeHandoffNode($conversation, $node),
                'end' => $this->executeEndNode($conversation, $node),
                default => ['success' => true, 'stop' => false],
            };

            // Stop execution if needed (delay, handoff, end, or waiting for input)
            if ($result['stop'] ?? false) {
                return;
            }

            // Execute on_success actions
            $this->executeActions($conversation, $node, 'on_success');

            // Mark as completed
            $node->incrementCompleted();
            $this->logAnalytics($conversation, $node, 'node_completed');

            // Log execution time
            $executionTime = (int)((microtime(true) - $startTime) * 1000);
            \App\Models\FlowExecutionLog::logNodeExit($conversation, $node, true, null, $executionTime);

            // Continue to next node
            $variables = $conversation->variables()->pluck('value', 'key')->toArray();
            $nextNode = $this->getNextNode($conversation->flowVersion, $node, $variables);

            if ($nextNode) {
                $this->executeNode($conversation, $nextNode);
            }
        } catch (\Exception $e) {
            $node->incrementFailed();
            $this->logAnalytics($conversation, $node, 'error_occurred', ['error' => $e->getMessage()]);

            $executionTime = (int)((microtime(true) - $startTime) * 1000);
            \App\Models\FlowExecutionLog::logNodeExit($conversation, $node, false, $e->getMessage(), $executionTime);

            // Execute on_failure actions
            $this->executeActions($conversation, $node, 'on_failure');

            throw $e;
        }
    }

    /**
     * Execute node actions for a specific trigger event
     */
    private function executeActions(Conversation $conversation, FlowNode $node, string $triggerEvent): void
    {
        $actions = $node->actions()
            ->where('trigger_event', $triggerEvent)
            ->orderBy('execution_order')
            ->get();

        foreach ($actions as $action) {
            try {
                $this->executeAction($conversation, $node, $action);
            } catch (\Exception $e) {
                Log::error('Action execution failed', [
                    'action_id' => $action->id,
                    'action_type' => $action->action_type,
                    'error' => $e->getMessage(),
                ]);

                if (!$action->continue_on_failure) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Execute a single action
     */
    private function executeAction(Conversation $conversation, FlowNode $node, $action): void
    {
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();
        $config = $action->config;

        $startTime = microtime(true);

        match ($action->action_type) {
            'save_variable' => $this->executeSaveVariableAction($conversation, $config, $variables),
            'update_variable' => $this->executeSaveVariableAction($conversation, $config, $variables),
            'delete_variable' => $this->executeDeleteVariableAction($conversation, $config),
            'api_call' => $this->executeApiCallAction($conversation, $config, $variables),
            'execute_function' => $this->executeFunctionAction($conversation, $config, $variables),
            'delay' => $this->executeDelayAction($conversation, $node, $config),
            'webhook_call' => $this->executeWebhookAction($conversation, $config, $variables),
            'emit_event' => null, // Navigation events are handled via edges
            default => null,
        };

        $executionTime = (int)((microtime(true) - $startTime) * 1000);
        \App\Models\FlowExecutionLog::logActionExecution($conversation, $node, $action->action_type, true, null, $executionTime);
    }

    // ─── Node Type Executors ──────────────────────────────────────────────────

    private function executeTriggerNode(Conversation $conversation, FlowNode $node): array
    {
        // Trigger nodes just pass through - matching happens elsewhere
        return ['success' => true, 'stop' => false];
    }

    private function executeMessageNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();
        $text = $this->variableResolver->resolve($config['text'] ?? '', $variables);

        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $text
        );

        return ['success' => true, 'stop' => false];
    }

    private function executeInputNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();
        $text = $this->variableResolver->resolve($config['text'] ?? '', $variables);

        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $text
        );

        // Wait for user input - stop execution
        return ['success' => true, 'stop' => true];
    }

    private function executeButtonsNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();
        $text = $this->variableResolver->resolve($config['btnText'] ?? '', $variables);

        $buttons = array_map(function ($btn) {
            return [
                'id' => $btn['id'],
                'title' => $btn['label'],
            ];
        }, $config['buttons'] ?? []);

        $this->messageService->sendButtonMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $text,
            $buttons
        );

        // Wait for user selection - stop execution
        return ['success' => true, 'stop' => true];
    }

    private function executeListNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        $header = $this->variableResolver->resolve($config['listHeader'] ?? '', $variables);
        $body = $this->variableResolver->resolve($config['listBody'] ?? '', $variables);

        $sections = $config['sections'] ?? [];

        $this->messageService->sendListMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $header,
            $body,
            $sections
        );

        // Wait for user selection - stop execution
        return ['success' => true, 'stop' => true];
    }

    private function executeConditionNode(Conversation $conversation, FlowNode $node): array
    {
        // Conditions are evaluated in getNextNode() via edges
        return ['success' => true, 'stop' => false];
    }

    private function executeFunctionNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        try {
            $params = array_map(
                fn($v) => $this->variableResolver->resolveValue($v, $variables),
                json_decode($config['paramsRaw'] ?? '{}', true)
            );

            $result = $this->functionExecutor->execute($config['fnId'], $params);

            if ($config['resultVar'] ?? null) {
                $this->saveVariable($conversation, $config['resultVar'], $result);
            }

            $this->logAnalytics($conversation, $node, 'function_executed', ['success' => true]);

            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Function execution failed', ['error' => $e->getMessage()]);
            $this->logAnalytics($conversation, $node, 'function_executed', ['success' => false, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function executeApiCallNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $method = $config['method'] ?? 'GET';
            $url = $this->variableResolver->resolve($config['endpoint'] ?? '', $variables);

            $options = [];
            if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($config['bodyRaw'])) {
                $options['json'] = json_decode($this->variableResolver->resolve($config['bodyRaw'], $variables), true);
            }

            $response = $client->request($method, $url, $options);
            $data = json_decode($response->getBody()->getContents(), true);

            if ($config['apiResultVar'] ?? null) {
                $this->saveVariable($conversation, $config['apiResultVar'], $data);
            }

            $this->logAnalytics($conversation, $node, 'api_called', ['success' => true]);

            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('API call failed', ['error' => $e->getMessage()]);
            $this->logAnalytics($conversation, $node, 'api_called', ['success' => false, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function executeDelayNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $seconds = $config['seconds'] ?? 3;

        // Get next node
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();
        $nextNode = $this->getNextNode($conversation->flowVersion, $node, $variables);

        if ($nextNode) {
            \App\Jobs\ContinueChatbotFlow::dispatch($conversation, $nextNode)
                ->delay(now()->addSeconds($seconds));
        }

        // Stop execution - will resume after delay
        return ['success' => true, 'stop' => true];
    }

    private function executeHandoffNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        $conversation->update(['status' => 'handed_off']);

        if (!empty($config['text'])) {
            $message = $this->variableResolver->resolve($config['text'], $variables);
            $this->messageService->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $message
            );
        }

        $this->logAnalytics($conversation, $node, 'handoff_initiated');

        // Stop execution - agent will handle from here
        return ['success' => true, 'stop' => true];
    }

    private function executeWebhookNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $url = $this->variableResolver->resolve($config['url'] ?? '', $variables);

            $body = array_merge(
                json_decode($this->variableResolver->resolve($config['bodyRaw'] ?? '{}', $variables), true),
                [
                    'conversation_id' => $conversation->id,
                    'variables' => $variables,
                ]
            );

            $client->request($config['method'] ?? 'POST', $url, ['json' => $body]);

            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Webhook failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function executeEndNode(Conversation $conversation, FlowNode $node): array
    {
        $config = $node->config;
        $variables = $conversation->variables()->pluck('value', 'key')->toArray();

        if (!empty($config['text'])) {
            $message = $this->variableResolver->resolve($config['text'], $variables);
            $this->messageService->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $message
            );
        }

        $conversation->complete();
        $this->logAnalytics($conversation, $node, 'conversation_completed');

        // Stop execution - conversation is done
        return ['success' => true, 'stop' => true];
    }

    // ─── Action Executors ─────────────────────────────────────────────────────

    private function executeSaveVariableAction(Conversation $conversation, array $config, array $variables): void
    {
        $key = $config['varName'] ?? null;
        $value = $this->variableResolver->resolve($config['varValue'] ?? '', $variables);

        if ($key) {
            $this->saveVariable($conversation, $key, $value);
        }
    }

    private function executeDeleteVariableAction(Conversation $conversation, array $config): void
    {
        $key = $config['varName'] ?? null;
        if ($key) {
            $conversation->variables()->where('key', $key)->delete();
        }
    }

    private function executeApiCallAction(Conversation $conversation, array $config, array $variables): void
    {
        // Same as executeApiCallNode but for actions
        $this->executeApiCallNode($conversation, (object)['config' => $config]);
    }

    private function executeFunctionAction(Conversation $conversation, array $config, array $variables): void
    {
        // Same as executeFunctionNode but for actions
        $this->executeFunctionNode($conversation, (object)['config' => $config]);
    }

    private function executeDelayAction(Conversation $conversation, FlowNode $node, array $config): void
    {
        // Delay actions work same as delay nodes
        $this->executeDelayNode($conversation, (object)['config' => $config]);
    }

    private function executeWebhookAction(Conversation $conversation, array $config, array $variables): void
    {
        // Same as executeWebhookNode but for actions
        $this->executeWebhookNode($conversation, (object)['config' => $config]);
    }

    // ─── Helper Methods ───────────────────────────────────────────────────────

    private function processUserInput(Conversation $conversation, FlowNode $node, Message $message): void
    {
        $config = $node->config;

        $input = match ($message->message_type) {
            'text' => $message->content['text'] ?? '',
            'interactive' => $message->content['response']['title'] ?? $message->content['response']['id'] ?? '',
            default => null,
        };

        if (!$input) {
            return;
        }

        // Save input variable if configured
        if (!empty($config['inputVariable'])) {
            $this->saveVariable($conversation, $config['inputVariable'], $input);
        }

        // Save button/list selection variable if configured
        if ($node->type === 'buttons' && !empty($config['buttons'])) {
            foreach ($config['buttons'] as $btn) {
                if (($btn['id'] ?? '') === $input && !empty($btn['saveVariable'])) {
                    $this->saveVariable($conversation, $btn['saveVariable'], $btn['label']);
                }
            }
        }

        if ($node->type === 'list' && !empty($config['sections'])) {
            foreach ($config['sections'] as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $input && !empty($row['saveVariable'])) {
                        $this->saveVariable($conversation, $row['saveVariable'], $row['title']);
                    }
                }
            }
        }
    }

    private function saveVariable(Conversation $conversation, string $key, $value): void
    {
        $conversation->setVariable($key, $value);
    }

    private function getCurrentNode($version, Conversation $conversation): ?FlowNode
    {
        // Get last message sent by bot
        $lastMessage = $conversation->messages()
            ->where('direction', 'outbound')
            ->whereNotNull('flow_node_id')
            ->latest()
            ->first();

        return $lastMessage ? $version->nodes()->find($lastMessage->flow_node_id) : null;
    }

    private function getStartNode($version): ?FlowNode
    {
        return $version->nodes()->where('is_entry_point', true)->first();
    }

    private function getNextNode($version, FlowNode $currentNode, array $variables): ?FlowNode
    {
        // Get edges ordered by priority
        $edges = $currentNode->outgoingEdges()->orderBy('priority', 'desc')->get();

        foreach ($edges as $edge) {
            // Check if edge has condition
            if (!empty($edge->condition)) {
                if (!$this->evaluateEdgeCondition($edge->condition, $variables)) {
                    continue;
                }
            }

            return $edge->targetNode;
        }

        return null;
    }

    private function evaluateEdgeCondition(array $condition, array $variables): bool
    {
        // Simple condition evaluation
        $left = $this->variableResolver->resolveValue($condition['left'] ?? '', $variables);
        $right = $this->variableResolver->resolveValue($condition['right'] ?? '', $variables);
        $operator = $condition['operator'] ?? '==';

        return $this->compareValues($left, $right, $operator);
    }

    private function compareValues($left, $right, $op): bool
    {
        return match ($op) {
            '==', 'equals' => $left == $right,
            '!=', 'not_equals' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            'contains' => is_string($left) && str_contains($left, (string)$right),
            'starts_with' => is_string($left) && str_starts_with($left, (string)$right),
            'ends_with' => is_string($left) && str_ends_with($left, (string)$right),
            'is_empty' => empty($left),
            'is_not_empty', 'not_empty' => !empty($left),
            default => false,
        };
    }

    private function logAnalytics(Conversation $conversation, FlowNode $node, string $eventType, array $metadata = []): void
    {
        AnalyticsEvent::create([
            'tenant_id' => $conversation->tenant_id,
            'flow_id' => $conversation->flow_id,
            'conversation_id' => $conversation->id,
            'event_type' => $eventType,
            'node_id' => $node->id, // ✅ NOW USING BIGINT ID
            'metadata' => $metadata,
        ]);
    }
}