<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\FlowNode;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    /**
     * List chatbots for current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Flow::where('tenant_id', $tenant->id)
            ->with(['whatsappAccount', 'creator']);

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by WhatsApp account
        if ($request->has('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        // Filter by active/inactive
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        // Sorting
        $sortField = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $flows = $query->paginate($request->get('per_page', 20));


        $flows->getCollection()->transform(function ($flow) {
            $flow->stats = [
                'total_conversations' => $flow->getTotalConversations(),
                'completed_conversations' => $flow->getCompletedConversations(),
                'completion_rate' => $flow->getCompletionRate(),
            ];
            return $flow;
        });

        return response()->json($flows);
    }
    /**
     * Get flow with current draft or published version
     */
    public function show(Flow $flow): JsonResponse
    {
        // Get draft version or published version
        $version = $flow->getDraftVersion() ?? $flow->getPublishedVersion();

        if (!$version) {
            // Create initial draft version if none exists
            $version = $flow->createVersion();
        }

        return response()->json([
            'flow' => $flow->load('whatsappAccount'),
            'version' => $version,
            'nodes' => $version->nodes()->with('actions')->get(),
            'edges' => $version->edges()->get(),
        ]);
    }
    /**
     * Create new flow
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        // Check if tenant can create more flows
        if (!$tenant->canCreateFlow()) {
            return response()->json([
                'message' => 'Flow limit reached for your subscription plan',
                'max_flows' => $tenant->max_flows,
            ], 422);
        }

        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'slug' => 'nullable|string|max:255',
            'welcome_message' => 'nullable|string',
            'fallback_message' => 'nullable|string',
            'default_language' => 'nullable|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        // Auto-generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);

            // Ensure uniqueness within tenant
            $count = 1;
            while (Flow::where('tenant_id', $tenant->id)
                ->where('slug', $validated['slug'])
                ->exists()
            ) {
                $validated['slug'] = Str::slug($validated['name']) . '-' . $count;
                $count++;
            }
        }

        $validated['tenant_id'] = $tenant->id;
        $validated['created_by'] = auth()->id();
        $validated['status'] = 'draft';

        DB::transaction(function () use (&$flow, $validated) {
            // Create flow
            $flow = Flow::create($validated);

            // Create initial draft version
            $version = $flow->versions()->create([
                'version_number' => 1,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);

            // Create COMPLETE trigger node with all configurable fields
            $triggerNode = $version->nodes()->create([
                'uuid' => Str::uuid(),
                'type' => 'trigger',
                'label' => 'Start',
                'position_x' => 100,
                'position_y' => 100,
                'is_entry_point' => true,
                'config' => [
                    // Node metadata
                    'kind' => 'trigger',
                    'id' => 'trigger-' . Str::uuid()->toString(),
                    'isFirstNode' => true,
                    'isLastNode' => false,
                    'triggersHandoff' => false,
                    'actions' => [],
                    'goTo' => '',

                    // Trigger-specific configuration
                    'text' => 'keyword', // Trigger type: keyword, any, first, opt_in
                    'mediaCaption' => '', // Keywords for matching (comma-separated)
                    'inputVariable' => '', // Optional variable to capture trigger input
                ],
            ]);

            // Set as start node
            $version->start_node_id = $triggerNode->id;
            $version->save();
        });

        if ($flow !== null) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($flow)
                ->log('Flow created');
        }

        return response()->json([
            'message' => 'Flow created successfully',
            'flow' => $flow?->load('whatsappAccount'),
        ], 201);
    }
    /**
     * Update flow
     */
    public function update(Request $request, Flow $flow): JsonResponse
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Flow not found'], 404);
        }

        $validated = $request->validate([
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'slug' => 'sometimes|string|max:255',
            'welcome_message' => 'nullable|string',
            'fallback_message' => 'nullable|string',
            'default_language' => 'sometimes|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings' => 'nullable|array',
        ]);

        // Check slug uniqueness if changed
        if (isset($validated['slug']) && $validated['slug'] !== $flow->slug) {
            $exists = Flow::where('tenant_id', $flow->tenant_id)
                ->where('slug', $validated['slug'])
                ->where('id', '!=', $flow->id)
                ->exists();

            if ($exists) {
                return response()->json([
                    'message' => 'Slug already exists',
                    'errors' => ['slug' => ['This slug is already in use']],
                ], 422);
            }
        }

        $flow->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($flow)
            ->log('Flow updated');

        return response()->json([
            'message' => 'Flow updated successfully',
            'flow' => $flow->load('whatsappAccount'),
        ]);
    }

    /**
     * Delete flow
     */
    public function destroy(Flow $flow): JsonResponse
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Flow not found'], 404);
        }

        // Check if flow has active conversations
        if ($flow->conversations()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete flow with active conversations',
            ], 422);
        }

        $flow->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Flow deleted: ' . $flow->name);

        return response()->json([
            'message' => 'Flow deleted successfully',
        ]);
    }

    /**
     * Unpublish flow
     */
    public function unpublish(Flow $flow): JsonResponse
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Flow not found'], 404);
        }

        $flow->unpublish();

        return response()->json([
            'message' => 'Flow unpublished successfully',
            'flow' => $flow,
        ]);
    }

    /**
     * Duplicate flow
     */
    public function duplicate(Request $request, Flow $flow): JsonResponse
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Flow not found'], 404);
        }

        // Check if tenant can create more flows
        if (!$tenant->canCreateFlow()) {
            return response()->json([
                'message' => 'Flow limit reached for your subscription plan',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        // Auto-generate slug if not provided
        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);

            $count = 1;
            while (Flow::where('tenant_id', $tenant->id)
                ->where('slug', $validated['slug'])
                ->exists()
            ) {
                $validated['slug'] = Str::slug($validated['name']) . '-' . $count;
                $count++;
            }
        }

        $duplicate = $flow->duplicate($validated['name'], $validated['slug']);

        return response()->json([
            'message' => 'Flow duplicated successfully',
            'flow' => $duplicate->load('whatsappAccount'),
        ], 201);
    }
    /**
     * Save complete flow (batch update nodes & edges)
     */
    public function saveFlow(Request $request, Flow $flow): JsonResponse
    {
        $validated = $request->validate([
            'nodes' => 'required|array',
            'nodes.*.node_id' => 'required|string',
            'nodes.*.node_type' => 'required|string',
            'nodes.*.position_x' => 'required|numeric',
            'nodes.*.position_y' => 'required|numeric',
            'nodes.*.config' => 'required|array',
            'edges' => 'required|array',
            'edges.*.edge_id' => 'required|string',
            'edges.*.source_node_id' => 'required|string',
            'edges.*.target_node_id' => 'required|string',
            'edges.*.label' => 'nullable|string',
        ]);

        // Get draft version (create if doesn't exist)
        $version = $flow->getDraftVersion();
        if (!$version) {
            $version = $flow->createVersion();
        }

        DB::transaction(function () use ($validated, $version) {
            // Map of old node IDs to new database IDs
            $nodeIdMap = [];

            // Delete existing nodes and edges
            $version->nodes()->delete();
            $version->edges()->delete();

            // Create nodes
            foreach ($validated['nodes'] as $nodeData) {
                $config = $nodeData['config'];

                // Extract node data
                $node = $version->nodes()->create([
                    'uuid' => Str::uuid(),
                    'type' => $this->mapNodeType($nodeData['node_type']),
                    'label' => $config['kind'] ?? $nodeData['node_type'],
                    'content' => $this->extractNodeContent($config),
                    'config' => $config,
                    'position_x' => $nodeData['position_x'],
                    'position_y' => $nodeData['position_y'],
                    'is_entry_point' => $config['isFirstNode'] ?? false,
                    'is_terminal' => $config['isLastNode'] ?? false,
                ]);

                $nodeIdMap[$nodeData['node_id']] = $node->id;

                // Create node actions if present
                if (!empty($config['actions'])) {
                    $this->createNodeActions($node, $config['actions']);
                }

                // Create button/list actions
                $this->createInteractiveActions($node, $config);
            }

            // Update version start_node_id if first node is set
            $firstNodeConfig = collect($validated['nodes'])->firstWhere('config.isFirstNode', true);
            if ($firstNodeConfig) {
                $version->start_node_id = $nodeIdMap[$firstNodeConfig['node_id']];
                $version->save();
            }

            // Create edges with remapped IDs
            foreach ($validated['edges'] as $edgeData) {
                $sourceId = $nodeIdMap[$edgeData['source_node_id']] ?? null;
                $targetId = $nodeIdMap[$edgeData['target_node_id']] ?? null;

                if ($sourceId && $targetId) {
                    $version->edges()->create([
                        'source_node_id' => $sourceId,
                        'target_node_id' => $targetId,
                        'label' => $edgeData['label'] ?? null,
                        'priority' => 0,
                    ]);
                }
            }
        });

        return response()->json([
            'message' => 'Flow saved successfully',
            'version' => $version->fresh()->load('nodes', 'edges'),
        ]);
    }

    /**
     * Publish flow
     */
    public function publish(Flow $flow): JsonResponse
    {
        $version = $flow->getDraftVersion();

        if (!$version) {
            return response()->json([
                'message' => 'No draft version to publish'
            ], 422);
        }

        // Validate before publishing
        $errors = $version->validate();
        if (!empty($errors)) {
            return response()->json([
                'message' => 'Flow validation failed',
                'errors' => $errors,
            ], 422);
        }

        // Publish
        $flow->publish($version);

        return response()->json([
            'message' => 'Flow published successfully',
            'flow' => $flow->fresh(),
        ]);
    }

    /**
     * Get all variables for this flow
     */
    public function getVariables(Flow $flow): JsonResponse
    {
        $version = $flow->getDraftVersion() ?? $flow->getPublishedVersion();

        if (!$version) {
            return response()->json(['variables' => []]);
        }

        // Extract all variable names from node configs
        $variables = collect();

        foreach ($version->nodes as $node) {
            $config = $node->config;

            // Input variables (message nodes capturing user input)
            if (!empty($config['inputVariable'])) {
                $variables->push(['key' => $config['inputVariable']]);
            }

            // Button save variables
            if (!empty($config['buttons'])) {
                foreach ($config['buttons'] as $btn) {
                    if (!empty($btn['saveVariable'])) {
                        $variables->push(['key' => $btn['saveVariable']]);
                    }
                }
            }

            // List row save variables
            if (!empty($config['sections'])) {
                foreach ($config['sections'] as $section) {
                    foreach ($section['rows'] ?? [] as $row) {
                        if (!empty($row['saveVariable'])) {
                            $variables->push(['key' => $row['saveVariable']]);
                        }
                    }
                }
            }

            // Variable actions
            foreach ($node->actions as $action) {
                if ($action->action_type === 'save_variable' && !empty($action->config['variable_key'])) {
                    $variables->push(['key' => $action->config['variable_key']]);
                }
            }
        }

        // Add system variables
        $systemVars = [
            ['key' => 'phone_number'],
            ['key' => 'user_name'],
            ['key' => 'current_date'],
            ['key' => 'current_time'],
        ];

        $allVariables = $variables->merge($systemVars)->unique('key')->values();

        return response()->json([
            'variables' => $allVariables,
        ]);
    }

    /**
     * Helper: Map frontend node type to backend enum
     */
    private function mapNodeType(string $frontendType): string
    {
        $mapping = [
            'trigger' => 'message',
            'message' => 'message',
            'buttons' => 'buttons',
            'list' => 'list',
            'media' => 'message',
            'end' => 'end',
        ];

        return $mapping[$frontendType] ?? 'message';
    }

    /**
     * Helper: Extract node content from config
     */
    private function extractNodeContent(array $config): array
    {
        $content = [];

        // Text content
        if (!empty($config['text'])) {
            $content['text'] = $config['text'];
        }

        // Buttons
        if (!empty($config['buttons'])) {
            $content['buttons'] = $config['buttons'];
        }

        // List
        if (!empty($config['sections'])) {
            $content['sections'] = $config['sections'];
        }

        // Media
        if (!empty($config['mediaType'])) {
            $content['media_type'] = $config['mediaType'];
            $content['media_url'] = $config['mediaUrl'] ?? '';
            $content['caption'] = $config['mediaCaption'] ?? '';
        }

        return $content;
    }

    /**
     * Helper: Create node actions from config
     */
    private function createNodeActions(FlowNode $node, array $actions): void
    {
        foreach ($actions as $index => $action) {
            $actionType = $this->mapActionType($action['kind']);

            $node->actions()->create([
                'trigger_event' => 'on_enter', // Default
                'action_type' => $actionType,
                'execution_order' => $index,
                'config' => $action,
                'continue_on_failure' => true,
            ]);
        }
    }

    /**
     * Helper: Create actions from buttons/list items
     */
    private function createInteractiveActions(FlowNode $node, array $config): void
    {
        // Button actions
        if (!empty($config['buttons'])) {
            foreach ($config['buttons'] as $btnIndex => $btn) {
                if (!empty($btn['actions'])) {
                    foreach ($btn['actions'] as $actIndex => $action) {
                        $node->actions()->create([
                            'trigger_event' => 'on_success',
                            'action_type' => $this->mapActionType($action['kind']),
                            'execution_order' => ($btnIndex * 10) + $actIndex,
                            'config' => array_merge($action, [
                                'source_button' => $btn['label'],
                                'source_button_id' => $btn['id'],
                            ]),
                            'continue_on_failure' => true,
                        ]);
                    }
                }
            }
        }

        // List row actions
        if (!empty($config['sections'])) {
            $rowIndex = 0;
            foreach ($config['sections'] as $section) {
                foreach ($section['rows'] ?? [] as $row) {
                    if (!empty($row['actions'])) {
                        foreach ($row['actions'] as $actIndex => $action) {
                            $node->actions()->create([
                                'trigger_event' => 'on_success',
                                'action_type' => $this->mapActionType($action['kind']),
                                'execution_order' => ($rowIndex * 10) + $actIndex,
                                'config' => array_merge($action, [
                                    'source_row' => $row['title'],
                                    'source_row_id' => $row['id'],
                                ]),
                                'continue_on_failure' => true,
                            ]);
                        }
                    }
                    $rowIndex++;
                }
            }
        }
    }

    /**
     * Helper: Map frontend action kind to backend enum
     */
    private function mapActionType(string $kind): string
    {
        $mapping = [
            'navigation' => 'emit_event', // Navigation is handled via edges
            'condition' => 'emit_event',  // Conditions create condition_groups
            'function' => 'execute_function',
            'variable' => 'save_variable',
            'delay' => 'delay',
            'api' => 'api_call',
        ];

        return $mapping[$kind] ?? 'emit_event';
    }
}