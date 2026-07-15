<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Bot\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // FIX: `latestMessage` was never eager-loaded here, so every list
        // item had nothing to build a preview from — this is why the chat
        // list preview wasn't showing anything for sticker/media messages
        // (or any message at all). show() computed it, index() didn't.
        $query = Conversation::with(['bot', 'whatsappAccount', 'assignedAgent', 'latestMessage']);

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q
                ->where('whatsapp_user_phone', 'like', "%{$s}%")
                ->orWhere('whatsapp_user_name', 'like', "%{$s}%"));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('bot_id')) {
            $query->where('bot_id', $request->bot_id);
        }

        if ($request->filled('whatsapp_account_id')) {
            $query->where('whatsapp_account_id', $request->whatsapp_account_id);
        }

        if ($request->filled('assigned_agent_id')) {
            $request->assigned_agent_id === 'unassigned'
                ? $query->whereNull('assigned_agent_id')
                : $query->where('assigned_agent_id', $request->assigned_agent_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('started_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('started_at', '<=', $request->end_date);
        }

        $query->orderBy($request->get('sort', 'last_message_at'), $request->get('direction', 'desc'));

        $conversations = $query->paginate($request->get('per_page', 20));

        $conversations->getCollection()->transform(function ($c) {
            $c->duration_formatted = $c->getFormattedDuration();
            $preview = $this->buildMessagePreview($c->latestMessage);
            $c->last_message_preview = $preview['text'];
            $c->last_message_preview_type = $preview['type'];

            return $c;
        });

        return response()->json($conversations);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:4096',
            'reply_to_wamid' => 'nullable|string|max:128',
        ]);

        $account = $conversation->whatsappAccount;

        if (!$account) {
            return response()->json([
                'message' => 'This conversation has no linked WhatsApp account — cannot send.',
            ], 422);
        }

        if (!$account->is_active) {
            return response()->json([
                'message' => 'The WhatsApp account for this conversation is inactive.',
            ], 422);
        }

        if ($conversation->isActive()) {
            $conversation->handOff(agentId: auth()->id());

            Log::info('Conversation auto-handed-off — agent sent a message', [
                'conversation_id' => $conversation->id,
                'agent_id' => auth()->id(),
            ]);

            $conversation->refresh();
        }

        $service = app(WhatsAppMessageService::class);

        try {
            $message = !empty($validated['reply_to_wamid'])
                ? $service->sendReplyText(
                    account: $account,
                    to: $conversation->whatsapp_user_phone,
                    text: $validated['text'],
                    replyToWamid: $validated['reply_to_wamid'],
                )
                : $service->sendTextMessage(
                    account: $account,
                    to: $conversation->whatsapp_user_phone,
                    text: $validated['text'],
                );
        } catch (\Exception $e) {
            Log::error('Failed to send agent text message', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send message. Please try again.',
            ], 502);
        }

        if ($message->reply_to_wamid) {
            $quoted = Message::where('conversation_id', $conversation->id)
                ->where('whatsapp_message_id', $message->reply_to_wamid)
                ->first();

            if ($quoted) {
                $message->quoted_message = [
                    'id' => $quoted->id,
                    'direction' => $quoted->direction,
                    'message_type' => $quoted->message_type,
                    'content' => $quoted->content,
                ];
            }
        }

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->withProperties(['message_id' => $message->id])->log('Agent sent message');

        return response()->json(['data' => $message], 201);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load(['bot', 'whatsappAccount', 'assignedAgent', 'context']);

        // ->latestMessage is null for a conversation with no messages yet —
        // buildMessagePreview() must accept that instead of blowing up
        // before the response can even be built.
        $preview = $this->buildMessagePreview($conversation->latestMessage);
        $conversation['latest_message'] = $preview['text'];
        $conversation['latest_message_type'] = $preview['type'];

        return response()->json([
            'data' => $conversation,
            'duration_formatted' => $conversation->getFormattedDuration(),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $query = $conversation->messages()->orderBy('sent_at', 'asc');

        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }

        if ($request->filled('message_type')) {
            $query->where('message_type', $request->message_type);
        }

        $paginated = $query->paginate($request->get('per_page', 50));

        $wamidsNeeded = $paginated->getCollection()
            ->pluck('reply_to_wamid')
            ->filter()
            ->unique()
            ->values();

        if ($wamidsNeeded->isNotEmpty()) {
            $quotedMessages = Message::where('conversation_id', $conversation->id)
                ->whereIn('whatsapp_message_id', $wamidsNeeded)
                ->get()
                ->keyBy('whatsapp_message_id');

            $paginated->getCollection()->transform(function ($m) use ($quotedMessages) {
                if ($m->reply_to_wamid && $quotedMessages->has($m->reply_to_wamid)) {
                    $quoted = $quotedMessages->get($m->reply_to_wamid);
                    $m->quoted_message = [
                        'id' => $quoted->id,
                        'direction' => $quoted->direction,
                        'message_type' => $quoted->message_type,
                        'content' => $quoted->content,
                    ];
                }

                return $m;
            });
        }

        return response()->json($paginated);
    }

    public function handoff(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'assigned_agent_id' => 'nullable|exists:users,id',
            'reason' => 'nullable|string|max:500',
        ]);

        $conversation->handOff(agentId: $validated['assigned_agent_id'] ?? null);

        if ($conversation->whatsappAccount) {
            app(WhatsAppMessageService::class)->sendTextMessage(
                $conversation->whatsappAccount,
                $conversation->whatsapp_user_phone,
                $validated['reason'] ?? 'Transferring you to an agent...'
            );
        }

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->withProperties($validated)->log('Conversation handed off');

        return response()->json([
            'message' => 'Conversation handed off.',
            'conversation' => $conversation->fresh(),
        ]);
    }

    public function end(Conversation $conversation): JsonResponse
    {
        $conversation->complete();

        activity()->causedBy(auth()->user())->performedOn($conversation)->log('Conversation ended');

        return response()->json(['message' => 'Conversation ended.', 'conversation' => $conversation]);
    }

    public function destroy(Conversation $conversation): JsonResponse
    {
        if ($conversation->isActive()) {
            return response()->json(['message' => 'Cannot delete an active conversation.'], 422);
        }

        $conversation->delete();

        activity()->causedBy(auth()->user())->log('Conversation deleted');

        return response()->json(['message' => 'Conversation deleted.']);
    }

    public function export(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'format' => 'required|in:csv,json',
            'bot_id' => 'nullable|exists:bots,id',
            'status' => 'nullable|in:active,completed,abandoned,handed_off',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $query = Conversation::query();

        if (!empty($validated['bot_id'])) {
            $query->where('bot_id', $validated['bot_id']);
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (!empty($validated['start_date'])) {
            $query->whereDate('started_at', '>=', $validated['start_date']);
        }
        if (!empty($validated['end_date'])) {
            $query->whereDate('started_at', '<=', $validated['end_date']);
        }

        $conversations = $query->with(['bot', 'whatsappAccount'])->get();

        $filename = 'conversations_'.now()->format('Y-m-d');

        if ($validated['format'] === 'csv') {
            return response()->json([
                'data' => base64_encode($this->generateCsv($conversations)),
                'filename' => $filename.'.csv',
            ]);
        }

        return response()->json(['data' => $conversations, 'filename' => $filename.'.json']);
    }

    public function statistics(Request $request): JsonResponse
    {
        $query = Conversation::query();

        if ($request->filled('bot_id')) {
            $query->where('bot_id', $request->bot_id);
        }
        if ($request->filled('start_date')) {
            $query->whereDate('started_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('started_at', '<=', $request->end_date);
        }

        return response()->json([
            'total_conversations' => (clone $query)->count(),
            'active_conversations' => (clone $query)->where('status', 'active')->count(),
            'completed_conversations' => (clone $query)->where('status', 'completed')->count(),
            'abandoned_conversations' => (clone $query)->where('status', 'abandoned')->count(),
            'handed_off_conversations' => (clone $query)->where('status', 'handed_off')->count(),
            'average_duration_seconds' => (clone $query)->whereNotNull('ended_at')
                ->get()->avg(fn ($c) => $c->getDuration()),
            'average_message_count' => (clone $query)->avg('message_count'),
            'by_status' => (clone $query)
                ->selectRaw('status, COUNT(*) as count')->groupBy('status')->get(),
        ]);
    }

    // -------------------------------------------------------------------------

    /**
     * Type-aware summary of a message's content, mirroring how WhatsApp
     * itself renders a chat-list preview: media/sticker/location/contact
     * messages show a fixed icon + label (e.g. "Sticker", "Photo"), not
     * their raw payload. Returns both the label text and a `type` key so
     * the frontend can pick the matching icon — the text alone isn't
     * enough to distinguish "a photo with no caption" from "a message that
     * literally says Photo".
     *
     * Defensive against every shape traced in content-shapes-reference:
     * text, interactive (list/buttons, both outbound-sent and
     * inbound-tapped shapes), media, location, contacts, sticker. Never
     * indexes into $content without checking the key exists first.
     *
     * Accepts a nullable Message — a conversation with zero messages yet
     * has ->latestMessage === null, and this must not blow up in that case
     * (a plain `Message $message` type-hint previously rejected null with
     * a TypeError, crashing show() for any empty conversation).
     *
     * @return array{type: string, text: string}
     */
    private function buildMessagePreview(?Message $message): array
    {
        if (!$message) {
            return ['type' => 'none', 'text' => 'No messages yet'];
        }

        $rawContent = $message->content ?? [];
        // Defensive: if the model's `content` cast isn't (or stops being)
        // 'array'/'json', $rawContent could arrive here as a raw JSON
        // string instead of an array — decode it rather than crashing on
        // "Cannot use a scalar value as an array".
        $content = is_array($rawContent) ? $rawContent : (json_decode((string) $rawContent, true) ?? []);
        $type = $message->message_type;

        return match (true) {
            $type === 'text' => ['type' => 'text', 'text' => $content['text'] ?? ''],

            $type === 'interactive' && ($content['type'] ?? null) === 'list' => ['type' => 'list', 'text' => $content['body']['text'] ?? 'List message'],

            $type === 'interactive' && ($content['type'] ?? null) === 'button' => ['type' => 'buttons', 'text' => $content['body']['text'] ?? 'Button message'],

            // user tapped a button/list row — inbound shape
            $type === 'interactive' && isset($content['response']) => ['type' => 'reply', 'text' => $content['response']['title'] ?? 'Selected an option'],

            $type === 'button' => ['type' => 'reply', 'text' => $content['text'] ?? 'Quick reply'],

            $type === 'image' => ['type' => 'image', 'text' => $content['caption'] ?? 'Photo'],
            $type === 'video' => ['type' => 'video', 'text' => $content['caption'] ?? 'Video'],
            $type === 'audio' => ['type' => 'audio', 'text' => $content['caption'] ?? 'Audio'],
            $type === 'document' => [
                'type' => 'document',
                'text' => $content['caption'] ?? ($content['filename'] ?? 'Document'),
            ],
            $type === 'sticker' => ['type' => 'sticker', 'text' => 'Sticker'],

            $type === 'location' => ['type' => 'location', 'text' => $content['name'] ?? 'Location'],

            $type === 'contacts' => [
                'type' => 'contact',
                'text' => ($content['contacts'][0]['name']['formatted_name'] ?? null) ?? 'Contact card',
            ],

            default => ['type' => 'unknown', 'text' => 'Message'],
        };
    }

    private function generateCsv($conversations): string
    {
        $csv = "ID,Phone,Name,Bot,Status,Started At,Ended At,Duration (s),Messages\n";

        foreach ($conversations as $c) {
            $csv .= implode(',', [
                $c->id,
                $c->whatsapp_user_phone,
                $c->whatsapp_user_name ?? 'N/A',
                $c->bot->name ?? 'N/A',
                $c->status,
                $c->started_at->format('Y-m-d H:i:s'),
                $c->ended_at?->format('Y-m-d H:i:s') ?? 'N/A',
                $c->getDuration() ?? 'N/A',
                $c->message_count,
            ])."\n";
        }

        return $csv;
    }
}