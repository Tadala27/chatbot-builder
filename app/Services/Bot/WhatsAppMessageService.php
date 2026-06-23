<?php

namespace App\Services\Bot;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\WhatsappAccount;
use App\Services\VariableResolver;
use GuzzleHttp\Client;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageService
{
    private Client $client;

    public function __construct()
    {
        $apiVersion = config('services.meta.api_version', 'v21.0');

        $this->client = new Client([
            'base_uri' => "https://graph.facebook.com/{$apiVersion}/",
            'timeout' => 30,
        ]);
    }
    // =========================================================================
    // PUBLIC SENDERS
    // =========================================================================

    public function sendTextMessage(
        WhatsappAccount $account,
        string $to,
        string $text,
        array $variables = [],
        ?string $replyToWamid = null   // WhatsApp message ID to quote/reply to
    ): Message {
        $resolver = app(VariableResolver::class);
        $variables = $this->addSystemVariables($variables, $to, $account);
        $body = $resolver->resolve($this->formatText($text), $variables);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $body],
        ];

        // Contextual reply — shows the quoted message in the customer's chat
        if (!empty($replyToWamid)) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'text', ['text' => $body], $response);
    }

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
        $variables = $this->addSystemVariables($variables, $to, $account);

        $bodyText = $resolver->resolve($this->formatText($bodyText), $variables);

        $formattedButtons = array_map(function ($btn, $idx) use ($resolver, $variables) {
            $title = $resolver->resolve($btn['title'] ?? $btn['label'] ?? 'Option '.($idx + 1), $variables);

            return [
                'type' => 'reply',
                'reply' => [
                    'id' => substr($btn['id'] ?? "btn_{$idx}", 0, 256),
                    'title' => $this->truncate($this->formatText($title), 20),
                ],
            ];
        }, array_slice($buttons, 0, 3), array_keys(array_slice($buttons, 0, 3)));

        $interactive = [
            'type' => 'button',
            'body' => ['text' => $bodyText],
            'action' => ['buttons' => $formattedButtons],
        ];

        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($resolver->resolve($this->formatText($headerText), $variables), 60),
            ];
        }

        if ($footerText) {
            $interactive['footer'] = [
                'text' => $this->truncate($resolver->resolve($this->formatText($footerText), $variables), 60),
            ];
        }

        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'interactive', 'interactive' => $interactive];
        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'interactive', $interactive, $response);
    }

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
        $variables = $this->addSystemVariables($variables, $to, $account);

        $bodyText = $resolver->resolve($this->formatText($bodyText), $variables);
        $buttonText = $resolver->resolve($this->formatText($buttonText), $variables);

        $formattedSections = [];
        foreach ($sections as $section) {
            $rows = [];
            foreach (array_slice($section['rows'] ?? [], 0, 10) as $row) {
                $rowTitle = $this->truncate($resolver->resolve($this->formatText($row['title'] ?? ''), $variables), 24);
                $rowDesc = !empty($row['description'])
                    ? $this->truncate($resolver->resolve($this->formatText($row['description']), $variables), 72)
                    : '';

                $rows[] = [
                    'id' => substr($row['id'] ?? substr(md5($row['title'] ?? ''), 0, 20), 0, 200),
                    'title' => $rowTitle,
                    'description' => $rowDesc,
                ];
            }

            if (!empty($rows)) {
                $formattedSections[] = [
                    'title' => $this->truncate($resolver->resolve($section['title'] ?? 'Options', $variables), 24),
                    'rows' => $rows,
                ];
            }
        }

        $interactive = [
            'type' => 'list',
            'body' => ['text' => $bodyText],
            'action' => ['button' => $this->truncate($buttonText, 20), 'sections' => $formattedSections],
        ];

        if (!empty($headerText)) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($resolver->resolve($this->formatText($headerText), $variables), 60),
            ];
        }

        if (!empty($footerText)) {
            $interactive['footer'] = [
                'text' => $this->truncate($resolver->resolve($this->formatText($footerText), $variables), 60),
            ];
        }

        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'interactive', 'interactive' => $interactive];
        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'interactive', $interactive, $response);
    }

    public function sendMediaMessage(
        WhatsappAccount $account,
        string $to,
        string $mediaType,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
        array $variables = [],
        ?string $mimeType = null,
        ?string $replyToWamid = null   // contextual reply
    ): Message {
        $resolver = app(VariableResolver::class);

        if ($caption) {
            $variables = $this->addSystemVariables($variables, $to, $account);
            $caption = $this->formatText($resolver->resolve($caption, $variables));
        }

        $mediaObject = ['link' => $mediaUrl];
        if (!empty($caption)) {
            $mediaObject['caption'] = $caption;
        }
        if (!empty($filename)) {
            $mediaObject['filename'] = $filename;
        }
        if (!empty($mimeType)) {
            $mediaObject['mime_type'] = $mimeType;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => $mediaObject,
        ];

        if (!empty($replyToWamid)) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, $mediaType, $mediaObject, $response);
    }

    public function sendLocationMessage(
        WhatsappAccount $account,
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): Message {
        $locationContent = array_filter(['latitude' => $latitude, 'longitude' => $longitude, 'name' => $name, 'address' => $address]);
        $payload = ['messaging_product' => 'whatsapp', 'recipient_type' => 'individual', 'to' => $to, 'type' => 'location', 'location' => $locationContent];
        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'location', $locationContent, $response);
    }

    public function sendContactMessage(WhatsappAccount $account, string $to, array $contactData): Message
    {
        $contact = [];

        if (!empty($contactData['name'])) {
            $contact['name'] = array_filter($contactData['name']);
        }

        foreach (['phones', 'emails', 'addresses', 'urls'] as $field) {
            if (!empty($contactData[$field])) {
                $contact[$field] = array_map(fn ($item) => array_filter($item), $contactData[$field]);
            }
        }

        if (!empty($contactData['org'])) {
            $contact['org'] = array_filter($contactData['org']);
        }

        if (!empty($contactData['birthday'])) {
            $contact['birthday'] = $contactData['birthday'];
        }

        $payload = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'contacts', 'contacts' => [$contact]];
        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'contacts', $contact, $response);
    }

    public function markAsRead(WhatsappAccount $account, string $messageId): bool
    {
        return $this->sendStatusRequest($account, [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ]);
    }

    /**
     * Send a text reply that quotes a previous WhatsApp message.
     * The quoted message is identified by its wamid (whatsapp_message_id).
     */
    public function sendReplyText(
        WhatsappAccount $account,
        string $to,
        string $text,
        string $replyToWamid,
        array $variables = []
    ): Message {
        $resolver = app(VariableResolver::class);
        $variables = $this->addSystemVariables($variables, $to, $account);
        $body = $resolver->resolve($this->formatText($text), $variables);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'context' => ['message_id' => $replyToWamid],
            'type' => 'text',
            'text' => ['preview_url' => true, 'body' => $body],
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'text', [
            'text' => $body,
            'context' => ['message_id' => $replyToWamid],
        ], $response);
    }

    /**
     * Send a media message that quotes a previous WhatsApp message.
     */
    public function sendReplyMedia(
        WhatsappAccount $account,
        string $to,
        string $mediaType,
        string $mediaUrl,
        string $replyToWamid,
        ?string $caption = null,
        ?string $filename = null,
        ?string $mimeType = null
    ): Message {
        $mediaObject = ['link' => $mediaUrl];
        if (!empty($caption)) {
            $mediaObject['caption'] = $caption;
        }
        if (!empty($filename)) {
            $mediaObject['filename'] = $filename;
        }
        if (!empty($mimeType)) {
            $mediaObject['mime_type'] = $mimeType;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'context' => ['message_id' => $replyToWamid],
            'type' => $mediaType,
            $mediaType => $mediaObject,
        ];

        $response = $this->sendRequest($account, $payload);

        $content = array_merge($mediaObject, ['context' => ['message_id' => $replyToWamid]]);

        return $this->createMessageRecord($account, $to, $mediaType, $content, $response);
    }

    /**
     * Upload a local file to WhatsApp, then send it as a media message.
     * Used by the Inbox agent-send flow when the agent attaches a file.
     *
     * Flow:
     *   1. Upload the file bytes to the WA Media API → get a media_id
     *   2. Send a media message using that media_id (not a link)
     *   3. Delete the temp file
     */
    public function sendMediaFile(
        WhatsappAccount $account,
        string $to,
        UploadedFile $file,
        ?string $caption = null,
        ?string $replyToWamid = null
    ): Message {
        $mediaType = $this->detectMediaType($file);
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';

        // 1. Upload to WhatsApp Media API
        $mediaId = $this->uploadMediaFile($account, $file->getRealPath(), $mimeType, $file->getClientOriginalName());

        if (!$mediaId) {
            throw new \RuntimeException('Failed to upload media to WhatsApp — upload returned no media ID.');
        }

        // 2. Build the media object using the media_id (not a link)
        $mediaObject = ['id' => $mediaId];
        if (!empty($caption) && $mediaType !== 'audio') {
            $mediaObject['caption'] = $caption;
        }
        if ($mediaType === 'document') {
            $mediaObject['filename'] = $file->getClientOriginalName();
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => $mediaObject,
        ];

        if ($replyToWamid) {
            $payload['context'] = ['message_id' => $replyToWamid];
        }

        $response = $this->sendRequest($account, $payload);

        // Store a usable content record — include a local storage URL if we can
        $storedPath = $file->store("inbox-media/{$account->id}", 'public');
        $storedUrl = $storedPath
            ? rtrim(config('app.url'), '/').\Illuminate\Support\Facades\Storage::disk('public')->url($storedPath)
            : null;

        $content = [
            'id' => $mediaId,
            'link' => $storedUrl,
            'filename' => $file->getClientOriginalName(),
            'mime_type' => $mimeType,
            'caption' => $caption ?? null,
        ];
        if ($replyToWamid) {
            $content['context'] = ['message_id' => $replyToWamid];
        }

        return $this->createMessageRecord($account, $to, $mediaType, $content, $response);
    }

    public function sendTypingIndicator(
        WhatsappAccount $account,
        string $to,
        bool $isTyping = true,
        ?Conversation $conversation = null
    ): void {
        if ($conversation !== null) {
            $lastInbound = $conversation->messages()
                ->where('direction', 'inbound')
                ->whereNotNull('whatsapp_message_id')
                ->latest('sent_at')
                ->value('whatsapp_message_id');
        } else {
            // Legacy fallback path
            $lastInbound = Message::join('conversations', 'conversations.id', '=', 'messages.conversation_id')
                ->where('conversations.whatsapp_account_id', $account->id)
                ->where('conversations.whatsapp_user_phone', $to)
                ->where('messages.direction', 'inbound')
                ->whereNotNull('messages.whatsapp_message_id')
                ->orderByDesc('messages.sent_at')
                ->value('messages.whatsapp_message_id');
        }

        if (!$lastInbound) {
            return;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $lastInbound,
        ];

        if ($isTyping) {
            $payload['typing_indicator'] = ['type' => 'text'];
        }

        $this->sendStatusRequest($account, $payload);
    }

    // =========================================================================
    // PRIVATE — HTTP
    // =========================================================================

    /**
     * POST a message payload (type, text/interactive/media/etc).
     * All callers that create a Message record use this path.
     */
    private function sendRequest(WhatsappAccount $account, array $payload): array
    {
        $this->assertAccessToken($account);

        try {
            Log::info('Sending WhatsApp message', [
                'phone_number_id' => $account->phone_number_id,
                'to' => $payload['to'] ?? null,
                'type' => $payload['type'] ?? '(no type)',
            ]);

            $response = $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer '.decrypt($account->access_token),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('WhatsApp message sent', [
                'phone_number_id' => $account->phone_number_id,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return $data;
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorData = json_decode($e->getResponse()->getBody()->getContents(), true);
            Log::error('WhatsApp API client error', [
                'account_id' => $account->id,
                'error' => $errorData['error']['message'] ?? $e->getMessage(),
                'error_code' => $errorData['error']['code'] ?? null,
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('WhatsApp API error', ['account_id' => $account->id, 'error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * POST a status-only payload (read receipts, typing indicators).
     * These payloads have no 'type' key — they use 'status' instead.
     * Logs at debug level and never throws (non-fatal by contract).
     */
    private function sendStatusRequest(WhatsappAccount $account, array $payload): bool
    {
        $this->assertAccessToken($account);

        try {
            $response = $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer '.decrypt($account->access_token),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::debug('WhatsApp status request sent', [
                'phone_number_id' => $account->phone_number_id,
                'status' => $payload['status'] ?? null,
                'success' => $data['success'] ?? null,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::warning('WhatsApp status request failed (non-fatal)', [
                'account_id' => $account->id,
                'payload' => $payload,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function assertAccessToken(WhatsappAccount $account): void
    {
        if (empty($account->access_token)) {
            throw new \RuntimeException("WhatsApp account (id={$account->id}) has no access_token. Please reconnect in Settings.");
        }
    }

    // =========================================================================
    // PRIVATE — Message record
    // =========================================================================

    /**
     * Persist the outbound message.
     * Note: Message has NO flow_node_id / dialog_id column.
     * Dialog position tracking is handled via ConversationContext.last_dialog_id.
     */
    private function createMessageRecord(
        WhatsappAccount $account,
        string $to,
        string $type,
        array $content,
        array $response
    ): Message {
        // Find the active conversation — or create a minimal one if this is a
        // proactive outbound message (outside of a flow execution).
        $conversation = Conversation::where('whatsapp_account_id', $account->id)
            ->where('whatsapp_user_phone', $to)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (!$conversation) {
            // Proactive message: look up the most recent published flow for this account
            $bot = $account->bots()->where('is_active', true)->first();
            $flow = $bot?->flows()
                ->where('status', 'published')
                ->where('is_active', true)
                ->latest('published_at')
                ->first();

            $conversation = Conversation::create([
                'tenant_id' => $account->tenant_id,
                'flow_id' => $flow?->id,
                'flow_version_id' => $flow?->current_published_version_id,
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
            // Non-fatal — if Pusher is misconfigured, messages still save correctly.
            Log::warning('Failed to broadcast MessageSent', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $message;
    }

    // =========================================================================
    // PRIVATE — Helpers
    // =========================================================================

    private function addSystemVariables(array $variables, string $to, WhatsappAccount $account): array
    {
        $userName = '';
        try {
            $conv = Conversation::where('whatsapp_account_id', $account->id)
                ->where('whatsapp_user_phone', $to)
                ->latest()
                ->first();
            $userName = $conv?->whatsapp_user_name ?? '';
        } catch (\Exception) {
        }

        $now = now();

        return array_merge([
            'userName' => $userName,
            'phoneNumber' => $to,
            'currentDate' => $now->format('F j, Y'),
            'currentTime' => $now->format('g:i A'),   // ← corrected
            'currentDime' => $now->format('g:i A'),   // ← legacy alias; remove in v2
            'currentDatetime' => $now->format('F j, Y g:i A'),
            'dayOfWeek' => $now->format('l'),
            'month' => $now->format('F'),
            'year' => $now->format('Y'),
        ], $variables);
    }

    private function formatText(string $text): string
    {
        return $text; // extend here for WhatsApp markdown normalisation
    }

    private function truncate(string $text, int $maxLength): string
    {
        return mb_strlen($text) <= $maxLength
            ? $text
            : mb_substr($text, 0, $maxLength - 3).'...';
    }

    // =========================================================================
    // MEDIA UPLOAD / RETRIEVAL
    // =========================================================================

    public function uploadMedia(WhatsappAccount $account, string $filePath, string $mimeType): ?string
    {
        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers' => ['Authorization' => 'Bearer '.decrypt($account->access_token)],
                'multipart' => [
                    ['name' => 'file',              'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            Log::info('Media uploaded', ['media_id' => $data['id'] ?? null]);

            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to upload media', ['error' => $e->getMessage()]);

            return null;
        }
    }

    public function getMediaUrl(WhatsappAccount $account, string $mediaId): ?string
    {
        try {
            $response = $this->client->get($mediaId, [
                'headers' => ['Authorization' => 'Bearer '.decrypt($account->access_token)],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            return $data['url'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get media URL', ['media_id' => $mediaId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Upload a file from a local path to the WhatsApp Media API.
     * Returns the WhatsApp media_id, or null on failure.
     */
    private function uploadMediaFile(
        WhatsappAccount $account,
        string $filePath,
        string $mimeType,
        string $filename
    ): ?string {
        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers' => ['Authorization' => 'Bearer '.decrypt($account->access_token)],
                'multipart' => [
                    ['name' => 'file',              'contents' => fopen($filePath, 'r'), 'filename' => $filename],
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            Log::info('Media file uploaded to WhatsApp', ['media_id' => $data['id'] ?? null]);

            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to upload media file to WhatsApp', [
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map an UploadedFile to the WhatsApp media type string.
     */
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

        // Treat everything else as document (PDF, Word, Excel, etc.)
        return 'document';
    }
}
