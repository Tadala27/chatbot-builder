<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bot;
use App\Models\BotMediaFile;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Bot\WhatsAppMessageService;
use App\Services\Tenant\TenantStorageManager;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    private const META_MEDIA_ID_CACHE_TTL = 6 * 60 * 60;

    // ── Storage: upload / list / delete / serve ───────────────────────────

    /**
     * Upload a BotMediaFile (used by the bot flow node editor).
     * Files are stored on the tenant's S3 path: bot-media/{bot_id}/{uuid}.ext.
     */
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
                'message' => "Invalid file type for {$type}. Allowed: ".(self::EXTENSION_HINTS[$type] ?? 'unknown'),
            ], 422);
        }

        $maxBytes = self::MAX_SIZES[$type];
        if ($size > $maxBytes) {
            return response()->json([
                'message' => 'File too large. Maximum size for '.$type.' is '.round($maxBytes / 1024 / 1024).' MB.',
            ], 422);
        }

        $storedFilename = Str::uuid().'.'.$file->getClientOriginalExtension();
        $directory = "bot-media/{$bot->id}";
        $storagePath = "{$directory}/{$storedFilename}";

        try {
            TenantStorageManager::store($file, $directory, $storedFilename);
        } catch (\Exception $e) {
            Log::error('[Media] S3 upload failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Storage upload failed: '.$e->getMessage()], 500);
        }

        $media = BotMediaFile::create([
            'user_id' => auth()->id(),
            'bot_id' => $bot->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_filename' => $storedFilename,
            'disk' => 'tenant',
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
        try {
            if ($media->disk === 'tenant') {
                TenantStorageManager::delete($media->path);
            } elseif (TenantStorageManager::exists($media->path)) {
                TenantStorageManager::delete($media->path);
            }
        } catch (\Exception $e) {
            Log::warning('[Media] S3 delete failed (proceeding with DB delete)', ['error' => $e->getMessage()]);
        }

        $messageService = app(WhatsAppMessageService::class);
        $media->bot->whatsappAccount?->tap(function ($account) use ($media, $messageService) {
            Cache::forget($messageService->metaMediaCacheKey($media->id, $account->id));
        });

        $media->delete();

        return response()->json(['message' => 'Media deleted.']);
    }

    /**
     * Serve a media file by stored filename.
     *
     * For S3-backed files: redirect to a fresh pre-signed URL.
     * The browser downloads directly from S3 — no memory pressure on the app.
     *
     * For legacy public-disk files (pre-S3 migration): stream through PHP.
     */
    public function serveFile(string $filename): RedirectResponse|StreamedResponse|Response
    {
        // Bot media library
        $media = BotMediaFile::where('stored_filename', $filename)->whereNull('deleted_at')->first();
        if ($media) {
            return $this->serve($media);
        }

        // Inbox / inbound message media
        $message = Message::where('content->stored_filename', $filename)->first();
        if ($message) {
            $content = $message->content;
            $storagePath = $content['storage_path'] ?? null;

            if (!$storagePath) {
                abort(404, 'Media file not found.');
            }

            // S3-backed (storage_driver === 'tenant' or file exists on S3)
            if (($content['storage_driver'] ?? '') === 'tenant' || TenantStorageManager::exists($storagePath)) {
                $signedUrl = TenantStorageManager::temporaryUrl($storagePath, minutes: 15);

                return redirect()->away($signedUrl);
            }

            // Legacy: file on public disk (pre-S3 migration)
            abort(404, 'Media file not found.');
        }

        abort(404, 'Media file not found.');
    }

    /**
     * Serve a BotMediaFile.
     * S3: redirect to signed URL. Legacy public disk: stream through PHP.
     */
    public function serve(BotMediaFile $media): RedirectResponse|StreamedResponse|Response
    {
        $path = $media->path;

        // S3-backed file
        if ($media->disk === 'tenant' || TenantStorageManager::exists($path)) {
            $signedUrl = TenantStorageManager::temporaryUrl($path, minutes: 60);

            return redirect()->away($signedUrl);
        }

        abort(404, 'File not found.');
    }

    /**
     * Streaming variant for video/audio (supports range requests).
     * S3 handles range requests natively — just redirect.
     */
    public function serveStream(BotMediaFile $media): RedirectResponse|StreamedResponse|Response
    {
        return $this->serve($media);
    }

    // ── WhatsApp Cloud API: upload BotMediaFile to Meta ──────────────────

    /**
     * POST /tenant/media/{media}/meta-upload.
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
            Log::info('[Media] Returning cached Meta media_id', ['media_id' => $media->id, 'meta_media_id' => $cached]);

            return response()->json(['media_id' => $cached, 'cached' => true]);
        }

        $path = $media->path;

        // Resolve stream from S3 or legacy disk
        if ($media->disk === 'tenant' || TenantStorageManager::exists($path)) {
            if (!TenantStorageManager::exists($path)) {
                return response()->json(['message' => 'File not found in storage.'], 404);
            }
            $stream = TenantStorageManager::disk()->readStream($path);
        } else {
            return response()->json(['message' => 'File not found in storage.'], 404);
        }

        $token = $this->resolveToken($whatsappAccount);
        $phoneNumberId = $whatsappAccount->phone_number_id;
        $apiVersion = config('services.meta.api_version', 'v21.0');

        try {
            $client = new Client(['timeout' => 60]);
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
                        ['name' => 'type',              'contents' => $media->mime_type],
                        ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);
            $mediaId = $data['id'] ?? null;

            if (!$mediaId) {
                Log::error('[Media] Meta upload returned no media_id', ['response' => $data, 'file' => $media->stored_filename]);

                return response()->json(['message' => 'Meta returned no media_id.'], 502);
            }

            Cache::put($cacheKey, $mediaId, self::META_MEDIA_ID_CACHE_TTL);

            Log::info('[Media] Uploaded to Meta successfully', [
                'media_id' => $media->id,
                'meta_media_id' => $mediaId,
                'file' => $media->stored_filename,
            ]);

            return response()->json(['media_id' => $mediaId, 'cached' => false]);
        } catch (ClientException $e) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $error = $body['error']['message'] ?? $e->getMessage();
            Log::error('[Media] Meta upload failed', ['file' => $media->stored_filename, 'error' => $error]);

            return response()->json(['message' => 'Failed to upload to Meta: '.$error], 502);
        } catch (\Throwable $e) {
            Log::error('[Media] Meta upload exception', ['file' => $media->stored_filename, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Media upload failed: '.$e->getMessage()], 500);
        } finally {
            if (isset($stream) && is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    // ── WhatsApp Cloud API: proxy inbound media (last-resort fallback) ────

    /**
     * GET /tenant/messages/{message}/media
     * Used only when S3 download failed at webhook time and no local copy exists.
     */
    public function stream(Request $request, Message $message): StreamedResponse|JsonResponse|RedirectResponse
    {
        // First: if we have a locally stored copy, redirect to S3
        $storagePath = $message->content['storage_path'] ?? null;
        if ($storagePath && TenantStorageManager::exists($storagePath)) {
            $signedUrl = TenantStorageManager::temporaryUrl($storagePath, minutes: 30);

            return redirect()->away($signedUrl);
        }

        // Fallback: proxy from Meta's CDN
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

    private function resolveToken(WhatsappAccount $account): string
    {
        if ($account->onboarding_method === 'registered_number') {
            return config('services.meta.system_user_token');
        }

        return $account->access_token;
    }

    private function serveUrl(string $storedFilename): string
    {
        $tenant = tenant();
        $domain = $tenant->primaryDomain();

        if (!$domain) {
            throw new \RuntimeException('Tenant has no primary domain configured.');
        }

        return rtrim($domain->url, '/').'/tenant/media/file/'.$storedFilename;
    }

    private function metaCacheKey(string $mediaId, ?string $accountId = null): string
    {
        return 'meta_media_id:'.$mediaId.($accountId ? ':'.$accountId : '');
    }
}