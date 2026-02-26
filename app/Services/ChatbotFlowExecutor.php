<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\FlowNode;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChatbotFlowExecutor
{
    /**
     * Stored on the instance so all private methods can access it
     * without needing to pass it through every call chain.
     */
    private Conversation $conversation;

    public function __construct(
        private WhatsAppMessageService $messageService,
        private VariableResolver       $variableResolver,
        private ActionExecutorService  $actionExecutor,
    ) {}

    // =========================================================================
    // MAIN ENTRY POINTS
    // =========================================================================

    /**
     * Handle an inbound WhatsApp message.
     * Called by ProcessChatbotMessage job.
     */
    public function processMessage(Conversation $conversation, Message $message): void
    {
        $this->conversation = $conversation;

        try {
            $flow    = $conversation->flow;
            $version = $conversation->flowVersion;

            if (!$flow || $flow->status !== 'published' || !$version) {
                Log::warning('Flow not available for conversation', [
                    'conversation_id' => $conversation->id,
                    'flow_id'         => $flow?->id,
                    'flow_status'     => $flow?->status,
                ]);
                return;
            }

            $currentNode = $this->getCurrentNode($version, $conversation);

            if ($currentNode) {
                Log::info('Current node found, processing user input', [
                    'conversation_id' => $conversation->id,
                    'current_node_id' => $currentNode->id,
                    'node_type'       => $currentNode->type,
                ]);

                // 1. Save user's reply into conversation variables
                $this->processUserInput($currentNode, $message);

                // 2. Run on_success DB actions for post-input processing if any
                $this->executeDbActions($currentNode, 'on_success');

                // 3. Resolve next node based on what the user selected/typed
                $variables = $this->getVariables();
                $nextNode  = $this->resolveNextNodeFromMessage($version, $currentNode, $message, $variables);

                if ($nextNode) {
                    $this->executeNodeFlow($nextNode);
                } else {
                    Log::warning('No next node found after user input', [
                        'conversation_id' => $conversation->id,
                        'current_node_id' => $currentNode->id,
                    ]);
                }
            } else {
                // No current node — conversation is just starting
                Log::info('No current node, starting flow from entry point', [
                    'conversation_id' => $conversation->id,
                ]);
                $this->startFlow($conversation);
            }
        } catch (\Exception $e) {
            Log::error('Error processing message', [
                'conversation_id' => $conversation->id,
                'message_id'      => $message->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Execute a node and keep the flow moving.
     * Called by ContinueChatbotFlow job (and internally via recursion).
     */
    public function executeNodeFlow(FlowNode $node, ?Conversation $conversation = null): void
    {
        if ($conversation) {
            $this->conversation = $conversation;
        }

        try {
            $version = $this->conversation->flowVersion;
            if (!$version) return;

            Log::info('Executing node flow', [
                'conversation_id' => $this->conversation->id,
                'node_id'         => $node->id,
                'node_type'       => $node->type,
                'node_kind'       => $node->config['kind'] ?? $node->type,
            ]);

            // Run on_enter DB actions before the node sends anything
            $this->executeDbActions($node, 'on_enter');

            // Send message / perform the node's primary action
            $result = $this->executeNode($node);

            Log::info('Node execution result', [
                'conversation_id' => $this->conversation->id,
                'node_id'         => $node->id,
                'result'          => $result,
            ]);

            if ($result['stop'] ?? false) {
                Log::info('Node execution stopped (waiting for user input)', [
                    'conversation_id' => $this->conversation->id,
                    'node_id'         => $node->id,
                ]);
                // Interactive node (buttons/list) — wait for user reply
                return;
            }

            if ($result['success'] ?? false) {
                // Non-interactive node: resolve next node via on_enter actions or direct goTo
                $variables  = $this->getVariables();
                $enterActions = $node->getEnterActions();

                Log::info('Looking for next node', [
                    'conversation_id'     => $this->conversation->id,
                    'node_id'             => $node->id,
                    'enter_actions_count' => count($enterActions),
                    'config_goTo'         => $node->config['goTo'] ?? null,
                ]);

                $nextNodeId = $this->runActionsForNode($node, $enterActions, $variables);

                // Fall back to direct config goTo
                if (!$nextNodeId && !empty($node->config['goTo'])) {
                    $nextNodeId = $node->config['goTo'];
                    Log::info('Using config goTo', [
                        'conversation_id' => $this->conversation->id,
                        'node_id'         => $node->id,
                        'next_node_uuid'  => $nextNodeId,
                    ]);
                }

                // Find this section in executeNodeFlow()
                if ($nextNodeId) {
                    // Change from where('uuid', $nextNodeId) to where('config->id', $nextNodeId)
                    $nextNode = $version->nodes()->where('config->id', $nextNodeId)->first();
                    if ($nextNode) {
                        Log::info('Navigating to next node', [
                            'conversation_id' => $this->conversation->id,
                            'from_node_id'    => $node->id,
                            'to_node_id'      => $nextNode->id,
                            'to_node_type'    => $nextNode->type,
                        ]);
                        $this->executeNodeFlow($nextNode);
                    } else {
                        Log::warning('Next node config.id not found in database', [
                            'conversation_id' => $this->conversation->id,
                            'node_id'         => $node->id,
                            'next_node_id'    => $nextNodeId,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error executing node flow', [
                'conversation_id' => $this->conversation->id,
                'node_id'         => $node->id,
                'error'           => $e->getMessage(),
                'trace'           => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    // =========================================================================
    // FLOW NAVIGATION
    // =========================================================================

    private function startFlow(Conversation $conversation): void
    {
        $this->conversation = $conversation;

        $startNode = $conversation->flowVersion
            ?->nodes()
            ->where('is_entry_point', true)
            ->first();

        if (!$startNode) {
            Log::warning('No entry point found', ['conversation_id' => $conversation->id]);
            return;
        }

        Log::info('Starting flow from entry point', [
            'conversation_id' => $conversation->id,
            'start_node_id'   => $startNode->id,
            'start_node_type' => $startNode->type,
        ]);

        $this->executeNodeFlow($startNode);
    }

    private function resolveNextNodeFromMessage(
        $version,
        FlowNode $currentNode,
        Message  $message,
        array    $variables
    ): ?FlowNode {
        $config = $currentNode->config ?? [];
        $kind   = $config['kind'] ?? $currentNode->type;

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id']  ?? null,
            'text'        => $message->content['text']            ?? null,
            default       => null,
        };

        // ── Buttons ───────────────────────────────────────────────────────────
        if ($kind === 'buttons' && $selectionId) {
            $actions = $currentNode->getSelectActionsForItem($selectionId);
            $targetUuid = $this->runActionsForNode($currentNode, $actions, $variables);

            if ($targetUuid) {
                // IMPORTANT: Look up by config.id (frontend ID), not database uuid
                return $version->nodes()
                    ->where('config->id', $targetUuid)
                    ->first();
            }
        }

        // ── List ──────────────────────────────────────────────────────────────
        if ($kind === 'list' && $selectionId) {
            $actions = $currentNode->getSelectActionsForItem($selectionId);
            $targetUuid = $this->runActionsForNode($currentNode, $actions, $variables);

            if ($targetUuid) {
                // IMPORTANT: Look up by config.id (frontend ID), not database uuid
                return $version->nodes()
                    ->where('config->id', $targetUuid)
                    ->first();
            }
        }

        // ── Fallback: on_enter actions → direct config goTo ───────────────────
        $enterActions = $currentNode->getEnterActions();
        $targetUuid   = $this->runActionsForNode($currentNode, $enterActions, $variables);

        if (!$targetUuid && !empty($config['goTo'])) {
            $targetUuid = $config['goTo'];
        }

        return $targetUuid
            ? $version->nodes()->where('config->id', $targetUuid)->first()
            : null;
    }
    /**
     * Run an array of action config arrays through ActionExecutorService.
     * Returns the first navigation UUID produced, or null.
     */
    private function runActionsForNode(
        FlowNode $node,
        array    $actions,
        array    $variables
    ): ?string {
        foreach ($actions as $action) {
            if (!is_array($action)) continue;

            $target = $this->actionExecutor->execute(
                $this->conversation,
                $node,
                $action,
                $variables
            );

            if ($target) return $target;
        }

        return null;
    }

    // =========================================================================
    // NODE EXECUTION (sends the WhatsApp message)
    // =========================================================================

    private function executeNode(FlowNode $node): array
    {
        if ($node->flow_version_id !== $this->conversation->flow_version_id) {
            Log::error('Version mismatch executing node', [
                'node_id'         => $node->id,
                'node_version_id' => $node->flow_version_id,
                'conv_version_id' => $this->conversation->flow_version_id,
            ]);
            return ['success' => false, 'error' => 'Version mismatch'];
        }

        $kind = $node->config['kind'] ?? $node->type;

        Log::info('Executing node', [
            'node_id'         => $node->id,
            'kind'            => $kind,
            'conversation_id' => $this->conversation->id,
        ]);

        return match ($kind) {
            'trigger'  => ['success' => true,  'stop' => false],
            'message'  => $this->executeMessageNode($node),
            'buttons'  => $this->executeButtonsNode($node),
            'list'     => $this->executeListNode($node),
            'media'    => $this->executeMediaNode($node),
            'location' => $this->executeLocationNode($node),
            'contact'  => $this->executeContactNode($node),
            'end'      => $this->executeEndNode($node),
            default    => ['success' => false, 'error' => "Unknown node kind: {$kind}"],
        };
    }

    // ── Individual node senders ───────────────────────────────────────────────

    private function executeMessageNode(FlowNode $node): array
    {
        $variables = $this->getVariables();
        $text      = $this->variableResolver->resolve($node->config['text'] ?? '', $variables);

        $this->messageService->sendTextMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            $variables
        );

        $this->stampNode($node);

        // Stop and wait if collecting free-text input
        return ['success' => true, 'stop' => !empty($node->config['inputVariable'])];
    }

    private function executeButtonsNode(FlowNode $node): array
    {
        $variables = $this->getVariables();
        $config    = $node->config;

        $text    = $this->variableResolver->resolve($config['btnText'] ?? '', $variables);
        $buttons = array_map(fn($b) => [
            'id'    => $b['id'],
            'title' => $this->variableResolver->resolve($b['label'] ?? '', $variables),
        ], $config['buttons'] ?? []);

        $this->messageService->sendButtonMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            $buttons,
            null,
            null,
            $variables
        );

        $this->stampNode($node);
        return ['success' => true, 'stop' => true]; // always wait for selection
    }

    private function executeListNode(FlowNode $node): array
    {
        $variables  = $this->getVariables();
        $config     = $node->config;

        $header     = $this->variableResolver->resolve($config['listHeader'] ?? '', $variables);
        $body       = $this->variableResolver->resolve($config['listBody']   ?? '', $variables);
        $footer     = $this->variableResolver->resolve($config['listFooter'] ?? '', $variables);
        $buttonText = $this->variableResolver->resolve(
            $config['action']['button'] ?? 'View Options',
            $variables
        );

        $sections = [];
        foreach ($config['action']['sections'] ?? $config['sections'] ?? [] as $section) {
            $rows = [];
            foreach ($section['rows'] ?? [] as $row) {
                $rows[] = [
                    'id'          => $row['id'],
                    'title'       => $this->variableResolver->resolve($row['title']       ?? '', $variables),
                    'description' => $this->variableResolver->resolve($row['description'] ?? '', $variables),
                ];
            }
            $sections[] = [
                'title' => $this->variableResolver->resolve($section['title'] ?? '', $variables),
                'rows'  => $rows,
            ];
        }

        $this->messageService->sendListMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $body,
            $buttonText,
            $sections,
            $header,
            $footer,
            $variables
        );

        $this->stampNode($node);
        return ['success' => true, 'stop' => true]; // always wait for selection
    }

    private function executeMediaNode(FlowNode $node): array
    {
        $variables = $this->getVariables();
        $config    = $node->config;
        $url       = $this->variableResolver->resolve($config['mediaUrl'] ?? '', $variables);

        if (empty($url)) {
            Log::warning('Media node has no URL', ['node_id' => $node->id]);
            return ['success' => false, 'error' => 'Media URL is required'];
        }

        try {
            $this->messageService->sendMediaMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $config['mediaType']    ?? 'image',
                $url,
                $this->variableResolver->resolve($config['mediaCaption']  ?? '', $variables),
                $this->variableResolver->resolve($config['mediaFilename'] ?? '', $variables),
                $variables
            );

            $this->stampNode($node);
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send media', ['node_id' => $node->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeLocationNode(FlowNode $node): array
    {
        $variables = $this->getVariables();
        $config    = $node->config;
        $lat       = (float) ($config['locationLatitude']  ?? 0);
        $lng       = (float) ($config['locationLongitude'] ?? 0);

        if ($lat == 0 || $lng == 0) {
            return ['success' => false, 'error' => 'Valid coordinates are required'];
        }

        try {
            $this->messageService->sendLocationMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $lat,
                $lng,
                $this->variableResolver->resolve($config['locationName']    ?? '', $variables),
                $this->variableResolver->resolve($config['locationAddress'] ?? '', $variables)
            );

            $this->stampNode($node);
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send location', ['node_id' => $node->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeContactNode(FlowNode $node): array
    {
        $variables   = $this->getVariables();
        $contactData = $node->config['contactData'] ?? [];

        if (empty($contactData['name']['formatted_name'])) {
            return ['success' => false, 'error' => 'Contact name is required'];
        }

        try {
            $this->messageService->sendContactMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->resolveContactVariables($contactData, $variables)
            );

            $this->stampNode($node);
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send contact', ['node_id' => $node->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeEndNode(FlowNode $node): array
    {
        $variables = $this->getVariables();

        if (!empty($node->config['text'])) {
            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->variableResolver->resolve($node->config['text'], $variables),
                $variables
            );
            $this->stampNode($node);
        }

        $this->conversation->update(['status' => 'completed', 'ended_at' => now()]);
        $this->logAnalytics($node, 'conversation_completed');

        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // DB ACTIONS (flow_node_actions table — trigger_event on_enter / on_success)
    // =========================================================================

    /**
     * Run DB action rows for a given trigger event through ActionExecutorService.
     * These are NOT navigation actions — they are side-effect actions like
     * saving variables, calling APIs, etc. that run on enter/success.
     */
    private function executeDbActions(FlowNode $node, string $triggerEvent): void
    {
        $actions = $node->actions()
            ->where('trigger_event', $triggerEvent)
            ->where(function ($q) {
                $q->whereNull('source_item_type')
                    ->orWhere('source_item_type', 'node');
            })
            ->orderBy('execution_order')
            ->get();

        if ($actions->count() > 0) {
            Log::info('Executing DB actions', [
                'conversation_id' => $this->conversation->id,
                'node_id'         => $node->id,
                'trigger_event'   => $triggerEvent,
                'action_count'    => $actions->count(),
            ]);
        }

        foreach ($actions as $action) {
            try {
                $this->actionExecutor->execute(
                    $this->conversation,
                    $node,
                    $action->config ?? [],
                    $this->getVariables()
                );
            } catch (\Exception $e) {
                Log::error('DB action execution failed', [
                    'action_id'   => $action->id,
                    'action_type' => $action->action_type,
                    'error'       => $e->getMessage(),
                ]);

                if (!$action->continue_on_failure) {
                    throw $e;
                }
            }
        }
    }

    // =========================================================================
    // USER INPUT PROCESSING
    // =========================================================================

    /**
     * Persist the user's reply into conversation variables.
     *
     * Handles:
     *   inputVariable  → free-text capture (message/input nodes)
     *   saveVariable   → label of the selected button or list row
     *                    (stored on the button/row in config, NOT in actions)
     */
    private function processUserInput(FlowNode $node, Message $message): void
    {
        $config = $node->config ?? [];
        $kind   = $config['kind'] ?? $node->type;

        $inputValue = match ($message->message_type) {
            'text'        => $message->content['text'] ?? '',
            'interactive' => $message->content['response']['title']
                ?? $message->content['response']['id']
                ?? '',
            default       => '',
        };

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text'        => $message->content['text']           ?? null,
            default       => null,
        };

        // Free-text variable
        if (!empty($config['inputVariable']) && $inputValue !== '') {
            $this->conversation->setVariable($config['inputVariable'], $inputValue);
        }

        // Button saveVariable — save the label of the tapped button
        if ($kind === 'buttons' && $selectionId) {
            foreach ($config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->conversation->setVariable($btn['saveVariable'], $btn['label']);
                }
            }
        }

        // List row saveVariable — save the title of the selected row
        if ($kind === 'list' && $selectionId) {
            $sections = $config['action']['sections'] ?? $config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['saveVariable'])) {
                        $this->conversation->setVariable($row['saveVariable'], $row['title']);
                    }
                }
            }
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getVariables(): array
    {
        return $this->conversation->variables()->pluck('value', 'key')->toArray();
    }

    private function getCurrentNode($version, Conversation $conversation): ?FlowNode
    {
        $lastMessage = $conversation->messages()
            ->where('direction', 'outbound')
            ->whereNotNull('flow_node_id')
            ->latest()
            ->first();

        return $lastMessage
            ? $version->nodes()->find($lastMessage->flow_node_id)
            : null;
    }

    /**
     * Stamp the most recent outbound message with this node's DB id.
     * This is how getCurrentNode() tracks position in the flow.
     */
    private function stampNode(FlowNode $node): void
    {
        $this->conversation->messages()
            ->where('direction', 'outbound')
            ->latest()
            ->first()
            ?->update(['flow_node_id' => $node->id]);
    }

    private function logAnalytics(FlowNode $node, string $eventType, array $metadata = []): void
    {
        AnalyticsEvent::create([
            'tenant_id'       => $this->conversation->tenant_id,
            'flow_id'         => $this->conversation->flow_id,
            'conversation_id' => $this->conversation->id,
            'event_type'      => $eventType,
            'node_id'         => $node->id,
            'metadata'        => $metadata,
        ]);
    }

    private function resolveContactVariables(array $contactData, array $variables): array
    {
        $resolve = fn($v) => $this->variableResolver->resolve((string) $v, $variables);

        if (isset($contactData['name'])) {
            $contactData['name'] = array_map($resolve, $contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!isset($contactData[$field])) continue;
            foreach ($contactData[$field] as $i => $item) {
                $contactData[$field][$i] = array_map(
                    fn($v) => is_string($v) ? $resolve($v) : $v,
                    $item
                );
            }
        }

        if (isset($contactData['org'])) {
            $contactData['org'] = array_map($resolve, $contactData['org']);
        }

        return $contactData;
    }
}