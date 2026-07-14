<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

class Message extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'conversation_id', 'whatsapp_message_id', 'direction',
        'message_type', 'content', 'status', 'error_message',
        'sent_at', 'delivered_at', 'read_at',
    ];

    protected $casts = [
        'content' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    protected $appends = ['media_url', 'sender_name'];

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
        // Agent-sent (has metadata.sender_name set by InboxController::sendMessage/sendMedia)
        if (!empty($this->metadata['sender_name'] ?? null)) {
            return $this->metadata['sender_name'];
        }

        // Bot-sent outbound — fall back to the conversation's bot name.
        if ($this->direction === 'outbound') {
            return $this->conversation?->bot?->name ?? 'Chatbot';
        }

        // Inbound (the contact) — no sender label needed, MessageBubble only
        // shows this for outbound bubbles anyway.
        return null;
    }

    public function getMediaUrlAttribute(): ?string
    {
        // Outbound media already has a real, non-expiring URL stored directly
        // — no proxy needed, just use it.
        if ($this->direction === 'outbound' && !empty($this->content['link'] ?? null)) {
            return $this->content['link'];
        }

        $hasMediaId = !empty($this->content['id'] ?? null);
        $isMediaType = in_array($this->message_type, ['image', 'video', 'audio', 'document', 'sticker'], true);

        if ($this->direction === 'inbound' && $isMediaType && $hasMediaId) {
            try {
                // Use the correct route name: 'tenant.message.media.stream'
                // This route expects {message} parameter
                return URL::temporarySignedRoute(
                    'tenant.message.media.stream',  // ← Must match the route name
                    now()->addMinutes(30),
                    ['message' => $this->id]  // ← Parameter must be 'message'
                );
            } catch (\Exception $e) {
                // Fallback: generate URL manually
                $baseUrl = rtrim(config('app.url'), '/');

                return $baseUrl.'/tenant/api/messages/'.$this->id.'/media';
            }
        }

        return null;
    }
}
