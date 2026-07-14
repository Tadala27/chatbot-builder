<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppWebhookPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function verifyWhatsApp(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        // Log::warning('WhatsApp webhook verification', [
        //     'token' => $verifyToken,
        //     'token_provided' => $token,
        // ]);

        if ($mode === 'subscribe' && $token) {
            $exists = $verifyToken === $token;

            if ($exists) {
                return response((string) $challenge, 200);
            }
        }

        Log::warning('WhatsApp webhook verification failed', [
            'mode' => $mode,
            'token_provided' => !empty($token),
        ]);

        return response('Forbidden', 403);
    }

    public function handleWhatsApp(Request $request): Response
    {
        Log::info('WhatsApp webhook received', [
            'phone_number_id' => $request->input('entry.0.changes.0.value.metadata.phone_number_id'),
            'field' => $request->input('entry.0.changes.0.field'),
            'has_messages' => !empty($request->input('entry.0.changes.0.value.messages')),
            'has_statuses' => !empty($request->input('entry.0.changes.0.value.statuses')),
        ]);

        $rawBody = $request->getContent();
        $signature = $request->header('X-Hub-Signature-256', '');
        $appSecret = config('services.meta.app_secret');

        if (!empty($appSecret) && !$this->isValidSignature($rawBody, $signature, $appSecret)) {
            Log::warning('WhatsApp webhook: invalid signature', [
                'ip' => $request->ip(),
                'signature_given' => substr($signature, 0, 20).'...',
            ]);

            return response('OK', 200);
        }

        ProcessWhatsAppWebhookPayload::dispatch($request->all())
            ->onQueue('whatsapp-webhooks');

        Log::info('WhatsApp webhook processed');

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
