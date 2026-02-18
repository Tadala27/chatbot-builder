<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowNodeAction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flow_node_id',
        'trigger_event',
        'action_type',
        'execution_order',
        'config',
        'continue_on_failure',
    ];

    protected $casts = [
        'execution_order' => 'integer',
        'config' => 'array',
        'continue_on_failure' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function isSaveVariable(): bool
    {
        return in_array($this->action_type, ['save_variable', 'update_variable']);
    }

    public function isApiCall(): bool
    {
        return $this->action_type === 'api_call';
    }

    public function isFunction(): bool
    {
        return $this->action_type === 'execute_function';
    }

    public function isDelay(): bool
    {
        return $this->action_type === 'delay';
    }

    public function isWebhook(): bool
    {
        return $this->action_type === 'webhook_call';
    }

    public function getVariableKey(): ?string
    {
        return $this->config['variable_key'] ?? null;
    }

    public function getVariableValue(): ?string
    {
        return $this->config['variable_value'] ?? null;
    }

    public function getApiEndpoint(): ?string
    {
        return $this->config['api_endpoint'] ?? null;
    }

    public function getDelaySeconds(): int
    {
        return $this->config['delay_seconds'] ?? 0;
    }
}
