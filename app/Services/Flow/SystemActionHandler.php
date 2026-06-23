<?php

// =============================================================================
// FILE: app/Services/Flow/SystemActionHandler.php  (NEW)
// PRIORITY: 3 — Architectural refactor
//
// WHAT: Handles the "system" navigation actions: go_home, go_back,
// talk_to_agent, start_flow.
//
// Extracted from ChatbotFlowExecutor to isolate this policy.
// =============================================================================

namespace App\Services\Flow;

use App\Models\Conversation;
use App\Models\Dialog;
use App\Models\FlowVersion;
use App\Services\WhatsAppMessageService;
use Illuminate\Support\Facades\Log;

class SystemActionHandler
{
    public function __construct(
        private WhatsAppMessageService  $messageService,
        private ConversationStateManager $state,
    ) {}

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

    /**
     * Returns the dialog the executor should navigate to next, or null if
     * navigation was handled entirely by this method (e.g., handoff).
     *
     * The caller is responsible for calling executeDialogFlow() on the result.
     */
    public function execute(
        string       $kind,
        Conversation $conversation,
        Dialog       $sourceDialog,
        FlowVersion  $version
    ): ?Dialog {
        return match ($kind) {
            'start_flow'    => $this->doStartFlow($version),
            'go_home'       => $this->doGoHome($conversation, $version),
            'go_back'       => $this->doGoBack($conversation, $version),
            'talk_to_agent' => $this->doTalkToAgent($conversation, $sourceDialog, $version),
            default         => null,
        };
    }

    // =========================================================================

    private function doStartFlow(FlowVersion $version): ?Dialog
    {
        return $version->dialogs()->where('is_entry_point', true)->first();
    }

    private function doGoHome(Conversation $conversation, FlowVersion $version): ?Dialog
    {
        $home = $version->dialogs()->where('is_entry_point', true)->first();

        if (!$home) {
            Log::warning('go_home: no entry-point dialog', ['conversation_id' => $conversation->id]);
            return null;
        }

        $this->state->clearHistory($conversation);
        return $home;
    }

    private function doGoBack(Conversation $conversation, FlowVersion $version): ?Dialog
    {
        $prevId = $this->state->popPreviousDialog($conversation);

        if ($prevId) {
            $prev = $version->dialogs()->find((int) $prevId);
            if ($prev) return $prev;
        }

        Log::info('go_back: history too shallow, falling back to home', [
            'conversation_id' => $conversation->id,
        ]);
        return $this->doGoHome($conversation, $version);
    }

    private function doTalkToAgent(
        Conversation $conversation,
        Dialog       $sourceDialog,
        FlowVersion  $version
    ): ?Dialog {
        $conversation->update([
            'status'   => 'handed_off',
            'metadata' => array_merge($conversation->metadata ?? [], [
                'handoff_source_dialog' => $sourceDialog->id,
                'handoff_reason'        => 'user_requested',
                'handoff_at'            => now()->toISOString(),
            ]),
        ]);

        $botConfig   = $conversation->flow?->bot?->configuration;
        $agentDialog = null;

        if ($botConfig && !empty($botConfig->agent_dialog_id)) {
            $agentDialog = $version->dialogs()->find((int) $botConfig->agent_dialog_id);
        }

        if ($agentDialog) {
            Log::info('talk_to_agent: routing to agent dialog', [
                'conversation_id' => $conversation->id,
                'agent_dialog_id' => $agentDialog->id,
            ]);
            return $agentDialog;
        }

        // No agent dialog configured — send a default message
        $this->messageService->sendTextMessage(
            $conversation->whatsappAccount,
            $conversation->whatsapp_user_phone,
            'You are being connected to a live agent. Please wait…',
            []
        );

        Log::info('talk_to_agent: no agent dialog — sent default message', [
            'conversation_id' => $conversation->id,
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
