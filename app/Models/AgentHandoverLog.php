<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentHandoverLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'flow_node_id',
        'assigned_agent_id',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
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

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function getDuration(): ?int
    {
        if (!$this->ended_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->ended_at);
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }
}