<?php

namespace App\Services;

use App\Models\AnalyticsEvent;
use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationVariable;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class ChatbotFlowExecutor
{
    private Conversation $conversation;

    /**
     * Stores a navigation target UUID produced by processUserInput's action chain.
     * Consumed once by resolveNextDialogFromMessage to avoid double-executing actions.
     */
    private ?string $pendingNavigationTarget = null;

    public function __construct(
        private WhatsAppMessageService $messageService,
        private VariableResolver       $variableResolver,
        private ActionExecutorService  $actionExecutor,
    ) {}

    // =========================================================================
    // MAIN ENTRY POINT
    // =========================================================================

    public function processMessage(Conversation $conversation, Message $message): void
    {
        $this->conversation           = $conversation;
        $this->pendingNavigationTarget = null;

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

            if ($message->message_type === 'interactive') {
                $selId = $message->content['response']['id'] ?? null;
                if ($selId) {
                    $ownerDialog = $this->findDialogOwningSelection($version, $selId);
                    if ($ownerDialog) {
                        $sysAction = $this->detectSystemAction($ownerDialog, $selId);
                        if ($sysAction !== null) {
                            Log::info('System action intercepted', [
                                'conversation_id' => $conversation->id,
                                'action'          => $sysAction,
                                'owner_dialog_id' => $ownerDialog->id,
                                'selection_id'    => $selId,
                            ]);
                            $this->executeSystemAction($sysAction, $ownerDialog, $version);
                            return;
                        }
                    }
                }
            }

            // ── 2. Late-selection intercept ───────────────────────────────────
            if ($message->message_type === 'interactive') {
                $selectionId = $message->content['response']['id'] ?? null;

                if ($selectionId) {
                    $ownerDialog   = $this->findDialogOwningSelection($version, $selectionId);
                    $currentDialog = $this->getCurrentDialog($version, $conversation);

                    if ($ownerDialog && (!$currentDialog || $ownerDialog->id !== $currentDialog->id)) {
                        Log::info('Late-selection intercept', [
                            'conversation_id'   => $conversation->id,
                            'current_dialog_id' => $currentDialog?->id,
                            'owner_dialog_id'   => $ownerDialog->id,
                            'owner_dialog_kind' => $ownerDialog->kind,
                            'selection_id'      => $selectionId,
                        ]);

                        $actionsAlreadyRan = $this->processUserInput($ownerDialog, $message);
                        $variables         = $this->getVariables();
                        $nextDialog        = $this->resolveNextDialogFromMessage(
                            $version,
                            $ownerDialog,
                            $message,
                            $variables,
                            $actionsAlreadyRan
                        );

                        if ($nextDialog) {
                            $this->executeDialogFlow($nextDialog);
                        } else {
                            Log::warning('Late-selection: no target dialog found', [
                                'conversation_id' => $conversation->id,
                                'owner_dialog_id' => $ownerDialog->id,
                                'selection_id'    => $selectionId,
                            ]);
                        }
                        return;
                    }
                }
            }

            // ── 3. Normal flow ────────────────────────────────────────────────
            $currentDialog = $this->getCurrentDialog($version, $conversation);

            if ($currentDialog) {
                Log::info('Current dialog found — processing user input', [
                    'conversation_id'   => $conversation->id,
                    'current_dialog_id' => $currentDialog->id,
                    'dialog_kind'       => $currentDialog->kind,
                ]);

                $actionsAlreadyRan = $this->processUserInput($currentDialog, $message);
                $variables         = $this->getVariables();
                $nextDialog        = $this->resolveNextDialogFromMessage(
                    $version,
                    $currentDialog,
                    $message,
                    $variables,
                    $actionsAlreadyRan
                );

                if ($nextDialog) {
                    $this->executeDialogFlow($nextDialog);
                } else {
                    Log::warning('No next dialog found after user input', [
                        'conversation_id'   => $conversation->id,
                        'current_dialog_id' => $currentDialog->id,
                    ]);
                }
            } else {
                Log::info('No current dialog — starting flow from entry point', [
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

    // =========================================================================
    // SYSTEM NAVIGATION
    // =========================================================================

    private function detectSystemAction(Dialog $dialog, string $selectionId): ?string
    {
        foreach ($this->getActionsForSelection($dialog, $selectionId) as $action) {
            $kind = $action['kind'] ?? $action['action_type'] ?? null;
            if (in_array($kind, ['start_flow', 'go_home', 'go_back', 'talk_to_agent'], true)) {
                return $kind;
            }
        }
        return null;
    }

    private function executeSystemAction(string $kind, Dialog $sourceDialog, $version): void
    {
        match ($kind) {
            'start_flow'    => $this->startFlow($this->conversation),
            'go_home'       => $this->doGoHome($version),
            'go_back'       => $this->doGoBack($version),
            'talk_to_agent' => $this->doTalkToAgent($sourceDialog, $version),
            default         => null,
        };

        $this->logAnalytics($sourceDialog, "system_action_{$kind}");
    }

    private function doGoHome($version): void
    {
        $home = $version->dialogs()->where('is_entry_point', true)->first();

        if (!$home) {
            Log::warning('go_home: no entry-point dialog found', [
                'conversation_id' => $this->conversation->id,
            ]);
            return;
        }

        $this->getOrCreateContext()->update(['dialog_history' => []]);

        Log::info('go_home: navigating to entry point', [
            'conversation_id' => $this->conversation->id,
            'home_dialog_id'  => $home->id,
        ]);

        $this->executeDialogFlow($home);
    }

    private function doGoBack($version): void
    {
        $ctx     = $this->getOrCreateContext();
        $history = $ctx->dialog_history ?? [];

        if (count($history) >= 2) {
            array_pop($history);
            $prevId = array_pop($history);
            $ctx->update(['dialog_history' => $history]);

            $prevDialog = $version->dialogs()->find((int) $prevId);
            if ($prevDialog) {
                Log::info('go_back: navigating to previous dialog', [
                    'conversation_id' => $this->conversation->id,
                    'prev_dialog_id'  => $prevDialog->id,
                ]);
                $this->executeDialogFlow($prevDialog);
                return;
            }
        }

        Log::info('go_back: history too shallow, falling back to home', [
            'conversation_id' => $this->conversation->id,
        ]);
        $this->doGoHome($version);
    }

    private function doTalkToAgent(Dialog $sourceDialog, $version): void
    {
        $this->conversation->update([
            'status'   => 'handed_off',
            'metadata' => array_merge($this->conversation->metadata ?? [], [
                'handoff_source_dialog' => $sourceDialog->id,
                'handoff_reason'        => 'user_requested',
                'handoff_at'            => now()->toISOString(),
            ]),
        ]);

        $botConfig   = $this->conversation->flow?->bot?->configuration;
        $agentDialog = null;

        if ($botConfig && !empty($botConfig->agent_dialog_id)) {
            $agentDialog = $version->dialogs()->find((int) $botConfig->agent_dialog_id);
        }

        if ($agentDialog) {
            $this->executeDialog($agentDialog);
        } else {
            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                'You are being connected to a live agent. Please wait…',
                []
            );
        }

        Log::info('talk_to_agent: handed off', [
            'conversation_id'  => $this->conversation->id,
            'source_dialog_id' => $sourceDialog->id,
            'agent_dialog_id'  => $agentDialog?->id,
        ]);
    }

    // =========================================================================
    // DIALOG SEARCH
    // =========================================================================

    private function findDialogOwningSelection($version, string $selectionId): ?Dialog
    {
        $dialogs = $version->dialogs()->whereIn('kind', ['buttons', 'list', 'nav_buttons'])->get();

        foreach ($dialogs as $dialog) {
            if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
                foreach ($dialog->config['buttons'] ?? [] as $btn) {
                    if (($btn['id'] ?? '') === $selectionId) {
                        return $dialog;
                    }
                }
            }

            if ($dialog->kind === 'list') {
                $sections = $dialog->config['action']['sections']
                    ?? $dialog->config['sections']
                    ?? [];
                foreach ($sections as $section) {
                    foreach ($section['rows'] ?? [] as $row) {
                        if (($row['id'] ?? '') === $selectionId) {
                            return $dialog;
                        }
                    }
                }
            }
        }

        return null;
    }

    // =========================================================================
    // DIALOG FLOW EXECUTION
    // =========================================================================

    public function executeDialogFlow(Dialog $dialog, ?Conversation $conversation = null): void
    {
        if ($conversation) {
            $this->conversation = $conversation;
        }

        try {
            $version = $this->conversation->flowVersion;
            if (!$version) return;

            Log::info('Executing dialog flow', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
                'dialog_kind'     => $dialog->kind,
            ]);

            $result = $this->executeDialog($dialog);

            Log::info('Dialog execution result', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
                'result'          => $result,
            ]);

            if ($result['stop'] ?? false) {
                Log::info('Dialog waiting for user input', [
                    'conversation_id' => $this->conversation->id,
                    'dialog_id'       => $dialog->id,
                ]);
                return;
            }

            if ($result['success'] ?? false) {
                $variables    = $this->getVariables();
                $nextDialogId = $this->runConfigActions(
                    $dialog,
                    $this->getDialogActions($dialog),
                    $variables
                );

                if (!$nextDialogId && !empty($dialog->config['goTo'])) {
                    $nextDialogId = $dialog->config['goTo'];
                }

                if ($nextDialogId) {
                    $nextDialog = $version->dialogs()
                        ->where('config->id', $nextDialogId)
                        ->first();

                    if ($nextDialog) {
                        Log::info('Navigating to next dialog', [
                            'conversation_id' => $this->conversation->id,
                            'from_dialog_id'  => $dialog->id,
                            'to_dialog_id'    => $nextDialog->id,
                            'to_dialog_kind'  => $nextDialog->kind,
                        ]);
                        $this->executeDialogFlow($nextDialog);
                    } else {
                        Log::warning('Next dialog config.id not found', [
                            'conversation_id' => $this->conversation->id,
                            'dialog_id'       => $dialog->id,
                            'next_dialog_id'  => $nextDialogId,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Error executing dialog flow', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $dialog->id,
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

        $startDialog = $conversation->flowVersion
            ?->dialogs()
            ->where('is_entry_point', true)
            ->first();

        if (!$startDialog) {
            Log::warning('No entry point dialog found', ['conversation_id' => $conversation->id]);
            return;
        }

        Log::info('Starting flow from entry point', [
            'conversation_id'   => $conversation->id,
            'start_dialog_id'   => $startDialog->id,
            'start_dialog_kind' => $startDialog->kind,
        ]);

        $this->executeDialogFlow($startDialog);
    }

    /**
     * Resolve the next dialog to execute after the user has replied.
     *
     * @param bool $actionsAlreadyRan  True when processUserInput already ran this
     *                                  dialog's DB actions (text-input dialogs with
     *                                  variable actions). Prevents double-execution
     *                                  and uses $pendingNavigationTarget instead.
     */
    private function resolveNextDialogFromMessage(
        $version,
        Dialog  $currentDialog,
        Message $message,
        array   $variables,
        bool    $actionsAlreadyRan = false
    ): ?Dialog {
        $kind = $currentDialog->kind;

        $selectionId = match ($message->message_type) {
            'interactive' => $message->content['response']['id'] ?? null,
            'text'        => $message->content['text']           ?? null,
            default       => null,
        };

        // ── Buttons / nav_buttons ─────────────────────────────────────────────
        if (in_array($kind, ['buttons', 'nav_buttons'], true) && $selectionId) {
            // 1. Per-button direct goTo
            foreach ($currentDialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['goTo'])) {
                    $dialog = $version->dialogs()->where('config->id', $btn['goTo'])->first();
                    if ($dialog) return $dialog;
                }
            }

            // 2. Per-button inline actions (embedded in config)
            $selectionActions = $this->getActionsForSelection($currentDialog, $selectionId);
            if (!empty($selectionActions)) {
                $targetId = $this->runConfigActions($currentDialog, $selectionActions, $variables);
                if ($targetId) {
                    return $version->dialogs()->where('config->id', $targetId)->first();
                }
            }

            // 3. Dialog-level DB actions
            $targetId = $this->runConfigActions(
                $currentDialog,
                $this->getDialogActions($currentDialog),
                $variables
            );
            if ($targetId) {
                return $version->dialogs()->where('config->id', $targetId)->first();
            }
        }

        // ── List ──────────────────────────────────────────────────────────────
        if ($kind === 'list' && $selectionId) {
            $sections = $currentDialog->config['action']['sections']
                ?? $currentDialog->config['sections']
                ?? [];

            // 1. Per-row direct goTo
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['goTo'])) {
                        $dialog = $version->dialogs()->where('config->id', $row['goTo'])->first();
                        if ($dialog) return $dialog;
                    }
                }
            }

            // 2. Per-row inline actions
            $selectionActions = $this->getActionsForSelection($currentDialog, $selectionId);
            if (!empty($selectionActions)) {
                $targetId = $this->runConfigActions($currentDialog, $selectionActions, $variables);
                if ($targetId) {
                    return $version->dialogs()->where('config->id', $targetId)->first();
                }
            }

            // 3. Dialog-level DB actions
            $targetId = $this->runConfigActions(
                $currentDialog,
                $this->getDialogActions($currentDialog),
                $variables
            );
            if ($targetId) {
                return $version->dialogs()->where('config->id', $targetId)->first();
            }
        }

        // ── message / other kinds ─────────────────────────────────────────────
        // If processUserInput already ran this dialog's actions (text-input dialog
        // with variable action), use the navigation target it produced instead of
        // re-running actions — which would overwrite the variable with stale input.
        if ($actionsAlreadyRan) {
            $targetId = $this->pendingNavigationTarget;
            $this->pendingNavigationTarget = null; // consume once

            Log::info('Using pending navigation target from processUserInput', [
                'conversation_id' => $this->conversation->id,
                'dialog_id'       => $currentDialog->id,
                'target_id'       => $targetId,
            ]);
        } else {
            $targetId = $this->runConfigActions(
                $currentDialog,
                $this->getDialogActions($currentDialog),
                $variables
            );
        }

        if (!$targetId && !empty($currentDialog->config['goTo'])) {
            $targetId = $currentDialog->config['goTo'];
        }

        return $targetId
            ? $version->dialogs()->where('config->id', $targetId)->first()
            : null;
    }

    private function getActionsForSelection(Dialog $dialog, string $selectionId): array
    {
        if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId) {
                    return $btn['actions'] ?? [];
                }
            }
        }

        if ($dialog->kind === 'list') {
            $sections = $dialog->config['action']['sections'] ?? $dialog->config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId) {
                        return $row['actions'] ?? [];
                    }
                }
            }
        }

        return [];
    }

    private function runConfigActions(Dialog $dialog, array $actions, array $variables): ?string
    {
        foreach ($actions as $action) {
            if (!is_array($action)) continue;

            $result = $this->executeSingleAction($dialog, $action, $variables);
            $variables = $this->getVariables(); // re-fetch after every action

            if ($result !== null && !str_starts_with((string) $result, '__system:')) {
                return $result; // navigation found — stop chain
            }
        }

        return null;
    }

    /**
     * Execute one action and then follow its 'then' chain recursively.
     * Returns a navigation UUID if any action in the chain produces one, else null.
     */
    private function executeSingleAction(Dialog $dialog, array $action, array $variables): ?string
    {
        $target = $this->actionExecutor->execute(
            $this->conversation,
            $dialog,
            $action,
            $variables
        );

        $variables = $this->getVariables();

        // If this action produced a navigation, return it immediately (don't follow then)
        if ($target !== null && !str_starts_with((string) $target, '__system:')) {
            return $target;
        }

        // Follow the 'then' chain — either from config or from DB then_action_id
        $thenAction = $this->resolveThenAction($dialog, $action);

        if ($thenAction !== null) {
            return $this->executeSingleAction($dialog, $thenAction, $variables);
        }

        return $target; // null or system sentinel
    }


    private function resolveThenAction(Dialog $dialog, array $action): ?array
    {
        // Config-embedded 'then' (from frontend tree structure)
        if (!empty($action['then']) && is_array($action['then'])) {
            return $action['then'];
        }

        // DB then_action_id (set during syncActions)
        $actionId = $action['_db_action_id'] ?? null;
        if ($actionId) {
            $thenAction = \App\Models\Action::find($actionId)?->thenAction;
            if ($thenAction) {
                $config = is_array($thenAction->config)
                    ? $thenAction->config
                    : (json_decode($thenAction->config, true) ?? []);
                return array_merge($config, [
                    'kind'           => $thenAction->action_type,
                    '_db_action_id'  => $thenAction->then_action_id,
                ]);
            }
        }

        return null;
    }

    // =========================================================================
    // DIALOG EXECUTION (sends the WhatsApp message)
    // =========================================================================

    private function executeDialog(Dialog $dialog): array
    {
        if ($dialog->flow_version_id !== $this->conversation->flow_version_id) {
            Log::error('Version mismatch executing dialog', [
                'dialog_id'      => $dialog->id,
                'dialog_version' => $dialog->flow_version_id,
                'conv_version'   => $this->conversation->flow_version_id,
            ]);
            return ['success' => false, 'error' => 'Version mismatch'];
        }

        $kind = $dialog->kind;

        Log::info('Executing dialog', [
            'dialog_id'       => $dialog->id,
            'kind'            => $kind,
            'conversation_id' => $this->conversation->id,
        ]);

        return match ($kind) {
            'trigger'     => ['success' => true, 'stop' => false],
            'message'     => $this->executeMessageDialog($dialog),
            'buttons'     => $this->executeButtonsDialog($dialog),
            'list'        => $this->executeListDialog($dialog),
            'media'       => $this->executeMediaDialog($dialog),
            'location'    => $this->executeLocationDialog($dialog),
            'contact'     => $this->executeContactDialog($dialog),
            'end'         => $this->executeEndDialog($dialog),
            'nav_buttons' => $this->executeNavButtonsDialog($dialog),
            default       => ['success' => false, 'error' => "Unknown dialog kind: {$kind}"],
        };
    }

    private function executeMessageDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $text      = $this->variableResolver->resolve($dialog->config['text'] ?? '', $variables);

        $this->messageService->sendTextMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            $variables
        );

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        // Stop and wait if the dialog has a variable DB action (collects user input)
        $hasVariableAction = collect($this->getDialogActions($dialog))
            ->contains(fn($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');

        $stop = !empty($dialog->config['inputVariable'])
            || !empty($dialog->input_variable)
            || $hasVariableAction;

        return ['success' => true, 'stop' => $stop];
    }

    private function executeButtonsDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;

        $text    = $this->variableResolver->resolve($config['btnText'] ?? $config['text'] ?? '', $variables);
        $buttons = array_map(fn($b) => [
            'id'    => $b['id'],
            'title' => $this->variableResolver->resolve($b['label'] ?? $b['title'] ?? '', $variables),
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

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        return ['success' => true, 'stop' => true];
    }

    private function executeListDialog(Dialog $dialog): array
    {
        $variables  = $this->getVariables();
        $config     = $dialog->config;

        $header     = $this->variableResolver->resolve($config['listHeader'] ?? '', $variables);
        $body       = $this->variableResolver->resolve($config['listBody'] ?? $config['text'] ?? '', $variables);
        $footer     = $this->variableResolver->resolve($config['listFooter'] ?? '', $variables);
        $buttonText = $this->variableResolver->resolve(
            $config['action']['button'] ?? $config['buttonText'] ?? 'View Options',
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

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        return ['success' => true, 'stop' => true];
    }

    private function executeNavButtonsDialog(Dialog $dialog): array
    {
        $config  = $dialog->config;
        $buttons = [];

        if (!empty($config['includeStartFlow'])) {
            $buttons[] = [
                'id'      => "sys_start_flow_{$dialog->id}",
                'title'   => $config['labelStartFlow'] ?? 'Start Flow',
                'actions' => [['kind' => 'start_flow']],
            ];
        }
        if (!empty($config['includeGoHome'])) {
            $buttons[] = [
                'id'      => "sys_go_home_{$dialog->id}",
                'title'   => $config['labelGoHome'] ?? 'Main Menu',
                'actions' => [['kind' => 'go_home']],
            ];
        }
        if (!empty($config['includeGoBack'])) {
            $buttons[] = [
                'id'      => "sys_go_back_{$dialog->id}",
                'title'   => $config['labelGoBack'] ?? 'Go Back',
                'actions' => [['kind' => 'go_back']],
            ];
        }
        if (!empty($config['includeTalkToAgent'])) {
            $buttons[] = [
                'id'      => "sys_talk_agent_{$dialog->id}",
                'title'   => $config['labelTalkToAgent'] ?? 'Talk to Agent',
                'actions' => [['kind' => 'talk_to_agent']],
            ];
        }

        if (empty($buttons)) {
            return ['success' => true, 'stop' => false];
        }

        $buttons   = array_slice($buttons, 0, 3);
        $variables = $this->getVariables();
        $text      = $this->variableResolver->resolve(
            $config['text'] ?? 'What would you like to do?',
            $variables
        );

        $this->messageService->sendButtonMessage(
            $this->conversation->whatsappAccount,
            $this->conversation->whatsapp_user_phone,
            $text,
            array_map(fn($b) => ['id' => $b['id'], 'title' => $b['title']], $buttons),
            null,
            null,
            $variables
        );

        $dialog->config = array_merge($config, ['buttons' => $buttons]);

        $this->stampDialog($dialog);
        $this->logAnalytics($dialog, 'dialog_entered');

        return ['success' => true, 'stop' => true];
    }

    private function executeMediaDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;
        $mediaFile = null;
        $mimeType  = null;
        $url       = '';

        if (!empty($config['mediaFileId'])) {
            $mediaFile = \App\Models\BotMediaFile::find($config['mediaFileId']);
        }

        if ($mediaFile) {
            $url      = $mediaFile->url;
            $mimeType = $mediaFile->mime_type;
        } else {
            $url = $this->variableResolver->resolve($config['mediaUrl'] ?? '', $variables);
        }

        if (empty($url)) {
            Log::warning('Media dialog has no URL', ['dialog_id' => $dialog->id]);
            return ['success' => false, 'error' => 'Media URL is required'];
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = rtrim(config('app.url'), '/') . '/' . ltrim($url, '/');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            Log::error('Media dialog: invalid URL', ['dialog_id' => $dialog->id, 'url' => $url]);
            return ['success' => false, 'error' => 'Invalid media URL.'];
        }

        $mediaType      = $config['mediaType'] ?? 'image';
        $caption        = $this->variableResolver->resolve($config['mediaCaption']  ?? '', $variables);
        $filename       = $this->variableResolver->resolve($config['mediaFilename'] ?? '', $variables);
        $stop           = !empty($config['waitForReply']);
        $isUploadedFile = $mediaFile !== null;

        if (!$isUploadedFile && !$this->urlLooksLikeMediaFile($url)) {
            $body = trim(($caption ? $caption . "\n" : '') . $url);
            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $body,
                $variables
            );
            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => $stop];
        }

        if (!$isUploadedFile && $mediaType === 'document') {
            $mimeType = $this->inferMimeType($url, $mediaType);
        }

        try {
            $this->messageService->sendMediaMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $mediaType,
                $url,
                $caption,
                $filename,
                $variables,
                $mimeType
            );
            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => $stop];
        } catch (\Exception $e) {
            Log::error('Failed to send media dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function urlLooksLikeMediaFile(string $url): bool
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (empty($ext)) return false;
        return in_array($ext, [
            'jpg',
            'jpeg',
            'png',
            'webp',
            'gif',
            'mp4',
            '3gp',
            '3gpp',
            'mov',
            'avi',
            'mkv',
            'webm',
            'mp3',
            'aac',
            'amr',
            'ogg',
            'oga',
            'opus',
            'm4a',
            'wav',
            'flac',
            'pdf',
            'doc',
            'docx',
            'xls',
            'xlsx',
            'ppt',
            'pptx',
            'txt',
            'zip',
            'csv',
        ], true);
    }

    private function executeLocationDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();
        $config    = $dialog->config;
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
            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send location dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeContactDialog(Dialog $dialog): array
    {
        $variables   = $this->getVariables();
        $contactData = $dialog->config['contactData'] ?? [];

        if (empty($contactData['name']['formatted_name'])) {
            return ['success' => false, 'error' => 'Contact name is required'];
        }

        try {
            $this->messageService->sendContactMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->resolveContactVariables($contactData, $variables)
            );
            $this->stampDialog($dialog);
            $this->logAnalytics($dialog, 'dialog_entered');
            return ['success' => true, 'stop' => false];
        } catch (\Exception $e) {
            Log::error('Failed to send contact dialog', ['dialog_id' => $dialog->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function executeEndDialog(Dialog $dialog): array
    {
        $variables = $this->getVariables();

        if (!empty($dialog->config['text'])) {
            $this->messageService->sendTextMessage(
                $this->conversation->whatsappAccount,
                $this->conversation->whatsapp_user_phone,
                $this->variableResolver->resolve($dialog->config['text'], $variables),
                $variables
            );
            $this->stampDialog($dialog);
        }

        $this->conversation->update(['status' => 'completed', 'ended_at' => now()]);
        $this->logAnalytics($dialog, 'dialog_completed');
        $this->logAnalytics($dialog, 'conversation_completed');

        return ['success' => true, 'stop' => true];
    }

    // =========================================================================
    // USER INPUT PROCESSING
    // =========================================================================

    /**
     * Process inbound user input against the current dialog.
     *
     * Returns TRUE if this method ran the dialog's DB actions (i.e. it's a
     * text-input message dialog with a variable action). The caller uses this
     * flag to avoid re-running actions in resolveNextDialogFromMessage, and
     * reads $pendingNavigationTarget for the resulting navigation UUID.
     */
    private function processUserInput(Dialog $dialog, Message $message): bool
    {
        $config = $dialog->config ?? [];
        $kind   = $dialog->kind;

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

        // ── 1. Save inputVariable ─────────────────────────────────────────────
        $inputVar = $config['inputVariable'] ?? $dialog->input_variable ?? null;
        if ($inputVar && $inputValue !== '') {
            $this->setVariable($inputVar, $inputValue);
        }

        // ── 2. Save button/row-level saveVariable ─────────────────────────────
        if ($kind === 'buttons' && $selectionId) {
            foreach ($config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->setVariable($btn['saveVariable'], $btn['label'] ?? $btn['title'] ?? '');
                }
            }
        }

        if ($kind === 'list' && $selectionId) {
            $sections = $config['action']['sections'] ?? $config['sections'] ?? [];
            foreach ($sections as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (($row['id'] ?? '') === $selectionId && !empty($row['saveVariable'])) {
                        $this->setVariable($row['saveVariable'], $row['title'] ?? '');
                    }
                }
            }
        }

        // ── 3. Save save_response DialogOption selection ──────────────────────
        // When a DialogOption has save_response=true, persist the selection keyed
        // by dialog ID so condition actions can check it via __dialog_{id}_selection.
        // Overwrites any previous selection for this dialog — only the latest is kept.
        if (in_array($kind, ['buttons', 'list'], true) && $selectionId) {
            $selectedOption = $dialog->options()
                ->where('external_id', $selectionId)
                ->first();

            if ($selectedOption && $selectedOption->save_response) {
                $this->setVariable("__dialog_{$dialog->id}_selection", $selectionId);
                $this->setVariable("__dialog_{$dialog->id}_selection_title", $selectedOption->title ?? '');

                Log::info('Saved response stored', [
                    'conversation_id' => $this->conversation->id,
                    'dialog_id'       => $dialog->id,
                    'selection_id'    => $selectionId,
                    'selection_title' => $selectedOption->title ?? '',
                ]);
            }
        }

        // ── 4. Run dialog-level DB actions for text-input dialogs ─────────────
        // Only fires for message dialogs waiting for a text reply (has a variable
        // action). Button/list dialogs handle routing via resolveNextDialogFromMessage.
        $dialogActions     = $this->getDialogActions($dialog);
        $hasVariableAction = collect($dialogActions)
            ->contains(fn($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
        $isTextReply = $message->message_type === 'text';

        if (
            $isTextReply &&
            ($inputVar || $hasVariableAction) &&
            !in_array($kind, ['buttons', 'list', 'nav_buttons'], true)
        ) {
            // Re-fetch so the variable action sees the just-stored inputVar
            $variables = $this->getVariables();

            // Inject current input text into variable actions to guarantee THIS
            // message's value is used (not whatever getLastUserInput() would return)
            $actionsWithInput = array_map(function ($action) use ($inputValue) {
                if (($action['kind'] ?? $action['action_type'] ?? null) === 'variable') {
                    $action['_resolvedInput'] = $inputValue;
                }
                return $action;
            }, $dialogActions);

            // Run actions in order; capture any navigation target produced
            $navigationTarget = $this->runConfigActions($dialog, $actionsWithInput, $variables);

            if ($navigationTarget) {
                $this->pendingNavigationTarget = $navigationTarget;

                Log::info('Navigation target captured from processUserInput actions', [
                    'conversation_id'  => $this->conversation->id,
                    'dialog_id'        => $dialog->id,
                    'navigation_target' => $navigationTarget,
                ]);
            }

            $this->logAnalytics($dialog, 'dialog_completed');
            return true; // tell caller actions already ran
        }

        $this->logAnalytics($dialog, 'dialog_completed');
        return false;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function setVariable(string $key, mixed $value): void
    {
        ConversationVariable::updateOrCreate(
            [
                'conversation_id' => $this->conversation->id,
                'key'             => $key,
            ],
            ['value' => is_array($value) ? json_encode($value) : (string) $value]
        );
    }

    private function getVariables(): array
    {
        return $this->conversation->variables()->pluck('value', 'key')->toArray();
    }

    private function getCurrentDialog($version, Conversation $conversation): ?Dialog
    {
        $context = $conversation->context;
        if (!$context || !$context->last_dialog_id) {
            return null;
        }
        return $version->dialogs()->find($context->last_dialog_id);
    }

    private function getOrCreateContext(): ConversationContext
    {
        return ConversationContext::firstOrCreate(
            ['conversation_id' => $this->conversation->id],
            [
                'variables'      => [],
                'dialog_history' => [],
                'expires_at'     => now()->addHours(24),
            ]
        );
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
                        $config['_db_conditions'] = $a->conditions->map(fn($c) => [
                            'type'            => $c->condition_type,
                            'operator'        => $c->condition_operator,
                            'source'          => $c->variable_key ?? $c->option_id,
                            'value'           => $c->condition_value,
                            'option_id'       => $c->option_id,
                            'response_field'  => $c->response_field,
                            'response_path'   => $c->response_path,
                            'condition_order' => $c->condition_order,
                        ])->toArray();
                    }
                }

                // Inject DB action ID so resolveThenAction can look up then_action_id
                $config['_db_action_id'] = $a->then_action_id;

                return array_merge($config, ['kind' => $a->action_type]);
            })
            ->toArray();
    }
    private function stampDialog(Dialog $dialog): void
    {
        $ctx     = $this->getOrCreateContext();
        $history = $ctx->dialog_history ?? [];

        if (empty($history) || end($history) !== (string) $dialog->id) {
            $history[] = (string) $dialog->id;
        }

        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        $ctx->fill([
            'last_dialog_id' => $dialog->id,
            'dialog_history' => $history,
            'expires_at'     => now()->addHours(24),
            'variables'      => $this->getVariables(),
        ])->save();
    }

    private function logAnalytics(Dialog $dialog, string $eventType, array $extra = []): void
    {
        try {
            AnalyticsEvent::create([
                'tenant_id'       => $this->conversation->tenant_id,
                'flow_id'         => $this->conversation->flow_id,
                'conversation_id' => $this->conversation->id,
                'event_type'      => $eventType,
                'metadata'        => array_merge([
                    'dialog_id'   => $dialog->id,
                    'dialog_kind' => $dialog->kind,
                ], $extra),
            ]);
        } catch (\Exception $e) {
            Log::warning('Analytics event logging failed', [
                'event_type' => $eventType,
                'dialog_id'  => $dialog->id,
                'error'      => $e->getMessage(),
            ]);
        }
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

    private function inferMimeType(string $url, string $mediaType): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $map = [
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'mp4'  => 'video/mp4',
            '3gp'  => 'video/3gpp',
            '3gpp' => 'video/3gpp',
            'mov'  => 'video/quicktime',
            'ogg'  => 'audio/ogg',
            'oga'  => 'audio/ogg',
            'opus' => 'audio/ogg; codecs=opus',
            'mp3'  => 'audio/mpeg',
            'aac'  => 'audio/aac',
            'amr'  => 'audio/amr',
            'm4a'  => 'audio/mp4',
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'  => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt'  => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt'  => 'text/plain',
        ];

        if (!empty($ext) && isset($map[$ext])) {
            return $map[$ext];
        }

        return match ($mediaType) {
            'video'    => 'video/mp4',
            'audio'    => 'audio/mpeg',
            'image'    => 'image/jpeg',
            'document' => 'application/pdf',
            'sticker'  => 'image/webp',
            default    => 'application/octet-stream',
        };
    }
}
