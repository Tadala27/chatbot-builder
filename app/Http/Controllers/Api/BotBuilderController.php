<?php

// app/Http/Controllers/Api/BotBuilderController.php
//
// Only autoSave() changed — everything else in the file stays exactly as
// it was. Full file shown for drop-in replacement convenience.

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotVersion;
use App\Models\BuiltInFunction;
use App\Models\CustomFunction;
use App\Models\CustomVariable;
use App\Models\Dialog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BotBuilderController extends Controller
{
    // GET /api/bots/{bot}/builder
    public function show(Bot $bot): JsonResponse
    {
        $version = $bot->draftVersion() ?? $bot->publishedVersion();

        if (!$version) {
            $version = $this->createInitialVersion($bot);
        }

        $dialogs = $version->dialogs()->with(['options', 'actions'])->get();

        return response()->json([
            'bot' => $bot,
            'version' => $version,
            'dialogs' => $dialogs,
        ]);
    }

    // POST /api/bots/{bot}/builder/save
    //
    // FIXED: previously returned a 422 ("Create a new version first.") if
    // no draft existed at all — forcing the frontend to separately call
    // createVersion() before the very first auto-save could ever succeed.
    // Now mirrors exactly what show() already does in this situation:
    //   - no draft, no published/locked version at all → create the
    //     initial version (same helper show() uses)
    //   - no draft, but a published/locked version exists → branch a new
    //     draft FROM that version (same pattern as createVersion(), so the
    //     new draft isn't empty — it starts from whatever was last live)
    // Either way, autoSave() now always has a draft to write into instead
    // of ever refusing to save.
    public function autoSave(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'dialogs' => 'required|array',
            'dialogs.*.dialog_id' => 'required|string',
            'dialogs.*.kind' => 'required|string',
            'dialogs.*.label' => 'nullable|string',
            'dialogs.*.position_x' => 'required|numeric',
            'dialogs.*.position_y' => 'required|numeric',
            'dialogs.*.config' => 'required|array',
            'dialogs.*.is_entry_point' => 'sometimes|boolean',
            'dialogs.*.is_terminal' => 'sometimes|boolean',
            'dialogs.*.options' => 'sometimes|array',
            'dialogs.*.actions' => 'sometimes|array',
        ]);

        $version = $bot->draftVersion();

        if (!$version) {
            $version = $this->ensureDraftVersion($bot);
        }

        if ($version->status !== 'draft') {
            return response()->json(['message' => 'Cannot modify a published version.'], 422);
        }

        DB::transaction(function () use ($validated, $version) {
            $existing = $version->dialogs()
                ->with(['options', 'actions'])
                ->get()
                ->keyBy(fn ($d) => $d->config['id'] ?? $d->uuid);

            $incomingIds = collect($validated['dialogs'])->pluck('dialog_id')->all();

            foreach ($existing as $frontendId => $dbDialog) {
                if (!in_array($frontendId, $incomingIds, true)) {
                    $dbDialog->actions()->delete();
                    $dbDialog->options()->delete();
                    $dbDialog->delete();
                }
            }

            $dialogIdMap = [];

            foreach ($validated['dialogs'] as $data) {
                $config = $data['config'];
                $config['id'] = $data['dialog_id'];

                $attributes = [
                    'kind' => $data['kind'],
                    'label' => $data['label'] ?? $data['kind'],
                    'config' => $config,
                    'position_x' => $data['position_x'],
                    'position_y' => $data['position_y'],
                    'is_entry_point' => $data['is_entry_point'] ?? ($config['isFirstNode'] ?? false),
                    'is_terminal' => $data['is_terminal'] ?? ($config['isLastNode'] ?? false),
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
                $this->syncOptions($dbDialog, $data['options'] ?? []);
                $this->syncActions($dbDialog, $data['actions'] ?? []);
            }

            $entryData = collect($validated['dialogs'])
                ->first(fn ($d) => ($d['is_entry_point'] ?? false) || ($d['config']['isFirstNode'] ?? false));

            $version->touch();
        });

        return response()->json(['message' => 'Auto-saved.', 'saved_at' => now()->toIso8601String()]);
    }

    // POST /api/bots/{bot}/builder/publish
    public function publish(Bot $bot): JsonResponse
    {
        $version = $bot->draftVersion();

        if (!$version) {
            return response()->json(['message' => 'No draft version to publish.'], 422);
        }

        if (!$version->dialogs()->where('is_entry_point', true)->exists()) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => ['Bot must have at least one entry-point dialog.'],
            ], 422);
        }

        DB::transaction(function () use ($bot, $version) {
            BotVersion::where('bot_id', $bot->id)
                ->where('id', '!=', $version->id)
                ->where('status', 'published')
                ->update(['status' => 'locked']);

            $version->update([
                'status' => 'published',
                'published_at' => now(),
            ]);
            $bot->update([
                'current_published_version_id' => $version->id,
                'published_at' => now(),
            ]);
        });

        return response()->json([
            'message' => 'Bot published successfully.',
            'bot' => $bot->fresh(['currentPublishedVersion']),
        ]);
    }

    // GET /api/bots/{bot}/builder/versions
    public function getVersions(Bot $bot): JsonResponse
    {
        $versions = $bot->versions()
            ->with('creator:id,name')
            ->orderByDesc('version_number')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status,
                'published_at' => $v->published_at?->toIso8601String(),
                'created_at' => $v->created_at->toIso8601String(),
                'created_by' => $v->creator?->only('id', 'name'),
                'changelog' => $v->changelog,
            ]);

        return response()->json(['versions' => $versions]);
    }

    // GET /api/bots/{bot}/builder/versions/{versionId}
    public function getVersion(Bot $bot, string $versionId): JsonResponse
    {
        $version = $bot->versions()
            ->with(['dialogs.options', 'dialogs.actions'])
            ->findOrFail($versionId);

        return response()->json([
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'created_at' => $version->created_at->toIso8601String(),
            ],
            'dialogs' => $version->dialogs,
            'bot' => $bot,
        ]);
    }

    // POST /api/bots/{bot}/builder/versions
    public function createVersion(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'source_version_id' => 'required|string|exists:bot_versions,id',
            'changelog' => 'nullable|boolean',
        ]);

        $source = $bot->versions()->findOrFail($validated['source_version_id']);
        $newVersion = $this->branchVersionFrom($bot, $source, $validated['changelog'] ?? null);

        activity()->causedBy(auth()->user())->performedOn($bot)
            ->log("Version {$newVersion->version_number} created from v{$source->version_number}");

        return response()->json(['message' => 'Version created.', 'version' => $newVersion], 201);
    }

    // GET /api/bots/{bot}/builder/variables
    public function getVariables(Bot $bot): JsonResponse
    {
        $custom = CustomVariable::where('bot_id', $bot->id)
            ->orderBy('name')->get()
            ->map(fn ($v) => array_merge($v->toArray(), ['is_system' => false]));

        $system = collect([
            ['id' => null, 'name' => 'phone_number', 'key' => 'phoneNumber', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'user_name', 'key' => 'userName', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_date', 'key' => 'currentDate', 'data_type' => 'date', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_time', 'key' => 'currentTime', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
        ]);

        return response()->json(['variables' => $custom->concat($system)->values()]);
    }

    // GET /api/bots/{bot}/builder/functions
    public function getFunctions(Bot $bot): JsonResponse
    {
        $custom = CustomFunction::where('bot_id', $bot->id)->orderBy('name')->get()
            ->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'slug' => $f->slug,
                'description' => $f->description, 'function_type' => $f->function_type,
                'source' => 'custom',
            ]);

        $builtIn = BuiltInFunction::where('is_active', true)->orderBy('name')->get()
            ->map(fn ($f) => [
                'id' => $f->id, 'name' => $f->name, 'slug' => $f->name,
                'description' => $f->description, 'function_type' => 'built_in',
                'source' => 'built_in', 'category' => $f->category ?? null,
                'syntax' => $f->syntax ?? null, 'examples' => $f->examples ?? null,
            ]);

        return response()->json(['functions' => $custom->concat($builtIn)->values()]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function createInitialVersion(Bot $bot): BotVersion
    {
        $version = BotVersion::create([
            'bot_id' => $bot->id,
            'version_number' => 1,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]);

        $entry = $version->dialogs()->create([
            'uuid' => (string) Str::uuid(),
            'label' => 'Start',
            'kind' => 'trigger',
            'is_entry_point' => true,
            'is_terminal' => false,
            'position_x' => 100,
            'position_y' => 100,
            'config' => ['id' => 'trigger-'.Str::uuid(), 'isFirstNode' => true, 'isLastNode' => false],
        ]);

        return $version->fresh();
    }

    /**
     * Used ONLY by autoSave() when no draft exists. Two cases:
     *
     *   1. Bot has NO versions at all (brand new bot, somehow never
     *      opened via show() first) → create the initial version, same
     *      as show() does.
     *
     *   2. Bot has a published/locked version but no draft (the common
     *      case: someone published, then came back later to make more
     *      changes) → branch a new draft FROM the most recent
     *      published/locked version, so the first auto-save after
     *      publishing doesn't silently start from an empty canvas. This
     *      reuses the exact same branching logic as createVersion()/
     *      duplicate-version flows, just triggered automatically instead
     *      of requiring an explicit "create new version" click first.
     */
    private function ensureDraftVersion(Bot $bot): BotVersion
    {
        $latest = $bot->versions()
            ->whereIn('status', ['published', 'locked'])
            ->orderByDesc('version_number')
            ->first();

        if (!$latest) {
            return $this->createInitialVersion($bot);
        }

        return $this->branchVersionFrom($bot, $latest, 'Auto-created draft for unsaved changes');
    }

    /**
     * Shared branching logic — extracted from createVersion() so
     * ensureDraftVersion() can reuse it without duplicating the
     * dialog/option/action copy loop.
     */
    private function branchVersionFrom(Bot $bot, BotVersion $source, ?string $changelog): BotVersion
    {
        $newVersionNumber = $bot->versions()->max('version_number') + 1;
        $newVersion = null;

        DB::transaction(function () use (&$newVersion, $bot, $source, $newVersionNumber, $changelog) {
            $newVersion = BotVersion::create([
                'bot_id' => $bot->id,
                'version_number' => $newVersionNumber,
                'status' => 'draft',
                'created_by' => auth()->id(),
                'changelog' => $changelog,
            ]);

            $dialogIdMap = [];

            foreach ($source->dialogs()->with(['options', 'actions'])->get() as $oldDialog) {
                $newDialog = $newVersion->dialogs()->create([
                    'uuid' => (string) Str::uuid(),
                    'label' => $oldDialog->label,
                    'kind' => $oldDialog->kind,
                    'config' => $oldDialog->config,
                    'position_x' => $oldDialog->position_x,
                    'position_y' => $oldDialog->position_y,
                    'is_entry_point' => $oldDialog->is_entry_point,
                    'is_terminal' => $oldDialog->is_terminal,
                    'input_variable' => $oldDialog->input_variable,
                ]);

                $dialogIdMap[$oldDialog->id] = $newDialog->id;

                foreach ($oldDialog->options as $opt) {
                    $newDialog->options()->create($opt->only([
                        'external_id', 'title', 'description',
                        'section_title', 'section_order', 'option_order', 'save_response',
                    ]));
                }

                foreach ($oldDialog->actions as $act) {
                    $newDialog->actions()->create($act->only([
                        'action_type', 'action_order', 'config', 'is_active',
                    ]));
                }
            }
        });

        return $newVersion;
    }

    private function syncOptions(Dialog $dialog, array $options): void
    {
        $keepIds = [];
        foreach ($options as $index => $opt) {
            $existing = $dialog->options()->where('external_id', $opt['external_id'] ?? null)->first();
            $data = [
                'external_id' => $opt['external_id'] ?? null,
                'title' => $opt['title'] ?? '',
                'description' => $opt['description'] ?? null,
                'section_title' => $opt['section_title'] ?? null,
                'section_order' => $opt['section_order'] ?? 0,
                'option_order' => $opt['option_order'] ?? $index,
                'save_response' => $opt['save_response'] ?? false,
            ];
            if ($existing) {
                $existing->update($data);
                $keepIds[] = $existing->id;
            } else {
                $keepIds[] = $dialog->options()->create($data)->id;
            }
        }
        $dialog->options()->whereNotIn('id', $keepIds)->delete();
    }

    private function syncActions(Dialog $dialog, array $actions): void
    {
        $keepIds = [];
        $createdMap = [];
        foreach ($actions as $index => $act) {
            $actionType = $act['action_type'] ?? $act['kind'] ?? 'navigation';
            $storedConfig = collect($act['config'] ?? $act)
                ->except(['_resolvedInput', '_db_conditions', 'then'])->toArray();
            $existing = $dialog->actions()->where('action_order', $index)->first();
            $data = [
                'action_type' => $actionType,
                'action_order' => $index,
                'config' => $storedConfig,
                'is_active' => $act['is_active'] ?? true,
                'then_action_id' => null,
            ];
            $action = $existing ? tap($existing, fn ($a) => $a->update($data)) : $dialog->actions()->create($data);
            $keepIds[] = $action->id;
            $createdMap[$index] = $action;
        }

        $orders = array_keys($createdMap);
        sort($orders);
        foreach ($orders as $i => $order) {
            $nextOrder = $orders[$i + 1] ?? null;
            $createdMap[$order]->update(['then_action_id' => $nextOrder !== null ? $createdMap[$nextOrder]->id : null]);
        }

        $dialog->actions()->whereNotIn('id', $keepIds)->delete();
    }
}