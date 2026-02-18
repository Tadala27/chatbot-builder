<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowExecutionLog extends Model
{
    use HasFactory;

    public $timestamps = false;
    protected $dates = ['created_at'];

    protected $fillable = [
        'conversation_id',
        'flow_node_id',
        'event_type',
        'success',
        'error_message',
        'execution_time_ms',
    ];

    protected $casts = [
        'success' => 'boolean',
        'execution_time_ms' => 'integer',
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

    // ─── Business Logic ───────────────────────────────────────────────────────

    public static function logNodeEnter(Conversation $conversation, FlowNode $node, bool $success = true, ?string $error = null, ?int $executionTimeMs = null): void
    {
        self::create([
            'conversation_id' => $conversation->id,
            'flow_node_id' => $node->id,
            'event_type' => 'node_enter',
            'success' => $success,
            'error_message' => $error,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }

    public static function logNodeExit(Conversation $conversation, FlowNode $node, bool $success = true, ?string $error = null, ?int $executionTimeMs = null): void
    {
        self::create([
            'conversation_id' => $conversation->id,
            'flow_node_id' => $node->id,
            'event_type' => 'node_exit',
            'success' => $success,
            'error_message' => $error,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }

    public static function logActionExecution(Conversation $conversation, FlowNode $node, string $actionType, bool $success = true, ?string $error = null, ?int $executionTimeMs = null): void
    {
        self::create([
            'conversation_id' => $conversation->id,
            'flow_node_id' => $node->id,
            'event_type' => "action_{$actionType}",
            'success' => $success,
            'error_message' => $error,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }
}