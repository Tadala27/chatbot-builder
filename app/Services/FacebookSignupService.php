<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Models\FacebookBusinessAccount;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FacebookSignupService
{
    private Client $client;
    private string $appId;
    private string $appSecret;
    private string $redirectUri;

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'verify' => true,
        ]);

        $this->appId = config('services.facebook.app_id');
        $this->appSecret = config('services.facebook.app_secret');
        $this->redirectUri = config('services.facebook.redirect_uri');
    }

    /**
     * Generate Facebook Embedded Signup URL
     */
    public function getSignupUrl(Tenant $tenant): string
    {
        $state = base64_encode(json_encode([
            'tenant_id' => $tenant->id,
            'nonce' => Str::random(32),
            'timestamp' => time(),
        ]));

        $params = http_build_query([
            'client_id' => $this->appId,
            'redirect_uri' => $this->redirectUri,
            'state' => $state,
            'scope' => 'whatsapp_business_management,whatsapp_business_messaging',
            'extras' => json_encode([
                'feature' => 'whatsapp_embedded_signup',
                'version' => 2,
                'setup' => [
                    'channel' => 'whatsapp',
                ],
            ]),
        ]);

        return "https://www.facebook.com/v18.0/dialog/oauth?{$params}";
    }

    /**
     * Handle callback from Facebook
     */
    public function handleCallback(string $code, string $state): array
    {
        // Decode and validate state
        $stateData = json_decode(base64_decode($state), true);

        if (!$stateData || !isset($stateData['tenant_id'])) {
            throw new \Exception('Invalid state parameter');
        }

        // Check timestamp (should be within 10 minutes)
        if (isset($stateData['timestamp']) && (time() - $stateData['timestamp']) > 600) {
            throw new \Exception('State parameter expired');
        }

        $tenant = Tenant::findOrFail($stateData['tenant_id']);

        // Exchange code for access token
        $tokenData = $this->exchangeCodeForToken($code);

        // Get WABA (WhatsApp Business Account) details
        $wabaDetails = $this->getWABADetails($tokenData['access_token']);

        // Store Facebook Business Account
        $fbAccount = FacebookBusinessAccount::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'fb_business_id' => $wabaDetails['business_id'],
            ],
            [
                'fb_user_id' => $wabaDetails['user_id'] ?? null,
                'access_token' => encrypt($tokenData['access_token']),
                'token_expires_at' => isset($tokenData['expires_in'])
                    ? now()->addSeconds($tokenData['expires_in'])
                    : null,
                'scopes' => $tokenData['scope'] ?? '',
            ]
        );

        // Store WhatsApp Account
        $whatsappAccount = WhatsappAccount::create([
            'tenant_id' => $tenant->id,
            'waba_id' => $wabaDetails['waba_id'],
            'phone_number_id' => $wabaDetails['phone_number_id'],
            'phone_number' => $wabaDetails['phone_number'],
            'display_phone_number' => $wabaDetails['display_phone_number'],
            'verified_name' => $wabaDetails['verified_name'],
            'quality_rating' => $wabaDetails['quality_rating'] ?? 'UNKNOWN',
            'messaging_limit' => $wabaDetails['messaging_limit_tier'] ?? 'TIER_1K',
            'access_token' => encrypt($tokenData['access_token']),
            'webhook_verify_token' => Str::random(32),
            'is_active' => true,
        ]);

        // Subscribe to webhooks
        $this->subscribeToWebhooks($whatsappAccount);

        Log::info('WhatsApp account connected successfully', [
            'tenant_id' => $tenant->id,
            'waba_id' => $whatsappAccount->waba_id,
            'phone_number' => $whatsappAccount->phone_number,
        ]);

        return [
            'facebook_account' => $fbAccount,
            'whatsapp_account' => $whatsappAccount,
        ];
    }

    /**
     * Exchange authorization code for access token
     */
    private function exchangeCodeForToken(string $code): array
    {
        try {
            $response = $this->client->post('https://graph.facebook.com/v18.0/oauth/access_token', [
                'form_params' => [
                    'client_id' => $this->appId,
                    'client_secret' => $this->appSecret,
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!isset($data['access_token'])) {
                throw new \Exception('No access token received');
            }

            return $data;
        } catch (\Exception $e) {
            Log::error('Failed to exchange code for token', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to exchange authorization code: ' . $e->getMessage());
        }
    }

    /**
     * Get WhatsApp Business Account details
     */
    private function getWABADetails(string $accessToken): array
    {
        try {
            // Get user ID from token
            $response = $this->client->get('https://graph.facebook.com/v18.0/debug_token', [
                'query' => [
                    'input_token' => $accessToken,
                    'access_token' => "{$this->appId}|{$this->appSecret}",
                ],
            ]);

            $tokenInfo = json_decode($response->getBody()->getContents(), true);
            $userId = $tokenInfo['data']['user_id'] ?? null;

            // Get user's businesses
            $response = $this->client->get("https://graph.facebook.com/v18.0/{$userId}/businesses", [
                'query' => [
                    'access_token' => $accessToken,
                    'fields' => 'id,name',
                ],
            ]);

            $businesses = json_decode($response->getBody()->getContents(), true);

            if (empty($businesses['data'])) {
                throw new \Exception('No businesses found for user');
            }

            $businessId = $businesses['data'][0]['id'];

            // Get WABA from business
            $response = $this->client->get("https://graph.facebook.com/v18.0/{$businessId}/client_whatsapp_business_accounts", [
                'query' => [
                    'access_token' => $accessToken,
                    'fields' => 'id,name',
                ],
            ]);

            $wabas = json_decode($response->getBody()->getContents(), true);

            if (empty($wabas['data'])) {
                throw new \Exception('No WhatsApp Business Accounts found');
            }

            $wabaId = $wabas['data'][0]['id'];

            // Get phone numbers
            $response = $this->client->get("https://graph.facebook.com/v18.0/{$wabaId}/phone_numbers", [
                'query' => [
                    'access_token' => $accessToken,
                    'fields' => 'id,display_phone_number,verified_name,code_verification_status,quality_rating,messaging_limit_tier',
                ],
            ]);

            $phoneNumbers = json_decode($response->getBody()->getContents(), true);

            if (empty($phoneNumbers['data'])) {
                throw new \Exception('No phone numbers found');
            }

            $phoneData = $phoneNumbers['data'][0];

            return [
                'user_id' => $userId,
                'business_id' => $businessId,
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneData['id'],
                'phone_number' => preg_replace('/\D/', '', $phoneData['display_phone_number']),
                'display_phone_number' => $phoneData['display_phone_number'],
                'verified_name' => $phoneData['verified_name'],
                'quality_rating' => $phoneData['quality_rating'] ?? 'UNKNOWN',
                'messaging_limit_tier' => $phoneData['messaging_limit_tier'] ?? 'TIER_1K',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get WABA details', [
                'error' => $e->getMessage(),
            ]);
            throw new \Exception('Failed to retrieve WhatsApp account details: ' . $e->getMessage());
        }
    }

    /**
     * Subscribe to webhooks
     */
    private function subscribeToWebhooks(WhatsappAccount $account): void
    {
        try {
            $response = $this->client->post("https://graph.facebook.com/v18.0/{$account->waba_id}/subscribed_apps", [
                'form_params' => [
                    'access_token' => decrypt($account->access_token),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            Log::info("Subscribed to webhooks for WABA", [
                'waba_id' => $account->waba_id,
                'response' => $data,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to subscribe to webhooks', [
                'waba_id' => $account->waba_id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - this is non-critical
        }
    }

    /**
     * Refresh access token (for long-lived tokens)
     */
    public function refreshAccessToken(string $accessToken): array
    {
        try {
            $response = $this->client->get('https://graph.facebook.com/v18.0/oauth/access_token', [
                'query' => [
                    'grant_type' => 'fb_exchange_token',
                    'client_id' => $this->appId,
                    'client_secret' => $this->appSecret,
                    'fb_exchange_token' => $accessToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\Exception $e) {
            Log::error('Failed to refresh access token', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}