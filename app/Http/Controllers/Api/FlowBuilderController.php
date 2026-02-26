<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomVariable;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowBuilderController extends Controller
{
    /**
     * Get flow with current draft or published version for the builder.
     * Returns nodes + nodeActions separately for frontend rehydration.
     */
    public function show(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $version = $flow->getDraftVersion() ?? $flow->getPublishedVersion();

        if (!$version) {
            $version = $flow->createVersion();
        }

        $nodes = $version->nodes()->with('actions')->get();

        return response()->json([
            'flow'        => $flow->load('whatsappAccount'),
            'version'     => $version,
            'nodes'       => $nodes,
            'nodeActions' => $this->buildNodeActionsPayload($nodes),
        ]);
    }

    public function autoSave(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $validated = $request->validate([
            'nodes'              => 'required|array',
            'nodes.*.node_id'    => 'required|string',
            'nodes.*.node_type'  => 'required|string',
            'nodes.*.position_x' => 'required|numeric',
            'nodes.*.position_y' => 'required|numeric',
            'nodes.*.config'     => 'required|array',
        ]);

        $version = $flow->getDraftVersion();
        if (!$version) {
            return response()->json([
                'message' => 'No draft version found. Create new version first.',
            ], 422);
        }

        if ($version->status !== 'draft') {
            return response()->json([
                'message' => 'Cannot modify published version. Create new version first.',
            ], 422);
        }

        DB::transaction(function () use ($validated, $version) {
            $existingNodes = $version->nodes()
                ->with('actions')
                ->get()
                ->keyBy(fn($n) => $n->config['id'] ?? $n->uuid);

            $incomingIds = collect($validated['nodes'])->pluck('node_id')->all();

            // Delete removed nodes
            foreach ($existingNodes as $frontendId => $dbNode) {
                if (!in_array($frontendId, $incomingIds, true)) {
                    $dbNode->actions()->delete();
                    $dbNode->delete();
                }
            }

            // Upsert nodes
            $nodeIdMap = [];
            foreach ($validated['nodes'] as $nodeData) {
                $originalConfig       = $nodeData['config'];
                $originalConfig['id'] = $nodeData['node_id'];

                if ($nodeData['node_type'] === 'list') {
                    $originalConfig = $this->normalizeListNodeConfig($originalConfig);
                }

                $configForStorage = $this->stripActionsFromConfig($originalConfig);

                $attributes = [
                    'type'           => $this->mapNodeType($nodeData['node_type']),
                    'label'          => $originalConfig['kind'] ?? $nodeData['node_type'],
                    'content'        => $this->extractNodeContent($configForStorage),
                    'config'         => $configForStorage,
                    'position_x'     => $nodeData['position_x'],
                    'position_y'     => $nodeData['position_y'],
                    'is_entry_point' => $originalConfig['isFirstNode'] ?? false,
                    'is_terminal'    => $originalConfig['isLastNode']  ?? false,
                ];

                if (isset($existingNodes[$nodeData['node_id']])) {
                    $dbNode = $existingNodes[$nodeData['node_id']];
                    $dbNode->update($attributes);
                    $this->syncNodeActions($dbNode, $originalConfig);
                } else {
                    $dbNode = $version->nodes()->create(array_merge(
                        ['uuid' => Str::uuid()],
                        $attributes
                    ));
                    $this->createNodeActions($dbNode, $originalConfig['actions'] ?? []);
                    $this->createInteractiveActions($dbNode, $originalConfig);
                }

                $nodeIdMap[$nodeData['node_id']] = $dbNode->id;
            }

            // Update start node
            $firstNodeData = collect($validated['nodes'])
                ->firstWhere('config.isFirstNode', true);

            if ($firstNodeData && isset($nodeIdMap[$firstNodeData['node_id']])) {
                $version->start_node_id = $nodeIdMap[$firstNodeData['node_id']];
            }

            $version->touch();
            $version->save();
        });

        return response()->json([
            'message'  => 'Auto-saved',
            'saved_at' => now()->toIso8601String(),
        ]);
    }

    public function publish(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $version = $flow->getDraftVersion();

        if (!$version) {
            return response()->json(['message' => 'No draft version to publish'], 422);
        }

        $errors = $version->validate();

        if (!empty($errors)) {
            return response()->json([
                'message' => 'Flow validation failed',
                'errors'  => $errors,
            ], 422);
        }

        try {
            $flow->publish($version);

            return response()->json([
                'message' => 'Flow published successfully',
                'flow'    => $flow->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to publish flow',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function getVariables(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $customVariables = CustomVariable::where('flow_id', $flow->id)
            ->orderBy('name')
            ->get()
            ->map(fn($v) => array_merge($v->toArray(), ['is_system' => false]));

        $systemVariables = collect([
            ['id' => null, 'flow_id' => null, 'name' => 'phone_number',  'save_in' => 'bot_variables', 'use_in_js' => false, 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'flow_id' => null, 'name' => 'user_name',     'save_in' => 'bot_variables', 'use_in_js' => false, 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'flow_id' => null, 'name' => 'current_date',  'save_in' => 'bot_variables', 'use_in_js' => false, 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'flow_id' => null, 'name' => 'current_time',  'save_in' => 'bot_variables', 'use_in_js' => false, 'is_sensitive' => false, 'is_system' => true],
        ]);

        return response()->json([
            'variables' => $customVariables->concat($systemVariables)->unique('name')->values(),
        ]);
    }

    public function getVersions(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $versions = $flow->versions()
            ->orderBy('version_number', 'desc')
            ->get()
            ->map(fn($v) => [
                'id'             => $v->id,
                'version_number' => $v->version_number,
                'status'         => $v->status,
                'created_at'     => $v->created_at->toISOString(),
                'created_by'     => $v->created_by,
            ]);

        return response()->json(['versions' => $versions]);
    }

    public function getVersion(Flow $flow, int $versionId): JsonResponse
    {
        $this->authorizeAccess($flow);

        $version = $flow->versions()->findOrFail($versionId);
        $nodes   = $version->nodes()->with('actions')->get();

        return response()->json([
            'version' => [
                'id'             => $version->id,
                'version_number' => $version->version_number,
                'status'         => $version->status,
                'created_at'     => $version->created_at->toISOString(),
                'start_node_id'  => $version->start_node_id,
            ],
            'nodes'       => $nodes->map(fn($node) => [
                'id'             => $node->id,
                'uuid'           => $node->uuid,
                'type'           => $node->type,
                'label'          => $node->label,
                'config'         => $node->config,
                'position_x'     => $node->position_x,
                'position_y'     => $node->position_y,
                'is_entry_point' => $node->is_entry_point,
                'is_terminal'    => $node->is_terminal,
            ]),
            'nodeActions' => $this->buildNodeActionsPayload($nodes),
        ]);
    }

    public function createVersionFromExisting(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $validated = $request->validate([
            'source_version_id' => 'required|exists:flow_versions,id',
        ]);

        $sourceVersion    = $flow->versions()->findOrFail($validated['source_version_id']);
        $newVersionNumber = $flow->versions()->max('version_number') + 1;
        $newVersion       = null;

        DB::transaction(function () use (&$newVersion, $flow, $sourceVersion, $newVersionNumber) {
            $newVersion = $flow->versions()->create([
                'version_number' => $newVersionNumber,
                'status'         => 'draft',
                'created_by'     => auth()->id(),
            ]);

            $nodeIdMap = [];

            foreach ($sourceVersion->nodes as $oldNode) {
                $newNode = $newVersion->nodes()->create([
                    'uuid'           => Str::uuid(),
                    'type'           => $oldNode->type,
                    'label'          => $oldNode->label,
                    'content'        => $oldNode->content,
                    'config'         => $oldNode->config,
                    'position_x'     => $oldNode->position_x,
                    'position_y'     => $oldNode->position_y,
                    'is_entry_point' => $oldNode->is_entry_point,
                    'is_terminal'    => $oldNode->is_terminal,
                ]);

                $nodeIdMap[$oldNode->id] = $newNode->id;

                foreach ($oldNode->actions as $oldAction) {
                    $newNode->actions()->create([
                        'trigger_event'       => $oldAction->trigger_event,
                        'source_item_id'      => $oldAction->source_item_id,
                        'source_item_type'    => $oldAction->source_item_type,
                        'action_type'         => $oldAction->action_type,
                        'execution_order'     => $oldAction->execution_order,
                        'config'              => $oldAction->config,
                        'continue_on_failure' => $oldAction->continue_on_failure,
                    ]);
                }
            }

            if ($sourceVersion->start_node_id && isset($nodeIdMap[$sourceVersion->start_node_id])) {
                $newVersion->start_node_id = $nodeIdMap[$sourceVersion->start_node_id];
                $newVersion->save();
            }
        });

        activity()
            ->causedBy(auth()->user())
            ->performedOn($flow)
            ->log("Created version {$newVersionNumber} from version {$sourceVersion->version_number}");

        return response()->json([
            'message' => 'Version created successfully',
            'version' => $newVersion,
        ], 201);
    }

    // =========================================================================
    // PRIVATE HELPERS (All the complex node/action helpers from original)
    // =========================================================================

    private function authorizeAccess(Flow $flow): void
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            abort(404, 'Flow not found');
        }
    }

    private function buildNodeActionsPayload($nodes): array
    {
        return $nodes->flatMap(
            fn($node) =>
            $node->actions
                ->where('trigger_event', 'on_select')
                ->map(fn($a) => [
                    'node_id'          => $node->config['id'] ?? $node->uuid,
                    'source_item_id'   => $a->source_item_id,
                    'source_item_type' => $a->source_item_type,
                    'execution_order'  => $a->execution_order,
                    'config'           => $a->config,
                ])
        )->values()->toArray();
    }

    private function stripActionsFromConfig(array $config): array
    {
        if (!empty($config['buttons'])) {
            foreach ($config['buttons'] as &$btn) {
                unset($btn['actions']);
            }
            unset($btn);
        }

        if (!empty($config['action']['sections'])) {
            foreach ($config['action']['sections'] as &$section) {
                foreach ($section['rows'] ?? [] as &$row) {
                    unset($row['actions']);
                }
                unset($row);
            }
            unset($section);
        }

        if (!empty($config['sections'])) {
            foreach ($config['sections'] as &$section) {
                foreach ($section['rows'] ?? [] as &$row) {
                    unset($row['actions']);
                }
                unset($row);
            }
            unset($section);
        }

        return $config;
    }

    private function normalizeListNodeConfig(array $config): array
    {
        if (!isset($config['action'])) {
            $config['action'] = [
                'button'   => $config['actionButton'] ?? 'View Options',
                'sections' => $config['sections'] ?? [],
            ];
        }

        if (!isset($config['action']['button'])) {
            $config['action']['button'] = $config['actionButton'] ?? 'View Options';
        }

        if (!isset($config['action']['sections'])) {
            $config['action']['sections'] = $config['sections'] ?? [];
        }

        foreach ($config['action']['sections'] as &$section) {
            foreach ($section['rows'] ?? [] as &$row) {
                if (!isset($row['description'])) {
                    $row['description'] = $row['desc'] ?? '';
                }
                unset($row['desc']);
            }
            unset($row);
        }
        unset($section);

        return $config;
    }

    private function mapNodeType(string $frontendType): string
    {
        return [
            'trigger'  => 'message',
            'message'  => 'message',
            'buttons'  => 'buttons',
            'list'     => 'list',
            'media'    => 'message',
            'location' => 'message',
            'contact'  => 'message',
            'end'      => 'end',
        ][$frontendType] ?? 'message';
    }

    private function extractNodeContent(array $config): array
    {
        $content = [];

        if (!empty($config['text']))       $content['text']       = $config['text'];
        if (!empty($config['buttons']))    $content['buttons']    = $config['buttons'];
        if (!empty($config['action']))     $content['action']     = $config['action'];
        if (!empty($config['listHeader'])) $content['listHeader'] = $config['listHeader'];
        if (!empty($config['listFooter'])) $content['listFooter'] = $config['listFooter'];
        if (!empty($config['listBody']))   $content['listBody']   = $config['listBody'];
        if (!empty($config['sections']))   $content['sections']   = $config['sections'];

        if (!empty($config['mediaType'])) {
            $content['media_type'] = $config['mediaType'];
            $content['media_url']  = $config['mediaUrl']     ?? '';
            $content['caption']    = $config['mediaCaption'] ?? '';
        }

        return $content;
    }

    private function mapActionType(string $kind): string
    {
        return [
            'navigation' => 'emit_event',
            'condition'  => 'emit_event',
            'function'   => 'execute_function',
            'variable'   => 'save_variable',
            'delay'      => 'delay',
            'api'        => 'api_call',
        ][$kind] ?? 'emit_event';
    }

    private function syncNodeActions(FlowNode $node, array $config): void
    {
        $processedActionIds = [];

        // On-enter actions
        if (!empty($config['actions'])) {
            foreach ($config['actions'] as $index => $action) {
                if (!is_array($action)) continue;

                $existingAction = $this->findExistingAction($node, 'on_enter', 'node', null, $action, $index);

                if (!isset($action['id'])) {
                    $action['id'] = (string) Str::uuid();
                }

                $actionData = [
                    'trigger_event'       => 'on_enter',
                    'source_item_id'      => null,
                    'source_item_type'    => 'node',
                    'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                    'execution_order'     => $index,
                    'config'              => $action,
                    'continue_on_failure' => true,
                ];

                if ($existingAction) {
                    $existingAction->update($actionData);
                    $processedActionIds[] = $existingAction->id;
                } else {
                    $newAction = $node->actions()->create($actionData);
                    $processedActionIds[] = $newAction->id;
                }
            }
        }

        // Button actions
        if (!empty($config['buttons'])) {
            foreach ($config['buttons'] as $btnIndex => $btn) {
                $btnId = $btn['id'] ?? null;
                if (!$btnId) continue;

                foreach ($btn['actions'] ?? [] as $actIndex => $action) {
                    if (!is_array($action)) continue;

                    if (!isset($action['id'])) {
                        $action['id'] = (string) Str::uuid();
                    }

                    $existingAction = $this->findExistingAction($node, 'on_select', 'button', $btnId, $action, ($btnIndex * 10) + $actIndex);

                    $actionData = [
                        'trigger_event'       => 'on_select',
                        'source_item_id'      => $btnId,
                        'source_item_type'    => 'button',
                        'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                        'execution_order'     => ($btnIndex * 10) + $actIndex,
                        'config'              => $action,
                        'continue_on_failure' => true,
                    ];

                    if ($existingAction) {
                        $existingAction->update($actionData);
                        $processedActionIds[] = $existingAction->id;
                    } else {
                        $newAction = $node->actions()->create($actionData);
                        $processedActionIds[] = $newAction->id;
                    }
                }
            }
        }

        // List row actions
        $this->syncListRowActions($node, $config, $processedActionIds);

        // Clean up orphaned actions
        if (!empty($processedActionIds)) {
            $node->actions()
                ->whereNotIn('id', $processedActionIds)
                ->delete();
        }
    }

    private function findExistingAction(FlowNode $node, string $triggerEvent, string $sourceItemType, ?string $sourceItemId, array $action, int $executionOrder): ?\App\Models\FlowNodeAction
    {
        if (!empty($action['id'])) {
            $existing = $node->actions()
                ->where('trigger_event', $triggerEvent)
                ->where('source_item_type', $sourceItemType)
                ->when($sourceItemId, fn($q) => $q->where('source_item_id', $sourceItemId))
                ->where('config->id', $action['id'])
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        return $node->actions()
            ->where('trigger_event', $triggerEvent)
            ->where('source_item_type', $sourceItemType)
            ->when($sourceItemId, fn($q) => $q->where('source_item_id', $sourceItemId))
            ->where('execution_order', $executionOrder)
            ->first();
    }

    private function syncListRowActions(FlowNode $node, array $config, array &$processedActionIds): void
    {
        // New structure (action.sections)
        if (!empty($config['action']['sections'])) {
            foreach ($config['action']['sections'] as $sectionIndex => $section) {
                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    $rowId = $row['id'] ?? null;
                    if (!$rowId) continue;

                    foreach ($row['actions'] ?? [] as $actIndex => $action) {
                        if (!is_array($action)) continue;

                        if (!isset($action['id'])) {
                            $action['id'] = (string) Str::uuid();
                        }

                        $executionOrder = ($rowIndex * 10) + $actIndex;

                        $existingAction = $this->findExistingAction($node, 'on_select', 'row', $rowId, $action, $executionOrder);

                        $actionData = [
                            'trigger_event'       => 'on_select',
                            'source_item_id'      => $rowId,
                            'source_item_type'    => 'row',
                            'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                            'execution_order'     => $executionOrder,
                            'config'              => $action,
                            'continue_on_failure' => true,
                        ];

                        if ($existingAction) {
                            $existingAction->update($actionData);
                            $processedActionIds[] = $existingAction->id;
                        } else {
                            $newAction = $node->actions()->create($actionData);
                            $processedActionIds[] = $newAction->id;
                        }
                    }
                }
            }
        }

        // Legacy structure (direct sections)
        if (!empty($config['sections']) && empty($config['action'])) {
            foreach ($config['sections'] as $sectionIndex => $section) {
                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    $rowId = $row['id'] ?? null;
                    if (!$rowId) continue;

                    foreach ($row['actions'] ?? [] as $actIndex => $action) {
                        if (!is_array($action)) continue;

                        if (!isset($action['id'])) {
                            $action['id'] = (string) Str::uuid();
                        }

                        $executionOrder = ($rowIndex * 10) + $actIndex;

                        $existingAction = $this->findExistingAction($node, 'on_select', 'row', $rowId, $action, $executionOrder);

                        $actionData = [
                            'trigger_event'       => 'on_select',
                            'source_item_id'      => $rowId,
                            'source_item_type'    => 'row',
                            'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                            'execution_order'     => $executionOrder,
                            'config'              => $action,
                            'continue_on_failure' => true,
                        ];

                        if ($existingAction) {
                            $existingAction->update($actionData);
                            $processedActionIds[] = $existingAction->id;
                        } else {
                            $newAction = $node->actions()->create($actionData);
                            $processedActionIds[] = $newAction->id;
                        }
                    }
                }
            }
        }
    }

    private function createNodeActions(FlowNode $node, array $actions): void
    {
        foreach ($actions as $index => $action) {
            if (!is_array($action)) continue;

            $node->actions()->create([
                'trigger_event'       => 'on_enter',
                'source_item_id'      => null,
                'source_item_type'    => 'node',
                'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                'execution_order'     => $index,
                'config'              => $action,
                'continue_on_failure' => true,
            ]);
        }
    }

    private function createInteractiveActions(FlowNode $node, array $config): void
    {
        // List row actions - new structure
        if (!empty($config['action']['sections'])) {
            foreach ($config['action']['sections'] as $sectionIndex => $section) {
                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    $rowId = $row['id'] ?? null;
                    if (!$rowId) continue;

                    foreach ($row['actions'] ?? [] as $actIndex => $action) {
                        if (!is_array($action)) continue;

                        $node->actions()->create([
                            'trigger_event'       => 'on_select',
                            'source_item_id'      => $rowId,
                            'source_item_type'    => 'row',
                            'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                            'execution_order'     => ($rowIndex * 10) + $actIndex,
                            'config'              => $action,
                            'continue_on_failure' => true,
                        ]);
                    }
                }
            }
        }

        // List row actions - legacy structure
        if (!empty($config['sections']) && empty($config['action'])) {
            foreach ($config['sections'] as $sectionIndex => $section) {
                foreach ($section['rows'] ?? [] as $rowIndex => $row) {
                    $rowId = $row['id'] ?? null;
                    if (!$rowId) continue;

                    foreach ($row['actions'] ?? [] as $actIndex => $action) {
                        if (!is_array($action)) continue;

                        $node->actions()->create([
                            'trigger_event'       => 'on_select',
                            'source_item_id'      => $rowId,
                            'source_item_type'    => 'row',
                            'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                            'execution_order'     => ($rowIndex * 10) + $actIndex,
                            'config'              => $action,
                            'continue_on_failure' => true,
                        ]);
                    }
                }
            }
        }

        // Button actions
        if (!empty($config['buttons'])) {
            foreach ($config['buttons'] as $btnIndex => $btn) {
                $btnId = $btn['id'] ?? null;
                if (!$btnId) continue;

                foreach ($btn['actions'] ?? [] as $actIndex => $action) {
                    if (!is_array($action)) continue;

                    $node->actions()->create([
                        'trigger_event'       => 'on_select',
                        'source_item_id'      => $btnId,
                        'source_item_type'    => 'button',
                        'action_type'         => $this->mapActionType($action['kind'] ?? ''),
                        'execution_order'     => ($btnIndex * 10) + $actIndex,
                        'config'              => $action,
                        'continue_on_failure' => true,
                    ]);
                }
            }
        }
    }
}