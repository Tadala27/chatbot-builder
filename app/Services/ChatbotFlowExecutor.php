<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\ConversationContext;
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

        if (!$flow || $flow->status !== 'published') {
            Log::warning('Chatbot not available');
            return;
        }

        try {
            $context = $this->getOrCreateContext($conversation);
            $currentNode = $this->getCurrentNode($flow, $context);

            if (!$currentNode) {
                $currentNode = $this->getStartNode($flow);
                if (!$currentNode) return;
            }

            if ($message->direction === 'inbound' && $currentNode->node_type === 'question') {
                $this->processUserInput($context, $currentNode, $message);
            }

            $nextNode = $this->getNextNode($flow, $currentNode, $context);
            if ($nextNode) {
                $this->executeNode($conversation, $context, $nextNode);
            }
        } catch (\Exception $e) {
            Log::error('Flow execution error', ['error' => $e->getMessage()]);
            if ($chatbflowot->fallback_message) {
                $this->messageService->sendTextMessage(
                    $conversation->whatsappAccount,
                    $conversation->whatsapp_user_phone,
                    $flow->fallback_message
                );
            }
        }
    }

    private function executeNode(Conversation $conversation, ConversationContext $context, FlowNode $node): void
    {
        $this->logAnalytics($conversation, $node, 'node_entered');

        try {
            match ($node->node_type) {
                'message' => $this->executeMessageNode($conversation->whatsappAccount, $conversation, $context, $node),
                'question' => $this->executeQuestionNode($conversation->whatsappAccount, $conversation, $context, $node),
                'condition' => $this->executeConditionNode($conversation, $context, $node),
                'function' => $this->executeFunctionNode($conversation, $context, $node),
                'api_call' => $this->executeApiCallNode($conversation, $context, $node),
                'variable_set' => $this->executeVariableSetNode($context, $node),
                'delay' => $this->executeDelayNode($conversation, $context, $node),
                'handoff' => $this->executeHandoffNode($conversation, $context, $node),
                'webhook' => $this->executeWebhookNode($conversation, $context, $node),
                'end' => $this->executeEndNode($conversation, $context, $node),
                default => null,
            };

            if (in_array($node->node_type, ['delay', 'handoff', 'end'])) return;

            $context->update(['last_node_id' => $node->node_id]);
            $this->logAnalytics($conversation, $node, 'node_completed');

            if (!in_array($node->node_type, ['question'])) {
                $nextNode = $this->getNextNode($conversation->flow, $node, $context);
                if ($nextNode) $this->executeNode($conversation, $context, $nextNode);
            }
        } catch (\Exception $e) {
            $this->logAnalytics($conversation, $node, 'error_occurred', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function executeMessageNode($account, $conversation, $context, $node): void
    {
        $config = $node->config;
        $text = $this->variableResolver->resolve($config['content'] ?? '', $context->variables);

        match ($config['message_type'] ?? 'text') {
            'text' => $this->messageService->sendTextMessage($account, $conversation->whatsapp_user_phone, $text),
            'image', 'video', 'audio', 'document' => $this->messageService->sendMediaMessage(
                $account,
                $conversation->whatsapp_user_phone,
                $config['message_type'],
                $this->variableResolver->resolve($config['media_url'] ?? '', $context->variables),
                $text
            ),
            default => null,
        };
    }

    private function executeQuestionNode($account, $conversation, $context, $node): void
    {
        $config = $node->config;
        $text = $this->variableResolver->resolve($config['question_text'] ?? '', $context->variables);

        match ($config['input_type'] ?? 'text') {
            'text' => $this->messageService->sendTextMessage($account, $conversation->whatsapp_user_phone, $text),
            'buttons' => $this->messageService->sendButtonMessage($account, $conversation->whatsapp_user_phone, $text, $config['options'] ?? []),
            'list' => $this->messageService->sendListMessage($account, $conversation->whatsapp_user_phone, $text, 'Select', $config['options'] ?? []),
            default => null,
        };
    }

    private function executeConditionNode($conversation, $context, $node): void
    {
        $config = $node->config;
        $result = $this->evaluateCondition($config['conditions'] ?? [], $context->variables, $config['operator'] ?? 'AND');
        $this->logAnalytics($conversation, $node, 'condition_evaluated', ['result' => $result]);
        $context->setVariable('_last_condition_result', $result);
    }

    private function executeFunctionNode($conversation, $context, $node): void
    {
        try {
            $config = $node->config;
            $params = array_map(fn($v) => $this->variableResolver->resolveValue($v, $context->variables), $config['parameters'] ?? []);
            $result = $this->functionExecutor->execute($config['function_id'], $params);
            if ($config['output_variable'] ?? null) $context->setVariable($config['output_variable'], $result);
            $this->logAnalytics($conversation, $node, 'function_executed', ['success' => true]);
        } catch (\Exception $e) {
            Log::error('Function failed', ['error' => $e->getMessage()]);
            if (($config['error_handling'] ?? 'continue') === 'stop') throw $e;
        }
    }

    private function executeApiCallNode($conversation, $context, $node): void
    {
        try {
            $config = $node->config;
            $integration = \App\Models\ApiIntegration::find($config['integration_id'] ?? null);
            if (!$integration) throw new \Exception('Integration not found');

            $client = new \GuzzleHttp\Client(['timeout' => $integration->timeout_seconds]);
            $method = $config['method'] ?? 'GET';
            $url = $integration->buildUrl($this->variableResolver->resolve($config['endpoint'] ?? '', $context->variables));
            $body = $this->variableResolver->resolveArray($config['body'] ?? [], $context->variables);

            $options = ['headers' => array_merge($integration->headers ?? [], $integration->getAuthHeaders())];
            if (in_array($method, ['POST', 'PUT', 'PATCH'])) $options['json'] = $body;
            else $options['query'] = $body;

            $response = $client->request($method, $url, $options);
            $data = json_decode($response->getBody()->getContents(), true);

            if ($config['response_variable'] ?? null) $context->setVariable($config['response_variable'], $data);
            $this->logAnalytics($conversation, $node, 'api_called', ['success' => true]);
        } catch (\Exception $e) {
            Log::error('API call failed', ['error' => $e->getMessage()]);
            if (($config['error_handling'] ?? 'continue') === 'stop') throw $e;
        }
    }

    private function executeVariableSetNode($context, $node): void
    {
        $config = $node->config;
        $value = $this->variableResolver->resolve($config['value'] ?? '', $context->variables);
        $value = $this->castValue($value, $config['data_type'] ?? 'string');
        $context->setVariable($config['variable_name'] ?? '', $value);
    }

    private function executeDelayNode($conversation, $context, $node): void
    {
        $config = $node->config;
        $nextNode = $this->getNextNode($conversation->flow, $node, $context);
        if ($nextNode) {
            \App\Jobs\ContinueChatbotFlow::dispatch($conversation, $context, $nextNode)
                ->delay(now()->addSeconds($config['duration_seconds'] ?? 1));
        }
    }

    private function executeHandoffNode($conversation, $context, $node): void
    {
        $config = $node->config;
        $conversation->update(['status' => 'handed_off']);
        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $this->variableResolver->resolve($config['message'] ?? 'Transferring...', $context->variables)
        );
        $this->logAnalytics($conversation, $node, 'handoff_initiated');
    }

    private function executeWebhookNode($conversation, $context, $node): void
    {
        try {
            $config = $node->config;
            $client = new \GuzzleHttp\Client(['timeout' => 30]);
            $client->request($config['method'] ?? 'POST', $this->variableResolver->resolve($config['url'] ?? '', $context->variables), [
                'json' => array_merge($this->variableResolver->resolveArray($config['body'] ?? [], $context->variables), [
                    'conversation_id' => $conversation->id,
                    'variables' => $context->variables,
                ]),
            ]);
        } catch (\Exception $e) {
            Log::error('Webhook failed', ['error' => $e->getMessage()]);
        }
    }

    private function executeEndNode($conversation, $context, $node): void
    {
        $config = $node->config;
        if ($config['message'] ?? null) {
            $this->messageService->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $this->variableResolver->resolve($config['message'], $context->variables)
            );
        }
        $conversation->complete();
        $this->logAnalytics($conversation, $node, 'conversation_completed');
    }

    private function processUserInput($context, $node, $message): void
    {
        $config = $node->config;
        $input = match ($message->message_type) {
            'text' => $message->content['text'] ?? '',
            'interactive' => $message->content['response']['title'] ?? $message->content['response']['id'] ?? '',
            default => null,
        };

        if (!$input) return;
        if (($config['validation'] ?? null) && !$this->validateInput($input, $config['validation'])) return;
        if ($config['variable_name'] ?? null) $context->setVariable($config['variable_name'], $input);
    }

    private function getOrCreateContext($conversation): ConversationContext
    {
        $context = $conversation->context ?: ConversationContext::create([
            'conversation_id' => $conversation->id,
            'variables' => [],
            'expires_at' => now()->addHours(24),
        ]);

        $globalVars = $conversation->tenant->globalVariables()->get()->mapWithKeys(fn($v) => [$v->key => $v->getCastedValue()])->toArray();
        $botVars = $conversation->flow->variables()->get()->mapWithKeys(fn($v) => [$v->key => $v->getCastedValue()])->toArray();
        $context->variables = array_merge($globalVars, $botVars, $context->variables ?? []);

        return $context;
    }

    private function getCurrentNode($flow, $context): ?FlowNode
    {
        return $context->last_node_id ? $flow->dialogNodes()->where('node_id', $context->last_node_id)->first() : null;
    }

    private function getStartNode($flow): ?FlowNode
    {
        return $flow->dialogNodes()->where('node_type', 'trigger')->first();
    }

    private function getNextNode($flow, $currentNode, $context): ?FlowNode
    {
        foreach ($currentNode->outgoingEdges()->orderBy('priority', 'desc')->get() as $edge) {
            if ($edge->hasCondition() && !$edge->evaluateCondition($context->variables)) continue;
            return $flow->dialogNodes()->where('node_id', $edge->target_node_id)->first();
        }
        return null;
    }

    private function evaluateCondition($conditions, $variables, $operator): bool
    {
        if (empty($conditions)) return true;
        $results = array_map(fn($c) => $this->compareValues(
            $this->variableResolver->resolveValue($c['left'] ?? '', $variables),
            $this->variableResolver->resolveValue($c['right'] ?? '', $variables),
            $c['operator'] ?? '=='
        ), $conditions);
        return $operator === 'AND' ? !in_array(false, $results, true) : in_array(true, $results, true);
    }

    private function compareValues($left, $right, $op): bool
    {
        return match ($op) {
            '==' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            'contains' => is_string($left) && str_contains($left, (string)$right),
            'starts_with' => is_string($left) && str_starts_with($left, (string)$right),
            'ends_with' => is_string($left) && str_ends_with($left, (string)$right),
            'is_empty' => empty($left),
            'is_not_empty' => !empty($left),
            default => false,
        };
    }

    private function validateInput($input, $rules): bool
    {
        foreach ($rules as $rule) {
            $valid = match ($rule['type'] ?? '') {
                'required' => !empty($input),
                'email' => filter_var($input, FILTER_VALIDATE_EMAIL) !== false,
                'phone' => preg_match('/^\+?[1-9]\d{1,14}$/', $input),
                'number' => is_numeric($input),
                'min_length' => strlen($input) >= ($rule['value'] ?? 0),
                'max_length' => strlen($input) <= ($rule['value'] ?? PHP_INT_MAX),
                default => true,
            };
            if (!$valid) return false;
        }
        return true;
    }

    private function castValue($value, $type)
    {
        return match ($type) {
            'string' => (string)$value,
            'number' => is_numeric($value) ? +$value : 0,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'date' => \Carbon\Carbon::parse($value),
            default => $value,
        };
    }

    private function logAnalytics($conversation, $node, $eventType, $metadata = []): void
    {
        AnalyticsEvent::create([
            'tenant_id' => $conversation->tenant_id,
            'flow_id' => $conversation->flow_id,
            'conversation_id' => $conversation->id,
            'event_type' => $eventType,
            'node_id' => $node->node_id,
            'metadata' => $metadata,
        ]);
    }
}