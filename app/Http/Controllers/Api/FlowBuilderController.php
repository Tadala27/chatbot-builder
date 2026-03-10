<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\CustomVariable;
use App\Models\Dialog;
use App\Models\Flow;
use App\Models\FlowVersion;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Handles the visual flow builder: fetching, auto-saving dialogs, publishing
 * and version management. "Dialogs" are the new equivalent of "flow_nodes".
 */
class FlowBuilderController extends Controller
{
    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/builder
    // Returns the current draft (or published) version with all dialogs.
    // =========================================================================

    public function show(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $version = $flow->draftVersion() ?? $flow->publishedVersion();

        if (!$version) {
            $version = $this->createInitialVersion($flow);
        }

        $dialogs = $version->dialogs()->with(['options', 'actions'])->get();

        return response()->json([
            'flow'    => $flow,
            'version' => $version,
            'dialogs' => $dialogs,
        ]);
    }

    // =========================================================================
    // POST /api/bots/{bot}/flows/{flow}/builder/save
    // Auto-save: upsert dialogs + options + actions from the frontend state.
    // =========================================================================

    public function autoSave(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $validated = $request->validate([
            'dialogs'                  => 'required|array',
            'dialogs.*.dialog_id'      => 'required|string',   // frontend UUID
            'dialogs.*.kind'           => 'required|string',
            'dialogs.*.label'          => 'nullable|string',
            'dialogs.*.position_x'     => 'required|numeric',
            'dialogs.*.position_y'     => 'required|numeric',
            'dialogs.*.config'         => 'required|array',
            'dialogs.*.is_entry_point' => 'sometimes|boolean',
            'dialogs.*.is_terminal'    => 'sometimes|boolean',
            'dialogs.*.options'        => 'sometimes|array',
            'dialogs.*.actions'        => 'sometimes|array',
        ]);

        $version = $flow->draftVersion();

        if (!$version) {
            return response()->json(['message' => 'No draft version found. Create a new version first.'], 422);
        }

        if ($version->status !== 'draft') {
            return response()->json(['message' => 'Cannot modify a published version. Create a new version first.'], 422);
        }

        DB::transaction(function () use ($validated, $version) {
            // Index existing dialogs by their frontend uuid stored in config['id']
            $existing = $version->dialogs()
                ->with(['options', 'actions'])
                ->get()
                ->keyBy(fn($d) => $d->config['id'] ?? $d->uuid);

            $incomingIds = collect($validated['dialogs'])->pluck('dialog_id')->all();

            // Remove deleted dialogs
            foreach ($existing as $frontendId => $dbDialog) {
                if (!in_array($frontendId, $incomingIds, true)) {
                    $dbDialog->actions()->delete();
                    $dbDialog->options()->delete();
                    $dbDialog->delete();
                }
            }

            $dialogIdMap = [];

            foreach ($validated['dialogs'] as $data) {
                $config              = $data['config'];
                $config['id']        = $data['dialog_id'];

                $attributes = [
                    'kind'           => $data['kind'],
                    'label'          => $data['label'] ?? $data['kind'],
                    'config'         => $config,
                    'position_x'     => $data['position_x'],
                    'position_y'     => $data['position_y'],
                    'is_entry_point' => $data['is_entry_point'] ?? ($config['isFirstNode'] ?? false),
                    'is_terminal'    => $data['is_terminal']    ?? ($config['isLastNode']  ?? false),
                    'input_variable' => $config['inputVariable'] ?? null,
                ];

                if (isset($existing[$data['dialog_id']])) {
                    $dbDialog = $existing[$data['dialog_id']];
                    $dbDialog->update($attributes);
                } else {
                    $dbDialog = $version->dialogs()->create(array_merge(
                        ['uuid' => (string) Str::uuid()],
                        $attributes
                    ));
                }

                $dialogIdMap[$data['dialog_id']] = $dbDialog->id;

                // Sync options (buttons / list rows)
                $this->syncOptions($dbDialog, $data['options'] ?? []);

                // Sync actions
                $this->syncActions($dbDialog, $data['actions'] ?? []);
            }

            // Update the entry-point start_node_id on the version
            $entryData = collect($validated['dialogs'])->first(fn($d) => ($d['is_entry_point'] ?? false) || ($d['config']['isFirstNode'] ?? false));
            if ($entryData && isset($dialogIdMap[$entryData['dialog_id']])) {
                $version->update(['start_node_id' => $dialogIdMap[$entryData['dialog_id']]]);
            }

            $version->touch();
        });

        return response()->json(['message' => 'Auto-saved.', 'saved_at' => now()->toIso8601String()]);
    }

