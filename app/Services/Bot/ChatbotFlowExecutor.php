<?php

namespace App\Services\Bot;

use App\Models\AnalyticsEvent;
use App\Models\BotDialog;
use App\Models\BotVersion;
use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

/**
 * DUAL-MODE FLOW EXECUTOR
 * ────────────────────────
 * Every conversation is in exactly one of two modes at any time:
 *
 *   CONFIG MODE  (metadata.bot_mode = 'config')
 *     The bot sends BotDialogs — standalone, purpose-driven dialogs that
 *     belong to the bot (not any version). This is the DEFAULT state.
 *     The user stays here until they tap a button with kind='start_flow'.
 *
 *   FLOW MODE  (metadata.bot_mode = 'flow')
 *     The bot executes the flow graph built in the bot builder. Dialogs
 *     are versioned (Dialog model, bot_version_id). The user enters here
 *     from a start_flow button and exits back to config mode via go_home.
 *
 * INTERRUPTS (fire in either mode):
 *   invalid_input   → sends the appropriate invalid-input BotDialog, then
 *                     resumes from where the conversation was
 *   talk_to_agent   → sends handover BotDialog, conversation = handed_off
 *   retry           → nudge after silence (sent by a scheduled command)
 *   home keywords   → always → main_menu BotDialog → config mode
 *   handover keywords → always → talk_to_agent
 */
class ChatbotFlowExecutor
{
    // Metadata keys used to track conversation state
    private const META_MODE = 'bot_mode';
    private const META_FLOW_DIALOG = 'current_flow_dialog_id';
    private const META_GREETING_SENT = 'greeting_sent';
    private const META_INVALID_ATTEMPTS = 'invalid_attempts';
    private const META_PENDING_CONFIG = 'pending_config_dialog';

    private const MODE_CONFIG = 'config';
    private const MODE_FLOW = 'flow';

