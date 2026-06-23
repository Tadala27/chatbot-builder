<?php

namespace App\Services\Bot;

use App\Events\ContactTyping;
use App\Events\MessageStatusUpdated;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\States\Active;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    private string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
    }

    // =========================================================================
    // WEBHOOK VERIFICATION  (GET from Facebook)
    // =========================================================================
    public function verifyWebhook(array $params): string|int
    {
        $mode = $params['hub_mode'] ?? '';
        $token = $params['hub_verify_token'] ?? '';
        $challenge = $params['hub_challenge'] ?? '';

        if ($mode === 'subscribe' && $token) {
            $indexEntry = \App\Models\WhatsappPhoneIndex::where('verify_token', $token)
                ->where('is_active', true)
                ->first();

            if ($indexEntry) {
                Log::info('Webhook verified', [
                    'tenant_id' => $indexEntry->tenant_id,
                    'phone_number_id' => $indexEntry->phone_number_id,
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
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

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
            $message = $data['messages'][0];
            $contact = $data['contacts'][0] ?? null;

            // ── 1. Find account — tenant-scoped query, no tenant_id needed ──────
            $account = WhatsappAccount::where('phone_number_id', $metadata['phone_number_id'])->first();

            if (!$account) {
                Log::warning('WhatsApp account not found', [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);

                return;
            }

            if (!$account->is_active) {
                Log::info('Message received for inactive account', ['account_id' => $account->id]);

                return;
            }

            // ── 2. Idempotency gate — skip if already processed ───────────────
            if (Message::where('whatsapp_message_id', $message['id'])->exists()) {
                Log::info('Duplicate webhook — message already stored', [
                    'whatsapp_message_id' => $message['id'],
                ]);

                return;
            }

            // ── 3. Single-query bot + published flow lookup ───────────────────
            $bot = $account->bots()
                ->where('is_active', true)
                ->whereHas('flows', function ($q) {
                    $q->where('status', 'published')->where('is_active', true);
                })
                ->with(['flows' => function ($q) {
                    $q->where('status', 'published')
                      ->where('is_active', true)
                      ->with('currentPublishedVersion')
                      ->latest('published_at')
                      ->limit(1);
                }])
                ->first();

            $publishedFlow = $bot?->flows->first();
            $publishedVersion = $publishedFlow?->currentPublishedVersion;

            if (!$bot || !$publishedFlow || !$publishedVersion) {
                Log::warning('No active bot with a published flow', [
                    'account_id' => $account->id,
                ]);

                return;
            }

            // ── 4. Atomic conversation create / message store ─────────────────
            // Lock key no longer needs the tenant in it — account_id alone is
            // unique within this tenant's own database connection.
            $lockKey = "wa-conv:{$account->id}:{$message['from']}";

            $result = Cache::lock($lockKey, 10)->block(5, function () use ($account, $message, $contact, $publishedFlow, $publishedVersion) {
                return DB::transaction(function () use ($account, $message, $contact, $publishedFlow, $publishedVersion) {
                    $conversation = Conversation::where('whatsapp_account_id', $account->id)
                        ->where('whatsapp_user_phone', $message['from'])
                        ->lockForUpdate()
                        ->latest('last_message_at')
                        ->first();

                    if (!$conversation) {
                        $conversation = Conversation::create([
                            // tenant_id REMOVED — Conversation no longer has this
                            // column; the row only ever exists in this tenant's DB.
                            'whatsapp_account_id' => $account->id,
                            'whatsapp_user_phone' => $message['from'],
                            'whatsapp_user_name' => $contact['profile']['name'] ?? null,
                            'flow_id' => $publishedFlow->id,
                            'flow_version_id' => $publishedVersion->id,
                            'status' => 'active',
                            'started_at' => now(),
                            'last_message_at' => now(),
                        ]);
                    } elseif (!$conversation->status->equals(Active::class)) {
                        $conversation->status->transitionTo(Active::class);
                        $conversation->update([
                            'flow_id' => $publishedFlow->id,
                            'flow_version_id' => $publishedVersion->id,
                            'started_at' => now(),
                            'last_message_at' => now(),
                        ]);
                    }

                    try {
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
                    } catch (\Illuminate\Database\QueryException $e) {
                        if ($e->getCode() === '23000') {
                            return null; // duplicate — another worker beat us
                        }
                        throw $e;
                    }

                    $conversation->increment('message_count');
                    $conversation->update(['last_message_at' => now()]);

                    return ['conversation' => $conversation->fresh(), 'message' => $storedMessage];
                });
            });

            if (!$result) {
                Log::info('Message stored by concurrent worker — skipping', [
                    'whatsapp_message_id' => $message['id'],
                ]);

                return;
            }

            $conversation = $result['conversation'];
            $storedMessage = $result['message'];

            // ── 5. Broadcast (fire-and-forget) ─────────────────────────────────
            try {
                broadcast(new \App\Events\MessageSent($storedMessage, $conversation));
            } catch (\Exception $e) {
                Log::warning('Failed to broadcast inbound MessageSent', [
                    'message_id' => $storedMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // ── 6. Dispatch flow execution ──────────────────────────────────────
            if ($conversation->status === 'active' && $conversation->flow_id) {
                dispatch(new \App\Jobs\ProcessChatbotMessage($conversation, $storedMessage));
            }
        } catch (\Exception $e) {
            Log::error('Error handling incoming message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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

                if ($newStatus === 'typing') {
                    $this->handleContactTyping($statusData, $data['metadata'] ?? []);
                    continue;
                }

                $message = Message::where('whatsapp_message_id', $statusData['id'])->first();

                if (!$message) {
                    continue;
                }

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

                if ($newStatus === 'deleted') {
                    $updates['deleted_at'] = now();
                }

                $message->update($updates);

                try {
                    broadcast(new MessageStatusUpdated($message->fresh()));
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast MessageStatusUpdated', [
                        'message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::debug('Message status updated', [
                    'message_id' => $message->id,
                    'status' => $newStatus,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error handling message status', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

    /**
     * Resolve the conversation from a typing status event and broadcast
     * ContactTyping. Tenant-scoped automatically — no tenant_id needed.
     */
    private function handleContactTyping(array $statusData, array $metadata): void
    {
        try {
            $contactPhone = $statusData['recipient_id'] ?? null;
            $phoneNumberId = $metadata['phone_number_id'] ?? null;

            if (!$contactPhone || !$phoneNumberId) {
                return;
            }

            $account = WhatsappAccount::where('phone_number_id', $phoneNumberId)->first();
            if (!$account) {
                return;
            }

            $conversation = Conversation::where('whatsapp_account_id', $account->id)
                ->where('whatsapp_user_phone', $contactPhone)
                ->where('status', 'active')
                ->latest('last_message_at')
                ->first();

            if (!$conversation) {
                return;
            }

            broadcast(new ContactTyping(
                conversationId: $conversation->id,
                contactPhone: $contactPhone,
                isTyping: true,
            ));

            Log::debug('Contact typing indicator received', [
                'conversation_id' => $conversation->id,
                'contact_phone' => $contactPhone,
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
                'type' => $message['interactive']['type'],
                'response' => $message['interactive']['button_reply']
                    ?? $message['interactive']['list_reply']
                    ?? null,
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

            'contacts' => ['contacts' => $message['contacts']],

            'sticker' => [
                'id' => $message['sticker']['id'],
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
            $auth = ['Authorization' => 'Bearer '.decrypt($account->access_token)];

            $metaResponse = $client->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}",
                ['headers' => $auth]
            );
            $mediaData = json_decode($metaResponse->getBody()->getContents(), true);

            if (empty($mediaData['url'])) {
                Log::error('Media lookup returned no URL', ['media_id' => $mediaId]);

                return null;
            }

            $binaryResponse = $client->get($mediaData['url'], ['headers' => $auth]);

            return [
                'content' => $binaryResponse->getBody()->getContents(),
                'mime_type' => $mediaData['mime_type'] ?? null,
                'file_size' => $mediaData['file_size'] ?? null,
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