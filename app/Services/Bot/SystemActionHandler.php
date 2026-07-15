<?php

namespace App\Services\Bot;

use App\Models\Botversion;
use App\Models\Conversation;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

class SystemActionHandler
{
    public function __construct(
        private WhatsAppMessageService $messageService,
        private ConversationStateManager $state,
        private BotConfigDialogRenderer $configDialogRenderer,
        private HandoverAvailability $availability,
    ) {
    }

    /**
     * Scan a dialog's selection actions and return the first system-action
     * kind found (or null if none).
     */
    public function detectSystemAction(Dialog $dialog, string $selectionId): ?string
    {
        $actions = $this->getActionsForSelection($dialog, $selectionId);

        foreach ($actions as $action) {
            $kind = $action['kind'] ?? $action['action_type'] ?? null;
            if (in_array($kind, ['start_flow', 'go_home', 'go_back', 'talk_to_agent'], true)) {
                return $kind;
            }
        }

        return null;
    }

    public function execute(
        string $kind,
        Conversation $conversation,
        Dialog $sourceDialog,
        Botversion $version
    ): ?Dialog {
        return match ($kind) {
            'start_flow' => $this->doStartFlow($version),
            'go_home' => $this->doGoHome($conversation, $version),
            'go_back' => $this->doGoBack($conversation, $version),
            'talk_to_agent' => $this->doTalkToAgent($conversation, $sourceDialog, $version),
            default => null,
        };
    }

    // =========================================================================

    private function doStartFlow(Botversion $version): ?Dialog
    {
        return $version->dialogs()->where('is_entry_point', true)->first();
    }

    private function doGoHome(Conversation $conversation, Botversion $version): ?Dialog
    {
        $home = $version->dialogs()->where('is_entry_point', true)->first();

        if (!$home) {
            Log::warning('go_home: no entry-point dialog', ['conversation_id' => $conversation->id]);

            return null;
        }

        $this->state->clearHistory($conversation);

        return $home;
    }

    private function doGoBack(Conversation $conversation, Botversion $version): ?Dialog
    {
        $prevId = $this->state->popPreviousDialog($conversation);

        if ($prevId) {
            // dialogs.id is a UUID primary key, not an auto-increment
            // integer — casting to (int) here used to silently truncate any
            // real UUID to 0, so find() always returned null and go_back
            // fell through to doGoHome() every time.
            $prev = $version->dialogs()->find((string) $prevId);
            if ($prev) {
                return $prev;
            }
        }

        Log::info('go_back: history too shallow, falling back to home', [
            'conversation_id' => $conversation->id,
        ]);

        return $this->doGoHome($conversation, $version);
    }

    /**
     * Hands the conversation off to a human agent.
     *
     * FIX: this previously read `$botConfig->agent_dialog_id`, a column
     * that doesn't exist anywhere in the schema, and tried to look it up in
     * the flow-graph `dialogs` table. The real config is
     * `handover_dialog_id_in_hours` / `handover_dialog_id_off_hours` on
     * BotConfiguration, and those point at `bot_dialogs` (config-level
     * dialogs), not flow dialogs — so this now picks in-hours vs off-hours
     * via HandoverAvailability and renders the result as a BotDialog
     * directly, instead of trying to return it as a flow ?Dialog to
     * continue the graph. That's correct either way: handover always ends
     * with conversation status = handed_off, so there's nothing for the
     * flow to continue into.
     */
    private function doTalkToAgent(
        Conversation $conversation,
        Dialog $sourceDialog,
        Botversion $version
    ): ?Dialog {
        $conversation->update([
            'status' => 'handed_off',
            'metadata' => array_merge($conversation->metadata ?? [], [
                'handoff_source_dialog' => $sourceDialog->id,
                'handoff_reason' => 'user_requested',
                'handoff_at' => now()->toISOString(),
            ]),
        ]);

        $botConfig = $conversation->bot?->configuration;
        $inHours = $this->availability->isInHours($botConfig?->operating_hours ?? null);

        $handoverDialogId = $inHours
            ? $botConfig?->handover_dialog_id_in_hours
            : $botConfig?->handover_dialog_id_off_hours;

        $handoverDialog = $handoverDialogId
            ? \App\Models\BotDialog::find($handoverDialogId)
            : null;

        if ($handoverDialog) {
            $this->configDialogRenderer->render($conversation, $handoverDialog);

            Log::info('talk_to_agent: sent handover dialog', [
                'conversation_id' => $conversation->id,
                'dialog_id' => $handoverDialog->id,
                'in_hours' => $inHours,
            ]);

            return null;
        }

        $fallback = (!$inHours && $botConfig?->handover_unavailable_message)
            ? $botConfig->handover_unavailable_message
            : 'You are being connected to a live agent. Please wait…';

        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            $fallback,
            []
        );

        Log::info('talk_to_agent: no handover dialog configured — sent fallback message', [
            'conversation_id' => $conversation->id,
            'in_hours' => $inHours,
        ]);

        return null;
    }

    // =========================================================================
    // Duplicate of NavigationResolver's helper — kept here to avoid circular
    // dependency. Small enough that the duplication is worth the decoupling.
    // =========================================================================

    private function getActionsForSelection(Dialog $dialog, string $selectionId): array
    {
        if (in_array($dialog->kind, ['buttons', 'nav_buttons'], true)) {
            foreach ($dialog->config['buttons'] ?? [] as $btn) {
                if (($btn['id'] ?? '') === $selectionId) {
                    return $btn['actions'] ?? [];
                }
            }

            return [];
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
}