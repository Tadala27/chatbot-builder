<?php

namespace App\Services\Bot;

use App\Events\ContactTyping;
use App\Events\MessageSent;
use App\Events\MessageStatusUpdated;
use App\Jobs\ProcessChatbotMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\Tenant\TenantStorageManager;
use App\States\Active;
use GuzzleHttp\Client;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppWebhookService
{
    private string $apiVersion;

    private const MEDIA_TYPES = ['image', 'video', 'audio', 'document', 'sticker'];

    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/3gpp' => '3gp',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/ogg' => 'ogg',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    public function __construct(private WhatsAppMessageService $messageService)
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
    }

    // =========================================================================
    // WEBHOOK VERIFICATION
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

        Log::warning('[Webhook] Verification failed', ['mode' => $mode, 'token_provided' => !empty($token)]);

        return 403;
    }

    // =========================================================================
    // WEBHOOK HANDLER
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
                Log::warning('[Webhook] Missing metadata or message');

                return;
            }

            $account = WhatsappAccount::where('phone_number_id', $metadata['phone_number_id'])->first();

            if (!$account) {
                Log::warning('[Webhook] Account not found', ['phone_number_id' => $metadata['phone_number_id']]);

                return;
            }

            if (!$account->is_active) {
                Log::info('[Webhook] Message received for inactive account', ['account_id' => $account->id]);

                return;
            }

            try {
                $this->messageService->markAsRead($account, $message['id']);
            } catch (\Exception $e) {
                Log::debug('[Webhook] Immediate read receipt failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            if (Message::where('whatsapp_message_id', $message['id'])->exists()) {
                Log::info('[Webhook] Duplicate — message already stored', ['whatsapp_message_id' => $message['id']]);

                return;
            }

            $bot = $account->bots()
                ->where('is_active', true)
                ->whereNotNull('current_published_version_id')
                ->with(['currentPublishedVersion'])
                ->first();

            $publishedVersion = $bot?->currentPublishedVersion;

            if (!$bot || !$publishedVersion) {
                Log::warning('[Webhook] No active bot with published version', ['account_id' => $account->id]);

                return;
            }

            // Extract content, then immediately download any media to S3
            $content = $this->extractMessageContent($message);

            if (in_array($message['type'], self::MEDIA_TYPES, true)) {
                $content = $this->downloadAndStoreInboundMedia($account, $message, $content);
            }

            $lockKey = "wa-conv:{$account->id}:{$message['from']}";

            $result = Cache::lock($lockKey, 10)->block(5, function () use (
                $account, $message, $contact, $bot, $publishedVersion, $replyToWamid, $content
            ) {
                return DB::transaction(function () use (
                    $account, $message, $contact, $bot, $publishedVersion, $replyToWamid, $content
                ) {
                    $conversation = Conversation::where('whatsapp_account_id', $account->id)
                        ->where('whatsapp_user_phone', $message['from'])
                        ->lockForUpdate()
                        ->latest('last_message_at')
                        ->first();

                    if (!$conversation) {
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
                        $wasUpgraded = false;
                        if ($conversation->bot_version_id !== $publishedVersion->id) {
                            $wasUpgraded = $conversation->upgradeToLatestVersion();
                            if ($wasUpgraded) {
                                $conversation->refresh();
                            }
                        }

                        if (!$conversation->status->equals(Active::class)) {
                            $conversation->status->transitionTo(Active::class);
                            $conversation->update([
                                'bot_id' => $bot->id,
                                'bot_version_id' => $wasUpgraded ? $conversation->bot_version_id : $publishedVersion->id,
                                'started_at' => now(),
                                'last_message_at' => now(),
                            ]);
                        } else {
                            $conversation->update(['last_message_at' => now()]);
                        }
                    }

                    try {
                        $storedMessage = Message::create([
                            'conversation_id' => $conversation->id,
                            'whatsapp_message_id' => $message['id'],
                            'reply_to_wamid' => $replyToWamid,
                            'direction' => 'inbound',
                            'message_type' => $message['type'],
                            'content' => $content,
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
                Log::info('[Webhook] Message stored by concurrent worker — skipping');

                return;
            }

            $conversation = $result['conversation'];
            $storedMessage = $result['message'];

            try {
                broadcast(new MessageSent($storedMessage, $conversation));
            } catch (\Exception $e) {
                Log::warning('[Webhook] Failed to broadcast inbound MessageSent', ['error' => $e->getMessage()]);
            }

            if ($conversation->status->equals(Active::class) && $conversation->bot_id) {
                ProcessChatbotMessage::dispatchFor($conversation, $storedMessage);
            }
        } catch (\Exception $e) {
            Log::error('[Webhook] Error handling incoming message', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    // =========================================================================
    // INBOUND MEDIA — download from Meta CDN and store on S3
    // =========================================================================

    private function downloadAndStoreInboundMedia(
        WhatsappAccount $account,
        array $message,
        array $content
    ): array {
        $mediaId = $content['id'] ?? null;
        $mimeType = $content['mime_type'] ?? 'application/octet-stream';

        if (!$mediaId) {
            return $content;
        }

        try {
            $token = $this->resolveToken($account);
            $client = new Client(['timeout' => 60]);

            // Step 1: get the CDN download URL from Meta
            $metaResponse = $client->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$mediaId}",
                ['headers' => ['Authorization' => "Bearer {$token}"]]
            );
            $mediaData = json_decode($metaResponse->getBody()->getContents(), true);
            $cdnUrl = $mediaData['url'] ?? null;

            if (!$cdnUrl) {
                Log::warning('[Webhook] Media CDN URL not found — skipping S3 upload', ['media_id' => $mediaId]);

                return $content;
            }

            // Step 2: download the binary from Meta's CDN
            $binary = $client->get($cdnUrl, [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ])->getBody()->getContents();

            // Step 3: store on the tenant's S3 path
            $ext = self::MIME_EXTENSIONS[$mimeType] ?? 'bin';
            $storedFilename = Str::uuid()->toString().'.'.$ext;
            $storagePath = "inbound-media/{$account->id}/{$storedFilename}";

            TenantStorageManager::putContent($storagePath, $binary);

            // Step 4: generate a long-lived signed URL (7 days — same as Freshdesk)
            $signedUrl = TenantStorageManager::temporaryUrl($storagePath, minutes: 60 * 24 * 7);

            Log::info('[Webhook] Inbound media stored on S3', [
                'meta_media_id' => $mediaId,
                'mime_type' => $mimeType,
                'stored_filename' => $storedFilename,
                's3_key' => TenantStorageManager::fullKey($storagePath),
                'size_bytes' => strlen($binary),
            ]);

            return array_merge($content, [
                'stored_filename' => $storedFilename,
                'storage_path' => $storagePath,
                'storage_driver' => 'tenant',
                'url' => $signedUrl,
                'file_size' => strlen($binary),
            ]);
        } catch (\Exception $e) {
            // Non-fatal: message is still stored with the Meta ID so the
            // agent can fall back to the proxy endpoint
            Log::warning('[Webhook] Failed to store inbound media on S3 (non-fatal — Meta ID preserved)', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return $content;
        }
    }

    private function resolveToken(WhatsappAccount $account): string
    {
        if ($account->onboarding_method === 'registered_number') {
            return config('services.meta.system_user_token');
        }

        return $account->access_token;
    }

    // =========================================================================
    // STATUS UPDATES
    // =========================================================================

    private function handleMessageStatus(array $data): void
    {
        try {
            foreach ($data['statuses'] as $statusData) {
                $newStatus = $statusData['status'];

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
                    $errors = $statusData['errors'] ?? [];
                    $updates['error_message'] = $errors[0]['title'] ?? 'Message failed';

                    Log::error('[Webhook] Message delivery FAILED', [
                        'wamid' => $statusData['id'],
                        'message_id' => $message->id,
                        'message_type' => $message->message_type,
                        'direction' => $message->direction,
                        'recipient' => $statusData['recipient_id'] ?? null,
                        'errors' => $errors,
                        'error_code' => $errors[0]['code'] ?? null,
                        'error_title' => $errors[0]['title'] ?? null,
                        'error_detail' => $errors[0]['error_data']['details'] ?? null,
                    ]);
                }

                if ($newStatus === 'deleted') {
                    $updates['deleted_at'] = now();
                }

                $message->update($updates);

                try {
                    broadcast(new MessageStatusUpdated($message->fresh()));
                } catch (\Exception $e) {
                    Log::warning('[Webhook] Failed to broadcast MessageStatusUpdated', ['error' => $e->getMessage()]);
                }

                Log::debug('[Webhook] Message status updated', ['message_id' => $message->id, 'status' => $newStatus]);
            }
        } catch (\Exception $e) {
            Log::error('[Webhook] Error handling message status', ['error' => $e->getMessage(), 'data' => $data]);
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
        } catch (\Exception $e) {
            Log::warning('[Webhook] Failed to handle contact typing', ['error' => $e->getMessage()]);
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
                'filename' => $message[$message['type']]['filename'] ?? null,
            ],

            'sticker' => [
                'id' => $message['sticker']['id'],
                'mime_type' => $message['sticker']['mime_type'],
                'sha256' => $message['sticker']['sha256'] ?? null,
            ],

            'location' => [
                'latitude' => $message['location']['latitude'],
                'longitude' => $message['location']['longitude'],
                'name' => $message['location']['name'] ?? null,
                'address' => $message['location']['address'] ?? null,
            ],

            'contacts' => ['contacts' => $message['contacts']],

            'reaction' => [
                'emoji' => $message['reaction']['emoji'] ?? null,
                'message_id' => $message['reaction']['message_id'] ?? null,
            ],

            default => $message,
        };
    }

    // Legacy helper — used by MediaController::stream as last-resort fallback
    public function downloadMedia(WhatsappAccount $account, string $mediaId): ?array
    {
        try {
            $client = new Client(['timeout' => 30]);
            $token = $this->resolveToken($account);
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
            Log::error('[Webhook] Error downloading media', ['media_id' => $mediaId, 'error' => $e->getMessage()]);

            return null;
        }
    }
}