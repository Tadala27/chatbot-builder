<?php

namespace App\Services;

use App\Models\WhatsappAccount;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    /**
     * Verify webhook (GET request from Facebook)
     */
    public function verifyWebhook(array $params): string|int
    {
        $mode = $params['hub_mode'] ?? '';
        $token = $params['hub_verify_token'] ?? '';
        $challenge = $params['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token) {
            // Verify token matches one of our accounts
            $account = WhatsappAccount::where('webhook_verify_token', $token)->first();

            if ($account) {
                Log::info("Webhook verified for WABA", [
                    'waba_id' => $account->waba_id,
                    'phone_number' => $account->phone_number,
                ]);
                return (int) $challenge;
            }
        }

        Log::warning('Webhook verification failed', [
            'mode' => $mode,
            'token_provided' => !empty($token),
        ]);

        return 403;
    }

    /**
     * Handle incoming webhook (POST request from Facebook)
     */
    public function handleWebhook(array $payload): void
    {
        if (!isset($payload['entry'])) {
            Log::warning('Webhook payload missing entry field');
            return;
        }

        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if ($change['field'] !== 'messages') {
                    continue;
                }

                $value = $change['value'];

                // Handle different event types
                if (isset($value['messages'])) {
                    $this->handleIncomingMessage($value);
                }

                if (isset($value['statuses'])) {
                    $this->handleMessageStatus($value);
                }
            }
        }
    }

    /**
     * Handle incoming message
     */
    private function handleIncomingMessage(array $data): void
    {
        try {
            $metadata = $data['metadata'];
            $message = $data['messages'][0];
            $contact = $data['contacts'][0] ?? null;

            // Find WhatsApp account
            $account = WhatsappAccount::where('phone_number_id', $metadata['phone_number_id'])->first();

            if (!$account) {
                Log::warning("WhatsApp account not found", [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);
                return;
            }

            if (!$account->is_active) {
                Log::info("Message received for inactive account", [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);
                return;
            }

            // Get active chatbot for this account
            $flow = $account->flows()->published()->first();

            // Find or create conversation
            $conversation = Conversation::firstOrCreate(
                [
                    'whatsapp_account_id' => $account->id,
                    'whatsapp_user_phone' => $message['from'],
                ],
                [
                    'tenant_id' => $account->tenant_id,
                    '_id' => $flow?->id,
                    'whatsapp_user_name' => $contact['profile']['name'] ?? null,
                    'status' => 'active',
                    'started_at' => now(),
                    'last_message_at' => now(),
                ]
            );

            // If conversation was previously ended, reopen it
            if (in_array($conversation->status, ['completed', 'abandoned'])) {
                $conversation->update([
                    'status' => 'active',
                    'flow_id' => $flow?->id,
                    'started_at' => now(),
                ]);
            }

            // Store message
            $storedMessage = Message::create([
                'conversation_id' => $conversation->id,
                'whatsapp_message_id' => $message['id'],
                'direction' => 'inbound',
                'message_type' => $message['type'],
                'content' => $this->extractMessageContent($message),
                'status' => 'delivered',
                'sent_at' => now(),
                'delivered_at' => now(),
            ]);

            // Update conversation
            $conversation->increment('message_count');
            $conversation->update(['last_message_at' => now()]);

            Log::info('Message received', [
                'conversation_id' => $conversation->id,
                'message_id' => $storedMessage->id,
                'from' => $message['from'],
                'type' => $message['type'],
            ]);

            // Process message with chatbot (if conversation is active and has chatbot)
            if ($conversation->status === 'active' && $conversation->_id) {
                dispatch(new \App\Jobs\ProcessChatbotMessage($conversation, $storedMessage));
            }
        } catch (\Exception $e) {
            Log::error('Error handling incoming message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Handle message status update
     */
    private function handleMessageStatus(array $data): void
    {
        try {
            foreach ($data['statuses'] as $status) {
                $message = Message::where('whatsapp_message_id', $status['id'])->first();

                if ($message) {
                    $updates = [
                        'status' => $status['status'],
                    ];

                    if ($status['status'] === 'delivered' && !$message->delivered_at) {
                        $updates['delivered_at'] = now();
                    }

                    if ($status['status'] === 'read' && !$message->read_at) {
                        $updates['read_at'] = now();
                    }

                    if ($status['status'] === 'failed') {
                        $updates['error_message'] = $status['errors'][0]['title'] ?? 'Message failed';
                    }

                    $message->update($updates);

                    Log::debug('Message status updated', [
                        'message_id' => $message->id,
                        'status' => $status['status'],
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error handling message status', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Extract content based on message type
     */
    private function extractMessageContent(array $message): array
    {
        return match ($message['type']) {
            'text' => [
                'text' => $message['text']['body'],
            ],
            'interactive' => [
                'type' => $message['interactive']['type'],
                'response' => $message['interactive']['button_reply'] ??
                    $message['interactive']['list_reply'] ?? null,
            ],
            'button' => [
                'text' => $message['button']['text'],
                'payload' => $message['button']['payload'],
            ],
            'image', 'video', 'audio', 'document' => [
                'id' => $message[$message['type']]['id'],
                'mime_type' => $message[$message['type']]['mime_type'],
                'caption' => $message[$message['type']]['caption'] ?? null,
                'sha256' => $message[$message['type']]['sha256'] ?? null,
            ],
            'location' => [
                'latitude' => $message['location']['latitude'],
                'longitude' => $message['location']['longitude'],
                'name' => $message['location']['name'] ?? null,
                'address' => $message['location']['address'] ?? null,
            ],
            'contacts' => [
                'contacts' => $message['contacts'],
            ],
            'sticker' => [
                'id' => $message['sticker']['id'],
                'mime_type' => $message['sticker']['mime_type'],
            ],
            default => $message,
        };
    }

    /**
     * Download media from WhatsApp
     */
    public function downloadMedia(WhatsappAccount $account, string $mediaId): ?array
    {
        try {
            $client = new \GuzzleHttp\Client();

            // Get media URL
            $response = $client->get("https://graph.facebook.com/v18.0/{$mediaId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                ],
            ]);

            $mediaData = json_decode($response->getBody()->getContents(), true);
            $mediaUrl = $mediaData['url'];

            // Download media
            $response = $client->get($mediaUrl, [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                ],
            ]);

            return [
                'content' => $response->getBody()->getContents(),
                'mime_type' => $mediaData['mime_type'],
                'file_size' => $mediaData['file_size'],
            ];
        } catch (\Exception $e) {
            Log::error('Error downloading media', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}