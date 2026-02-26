<?php

namespace App\Services;

use App\Models\WhatsappAccount;
use App\Models\Message;
use App\Models\Conversation;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

class WhatsAppMessageService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://graph.facebook.com/v18.0/',
            'timeout' => 30,
        ]);
    }

    /**
     * Send text message with WhatsApp formatting support
     */
    public function sendTextMessage(
        WhatsappAccount $account,
        string $to,
        string $text,
        array $variables = []
    ): Message {
        $variableResolver = app(VariableResolver::class);

        // Merge system variables so {{current_date}}, {{phone_number}}, etc. always resolve
        $variables = $this->addSystemVariables($variables, $to, $account);

        $processedText = $variableResolver->resolve($text, $variables);
        $processedText = $this->formatWhatsAppText($processedText);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => [
                'preview_url' => true,
                'body'        => $processedText,
            ],
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            'text',
            ['text' => $processedText],
            $response
        );
    }

    /**
     * Send interactive button message (Quick Replies)
     *
     * @param array $variables Conversation variables for resolution
     */
    public function sendButtonMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null,
        array $variables = []
    ): Message {
        $variableResolver = app(VariableResolver::class);

        // Merge system variables
        $variables = $this->addSystemVariables($variables, $to, $account);

        $bodyText = $variableResolver->resolve($bodyText, $variables);
        $bodyText = $this->formatWhatsAppText($bodyText);

        // WhatsApp allows max 3 buttons
        $buttons = array_slice($buttons, 0, 3);

        $formattedButtons = [];
        foreach ($buttons as $idx => $btn) {
            $buttonId    = $btn['id'] ?? "btn_{$idx}";
            $buttonTitle = $btn['title'] ?? $btn['label'] ?? 'Option ' . ($idx + 1);

            $buttonTitle = $variableResolver->resolve($buttonTitle, $variables);
            $buttonTitle = $this->formatWhatsAppText($buttonTitle);

            $formattedButtons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => substr($buttonId, 0, 256),
                    'title' => $this->truncate($buttonTitle, 20),
                ],
            ];
        }

        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $bodyText],
            'action' => ['buttons' => $formattedButtons],
        ];

        if ($headerText) {
            $headerText            = $variableResolver->resolve($headerText, $variables);
            $headerText            = $this->formatWhatsAppText($headerText);
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($headerText, 60),
            ];
        }

        if ($footerText) {
            $footerText            = $variableResolver->resolve($footerText, $variables);
            $footerText            = $this->formatWhatsAppText($footerText);
            $interactive['footer'] = ['text' => $this->truncate($footerText, 60)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'interactive', $interactive, $response);
    }

    /**
     * Send interactive list message
     *
     * @param array $variables Conversation variables for resolution
     */
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
        $variableResolver = app(VariableResolver::class);

        // Merge system variables
        $variables = $this->addSystemVariables($variables, $to, $account);

        $bodyText   = $variableResolver->resolve($bodyText, $variables);
        $bodyText   = $this->formatWhatsAppText($bodyText);

        $buttonText = $variableResolver->resolve($buttonText, $variables);
        $buttonText = $this->formatWhatsAppText($buttonText);

        $formattedSections = [];
        foreach ($sections as $section) {
            $sectionTitle = $variableResolver->resolve($section['title'] ?? 'Options', $variables);
            $sectionTitle = $this->formatWhatsAppText($sectionTitle);

            $rows = [];
            foreach (array_slice($section['rows'] ?? [], 0, 10) as $row) {
                $rowId          = $row['id'] ?? substr(md5($row['title'] ?? ''), 0, 20);
                $rowTitle       = $variableResolver->resolve($row['title'] ?? '', $variables);
                $rowTitle       = $this->formatWhatsAppText($rowTitle);
                $rowDescription = $row['description'] ?? $row['desc'] ?? '';

                if ($rowDescription) {
                    $rowDescription = $variableResolver->resolve($rowDescription, $variables);
                    $rowDescription = $this->formatWhatsAppText($rowDescription);
                }

                $rows[] = [
                    'id'          => substr($rowId, 0, 200),
                    'title'       => $this->truncate($rowTitle, 24),
                    'description' => !empty($rowDescription) ? $this->truncate($rowDescription, 72) : '',
                ];
            }

            if (!empty($rows)) {
                $formattedSections[] = [
                    'title' => $this->truncate($sectionTitle, 24),
                    'rows'  => $rows,
                ];
            }
        }

        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => $bodyText],
            'action' => [
                'button'   => $this->truncate($buttonText, 20),
                'sections' => $formattedSections,
            ],
        ];

        if (!empty($headerText)) {
            $headerText            = $variableResolver->resolve($headerText, $variables);
            $headerText            = $this->formatWhatsAppText($headerText);
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($headerText, 60),
            ];
        }

        if (!empty($footerText)) {
            $footerText            = $variableResolver->resolve($footerText, $variables);
            $footerText            = $this->formatWhatsAppText($footerText);
            $interactive['footer'] = ['text' => $this->truncate($footerText, 60)];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'interactive',
            'interactive'       => $interactive,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'interactive', $interactive, $response);
    }

    /**
     * Send media message (image, video, audio, document)
     */
    public function sendMediaMessage(
        WhatsappAccount $account,
        string $to,
        string $mediaType,
        string $mediaUrl,
        ?string $caption = null,
        ?string $filename = null,
        array $variables = []
    ): Message {
        $variableResolver = app(VariableResolver::class);

        if ($caption) {
            $variables = $this->addSystemVariables($variables, $to, $account);
            $caption   = $variableResolver->resolve($caption, $variables);
            $caption   = $this->formatWhatsAppText($caption);
        }

        $mediaContent = array_filter([
            'link'     => $mediaUrl,
            'caption'  => $caption,
            'filename' => $filename,
        ]);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => $mediaType,
            $mediaType          => $mediaContent,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, $mediaType, $mediaContent, $response);
    }

    /**
     * Send location message
     */
    public function sendLocationMessage(
        WhatsappAccount $account,
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): Message {
        $locationContent = array_filter([
            'latitude'  => $latitude,
            'longitude' => $longitude,
            'name'      => $name,
            'address'   => $address,
        ]);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'location',
            'location'          => $locationContent,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'location', $locationContent, $response);
    }

    /**
     * Send contact message (vCard)
     */
    public function sendContactMessage(
        WhatsappAccount $account,
        string $to,
        array $contactData
    ): Message {
        $contact = [];

        if (!empty($contactData['name'])) {
            $contact['name'] = array_filter([
                'formatted_name' => $contactData['name']['formatted_name'] ?? '',
                'first_name'     => $contactData['name']['first_name'] ?? null,
                'last_name'      => $contactData['name']['last_name'] ?? null,
                'middle_name'    => $contactData['name']['middle_name'] ?? null,
                'suffix'         => $contactData['name']['suffix'] ?? null,
                'prefix'         => $contactData['name']['prefix'] ?? null,
            ]);
        }

        if (!empty($contactData['phones'])) {
            $contact['phones'] = array_map(fn($p) => array_filter([
                'phone' => $p['phone'] ?? '',
                'type'  => $p['type'] ?? null,
                'wa_id' => $p['wa_id'] ?? null,
            ]), $contactData['phones']);
        }

        if (!empty($contactData['emails'])) {
            $contact['emails'] = array_map(fn($e) => array_filter([
                'email' => $e['email'] ?? '',
                'type'  => $e['type'] ?? null,
            ]), $contactData['emails']);
        }

        if (!empty($contactData['addresses'])) {
            $contact['addresses'] = array_map(fn($a) => array_filter([
                'street'       => $a['street'] ?? null,
                'city'         => $a['city'] ?? null,
                'state'        => $a['state'] ?? null,
                'zip'          => $a['zip'] ?? null,
                'country'      => $a['country'] ?? null,
                'country_code' => $a['country_code'] ?? null,
                'type'         => $a['type'] ?? null,
            ]), $contactData['addresses']);
        }

        if (!empty($contactData['urls'])) {
            $contact['urls'] = array_map(fn($u) => array_filter([
                'url'  => $u['url'] ?? '',
                'type' => $u['type'] ?? null,
            ]), $contactData['urls']);
        }

        if (!empty($contactData['org'])) {
            $contact['org'] = array_filter([
                'company'    => $contactData['org']['company'] ?? null,
                'department' => $contactData['org']['department'] ?? null,
                'title'      => $contactData['org']['title'] ?? null,
            ]);
        }

        if (!empty($contactData['birthday'])) {
            $contact['birthday'] = $contactData['birthday'];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'contacts',
            'contacts'          => [$contact],
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord($account, $to, 'contacts', $contact, $response);
    }

    private function formatWhatsAppText(string $text): string
    {
        return $text;
    }

    /**
     * Build the system-variable map merged with any caller-supplied variables.
     *
     */
    private function addSystemVariables(array $variables, string $to, WhatsappAccount $account): array
    {
        $userName = '';
        try {
            $conversation = \App\Models\Conversation::where('whatsapp_account_id', $account->id)
                ->where('whatsapp_user_phone', $to)
                ->latest()
                ->first();
            $userName = $conversation?->whatsapp_user_name ?? '';
        } catch (\Exception $e) {
            // Non-fatal — leave blank
        }

        $systemVars = [
            'user_name'        => $userName,
            'phone_number'     => $to,
            'current_date'     => now()->format('F j, Y'),
            'current_time'     => now()->format('g:i A'),
            'current_datetime' => now()->format('F j, Y g:i A'),
            'day_of_week'      => now()->format('l'),
            'month'            => now()->format('F'),
            'year'             => now()->format('Y'),
        ];

        // Caller-supplied variables win over system defaults
        return array_merge($systemVars, $variables);
    }

    /**
     * Truncate text to the specified character length, appending "…" if needed.
     */
    private function truncate(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3) . '...';
    }

    /**
     * Mark a WhatsApp message as read.
     */
    public function markAsRead(WhatsappAccount $account, string $messageId): bool
    {
        try {
            $this->sendRequest($account, [
                'messaging_product' => 'whatsapp',
                'status'            => 'read',
                'message_id'        => $messageId,
            ]);

            Log::debug('Message marked as read', ['message_id' => $messageId]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark message as read', [
                'message_id' => $messageId,
                'error'      => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Make an API request to the WhatsApp Cloud API.
     */
    private function sendRequest(WhatsappAccount $account, array $payload): array
    {
        // Guard against a null / missing access_token before decrypt() is called
        if (empty($account->access_token)) {
            Log::error('WhatsApp account has no access_token', [
                'account_id'      => $account->id,
                'phone_number_id' => $account->phone_number_id,
            ]);
            throw new \RuntimeException(
                "WhatsApp account (id={$account->id}) has no access_token. " .
                "Please reconnect the account in Settings."
            );
        }

        try {
            Log::info('Sending WhatsApp message', [
                'phone_number_id' => $account->phone_number_id,
                'to'              => $payload['to'] ?? null,
                'type'            => $payload['type'],
                'payload_preview' => json_encode($payload, JSON_PRETTY_PRINT),
            ]);

            $response = $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                    'Content-Type'  => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('WhatsApp message sent successfully', [
                'phone_number_id' => $account->phone_number_id,
                'to'              => $payload['to'] ?? null,
                'type'            => $payload['type'],
                'message_id'      => $data['messages'][0]['id'] ?? null,
            ]);

            return $data;

        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $errorBody = $e->getResponse()->getBody()->getContents();
            $errorData = json_decode($errorBody, true);

            Log::error('WhatsApp API client error', [
                'account_id'      => $account->id,
                'phone_number_id' => $account->phone_number_id,
                'error'           => $errorData['error']['message'] ?? $e->getMessage(),
                'error_code'      => $errorData['error']['code'] ?? null,
                'error_details'   => $errorData['error'] ?? null,
                'payload'         => $payload,
            ]);

            throw $e;

        } catch (\Exception $e) {
            Log::error('WhatsApp API error', [
                'account_id'      => $account->id,
                'phone_number_id' => $account->phone_number_id,
                'error'           => $e->getMessage(),
                'payload'         => $payload,
            ]);

            throw $e;
        }
    }

    /**
     * Find-or-create a conversation and persist an outbound message record.
     */
    private function createMessageRecord(
        WhatsappAccount $account,
        string $to,
        string $type,
        array $content,
        array $response
    ): Message {
        $conversation = Conversation::firstOrCreate(
            [
                'whatsapp_account_id' => $account->id,
                'whatsapp_user_phone' => $to,
            ],
            [
                'tenant_id'       => $account->tenant_id,
                'flow_id'         => $account->flows()->published()->first()?->id,
                'status'          => 'active',
                'started_at'      => now(),
                'last_message_at' => now(),
            ]
        );

        if (in_array($conversation->status, ['completed', 'abandoned'])) {
            $conversation->update([
                'status'     => 'active',
                'started_at' => now(),
            ]);
        }

        $message = Message::create([
            'conversation_id'     => $conversation->id,
            'whatsapp_message_id' => $response['messages'][0]['id'] ?? null,
            'direction'           => 'outbound',
            'message_type'        => $type,
            'content'             => $content,
            'status'              => 'sent',
            'sent_at'             => now(),
        ]);

        $conversation->increment('message_count');
        $conversation->update(['last_message_at' => now()]);

        return $message;
    }

    /**
     * Upload a media file to WhatsApp.
     */
    public function uploadMedia(WhatsappAccount $account, string $filePath, string $mimeType): ?string
    {
        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers'   => ['Authorization' => 'Bearer ' . decrypt($account->access_token)],
                'multipart' => [
                    ['name' => 'file',              'contents' => fopen($filePath, 'r'), 'filename' => basename($filePath)],
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('Media uploaded to WhatsApp', ['media_id' => $data['id'] ?? null]);

            return $data['id'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to upload media', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Retrieve a temporary download URL for a media object.
     */
    public function getMediaUrl(WhatsappAccount $account, string $mediaId): ?string
    {
        try {
            $response = $this->client->get($mediaId, [
                'headers' => ['Authorization' => 'Bearer ' . decrypt($account->access_token)],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['url'] ?? null;

        } catch (\Exception $e) {
            Log::error('Failed to get media URL', [
                'media_id' => $mediaId,
                'error'    => $e->getMessage(),
            ]);

            return null;
        }
    }
}