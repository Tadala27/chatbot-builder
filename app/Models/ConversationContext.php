<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationContext extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id', 'variables', 'last_dialog_id', 'expires_at',
    ];

    protected $casts = [
        'variables'  => 'array',
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
}
