<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotDialog;
use App\Models\BuiltInFunction;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BotDialogController extends Controller
{
    public function index(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $dialogs = BotDialog::where('bot_id', $bot->id)
            ->orderByRaw("FIELD(purpose, '" . implode("','", array_keys(BotDialog::ALL_PURPOSES)) . "')")
            ->get();

        $system = collect([
            ['id' => null, 'name' => 'phone_number', 'key' => 'phoneNumber', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'user_name', 'key' => 'userName',    'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_date', 'key' => 'currentDate', 'data_type' => 'date',   'is_sensitive' => false, 'is_system' => true],
            ['id' => null, 'name' => 'current_time', 'key' => 'currentTime', 'data_type' => 'string', 'is_sensitive' => false, 'is_system' => true],
        ]);
        $builtIn = BuiltInFunction::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($f) {
                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'slug' => $f->name,
                    'description' => $f->description,
                    'parameters' => $f->parameters,
                    'return_type' => $f->return_type,
                    'category' => $f->category,
                    'syntax' => $f->syntax,
                    'examples' => $f->examples,
                    'function_type' => 'built_in',
                    'is_system' => true,
                ];
            });
        return response()->json([
            'data'     => $dialogs,
            'purposes' => BotDialog::ALL_PURPOSES,
            'variables'   => $system,
            'functions'  => $builtIn,
        ]);
    }

    // ── POST /api/bots/{bot}/bot-dialogs ─────────────────────────────────────
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'purpose'     => ['required', 'string', 'in:' . implode(',', array_keys(BotDialog::ALL_PURPOSES))],
            'title'        => 'required|string|max:120',
            'description' => 'nullable|string|max:500',
            'kind'        => 'required|string|in:message,buttons,list,media,end',
            'config'      => 'nullable|array',
            'is_active'   => 'boolean',
            'is_entry_point'   => 'boolean',
        ]);

        $dialog = BotDialog::updateOrCreate(
            ['bot_id' => $bot->id, 'purpose' => $validated['purpose'], 'name' => $validated['title']],
            array_merge($validated, ['tenant_id' => $bot->tenant_id])
        );

        return response()->json(['data' => $dialog], $dialog->wasRecentlyCreated ? 201 : 200);
    }

    // ── GET /api/bots/{bot}/bot-dialogs/{botDialog} ───────────────────────────
    public function show(Bot $bot, BotDialog $botDialog): JsonResponse
    {
        $this->authorizeDialog($bot, $botDialog);
        return response()->json(['data' => $botDialog]);
    }

    // ── PUT /api/bots/{bot}/bot-dialogs/{botDialog} ───────────────────────────
    public function update(Request $request, Bot $bot, BotDialog $botDialog): JsonResponse
    {
        $this->authorizeDialog($bot, $botDialog);

        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:120',
            'description' => 'nullable|string|max:500',
            'kind'        => 'sometimes|required|string|in:message,buttons,list,media,end',
            'config'      => 'nullable|array',
            'is_active'   => 'boolean',
            'is_entry_point'   => 'boolean',
        ]);

        $botDialog->update($validated);

        return response()->json(['data' => $botDialog->fresh()]);
    }

    // ── DELETE /api/bots/{bot}/bot-dialogs/{botDialog} ────────────────────────
    public function destroy(Bot $bot, BotDialog $botDialog): JsonResponse
    {
        $this->authorizeDialog($bot, $botDialog);
        $botDialog->delete();
        return response()->json(null, 204);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) {
            abort(404, 'Bot not found.');
        }
    }

    private function authorizeDialog(Bot $bot, BotDialog $botDialog): void
    {
        $this->authorizeBot($bot);
        if ($botDialog->bot_id !== $bot->id) {
            abort(404, 'Dialog not found.');
        }
    }
}