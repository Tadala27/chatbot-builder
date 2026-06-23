<?php

// app/Jobs/ForwardConnectorWebhook.php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\WhatsappAccount;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Forwards a raw Meta webhook payload to a connector tenant's webhook_url.
 *
 * MEDIA HANDLING — REWRITTEN for scalability:
 *
 *   Previous version downloaded the FULL media binary here (Http::get on
 *   the CDN URL), base64-encoded it, and embedded it directly in the JSON
 *   payload forwarded to the tenant. This breaks down hard at scale:
 *     - A 100MB document (Meta's actual max) becomes ~133MB of base64 JSON
 *     - That JSON sits fully in memory in THIS job, then again in Guzzle's
 *       request buffer, then again in the tenant's controller after
 *       $request->all() decodes it — three full in-memory copies minimum
 *     - Any timeout, ngrok bandwidth cap, or PHP memory_limit along that
 *       chain silently drops the message with no useful error — which is
 *       exactly what happened with the 2MB PDF: base64 inflation pushed it
 *       to ~2.7MB, and SOMETHING in that chain (Guzzle timeout, ngrok cap,
 *       or php.ini limits) ate it silently.
 *
 *   NEW approach: never touch the binary at all here. Only call Meta's
 *   metadata endpoint (GET /{media_id}) to confirm the file exists and get
 *   its mime_type/size — this response is tiny (a JSON object with a few
 *   fields, not the file itself). Then generate a SIGNED, EXPIRING proxy
 *   URL pointing back at THIS platform's own
 *   GET /api/connector/media/{media_id} endpoint (see ConnectorController::
 *   streamMedia()) and forward that instead of any binary data.
 *
 *   The tenant does a plain GET against that proxy URL whenever it's ready
 *   — no token needed on their side, and the actual file bytes are
 *   STREAMED through the proxy (see streamMedia()) rather than ever being
 *   fully buffered in PHP memory on ANY of the three processes involved.
 *
 *   Your tech_provider_token never leaves this server. The tenant gets a
 *   URL that only works for this specific media_id, only for a limited
 *   time (signed URL expiry), and only ever returns this one file.
 */
class ForwardConnectorWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 10;

    /** Meta message types that carry a media block. */
    private const MEDIA_TYPES = ['image', 'document', 'audio', 'video', 'sticker'];

    /** How long the proxy URL stays valid — generous for slow tenant bots, short enough to limit exposure. */
    private const PROXY_URL_TTL_MINUTES = 30;

    public function __construct(
        public string $tenantId,
        public string $phoneNumberId,
        public array $payload,
    ) {
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->tenantId);

        if (!$tenant || !$tenant->is_active) {
            Log::warning("ForwardConnectorWebhook: tenant [{$this->tenantId}] not found or inactive.");

            return;
        }

        tenancy()->initialize($tenant);

        try {
            $account = WhatsappAccount::where('phone_number_id', $this->phoneNumberId)->first();

            if (!$account || !$account->webhook_url) {
                Log::warning("ForwardConnectorWebhook: no webhook_url for phone_number_id [{$this->phoneNumberId}] in tenant [{$this->tenantId}].");

                return;
            }

            $forwardPayload = $this->resolveMediaMetadata($this->payload);

            $this->forward($account->webhook_url, $forwardPayload);
        } finally {
            tenancy()->end();
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MEDIA METADATA RESOLUTION  (no binary download here — metadata only)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * If the payload contains a media message, fetch its metadata (mime
     * type, size) from Meta — a small JSON response, NOT the file itself —
     * then attach a signed proxy URL the tenant can stream the actual file
     * from whenever it's ready. Returns the payload unchanged for text
     * messages or if resolution fails.
     */
    private function resolveMediaMetadata(array $payload): array
    {
        $message = $payload['entry'][0]['changes'][0]['value']['messages'][0] ?? null;

        if (!$message) {
            return $payload;
        }

        $type = $message['type'] ?? 'text';

        if (!in_array($type, self::MEDIA_TYPES, true)) {
            return $payload;
        }

        $mediaBlock = $message[$type] ?? [];
        $mediaId = $mediaBlock['id'] ?? null;

        if (!$mediaId) {
            return $payload;
        }

        $token = config('services.meta.tech_provider_token');

        if (empty($token)) {
            Log::warning('ForwardConnectorWebhook: tech_provider_token not configured — media cannot be resolved.', [
                'media_id' => $mediaId,
            ]);

            return $payload;
        }

        try {
            $apiVersion = config('services.meta.api_version', 'v21.0');

            // Metadata call only — returns {url, mime_type, sha256, file_size, id}
            // as a small JSON object. The 'url' field is Meta's CDN location for
            // the binary, but we do NOT fetch it here; streamMedia() does that
            // later, on demand, streamed.
            $response = Http::withToken($token)
                ->timeout(8)
                ->get("https://graph.facebook.com/{$apiVersion}/{$mediaId}");

            if (!$response->successful()) {
                Log::warning('ForwardConnectorWebhook: media metadata fetch failed', [
                    'media_id' => $mediaId,
                    'status' => $response->status(),
                ]);

                return $payload;
            }

            $metadata = $response->json();

            if (empty($metadata['url'])) {
                Log::warning('ForwardConnectorWebhook: meta returned no URL for media_id', ['media_id' => $mediaId]);

                return $payload;
            }

            // Signed, expiring, scoped to THIS media_id only. Laravel's
            // signed-route mechanism handles the HMAC + expiry check on
            // verification — see ConnectorController::streamMedia().
            $proxyUrl = URL::temporarySignedRoute(
                'connector.media.stream',
                now()->addMinutes(self::PROXY_URL_TTL_MINUTES),
                ['media_id' => $mediaId]
            );

            $payload['_resolved_media'] = [
                'type' => $type,
                'mime_type' => $mediaBlock['mime_type'] ?? $metadata['mime_type'] ?? null,
                'filename' => $mediaBlock['filename'] ?? null,
                'caption' => $mediaBlock['caption'] ?? null,
                'size' => $metadata['file_size'] ?? null,
                // The tenant fetches THIS, not Meta's URL directly — no
                // token needed on their end, file is streamed not buffered.
                'download_url' => $proxyUrl,
                'expires_in_minutes' => self::PROXY_URL_TTL_MINUTES,
            ];

            Log::debug('ForwardConnectorWebhook: media metadata resolved, proxy URL issued', [
                'media_id' => $mediaId,
                'type' => $type,
                'size' => $metadata['file_size'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ForwardConnectorWebhook: media metadata resolution threw', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
        }

        return $payload;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FORWARDING — payload is now small (metadata + URL), never the file itself
    // ─────────────────────────────────────────────────────────────────────────

    private function forward(string $webhookUrl, array $payload): void
    {
        try {
            $client = new Client(['timeout' => 5, 'connect_timeout' => 3]);
            $client->post($webhookUrl, ['json' => $payload]);

            Log::info('Connector webhook forwarded', ['url' => $webhookUrl]);
        } catch (\Throwable $e) {
            Log::warning('Connector webhook forward failed', [
                'url' => $webhookUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}