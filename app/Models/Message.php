<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'whatsapp_message_id',
        'direction',
        'message_type',
        'content',
        'status',
        'flow_node_id',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'content' => 'array',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function markDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }

    public function markRead(): void
    {
        $this->update([
            'status' => 'read',
            'read_at' => now(),
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
    }

    public function isInbound(): bool
    {
        return $this->direction === 'inbound';
    }

    public function isOutbound(): bool
    {
        return $this->direction === 'outbound';
    }
}