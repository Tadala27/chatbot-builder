<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'flow_id', 'flow_version_id', 'whatsapp_account_id',
        'whatsapp_user_phone', 'whatsapp_user_name', 'status',
        'assigned_agent_id', 'started_at', 'ended_at',
        'last_message_at', 'message_count', 'metadata',
    ];

    protected $casts = [
        'started_at'      => 'datetime',
        'ended_at'        => 'datetime',
        'last_message_at' => 'datetime',
        'message_count'   => 'integer',
        'metadata'        => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function whatsappAccount(): BelongsTo
    {
        return $this->belongsTo(WhatsappAccount::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('sent_at');
    }

    public function variables(): HasMany
    {
        return $this->hasMany(ConversationVariable::class);
    }

    public function variableLogs(): HasMany
    {
        return $this->hasMany(ConversationVariableLog::class);
    }

    public function context(): HasOne
    {
        return $this->hasOne(ConversationContext::class)->latest();
    }

    public function agentHandoverLogs(): HasMany
    {
        return $this->hasMany(AgentHandoverLog::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(FlowExecutionLog::class);
    }

    public function analyticsEvents(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function duration(): ?int
    {
        if (!$this->ended_at) return null;
        return $this->started_at->diffInSeconds($this->ended_at);
    }
}
