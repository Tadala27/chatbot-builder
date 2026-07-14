<?php

namespace App\Services\Bot;

use App\Models\BotMediaFile;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppMessageService
{
    private Client $client;

    /** Meta media_ids expire after ~7 days. Cache for 6 h to avoid re-uploads. */
    private const MEDIA_CACHE_TTL = 6 * 3600;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/'.config('services.meta.api_version', 'v21.0').'/',
            'timeout' => 30,
        ]);
    }

    // =========================================================================
    // TEXT
    // =========================================================================

    public function sendTextMessage(
        WhatsappAccount $account,
        string $to,
        string $text,
        array $variables = [],
        ?string $replyToWamid = null
    ): Message {
        $body = $this->resolveText($text, $variables, $to, $account);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $body],
        ];

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'text', ['text' => $body], $response, $replyToWamid);
    }

    public function sendReplyText(
        WhatsappAccount $account,
        string $to,
        string $text,
        string $replyToWamid,
        array $variables = []
    ): Message {
        return $this->sendTextMessage($account, $to, $text, $variables, $replyToWamid);
    }

    // =========================================================================
    // INTERACTIVE — BUTTONS (Supports both Reply and URL buttons)
    // =========================================================================

    public function sendButtonMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null,
        array $variables = []
    ): Message {
        $resolver = app(VariableResolver::class);

        // Use resolveWithContext for all text fields
        $bodyText = $resolver->resolveWithContext($bodyText, $account, $to, $variables);

        // Separate reply buttons from URL buttons
        $replyButtons = [];
        $urlButtons = [];

        foreach ($buttons as $btn) {
            $type = $btn['type'] ?? 'reply';
            $btn['title'] = $resolver->resolveWithContext($btn['title'] ?? $btn['label'] ?? '', $account, $to, $variables);

            if ($type === 'url') {
                $btn['url'] = $resolver->resolveWithContext($btn['url'] ?? '#', $account, $to, $variables);
                $urlButtons[] = $btn;
            } else {
                $replyButtons[] = $btn;
            }
        }

        // If there are URL buttons, send as CTA URL message
        if (!empty($urlButtons)) {
            return $this->sendCtaUrlMessage(
                $account,
                $to,
                $bodyText,
                $urlButtons[0],
                $headerText ? $resolver->resolveWithContext($headerText, $account, $to, $variables) : null,
                $footerText ? $resolver->resolveWithContext($footerText, $account, $to, $variables) : null,
                $variables
            );
        }

        // Otherwise send as reply buttons (max 3)
        $formatted = array_map(function ($btn, $idx) {
            return [
                'type' => 'reply',
                'reply' => [
                    'id' => substr($btn['id'] ?? "btn_{$idx}", 0, 256),
                    'title' => $this->truncate($btn['title'] ?? 'Option '.($idx + 1), 20),
                ],
            ];
        }, array_slice($replyButtons, 0, 3), array_keys(array_slice($replyButtons, 0, 3)));

        $interactive = [
            'type' => 'button',
            'body' => ['text' => $bodyText],
            'action' => ['buttons' => $formatted],
        ];

        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate(
                    $resolver->resolveWithContext($headerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        if ($footerText) {
            $interactive['footer'] = [
                'text' => $this->truncate(
                    $resolver->resolveWithContext($footerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        $payload = $this->basePayload($to, 'interactive', ['interactive' => $interactive]);
        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'interactive', $interactive, $response);
    }

    /**
     * Send a CTA URL interactive message (for URL buttons).
     */
    private function sendCtaUrlMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        array $button,
        ?string $headerText = null,
        ?string $footerText = null,
        array $variables = []
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve the URL with variables using resolveWithContext
        $url = $resolver->resolveWithContext($button['url'] ?? '#', $account, $to, $variables);
        $displayText = $this->truncate(
            $button['title'] ?? 'Learn More',
            20
        );

        $interactive = [
            'type' => 'cta_url',
            'body' => ['text' => $bodyText],
            'action' => [
                'name' => 'cta_url',
                'parameters' => [
                    'display_text' => $displayText,
                    'url' => $url,
                ],
            ],
        ];

        // Add header if provided
        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate(
                    $resolver->resolveWithContext($headerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        // Add footer if provided
        if ($footerText) {
            $interactive['footer'] = [
                'text' => $this->truncate(
                    $resolver->resolveWithContext($footerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        $payload = $this->basePayload($to, 'interactive', ['interactive' => $interactive]);
        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'interactive', $interactive, $response);
    }

    // =========================================================================
    // INTERACTIVE — LIST
    // =========================================================================

    public function sendListMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        string $buttonText,
        array $sections,
        ?string $headerText = null,
        ?string $footerText = null,
        array $variables = []
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve all text fields with context
        $bodyText = $resolver->resolveWithContext($bodyText, $account, $to, $variables);
        $buttonText = $this->truncate(
            $resolver->resolveWithContext($buttonText, $account, $to, $variables),
            20
        );

        $formattedSections = [];

        foreach ($sections as $section) {
            $rows = [];

            foreach (array_slice($section['rows'] ?? [], 0, 10) as $row) {
                $rows[] = [
                    'id' => substr($row['id'] ?? substr(md5($row['title'] ?? ''), 0, 20), 0, 200),
                    'title' => $this->truncate(
                        $resolver->resolveWithContext($row['title'] ?? '', $account, $to, $variables),
                        24
                    ),
                    'description' => $this->truncate(
                        $resolver->resolveWithContext($row['description'] ?? '', $account, $to, $variables),
                        72
                    ),
                ];
            }

            if ($rows) {
                $formattedSections[] = [
                    'title' => $this->truncate(
                        $resolver->resolveWithContext($section['title'] ?? 'Options', $account, $to, $variables),
                        24
                    ),
                    'rows' => $rows,
                ];
            }
        }

        $interactive = [
            'type' => 'list',
            'body' => ['text' => $bodyText],
            'action' => ['button' => $buttonText, 'sections' => $formattedSections],
        ];

        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate(
                    $resolver->resolveWithContext($headerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        if ($footerText) {
            $interactive['footer'] = [
                'text' => $this->truncate(
                    $resolver->resolveWithContext($footerText, $account, $to, $variables),
                    60
                ),
            ];
        }

        $payload = $this->basePayload($to, 'interactive', ['interactive' => $interactive]);
        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'interactive', $interactive, $response);
    }

    // =========================================================================
    // MEDIA — public URL / link
    // =========================================================================

    public function sendMediaMessage(
        WhatsappAccount $account,
        string $to,
        string $mediaType,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
        array $variables = [],
        ?string $replyToWamid = null
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve caption with context
        if ($caption) {
            $caption = $resolver->resolveWithContext($caption, $account, $to, $variables);
        }

        // Resolve filename with context
        if ($filename) {
            $filename = $resolver->resolveWithContext($filename, $account, $to, $variables);
        }

        // Resolve media URL with context
        $mediaUrl = $resolver->resolveWithContext($mediaUrl, $account, $to, $variables);

        $media = ['link' => $mediaUrl];

        if ($caption && $mediaType !== 'audio') {
            $media['caption'] = $caption;
        }

        if ($filename && $mediaType === 'document') {
            $media['filename'] = $filename;
        }

        $payload = $this->basePayload($to, $mediaType, [$mediaType => $media]);

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->send($account, $payload);

        return $this->record($account, $to, $mediaType, $media, $response, $replyToWamid);
    }

    public function sendReplyMedia(
        WhatsappAccount $account,
        string $to,
        string $mediaType,
        string $mediaUrl,
        string $replyToWamid,
        ?string $caption = null,
        ?string $filename = null
    ): Message {
        return $this->sendMediaMessage(
            $account, $to, $mediaType, $mediaUrl,
            $caption, $filename, [], $replyToWamid
        );
    }

    // =========================================================================
    // MEDIA — server-stored BotMediaFile → upload to Meta first
    // =========================================================================

    public function sendStoredMediaFile(
        WhatsappAccount $account,
        string $to,
        BotMediaFile $mediaFile,
        ?string $caption = null,
        ?string $replyToWamid = null
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve caption with context
        if ($caption) {
            $caption = $resolver->resolveWithContext($caption, $account, $to, []);
        }

        $mediaId = $this->resolveMetaMediaId($account, $mediaFile);
        $mediaType = $mediaFile->media_type;

        $media = ['id' => $mediaId];

        if ($caption && $mediaType !== 'audio') {
            $media['caption'] = $caption;
        }

        if ($mediaType === 'document') {
            $media['filename'] = $mediaFile->original_filename;
        }

        $payload = $this->basePayload($to, $mediaType, [$mediaType => $media]);

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->send($account, $payload);

        return $this->record($account, $to, $mediaType, array_merge($media, [
            'media_file_id' => $mediaFile->id,
            'url' => $mediaFile->url,
            'original_filename' => $mediaFile->original_filename,
            'mime_type' => $mediaFile->mime_type,
        ]), $response, $replyToWamid);
    }

    // =========================================================================
    // MEDIA — agent inbox upload (transient UploadedFile)
    // =========================================================================

    public function sendMediaFile(
        WhatsappAccount $account,
        string $to,
        UploadedFile $file,
        ?string $caption = null,
        ?string $replyToWamid = null
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve caption with context
        if ($caption) {
            $caption = $resolver->resolveWithContext($caption, $account, $to, []);
        }

        $mediaType = $this->detectMediaType($file);
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $mediaId = $this->uploadFile(
            $account,
            $file->getRealPath(),
            $mimeType,
            $file->getClientOriginalName()
        );

        if (!$mediaId) {
            throw new \RuntimeException('Failed to upload media to WhatsApp — no media_id returned.');
        }

        $media = ['id' => $mediaId];

        if ($caption && $mediaType !== 'audio') {
            $media['caption'] = $caption;
        }

        if ($mediaType === 'document') {
            $media['filename'] = $file->getClientOriginalName();
        }

        $payload = $this->basePayload($to, $mediaType, [$mediaType => $media]);

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->send($account, $payload);

        // Store a copy locally for inbox display
        $tenantId = tenant()->id ?? 'unknown';
        $storedFilename = \Illuminate\Support\Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $storagePath = "inbox-media/{$tenantId}/{$account->id}/{$storedFilename}";
        $disk = 'public';

        $stored = Storage::disk($disk)->putFileAs(
            "inbox-media/{$tenantId}/{$account->id}",
            $file,
            $storedFilename
        );

        $content = array_merge($media, [
            'link' => $stored ? Storage::disk($disk)->url($storagePath) : null,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'stored_filename' => $storedFilename,
            'storage_path' => $storagePath,
            'disk' => $disk,
        ]);

        if ($replyToWamid) {
            $content['context'] = ['message_id' => $replyToWamid];
        }

        return $this->record($account, $to, $mediaType, $content, $response, $replyToWamid);
    }

    // =========================================================================
    // LOCATION
    // =========================================================================

    public function sendLocationMessage(
        WhatsappAccount $account,
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): Message {
        $resolver = app(VariableResolver::class);

        // Resolve name and address with context
        $name = $name ? $resolver->resolveWithContext($name, $account, $to, []) : null;
        $address = $address ? $resolver->resolveWithContext($address, $account, $to, []) : null;

        $location = array_filter([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ]);

        $payload = $this->basePayload($to, 'location', ['location' => $location]);
        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'location', $location, $response);
    }

    // =========================================================================
    // CONTACTS
    // =========================================================================

    public function sendContactMessage(WhatsappAccount $account, string $to, array $contactData): Message
    {
        $resolver = app(VariableResolver::class);

        // Resolve contact data with context
        $contactData = $this->resolveContactDataWithContext($contactData, $account, $to, $resolver);

        $contact = [];

        if (!empty($contactData['name'])) {
            $contact['name'] = array_filter($contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!empty($contactData[$field])) {
                $contact[$field] = array_map('array_filter', $contactData[$field]);
            }
        }

        if (!empty($contactData['org'])) {
            $contact['org'] = array_filter($contactData['org']);
        }
        if (!empty($contactData['birthday'])) {
            $contact['birthday'] = $contactData['birthday'];
        }

        $payload = $this->basePayload($to, 'contacts', ['contacts' => [$contact]]);
        $response = $this->send($account, $payload);

        return $this->record($account, $to, 'contacts', ['contacts' => [$contact]], $response);
    }

    /**
     * Recursively resolve contact data with context.
     */
    private function resolveContactDataWithContext(array $data, WhatsappAccount $account, string $to, VariableResolver $resolver): array
    {
        $resolved = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $resolved[$key] = $this->resolveContactDataWithContext($value, $account, $to, $resolver);
            } elseif (is_string($value)) {
                $resolved[$key] = $resolver->resolveWithContext($value, $account, $to, []);
            } else {
                $resolved[$key] = $value;
            }
        }

        return $resolved;
    }

    // =========================================================================
    // STATUS / TYPING INDICATORS
    // =========================================================================

    public function markAsRead(WhatsappAccount $account, string $messageId): bool
    {
        return $this->sendStatus($account, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    public function sendTypingIndicator(
        WhatsappAccount $account,
        string $to,
        bool $isTyping = true,
        ?Conversation $conversation = null
    ): void {
        $lastWamid = $conversation
            ? $conversation->messages()
                ->where('direction', 'inbound')
                ->whereNotNull('whatsapp_message_id')
                ->latest('sent_at')
                ->value('whatsapp_message_id')
            : Message::join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.whatsapp_account_id', $account->id)
                ->where('conversations.whatsapp_user_phone', $to)
                ->where('messages.direction', 'inbound')
                ->whereNotNull('messages.whatsapp_message_id')
                ->orderByDesc('messages.sent_at')
                ->value('messages.whatsapp_message_id');

        if (!$lastWamid) {
            return;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $lastWamid,
        ];

        if ($isTyping) {
            $payload['typing_indicator'] = ['type' => 'text'];
        }

        $this->sendStatus($account, $payload);
    }

    // =========================================================================
    // META MEDIA UPLOAD
    // =========================================================================

    public function resolveMetaMediaId(WhatsappAccount $account, BotMediaFile $mediaFile): string
    {
        $key = $this->metaMediaCacheKey($mediaFile->id, $account->id);
        $cached = Cache::get($key);

        if ($cached) {
            Log::info('[WhatsApp] Cached Meta media_id', [
                'media_file_id' => $mediaFile->id,
                'meta_media_id' => $cached,
            ]);

            return $cached;
        }

        $disk = $mediaFile->disk ?? 'public';
        $path = $mediaFile->path;

        if (!Storage::disk($disk)->exists($path)) {
            throw new \RuntimeException("BotMediaFile [{$mediaFile->id}] not found at [{$path}] on disk [{$disk}].");
        }

        $stream = Storage::disk($disk)->readStream($path);

        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers' => ['Authorization' => 'Bearer '.$this->token($account)],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => $stream,
                        'filename' => $mediaFile->original_filename ?? $mediaFile->stored_filename,
                        'headers' => ['Content-Type' => $mediaFile->mime_type],
                    ],
                    ['name' => 'type',              'contents' => $mediaFile->mime_type],
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $mediaId = $data['id'] ?? null;

            if (!$mediaId) {
                throw new \RuntimeException('Meta media upload returned no id. Response: '.json_encode($data));
            }

            Cache::put($key, $mediaId, self::MEDIA_CACHE_TTL);

            Log::info('[WhatsApp] BotMediaFile uploaded to Meta', [
                'media_file_id' => $mediaFile->id,
                'meta_media_id' => $mediaId,
            ]);

            return $mediaId;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function metaMediaCacheKey(string $mediaFileId, string $accountId): string
    {
        return "meta_media_id:{$mediaFileId}:{$accountId}";
    }

    // =========================================================================
    // UTILITY
    // =========================================================================

    public function uploadMedia(WhatsappAccount $account, string $filePath, string $mimeType): ?string
    {
        return $this->uploadFile($account, $filePath, $mimeType, basename($filePath));
    }

    public function getMediaUrl(WhatsappAccount $account, string $mediaId): ?string
    {
        try {
            $response = $this->client->get($mediaId, [
                'headers' => ['Authorization' => 'Bearer '.$this->token($account)],
            ]);

            return json_decode($response->getBody()->getContents(), true)['url'] ?? null;
        } catch (\Exception $e) {
            Log::error('[WhatsApp] getMediaUrl failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // =========================================================================
    // PRIVATE — HTTP
    // =========================================================================

    private function send(WhatsappAccount $account, array $payload): array
    {
        try {
            Log::info('[WhatsApp] Sending message', [
                'phone_number_id' => $account->phone_number_id,
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? null,
            ]);

            $response = $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token($account),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('[WhatsApp] Message sent', [
                'phone_number_id' => $account->phone_number_id,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return $data;
        } catch (ClientException $e) {
            $error = json_decode($e->getResponse()->getBody()->getContents(), true);
            Log::error('[WhatsApp] API error', [
                'account_id' => $account->id,
                'error' => $error['error']['message'] ?? $e->getMessage(),
                'code' => $error['error']['code'] ?? null,
            ]);
            throw $e;
        }
    }

    private function sendStatus(WhatsappAccount $account, array $payload): bool
    {
        try {
            $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token($account),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::warning('[WhatsApp] Status request failed (non-fatal)', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function uploadFile(
        WhatsappAccount $account,
        string $filePath,
        string $mimeType,
        string $filename
    ): ?string {
        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers' => ['Authorization' => 'Bearer '.$this->token($account)],
                'multipart' => [
                    ['name' => 'file',              'contents' => fopen($filePath, 'r'), 'filename' => $filename],
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('[WhatsApp] File uploaded', ['media_id' => $data['id'] ?? null]);

            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('[WhatsApp] File upload failed', ['filename' => $filename, 'error' => $e->getMessage()]);

            return null;
        }
    }

    // =========================================================================
    // PRIVATE — Token
    // =========================================================================

    private function token(WhatsappAccount $account): string
    {
        if ($account->onboarding_method === 'registered_number') {
            $token = config('services.meta.system_user_token');

            if (empty($token)) {
                throw new \RuntimeException('WHATSAPP_SYSTEM_USER_TOKEN is not configured. Add it to your .env.');
            }

            return $token;
        }

        if (empty($account->access_token)) {
            throw new \RuntimeException("WhatsApp account [{$account->id}] has no access_token. Please reconnect.");
        }

        return $account->access_token;
    }

    // =========================================================================
    // PRIVATE — Message record
    // =========================================================================

    private function record(
        WhatsappAccount $account,
        string $to,
        string $type,
        array $content,
        array $response,
        ?string $replyToWamid = null
    ): Message {
        $conversation = Conversation::where('whatsapp_account_id', $account->id)
            ->where('whatsapp_user_phone', $to)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$conversation) {
            $bot = $account->bots()->where('is_active', true)->first();
            $version = $bot?->publishedVersion();

            $conversation = Conversation::create([
                'bot_id' => $bot?->id,
                'bot_version_id' => $version?->id,
                'whatsapp_account_id' => $account->id,
                'whatsapp_user_phone' => $to,
                'status' => 'active',
                'started_at' => now(),
                'last_message_at' => now(),
            ]);
        }

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'reply_to_wamid' => $replyToWamid,
            'direction' => 'outbound',
            'message_type' => $type,
            'content' => $content,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        $conversation->increment('message_count');
        $conversation->update(['last_message_at' => now()]);

        try {
            broadcast(new \App\Events\MessageSent($message, $conversation->fresh()));
        } catch (\Exception $e) {
            Log::warning('[WhatsApp] MessageSent broadcast failed', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    // =========================================================================
    // PRIVATE — Helpers
    // =========================================================================

    private function basePayload(string $to, string $type, array $extra = []): array
    {
        return array_merge([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
        ], $extra);
    }

    private function resolveText(string $text, array $variables, string $to, WhatsappAccount $account): string
    {
        return app(VariableResolver::class)->resolveWithContext($text, $account, $to, $variables);
    }

    private function systemVars(array $variables, string $to, WhatsappAccount $account): array
    {
        return app(VariableResolver::class)->systemVars($variables, $to, $account);
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 3).'...';
    }

    private function detectMediaType(UploadedFile $file): string
    {
        $mime = $file->getMimeType() ?? '';

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'document';
    }
}