    // =========================================================================
    // POST /api/bots/{bot}/flows/{flow}/builder/publish
    // =========================================================================
    public function publish(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $version = $flow->draftVersion();

        if (!$version) {
            return response()->json(['message' => 'No draft version to publish.'], 422);
        }

        if (!$version->dialogs()->where('is_entry_point', true)->exists()) {
            return response()->json([
                'message' => 'Flow validation failed.',
                'errors'  => ['Flow must have at least one entry-point dialog.'],
            ], 422);
        }

        DB::transaction(function () use ($bot, $flow, $version) {
            // Archive all OTHER currently-published flows for this bot in bulk
            $otherPublishedIds = Flow::where('bot_id', $bot->id)
                ->where('status', 'published')
                ->where('id', '!=', $flow->id)
                ->pluck('current_published_version_id', 'id'); // [flow_id => version_id]

            if ($otherPublishedIds->isNotEmpty()) {
                Flow::whereIn('id', $otherPublishedIds->keys())
                    ->update(['status' => 'archived', 'is_active' => false]);

                FlowVersion::whereIn('id', $otherPublishedIds->values()->filter())
                    ->update(['status' => 'archived']);
            }

            // Publish the draft version
            $version->update([
                'status'       => 'published',
                'published_at' => now(),
            ]);

            // Mark the flow as published
            $flow->update([
                'status'                       => 'published',
                'current_published_version_id' => $version->id,
                'published_at'                 => now(),
                'is_active'                    => true,
            ]);
        });

        return response()->json([
            'message' => 'Flow published successfully.',
            'flow'    => $flow->fresh(['currentPublishedVersion']),
        ]);
    }
    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/builder/versions
    // =========================================================================

    public function getVersions(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $versions = $flow->versions()
            ->with('creator:id,name')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn($v) => [
                'id'             => $v->id,
                'version_number' => $v->version_number,
                'status'         => $v->status,
                'published_at'   => $v->published_at?->toIso8601String(),
                'created_at'     => $v->created_at->toIso8601String(),
                'created_by'     => $v->creator?->only('id', 'name'),
            ]);

