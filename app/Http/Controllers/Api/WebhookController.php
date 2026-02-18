<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppWebhookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WebhookController extends Controller
{
    protected WhatsAppWebhookService $webhookService;

    public function __construct(WhatsAppWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * Verify WhatsApp webhook (GET request from Facebook)
     */
    public function verifyWhatsApp(Request $request)
    {
        $params = [
            'hub_mode' => $request->query('hub.mode'),
            'hub_verify_token' => $request->query('hub.verify_token'),
            'hub_challenge' => $request->query('hub.challenge'),
        ];

        $result = $this->webhookService->verifyWebhook($params);

        if (is_int($result) && $result === 403) {
            return response('Forbidden', 403);
        }

        return response($result, 200);
    }

    /**
     * Handle WhatsApp webhook (POST request from Facebook)
     */
    public function handleWhatsApp(Request $request): Response
    {
        $payload = $request->all();

        // Process webhook asynchronously
        $this->webhookService->handleWebhook($payload);

        // Always return 200 OK immediately
        return response('OK', 200);
    }
}