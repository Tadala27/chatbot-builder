<?php

namespace App\Services\Bot;

use App\Events\ContactTyping;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Jobs\ProcessChatbotMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\States\Active;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookService
{
    private string $apiVersion;

    public function __construct(private WhatsAppMessageService $messageService)
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
    }

    // =========================================================================
    // WEBHOOK VERIFICATION (GET from Meta)
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
                Log::info('[Webhook] Verified', [
                    'tenant_id' => $indexEntry->tenant_id,
                    'phone_number_id' => $indexEntry->phone_number_id,
                ]);

                return (int) $challenge;
            }
        }

        Log::warning('[Webhook] Verification failed', [
            'mode' => $mode,
            'token_provided' => !empty($token),
        ]);

        return 403;
    }

    // =========================================================================
    // WEBHOOK HANDLER (POST from Meta)
    // =========================================================================

    public function handleWebhook(array $payload): void
    {
        Log::debug('[Webhook] Payload received', [
            'phone_number_id' => $payload['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? null,
            'message_type' => $payload['entry'][0]['changes'][0]['value']['messages'][0]['type'] ?? null,
            'from' => $payload['entry'][0]['changes'][0]['value']['messages'][0]['from'] ?? null,
        ]);

        if (!isset($payload['entry'])) {
            Log::warning('[Webhook] Payload missing entry field');

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
            $metadata = $data['metadata'] ?? null;
            $message = $data['messages'][0] ?? null;
            $contact = $data['contacts'][0] ?? null;
            $replyToWamid = $message['context']['id'] ?? null;


            if (!$metadata || !$message) {
                Log::warning('[Webhook] Missing metadata or message', [
                    'has_metadata' => $metadata !== null,
                    'has_message' => $message !== null,
                ]);

                return;
            }

            // ── Find account ───────────────────────────────────────────────────
            $account = WhatsappAccount::where('phone_number_id', $metadata['phone_number_id'])->first();

            if (!$account) {
                Log::warning('[Webhook] Account not found', [
                    'phone_number_id' => $metadata['phone_number_id'],
                ]);

                return;
            }

            if (!$account->is_active) {
                Log::info('[Webhook] Message received for inactive account', [
                    'account_id' => $account->id,
                ]);

                return;
            }

            try {
                $this->messageService->markAsRead($account, $message['id']);
            } catch (\Exception $e) {
                Log::debug('[Webhook] Immediate read receipt failed (non-fatal)', [
                    'message_id' => $message['id'],
                    'error' => $e->getMessage(),
                ]);
            }

            // ── Idempotency gate ───────────────────────────────────────────────
            if (Message::where('whatsapp_message_id', $message['id'])->exists()) {
                Log::info('[Webhook] Duplicate — message already stored', [
                    'whatsapp_message_id' => $message['id'],
                ]);

                return;
            }

            // ── Find active bot ────────────────────────────────────────────────
            $bot = $account->bots()
                ->where('is_active', true)
                ->whereNotNull('current_published_version_id')
                ->with(['currentPublishedVersion'])
                ->first();

            $publishedVersion = $bot?->currentPublishedVersion;

            if (!$bot || !$publishedVersion) {
                Log::warning('[Webhook] No active bot with published version', [
                    'account_id' => $account->id,
                ]);

                return;
            }

            // ── Atomic conversation create / message store ─────────────────────
            $lockKey = "wa-conv:{$account->id}:{$message['from']}";

            $result = Cache::lock($lockKey, 10)->block(5, function () use (
                $account, $message, $contact, $bot, $publishedVersion, $replyToWamid
            ) {
                return DB::transaction(function () use (
                    $account, $message, $contact, $bot, $publishedVersion, $replyToWamid
                ) {
                    $conversation = Conversation::where('whatsapp_account_id', $account->id)
                        ->where('whatsapp_user_phone', $message['from'])
                        ->lockForUpdate()
                        ->latest('last_message_at')
                        ->first();

                    if (!$conversation) {
                        // ── New conversation ────────────────────────────────────
                        $conversation = Conversation::create([
                            'bot_id' => $bot->id,
                            'bot_version_id' => $publishedVersion->id,
                            'whatsapp_account_id' => $account->id,
                            'whatsapp_user_phone' => $message['from'],
                            'whatsapp_user_name' => $contact['profile']['name'] ?? null,
                            'status' => 'active',
                            'started_at' => now(),
                            'last_message_at' => now(),
                        ]);
                    } else {
                        // ── Existing conversation ──────────────────────────────

                        // Check if the conversation is on an outdated version
                        // and upgrade if needed
                        $wasUpgraded = false;
                        if ($conversation->bot_version_id !== $publishedVersion->id) {
                            $wasUpgraded = $conversation->upgradeToLatestVersion();

                            // If upgraded, refresh to get the new version ID
                            if ($wasUpgraded) {
                                $conversation->refresh();
                            }
                        }

                        // Reactivate if needed
                        if (!$conversation->status->equals(Active::class)) {
                            $conversation->status->transitionTo(Active::class);
                            $conversation->update([
                                'bot_id' => $bot->id,
                                // If not upgraded, update to the latest version anyway
                                'bot_version_id' => $wasUpgraded ? $conversation->bot_version_id : $publishedVersion->id,
                                'started_at' => now(),
                                'last_message_at' => now(),
                            ]);
                        } else {
                            // Update last_message_at even if already active
                            $conversation->update([
                                'last_message_at' => now(),
                            ]);
                        }
                    }

                    try {
                        $storedMessage = Message::create([
                            'conversation_id' => $conversation->id,
                            'whatsapp_message_id' => $message['id'],
                            'reply_to_wamid' => $replyToWamid,
                            'direction' => 'inbound',
                            'message_type' => $message['type'],
                            'content' => $this->extractMessageContent($message),
                            'status' => 'delivered',
                            'sent_at' => now(),
                            'delivered_at' => now(),
                        ]);
                    } catch (QueryException $e) {
                        if ($e->getCode() === '23000') {
                            return null;
                        }
                        throw $e;
                    }

                    $conversation->increment('message_count');
                    $conversation->update(['last_message_at' => now()]);

                    return ['conversation' => $conversation->fresh(), 'message' => $storedMessage];
                });
            });

            if (!$result) {
                Log::info('[Webhook] Message stored by concurrent worker — skipping', [
                    'whatsapp_message_id' => $message['id'],
                ]);

                return;
            }

            $conversation = $result['conversation'];
            $storedMessage = $result['message'];

            // ── Broadcast to agent inbox ───────────────────────────────────────
            try {
                broadcast(new MessageSent($storedMessage, $conversation));
            } catch (\Exception $e) {
                Log::warning('[Webhook] Failed to broadcast inbound MessageSent', [
                    'message_id' => $storedMessage->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // ── Dispatch to chatbot queue ──────────────────────────────────────
            Log::info('[Webhook] Dispatching ProcessChatbotMessage', [
                'conversation_id' => $conversation->id,
                'message_id' => $storedMessage->id,
                'status' => $conversation->status,
                'bot_version_id' => $conversation->bot_version_id,
            ]);

            if ($conversation->status->equals(Active::class) && $conversation->bot_id) {
                ProcessChatbotMessage::dispatchFor($conversation, $storedMessage);
            }
        } catch (\Exception $e) {
            Log::error('[Webhook] Error handling incoming message', [
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
                $newStatus = $statusData['status'];

                // Meta sends typing as a status event on WABA business accounts
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
                    Log::warning('[Webhook] Failed to broadcast MessageStatusUpdated', [
                        'message_id' => $message->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                Log::debug('[Webhook] Message status updated', [
                    'message_id' => $message->id,
                    'status' => $newStatus,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('[Webhook] Error handling message status', [
                'error' => $e->getMessage(),
                'data' => $data,
            ]);
        }
    }

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

            Log::debug('[Webhook] Contact typing indicator received', [
                'conversation_id' => $conversation->id,
                'contact_phone' => $contactPhone,
            ]);
        } catch (\Exception $e) {
            Log::warning('[Webhook] Failed to handle contact typing', [
                'error' => $e->getMessage(),
            ]);
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

            'reaction' => [
                'emoji' => $message['reaction']['emoji'] ?? null,
                'message_id' => $message['reaction']['message_id'] ?? null,
            ],

            default => $message,
        };
    }

    // =========================================================================
    // MEDIA DOWNLOAD (for saving inbound media attachments)
    // =========================================================================

    public function downloadMedia(WhatsappAccount $account, string $mediaId): ?array
    {
        try {
            $client = new \GuzzleHttp\Client();
            $token = $account->access_token;
            $auth = ['Authorization' => "Bearer {$token}"];

            $metaResponse = $client->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}",
                ['headers' => $auth]
            );
            $mediaData = json_decode($metaResponse->getBody()->getContents(), true);

            if (empty($mediaData['url'])) {
                Log::error('[Webhook] Media lookup returned no URL', ['media_id' => $mediaId]);

                return null;
            }

            $binaryResponse = $client->get($mediaData['url'], ['headers' => $auth]);

            return [
                'content' => $binaryResponse->getBody()->getContents(),
                'mime_type' => $mediaData['mime_type'] ?? null,
                'file_size' => $mediaData['file_size'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('[Webhook] Error downloading media', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}