<?php


namespace App\Services\Flow;

use App\Models\Conversation;
use App\Models\ConversationContext;
use App\Models\ConversationVariable;
use App\Models\Dialog;
use App\Models\FlowVersion;

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
                'variables'      => [],
                'dialog_history' => [],
                'expires_at'     => now()->addHours(self::CONTEXT_TTL_HOURS),
            ]
        );
    }

    public function getCurrentDialog(FlowVersion $version, Conversation $conversation): ?Dialog
    {
        $context = $conversation->context;
        if (!$context || !$context->last_dialog_id) {
            return null;
        }
        return $version->dialogs()->find($context->last_dialog_id);
    }

    /**
     * Mark a dialog as the conversation's current position and append to history.
     */
    public function stampDialog(Conversation $conversation, Dialog $dialog): void
    {
        $ctx     = $this->getOrCreateContext($conversation);
        $history = $ctx->dialog_history ?? [];

        if (empty($history) || end($history) !== (string) $dialog->id) {
            $history[] = (string) $dialog->id;
        }

        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }

        $ctx->fill([
            'last_dialog_id' => $dialog->id,
            'dialog_history' => $history,
            'expires_at'     => now()->addHours(self::CONTEXT_TTL_HOURS),
            'variables'      => $this->getVariables($conversation),
        ])->save();
    }

    public function clearHistory(Conversation $conversation): void
    {
        $ctx = $this->getOrCreateContext($conversation);
        $ctx->update(['dialog_history' => []]);
    }

    /**
     * For go_back: pop twice (current + previous) and return the previous ID.
     * Returns null if history is too shallow.
     */
    public function popPreviousDialog(Conversation $conversation): ?string
    {
        $ctx     = $this->getOrCreateContext($conversation);
        $history = $ctx->dialog_history ?? [];

        if (count($history) < 2) return null;

        array_pop($history);          // current
        $previous = array_pop($history); // previous
        $ctx->update(['dialog_history' => $history]);

        return $previous;
    }

    public function saveOptionSelection(
        Conversation $conversation,
        int          $dialogId,
        string       $selectionId,
        ?string      $selectionTitle = null
    ): void {
        $this->setVariable($conversation, "__dialog_{$dialogId}_selection", $selectionId);
        if ($selectionTitle !== null) {
            $this->setVariable($conversation, "__dialog_{$dialogId}_selection_title", $selectionTitle);
        }
    }
}