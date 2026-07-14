<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotDialog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BotDialogController extends Controller
{
    /**
     * List config-level dialogs for a bot, shaped for the settings page's
     * dialog pickers. Kept separate from BotConfigurationController::show()
     * since this is a resource list, not part of the configuration record
     * itself.
     */
    public function index(Bot $bot): JsonResponse
    {
        $dialogs = BotDialog::where('bot_id', $bot->id)
            ->orderBy('name')
            ->get()
            ->map(fn (BotDialog $dialog) => $this->toOption($dialog));

        return response()->json(['dialogs' => $dialogs]);
    }

    public function store(Request $request, Bot $bot): JsonResponse
    {
        $validated = $this->validateDialog($request, $bot);

        $dialog = BotDialog::create([
            'id' => (string) Str::uuid(),
            'bot_id' => $bot->id,
            ...$validated,
        ]);

        return response()->json([
            'message' => 'Dialog created.',
            'dialog' => $this->toOption($dialog),
        ], 201);
    }

    public function update(Request $request, Bot $bot, BotDialog $dialog): JsonResponse
    {
        $this->authorizeDialogBelongsToBot($bot, $dialog);

        $validated = $this->validateDialog($request, $bot, $dialog);
        $dialog->update($validated);

        return response()->json([
            'message' => 'Dialog updated.',
            'dialog' => $this->toOption($dialog),
        ]);
    }

    public function destroy(Bot $bot, BotDialog $dialog): JsonResponse
    {
        $this->authorizeDialogBelongsToBot($bot, $dialog);

        if ($this->isReferencedByConfiguration($bot, $dialog)) {
            return response()->json([
                'message' => 'This dialog is currently selected in bot settings. Choose a different dialog there first, then delete this one.',
            ], 422);
        }

        $dialog->delete();

        return response()->json(['message' => 'Dialog deleted.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function validateDialog(Request $request, Bot $bot, ?BotDialog $dialog = null): array
    {
        $validated = $request->validate([
            'purpose' => [
                'required', 'string', 'max:100',
                Rule::unique('bot_dialogs', 'purpose')
                    ->where('bot_id', $bot->id)
                    ->ignore($dialog?->id),
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'kind' => ['required', Rule::in(BotDialog::KINDS)],
            'is_entry_point' => 'boolean',
            'is_active' => 'boolean',

            'config' => 'required|array',
            'config.text' => 'nullable|string|max:4096',

            'config.buttons' => 'required_if:kind,buttons|array|max:3',
            'config.buttons.*.id' => 'required_with:config.buttons|string',
            'config.buttons.*.label' => 'required_with:config.buttons|string|max:20',
            'config.buttons.*.kind' => [
                'required_with:config.buttons',
                Rule::in(BotDialog::SYSTEM_ACTIONS),
            ],

            'config.sections' => 'required_if:kind,list|array',
            'config.sections.*.title' => 'nullable|string|max:24',
            'config.sections.*.rows' => 'required_with:config.sections|array',
            'config.sections.*.rows.*.id' => 'required_with:config.sections.*.rows|string',
            'config.sections.*.rows.*.label' => 'required_with:config.sections.*.rows|string|max:24',
            'config.sections.*.rows.*.kind' => [
                'required_with:config.sections.*.rows',
                Rule::in(BotDialog::SYSTEM_ACTIONS),
            ],
        ]);

        if ($validated['kind'] === BotDialog::KIND_BUTTONS) {
            $ids = array_column($validated['config']['buttons'], 'id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['config.buttons' => ['Button IDs must be unique.']]);
            }
        }

        return $validated;
    }

    private function toOption(BotDialog $dialog): array
    {
        return [
            'id' => $dialog->id,
            'label' => $dialog->name,
            'kind' => $dialog->kind,
            'is_entry_point' => $dialog->is_entry_point,
            'display' => $dialog->name.' ('.ucfirst($dialog->kind).')',
            'config' => $dialog->config,
            'purpose' => $dialog->purpose,
        ];
    }

    private function authorizeDialogBelongsToBot(Bot $bot, BotDialog $dialog): void
    {
        abort_unless($dialog->bot_id === $bot->id, 404);
    }

    /**
     * Guards against deleting a dialog that's actively selected somewhere in
     * BotConfiguration — otherwise the FK would need to be nulled silently,
     * which is more likely to surprise someone than a 422 asking them to
     * pick a different dialog first.
     */
    private function isReferencedByConfiguration(Bot $bot, BotDialog $dialog): bool
    {
        $config = $bot->configuration;

        if (!$config) {
            return false;
        }

        $referencingFields = [
            'starting_dialog_id',
            'welcome_dialog_id',
            'invalid_input_dialog_id',
            'invalid_attempts_dialog_id',
            'retry_dialog_id',
            'handover_dialog_id_in_hours',
            'handover_dialog_id_off_hours',
        ];

        foreach ($referencingFields as $field) {
            if ($config->{$field} === $dialog->id) {
                return true;
            }
        }

        return false;
    }
}
