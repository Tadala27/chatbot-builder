<?php

namespace App\Services\Bot;

use App\Models\WhatsappAccount;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MetaEmbeddedSignupService
{
    private Client $client;
    private string $apiVersion;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 30]);
        $this->apiVersion = config('services.meta.api_version');
    }

    public function onboard(array $payload): WhatsappAccount
    {
        $businessToken = $this->exchangeCodeForBusinessToken($payload['code']);

        $this->subscribeToWebhooks($payload['waba_id'], $businessToken);

        $pin = (string) random_int(100000, 999999);
        $this->registerPhoneNumber($payload['phone_number_id'], $businessToken, $pin);

        $details = $this->fetchPhoneNumberDetails($payload['phone_number_id'], $businessToken);
        $wabaDetails = $this->fetchWabaDetails($payload['waba_id'], $businessToken);

        $account = WhatsappAccount::create([
            'waba_id' => $payload['waba_id'],
            'business_id' => $payload['business_id'] ?? null,
            'phone_number_id' => $payload['phone_number_id'],
            'phone_number' => preg_replace('/\D/', '', $details['display_phone_number']),
            'display_phone_number' => $details['display_phone_number'],
            'verified_name' => $details['verified_name'] ?? null,
            'quality_rating' => strtoupper($details['quality_rating'] ?? 'UNKNOWN'),
            'access_token' => encrypt($businessToken),
            'phone_number_pin' => encrypt($pin),
            'webhook_verify_token' => Str::random(32),
            'is_active' => true,
            'onboarding_status' => 'pending_payment',
            'mode' => null, // tenant picks managed_bot / connector next
            'metadata' => ['waba' => $wabaDetails, 'phone' => $details],
        ]);

        Log::info('WhatsApp account onboarded via Embedded Signup', [
            'waba_id' => $account->waba_id,
            'phone_number_id' => $account->phone_number_id,
        ]);

        return $account;
    }

    /**
     * Step 1: exchange the short-lived ES code for a business integration
     * system user access token. Server-to-server only — never expose
     * app_secret to the client.
     */
    private function exchangeCodeForBusinessToken(string $code): string
    {
        try {
            $response = $this->client->get("https://graph.facebook.com/{$this->apiVersion}/oauth/access_token", [
                'query' => [
                    'client_id' => config('services.meta.app_id'),
                    'client_secret' => config('services.meta.app_secret'),
                    'code' => $code,
                ],
            ]);

            // This endpoint returns the raw token as the body — not JSON —
            // per the doc's response section. Guard against either shape.
            $body = trim($response->getBody()->getContents());
            $decoded = json_decode($body, true);

            return $decoded['access_token'] ?? $body;
        } catch (RequestException $e) {
            Log::error('Failed to exchange ES code for business token', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to exchange authorization code: '.$e->getMessage());
        }
    }

    /** Step 2: subscribe your app to webhooks on the customer's WABA. */
    private function subscribeToWebhooks(string $wabaId, string $businessToken): void
    {
        try {
            $this->client->post("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}/subscribed_apps", [
                'headers' => ['Authorization' => "Bearer {$businessToken}"],
            ]);
        } catch (RequestException $e) {
            Log::error('Failed to subscribe to WABA webhooks', ['waba_id' => $wabaId, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to subscribe to webhooks: '.$e->getMessage());
        }
    }

    /** Step 3: register the phone number for Cloud API use — mandatory. */
    private function registerPhoneNumber(string $phoneNumberId, string $businessToken, string $pin): void
    {
        try {
            $this->client->post("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}/register", [
                'headers' => ['Authorization' => "Bearer {$businessToken}"],
                'json' => ['messaging_product' => 'whatsapp', 'pin' => $pin],
            ]);
        } catch (RequestException $e) {
            Log::error('Failed to register phone number', ['phone_number_id' => $phoneNumberId, 'error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to register phone number: '.$e->getMessage());
        }
    }

    private function fetchPhoneNumberDetails(string $phoneNumberId, string $businessToken): array
    {
        $response = $this->client->get("https://graph.facebook.com/{$this->apiVersion}/{$phoneNumberId}", [
            'headers' => ['Authorization' => "Bearer {$businessToken}"],
            'query' => ['fields' => 'display_phone_number,verified_name,quality_rating,code_verification_status'],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function fetchWabaDetails(string $wabaId, string $businessToken): array
    {
        $response = $this->client->get("https://graph.facebook.com/{$this->apiVersion}/{$wabaId}", [
            'headers' => ['Authorization' => "Bearer {$businessToken}"],
            'query' => ['fields' => 'name,currency,timezone_id,account_review_status'],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }
}
