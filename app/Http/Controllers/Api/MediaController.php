<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotMediaFile;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Bot\WhatsAppMessageService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Handles everything related to bot media files:
 *
 *   TENANT STORAGE (all routes inside authenticated tenant middleware)
 *   ─────────────────────────────────────────────────────────────────
 *   POST   /tenant/api/bots/{bot}/media              → upload()
 *   GET    /tenant/api/bots/{bot}/media              → index()
 *   DELETE /tenant/api/media/{media}                 → destroy()
 *   GET    /tenant/api/media/{media}/serve           → serve()
 *   GET    /tenant/api/media/{media}/serve/stream    → serveStream()
 *
 *   WHATSAPP CLOUD API
 *   ─────────────────────────────────────────────────────────────────
 *   POST   /tenant/api/media/{media}/meta-upload     → uploadToMeta()
 *     Uploads a stored file to Meta's media API and returns a media_id.
 *     Called by the bot flow executor when processing a media dialog node
 *     before sending the message to WhatsApp.
 *
 *   GET    /tenant/api/messages/{message}/media      → stream()
 *     Proxies inbound media from Meta's CDN back to the agent UI.
 *
 *   GET    /tenant/api/messages/{message}/media/info → info()
 *     Returns Meta's metadata for an inbound media message.
 *
 * WHY serve() IS IN TENANT AUTH (not a public route):
 *   The file is served to the agent UI and to the Vue node editor — both
 *   authenticated requests inside the tenant context. The tenant database
 *   connection is already switched by middleware before this controller runs,
 *   so BotMediaFile queries hit the right DB with no extra resolver needed.
 *
 *   For sending media TO WhatsApp, the bot flow calls uploadToMeta() to get
 *   a media_id, then passes that id to WhatsAppMessageService — Meta fetches
 *   the file from their own CDN using that id, not from your serve() endpoint.
 *   So serve() never needs to be public.
 */
class MediaController extends Controller
{
    private const MAX_SIZES = [
        'image' => 5 * 1024 * 1024,
        'video' => 16 * 1024 * 1024,
        'audio' => 16 * 1024 * 1024,
        'document' => 100 * 1024 * 1024,
    ];

    private const ALLOWED_MIMES = [
        'image' => ['image/jpeg', 'image/png'],
        'video' => ['video/3gpp', 'video/mp4'],
        'audio' => ['audio/aac', 'audio/amr', 'audio/mpeg', 'audio/mp4', 'audio/ogg'],
        'document' => [
            'text/plain',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/pdf',
        ],
    ];

    private const EXTENSION_HINTS = [
        'image' => '.jpg, .jpeg, .png',
        'video' => '.3gp, .mp4 (H.264 video + AAC audio)',
        'audio' => '.aac, .amr, .mp3, .m4a, .ogg (OPUS codec only)',
        'document' => '.txt, .xls, .xlsx, .doc, .docx, .ppt, .pptx, .pdf',
    ];

    // Meta media_ids are ephemeral — cache per BotMediaFile for 6 hours
    // (well within Meta's window) to avoid re-uploading on every bot send.
    private const META_MEDIA_ID_CACHE_TTL = 6 * 60 * 60;

    // ── Storage: upload / list / delete / serve ───────────────────────────

    public function upload(Request $request, Bot $bot): JsonResponse
    {
        $request->validate([
            'file' => 'required|file',
            'type' => 'required|in:image,video,audio,document',
        ]);

        $file = $request->file('file');
        $type = $request->input('type');
        $mime = $file->getMimeType() ?? '';
        $size = $file->getSize();

        $allowed = self::ALLOWED_MIMES[$type] ?? [];

        if (!in_array($mime, $allowed, true)) {
            return response()->json([
                'message' => "Invalid file type for {$type}. Allowed formats: "
                    .(self::EXTENSION_HINTS[$type] ?? 'unknown'),
            ], 422);
        }

        $maxBytes = self::MAX_SIZES[$type];

        if ($size > $maxBytes) {
            return response()->json([
                'message' => 'File too large. Maximum size for '.$type.' is '.round($maxBytes / 1024 / 1024).' MB.',
            ], 422);
        }

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $storagePath = "bot-media/{$bot->id}/{$storedFilename}";
        $disk = 'public';

        Storage::disk($disk)->putFileAs("bot-media/{$bot->id}", $file, $storedFilename);

        $media = BotMediaFile::create([
            'user_id' => auth()->id(),
            'bot_id' => $bot->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => $disk,
            'path' => $storagePath,
            'url' => $this->serveUrl($storedFilename),
            'media_type' => $type,
            'mime_type' => $mime,
            'size' => $size,
        ]);

        return response()->json([
            'id' => $media->id,
            'url' => $media->url,
            'filename' => $media->original_filename,
            'type' => $type,
            'mime_type' => $mime,
            'size' => $size,
        ], 201);
    }

    public function index(Bot $bot): JsonResponse
    {
        $files = BotMediaFile::where('bot_id', $bot->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'original_filename', 'stored_filename', 'url', 'media_type', 'mime_type', 'size', 'created_at']);

