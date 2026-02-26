<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class FlowVersion extends Model
{
    use HasFactory;

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
        'version_number'      => 'integer',
        'ai_fallback_enabled' => 'boolean',
        'ai_fallback_config'  => 'array',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

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

    // =========================================================================
    // SCOPES
    // =========================================================================

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

    // =========================================================================
    // BUSINESS LOGIC
    // =========================================================================

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

    public function getNodeCount(): int
    {
        return $this->nodes()->count();
    }

    /**
     * Validate the flow before publishing.
     *
    */
    public function validate(): array
    {
        $errors = [];

        // Eager-load actions so we don't N+1 inside the loops below
        $nodes = $this->nodes()->with('actions')->get();

        // ── 1. Start node ─────────────────────────────────────────────────────
        if (!$this->start_node_id) {
            $errors[] = 'No start node defined';
        } elseif (!$nodes->contains('id', $this->start_node_id)) {
            $errors[] = 'Start node does not exist';
        }

        if ($nodes->isEmpty()) {
            $errors[] = 'Flow has no nodes';
            return $errors;
        }

        // ── 2. Collect every UUID that is reachable as a navigation target ────
        // We read from DB actions — no JSON depth issues here.
        $referencedIds = collect();

        foreach ($nodes as $node) {
            $config = $node->config ?? [];

            // Config-level goTo (message / trigger nodes)
            if (!empty($config['goTo'])) {
                $referencedIds->push($config['goTo']);
            }

            // All DB actions (both on_enter and on_select)
            foreach ($node->actions as $action) {
                $this->collectTargetsFromActionConfig($action->config ?? [], $referencedIds);
            }
        }

        $referencedIds = $referencedIds->unique()->filter()->values();

        // ── 3. Orphaned nodes ─────────────────────────────────────────────────
        $startNode = $nodes->firstWhere('id', $this->start_node_id);

        $orphans = $nodes->filter(function ($node) use ($startNode, $referencedIds) {
            if ($startNode && $node->id === $startNode->id) return false;

            // A node is reachable if its uuid OR its config->id appears as a target
            return !$referencedIds->contains($node->uuid)
                && !$referencedIds->contains($node->config['id'] ?? null);
        });

        if ($orphans->count() > 0) {
            $errors[] = "{$orphans->count()} orphaned node(s) found: "
                . $orphans->pluck('id')->implode(', ');
        }

        // ── 4. Dead-end nodes ─────────────────────────────────────────────────
        $deadEnds = $nodes->filter(function ($node) {
            if ($node->is_terminal) return false;

            $config   = $node->config ?? [];
            $nodeKind = $config['kind'] ?? $node->type;

            if ($nodeKind === 'end') return false;

            // Buttons and list: check for on_select DB actions with a navigation target
            if (in_array($nodeKind, ['buttons', 'list'])) {
                $hasTarget = $node->actions
                    ->where('trigger_event', 'on_select')
                    ->contains(function ($action) {
                        $cfg = $action->config ?? [];
                        return !empty($cfg['goTo'])
                            || !empty($cfg['trueGoTo'])
                            || !empty($cfg['falseGoTo']);
                    });
                return !$hasTarget;
            }

            // All other nodes: dead-end if no config goTo AND no on_enter navigation action
            if (!empty($config['goTo'])) return false;

            return !$node->actions
                ->where('trigger_event', 'on_enter')
                ->contains(function ($action) {
                    $cfg = $action->config ?? [];
                    return !empty($cfg['goTo'])
                        || !empty($cfg['trueGoTo'])
                        || !empty($cfg['falseGoTo']);
                });
        });

        if ($deadEnds->count() > 0) {
            $errors[] = "{$deadEnds->count()} dead-end node(s) without a destination: "
                . $deadEnds->pluck('id')->implode(', ');
        }

        return $errors;
    }

    /**
     * Recursively collect all navigation UUIDs from a single action config.
     * Handles condition branches that contain nested navigation actions.
     */
    private function collectTargetsFromActionConfig(
        array $actionConfig,
        \Illuminate\Support\Collection &$uuids
    ): void {
        if (!empty($actionConfig['goTo']))      $uuids->push($actionConfig['goTo']);
        if (!empty($actionConfig['trueGoTo']))  $uuids->push($actionConfig['trueGoTo']);
        if (!empty($actionConfig['falseGoTo'])) $uuids->push($actionConfig['falseGoTo']);

        // Condition action branches
        foreach ($actionConfig['branches'] ?? [] as $branch) {
            foreach ($branch['actions'] ?? [] as $branchAction) {
                if (is_array($branchAction)) {
                    $this->collectTargetsFromActionConfig($branchAction, $uuids);
                }
            }
        }
    }

    // =========================================================================
    // DUPLICATION
    // =========================================================================

    public function duplicateToFlow(Flow $targetFlow): self
    {
        $newVersion = $targetFlow->versions()->create([
            'version_number'      => 1,
            'status'              => 'draft',
            'ai_fallback_enabled' => $this->ai_fallback_enabled,
            'ai_fallback_config'  => $this->ai_fallback_config,
            'created_by'          => auth()->id(),
        ]);

        $nodeMap = [];

        foreach ($this->nodes()->with('actions')->get() as $node) {
            $newNode                  = $node->replicate(['actions']);
            $newNode->flow_version_id = $newVersion->id;
            $newNode->save();
            $nodeMap[$node->id]       = $newNode->id;

            // Copy all actions including the new source_item_id / source_item_type fields
            foreach ($node->actions as $action) {
                $newAction = $action->replicate();
                $newAction->flow_node_id = $newNode->id;
                $newAction->save();
            }
        }

        if ($this->start_node_id && isset($nodeMap[$this->start_node_id])) {
            $newVersion->start_node_id = $nodeMap[$this->start_node_id];
        }

        if ($this->fallback_node_id && isset($nodeMap[$this->fallback_node_id])) {
            $newVersion->fallback_node_id = $nodeMap[$this->fallback_node_id];
        }

        $newVersion->save();

        return $newVersion;
    }
}