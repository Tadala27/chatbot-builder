<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowNode extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flow_version_id',
        'uuid',
        'type',
        'label',
        'content',
        'config',
        'position_x',
        'position_y',
        'retry_limit',
        'retry_fallback_node_id',
        'timeout_seconds',
        'timeout_next_node_id',
        'is_entry_point',
        'is_terminal',
        'ab_group',
        'ab_weight',
    ];

    protected $casts = [
        'content' => 'array',
        'config' => 'array',
        'position_x' => 'float',
        'position_y' => 'float',
        'retry_limit' => 'integer',
        'timeout_seconds' => 'integer',
        'is_entry_point' => 'boolean',
        'is_terminal' => 'boolean',
        'ab_weight' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function retryFallbackNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'retry_fallback_node_id');
    }

    public function timeoutNextNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'timeout_next_node_id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(FlowEdge::class, 'source_node_id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(FlowEdge::class, 'target_node_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(FlowNodeAction::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(NodeMetric::class);
    }

    public function executionLogs(): HasMany
    {
        return $this->hasMany(FlowExecutionLog::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeEntryPoints($query)
    {
        return $query->where('is_entry_point', true);
    }

    public function scopeTerminal($query)
    {
        return $query->where('is_terminal', true);
    }

    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    // ─── Boot ─────────────────────────────────────────────────────────────────

    public static function boot()
    {
        parent::boot();

        static::creating(function ($node) {
            if (empty($node->uuid)) {
                $node->uuid = (string) Str::uuid();
            }
        });
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function getNextNodes(): array
    {
        return $this->outgoingEdges()
            ->with('targetNode')
            ->orderBy('priority')
            ->get()
            ->pluck('targetNode')
            ->toArray();
    }

    public function hasNextNode(): bool
    {
        return $this->outgoingEdges()->exists();
    }

    public function isHandoff(): bool
    {
        return $this->type === 'handoff';
    }

    public function isCondition(): bool
    {
        return $this->type === 'condition';
    }

    public function requiresUserInput(): bool
    {
        return in_array($this->type, ['input', 'buttons', 'list']);
    }

    public function getActionsForEvent(string $event): array
    {
        return $this->actions()
            ->where('trigger_event', $event)
            ->orderBy('execution_order')
            ->get()
            ->toArray();
    }

    public function incrementEntered(?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        NodeMetric::updateOrCreate(
            ['flow_node_id' => $this->id, 'metric_date' => $date],
            ['entered_count' => DB::raw('entered_count + 1')]
        );
    }

    public function incrementCompleted(?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        NodeMetric::updateOrCreate(
            ['flow_node_id' => $this->id, 'metric_date' => $date],
            ['completed_count' => DB::raw('completed_count + 1')]
        );
    }

    public function incrementFailed(?string $date = null): void
    {
        $date = $date ?? now()->toDateString();

        NodeMetric::updateOrCreate(
            ['flow_node_id' => $this->id, 'metric_date' => $date],
            ['failed_count' => DB::raw('failed_count + 1')]
        );
    }

    public function getCompletionRate(): float
    {
        $metrics = $this->metrics()->selectRaw('
            SUM(entered_count) as total_entered,
            SUM(completed_count) as total_completed
        ')->first();

        if (!$metrics || $metrics->total_entered == 0) {
            return 0;
        }

        return round(($metrics->total_completed / $metrics->total_entered) * 100, 2);
    }

    public function getDropOffRate(): float
    {
        return 100 - $this->getCompletionRate();
    }

    public function getAverageExecutionTime(): int
    {
        return $this->executionLogs()
            ->where('success', true)
            ->avg('execution_time_ms') ?? 0;
    }
}