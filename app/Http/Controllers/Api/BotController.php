<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Manages Bots — the top-level entity that owns flows, variables, functions
 * and API integrations. Each bot is tied to one WhatsApp account and belongs
 * to a tenant.
 */
class BotController extends Controller
{
    // GET /api/bots
    public function index(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $query = Bot::where('tenant_id', $tenant->id)
            ->with('whatsappAccount')
            ->withCount('flows');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        if ($request->filled('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'));

        $bots = $query->paginate($request->get('per_page', 20));

        return response()->json($bots);
    }

    // GET /api/bots/{bot}
    public function show(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $bot->load(['whatsappAccount', 'flows', 'customVariables', 'customFunctions', 'apis']);

        return response()->json([
            'bot'   => $bot,
            'stats' => [
                'total_flows'         => $bot->flows()->count(),
                'published_flows'     => $bot->flows()->where('status', 'published')->count(),
                'total_conversations' => \App\Models\Conversation::whereHas(
                    'flow', fn($q) => $q->where('bot_id', $bot->id)
                )->count(),
            ],
        ]);
    }

    // POST /api/bots
    public function store(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'name'                => 'required|string|max:255',
            'description'         => 'nullable|string',
            'welcome_message'     => 'nullable|string',
            'fallback_message'    => 'nullable|string',
            'default_language'    => 'nullable|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings'            => 'nullable|array',
        ]);

        // Verify the WhatsApp account belongs to this tenant
        $accountBelongs = $tenant->whatsappAccounts()->where('id', $validated['whatsapp_account_id'])->exists();
        if (!$accountBelongs) {
            return response()->json(['message' => 'WhatsApp account not found for this tenant.'], 422);
        }

        $bot = Bot::create(array_merge($validated, [
            'tenant_id' => $tenant->id,
            'user_id'   => auth()->id(),
        ]));

        activity()->causedBy(auth()->user())->performedOn($bot)->log('Bot created');

        return response()->json(['message' => 'Bot created.', 'bot' => $bot->load('whatsappAccount')], 201);
    }

    // PUT /api/bots/{bot}
    public function update(Request $request, Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        $validated = $request->validate([
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
            'name'                => 'sometimes|string|max:255',
            'description'         => 'nullable|string',
            'welcome_message'     => 'nullable|string',
            'fallback_message'    => 'nullable|string',
            'default_language'    => 'sometimes|string|max:10',
            'supported_languages' => 'nullable|array',
            'is_active'           => 'sometimes|boolean',
            'settings'            => 'nullable|array',
        ]);

        $bot->update($validated);

        activity()->causedBy(auth()->user())->performedOn($bot)->log('Bot updated');

        return response()->json(['message' => 'Bot updated.', 'bot' => $bot->load('whatsappAccount')]);
    }

    // DELETE /api/bots/{bot}
    public function destroy(Bot $bot): JsonResponse
    {
        $this->authorizeBot($bot);

        if (\App\Models\Conversation::whereHas('flow', fn($q) => $q->where('bot_id', $bot->id))
            ->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete bot with active conversations.',
            ], 422);
        }

        $name = $bot->name;
        $bot->delete();

        activity()->causedBy(auth()->user())->log("Bot deleted: {$name}");

        return response()->json(['message' => 'Bot deleted.']);
    }

    // -------------------------------------------------------------------------

    private function authorizeBot(Bot $bot): void
    {
        if ($bot->tenant_id !== Tenant::current()->id) {
            abort(404, 'Bot not found.');
        }
    }
}