        return response()->json(['versions' => $versions]);
    }

    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/builder/versions/{version}
    // =========================================================================

    public function getVersion(Bot $bot, Flow $flow, int $versionId): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $version = $flow->versions()->with(['dialogs.options', 'dialogs.actions'])->findOrFail($versionId);

        return response()->json([
            'version' => [
                'id'             => $version->id,
                'version_number' => $version->version_number,
                'status'         => $version->status,
                'start_node_id'  => $version->start_node_id,
                'created_at'     => $version->created_at->toIso8601String(),
            ],
            'dialogs' => $version->dialogs,
        ]);
    }

    // =========================================================================
    // POST /api/bots/{bot}/flows/{flow}/builder/versions
    // Branch a new draft from an existing version.
    // =========================================================================

    public function createVersion(Request $request, Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $validated = $request->validate([
            'source_version_id' => 'required|integer|exists:flow_versions,id',
            'changelog'         => 'nullable',
        ]);

        $source          = $flow->versions()->findOrFail($validated['source_version_id']);
        $newVersionNumber = $flow->versions()->max('version_number') + 1;
        $newVersion       = null;

        DB::transaction(function () use (&$newVersion, $flow, $source, $newVersionNumber, $validated) {
            $newVersion = FlowVersion::create([
                'flow_id'        => $flow->id,
                'version_number' => $newVersionNumber,
                'status'         => 'draft',
                'created_by'     => auth()->id(),
                'changelog'      => $validated['changelog'] ?? null,
            ]);

            $dialogIdMap = [];

            foreach ($source->dialogs()->with(['options', 'actions'])->get() as $oldDialog) {
                $newDialog = $newVersion->dialogs()->create([
                    'uuid'           => (string) Str::uuid(),
                    'label'          => $oldDialog->label,
                    'kind'           => $oldDialog->kind,
                    'config'         => $oldDialog->config,
                    'position_x'     => $oldDialog->position_x,
                    'position_y'     => $oldDialog->position_y,
                    'is_entry_point' => $oldDialog->is_entry_point,
                    'is_terminal'    => $oldDialog->is_terminal,
                    'input_variable' => $oldDialog->input_variable,
                ]);

                $dialogIdMap[$oldDialog->id] = $newDialog->id;

                // Clone options
                foreach ($oldDialog->options as $opt) {
                    $newDialog->options()->create($opt->only([
                        'external_id',
                        'title',
                        'description',
                        'section_title',
                        'section_order',
                        'option_order',
                        'save_response',
                    ]));
                }

                // Clone actions
                foreach ($oldDialog->actions as $act) {
                    $newDialog->actions()->create($act->only([
                        'action_type',
                        'action_order',
                        'config',
                        'is_active',
                    ]));
                }
            }

            if ($source->start_node_id && isset($dialogIdMap[$source->start_node_id])) {
                $newVersion->update(['start_node_id' => $dialogIdMap[$source->start_node_id]]);
            }
        });

        activity()->causedBy(auth()->user())->performedOn($flow)
            ->log("Version {$newVersionNumber} created from version {$source->version_number}");

        return response()->json(['message' => 'Version created.', 'version' => $newVersion], 201);
    }

    // =========================================================================
    // GET /api/bots/{bot}/flows/{flow}/builder/variables
    // Returns bot-scoped custom variables + system variables
    // =========================================================================

    public function getVariables(Bot $bot, Flow $flow): JsonResponse
    {
        $this->authorizeFlow($bot, $flow);

        $custom = CustomVariable::where('bot_id', $bot->id)
            ->orderBy('name')
            ->get()
            ->map(fn($v) => array_merge($v->toArray(), ['is_system' => false]));

        $system = collect([
            ['id' => null, 'name' => 'phone_number', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'user_name',    'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_date', 'data_type' => 'date',   'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_time', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
        ]);

        return response()->json([
            'variables' => $custom->concat($system)->values(),
        ]);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function authorizeFlow(Bot $bot, Flow $flow): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) abort(404, 'Bot not found.');
        if ($flow->bot_id !== $bot->id) abort(404, 'Flow not found.');
    }

    private function createInitialVersion(Flow $flow): FlowVersion
    {
        $version = FlowVersion::create([
            'flow_id'        => $flow->id,
            'version_number' => 1,
            'status'         => 'draft',
            'created_by'     => auth()->id(),
        ]);

        $entry = $version->dialogs()->create([
            'uuid'           => (string) Str::uuid(),
            'label'          => 'Start',
            'kind'           => 'trigger',
            'is_entry_point' => true,
            'is_terminal'    => false,
            'position_x'     => 100,
            'position_y'     => 100,
            'config'         => ['id' => 'trigger-' . Str::uuid(), 'isFirstNode' => true, 'isLastNode' => false],
        ]);

        $version->update(['start_node_id' => $entry->id]);

        return $version->fresh();
    }

    private function syncOptions(Dialog $dialog, array $options): void
    {
        $keepIds = [];

        foreach ($options as $index => $opt) {
            $existing = $dialog->options()
                ->where('external_id', $opt['external_id'] ?? null)
                ->first();

            $data = [
                'external_id'    => $opt['external_id'] ?? null,
                'title'          => $opt['title'] ?? '',
                'description'    => $opt['description'] ?? null,
                'section_title'  => $opt['section_title'] ?? null,
                'section_order'  => $opt['section_order'] ?? 0,
                'option_order'   => $opt['option_order'] ?? $index,
                'save_response'  => $opt['save_response'] ?? false,
            ];

            if ($existing) {
                $existing->update($data);
                $keepIds[] = $existing->id;
            } else {
                $created   = $dialog->options()->create($data);
                $keepIds[] = $created->id;
            }
        }

        // Remove options no longer in the payload
        $dialog->options()->whereNotIn('id', $keepIds)->delete();
    }

    private function syncActions(Dialog $dialog, array $actions): void
    {
        $keepIds = [];

        foreach ($actions as $index => $act) {
            $existing = $dialog->actions()
                ->where('action_order', $index)
                ->first();

            $data = [
                'action_type'  => $act['action_type'],
                'action_order' => $index,
                'config'       => $act['config'] ?? [],
                'is_active'    => $act['is_active'] ?? true,
            ];

            if ($existing) {
                $existing->update($data);
                $keepIds[] = $existing->id;
            } else {
                $created   = $dialog->actions()->create($data);
                $keepIds[] = $created->id;
            }
        }

        $dialog->actions()->whereNotIn('id', $keepIds)->delete();
    }
}
