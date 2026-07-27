<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageSent;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConnectorController extends Controller
{
    public function streamMedia(Request $request, string $media_id): StreamedResponse|JsonResponse
    {
        $token = config('services.meta.tech_provider_token');

        if (empty($token)) {
            return response()->json(['message' => 'Media proxy is not configured.'], 500);
        }

        $apiVersion = config('services.meta.api_version', 'v21.0');

        try {
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

            $streamResponse = $client->get($cdnUrl, [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'stream' => true,
            ]);

            $body = $streamResponse->getBody();

            return response()->stream(function () use ($body) {
                while (!$body->eof()) {
                    echo $body->read(8192);
                    flush();
                }
            }, 200, [
                'Content-Type' => $mimeType,
                'Content-Length' => $metadata['file_size'] ?? null,
                'Cache-Control' => 'private, max-age=1800',
            ]);
        } catch (\Throwable $e) {
            Log::error('Connector media proxy failed', [
                'media_id' => $media_id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to retrieve media.'], 502);
        }
    }

    public function send(Request $request): JsonResponse
    {
        /** @var WhatsappAccount $account */
        $account = $request->attributes->get('connectorAccount');

        if (!$account) {
            return response()->json(['success' => false, 'message' => 'Account not resolved from connector key.'], 401);
        }

        $validated = $request->validate([
            'to' => ['required', 'string'],
            'type' => ['sometimes', 'in:text,image,document,template'],
            'text' => ['required_if:type,text', 'nullable', 'string', 'max:4096'],
            'media_url' => ['required_if:type,image,document', 'nullable', 'string'],
            'caption' => ['sometimes', 'nullable', 'string'],
            'filename' => ['sometimes', 'nullable', 'string'],
            'template_name' => ['required_if:type,template', 'nullable', 'string'],
            'template_params' => ['sometimes', 'array'],
        ]);

        $type = $validated['type'] ?? 'text';
        $to = $validated['to'];

        try {
            // ── 1. Send to Meta ───────────────────────────────────────────────
            $wamid = $this->sendToMeta($account, $to, $type, $validated);

            // ── 2. Find or create conversation ────────────────────────────────
            $conversation = $this->findOrCreateConversation($account, $to);

            // ── 3. Record outbound message ────────────────────────────────────
            $content = $this->buildContent($type, $validated);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'whatsapp_message_id' => $wamid,
                'direction' => 'outbound',
                'message_type' => $type === 'template' ? 'text' : $type,
                'content' => $content,
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => ['sender_type' => 'connector'],
            ]);

            $conversation->increment('message_count');
            $conversation->update(['last_message_at' => now()]);

            // ── 4. Broadcast to the agent inbox ───────────────────────────────
            try {
                broadcast(new MessageSent($message, $conversation->fresh()));
            } catch (\Throwable $e) {
                Log::warning('Connector: MessageSent broadcast failed', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json([
                'success' => true,
                'message_id' => $wamid,
            ]);
        } catch (\Throwable $e) {
            Log::error('Connector send failed', [
                'account_id' => $account->id,
                'to' => $to,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    // =========================================================================
    // PRIVATE
    // =========================================================================

    /**
     * Send the message to Meta's Cloud API and return the wamid.
     */
    private function sendToMeta(WhatsappAccount $account, string $to, string $type, array $data): string
    {
        $apiVersion = config('services.meta.api_version', 'v21.0');
        $token = $this->resolveToken($account);

        $client = new Client(['base_uri' => "https://graph.facebook.com/{$apiVersion}/", 'timeout' => 30]);
        $payload = $this->buildMetaPayload($to, $type, $data);

        $response = $client->post("{$account->phone_number_id}/messages", [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $body = json_decode($response->getBody()->getContents(), true);
        $wamid = $body['messages'][0]['id'] ?? null;

        if (!$wamid) {
            throw new \RuntimeException('Meta API returned no message ID. Response: '.json_encode($body));
        }

        return $wamid;
    }

    /**
     * Find the most recent active conversation for this contact on this account,
     * or create a new one. Connector conversations are always handed_off — the
     * external system drives them, not our bot.
     */
    private function findOrCreateConversation(WhatsappAccount $account, string $to): Conversation
    {
        $conversation = Conversation::where('whatsapp_account_id', $account->id)
            ->where('whatsapp_user_phone', $to)
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return Conversation::create([
            'whatsapp_account_id' => $account->id,
            'whatsapp_user_phone' => $to,
            'status' => 'handed_off', // connector owns this conversation
            'started_at' => now(),
            'last_message_at' => now(),
            'metadata' => ['source' => 'connector'],
        ]);
    }

    /**
     * Build the content array stored in the messages table.
     */
    private function buildContent(string $type, array $data): array
    {
        return match ($type) {
            'text' => ['text' => $data['text']],
            'image' => ['link' => $data['media_url'], 'caption' => $data['caption'] ?? null, 'mime_type' => 'image/jpeg'],
            'document' => ['link' => $data['media_url'], 'caption' => $data['caption'] ?? null, 'filename' => $data['filename'] ?? null, 'mime_type' => 'application/octet-stream'],
            'template' => ['text' => $data['template_name'], 'template_params' => $data['template_params'] ?? []],
            default => $data,
        };
    }

    /**
     * Build the payload for Meta's messages API.
     */
    private function buildMetaPayload(string $to, string $type, array $data): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ];

        return match ($type) {
            'text' => array_merge($base, [
                'type' => 'text',
                'text' => ['body' => $data['text'], 'preview_url' => true],
            ]),
            'image' => array_merge($base, [
                'type' => 'image',
                'image' => array_filter([
                    'link' => $data['media_url'],
                    'caption' => $data['caption'] ?? null,
                ]),
            ]),
            'document' => array_merge($base, [
                'type' => 'document',
                'document' => array_filter([
                    'link' => $data['media_url'],
                    'caption' => $data['caption'] ?? null,
                    'filename' => $data['filename'] ?? null,
                ]),
            ]),
            'template' => array_merge($base, [
                'type' => 'template',
                'template' => [
                    'name' => $data['template_name'],
                    'language' => ['code' => 'en_US'],
                    'components' => $this->buildTemplateComponents($data['template_params'] ?? []),
                ],
            ]),
            default => $base,
        };
    }

    private function buildTemplateComponents(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        return [[
            'type' => 'body',
            'parameters' => array_map(
                fn ($p) => ['type' => 'text', 'text' => (string) $p],
                $params
            ),
        ]];
    }

    private function resolveToken(WhatsappAccount $account): string
    {
        if ($account->onboarding_method === 'registered_number') {
            $token = config('services.meta.system_user_token');
            if (empty($token)) {
                throw new \RuntimeException('WHATSAPP_SYSTEM_USER_TOKEN is not configured.');
            }

            return $token;
        }

        if (empty($account->access_token)) {
            throw new \RuntimeException("WhatsApp account [{$account->id}] has no access token.");
        }

        return $account->access_token;
    }
}