<?php

namespace App\Http\Controllers\Api;

use App\Events\AgentTyping;
use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Bot\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;
    private const MAX_VIDEO_SIZE = 16 * 1024 * 1024;
    private const MAX_AUDIO_SIZE = 16 * 1024 * 1024;
    private const MAX_DOCUMENT_SIZE = 100 * 1024 * 1024;

    public function __construct(
        private WhatsAppMessageService $whatsapp,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Conversation::with([
            'whatsappAccount:id,verified_name,display_phone_number,phone_number_id',
            'latestMessage:id,conversation_id,direction,message_type,content,sent_at,read_at',
        ])
            ->withCount([
                'messages as unread_count' => function ($q) {
                    $q->where('direction', 'inbound')->whereNull('read_at');
                },
            ]);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = $request->query('search')) {
            $query->where(fn ($q) => $q
                ->where('whatsapp_user_name', 'like', "%{$search}%")
                ->orWhere('whatsapp_user_phone', 'like', "%{$search}%"));
        }
        if ($accountId = $request->query('account_id')) {
            $query->where('whatsapp_account_id', $accountId);
        }

        return response()->json(
            $query->orderByDesc('last_message_at')->paginate(30)
        );
    }

    public function show(Conversation $conversation): JsonResponse
    {
        $conversation->load('whatsappAccount:id,verified_name,display_phone_number,phone_number_id');

        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('sent_at', 'asc')
            ->paginate(50, ['*'], 'page', request()->query('page', 1));

        return response()->json([
            'conversation' => $conversation,
            'messages' => $messages,
        ]);
    }

    // ── Text message (with optional reply context) ────────────────────────────

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'text' => 'required|string|max:4096',
            'reply_to_wamid' => 'nullable|string',
        ]);

        $account = $conversation->whatsappAccount;
        $replyToWamid = $request->input('reply_to_wamid');

        $this->whatsapp->sendTypingIndicator($account, $conversation->whatsapp_user_phone, true);

        $message = $replyToWamid
            ? $this->whatsapp->sendReplyText(
                $account,
                $conversation->whatsapp_user_phone,
                $request->input('text'),
                $replyToWamid
            )
            : $this->whatsapp->sendTextMessage(
                $account,
                $conversation->whatsapp_user_phone,
                $request->input('text')
            );

        $message->update([
            'metadata' => array_merge($message->metadata ?? [], [
                'sender_type' => 'agent',
                'sender_id' => auth()->id(),
                'sender_name' => auth()->user()?->name,
            ]),
        ]);

        broadcast(new MessageSent($message->fresh(), $conversation->fresh()));

        return response()->json(['message' => $message->fresh()], 201);
    }

    // ── File / media attachment (with optional reply context) ─────────────────

    public function sendMedia(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp,mp4,3gp,ogg,oga,mp3,aac,amr,m4a,pdf,doc,docx,xls,xlsx,ppt,pptx,txt',
                'max:102400',
            ],
            'caption' => 'nullable|string|max:1024',
            'reply_to_wamid' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $caption = $request->input('caption') ?: null;
        $replyToWamid = $request->input('reply_to_wamid') ?: null;
        $account = $conversation->whatsappAccount;

        $mimeType = $file->getMimeType() ?? '';
        $sizeBytes = $file->getSize();
        $maxSize = match (true) {
            str_starts_with($mimeType, 'image/') => self::MAX_IMAGE_SIZE,
            str_starts_with($mimeType, 'video/') => self::MAX_VIDEO_SIZE,
            str_starts_with($mimeType, 'audio/') => self::MAX_AUDIO_SIZE,
            default => self::MAX_DOCUMENT_SIZE,
        };

        if ($sizeBytes > $maxSize) {
            return response()->json([
                'message' => sprintf(
                    'File too large. The %s limit for this type is %d MB.',
                    $mimeType,
                    round($maxSize / 1024 / 1024)
                ),
            ], 422);
        }

        try {
            $message = $this->whatsapp->sendMediaFile(
                $account,
                $conversation->whatsapp_user_phone,
                $file,
                $caption,
                $replyToWamid
            );

            $message->update([
                'metadata' => array_merge($message->metadata ?? [], [
                    'sender_type' => 'agent',
                    'sender_id' => auth()->id(),
                    'sender_name' => auth()->user()?->name,
                ]),
            ]);

            broadcast(new MessageSent($message->fresh(), $conversation->fresh()));

            return response()->json(['message' => $message->fresh()], 201);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to send media: '.$e->getMessage()], 500);
        }
    }

    // ── Mark conversation read (optionally trigger typing indicator) ──────────

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $account = $conversation->whatsappAccount;

        $latestUnread = Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->whereNotNull('whatsapp_message_id')
            ->latest('sent_at')
            ->first();

        if ($latestUnread) {
            $this->whatsapp->markAsRead($account, $latestUnread->whatsapp_message_id);

            if ($request->boolean('with_typing')) {
                $this->whatsapp->sendTypingIndicator($account, $conversation->whatsapp_user_phone, true);
            }
        }

        Message::where('conversation_id', $conversation->id)
            ->where('direction', 'inbound')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ── Typing indicator (agent → contact + multi-agent broadcast) ────────────

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $isTyping = $request->boolean('typing', true);
        $account = $conversation->whatsappAccount;

        $this->whatsapp->sendTypingIndicator($account, $conversation->whatsapp_user_phone, $isTyping);

        broadcast(new AgentTyping(
            $conversation->id,
            auth()->id(),
            auth()->user()?->name ?? 'Agent',
            $isTyping
        ));

        return response()->json(['ok' => true]);
    }

    public function accounts(): JsonResponse
    {
        $accounts = WhatsappAccount::where('is_active', true)
            ->get(['id', 'verified_name', 'display_phone_number', 'phone_number_id']);

        return response()->json(['accounts' => $accounts]);
    }
}