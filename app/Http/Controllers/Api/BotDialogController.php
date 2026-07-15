<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotDialog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BotDialogController extends Controller
{
    public function index(Bot $bot): JsonResponse
    {
        $dialogs = BotDialog::where('bot_id', $bot->id)
            ->orderBy('name')
            ->get()
            ->map(fn (BotDialog $d) => $this->toResource($d));

        return response()->json(['dialogs' => $dialogs]);
    }

    public function store(Request $request, Bot $bot): JsonResponse
    {
        $validated = $this->validateDialog($request, $bot);

        $dialog = BotDialog::create(array_merge($validated, ['bot_id' => $bot->id]));

        return response()->json([
            'message' => 'Dialog created.',
            'dialog' => $this->toResource($dialog),
        ], 201);
    }

    public function update(Request $request, Bot $bot, BotDialog $dialog): JsonResponse
    {
        abort_unless($dialog->bot_id === $bot->id, 404);

        $validated = $this->validateDialog($request, $bot, $dialog);
        $dialog->update($validated);

        return response()->json([
            'message' => 'Dialog updated.',
            'dialog' => $this->toResource($dialog->fresh()),
        ]);
    }

    public function destroy(Bot $bot, BotDialog $dialog): JsonResponse
    {
        abort_unless($dialog->bot_id === $bot->id, 404);

        $dialog->delete();

        return response()->json(['message' => 'Dialog deleted.']);
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    private function validateDialog(Request $request, Bot $bot, ?BotDialog $existing = null): array
    {
        $validated = $request->validate([
            // Purpose must be one of the reserved constants — the UI only
            // ever sends reserved purposes, but we enforce it server-side too.
            'purpose' => [
                'required',
                'string',
                Rule::in(BotDialog::RESERVED_PURPOSES),
                // One dialog per bot per purpose
                Rule::unique('bot_dialogs', 'purpose')
                    ->where('bot_id', $bot->id)
                    ->ignore($existing?->id),
            ],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'kind' => ['required', Rule::in([BotDialog::KIND_MESSAGE, BotDialog::KIND_BUTTONS, BotDialog::KIND_LIST])],
            'is_active' => 'boolean',

            'config' => 'required|array',
            // Message-only dialogs allow up to 4096 chars;
            // button/list dialogs cap the body at 1024 (WhatsApp interactive body limit).
            'config.text' => [
                'nullable',
                'string',
                function ($attribute, $value, $fail) use ($request) {
                    $kind = $request->input('kind');
                    $limit = $kind === BotDialog::KIND_MESSAGE ? 4096 : 1024;
                    if (strlen((string) $value) > $limit) {
                        $fail("Message text may not exceed {$limit} characters for a {$kind} dialog.");
                    }
                },
            ],

            'config.buttons' => 'required_if:kind,buttons|array|max:3',
            'config.buttons.*.id' => 'required_with:config.buttons|string',
            'config.buttons.*.label' => 'required_with:config.buttons|string|max:20',
            'config.buttons.*.kind' => [
                'required_with:config.buttons',
                Rule::in(['start_flow', 'go_home', 'go_back', 'talk_to_agent']),
            ],

            'config.sections' => 'required_if:kind,list|array',
            'config.sections.*.title' => 'nullable|string|max:24',
            'config.sections.*.rows' => 'required_with:config.sections|array',
            'config.sections.*.rows.*.id' => 'required_with:config.sections.*.rows|string',
            'config.sections.*.rows.*.label' => 'required_with:config.sections.*.rows|string|max:24',
            'config.sections.*.rows.*.kind' => [
                'required_with:config.sections.*.rows',
                Rule::in(['start_flow', 'go_home', 'go_back', 'talk_to_agent']),
            ],
        ]);

        // Button IDs within a single dialog must be unique
        if (($validated['kind'] === BotDialog::KIND_BUTTONS) && !empty($validated['config']['buttons'])) {
            $ids = array_column($validated['config']['buttons'], 'id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['config.buttons' => ['Button IDs must be unique.']]);
            }
        }

        return $validated;
    }

    private function toResource(BotDialog $dialog): array
    {
        return [
            'id' => $dialog->id,
            'bot_id' => $dialog->bot_id,
            'purpose' => $dialog->purpose,
            'name' => $dialog->name,
            'description' => $dialog->description,
            'kind' => $dialog->kind,
            'is_active' => $dialog->is_active,
            'config' => $dialog->config,
        ];
    }
}