    public function __construct(
        private DialogRenderer $renderer,
        private NavigationResolver $navigator,
        private ConversationStateManager $state,
        private SystemActionHandler $systemActions,
        private BotConfigDialogRenderer $configRenderer,
        private HandoverAvailability $availability,
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
            'mode' => $this->getMode($conversation),
        ]);

        $this->navigator->setPendingNavigation(null);

        try {
            $bot = $conversation->bot;
            $version = $conversation->botVersion;

            if (!$bot || !$bot->is_active) {
                Log::warning('[Flow] Bot not available or inactive', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            if ($conversation->fresh()->isHandedOff()) {
                Log::debug('[Flow] Conversation handed off — bot silent', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }
            // ── Sticker / reaction — neither mode can process these ───────────
            if (in_array($message->message_type, ['sticker', 'reaction'], true)) {
                $variables = $this->state->getVariables($conversation);
                $this->renderer->handleUnsupportedMessage($conversation, $message->message_type, $variables);

                return;
            }

            // ── Global keyword intercepts (fire in any mode) ─────────────────
            if ($message->message_type === 'text') {
                $text = strtolower(trim($message->content['text'] ?? ''));
                $config = $bot->configuration;

                if ($config && $this->matchesKeyword($text, $config->home_keywords ?? [])) {
                    $this->goToMainMenu($conversation);

                    return;
                }

                if ($config && $this->matchesKeyword($text, $config->handover_keywords ?? [])) {
                    $this->triggerHandover($conversation);

                    return;
                }
            }

            // ── Config-mode interactive reply intercept ───────────────────────
            // Always check this FIRST — even in flow mode, a pending config
            // dialog (e.g. invalid-input buttons shown over a flow dialog)
            // must be resolved before routing to the flow.
            if ($message->message_type === 'interactive') {
                $selectionId = $message->content['response']['id'] ?? null;
                if ($selectionId) {
                    $pending = $this->resolvePendingConfigDialog($conversation, $selectionId);
                    if ($pending !== null) {
                        $this->handleConfigDialogReply($pending, $conversation, $version);

                        return;
                    }
                }
            }

            // ── Route by mode ─────────────────────────────────────────────────
            if ($this->getMode($conversation) === self::MODE_FLOW) {
                if (!$version) {
                    Log::warning('[Flow] In flow mode but no published version', [
                        'conversation_id' => $conversation->id,
                    ]);
                    $this->goToMainMenu($conversation);

                    return;
                }
                $this->processFlowMessage($conversation, $message, $version);
            } else {
                $this->processConfigMessage($conversation, $message, $bot);
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

    // =========================================================================
    // CONFIG MODE
    // =========================================================================

    /**
     * Process a message while in config mode.
     * The bot is showing standalone BotDialogs. The user can only interact
     * via buttons — free text is invalid here and gets the invalid_input dialog.
     */
    private function processConfigMessage(Conversation $conversation, Message $message, $bot): void
    {
        // First ever message → send greeting
        if (!($conversation->metadata[self::META_GREETING_SENT] ?? false)) {
            $this->sendGreeting($conversation);

            return;
        }

        // In config mode, we're always waiting on a tap from a BotDialog.
        // Free text means the user sent something unexpected.
        if ($message->message_type !== 'interactive') {
            $this->handleConfigInvalidInput($conversation, null);

            return;
        }

        // A tap arrived but no pending config dialog was found above —
        // that means the selection ID doesn't match any known button.
        $this->handleConfigInvalidInput($conversation, null);
    }

    /**
     * Send the greeting BotDialog (first ever message from this contact).
     * Falls through to main_menu if no greeting is configured.
     */
    private function sendGreeting(Conversation $conversation): void
    {
        $this->mergeMetadata($conversation, [self::META_GREETING_SENT => true]);

        $greeting = BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_GREETING);

        if ($greeting) {
            $this->sendConfigDialog($conversation, $greeting, null);

            return;
        }

        // No greeting — go straight to main menu
        $this->goToMainMenu($conversation);
    }

    /**
     * Send the main_menu BotDialog and switch to config mode.
     * Falls through to flow entry point if no main_menu is configured.
     */
    private function goToMainMenu(Conversation $conversation): void
    {
        $this->setMode($conversation, self::MODE_CONFIG);
        $this->clearFlowState($conversation);

        $menu = BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_MAIN_MENU);

        if ($menu) {
            $this->sendConfigDialog($conversation, $menu, null);

            return;
        }

        // No main menu — go straight to the flow entry point
        $this->startFlow($conversation);
    }

    /**
     * Handle invalid input while in config mode.
     */
    private function handleConfigInvalidInput(Conversation $conversation, ?string $anchorFlowDialogId): void
    {
        $config = $conversation->bot?->configuration;
        $max = (int) ($config?->max_invalid_attempts ?? 3);
        $attempts = (int) ($conversation->metadata[self::META_INVALID_ATTEMPTS] ?? 0) + 1;

        Log::info('[Flow] Config-mode invalid input', [
            'conversation_id' => $conversation->id,
            'attempt' => $attempts,
            'max' => $max,
        ]);

        if ($attempts >= $max) {
            $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => 0]);
            $dialog = BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_INVALID_INPUT);
            // Fall back to main_menu when max attempts hit and no dialog is set
            if (!$dialog) {
                $this->goToMainMenu($conversation);

                return;
            }
            $this->sendConfigDialog($conversation, $dialog, $anchorFlowDialogId);

            return;
        }

        $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => $attempts]);

        if ($config?->invalid_input_message) {
            $this->configRenderer->sendPlainText($conversation, $config->invalid_input_message);
        }

        $dialog = BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_INVALID_INPUT);
        if ($dialog) {
            $this->sendConfigDialog($conversation, $dialog, $anchorFlowDialogId);
        }
    }

    // =========================================================================
    // FLOW MODE
    // =========================================================================

    /**
     * Process a message while in flow mode.
     */
    private function processFlowMessage(Conversation $conversation, Message $message, BotVersion $version): void
    {
        $ownerDialog = null;
        $selectionId = null;

        if ($message->message_type === 'interactive') {
            $selectionId = $message->content['response']['id'] ?? null;
            if ($selectionId) {
                $ownerDialog = $this->navigator->findDialogOwningSelection($version, $selectionId);
            }
        }

        // ── System-action intercept ───────────────────────────────────────────
        if ($ownerDialog && $selectionId) {
            $sysKind = $this->systemActions->detectSystemAction($ownerDialog, $selectionId);
            if ($sysKind !== null) {
                $this->logAnalytics($conversation, $ownerDialog, "system_action_{$sysKind}");
                $this->executeFlowSystemAction($sysKind, $conversation, $ownerDialog, $version);

                return;
            }
        }

        $currentDialog = $this->state->getCurrentDialog($version, $conversation);

        // ── Late-selection intercept ──────────────────────────────────────────
        if ($ownerDialog && (!$currentDialog || $ownerDialog->id !== $currentDialog->id)) {
            Log::info('[Flow] Late-selection intercept', [
                'conversation_id' => $conversation->id,
                'owner_dialog_id' => $ownerDialog->id,
            ]);
            $this->handleFlowDialogResponse($ownerDialog, $message, $version, $conversation);

            return;
        }

        if ($currentDialog) {
            $this->handleFlowDialogResponse($currentDialog, $message, $version, $conversation);
        } else {
            // No current dialog — start from the entry point
            $this->startFlow($conversation);
        }
    }

    /**
     * Execute a system action triggered from within the flow graph.
     * go_home and start_flow exit back to config mode.
     * go_back stays within the flow.
     * talk_to_agent triggers handover.
     */
    private function executeFlowSystemAction(
        string $kind,
        Conversation $conversation,
        Dialog $sourceDialog,
        BotVersion $version
    ): void {
        switch ($kind) {
            case 'go_home':
                // Exit flow → config mode → main menu
                $this->goToMainMenu($conversation);
                break;

            case 'start_flow':
                // Restart flow from entry point
                $this->clearFlowState($conversation);
                $this->startFlow($conversation);
                break;

            case 'go_back':
                $prevId = $this->state->popPreviousDialog($conversation);
                if ($prevId) {
                    $prev = $version->dialogs()->find((string) $prevId);
                    if ($prev) {
                        $this->executeDialogFlow($prev, $conversation);

                        return;
                    }
                }
                // Nothing to go back to — treat as go_home
                $this->goToMainMenu($conversation);
                break;

            case 'talk_to_agent':
                $this->triggerHandover($conversation, $sourceDialog);
                break;
        }
    }

    /**
     * Enter flow mode and execute from the entry-point dialog.
     */
    private function startFlow(Conversation $conversation): void
    {
        $version = $conversation->botVersion;

        if (!$version) {
            Log::warning('[Flow] startFlow called but no published version', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        $this->setMode($conversation, self::MODE_FLOW);

        $startDialog = $version->dialogs()->where('is_entry_point', true)->first();

        if (!$startDialog) {
            Log::warning('[Flow] No entry-point dialog in published version', [
                'conversation_id' => $conversation->id,
                'version_id' => $version->id,
            ]);

            return;
        }

        $this->executeDialogFlow($startDialog, $conversation);
    }

    // =========================================================================
    // CONFIG DIALOG MANAGEMENT
    // =========================================================================

    /**
     * Send a BotDialog and register it as pending if interactive.
     *
     * @param string|null $anchorFlowDialogId The flow Dialog::id the user was on
     *                                        when this config dialog was triggered.
     *                                        Used to resume the flow after the reply.
     */
    private function sendConfigDialog(
        Conversation $conversation,
        BotDialog $dialog,
        ?string $anchorFlowDialogId
    ): void {
        $this->configRenderer->render($conversation, $dialog);

        if ($dialog->isInteractive()) {
            $this->registerPendingConfigDialog($conversation, $dialog, $anchorFlowDialogId);
        }
    }

    /**
     * Store which config dialog's buttons we're waiting on.
     */
    private function registerPendingConfigDialog(
        Conversation $conversation,
        BotDialog $dialog,
        ?string $anchorFlowDialogId
    ): void {
        $buttons = [];

        foreach ($dialog->config['buttons'] ?? [] as $btn) {
            $buttons[$btn['id']] = $btn['kind'];
        }

        foreach ($dialog->config['sections'] ?? [] as $section) {
            foreach ($section['rows'] ?? [] as $row) {
                $buttons[$row['id']] = $row['kind'];
            }
        }

        $this->mergeMetadata($conversation, [
            self::META_PENDING_CONFIG => [
                'dialog_id' => $dialog->id,
                'purpose' => $dialog->purpose,
                'anchor_flow_dialog' => $anchorFlowDialogId,
                'buttons' => $buttons,
            ],
        ]);
    }

    /**
     * Check if the incoming selection ID matches a pending config dialog.
     * Returns ['kind' => string, 'anchor_flow_dialog' => ?string] or null.
     */
    private function resolvePendingConfigDialog(Conversation $conversation, string $selectionId): ?array
    {
        $pending = $conversation->metadata[self::META_PENDING_CONFIG] ?? null;
        if (!$pending) {
            return null;
        }

        $kind = $pending['buttons'][$selectionId] ?? null;
        if (!$kind) {
            return null;
        }

        // Clear immediately so a double-tap doesn't fire twice
        $meta = $conversation->metadata ?? [];
        unset($meta[self::META_PENDING_CONFIG]);
        $conversation->update(['metadata' => $meta]);
        $conversation->refresh();

        return [
            'kind' => $kind,
            'anchor_flow_dialog' => $pending['anchor_flow_dialog'] ?? null,
        ];
    }

    /**
     * A button on a config-level dialog was tapped. Route to the right action.
     */
    private function handleConfigDialogReply(
        array $resolved,
        Conversation $conversation,
        ?BotVersion $version
    ): void {
        $kind = $resolved['kind'];
        $anchorFlowDialog = $resolved['anchor_flow_dialog'] ?? null;

        $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => 0]);

        switch ($kind) {
            case 'start_flow':
                $this->clearFlowState($conversation);
                $this->startFlow($conversation);
                break;

            case 'go_home':
                $this->goToMainMenu($conversation);
                break;

            case 'go_back':
                // If we were in the flow, return to the anchor dialog.
                // If we were in config mode, go back to main menu.
                if ($anchorFlowDialog && $version) {
                    $dialog = $version->dialogs()->find((string) $anchorFlowDialog);
                    if ($dialog) {
                        $this->setMode($conversation, self::MODE_FLOW);
                        $this->executeDialogFlow($dialog, $conversation);

                        return;
                    }
                }
                $this->goToMainMenu($conversation);
                break;

            case 'talk_to_agent':
                $this->triggerHandover($conversation);
                break;

            default:
                Log::warning('[Flow] Unknown config dialog button kind', [
                    'kind' => $kind,
                    'conversation_id' => $conversation->id,
                ]);
                $this->goToMainMenu($conversation);
        }
    }

    // =========================================================================
    // HANDOVER
    // =========================================================================

    private function triggerHandover(Conversation $conversation, ?Dialog $sourceDialog = null): void
    {
        if (!$conversation->isHandedOff()) {
            $conversation->handOff(sourceDialogId: $sourceDialog?->id);
        }

        $config = $conversation->bot?->configuration;
        $inHours = $this->availability->isInHours($config?->operating_hours ?? null);

        $purpose = $inHours
            ? BotDialog::PURPOSE_HANDOVER_IN_HOURS
            : BotDialog::PURPOSE_HANDOVER_OFF_HOURS;

        $handoverDialog = BotDialog::forBot($conversation->bot_id, $purpose);

        if ($handoverDialog) {
            $this->configRenderer->render($conversation, $handoverDialog);

            return;
        }

        $fallback = (!$inHours && $config?->handover_unavailable_message)
            ? $config->handover_unavailable_message
            : 'You are being connected to a live agent. Please wait...';

        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $fallback,
            []
        );
    }

    // =========================================================================
    // FLOW DIALOG EXECUTION
    // =========================================================================

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

            $result = $this->executeFlowDialog($dialog, $conversation);

            if ($result['stop'] ?? false) {
                return;
            }

            if ($result['success'] ?? false) {
                $this->continueFromFlowDialog($dialog, $conversation, $version);
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

    private function handleFlowDialogResponse(
        Dialog $dialog,
        Message $message,
        BotVersion $version,
        Conversation $conversation
    ): void {
        $actionsAlreadyRan = $this->processUserInput($dialog, $message, $conversation);

        // If a handoff action fired, conversation is now handed_off.
        // Send the handover BotDialog and stop — don't treat this as invalid input.
        if ($conversation->fresh()->isHandedOff()) {
            $this->triggerHandover($conversation);

            return;
        }

        $variables = $this->state->getVariables($conversation);
        $dialogActions = $this->getDialogActions($dialog);

        $nextDialog = $this->navigator->resolveFromMessage(
            $version, $dialog, $message, $conversation,
            $variables, $dialogActions, $actionsAlreadyRan
        );

        if ($nextDialog) {
            $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => 0]);
            $this->executeDialogFlow($nextDialog, $conversation);

            return;
        }

        $this->handleFlowInvalidInput($conversation, $dialog);
    }

    /**
     * Invalid input while in flow mode.
     * Sends the flow_invalid_input BotDialog (or falls back to the
     * plain invalid_input one), anchored to the current flow dialog
     * so the reply sends the user back to it.
     */
    private function handleFlowInvalidInput(Conversation $conversation, Dialog $currentDialog): void
    {
        $config = $conversation->bot?->configuration;
        $max = (int) ($config?->max_invalid_attempts ?? 3);
        $attempts = (int) ($conversation->metadata[self::META_INVALID_ATTEMPTS] ?? 0) + 1;

        if ($attempts >= $max) {
            $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => 0]);
            // Max hit — exit to main menu
            $this->goToMainMenu($conversation);

            return;
        }

        $this->mergeMetadata($conversation, [self::META_INVALID_ATTEMPTS => $attempts]);

        // Try flow_invalid_input first, then fall back to invalid_input
        $dialog = BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_FLOW_INVALID_INPUT)
            ?? BotDialog::forBot($conversation->bot_id, BotDialog::PURPOSE_INVALID_INPUT);

        if ($dialog) {
            // Anchor to current flow dialog so go_back brings them back here
            $this->sendConfigDialog($conversation, $dialog, $currentDialog->id);

            return;
        }

        // No invalid input dialog at all — silently stay on the current dialog
        // (the user will just need to tap a valid button)
        Log::debug('[Flow] No flow_invalid_input or invalid_input BotDialog configured', [
            'conversation_id' => $conversation->id,
        ]);
    }

    private function continueFromFlowDialog(
        Dialog $dialog,
        Conversation $conversation,
        BotVersion $version
    ): void {
        $variables = $this->state->getVariables($conversation);
        $dialogActions = $this->getDialogActions($dialog);

        $nextDialogId = $this->navigator->runActionChain($conversation, $dialog, $dialogActions, $variables);

        // Handoff action may have fired during the action chain
        if ($conversation->fresh()->isHandedOff()) {
            $this->triggerHandover($conversation);

            return;
        }

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

    private function executeFlowDialog(Dialog $dialog, Conversation $conversation): array
    {
        if ($dialog->bot_version_id !== $conversation->bot_version_id) {
            Log::error('[Flow] Version mismatch executing dialog', [
                'dialog_id' => $dialog->id,
                'dialog_bot_version' => $dialog->bot_version_id,
                'conv_bot_version' => $conversation->bot_version_id,
            ]);

            return ['success' => false, 'error' => 'Version mismatch'];
        }

        // Track which flow dialog the user is on — needed to resume after interrupts
        $this->mergeMetadata($conversation, [self::META_FLOW_DIALOG => $dialog->id]);

        $variables = $this->state->getVariables($conversation);
        $result = $this->renderer->render($dialog, $conversation, $variables);

        if ($result['success'] ?? false) {
            $this->state->stampDialog($conversation, $dialog);
            $this->logAnalytics($conversation, $dialog, 'dialog_entered');

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
                // End of flow → return to config mode (main menu or greeting on next message)
                $this->setMode($conversation, self::MODE_CONFIG);
            }
        }

        return $result;
    }

    // =========================================================================
    // PRIVATE — Input processing (shared with flow mode)
    // =========================================================================

    private function processUserInput(Dialog $dialog, Message $message, Conversation $conversation): bool
    {
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

        $inputVar = $config['inputVariable'] ?? $dialog->input_variable ?? null;
        if ($inputVar && $inputValue !== '') {
            $this->state->setVariable($conversation, $inputVar, $inputValue);
        }

        $replyVar = $config['replyVariable'] ?? null;
        if ($replyVar && $inputValue !== '' && in_array($kind, ['location', 'contact', 'media'], true)) {
            $this->state->setVariable($conversation, $replyVar, $inputValue);
        }

        if ($selectionId) {
            $this->saveSelectionVariables($dialog, $selectionId, $conversation);
        }

        if (in_array($kind, ['buttons', 'list'], true) && $selectionId) {
            $option = $dialog->options()->where('external_id', $selectionId)->first();
            if ($option && $option->save_response) {
                $this->state->saveOptionSelection($conversation, $dialog->id, $selectionId, $option->title ?? '');
            }
        }

        $dialogActions = $this->getDialogActions($dialog);
        $hasVariableAction = collect($dialogActions)
            ->contains(fn ($a) => ($a['kind'] ?? $a['action_type'] ?? null) === 'variable');
        $isTextReply = $message->message_type === 'text';

        if ($isTextReply && ($inputVar || $hasVariableAction) && !in_array($kind, ['buttons', 'list', 'nav_buttons'], true)) {
            $variables = $this->state->getVariables($conversation);
            $actionsWithInput = array_map(function ($action) use ($inputValue) {
                if (($action['kind'] ?? $action['action_type'] ?? null) === 'variable') {
                    $action['_resolvedInput'] = $inputValue;
                }

                return $action;
            }, $dialogActions);

            $target = $this->navigator->runActionChain($conversation, $dialog, $actionsWithInput, $variables);
            if ($target) {
                $this->navigator->setPendingNavigation($target);
            }

            $this->logAnalytics($conversation, $dialog, 'dialog_completed');

            return true;
        }

        $this->logAnalytics($conversation, $dialog, 'dialog_completed');

        return false;
    }

    private function saveSelectionVariables(Dialog $dialog, string $selectionId, Conversation $conversation): void
    {
        if ($dialog->kind === 'buttons') {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId && !empty($btn['saveVariable'])) {
                    $this->state->setVariable($conversation, $btn['saveVariable'], $btn['label'] ?? $btn['title'] ?? '');
                }
            }
        }

        if ($dialog->kind === 'list') {
            foreach ($dialog->config['action']['sections'] ?? $dialog->config['sections'] ?? [] as $section) {
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
                    if (count($config['branches'] ?? []) <= 1) {
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
    // PRIVATE — Mode & metadata helpers
    // =========================================================================

    private function getMode(Conversation $conversation): string
    {
        return $conversation->metadata[self::META_MODE] ?? self::MODE_CONFIG;
    }

    private function setMode(Conversation $conversation, string $mode): void
    {
        $this->mergeMetadata($conversation, [self::META_MODE => $mode]);
    }

    private function clearFlowState(Conversation $conversation): void
    {
        $this->state->clearHistory($conversation);
        $meta = $conversation->metadata ?? [];
        unset($meta[self::META_FLOW_DIALOG], $meta[self::META_PENDING_CONFIG]);
        $conversation->update(['metadata' => $meta]);
        $conversation->refresh();
    }

    private function mergeMetadata(Conversation $conversation, array $values): void
    {
        $conversation->update(['metadata' => array_merge($conversation->metadata ?? [], $values)]);
        $conversation->refresh();
    }

    private function matchesKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            if (strtolower(trim($kw)) === $text) {
                return true;
            }
        }

        return false;
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
            Log::warning('[Flow] Analytics failed', [
                'event_type' => $eventType,
                'dialog_id' => $dialog->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}