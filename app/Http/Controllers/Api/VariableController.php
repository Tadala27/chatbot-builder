<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\CustomVariable;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Custom variables belong to Bots (not flows) in the new schema.
 * A bot's variables are shared across all its flows.
 */
class VariableController extends Controller
{
    // GET /api/bots/{bot}/variables
    public function index(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $variables = CustomVariable::where('bot_id', $bot->id)
            ->orderBy('name')
            ->get();

        return response()->json(['variables' => $variables]);
    }

    // POST /api/bots/{bot}/variables
    public function store(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'key'           => 'required|string|max:100',
            'data_type'     => 'required|in:string,number,boolean,json,date',
            'default_value' => 'nullable|string',
            'description'   => 'nullable|string',
            'is_sensitive'  => 'boolean',
        ]);

        // Key must be unique within the bot
        if (CustomVariable::where('bot_id', $bot->id)->where('key', $validated['key'])->exists()) {
            return response()->json(['message' => "Variable key '{$validated['key']}' already exists on this bot."], 422);
        }

        $variable = CustomVariable::create(array_merge($validated, ['bot_id' => $bot->id]));

        return response()->json(['message' => 'Variable created.', 'variable' => $variable], 201);
    }

    // PUT /api/bots/{bot}/variables/{variable}
    public function update(Request $request, Bot $bot, CustomVariable $variable): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeVariable($bot, $variable);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:255',
            'data_type'     => 'sometimes|in:string,number,boolean,json,date',
            'default_value' => 'nullable|string',
            'description'   => 'nullable|string',
            'is_sensitive'  => 'sometimes|boolean',
        ]);

        // Key is immutable after creation to avoid breaking conversation data

        $variable->update($validated);

        return response()->json(['message' => 'Variable updated.', 'variable' => $variable]);
    }

    // DELETE /api/bots/{bot}/variables/{variable}
    public function destroy(Bot $bot, CustomVariable $variable): JsonResponse
    {
        $this->authorizeBot($bot);
        $this->authorizeVariable($bot, $variable);

        $variable->delete();

        return response()->json(['message' => 'Variable deleted.']);
    }

    // -------------------------------------------------------------------------

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) {
            abort(404, 'Bot not found.');
        }
    }

    private function authorizeVariable(Bot $bot, CustomVariable $variable): void
    {
        if ($variable->bot_id !== $bot->id) {
            abort(404, 'Variable not found.');
        }
    }
}
