<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'flow_node_id',
        'metric_date',
        'entered_count',
        'completed_count',
        'failed_count',
    ];

    protected $casts = [
        'metric_date' => 'date',
        'entered_count' => 'integer',
        'completed_count' => 'integer',
        'failed_count' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flowNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class);
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function getCompletionRate(): float
    {
        if ($this->entered_count === 0) {
            return 0;
        }

        return round(($this->completed_count / $this->entered_count) * 100, 2);
    }

    public function getFailureRate(): float
    {
        if ($this->entered_count === 0) {
            return 0;
        }

        return round(($this->failed_count / $this->entered_count) * 100, 2);
    }

    public function getDropOffRate(): float
    {
        return 100 - $this->getCompletionRate();
    }
}