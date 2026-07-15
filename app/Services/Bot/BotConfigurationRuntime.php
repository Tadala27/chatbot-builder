<?php

namespace App\Services\Bot;

use App\Models\BotDialog;
use App\Models\Conversation;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

class BotConfigurationRuntime
{
    public function __construct(
        private BotConfigDialogRenderer $dialogRenderer,
    ) {
    }

    // =========================================================================
    // WELCOME
    // =========================================================================

    /**
     * Sends the configured welcome dialog the first time a conversation
     * reaches startBot() with no dialog stamped yet. Returns true if the
     * welcome dialog has buttons and the flow should pause for a reply,
     * false if it's plain text (or there's no welcome configured) and the
     * caller should continue straight into the entry-point dialog.
     */
    public function sendWelcomeIfNeeded(Conversation $conversation): bool
    {
        if ($conversation->metadata['welcome_sent'] ?? false) {
            return false;
        }

        $this->mergeMetadata($conversation, ['welcome_sent' => true]);

        $config = $conversation->bot?->configuration;
        if (!$config?->welcome_dialog_id) {
            return false;
        }

        $dialog = BotDialog::find($config->welcome_dialog_id);
        if (!$dialog) {
            Log::warning('[ConfigRuntime] welcome_dialog_id set but dialog not found', [
                'conversation_id' => $conversation->id,
                'dialog_id' => $config->welcome_dialog_id,
            ]);

            return false;
        }

        $this->renderConfigDialog($conversation, $dialog, null);

        return $dialog->kind !== BotDialog::KIND_MESSAGE;
    }

    // =========================================================================
    // INVALID INPUT
    // =========================================================================

    public function handleInvalidInput(Conversation $conversation, Dialog $currentDialog): void
    {
        $config = $conversation->bot?->configuration;
        $max = $config?->max_invalid_attempts ?? 3;
        $attempts = (int) ($conversation->metadata['invalid_attempts'] ?? 0) + 1;

        Log::info('[ConfigRuntime] Invalid input', [
            'conversation_id' => $conversation->id,
            'dialog_id' => $currentDialog->id,
            'attempt' => $attempts,
            'max' => $max,
        ]);

        if ($attempts >= $max) {
            $this->mergeMetadata($conversation, ['invalid_attempts' => 0]);
            $this->escalateInvalidInput($conversation, $currentDialog, $config?->invalid_attempts_dialog_id);

            return;
        }

        $this->mergeMetadata($conversation, ['invalid_attempts' => $attempts]);

        $this->dialogRenderer->sendPlainText($conversation, $config?->invalid_input_message);

        if ($config?->invalid_input_dialog_id) {
            $dialog = BotDialog::find($config->invalid_input_dialog_id);
            if ($dialog) {
                $this->renderConfigDialog($conversation, $dialog, $currentDialog->id);
            }
        }
    }

    public function resetInvalidAttempts(Conversation $conversation): void
    {
        if (($conversation->metadata['invalid_attempts'] ?? 0) !== 0) {
            $this->mergeMetadata($conversation, ['invalid_attempts' => 0]);
        }
    }

    private function escalateInvalidInput(Conversation $conversation, Dialog $currentDialog, ?string $dialogId): void
    {
        if (!$dialogId) {
            return;
        }

        $dialog = BotDialog::find($dialogId);
        if (!$dialog) {
            Log::warning('[ConfigRuntime] invalid_attempts_dialog_id set but dialog not found', [
                'conversation_id' => $conversation->id,
                'dialog_id' => $dialogId,
            ]);

            return;
        }

        $this->renderConfigDialog($conversation, $dialog, $currentDialog->id);
    }

    // =========================================================================
    // PENDING CONFIG-DIALOG REPLIES
    // =========================================================================

    /**
     * Render a config-level dialog and, if it's interactive, mark the
     * conversation as waiting on a reply to it. Public so the retry command
     * can reuse the exact same bookkeeping as welcome/invalid-input do.
     */
    public function renderConfigDialog(Conversation $conversation, BotDialog $dialog, ?string $sourceFlowDialogId): void
    {
        $this->dialogRenderer->render($conversation, $dialog);

        if ($dialog->kind !== BotDialog::KIND_MESSAGE) {
            $this->registerPendingDialog($conversation, $dialog, $sourceFlowDialogId);
        }
    }

    /**
     * If the conversation is waiting on a reply to a config-level dialog and
     * $selectionId matches one of its buttons/rows, return the system-action
     * kind to run (go_home / go_back / talk_to_agent / start_flow) plus the
     * flow dialog the interrupt happened on top of, and clear the marker.
     * Returns null if this selection isn't ours to handle.
     *
     * @return array{kind: string, source_flow_dialog_id: ?string}|null
     */
    public function resolvePendingSelection(Conversation $conversation, string $selectionId): ?array
    {
        $pending = $conversation->metadata['pending_config_dialog'] ?? null;
        if (!$pending) {
            return null;
        }

        $kind = $pending['buttons'][$selectionId] ?? null;
        if (!$kind) {
            return null;
        }

        $sourceFlowDialogId = $pending['source_flow_dialog_id'] ?? null;
        $this->clearPendingDialog($conversation);

        return ['kind' => $kind, 'source_flow_dialog_id' => $sourceFlowDialogId];
    }

    private function registerPendingDialog(Conversation $conversation, BotDialog $dialog, ?string $sourceFlowDialogId): void
    {
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
            'pending_config_dialog' => [
                'dialog_id' => $dialog->id,
                'purpose' => $dialog->purpose,
                'source_flow_dialog_id' => $sourceFlowDialogId,
                'buttons' => $buttons,
            ],
        ]);
    }

    private function clearPendingDialog(Conversation $conversation): void
    {
        $meta = $conversation->metadata ?? [];
        unset($meta['pending_config_dialog']);
        $conversation->update(['metadata' => $meta]);
        $conversation->refresh();
    }

    private function mergeMetadata(Conversation $conversation, array $values): void
    {
        $conversation->update(['metadata' => array_merge($conversation->metadata ?? [], $values)]);
        $conversation->refresh();
    }
}