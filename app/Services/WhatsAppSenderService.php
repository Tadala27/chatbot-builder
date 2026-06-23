<?php

// app/Services/WhatsAppSenderService.php

namespace App\Services;

use App\Models\WhatsappAccount;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WhatsAppSenderService
{
    private string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
    }

    public function send(WhatsappAccount $account, string $to, string $type, array $payload): array
    {
        // Fail fast on oversized files BEFORE making any network call to
        // Meta. We never download media_url ourselves (Meta fetches it
        // directly), but a file exceeding Meta's documented limits will be
        // rejected by them after their own fetch attempt — wasting time
        // and surfacing a vague error. A cheap HEAD request here gives a
        // clear, immediate error instead.
        if (in_array($type, ['image', 'document'], true) && !empty($payload['media_url'])) {
            $sizeError = $this->checkMediaUrlSize($payload['media_url']);

            if ($sizeError) {
                throw new \RuntimeException($sizeError);
            }
        }

        $body = $this->buildRequestBody($to, $type, $payload);
        $client = new Client();

        try {
            $response = $client->post(
                "https://graph.facebook.com/{$this->apiVersion}/{$account->phone_number_id}/messages",
                [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->resolveToken($account),
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $body,
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            return [
                'message_id' => $data['messages'][0]['id'] ?? null,
                'raw' => $data,
            ];
        } catch (RequestException $e) {
            $errorBody = $e->getResponse()
                ? json_decode($e->getResponse()->getBody()->getContents(), true)
                : null;

            $errorMessage = $errorBody['error']['message'] ?? $e->getMessage();

            Log::error('WhatsApp send failed', [
                'account_id' => $account->id,
                'mode' => $account->mode,
                'to' => $to,
                'type' => $type,
                'error' => $errorMessage,
            ]);

            throw new \RuntimeException($errorMessage, previous: $e);
        }
    }

    /**
     * Cheap HEAD request to learn Content-Length + extension without
     * downloading the body, then validates against Meta's documented
     * limits via MetaMediaLimits. Returns an error message, or null if
     * the file is fine (or size couldn't be determined — we don't block
     * a send just because a HEAD request failed; Meta enforces its own
     * limit regardless).
     */
    private function checkMediaUrlSize(string $mediaUrl): ?string
    {
        try {
            $client = new Client(['timeout' => 5]);
            $headResponse = $client->head($mediaUrl);

            $contentLength = (int) ($headResponse->getHeaderLine('Content-Length') ?: 0);

            if ($contentLength <= 0) {
                return null;
            }

            $extension = strtolower(pathinfo(parse_url($mediaUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

            if (!$extension) {
                return null;
            }

            return MetaMediaLimits::validate($extension, $contentLength);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function resolveToken(WhatsappAccount $account): string
    {
        if ($account->isConnectorMode()) {
            $token = config('services.meta.tech_provider_token');

            if (empty($token)) {
                throw new \RuntimeException('services.meta.tech_provider_token is not configured — required to send on behalf of connector-mode accounts.');
            }

            return $token;
        }

        return decrypt($account->access_token);
    }

    private function buildRequestBody(string $to, string $type, array $payload): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
        ];

        return match ($type) {
            'text' => array_merge($base, [
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $payload['text'],
                ],
            ]),

            'image' => array_merge($base, [
                'type' => 'image',
                'image' => array_filter([
                    'link' => $payload['media_url'],
                    'caption' => $payload['caption'] ?? null,
                ]),
            ]),

            'document' => array_merge($base, [
                'type' => 'document',
                'document' => array_filter([
                    'link' => $payload['media_url'],
                    'caption' => $payload['caption'] ?? null,
                ]),
            ]),

            'template' => array_merge($base, [
                'type' => 'template',
                'template' => [
                    'name' => $payload['template_name'],
                    'language' => ['code' => $payload['template_language'] ?? 'en_US'],
                    'components' => $this->buildTemplateComponents($payload['template_params'] ?? []),
                ],
            ]),

            default => throw new \InvalidArgumentException("Unsupported message type [{$type}]."),
        };
    }

    private function buildTemplateComponents(array $params): array
    {
        if (empty($params)) {
            return [];
        }

        return [
            [
                'type' => 'body',
                'parameters' => array_map(
                    fn ($value) => ['type' => 'text', 'text' => (string) $value],
                    $params
                ),
            ],
        ];
    }
}