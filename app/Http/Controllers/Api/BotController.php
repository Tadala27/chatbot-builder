<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BotController extends Controller
{
    private const MAX_ACTIVE_BOTS = 2;

    public function index(Request $request): JsonResponse
    {
        $query = Bot::with(['whatsappAccount', 'user']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('description', 'like', "%{$s}%"));
        }

        if ($request->filled('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $query->orderBy($request->get('sort', 'created_at'), $request->get('direction', 'desc'));

        $bots = $query->paginate($request->get('per_page', 20));

        $bots->getCollection()->transform(function ($bot) {
            $bot->stats = [
                'total_versions' => $bot->versions()->count(),
                'published_version' => $bot->versions()->where('status', 'published')->value('version_number'),
                'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
                'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
                'active_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'active')->count(),
            ];

            return $bot;
        });

        return response()->json($bots);
    }

    public function show(Bot $bot): JsonResponse
    {
        $bot->load(['whatsappAccount', 'user', 'currentPublishedVersion', 'customVariables', 'customFunctions', 'apis']);

        return response()->json([
            'bot' => $bot,
            'stats' => [
                'total_versions' => $bot->versions()->count(),
                'published_version' => $bot->versions()->where('status', 'published')->value('version_number'),
                'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
                'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
                'active_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'active')->count(),
                'media_files' => $bot->mediaFiles()->count(),
                'dialogs' => $bot->dialogs()->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'default_language' => 'nullable|string|max:10',
            'supported_languages' => 'nullable|array',
            'settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
        ]);

        $wantsActive = $validated['is_active'] ?? false;

        if ($wantsActive && Bot::where('is_active', true)->count() >= self::MAX_ACTIVE_BOTS) {
            return response()->json([
                'message' => 'You can have at most '.self::MAX_ACTIVE_BOTS.' active bots. Deactivate one before creating an active bot.',
            ], 422);
        }

        $bot = Bot::create([
            'user_id' => Auth::guard('tenant')->id(),
            'whatsapp_account_id' => $validated['whatsapp_account_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $wantsActive,
            'default_language' => $validated['default_language'] ?? 'en',
            'supported_languages' => $validated['supported_languages'] ?? null,
            'settings' => $validated['settings'] ?? null,
        ]);

        $bot->getConfigOrCreate();

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($bot)->log('Bot created');

        return response()->json([
            'message' => 'Bot created.',
            'bot' => $bot->load(['whatsappAccount', 'user']),
        ], 201);
    }

    public function update(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'default_language' => 'sometimes|string|max:10',
            'supported_languages' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'settings' => 'nullable|array',
            'current_published_version_id' => 'nullable|exists:bot_versions,id',
            'published_at' => 'nullable|date',
        ]);

        if (
            isset($validated['is_active'])
            && $validated['is_active'] === true
            && !$bot->is_active
            && Bot::where('is_active', true)->count() >= self::MAX_ACTIVE_BOTS
        ) {
            return response()->json([
                'message' => 'You can have at most '.self::MAX_ACTIVE_BOTS.' active bots. Deactivate one first.',
            ], 422);
        }

        $bot->update(array_filter($validated, fn ($v) => $v !== null || array_key_exists('description', $validated)));

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($bot)->log('Bot updated');

        return response()->json([
            'message' => 'Bot updated.',
            'bot' => $bot->load(['whatsappAccount', 'user']),
        ]);
    }

    public function destroy(Bot $bot): JsonResponse
    {
        if (Conversation::where('bot_id', $bot->id)->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete bot with active conversations.',
                'active_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'active')->count(),
            ], 422);
        }

        $name = $bot->name;
        $bot->delete();

        activity()->causedBy(Auth::guard('tenant')->user())->log("Bot deleted: {$name}");

        return response()->json(['message' => 'Bot deleted.', 'deleted_bot' => $name]);
    }

    public function duplicate(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
        ]);

        $newBot = Bot::create([
            'user_id' => Auth::guard('tenant')->id(),
            'whatsapp_account_id' => $validated['whatsapp_account_id'] ?? $bot->whatsapp_account_id,
            'name' => $validated['name'],
            'description' => $bot->description,
            'is_active' => false,
            'default_language' => $bot->default_language,
            'supported_languages' => $bot->supported_languages,
            'settings' => $bot->settings,
        ]);

        if ($config = $bot->configuration) {
            $newBot->getConfigOrCreate()->update($config->toArray());
        }

        foreach ($bot->dialogs as $dialog) {
            $newBot->dialogs()->create($dialog->toArray());
        }

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($newBot)->log("Bot duplicated from: {$bot->name}");

        return response()->json([
            'message' => 'Bot duplicated.',
            'bot' => $newBot->load(['whatsappAccount', 'user']),
        ], 201);
    }

    /**
     * Activate a bot.
     * Enforces the MAX_ACTIVE_BOTS limit — returns 422 if already at the cap.
     */
    public function activate(Bot $bot): JsonResponse
    {
        if ($bot->is_active) {
            return response()->json(['message' => 'Bot is already active.', 'is_active' => true]);
        }

        $activeCount = Bot::where('is_active', true)->count();

        if ($activeCount >= self::MAX_ACTIVE_BOTS) {
            $activeBots = Bot::where('is_active', true)->get(['id', 'name']);

            return response()->json([
                'message' => 'You can only have '.self::MAX_ACTIVE_BOTS.' active bots at a time. Deactivate one of the following first:',
                'active_bots' => $activeBots,
            ], 422);
        }

        $bot->update(['is_active' => true]);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($bot)->log("Bot activated: {$bot->name}");

        return response()->json(['message' => "{$bot->name} activated.", 'is_active' => true]);
    }

    /**
     * Deactivate a bot.
     */
    public function deactivate(Bot $bot): JsonResponse
    {
        if (!$bot->is_active) {
            return response()->json(['message' => 'Bot is already inactive.', 'is_active' => false]);
        }

        $bot->update(['is_active' => false]);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($bot)->log("Bot deactivated: {$bot->name}");

        return response()->json(['message' => "{$bot->name} deactivated.", 'is_active' => false]);
    }

    /**
     * Publish a bot version.
     *
     * If publishing would require the bot to be active and the limit is already
     * reached, automatically deactivates the least-recently-published other bot
     * and activates this one. The response includes which bot was displaced so
     * the frontend can show a clear message.
     */
    public function publish(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'version_id' => 'required|exists:bot_versions,id',
        ]);

        $version = $bot->versions()->findOrFail($validated['version_id']);

        $displacedBot = null;

        if (!$bot->is_active) {
            $activeCount = Bot::where('is_active', true)->count();

            if ($activeCount >= self::MAX_ACTIVE_BOTS) {
                // Deactivate the least-recently-published active bot (not this one)
                $displaced = Bot::where('is_active', true)
                    ->where('id', '!=', $bot->id)
                    ->orderBy('published_at', 'asc')
                    ->first();

                if ($displaced) {
                    $displaced->update(['is_active' => false]);
                    $displacedBot = ['id' => $displaced->id, 'name' => $displaced->name];

                    activity()->causedBy(Auth::guard('tenant')->user())
                        ->performedOn($displaced)
                        ->log("Bot auto-deactivated to make room for: {$bot->name}");
                }
            }

            $bot->update(['is_active' => true]);
        }

        // Lock all other versions and publish this one
        $bot->versions()->where('id', '!=', $version->id)->where('status', 'published')->update(['status' => 'locked']);

        $version->update(['status' => 'published', 'published_at' => now()]);

        $bot->update([
            'current_published_version_id' => $version->id,
            'published_at' => now(),
        ]);

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($bot)
            ->withProperties(['version_id' => $version->id, 'version_number' => $version->version_number])
            ->log("Bot version {$version->version_number} published");

        $response = [
            'message' => "Version {$version->version_number} published.",
            'bot' => $bot->fresh()->load(['whatsappAccount', 'currentPublishedVersion']),
            'version' => $version->fresh(),
        ];

        if ($displacedBot) {
            $response['displaced_bot'] = $displacedBot;
            $response['message'] = "Version {$version->version_number} published. {$displacedBot['name']} was deactivated to stay within the 2-bot limit.";
        }

        return response()->json($response);
    }

    public function stats(Bot $bot): JsonResponse
    {
        return response()->json([
            'total_versions' => $bot->versions()->count(),
            'published_versions' => $bot->versions()->where('status', 'published')->count(),
            'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
            'locked_versions' => $bot->versions()->where('status', 'locked')->count(),
            'current_published_version' => $bot->currentPublishedVersion?->version_number,
            'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
            'active_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'active')->count(),
            'completed_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'completed')->count(),
            'handed_off_conversations' => Conversation::where('bot_id', $bot->id)->where('status', 'handed_off')->count(),
            'media_files' => $bot->mediaFiles()->count(),
            'dialogs' => $bot->dialogs()->count(),
            'custom_variables' => $bot->customVariables()->count(),
            'custom_functions' => $bot->customFunctions()->count(),
            'apis' => $bot->apis()->count(),
            'created_at' => $bot->created_at,
            'updated_at' => $bot->updated_at,
            'published_at' => $bot->published_at,
        ]);
    }

    public function byWhatsAppAccount(string $whatsappAccountId): JsonResponse
    {
        $bots = Bot::where('whatsapp_account_id', $whatsappAccountId)->with(['user'])->orderBy('name')->get();

        return response()->json($bots);
    }

    public function activeByWhatsAppAccount(string $whatsappAccountId): JsonResponse
    {
        $bots = Bot::where('whatsapp_account_id', $whatsappAccountId)->where('is_active', true)->with(['user'])->orderBy('name')->get();

        return response()->json($bots);
    }
}