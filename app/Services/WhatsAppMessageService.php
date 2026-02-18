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
     * Send text message
     */
    public function sendTextMessage(
        WhatsappAccount $account,
        string $to,
        string $text,
        array $variables = []
    ): Message {
        // Replace variables in text
        $variableResolver = app(VariableResolver::class);
        $processedText = $variableResolver->resolve($text, $variables);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => true,
                'body' => $processedText,
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
     * Send interactive button message
     */
    public function sendButtonMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null
    ): Message {
        // WhatsApp allows max 3 buttons
        $buttons = array_slice($buttons, 0, 3);

        $interactive = [
            'type' => 'button',
            'body' => [
                'text' => $bodyText,
            ],
            'action' => [
                'buttons' => array_map(fn($btn, $idx) => [
                    'type' => 'reply',
                    'reply' => [
                        'id' => $btn['id'] ?? "btn_{$idx}",
                        'title' => substr($btn['title'], 0, 20), // Max 20 chars
                    ],
                ], $buttons, array_keys($buttons)),
            ],
        ];

        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => substr($headerText, 0, 60), // Max 60 chars
            ];
        }

        if ($footerText) {
            $interactive['footer'] = [
                'text' => substr($footerText, 0, 60), // Max 60 chars
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            'interactive',
            $interactive,
            $response
        );
    }

    /**
     * Send interactive list message
     */
    public function sendListMessage(
        WhatsappAccount $account,
        string $to,
        string $bodyText,
        string $buttonText,
        array $sections,
        ?string $headerText = null,
        ?string $footerText = null
    ): Message {
        // Validate sections format
        $validatedSections = array_map(function ($section) {
            return [
                'title' => substr($section['title'] ?? 'Options', 0, 24),
                'rows' => array_slice(
                    array_map(fn($row) => [
                        'id' => $row['id'],
                        'title' => substr($row['title'], 0, 24),
                        'description' => isset($row['description'])
                            ? substr($row['description'], 0, 72)
                            : null,
                    ], $section['rows'] ?? []),
                    0,
                    10 // Max 10 rows per section
                ),
            ];
        }, $sections);

        $interactive = [
            'type' => 'list',
            'body' => [
                'text' => $bodyText,
            ],
            'action' => [
                'button' => substr($buttonText, 0, 20), // Max 20 chars
                'sections' => $validatedSections,
            ],
        ];

        if ($headerText) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => substr($headerText, 0, 60),
            ];
        }

        if ($footerText) {
            $interactive['footer'] = [
                'text' => substr($footerText, 0, 60),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => $interactive,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            'interactive',
            $interactive,
            $response
        );
    }

    /**
     * Send template message
     */
    public function sendTemplateMessage(
        WhatsappAccount $account,
        string $to,
        string $templateName,
        string $languageCode = 'en',
        array $parameters = []
    ): Message {
        $components = [];

        if (!empty($parameters)) {
            $components[] = [
                'type' => 'body',
                'parameters' => array_map(fn($param) => [
                    'type' => 'text',
                    'text' => (string) $param,
                ], $parameters),
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode,
                ],
                'components' => $components,
            ],
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            'template',
            $payload['template'],
            $response
        );
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
        ?string $filename = null
    ): Message {
        $mediaContent = array_filter([
            'link' => $mediaUrl,
            'caption' => $caption,
            'filename' => $filename,
        ]);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => $mediaContent,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            $mediaType,
            $mediaContent,
            $response
        );
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
            'latitude' => $latitude,
            'longitude' => $longitude,
            'name' => $name,
            'address' => $address,
        ]);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'location',
            'location' => $locationContent,
        ];

        $response = $this->sendRequest($account, $payload);

        return $this->createMessageRecord(
            $account,
            $to,
            'location',
            $locationContent,
            $response
        );
    }

    /**
     * Mark message as read
     */
    public function markAsRead(WhatsappAccount $account, string $messageId): bool
    {
        try {
            $payload = [
                'messaging_product' => 'whatsapp',
                'status' => 'read',
                'message_id' => $messageId,
            ];

            $this->sendRequest($account, $payload);

            Log::debug('Message marked as read', [
                'message_id' => $messageId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to mark message as read', [
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Make API request to WhatsApp
     */
    private function sendRequest(WhatsappAccount $account, array $payload): array
    {
        try {
            $response = $this->client->post("{$account->phone_number_id}/messages", [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('WhatsApp message sent', [
                'phone_number_id' => $account->phone_number_id,
                'to' => $payload['to'],
                'type' => $payload['type'],
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return $data;
        } catch (\Exception $e) {
            Log::error('WhatsApp API error', [
                'account_id' => $account->id,
                'phone_number_id' => $account->phone_number_id,
                'error' => $e->getMessage(),
                'payload' => $payload,
            ]);

            throw $e;
        }
    }

    /**
     * Create message record in database
     */
    private function createMessageRecord(
        WhatsappAccount $account,
        string $to,
        string $type,
        array $content,
        array $response
    ): Message {
        // Find or create conversation
        $conversation = Conversation::firstOrCreate(
            [
                'whatsapp_account_id' => $account->id,
                'whatsapp_user_phone' => $to,
            ],
            [
                'tenant_id' => $account->tenant_id,
                'flow_id' => $account->flows()->published()->first()?->id,
                'status' => 'active',
                'started_at' => now(),
                'last_message_at' => now(),
            ]
        );

        // Reopen conversation if it was ended
        if (in_array($conversation->status, ['completed', 'abandoned'])) {
            $conversation->update([
                'status' => 'active',
                'started_at' => now(),
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

        return $message;
    }

    /**
     * Upload media to WhatsApp
     */
    public function uploadMedia(WhatsappAccount $account, string $filePath, string $mimeType): ?string
    {
        try {
            $response = $this->client->post("{$account->phone_number_id}/media", [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                ],
                'multipart' => [
                    [
                        'name' => 'file',
                        'contents' => fopen($filePath, 'r'),
                        'filename' => basename($filePath),
                    ],
                    [
                        'name' => 'messaging_product',
                        'contents' => 'whatsapp',
                    ],
                    [
                        'name' => 'type',
                        'contents' => $mimeType,
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info('Media uploaded to WhatsApp', [
                'media_id' => $data['id'] ?? null,
            ]);

            return $data['id'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to upload media', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get media URL from media ID
     */
    public function getMediaUrl(WhatsappAccount $account, string $mediaId): ?string
    {
        try {
            $response = $this->client->get($mediaId, [
                'headers' => [
                    'Authorization' => 'Bearer ' . decrypt($account->access_token),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $data['url'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to get media URL', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}