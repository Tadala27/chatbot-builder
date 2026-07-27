<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Bot\WhatsAppMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class InboxController extends Controller
{
    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024;   // 5 MB
    private const MAX_VIDEO_SIZE = 16 * 1024 * 1024;  // 16 MB
    private const MAX_AUDIO_SIZE = 16 * 1024 * 1024;  // 16 MB
    private const MAX_DOCUMENT_SIZE = 100 * 1024 * 1024; // 100 MB

    public function __construct(private WhatsAppMessageService $whatsapp)
    {
    }

    // =========================================================================
    // Consolidated send — handles text, media, and mixed payloads in one request
    // =========================================================================

    /**
     * POST /tenant/inbox/conversations/{conversation}/send.
     *
     * Accepts any combination of:
     *   - text (string)                   → sends a WhatsApp text message
     *   - file (UploadedFile)             → sends image/video/audio/document
     *   - caption (string, optional)      → caption on the media message
     *   - reply_to_wamid (string, opt.)   → context/quoted message
     *
     * When both text and file are present:
     *   - File is sent first (with caption if provided)
     *   - Text is sent as a follow-up text message
     *   (WhatsApp does not allow free-text body on media messages beyond caption)
     */
    public function send(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate([
            'text' => 'nullable|string|max:4096',
            'file' => [
                'nullable', 'file',
                'mimetypes:'
                    // Images
                    .'image/jpeg,image/png,image/webp,'
                    // Video
                    .'video/mp4,video/3gpp,'
                    // Audio — WhatsApp native + browser recorder formats
                    .'audio/aac,audio/amr,audio/mpeg,audio/mp4,audio/ogg,'
                    .'audio/webm,audio/wav,'
                    // Documents
                    .'application/pdf,'
                    .'application/msword,'
                    .'application/vnd.openxmlformats-officedocument.wordprocessingml.document,'
                    .'application/vnd.ms-excel,'
                    .'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,'
                    .'application/vnd.ms-powerpoint,'
                    .'application/vnd.openxmlformats-officedocument.presentationml.presentation,'
                    .'text/plain',
                'max:102400',
            ],
            'caption' => 'nullable|string|max:1024',
            'reply_to_wamid' => 'nullable|string|max:256',
        ]);

        $hasText = filled($request->input('text'));
        $hasFile = $request->hasFile('file') && $request->file('file')?->isValid();

        if (!$hasText && !$hasFile) {
            return response()->json(['message' => 'Provide either text or a file.'], 422);
        }

        $account = $conversation->whatsappAccount;

        if (!$account) {
            return response()->json(['message' => 'No WhatsApp account linked to this conversation.'], 422);
        }

        if (!$account->is_active) {
            return response()->json(['message' => 'The WhatsApp account is inactive.'], 422);
        }

        $replyToWamid = $request->input('reply_to_wamid');
        $agentMeta = $this->agentMeta();
        $sent = [];

        // ── Step 1: send file if present ─────────────────────────────────────
        if ($hasFile) {
            /** @var UploadedFile $file */
            $file = $request->file('file');
            $caption = filled($request->input('caption')) ? $request->input('caption') : null;
            $mimeType = $file->getMimeType() ?? 'application/octet-stream';

            $maxSize = $this->maxSizeForMime($mimeType);
            if ($file->getSize() > $maxSize) {
                return response()->json([
                    'message' => sprintf(
                        'File too large. Maximum size for this type is %d MB.',
                        round($maxSize / 1024 / 1024)
                    ),
                ], 422);
            }

            $this->whatsapp->sendTypingIndicator($account, $conversation->whatsapp_user_phone, true);

            try {
                $mediaMessage = $this->whatsapp->sendMediaFile(
                    account: $account,
                    to: $conversation->whatsapp_user_phone,
                    file: $file,
                    caption: $caption,
                    replyToWamid: $replyToWamid,
                    metadata: $agentMeta,
                );
                $sent[] = $mediaMessage;

                // Only attach reply context to the first message
                $replyToWamid = null;
            } catch (\Exception $e) {
                Log::error('[Inbox] Failed to send media', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Failed to send media: '.$e->getMessage(),
                ], 502);
            }
        }

        // ── Step 2: send text if present ─────────────────────────────────────
        if ($hasText) {
            $text = $request->input('text');

            $this->whatsapp->sendTypingIndicator($account, $conversation->whatsapp_user_phone, true);

            try {
                $textMessage = $this->whatsapp->sendTextMessage(
                    account: $account,
                    to: $conversation->whatsapp_user_phone,
                    text: $text,
                    variables: [],
                    replyToWamid: $replyToWamid,
                    metadata: $agentMeta,
                );
                $sent[] = $textMessage;
            } catch (\Exception $e) {
                Log::error('[Inbox] Failed to send text', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);

                return response()->json([
                    'message' => 'Failed to send message: '.$e->getMessage(),
                ], 502);
            }
        }

        activity()
            ->causedBy(auth()->user())
            ->performedOn($conversation)
            ->withProperties(['message_count' => count($sent)])
            ->log('Agent sent '.count($sent).' message(s)');

        return response()->json([
            'messages' => $sent,
            'message' => $sent[0] ?? null,
        ], 201);
    }

    // =========================================================================
    // Typing indicator
    // =========================================================================

    public function typing(Request $request, Conversation $conversation): JsonResponse
    {
        $request->validate(['typing' => 'required|boolean']);

        $account = $conversation->whatsappAccount;
        if ($account) {
            $this->whatsapp->sendTypingIndicator(
                $account,
                $conversation->whatsapp_user_phone,
                $request->boolean('typing'),
                $conversation
            );
        }

        return response()->json(['ok' => true]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function agentMeta(): array
    {
        return [
            'sender_type' => 'agent',
            'sender_id' => auth()->id(),
            'sender_name' => auth()->user()?->name,
        ];
    }

    private function maxSizeForMime(string $mimeType): int
    {
        return match (true) {
            str_starts_with($mimeType, 'image/') => self::MAX_IMAGE_SIZE,
            str_starts_with($mimeType, 'video/') => self::MAX_VIDEO_SIZE,
            str_starts_with($mimeType, 'audio/') => self::MAX_AUDIO_SIZE,
            default => self::MAX_DOCUMENT_SIZE,
        };
    }
}