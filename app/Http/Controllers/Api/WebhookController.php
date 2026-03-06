<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function __construct(protected WhatsAppWebhookService $webhookService) {}

    // GET /webhook/whatsapp  — Facebook verification handshake
    public function verifyWhatsApp(Request $request): Response
    {
        Log::debug('WhatsApp webhook verification request', $request->query());

        $result = $this->webhookService->verifyWebhook([
            'hub_mode'         => $request->query('hub.mode'),
            'hub_verify_token' => $request->query('hub.verify_token'),
            'hub_challenge'    => $request->query('hub.challenge'),
        ]);

        if ($result === 403) {
            return response('Forbidden', 403);
        }

        return response((string) $result, 200);
    }

    // POST /webhook/whatsapp  — Inbound messages and status updates
    public function handleWhatsApp(Request $request): Response
    {
        // Always acknowledge immediately; process asynchronously
        $this->webhookService->handleWebhook($request->all());

        return response('OK', 200);
    }
}
