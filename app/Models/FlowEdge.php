<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlowEdge extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flow_version_id',
        'source_node_id',
        'target_node_id',
        'source_handle',
        'label',
        'priority',
    ];

    protected $casts = [
        'priority' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flowVersion(): BelongsTo
    {
        return $this->belongsTo(FlowVersion::class);
    }

    public function sourceNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'source_node_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'target_node_id');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function hasCondition(): bool
    {
        return !empty($this->config['condition_group_id'] ?? null);
    }

    public function getConditionGroup(): ?ConditionGroup
    {
        $groupId = $this->config['condition_group_id'] ?? null;
        return $groupId ? ConditionGroup::find($groupId) : null;
    }
}