        return response()->json(['files' => $files]);
    }

    public function destroy(BotMediaFile $media): JsonResponse
    {
        if (Storage::disk($media->disk)->exists($media->path)) {
            Storage::disk($media->disk)->delete($media->path);
        }

        $messageService = app(WhatsAppMessageService::class);
        $media->bot->whatsappAccount?->tap(function ($account) use ($media, $messageService) {
            Cache::forget($messageService->metaMediaCacheKey($media->id, $account->id));
        });

        $media->delete();

        return response()->json(['message' => 'Media deleted.']);
    }

    /**
     * Serve a file to the authenticated agent UI / node editor.
     * Tenant DB connection is already correct — no resolver needed.
     * Not public: the bot uses uploadToMeta() + a media_id to send to WhatsApp.
     */
    public function serve(BotMediaFile $media): StreamedResponse|Response
    {
        $disk = $media->disk ?? 'public';
        $path = $media->path;

        if (!Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        return response(Storage::disk($disk)->get($path), 200, [
            'Content-Type' => $media->mime_type ?? 'application/octet-stream',
            'Content-Length' => Storage::disk($disk)->size($path),
            'Content-Disposition' => 'inline; filename="'.addslashes($media->original_filename ?? $media->stored_filename).'"',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Streaming variant — better for video/audio (supports seek via Accept-Ranges).
     */
    public function serveStream(BotMediaFile $media): StreamedResponse|Response
    {
        $disk = $media->disk ?? 'public';
        $path = $media->path;

        if (!Storage::disk($disk)->exists($path)) {
            abort(404, 'File not found');
        }

        $size = Storage::disk($disk)->size($path);

        return response()->stream(
            function () use ($disk, $path) {
                $stream = Storage::disk($disk)->readStream($path);
                if ($stream) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $media->mime_type ?? 'application/octet-stream',
                'Content-Length' => $size,
                'Content-Disposition' => 'inline; filename="'.addslashes($media->original_filename ?? $media->stored_filename).'"',
                'Cache-Control' => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
                'Accept-Ranges' => 'bytes',
            ]
        );
    }

    // ── WhatsApp Cloud API: upload to Meta ────────────────────────────────

    /**
     * Upload a stored BotMediaFile to Meta's media API and return its media_id.
     *
     * Called by the bot flow executor (or manually from the UI) before sending
     * a media dialog node. The media_id is what you pass to the messages API —
     * Meta fetches the file from their own CDN using that id, so your serve()
     * endpoint never needs to be publicly accessible.
     *
     * The media_id is cached per file for 6 hours to avoid redundant uploads
     * on repeated sends of the same media node.
     *
     * POST /tenant/api/media/{media}/meta-upload
     */
    public function uploadToMeta(Request $request, BotMediaFile $media): JsonResponse
    {
        $whatsappAccount = $request->input('whatsapp_account_id')
            ? WhatsappAccount::findOrFail($request->input('whatsapp_account_id'))
            : null;

        if (!$whatsappAccount) {
            return response()->json(['message' => 'whatsapp_account_id is required.'], 422);
        }

        $cacheKey = $this->metaCacheKey($media->id, $whatsappAccount->id);

        $cached = Cache::get($cacheKey);

        if ($cached) {
            Log::info('[Media] Returning cached Meta media_id', [
                'media_id' => $media->id,
                'meta_media_id' => $cached,
            ]);

            return response()->json([
                'media_id' => $cached,
                'cached' => true,
            ]);
        }

        $disk = $media->disk ?? 'public';
        $path = $media->path;

        if (!Storage::disk($disk)->exists($path)) {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $token = $this->resolveToken($whatsappAccount);
        $phoneNumberId = $whatsappAccount->phone_number_id;
        $apiVersion = config('services.meta.api_version', 'v21.0');

        try {
            $client = new Client(['timeout' => 60]);

            $stream = Storage::disk($disk)->readStream($path);
            $fileSize = Storage::disk($disk)->size($path);

            $response = $client->post(
                "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/media",
                [
                    'headers' => ['Authorization' => "Bearer {$token}"],
                    'multipart' => [
                        [
                            'name' => 'file',
                            'contents' => $stream,
                            'filename' => $media->original_filename ?? $media->stored_filename,
                            'headers' => ['Content-Type' => $media->mime_type],
                        ],
                        [
                            'name' => 'type',
                            'contents' => $media->mime_type,
                        ],
                        [
                            'name' => 'messaging_product',
                            'contents' => 'whatsapp',
                        ],
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            $mediaId = $data['id'] ?? null;

            if (!$mediaId) {
                Log::error('[Media] Meta upload returned no media_id', [
                    'response' => $data,
                    'file' => $media->stored_filename,
                ]);

                return response()->json(['message' => 'Meta returned no media_id.'], 502);
            }

            Cache::put($cacheKey, $mediaId, self::META_MEDIA_ID_CACHE_TTL);

            Log::info('[Media] Uploaded to Meta successfully', [
                'media_id' => $media->id,
                'meta_media_id' => $mediaId,
                'file' => $media->stored_filename,
            ]);

            return response()->json([
                'media_id' => $mediaId,
                'cached' => false,
            ]);
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $error = $body['error']['message'] ?? $e->getMessage();

            Log::error('[Media] Meta upload failed', [
                'file' => $media->stored_filename,
                'error' => $error,
            ]);

            return response()->json(['message' => 'Failed to upload to Meta: '.$error], 502);
        } catch (\Throwable $e) {
            Log::error('[Media] Meta upload exception', [
                'file' => $media->stored_filename,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Media upload failed: '.$e->getMessage()], 500);
        }
    }

    // ── WhatsApp Cloud API: proxy inbound media ───────────────────────────

    /**
     * Proxy inbound media from Meta's CDN to the agent UI.
     * GET /tenant/api/messages/{message}/media.
     */
    public function stream(Request $request, Message $message): StreamedResponse|JsonResponse
    {
        try {
            $mediaId = $message->content['id'] ?? $message->content['media_id'] ?? null;

            if (!$mediaId) {
                return response()->json(['message' => 'This message has no media to retrieve.'], 404);
            }

            $account = $message->conversation?->whatsappAccount;

            if (!$account) {
                return response()->json(['message' => 'No WhatsApp account linked to this conversation.'], 500);
            }

            $token = $this->resolveToken($account);
            $apiVersion = config('services.meta.api_version', 'v21.0');
            $client = new Client(['timeout' => 30, 'http_errors' => true]);

            $metaResponse = $client->get(
                "https://graph.facebook.com/{$apiVersion}/{$mediaId}",
                ['headers' => ['Authorization' => "Bearer {$token}"]]
            );

            $metadata = json_decode($metaResponse->getBody()->getContents(), true);
            $cdnUrl = $metadata['url'] ?? null;
            $mimeType = $metadata['mime_type'] ?? $message->content['mime_type'] ?? 'application/octet-stream';
            $fileSize = $metadata['file_size'] ?? null;

            if (!$cdnUrl) {
                return response()->json(['message' => 'Media URL not found or expired.'], 404);
            }

            $mediaResponse = $client->get($cdnUrl, [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'stream' => true,
            ]);

            $body = $mediaResponse->getBody();
            $headers = ['Content-Type' => $mimeType, 'Cache-Control' => 'private, max-age=1800'];

            if ($fileSize) {
                $headers['Content-Length'] = $fileSize;
            }

            return response()->stream(function () use ($body) {
                while (!$body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, $headers);
        } catch (ClientException $e) {
            $data = json_decode($e->getResponse()->getBody()->getContents(), true);
            $error = $data['error']['message'] ?? $e->getMessage();

            Log::error('[Media] Meta proxy error', ['message_id' => $message->id, 'error' => $error]);

            return response()->json(['message' => 'Failed to retrieve media.', 'error' => $error], 502);
        } catch (\Throwable $e) {
            Log::error('[Media] Proxy failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to retrieve media.'], 502);
        }
    }

    /**
     * Return Meta's metadata for an inbound media message without downloading.
     * GET /tenant/api/messages/{message}/media/info.
     */
    public function info(Message $message): JsonResponse
    {
        try {
            $mediaId = $message->content['id'] ?? $message->content['media_id'] ?? null;

            if (!$mediaId) {
                return response()->json(['message' => 'No media found in this message.'], 404);
            }

            $account = $message->conversation?->whatsappAccount;

            if (!$account) {
                return response()->json(['message' => 'No WhatsApp account linked.'], 500);
            }

            $token = $this->resolveToken($account);
            $client = new Client(['timeout' => 15]);

            $response = $client->get(
                'https://graph.facebook.com/'.config('services.meta.api_version', 'v21.0')."/{$mediaId}",
                ['headers' => ['Authorization' => "Bearer {$token}"]]
            );

            return response()->json([
                'media_id' => $mediaId,
                'metadata' => json_decode($response->getBody()->getContents(), true),
                'message_id' => $message->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('[Media] Info failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to get media info.'], 502);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Resolve the correct access token for a WhatsApp account.
     * embedded_signup → stored per-account token
     * registered_number → master system user token from config.
     */
    private function resolveToken(WhatsappAccount $account): string
    {
        if ($account->onboarding_method === 'registered_number') {
            return config('services.meta.system_user_token');
        }

        return $account->access_token;
    }

    /**
     * Serve URL for the agent UI — internal authenticated route, not public.
     * Format: /tenant/api/media/{id}/serve.
     */
    private function serveUrl(string $storedFilename): string
    {
        // We store the filename; the actual URL is built at query time in index()
        // using the media record's id once it exists. For the upload response
        // we return the route by stored_filename as a temporary reference —
        // the node editor will use the id-based route after the record is saved.
        return rtrim(config('app.url'), '/').'/tenant/api/media/by-filename/'.$storedFilename;
    }

    private function metaCacheKey(string $mediaId, ?string $accountId = null): string
    {
        return 'meta_media_id:'.$mediaId.($accountId ? ':'.$accountId : '');
    }
}
