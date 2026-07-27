<?php

// app/Services/MetaPhoneNumberLookupService.php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

/**
 * Uses YOUR Tech Provider Meta credentials (system user token tied to your
 * own Meta App / Business Manager) to resolve a raw phone number a tenant
 * types in (e.g. "265997123456") to the phone_number_id Meta uses
 * internally. The tenant never provides any Meta credentials — they only
 * ever type a phone number and a webhook URL.
 *
 * This only works for numbers already registered somewhere under YOUR
 * Business Manager. If the tenant's number isn't found, that's the
 * correct failure mode: they need to register/port the number into your
 * WABA first (a business/Meta-side step, not something this lookup can
 * shortcut).
 */
class MetaPhoneNumberLookupService
{
    private Client $client;
    private string $apiVersion;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 15]);
        $this->apiVersion = config('services.meta.api_version', 'v21.0');
    }

    /**
     * @return array{phone_number_id: string, waba_id: string, display_phone_number: string, verified_name: ?string, quality_rating: string}|null
     */
    public function resolveByPhoneNumber(string $rawPhoneNumber): ?array
    {
        $normalized = preg_replace('/\D/', '', $rawPhoneNumber);

        foreach ($this->ownedWabaIds() as $wabaId) {
            $match = $this->findPhoneNumberInWaba($wabaId, $normalized);

            if ($match) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Your own WABA ids. If services.meta.tech_provider_waba_id is set,
     * use that directly (fastest — skips a lookup call entirely). Otherwise
     * fetch the list of WABAs under your Business Manager.
     */
    private function ownedWabaIds(): array
    {
        $configured = config('services.meta.tech_provider_waba_id');

        if ($configured) {
            return [$configured];
        }

        try {
            $businessId = config('services.meta.business_id');

            $response = $this->client->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$businessId}/client_whatsapp_business_accounts",
                ['query' => ['access_token' => $this->techProviderToken(), 'fields' => 'id']]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            return array_column($data['data'] ?? [], 'id');
        } catch (RequestException $e) {
            Log::error('Failed to list owned WABAs', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function findPhoneNumberInWaba(string $wabaId, string $normalizedPhone): ?array
    {
        try {
            $response = $this->client->get(
                "https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/phone_numbers",
                [
                    'query' => [
                        'access_token' => $this->techProviderToken(),
                        'fields' => 'id,display_phone_number,verified_name,quality_rating',
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            foreach ($data['data'] ?? [] as $entry) {
                $entryNormalized = preg_replace('/\D/', '', $entry['display_phone_number'] ?? '');

                if ($entryNormalized === $normalizedPhone) {
                    return [
                        'phone_number_id' => $entry['id'],
                        'waba_id' => $wabaId,
                        'display_phone_number' => $entry['display_phone_number'],
                        'verified_name' => $entry['verified_name'] ?? null,
                        'quality_rating' => strtoupper($entry['quality_rating'] ?? 'UNKNOWN'),
                    ];
                }
            }
        } catch (RequestException $e) {
            Log::warning("Failed to list phone numbers for WABA [{$wabaId}]", ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function techProviderToken(): string
    {
        $token = config('services.whatsapp.system_user_token');

        if (empty($token)) {
            throw new \RuntimeException('services.whatsapp.system_user_token is not configured. This must be your own permanent System User access token from Meta Business Manager — see .env: META_TECH_PROVIDER_TOKEN.');
        }

        return $token;
    }
}