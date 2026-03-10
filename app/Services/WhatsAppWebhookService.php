<?php

namespace App\Services;

use App\Events\ContactTyping;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    // =========================================================================
    // WEBHOOK VERIFICATION  (GET from Facebook)
    // =========================================================================

    public function verifyWebhook(array $params): string|int
    {
        $mode      = $params['hub_mode']         ?? '';
        $token     = $params['hub_verify_token'] ?? '';
        $challenge = $params['hub_challenge']    ?? '';

        if ($mode === 'subscribe' && $token) {
            $account = WhatsappAccount::where('webhook_verify_token', $token)->first();

            if ($account) {
                Log::info('Webhook verified', [
                    'waba_id'      => $account->waba_id,
                    'phone_number' => $account->phone_number,
                ]);
                return (int) $challenge;
            }
        }

        Log::warning('Webhook verification failed', [
            'mode'           => $mode,
            'token_provided' => !empty($token),
        ]);

        return 403;
    }

    // =========================================================================
    // WEBHOOK HANDLER  (POST from Facebook)
    // =========================================================================

    public function handleWebhook(array $payload): void
    {
        if (!isset($payload['entry'])) {
            Log::warning('Webhook payload missing entry field');
            return;
        }

        foreach ($payload['entry'] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? '') !== 'messages') continue;

                $value = $change['value'];

                if (isset($value['messages'])) {
                    $this->handleIncomingMessage($value);
                }

                if (isset($value['statuses'])) {
                    $this->handleMessageStatus($value);
                }
            }
        }
    }

    // =========================================================================
    // INCOMING MESSAGE
    // =========================================================================

    private function handleIncomingMessage(array $data): void
    {
        try {
            $metadata = $data['metadata'];
            $message  = $data['messages'][0];
            $contact  = $data['contacts'][0] ?? null;

            // 1. Find the WhatsApp account
            $account = WhatsappAccount::where('phone_number_id', $metadata['phone_number_id'])->first();

            if (!$account) {
                Log::warning('WhatsApp account not found for phone_number_id', [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);
                return;
            }

            if (!$account->is_active) {
                Log::info('Message received for inactive account', [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);
                return;
            }

            // 2. Find an active bot on this account that has a published flow.
            //    Schema: whatsapp_account → bots → flows
            $publishedFlow = null;
            $bot           = null;

            foreach ($account->bots()->where('is_active', true)->get() as $candidate) {
                $flow = $candidate->flows()
                    ->where('status', 'published')
                    ->where('is_active', true)
                    ->latest('published_at')
                    ->first();

                if ($flow) {
                    $bot           = $candidate;
                    $publishedFlow = $flow;
                    break;
                }
            }

            if (!$publishedFlow || !$bot) {
                Log::warning('No active bot with a published flow found for account', [
                    'account_id'   => $account->id,
                    'phone_number' => $account->phone_number,
                ]);
                return;
            }

            $publishedVersion = $publishedFlow->currentPublishedVersion;

            if (!$publishedVersion) {
                Log::warning('Published flow has no version', [
                    'flow_id'   => $publishedFlow->id,
                    'flow_name' => $publishedFlow->name,
                ]);
                return;
            }

            // 3. Find or create conversation
            $conversation = Conversation::firstOrCreate(
                [
                    'whatsapp_account_id' => $account->id,
                    'whatsapp_user_phone' => $message['from'],
                    'status'              => 'active',     // scope: only resume open conversations
                ],
                [
                    'tenant_id'          => $account->tenant_id,
                    'flow_id'            => $publishedFlow->id,
                    'flow_version_id'    => $publishedVersion->id,
                    'whatsapp_user_name' => $contact['profile']['name'] ?? null,
                    'status'             => 'active',
                    'started_at'         => now(),
                    'last_message_at'    => now(),
                ]
            );

            // Reopen ended conversations with the current flow
            if (in_array($conversation->status, ['completed', 'abandoned'], true)) {
                $conversation->update([
                    'status'          => 'active',
                    'flow_id'         => $publishedFlow->id,
                    'flow_version_id' => $publishedVersion->id,
                    'started_at'      => now(),
                    'last_message_at' => now(),
                ]);
            }

            // 4. Persist message (deduplicate by WhatsApp message ID)
            $storedMessage = Message::firstOrCreate(
                ['whatsapp_message_id' => $message['id']],
                [
                    'conversation_id' => $conversation->id,
                    'direction'       => 'inbound',
                    'message_type'    => $message['type'],
                    'content'         => $this->extractMessageContent($message),
                    'status'          => 'delivered',
                    'sent_at'         => now(),
                    'delivered_at'    => now(),
                ]
            );

            if (!$storedMessage->wasRecentlyCreated) {
                Log::info('Duplicate webhook message — already processed', [
                    'whatsapp_message_id' => $message['id'],
                ]);
                return;
            }

            $conversation->increment('message_count');
            $conversation->update(['last_message_at' => now()]);

            // 5. Broadcast to Pusher so the inbox sidebar + chat window update instantly
            try {
                broadcast(new MessageSent($storedMessage, $conversation->fresh()));
            } catch (\Exception $broadcastEx) {
                Log::warning('Failed to broadcast inbound MessageSent', [
                    'message_id' => $storedMessage->id,
                    'error'      => $broadcastEx->getMessage(),
                ]);
            }

            // 6. Dispatch flow execution job
            if ($conversation->status === 'active' && $conversation->flow_id) {
                dispatch(new \App\Jobs\ProcessChatbotMessage($conversation, $storedMessage));
            }
        } catch (\Exception $e) {
            Log::error('Error handling incoming message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data'  => $data,
            ]);
        }
    }

    // =========================================================================
    // STATUS UPDATES
    // =========================================================================

    private function handleMessageStatus(array $data): void
    {
        try {
            foreach ($data['statuses'] as $statusData) {
                $newStatus = $statusData['status']; // sent | delivered | read | failed | typing | deleted

                // ── Contact typing indicator ──────────────────────────────────
                // WhatsApp Cloud API sends status="typing" when the contact is
                // composing a reply. There is no "stopped typing" event — the
                // frontend auto-clears after a timeout.
                if ($newStatus === 'typing') {
                    $this->handleContactTyping($statusData, $data['metadata'] ?? []);
                    continue;
                }

                // ── Message delivery / read status ────────────────────────────
                $message = Message::where('whatsapp_message_id', $statusData['id'])->first();

                if (!$message) continue;

                $updates = ['status' => $newStatus];

                if ($newStatus === 'delivered' && !$message->delivered_at) {
                    $updates['delivered_at'] = now();
                }

                if ($newStatus === 'read' && !$message->read_at) {
                    $updates['read_at'] = now();
                }

                if ($newStatus === 'failed') {
                    $updates['error_message'] = $statusData['errors'][0]['title'] ?? 'Message failed';
                }

                $message->update($updates);

                // Broadcast so the tick marks in the inbox update in real time
                try {
                    broadcast(new MessageStatusUpdated($message->fresh()));
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast MessageStatusUpdated', [
                        'message_id' => $message->id,
                        'error'      => $e->getMessage(),
                    ]);
                }

                Log::debug('Message status updated', [
                    'message_id' => $message->id,
                    'status'     => $newStatus,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error handling message status', [
                'error' => $e->getMessage(),
                'data'  => $data,
            ]);
        }
    }

    /**
     * Resolve the conversation from a typing status event and broadcast
     * ContactTyping so the inbox shows "Contact is typing…" in real time.
     *
     * The typing status payload looks like:
     *   { "id": "<wamid>", "status": "typing", "recipient_id": "<contact_phone>" }
     */
    private function handleContactTyping(array $statusData, array $metadata): void
    {
        try {
            // recipient_id = the contact's phone number (who is typing TO us)
            $contactPhone  = $statusData['recipient_id'] ?? null;
            $phoneNumberId = $metadata['phone_number_id'] ?? null;

            if (!$contactPhone || !$phoneNumberId) return;

            $account = WhatsappAccount::where('phone_number_id', $phoneNumberId)->first();
            if (!$account) return;

            $conversation = Conversation::where('whatsapp_account_id', $account->id)
                ->where('whatsapp_user_phone', $contactPhone)
                ->where('status', 'active')
                ->latest('last_message_at')
                ->first();

            if (!$conversation) return;

            broadcast(new ContactTyping(
                conversationId: $conversation->id,
                contactPhone: $contactPhone,
                isTyping: true,
            ));

            Log::debug('Contact typing indicator received', [
                'conversation_id' => $conversation->id,
                'contact_phone'   => $contactPhone,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to handle contact typing', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // CONTENT EXTRACTION
    // =========================================================================

    private function extractMessageContent(array $message): array
    {
        return match ($message['type']) {
            'text' => ['text' => $message['text']['body']],

            'interactive' => [
                'type'     => $message['interactive']['type'],
                'response' => $message['interactive']['button_reply']
                    ?? $message['interactive']['list_reply']
                    ?? null,
            ],

            'button' => [
                'text'    => $message['button']['text'],
                'payload' => $message['button']['payload'],
            ],

            'image', 'video', 'audio', 'document' => [
                'id'        => $message[$message['type']]['id'],
                'mime_type' => $message[$message['type']]['mime_type'],
                'caption'   => $message[$message['type']]['caption'] ?? null,
                'sha256'    => $message[$message['type']]['sha256']   ?? null,
            ],

            'location' => [
                'latitude'  => $message['location']['latitude'],
                'longitude' => $message['location']['longitude'],
                'name'      => $message['location']['name']    ?? null,
                'address'   => $message['location']['address'] ?? null,
            ],

            'contacts' => ['contacts' => $message['contacts']],

            'sticker' => [
                'id'        => $message['sticker']['id'],
                'mime_type' => $message['sticker']['mime_type'],
            ],

            default => $message,
        };
    }

    // =========================================================================
    // MEDIA
    // =========================================================================

    public function downloadMedia(WhatsappAccount $account, string $mediaId): ?array
    {
        try {
            $client = new \GuzzleHttp\Client();

            $response  = $client->get("https://graph.facebook.com/v18.0/{$mediaId}", [
                'headers' => ['Authorization' => 'Bearer ' . decrypt($account->access_token)],
            ]);
            $mediaData = json_decode($response->getBody()->getContents(), true);

            $response = $client->get($mediaData['url'], [
                'headers' => ['Authorization' => 'Bearer ' . decrypt($account->access_token)],
            ]);

            return [
                'content'   => $response->getBody()->getContents(),
                'mime_type' => $mediaData['mime_type'],
                'file_size' => $mediaData['file_size'],
            ];
        } catch (\Exception $e) {
            Log::error('Error downloading media', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);
            return null;
        }
    }
}
