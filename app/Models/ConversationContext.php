<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationContext extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'conversation_id',
        'variables',
        'last_dialog_id',
        'dialog_history',
        'expires_at',
    ];

    protected $casts = [
        'variables' => 'array',
        'dialog_history' => 'array',
        'expires_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** Soft reference to the last dialog visited (no FK — dialogs can be deleted) */
    public function lastDialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class, 'last_dialog_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Add a dialog to the history.
     */
    public function pushDialogToHistory(string $dialogId): void
    {
        $history = $this->dialog_history ?? [];

        // Avoid duplicates in a row
        if (empty($history) || end($history) !== $dialogId) {
            $history[] = $dialogId;
        }

        // Limit history size
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }

        $this->update(['dialog_history' => $history]);
    }

    /**
     * Pop the last dialog from history (for go_back).
     */
    public function popDialogFromHistory(): ?string
{
    $history = $this->dialog_history ?? [];
    if (empty($history)) return null;
    $popped = array_pop($history);
    $this->update(['dialog_history' => $history]);
    return $popped;
}

    /**
     * Get the previous dialog ID (for go_back).
     */
    public function getPreviousDialogId(): ?string
    {
        $history = $this->dialog_history ?? [];

        if (count($history) < 2) {
            return null;
        }

        // History: [dialog1, dialog2, dialog3]
        // For go_back from dialog3, we want dialog2
        return $history[count($history) - 2] ?? null;
    }
}