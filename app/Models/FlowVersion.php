<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowVersion extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flow_id',
        'version_number',
        'status',
        'start_node_id',
        'fallback_node_id',
        'ai_fallback_enabled',
        'ai_fallback_config',
        'changelog',
        'created_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'ai_fallback_enabled' => 'boolean',
        'ai_fallback_config' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function startNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'start_node_id');
    }

    public function fallbackNode(): BelongsTo
    {
        return $this->belongsTo(FlowNode::class, 'fallback_node_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(FlowNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(FlowEdge::class);
    }

    public function conditionGroups(): HasMany
    {
        return $this->hasMany(ConditionGroup::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeLocked($query)
    {
        return $query->where('status', 'locked');
    }

    // ─── Business Logic ───────────────────────────────────────────────────────

    public function lock(): bool
    {
        if ($this->status === 'published') {
            $this->update(['status' => 'locked']);
            return true;
        }
        return false;
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    public function validate(): array
    {
        $errors = [];

        // Check if start node exists
        if (!$this->start_node_id) {
            $errors[] = 'No start node defined';
        } elseif (!$this->nodes()->where('id', $this->start_node_id)->exists()) {
            $errors[] = 'Start node does not exist';
        }

        // Check for orphaned nodes (no incoming edges)
        $nodesWithIncoming = $this->edges()->pluck('target_node_id')->unique();
        $orphans = $this->nodes()
            ->whereNotIn('id', $nodesWithIncoming)
            ->where('id', '!=', $this->start_node_id)
            ->count();
        
        if ($orphans > 0) {
            $errors[] = "$orphans orphaned node(s) found";
        }

        // Check for terminal nodes (nodes with no outgoing edges that aren't marked as terminal)
        $nodesWithOutgoing = $this->edges()->pluck('source_node_id')->unique();
        $deadEnds = $this->nodes()
            ->whereNotIn('id', $nodesWithOutgoing)
            ->where('is_terminal', false)
            ->count();
        
        if ($deadEnds > 0) {
            $errors[] = "$deadEnds dead-end node(s) without terminal flag";
        }

        return $errors;
    }

    public function duplicateToFlow(Flow $targetFlow): self
    {
        // Create new version
        $newVersion = $targetFlow->versions()->create([
            'version_number' => 1,
            'status' => 'draft',
            'ai_fallback_enabled' => $this->ai_fallback_enabled,
            'ai_fallback_config' => $this->ai_fallback_config,
            'created_by' => auth()->id(),
        ]);

        // Map old node IDs to new node IDs
        $nodeMap = [];

        // Duplicate nodes
        foreach ($this->nodes as $node) {
            $newNode = $node->replicate();
            $newNode->flow_version_id = $newVersion->id;
            $newNode->save();
            $nodeMap[$node->id] = $newNode->id;

            // Duplicate node actions
            foreach ($node->actions as $action) {
                $newAction = $action->replicate();
                $newAction->flow_node_id = $newNode->id;
                $newAction->save();
            }
        }

        // Duplicate edges with remapped node IDs
        foreach ($this->edges as $edge) {
            $newEdge = $edge->replicate();
            $newEdge->flow_version_id = $newVersion->id;
            $newEdge->source_node_id = $nodeMap[$edge->source_node_id] ?? null;
            $newEdge->target_node_id = $nodeMap[$edge->target_node_id] ?? null;
            $newEdge->save();
        }

        // Update start/fallback node references
        if ($this->start_node_id && isset($nodeMap[$this->start_node_id])) {
            $newVersion->start_node_id = $nodeMap[$this->start_node_id];
        }
        if ($this->fallback_node_id && isset($nodeMap[$this->fallback_node_id])) {
            $newVersion->fallback_node_id = $nodeMap[$this->fallback_node_id];
        }
        $newVersion->save();

        return $newVersion;
    }

    public function getNodeCount(): int
    {
        return $this->nodes()->count();
    }

    public function getEdgeCount(): int
    {
        return $this->edges()->count();
    }
}
