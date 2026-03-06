<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FlowController extends Controller
{
    // GET /api/bots/{bot}/flows
    public function index(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $query = Flow::where('bot_id', $bot->id);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%")
                ->orWhere('slug', 'like', "%{$s}%"));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $query->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'));

        return response()->json($query->paginate($request->get('per_page', 20)));
    }

    // GET /api/bots/{bot}/flows/{flow}
    public function show(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        return response()->json(['flow' => $flow->load('currentPublishedVersion')]);
    }

    // POST /api/bots/{bot}/flows
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $tenant = Tenant::current();
        if (!$tenant->canCreateFlow()) {
            return response()->json([
                'message'   => 'Flow limit reached for your subscription plan.',
                'max_flows' => $tenant->max_flows,
            ], 422);
        }

        $validated = $request->validate([
            'name'        => 'required|string|max:255',
            'description' => 'nullable|string',
            'slug'        => 'nullable|string|max:255',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($bot->id, $validated['name']);
        }

        $flow = null;

        DB::transaction(function () use (&$flow, $bot, $validated) {
            $flow = Flow::create([
                'bot_id'      => $bot->id,
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
                'slug'        => $validated['slug'],
                'status'      => 'draft',
            ]);

            // Seed the first draft version with an entry-point trigger dialog
            $version = FlowVersion::create([
                'flow_id'        => $flow->id,
                'version_number' => 1,
                'status'         => 'draft',
                'created_by'     => auth()->id(),
            ]);

            $entryDialog = $version->dialogs()->create([
                'uuid'           => (string) Str::uuid(),
                'label'          => 'Start',
                'kind'           => 'trigger',
                'is_entry_point' => true,
                'is_terminal'    => false,
                'position_x'     => 100,
                'position_y'     => 100,
                'config'         => [
                    'id'          => 'trigger-' . Str::uuid(),
                    'isFirstNode' => true,
                    'isLastNode'  => false,
                    'goTo'        => '',
                ],
            ]);

            $version->update(['start_node_id' => $entryDialog->id]);
            activity()->causedBy(auth()->user())->performedOn($flow)->log('Flow created');
        });


        return response()->json(['message' => 'Flow created.', 'flow' => $flow], 201);
    }

    // PUT /api/bots/{bot}/flows/{flow}
    public function update(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $validated = $request->validate([
            'name'        => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'slug'        => 'sometimes|string|max:255',
            'is_active'   => 'sometimes|boolean',
        ]);

        if (isset($validated['slug']) && $validated['slug'] !== $flow->slug) {
            if (Flow::where('bot_id', $bot->id)->where('slug', $validated['slug'])->where('id', '!=', $flow->id)->exists()) {
                return response()->json(['message' => 'Slug already in use on this bot.'], 422);
            }
        }

        $flow->update($validated);

        activity()->causedBy(auth()->user())->performedOn($flow)->log('Flow updated');

        return response()->json(['message' => 'Flow updated.', 'flow' => $flow]);
    }

    // DELETE /api/bots/{bot}/flows/{flow}
    public function destroy(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        if ($flow->conversations()->where('status', 'active')->exists()) {
            return response()->json(['message' => 'Cannot delete flow with active conversations.'], 422);
        }

        $name = $flow->name;
        $flow->delete();

        activity()->causedBy(auth()->user())->log("Flow deleted: {$name}");

        return response()->json(['message' => 'Flow deleted.']);
    }

    // POST /api/bots/{bot}/flows/{flow}/unpublish
    public function unpublish(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $flow->update([
            'status'                     => 'draft',
            'current_published_version_id' => null,
            'published_at'               => null,
            'is_active'                  => false,
        ]);

        activity()->causedBy(auth()->user())->performedOn($flow)->log('Flow unpublished');

        return response()->json(['message' => 'Flow unpublished.', 'flow' => $flow]);
    }

    // POST /api/bots/{bot}/flows/{flow}/duplicate
    public function duplicate(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $tenant = Tenant::current();
        if (!$tenant->canCreateFlow()) {
            return response()->json(['message' => 'Flow limit reached for your subscription plan.'], 422);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255',
        ]);

        $slug = $validated['slug'] ?? $this->uniqueSlug($bot->id, $validated['name']);

        $duplicate = null;

        DB::transaction(function () use (&$duplicate, $flow, $bot, $validated, $slug) {
            $duplicate = Flow::create([
                'bot_id'      => $bot->id,
                'name'        => $validated['name'],
                'description' => $flow->description,
                'slug'        => $slug,
                'status'      => 'draft',
            ]);

            // Clone the latest version's dialogs into a new draft version
            $sourceVersion = $flow->draftVersion() ?? $flow->publishedVersion();

            if ($sourceVersion) {
                $newVersion = FlowVersion::create([
                    'flow_id'        => $duplicate->id,
                    'version_number' => 1,
                    'status'         => 'draft',
                    'created_by'     => auth()->id(),
                ]);

                $dialogIdMap = [];

                foreach ($sourceVersion->dialogs as $dialog) {
                    $newDialog = $newVersion->dialogs()->create([
                        'uuid'           => (string) Str::uuid(),
                        'label'          => $dialog->label,
                        'kind'           => $dialog->kind,
                        'config'         => $dialog->config,
                        'position_x'     => $dialog->position_x,
                        'position_y'     => $dialog->position_y,
                        'is_entry_point' => $dialog->is_entry_point,
                        'is_terminal'    => $dialog->is_terminal,
                        'input_variable' => $dialog->input_variable,
                    ]);

                    $dialogIdMap[$dialog->id] = $newDialog->id;
                }

                if ($sourceVersion->start_node_id && isset($dialogIdMap[$sourceVersion->start_node_id])) {
                    $newVersion->update(['start_node_id' => $dialogIdMap[$sourceVersion->start_node_id]]);
                }
            }
            activity()->causedBy(auth()->user())->performedOn($duplicate)->log("Flow duplicated from: {$flow->name}");
        });


        return response()->json(['message' => 'Flow duplicated.', 'flow' => $duplicate], 201);
    }

    // -------------------------------------------------------------------------

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) {
            abort(404, 'Bot not found.');
        }
    }

    private function authorizeFlow(Bot $bot, Flow $flow): void
    {
        $this->authorizeBot($bot);
        if ($flow->bot_id !== $bot->id) {
            abort(404, 'Flow not found.');
        }
    }

    private function uniqueSlug(int $botId, string $name): string
    {
        $base  = Str::slug($name);
        $slug  = $base;
        $count = 1;
        while (Flow::where('bot_id', $botId)->where('slug', $slug)->exists()) {
            $slug = $base . '-' . $count++;
        }
        return $slug;
    }
}
