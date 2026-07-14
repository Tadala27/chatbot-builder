<?php

namespace App\Services\Bot;

use App\Models\AnalyticsEvent;
use App\Models\BotVersion;
use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChatbotFlowExecutor
{
    public function __construct(
        private DialogRenderer $renderer,
        private NavigationResolver $navigator,
        private ConversationStateManager $state,
        private SystemActionHandler $systemActions,
        private WhatsAppMessageService $messageService,
    ) {
    }

    // =========================================================================
    // ENTRY POINT
    // =========================================================================

    public function processMessage(Conversation $conversation, Message $message): void
    {
        Log::debug('[Flow] Processing message', [
            'conversation_id' => $conversation->id,
            'message_type' => $message->message_type,
        ]);

        $this->navigator->setPendingNavigation(null);

        try {
            $bot = $conversation->bot;
            $version = $conversation->botVersion;

            if (!$bot || !$bot->is_active || !$version) {
                Log::warning('[Flow] Bot or version not available', [
                    'conversation_id' => $conversation->id,
                    'bot_active' => $bot?->is_active,
                    'version_id' => $version?->id,
                ]);

                return;
            }
            if (in_array($message->message_type, ['sticker', 'reaction'], true)) {
                if ($conversation->status !== 'handed_off') {
                    $variables = $this->state->getVariables($conversation);
                    $this->renderer->handleUnsupportedMessage(
                        $conversation,
                        $message->message_type,
                        $variables
                    );
                }

                return;
            }

            // ── Interactive: find which dialog owns the selection ─────────────
            $ownerDialog = null;
            $selectionId = null;

            if ($message->message_type === 'interactive') {
                $selectionId = $message->content['response']['id'] ?? null;
                if ($selectionId) {
                    $ownerDialog = $this->navigator->findDialogOwningSelection($version, $selectionId);
                }
            }

            // ── System-action intercept ───────────────────────────────────────
            if ($ownerDialog && $selectionId) {
                $sysKind = $this->systemActions->detectSystemAction($ownerDialog, $selectionId);
                if ($sysKind !== null) {
                    $this->logAnalytics($conversation, $ownerDialog, "system_action_{$sysKind}");
                    $target = $this->systemActions->execute($sysKind, $conversation, $ownerDialog, $version);
                    if ($target) {
                        $this->executeDialogFlow($target, $conversation);
                    }

                    return;
                }
            }

            $currentDialog = $this->state->getCurrentDialog($version, $conversation);

            // ── Late-selection intercept ──────────────────────────────────────
            // User replied to a dialog that isn't the current one (e.g. scrolled up
            // and tapped an old button). Route it to the dialog that owns the selection.
            if ($ownerDialog && (!$currentDialog || $ownerDialog->id !== $currentDialog->id)) {
                Log::info('[Flow] Late-selection intercept', [
                    'conversation_id' => $conversation->id,
                    'owner_dialog_id' => $ownerDialog->id,
                ]);
                $this->handleDialogResponse($ownerDialog, $message, $version, $conversation);

                return;
            }

            // ── Normal flow ───────────────────────────────────────────────────
            if ($currentDialog) {
                $this->handleDialogResponse($currentDialog, $message, $version, $conversation);
            } else {
                $this->startBot($conversation);
            }
        } catch (\Exception $e) {
            Log::error('[Flow] Error processing message', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function executeDialogFlow(Dialog $dialog, ?Conversation $conversation = null): void
    {
        $conversation ??= $dialog->botVersion->dialogs->first()?->conversation;
        if (!$conversation) {
            throw new \RuntimeException('executeDialogFlow requires a conversation');
        }

        try {
            $version = $conversation->botVersion;
            if (!$version) {
                return;
            }

            $result = $this->executeDialog($dialog, $conversation);

            if ($result['stop'] ?? false) {
                return;
            }

            if ($result['success'] ?? false) {
                $this->continueFromDialog($dialog, $conversation, $version);
            }
        } catch (\Exception $e) {
            Log::error('[Flow] Error executing dialog flow', [
                'conversation_id' => $conversation->id,
                'dialog_id' => $dialog->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // =========================================================================
    // PRIVATE — Routing
    // =========================================================================

    private function handleDialogResponse(
        Dialog $dialog,
        Message $message,
        BotVersion $version,
        Conversation $conversation
    ): void {
        $actionsAlreadyRan = $this->processUserInput($dialog, $message, $conversation);
        $variables = $this->state->getVariables($conversation);
        $dialogActions = $this->getDialogActions($dialog);

        $nextDialog = $this->navigator->resolveFromMessage(
            $version,
            $dialog,
            $message,
            $conversation,
            $variables,
            $dialogActions,
            $actionsAlreadyRan
        );

        if ($nextDialog) {
            $this->executeDialogFlow($nextDialog, $conversation);
        } else {
            Log::warning('[Flow] No next dialog after user input', [
                'conversation_id' => $conversation->id,
                'dialog_id' => $dialog->id,
            ]);
        }
    }

    private function continueFromDialog(
        Dialog $dialog,
        Conversation $conversation,
        BotVersion $version
    ): void {
        $variables = $this->state->getVariables($conversation);
        $dialogActions = $this->getDialogActions($dialog);

        $nextDialogId = $this->navigator->runActionChain($conversation, $dialog, $dialogActions, $variables);

        if (!$nextDialogId && !empty($dialog->config['goTo'])) {
            $nextDialogId = $dialog->config['goTo'];
        }

        if (!$nextDialogId) {
            return;
        }

        $nextDialog = $this->navigator->findDialogByConfigId($version, $nextDialogId);
        if ($nextDialog) {
            $this->executeDialogFlow($nextDialog, $conversation);
        } else {
            Log::warning('[Flow] Next dialog config.id not found', [
                'dialog_id' => $dialog->id,
                'next_dialog_id' => $nextDialogId,
            ]);
        }
    }

    private function startBot(Conversation $conversation): void
    {
        $startDialog = $conversation->botVersion
            ?->dialogs()
            ->where('is_entry_point', true)
            ->first();

        if (!$startDialog) {
            Log::warning('[Flow] No entry point dialog', ['conversation_id' => $conversation->id]);

            return;
        }

        $this->executeDialogFlow($startDialog, $conversation);
    }

    // =========================================================================
    // PRIVATE — Dialog execution
    // =========================================================================

    private function executeDialog(Dialog $dialog, Conversation $conversation): array
    {
        if ($dialog->bot_version_id !== $conversation->bot_version_id) {
            Log::error('[Flow] Version mismatch executing dialog', [
                'dialog_id' => $dialog->id,
                'dialog_bot_version' => $dialog->bot_version_id,
                'conv_bot_version' => $conversation->bot_version_id,
            ]);

            return ['success' => false, 'error' => 'Version mismatch'];
        }

        $variables = $this->state->getVariables($conversation);
        $result = $this->renderer->render($dialog, $conversation, $variables);

        if ($result['success'] ?? false) {
            $this->state->stampDialog($conversation, $dialog);
            $this->logAnalytics($conversation, $dialog, 'dialog_entered');

            // Text-input message nodes: wait for user's next reply
            if ($dialog->kind === 'message') {
                $hasVariableAction = collect($this->getDialogActions($dialog))
                    ->contains(fn ($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
                if ($hasVariableAction) {
                    $result['stop'] = true;
                }
            }

            if ($dialog->kind === 'end') {
                $conversation->update(['status' => 'completed', 'ended_at' => now()]);
                $this->logAnalytics($conversation, $dialog, 'dialog_completed');
                $this->logAnalytics($conversation, $dialog, 'conversation_completed');
            }
        }

        return $result;
    }

    private function processUserInput(
        Dialog $dialog,
        Message $message,
        Conversation $conversation
    ): bool {
        $config = $dialog->config ?? [];
        $kind = $dialog->kind;

        $inputValue = match ($message->message_type) {
            'text' => $message->content['text'] ?? '',
            'interactive' => $message->content['response']['title']
                          ?? $message->content['response']['id']
                          ?? '',
            default => '',
        };

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text' => $message->content['text'] ?? null,
            default => null,
        };

        // 1. Save inputVariable (message nodes with text input)
        $inputVar = $config['inputVariable'] ?? $dialog->input_variable ?? null;
        if ($inputVar && $inputValue !== '') {
            $this->state->setVariable($conversation, $inputVar, $inputValue);
        }

        // 2. Save replyVariable for location / contact / media nodes
        $replyVar = $config['replyVariable'] ?? null;
        if ($replyVar && $inputValue !== '' && in_array($kind, ['location', 'contact', 'media'], true)) {
            $this->state->setVariable($conversation, $replyVar, $inputValue);
        }

        // 3. Save button/row-level saveVariable
        if ($selectionId) {
            $this->saveSelectionVariables($dialog, $selectionId, $conversation);
        }

        // 4. Save save_response DialogOption selection
        if (in_array($kind, ['buttons', 'list'], true) && $selectionId) {
            $option = $dialog->options()->where('external_id', $selectionId)->first();
            if ($option && $option->save_response) {
                $this->state->saveOptionSelection(
                    $conversation,
                    $dialog->id,
                    $selectionId,
                    $option->title ?? ''
                );
            }
        }

        // 5. Run dialog-level actions for text-input dialogs
        $dialogActions = $this->getDialogActions($dialog);
        $hasVariableAction = collect($dialogActions)
            ->contains(fn ($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
        $isTextReply = $message->message_type === 'text';

        if (
            $isTextReply
            && ($inputVar || $hasVariableAction)
            && !in_array($kind, ['buttons', 'list', 'nav_buttons'], true)
        ) {
            $variables = $this->state->getVariables($conversation);
            $actionsWithInput = array_map(function ($action) use ($inputValue) {
                if (($action['kind'] ?? $action['action_type'] ?? null) === 'variable') {
                    $action['_resolvedInput'] = $inputValue;
                }

                return $action;
            }, $dialogActions);

            $target = $this->navigator->runActionChain(
                $conversation,
                $dialog,
                $actionsWithInput,
                $variables
            );

            if ($target) {
                $this->navigator->setPendingNavigation($target);
            }

            $this->logAnalytics($conversation, $dialog, 'dialog_completed');

            return true;
        }

        $this->logAnalytics($conversation, $dialog, 'dialog_completed');

        return false;
    }

    private function saveSelectionVariables(
        Dialog $dialog,
        string $selectionId,
        Conversation $conversation
    ): void {
        $config = $dialog->config;

        if ($dialog->kind === 'buttons') {
            foreach ($config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->state->setVariable(
                        $conversation,
                        $btn['saveVariable'],
                        $btn['label'] ?? $btn['title'] ?? ''
                    );
                }
            }
        }

        if ($dialog->kind === 'list') {
            $sections = $config['action']['sections'] ?? $config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['saveVariable'])) {
                        $this->state->setVariable($conversation, $row['saveVariable'], $row['title'] ?? '');
                    }
                }
            }
        }
    }

    private function getDialogActions(Dialog $dialog): array
    {
        return $dialog->actions()
            ->with(['conditions.dialogOption', 'thenAction'])
            ->where('is_active', true)
            ->orderBy('action_order')
            ->get()
            ->map(function ($a) {
                $config = is_array($a->config) ? $a->config : (json_decode($a->config, true) ?? []);

                if ($a->action_type === 'condition' && $a->conditions->isNotEmpty()) {
                    $branchCount = count($config['branches'] ?? []);
                    if ($branchCount <= 1) {
                        $config['_db_conditions'] = $a->conditions->map(fn ($c) => [
                            'type' => $c->condition_type,
                            'operator' => $c->condition_operator,
                            'source' => $c->variable_key ?? $c->option_id,
                            'value' => $c->condition_value,
                            'option_id' => $c->option_id,
                            'response_field' => $c->response_field,
                            'response_path' => $c->response_path,
                            'condition_order' => $c->condition_order,
                        ])->toArray();
                    }
                }

                $config['_db_action_id'] = $a->then_action_id;

                return array_merge($config, ['kind' => $a->action_type]);
            })
            ->toArray();
    }

    // =========================================================================
    // PRIVATE — Analytics
    // =========================================================================

    private function logAnalytics(
        Conversation $conversation,
        Dialog $dialog,
        string $eventType,
        array $extra = []
    ): void {
        try {
            AnalyticsEvent::create([
                'bot_id' => $conversation->bot_id,
                'conversation_id' => $conversation->id,
                'event_type' => $eventType,
                'metadata' => array_merge([
                    'dialog_id' => $dialog->id,
                    'dialog_kind' => $dialog->kind,
                    'bot_version_id' => $conversation->bot_version_id,
                ], $extra),
            ]);
        } catch (\Exception $e) {
            Log::warning('[Flow] Analytics event logging failed', [
                'event_type' => $eventType,
                'dialog_id' => $dialog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
