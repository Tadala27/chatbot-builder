<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotConfiguration;
use App\Models\BotDialog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotConfigurationController extends Controller
{
    private const RELATIONS = [
        'startingDialog',
        'welcomeDialog',
        'invalidInputDialog',
        'invalidAttemptsDialog',
        'retryDialog',
        'handoverDialogInHours',
        'handoverDialogOffHours',
    ];

    public function show(Bot $bot): JsonResponse
    {
        $config = BotConfiguration::with(self::RELATIONS)->firstOrNew(['bot_id' => $bot->id]);

        if (!$config->exists) {
            $config->fill([
                'session_timeout_minutes' => 1440,
                'invalid_input_message' => null,
                'max_invalid_attempts' => 3,
                'retry_enabled' => false,
                'retry_after_minutes' => 60,
                'max_retry_attempts' => 1,
                'handover_enabled' => false,
                'handover_unavailable_message' => null,
                'auto_resolve_after_minutes' => null,
                'operating_hours' => BotConfiguration::defaultOperatingHours(),
                'home_keywords' => ['menu', 'home', 'start'],
                'back_keywords' => ['back', '0'],
                'handover_keywords' => ['agent', 'human', 'help'],
                'opt_out_keywords' => ['stop', 'unsubscribe'],
                'opt_in_keywords' => ['start', 'subscribe'],
            ]);
        }

        return response()->json([
            'configuration' => $config,
            'exists' => $config->exists,
        ]);
    }

    /**
     * Config-level dialogs available for the settings page's pickers
     * (starting dialog, invalid input dialog, handover dialogs, etc).
     *
     * NOTE: bot_dialogs has no `version` column — earlier frontend typing
     * assumed one that doesn't exist in the schema. Dropped from the
     * response here; see the matching change in botSettings.ts.
     */
    public function dialogs(Bot $bot): JsonResponse
    {
        $dialogs = BotDialog::where('bot_id', $bot->id)
            ->orderBy('name')
            ->get()
            ->map(fn (BotDialog $dialog) => [
                'id' => $dialog->id,
                'label' => $dialog->name,
                'kind' => $dialog->kind,
                'is_entry_point' => $dialog->is_entry_point,
                'display' => $dialog->name.' ('.ucfirst($dialog->kind).')',
                'config' => $dialog->config,
                'purpose' => $dialog->purpose,
            ]);

        return response()->json(['dialogs' => $dialogs]);
    }

    public function upsert(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            // FIX: bot_dialogs.id is a UUID, not an auto-increment integer.
            // These were previously validated as `integer`, which rejects
            // every real dialog ID and made saving a dialog selection
            // impossible.
            'starting_dialog_id' => 'nullable|string|exists:bot_dialogs,id',
            'welcome_dialog_id' => 'nullable|string|exists:bot_dialogs,id',
            'session_timeout_minutes' => 'nullable|integer|min:1|max:43200',

            'invalid_input_message' => 'nullable|string|max:1000',
            'invalid_input_dialog_id' => 'nullable|string|exists:bot_dialogs,id',
            'max_invalid_attempts' => 'nullable|integer|min:1|max:20',
            'invalid_attempts_dialog_id' => 'nullable|string|exists:bot_dialogs,id',

            'retry_enabled' => 'boolean',
            'retry_dialog_id' => 'nullable|string|exists:bot_dialogs,id',
            'retry_after_minutes' => 'nullable|integer|min:1|max:10080',
            'max_retry_attempts' => 'nullable|integer|min:1|max:20',

            'home_keywords' => 'nullable',
            'back_keywords' => 'nullable',
            'handover_keywords' => 'nullable',
            'opt_out_keywords' => 'nullable',
            'opt_in_keywords' => 'nullable',

            'handover_enabled' => 'boolean',
            'handover_dialog_id_in_hours' => 'nullable|string|exists:bot_dialogs,id',
            'handover_dialog_id_off_hours' => 'nullable|string|exists:bot_dialogs,id',
            'handover_unavailable_message' => 'nullable|string|max:1000',
            'auto_resolve_after_minutes' => 'nullable|integer|min:1|max:43200',

            'operating_hours' => 'nullable|array',
            'operating_hours.*' => 'array',
            'operating_hours.*.enabled' => 'boolean',
            'operating_hours.*.open' => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'operating_hours.*.close' => 'nullable|string|regex:/^\d{2}:\d{2}$/',
            'operating_hours.*.timezone' => 'nullable|string|timezone',
        ]);

        foreach (['home_keywords', 'back_keywords', 'handover_keywords', 'opt_out_keywords', 'opt_in_keywords'] as $field) {
            if (isset($validated[$field])) {
                $validated[$field] = $this->normaliseKeywords($validated[$field]);
            }
        }

        $dialogFields = [
            'starting_dialog_id',
            'welcome_dialog_id',
            'invalid_input_dialog_id',
            'invalid_attempts_dialog_id',
            'retry_dialog_id',
            // 'handover_dialog_id_in_hours',
            'handover_dialog_id_off_hours',
        ];

        $botDialogIds = BotDialog::where('bot_id', $bot->id)->pluck('id')->toArray();

        foreach ($dialogFields as $field) {
            if (!empty($validated[$field]) && !in_array($validated[$field], $botDialogIds, true)) {
                return response()->json([
                    'message' => "Dialog ID for {$field} does not belong to this bot.",
                ], 422);
            }
        }

        $config = BotConfiguration::updateOrCreate(
            ['bot_id' => $bot->id],
            $validated
        );

        $config->load(self::RELATIONS);

        return response()->json([
            'message' => 'Bot configuration saved.',
            'configuration' => $config,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
}