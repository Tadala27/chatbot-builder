<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'flow_id',
        'flow_version_id',
        'whatsapp_account_id',
        'whatsapp_user_phone',
        'whatsapp_user_name',
        'status',
        'assigned_agent_id',
        'started_at',
        'ended_at',
        'last_message_at',
        'message_count',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'last_message_at' => 'datetime',
        'message_count' => 'integer',
        'metadata' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

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
        return $this->hasMany(Message::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(ConversationVariable::class);
    }

    public function variableLogs(): HasMany
    {
        return $this->hasMany(ConversationVariableLog::class);
    }

    public function handoverLogs(): HasMany
    {
        return $this->hasMany(AgentHandoverLog::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(FlowExecutionLog::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeHandedOff($query)
    {
        return $query->where('status', 'handed_off');
    }

    public function scopeAbandoned($query)
    {
        return $query->where('status', 'abandoned');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function getVariable(string $key, $default = null)
    {
        return $this->variables()
            ->where('key', $key)
            ->value('value') ?? $default;
    }

    public function setVariable(string $key, $value): void
    {
        // Update or create variable
        ConversationVariable::updateOrCreate(
            ['conversation_id' => $this->id, 'key' => $key],
            ['value' => $value]
        );

        // Log the change
        ConversationVariableLog::create([
            'conversation_id' => $this->id,
            'key' => $key,
            'value' => $value,
        ]);
    }

    public function deleteVariable(string $key): void
    {
        $this->variables()->where('key', $key)->delete();
    }

    public function getAllVariables(): array
    {
        return $this->variables()->pluck('value', 'key')->toArray();
    }

    public function handoffToAgent(int $agentId, ?int $nodeId = null): void
    {
        $this->update([
            'status' => 'handed_off',
            'assigned_agent_id' => $agentId,
        ]);

        AgentHandoverLog::create([
            'conversation_id' => $this->id,
            'flow_node_id' => $nodeId,
            'assigned_agent_id' => $agentId,
            'started_at' => now(),
        ]);
    }

    public function resumeFromAgent(): void
    {
        // Close active handover log
        $this->handoverLogs()
            ->whereNull('ended_at')
            ->update(['ended_at' => now()]);

        $this->update([
            'status' => 'active',
            'assigned_agent_id' => null,
        ]);
    }

    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'ended_at' => now(),
        ]);
    }

    public function abandon(): void
    {
        $this->update([
            'status' => 'abandoned',
            'ended_at' => now(),
        ]);
    }

    public function getDuration(): ?int
    {
        if (!$this->ended_at) {
            return null;
        }

        return $this->started_at->diffInSeconds($this->ended_at);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isHandedOff(): bool
    {
        return $this->status === 'handed_off';
    }
}


class ConversationVariable extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'key',
        'value',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}


class ConversationVariableLog extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = [
        'conversation_id',
        'key',
        'value',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}