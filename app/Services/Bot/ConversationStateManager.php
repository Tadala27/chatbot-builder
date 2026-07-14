<?php

namespace App\Services\Bot;

use App\Models\BotVersion;
use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationVariable;
use App\Models\Dialog;
use Illuminate\Support\Facades\Log;

class ConversationStateManager
{
    private const HISTORY_LIMIT = 50;
    private const CONTEXT_TTL_HOURS = 24;

    public function setVariable(Conversation $conversation, string $key, mixed $value): void
    {
        ConversationVariable::setForConversation($conversation->id, $key, $value);
    }

    public function getVariables(Conversation $conversation): array
    {
        return ConversationVariable::getTypedForConversation($conversation->id);
    }

    public function getOrCreateContext(Conversation $conversation): ConversationContext
    {
        return ConversationContext::firstOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'variables' => [],
                'dialog_history' => [],
                'expires_at' => now()->addHours(self::CONTEXT_TTL_HOURS),
            ]
        );
    }

    /**
     * Get the current dialog, checking if we need to find it in the current version
     * or if we need to map from the old version.
     */
    public function getCurrentDialog(BotVersion $version, Conversation $conversation): ?Dialog
    {
        $context = $this->getOrCreateContext($conversation);
        $lastDialogId = $context->last_dialog_id;

        if (!$lastDialogId) {
            return null;
        }

        // First try to find the dialog in the current version
        $dialog = $version->dialogs()->find($lastDialogId);

        if ($dialog) {
            return $dialog;
        }

        // If not found, the conversation was using a dialog from an older version.
        // Try to find a dialog with the same purpose or config ID in the new version.
        $oldDialog = Dialog::find($lastDialogId);

        if (!$oldDialog) {
            Log::warning('[StateManager] Last dialog not found in old version', [
                'dialog_id' => $lastDialogId,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        // Try to find a dialog with the same purpose in the new version
        $newDialog = $version->dialogs()
            ->where('purpose', $oldDialog->purpose)
            ->first();

        if ($newDialog) {
            Log::info('[StateManager] Mapped dialog from old to new version by purpose', [
                'old_dialog_id' => $oldDialog->id,
                'new_dialog_id' => $newDialog->id,
                'purpose' => $oldDialog->purpose,
                'conversation_id' => $conversation->id,
            ]);

            // Update the context to use the new dialog ID
            $context->update(['last_dialog_id' => $newDialog->id]);

            return $newDialog;
        }

        // Try to find a dialog with the same config ID (if it exists)
        $oldConfigId = $oldDialog->config['id'] ?? null;
        if ($oldConfigId) {
            $newDialog = $version->dialogs()
                ->where('config->id', $oldConfigId)
                ->first();

            if ($newDialog) {
                Log::info('[StateManager] Mapped dialog from old to new version by config ID', [
                    'old_dialog_id' => $oldDialog->id,
                    'new_dialog_id' => $newDialog->id,
                    'config_id' => $oldConfigId,
                    'conversation_id' => $conversation->id,
                ]);

                $context->update(['last_dialog_id' => $newDialog->id]);

                return $newDialog;
            }
        }

        Log::warning('[StateManager] Could not map dialog to new version, starting from home', [
            'old_dialog_id' => $oldDialog->id,
            'old_dialog_purpose' => $oldDialog->purpose,
            'conversation_id' => $conversation->id,
        ]);

        // Could not map, start from home/entry point
        return $version->dialogs()->where('is_entry_point', true)->first();
    }

    /**
     * Mark a dialog as the conversation's current position and append to history.
     */
    public function stampDialog(Conversation $conversation, Dialog $dialog): void
    {
        $ctx = $this->getOrCreateContext($conversation);

        // Update last dialog
        $ctx->update(['last_dialog_id' => $dialog->id]);

        // Push to history
        $ctx->pushDialogToHistory($dialog->id);

        // Update variables
        $ctx->update(['variables' => $this->getVariables($conversation)]);

        // Update expiry
        $ctx->update(['expires_at' => now()->addHours(self::CONTEXT_TTL_HOURS)]);
    }

    public function clearHistory(Conversation $conversation): void
    {
        $ctx = $this->getOrCreateContext($conversation);
        $ctx->update([
            'dialog_history' => [],
            'last_dialog_id' => null,
        ]);
    }

    /**
     * For go_back: pop the current dialog and return the previous one.
     * Returns null if history is too shallow.
     */
    public function popPreviousDialog(Conversation $conversation): ?string
    {
        $ctx = $this->getOrCreateContext($conversation);

        // Get the previous dialog ID before popping
        $previousId = $ctx->getPreviousDialogId();

        // Pop the current dialog from history
        $ctx->popDialogFromHistory();

        // Update last_dialog_id to the previous one (or null)
        $ctx->update(['last_dialog_id' => $previousId]);

        return $previousId;
    }

    public function saveOptionSelection(
        Conversation $conversation,
        string $dialogId,
        string $selectionId,
        ?string $selectionTitle = null
    ): void {
        $this->setVariable($conversation, "__dialog_{$dialogId}_selection", $selectionId);
        if ($selectionTitle !== null) {
            $this->setVariable($conversation, "__dialog_{$dialogId}_selection_title", $selectionTitle);
        }
    }

    /**
     * Reset the conversation state to start fresh with a new bot version.
     */
    public function resetForVersionUpgrade(Conversation $conversation): void
    {
        $ctx = $this->getOrCreateContext($conversation);
        $ctx->update([
            'last_dialog_id' => null,
            'dialog_history' => [],
            'variables' => [],
        ]);

        Log::info('[StateManager] Reset conversation state for version upgrade', [
            'conversation_id' => $conversation->id,
        ]);
    }
}
