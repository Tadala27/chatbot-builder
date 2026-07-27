<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'whatsapp_message_id', 'direction', 'reply_to_wamid',
        'message_type', 'content', 'status', 'error_message', 'metadata',
        'sent_at', 'delivered_at', 'read_at', 'processed_at',
    ];

    protected $casts = [
        'content' => 'array',
        'metadata' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    protected $appends = ['sender_name'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function getSenderNameAttribute(): ?string
    {
        if (!empty($this->metadata['sender_name'] ?? null)) {
            return $this->metadata['sender_name'];
        }

        if ($this->direction === 'outbound') {
            return $this->conversation?->bot?->name ?? 'Chatbot';
        }

        return null;
    }
}