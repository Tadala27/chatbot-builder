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

        // Add stats to each bot
        $bots->getCollection()->transform(function ($bot) {
            $bot->stats = [
                'total_versions' => $bot->versions()->count(),
                'published_version' => $bot->versions()->where('status', 'published')->value('version_number'),
                'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
                'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
                'active_conversations' => Conversation::where('bot_id', $bot->id)
                    ->where('status', 'active')
                    ->count(),
            ];

            return $bot;
        });

        return response()->json($bots);
    }

    public function show(Bot $bot): JsonResponse
    {
        $bot->load([
            'whatsappAccount',
            'user',
            'currentPublishedVersion',
            'customVariables',
            'customFunctions',
            'apis',
        ]);

        return response()->json([
            'bot' => $bot,
            'stats' => [
                'total_versions' => $bot->versions()->count(),
                'published_version' => $bot->versions()->where('status', 'published')->value('version_number'),
                'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
                'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
                'active_conversations' => Conversation::where('bot_id', $bot->id)
                    ->where('status', 'active')
                    ->count(),
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

        // Prepare the data with all fillable fields
        $botData = [
            'user_id' => Auth::guard('tenant')->id(),
            'whatsapp_account_id' => $validated['whatsapp_account_id'],
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'default_language' => $validated['default_language'] ?? 'en',
            'supported_languages' => $validated['supported_languages'] ?? null,
            'settings' => $validated['settings'] ?? null,
            // current_published_version_id and published_at will be null initially
        ];

        $bot = Bot::create($botData);

        // Create default configuration for the bot
        $bot->getConfigOrCreate();

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($bot)
            ->log('Bot created');

        return response()->json([
            'message' => 'Bot created successfully.',
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

        // Only update fields that are present in the request
        $updateData = [];

        if (isset($validated['whatsapp_account_id'])) {
            $updateData['whatsapp_account_id'] = $validated['whatsapp_account_id'];
        }

        if (isset($validated['name'])) {
            $updateData['name'] = $validated['name'];
        }

        if (array_key_exists('description', $validated)) {
            $updateData['description'] = $validated['description'];
        }

        if (isset($validated['default_language'])) {
            $updateData['default_language'] = $validated['default_language'];
        }

        if (array_key_exists('supported_languages', $validated)) {
            $updateData['supported_languages'] = $validated['supported_languages'];
        }

        if (array_key_exists('settings', $validated)) {
            $updateData['settings'] = $validated['settings'];
        }

        if (isset($validated['is_active'])) {
            $updateData['is_active'] = $validated['is_active'];
        }

        if (isset($validated['current_published_version_id'])) {
            $updateData['current_published_version_id'] = $validated['current_published_version_id'];
        }

        if (isset($validated['published_at'])) {
            $updateData['published_at'] = $validated['published_at'];
        }

        $bot->update($updateData);

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($bot)
            ->log('Bot updated');

        return response()->json([
            'message' => 'Bot updated successfully.',
            'bot' => $bot->load(['whatsappAccount', 'user']),
        ]);
    }

    public function destroy(Bot $bot): JsonResponse
    {
        // Check if bot has active conversations
        if (Conversation::where('bot_id', $bot->id)->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete bot with active conversations.',
                'active_conversations' => Conversation::where('bot_id', $bot->id)
                    ->where('status', 'active')
                    ->count(),
            ], 422);
        }

        $name = $bot->name;
        $bot->delete();

        activity()->causedBy(Auth::guard('tenant')->user())
            ->log("Bot deleted: {$name}");

        return response()->json([
            'message' => 'Bot deleted successfully.',
            'deleted_bot' => $name,
        ]);
    }

    /**
     * Duplicate an existing bot with its configuration.
     */
    public function duplicate(Request $request, Bot $bot): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'whatsapp_account_id' => 'sometimes|exists:whatsapp_accounts,id',
        ]);

        // Create new bot with same settings but new name
        $newBotData = [
            'user_id' => Auth::guard('tenant')->id(),
            'whatsapp_account_id' => $validated['whatsapp_account_id'] ?? $bot->whatsapp_account_id,
            'name' => $validated['name'],
            'description' => $bot->description,
            'is_active' => false, // New bot starts inactive
            'default_language' => $bot->default_language,
            'supported_languages' => $bot->supported_languages,
            'settings' => $bot->settings,
        ];

        $newBot = Bot::create($newBotData);

        // Copy configuration
        $config = $bot->configuration;
        if ($config) {
            $newBot->getConfigOrCreate()->update($config->toArray());
        }

        // Copy dialogs (system dialogs)
        foreach ($bot->dialogs as $dialog) {
            $newBot->dialogs()->create($dialog->toArray());
        }

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($newBot)
            ->log("Bot duplicated from: {$bot->name}");

        return response()->json([
            'message' => 'Bot duplicated successfully.',
            'bot' => $newBot->load(['whatsappAccount', 'user']),
        ], 201);
    }

    /**
     * Toggle bot active status.
     */
    public function toggleActive(Bot $bot): JsonResponse
    {
        $bot->update([
            'is_active' => !$bot->is_active,
        ]);

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($bot)
            ->log(($bot->is_active ? 'Activated' : 'Deactivated')." bot: {$bot->name}");

        return response()->json([
            'message' => 'Bot '.($bot->is_active ? 'activated' : 'deactivated').' successfully.',
            'is_active' => $bot->is_active,
        ]);
    }

    /**
     * Get bot statistics.
     */
    public function stats(Bot $bot): JsonResponse
    {
        $stats = [
            'total_versions' => $bot->versions()->count(),
            'published_versions' => $bot->versions()->where('status', 'published')->count(),
            'draft_versions' => $bot->versions()->where('status', 'draft')->count(),
            'locked_versions' => $bot->versions()->where('status', 'locked')->count(),
            'current_published_version' => $bot->currentPublishedVersion?->version_number,
            'total_conversations' => Conversation::where('bot_id', $bot->id)->count(),
            'active_conversations' => Conversation::where('bot_id', $bot->id)
                ->where('status', 'active')
                ->count(),
            'completed_conversations' => Conversation::where('bot_id', $bot->id)
                ->where('status', 'completed')
                ->count(),
            'handed_off_conversations' => Conversation::where('bot_id', $bot->id)
                ->where('status', 'handed_off')
                ->count(),
            'media_files' => $bot->mediaFiles()->count(),
            'dialogs' => $bot->dialogs()->count(),
            'custom_variables' => $bot->customVariables()->count(),
            'custom_functions' => $bot->customFunctions()->count(),
            'apis' => $bot->apis()->count(),
            'created_at' => $bot->created_at,
            'updated_at' => $bot->updated_at,
            'published_at' => $bot->published_at,
        ];

        return response()->json($stats);
    }

    /**
     * Get bots for a specific WhatsApp account.
     */
    public function byWhatsAppAccount(string $whatsappAccountId): JsonResponse
    {
        $bots = Bot::where('whatsapp_account_id', $whatsappAccountId)
            ->with(['user'])
            ->orderBy('name')
            ->get();

        return response()->json($bots);
    }

    /**
     * Get active bots for a specific WhatsApp account.
     */
    public function activeByWhatsAppAccount(string $whatsappAccountId): JsonResponse
    {
        $bots = Bot::where('whatsapp_account_id', $whatsappAccountId)
            ->where('is_active', true)
            ->with(['user'])
            ->orderBy('name')
            ->get();

        return response()->json($bots);
    }
}
