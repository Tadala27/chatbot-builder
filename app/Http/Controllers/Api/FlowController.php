<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Flow;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Flow::where('tenant_id', $tenant->id)
            ->with(['whatsappAccount', 'creator']);

        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $sortField     = $request->get('sort', 'created_at');
        $sortDirection = $request->get('direction', 'desc');
        $query->orderBy($sortField, $sortDirection);

        $flows = $query->paginate($request->get('per_page', 20));

        $flows->getCollection()->transform(function ($flow) {
            $flow->stats = [
                'total_conversations'     => $flow->getTotalConversations(),
                'completed_conversations' => $flow->getCompletedConversations(),
                'completion_rate'         => $flow->getCompletionRate(),
            ];
            return $flow;
        });

        return response()->json($flows);
    }

    public function show(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        return response()->json([
            'flow' => $flow->load('whatsappAccount'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        if (!$tenant->canCreateFlow()) {
            return response()->json([
                'message'   => 'Flow limit reached for your subscription plan',
                'max_flows' => $tenant->max_flows,
            ], 422);
        }

        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'slug'                => 'nullable|string|max:255',
            'welcome_message'     => 'nullable|string',
            'fallback_message'    => 'nullable|string',
            'default_language'    => 'nullable|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings'            => 'nullable|array',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($tenant->id, $validated['name']);
        }

        $validated['tenant_id']  = $tenant->id;
        $validated['created_by'] = auth()->id();
        $validated['status']     = 'draft';

        $flow = null;

        DB::transaction(function () use (&$flow, $validated) {
            $flow    = Flow::create($validated);

            // Create initial version with trigger node
            $version = $flow->versions()->create([
                'version_number' => 1,
                'status'         => 'draft',
                'created_by'     => auth()->id(),
            ]);

            $triggerNode = $version->nodes()->create([
                'uuid'           => Str::uuid(),
                'type'           => 'trigger',
                'label'          => 'Start',
                'position_x'     => 100,
                'position_y'     => 100,
                'is_entry_point' => true,
                'config'         => [
                    'kind'            => 'trigger',
                    'id'              => 'trigger-' . Str::uuid()->toString(),
                    'isFirstNode'     => true,
                    'isLastNode'      => false,
                    'triggersHandoff' => false,
                    'actions'         => [],
                    'goTo'            => '',
                    'text'            => 'keyword',
                    'mediaCaption'    => '',
                    'inputVariable'   => '',
                ],
            ]);

            $version->start_node_id = $triggerNode->id;
            $version->save();
            
            $this->logActivity($flow, 'Flow created');
        });


        return response()->json([
            'message' => 'Flow created successfully',
            'flow'    => $flow?->load('whatsappAccount'),
        ], 201);
    }

    public function update(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $validated = $request->validate([
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
            'name'                => 'sometimes|string|max:255',
            'description'         => 'nullable|string',
            'slug'                => 'sometimes|string|max:255',
            'welcome_message'     => 'nullable|string',
            'fallback_message'    => 'nullable|string',
            'default_language'    => 'sometimes|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings'            => 'nullable|array',
        ]);

        if (isset($validated['slug']) && $validated['slug'] !== $flow->slug) {
            $this->validateUniqueSlug($flow, $validated['slug']);
        }

        $flow->update($validated);

        $this->logActivity($flow, 'Flow updated');

        return response()->json([
            'message' => 'Flow updated successfully',
            'flow'    => $flow->load('whatsappAccount'),
        ]);
    }

    public function destroy(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        if ($flow->conversations()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete flow with active conversations',
            ], 422);
        }

        $flowName = $flow->name;
        $flow->delete();

        activity()
            ->causedBy(auth()->user())
            ->log('Flow deleted: ' . $flowName);

        return response()->json(['message' => 'Flow deleted successfully']);
    }

    public function unpublish(Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $flow->unpublish();

        return response()->json([
            'message' => 'Flow unpublished successfully',
            'flow'    => $flow,
        ]);
    }

    public function duplicate(Request $request, Flow $flow): JsonResponse
    {
        $this->authorizeAccess($flow);

        $tenant = Tenant::current();

        if (!$tenant->canCreateFlow()) {
            return response()->json([
                'message' => 'Flow limit reached for your subscription plan',
            ], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->generateUniqueSlug($tenant->id, $validated['name']);
        }

        $duplicate = $flow->duplicate($validated['name'], $validated['slug']);

        return response()->json([
            'message' => 'Flow duplicated successfully',
            'flow'    => $duplicate->load('whatsappAccount'),
        ], 201);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function authorizeAccess(Flow $flow): void
    {
        $tenant = Tenant::current();

        if ($flow->tenant_id !== $tenant->id) {
            abort(404, 'Flow not found');
        }
    }

    private function generateUniqueSlug(int $tenantId, string $name): string
    {
        $slug  = Str::slug($name);
        $count = 1;

        while (Flow::where('tenant_id', $tenantId)->where('slug', $slug)->exists()) {
            $slug = Str::slug($name) . '-' . $count++;
        }

        return $slug;
    }

    private function validateUniqueSlug(Flow $flow, string $slug): void
    {
        $exists = Flow::where('tenant_id', $flow->tenant_id)
            ->where('slug', $slug)
            ->where('id', '!=', $flow->id)
            ->exists();

        if ($exists) {
            abort(422, 'Slug already exists');
        }
    }

    private function logActivity(Flow $flow, string $description): void
    {
        activity()
            ->causedBy(auth()->user())
            ->performedOn($flow)
            ->log($description);
    }
}