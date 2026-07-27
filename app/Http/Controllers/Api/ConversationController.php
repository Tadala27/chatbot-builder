<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Bot\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Log;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
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

        $conversations = $query->paginate($request->get('per_page', 100));

        return ConversationResource::collection($conversations);
    }

    public function store(Request $request, Conversation $conversation): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'required|string|max:4096',
            'reply_to_wamid' => 'nullable|string|max:128',
        ]);

        $account = $conversation->whatsappAccount;

        if (!$account) {
            return response()->json(['message' => 'This conversation has no linked WhatsApp account — cannot send.'], 422);
        }
        if (!$account->is_active) {
            return response()->json(['message' => 'The WhatsApp account for this conversation is inactive.'], 422);
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

            return response()->json(['message' => 'Failed to send message. Please try again.'], 502);
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
                    'sender_name' => $quoted->metadata['sender_name'] ?? null,
                    'sender_type' => $quoted->metadata['sender_type'] ?? ($quoted->direction === 'outbound' ? 'bot' : 'contact'),
                ];
            }
        }

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->withProperties(['message_id' => $message->id])
            ->log('Agent sent message');

        return (new MessageResource($message))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load(['bot', 'whatsappAccount', 'assignedAgent', 'context', 'latestMessage']);

        return response()->json([
            'data' => new ConversationResource($conversation),
        ]);
    }

    public function messages(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        $query = $conversation->messages()->orderBy('sent_at', 'desc');

        if ($request->filled('direction')) {
            $query->where('direction', $request->direction);
        }
        if ($request->filled('message_type')) {
            $query->where('message_type', $request->message_type);
        }

        if ($request->filled('before')) {
            $cursor = Message::find($request->before);
            if ($cursor) {
                $query->where(fn ($q) => $q
                    ->where('sent_at', '<', $cursor->sent_at)
                    ->orWhere(fn ($q2) => $q2
                        ->where('sent_at', $cursor->sent_at)
                        ->where('id', '<', $cursor->id)
                    )
                );
            }
        }

        $perPage = (int) $request->get('per_page', 100);
        $paginated = $query->paginate($perPage);

        // Hydrate quoted messages for the current page in one query
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
                    $m->quoted_message = $quotedMessages->get($m->reply_to_wamid);
                }

                return $m;
            });
        }

        return MessageResource::collection($paginated);
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
                $validated['reason'] ?? 'Transferring you to an agent...',
            );
        }

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->withProperties($validated)
            ->log('Conversation handed off');

        return response()->json([
            'message' => 'Conversation handed off.',
            'conversation' => new ConversationResource($conversation->fresh(['bot', 'whatsappAccount', 'assignedAgent', 'latestMessage'])),
        ]);
    }

    public function resolve(Conversation $conversation): JsonResponse
    {
        $conversation->resolve(agentId: auth()->id());

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->log('Conversation resolved');

        return response()->json([
            'message' => 'Conversation resolved. The next message from this contact will start a fresh session.',
            'conversation' => new ConversationResource($conversation->fresh(['bot', 'whatsappAccount', 'assignedAgent', 'latestMessage'])),
        ]);
    }

    public function end(Conversation $conversation): JsonResponse
    {
        $conversation->complete();

        activity()->causedBy(auth()->user())->performedOn($conversation)
            ->log('Conversation ended');

        return response()->json([
            'message' => 'Conversation ended.',
            'conversation' => new ConversationResource($conversation),
        ]);
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

        return response()->json([
            'data' => ConversationResource::collection($conversations),
            'filename' => $filename.'.json',
        ]);
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
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get(),
        ]);
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