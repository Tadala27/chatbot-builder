<?php

// app/Http/Controllers/Api/ConnectorController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ForwardConnectorWebhook;
use App\Models\WhatsappPhoneIndex;
use App\Services\WhatsAppSenderService;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectorController extends Controller
{
    public function __construct(private WhatsAppSenderService $sender)
    {
    }

    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token) {
            $exists = WhatsappPhoneIndex::where('verify_token', $token)->exists();

            if ($exists) {
                return response((string) $challenge, 200);
            }
        }

        return response('Forbidden', 403);
    }

    // ── POST /webhook/connector — Meta's inbound message delivery ─────────
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->all();
        $phoneNumberId = $this->extractPhoneNumberId($payload);

        if (!$phoneNumberId) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $indexEntry = WhatsappPhoneIndex::where('phone_number_id', $phoneNumberId)->first();

        if (!$indexEntry) {
            Log::warning("Connector webhook: no tenant mapped for phone_number_id [{$phoneNumberId}].");

            return response()->json(['status' => 'ignored'], 200);
        }

        ForwardConnectorWebhook::dispatch($indexEntry->tenant_id, $phoneNumberId, $payload);

        return response()->json(['status' => 'ok'], 200);
    }

    // ── GET /api/connector/media/{media_id} — signed, streamed media proxy ─
    //
    // Reached via a Laravel signed-URL route (must be registered with
    // ->name('connector.media.stream') and the 'signed' middleware — see
    // routes file). Laravel validates the signature + expiry automatically
    // before this method ever runs; an invalid/expired/tampered URL never
    // reaches here at all.
    //
    // SCALABILITY: this is a STREAMED response. Guzzle's 'stream' => true
    // option means the response body is read from Meta's CDN in small
    // chunks and written directly to the outgoing connection as it arrives
    // — at no point does the full file exist in PHP's memory. A 100MB
    // document moves through this proxy using only a few KB of buffer at
    // any given moment, the same way it would through nginx or any other
    // reverse proxy. This is what makes "scalable enough for Meta's max
    // sizes" actually true rather than aspirational.
    public function streamMedia(Request $request, string $media_id): StreamedResponse|JsonResponse
    {
        $token = config('services.meta.tech_provider_token');

        if (empty($token)) {
            return response()->json(['message' => 'Media proxy is not configured.'], 500);
        }

        $apiVersion = config('services.meta.api_version', 'v21.0');

        try {
            // Step 1: metadata call (tiny response) to get the actual CDN url.
            // Meta's media URLs are short-lived, so we always resolve fresh
            // here rather than trusting a URL cached from earlier — by the
            // time the tenant calls this proxy, the original CDN URL from
            // ForwardConnectorWebhook may already be stale.
            $client = new Client();
            $metaResponse = $client->get(
                "https://graph.facebook.com/{$apiVersion}/{$media_id}",
                ['headers' => ['Authorization' => "Bearer {$token}"]]
            );

            $metadata = json_decode($metaResponse->getBody()->getContents(), true);
            $cdnUrl = $metadata['url'] ?? null;
            $mimeType = $metadata['mime_type'] ?? 'application/octet-stream';

            if (!$cdnUrl) {
                return response()->json(['message' => 'Media not found or expired.'], 404);
            }

            // Step 2: stream the actual binary through, chunk by chunk.
            $streamResponse = $client->get($cdnUrl, [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'stream' => true, // <-- the key option: don't buffer the body
            ]);

            $body = $streamResponse->getBody();

            return response()->stream(function () use ($body) {
                while (!$body->eof()) {
                    // 8KB chunks — small, constant memory footprint
                    // regardless of total file size.
                    echo $body->read(8192);
                    flush();
                }
            }, 200, [
                'Content-Type' => $mimeType,
                'Content-Length' => $metadata['file_size'] ?? null,
                'Cache-Control' => 'private, max-age=1800', // matches the 30-min signed URL TTL
            ]);
        } catch (\Throwable $e) {
            Log::error('Connector media proxy failed', [
                'media_id' => $media_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to retrieve media.'], 502);
        }
    }

    // ── POST /api/connector/messages — tenant's app sending a reply ───────
    public function send(Request $request): JsonResponse
    {
        /** @var \App\Models\WhatsappAccount $account */
        $account = $request->attributes->get('connectorAccount');

        $validated = $request->validate([
            'to' => ['required', 'string'],
            'type' => ['sometimes', 'in:text,image,document,template'],
            'text' => ['required_if:type,text', 'string', 'max:4096'],
            'media_url' => ['required_if:type,image,document', 'string'],
            'caption' => ['sometimes', 'string'],
            'template_name' => ['required_if:type,template', 'string'],
            'template_params' => ['sometimes', 'array'],
        ]);

        $type = $validated['type'] ?? 'text';

        try {
            $result = $this->sender->send($account, $validated['to'], $type, $validated);

            return response()->json([
                'success' => true,
                'message_id' => $result['message_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message.',
            ], 502);
        }
    }

    private function extractPhoneNumberId(array $payload): ?string
    {
        return $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null;
    }
}