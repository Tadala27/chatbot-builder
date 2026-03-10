<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotConfiguration;
use App\Models\Dialog;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotConfigurationController extends Controller
{
    // GET /api/bots/{bot}/configuration
    public function show(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $config = BotConfiguration::with([
            'startingDialog',
            'invalidInputDialog',
            'retryDialog',
            'handoverDialogInHours',
            'handoverDialogOffHours',
        ])->firstOrNew(['bot_id' => $bot->id]);

        // Seed defaults when record doesn't exist yet
        if (!$config->exists) {
            $config->fill([
                'session_timeout_minutes' => 1440,
                'retry_enabled'           => false,
                'retry_after_minutes'     => 60,
                'max_retry_attempts'      => 1,
                'handover_enabled'        => false,
                'operating_hours'         => BotConfiguration::defaultOperatingHours(),
                'home_keywords'           => ['menu', 'home', 'start'],
                'back_keywords'           => ['back', '0'],
                'handover_keywords'       => ['agent', 'human', 'help'],
            ]);
        }

        return response()->json([
            'configuration' => $config,
            'exists'        => $config->exists,
        ]);
    }

    // PUT /api/bots/{bot}/configuration
    public function upsert(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            // Entry
            'starting_dialog_id'            => 'nullable|integer|exists:dialogs,id',

            // Fallback
            'invalid_input_dialog_id'       => 'nullable|integer|exists:dialogs,id',

            // Retry
            'retry_enabled'                 => 'boolean',
            'retry_dialog_id'               => 'nullable|integer|exists:dialogs,id',
            'retry_after_minutes'           => 'nullable|integer|min:1|max:10080',   // max 1 week
            'max_retry_attempts'            => 'nullable|integer|min:1|max:20',

            // Keywords — accept comma-separated string OR array
            'home_keywords'                 => 'nullable',
            'back_keywords'                 => 'nullable',
            'handover_keywords'             => 'nullable',

            // Handover
            'handover_enabled'              => 'boolean',
            'handover_dialog_id_in_hours'   => 'nullable|integer|exists:dialogs,id',
            'handover_dialog_id_off_hours'  => 'nullable|integer|exists:dialogs,id',

            // Session
            'session_timeout_minutes'       => 'nullable|integer|min:1|max:43200',   // max 30 days

            // Operating hours
            'operating_hours'               => 'nullable|array',
            'operating_hours.*'             => 'array',
            'operating_hours.*.enabled'     => 'boolean',
            'operating_hours.*.open'        => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'operating_hours.*.close'       => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'operating_hours.*.timezone'    => 'nullable|string|timezone',
        ]);

        // Normalise keyword fields: accept both arrays and comma-separated strings
        foreach (['home_keywords', 'back_keywords', 'handover_keywords'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = $this->normaliseKeywords($validated[$field]);
            }
        }

        // Validate that all dialog IDs belong to this bot
        $dialogFields = [
            'starting_dialog_id',
            'invalid_input_dialog_id',
            'retry_dialog_id',
            'handover_dialog_id_in_hours',
            'handover_dialog_id_off_hours',
        ];

        $botDialogIds = Dialog::whereHas('flowVersion.flow', fn($q) => $q->where('bot_id', $bot->id))
            ->pluck('id')
            ->toArray();

        foreach ($dialogFields as $field) {
            if (!empty($validated[$field]) && !in_array($validated[$field], $botDialogIds)) {
                return response()->json([
                    'message' => "Dialog ID for {$field} does not belong to this bot.",
                ], 422);
            }
        }

        $config = BotConfiguration::updateOrCreate(
            ['bot_id' => $bot->id],
            $validated
        );

        // Reload with relations for the response
        $config->load([
            'startingDialog',
            'invalidInputDialog',
            'retryDialog',
            'handoverDialogInHours',
            'handoverDialogOffHours',
        ]);

        return response()->json([
            'message'       => 'Bot configuration saved.',
            'configuration' => $config,
        ]);
    }

    // GET /api/bots/{bot}/configuration/dialogs
    // Returns all dialogs across published flow versions for the dialog picker.
    public function dialogs(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $dialogs = Dialog::whereHas('flowVersion', function ($q) use ($bot) {
            $q->where('status', 'published')
                ->whereHas('flow', fn($fq) => $fq->where('bot_id', $bot->id));
        })
            ->with(['flowVersion:id,flow_id,version_number', 'flowVersion.flow:id,name'])
            ->select(['id', 'flow_version_id', 'label', 'kind', 'is_entry_point'])
            ->orderBy('flow_version_id')
            ->orderBy('label')
            ->get()
            ->map(fn($d) => [
                'id'           => $d->id,
                'label'        => $d->label ?: ucfirst($d->kind),
                'kind'         => $d->kind,
                'is_entry_point' => $d->is_entry_point,
                'flow_name'    => $d->flowVersion->flow->name ?? 'Unknown Flow',
                'version'      => $d->flowVersion->version_number ?? 1,
                'display'      => sprintf(
                    '[%s v%s] %s',
                    $d->flowVersion->flow->name ?? 'Flow',
                    $d->flowVersion->version_number ?? 1,
                    $d->label ?: ucfirst($d->kind)
                ),
            ]);

        // Group by flow for the UI select
        $grouped = $dialogs->groupBy('flow_name')->map(fn($items, $flowName) => [
            'flow_name' => $flowName,
            'dialogs'   => $items->values(),
        ])->values();

        return response()->json([
            'dialogs' => $dialogs,
            'grouped' => $grouped,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function normaliseKeywords(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', $value)));
        }
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        return [];
    }

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) {
            abort(404, 'Bot not found.');
        }
    }
}
