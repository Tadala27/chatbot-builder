<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookPayload;
use App\Services\Bot\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected WhatsAppWebhookService $webhookService)
    {
    }

    // GET /webhook/whatsapp
    public function verifyWhatsApp(Request $request): Response
    {
        $result = $this->webhookService->verifyWebhook([
            'hub_mode' => $request->query('hub.mode'),
            'hub_verify_token' => $request->query('hub.verify_token'),
            'hub_challenge' => $request->query('hub.challenge'),
        ]);

        return $result === 403
            ? response('Forbidden', 403)
            : response((string) $result, 200);
    }

    // POST /webhook/whatsapp
    public function handleWhatsApp(Request $request): Response
    {
        // 1. Verify signature FIRST (before even decoding the body as array)
        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256', '');
        $appSecret = config('services.whatsapp.app_secret');

        if (!empty($appSecret) && !$this->isValidSignature($rawBody, $signature, $appSecret)) {
            Log::warning('WhatsApp webhook: invalid signature', [
                'ip' => $request->ip(),
                'signature_given' => substr($signature, 0, 20).'...',
            ]);

            // Still return 200 so Meta doesn't retry — we intentionally drop it.
            return response('OK', 200);
        }

        ProcessWhatsAppWebhookPayload::dispatch($request->all())
            ->onQueue('whatsapp-webhooks');

        return response('OK', 200);
    }

    private function isValidSignature(string $rawBody, string $signatureHeader, string $appSecret): bool
    {
        if (!str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $appSecret);

        return hash_equals($expected, $signatureHeader);
    }
}