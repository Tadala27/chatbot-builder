<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowNode extends Model
{
    use HasFactory;

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
        'content'        => 'array',
        'config'         => 'array',
        'position_x'     => 'float',
        'position_y'     => 'float',
        'retry_limit'    => 'integer',
        'timeout_seconds' => 'integer',
        'is_entry_point' => 'boolean',
        'is_terminal'    => 'boolean',
        'ab_weight'      => 'integer',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function actions(): HasMany
    {
        return $this->hasMany(FlowNodeAction::class);
    }

    public function retryFallbackNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'retry_fallback_node_id');
    }

    public function timeoutNextNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'timeout_next_node_id');
    }

    // =========================================================================
    // ACTION HELPERS
    // =========================================================================

    /**
     * Get on_enter actions for this node (run when node is entered).
     * Returns array of action config arrays.
     */
    public function getEnterActions(): array
    {
        return $this->actions()
            ->where('trigger_event', 'on_enter')
            ->where(function ($q) {
                $q->whereNull('source_item_type')
                    ->orWhere('source_item_type', 'node');
            })
            ->orderBy('execution_order')
            ->get()
            ->pluck('config')
            ->filter(fn($c) => is_array($c))
            ->values()
            ->toArray();
    }

    /**
     * Get on_select actions for a specific button or list row.
     * Returns array of action config arrays.
     *
     * @param string $itemId The button id or row id
     */
    public function getSelectActionsForItem(string $itemId): array
    {
        return $this->actions()
            ->where('trigger_event', 'on_select')
            ->where('source_item_id', $itemId)
            ->where('source_item_type', 'in', ['button', 'row'])
            ->orderBy('execution_order')
            ->get()
            ->pluck('config')
            ->filter(fn($c) => is_array($c))
            ->values()
            ->toArray();
    }
    /**
     * Get on_success actions (run after successful execution).
     */
    public function getSuccessActions(): array
    {
        return $this->actions()
            ->where('trigger_event', 'on_success')
            ->where(function ($q) {
                $q->whereNull('source_item_type')
                    ->orWhere('source_item_type', 'node');
            })
            ->orderBy('execution_order')
            ->get()
            ->pluck('config')
            ->filter(fn($c) => is_array($c))
            ->values()
            ->toArray();
    }

    // =========================================================================
    // BUSINESS LOGIC
    // =========================================================================

    public function isInteractive(): bool
    {
        return in_array($this->type, ['buttons', 'list', 'input']);
    }

    public function requiresUserInput(): bool
    {
        $config = $this->config ?? [];
        return !empty($config['inputVariable']) || $this->isInteractive();
    }

    public function getNextNodeUuid(): ?string
    {
        return $this->config['goTo'] ?? null;
    }

    public function hasActions(): bool
    {
        return $this->actions()->exists();
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopeEntryPoint($query)
    {
        return $query->where('is_entry_point', true);
    }

    public function scopeTerminal($query)
    {
        return $query->where('is_terminal', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}