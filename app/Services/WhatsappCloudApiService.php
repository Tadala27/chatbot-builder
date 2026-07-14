<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsappCloudApiService
{
    protected string $baseUrl;
    protected string $token;
    protected string $wabaId;

    public function __construct()
    {
        $version = config('services.whatsapp.api_version', 'v25.0');
        $this->baseUrl = "https://graph.facebook.com/{$version}";
        $this->token = (string) config('services.whatsapp.system_user_token');
        $this->wabaId = (string) config('services.whatsapp.waba_id');

        if (!$this->token || !$this->wabaId) {
            throw new \RuntimeException('WhatsApp master WABA credentials are not configured. Set WHATSAPP_MASTER_WABA_ID and WHATSAPP_SYSTEM_USER_TOKEN.');
        }
    }

    /**
     * Adds a new phone number to the master WABA.
     * Returns the full response array from Meta.
     *
     * @throws \RuntimeException on failure, with Meta's own error message
     */
    public function addPhoneNumber(string $countryCode, string $nationalNumber, string $verifiedName): array
    {
        $response = $this->post("/{$this->wabaId}/phone_numbers", [
            'cc' => $countryCode,
            'phone_number' => $nationalNumber,
            'verified_name' => $verifiedName,
        ]);

        // Log the response for debugging
        Log::info('WhatsApp addPhoneNumber response', ['response' => $response]);

        return $response;
    }

    /**
     * Requests a verification code be sent to the phone number via SMS or a
     * voice call. $method must be 'sms' or 'voice'.
     */
    public function requestVerificationCode(string $phoneNumberId, string $method, string $language = 'en_US'): void
    {
        $this->post("/{$phoneNumberId}/request_code", [
            'code_method' => strtoupper($method),
            'language' => $language,
        ]);
    }

    /**
     * Verifies the code the customer received. Returns true on success —
     * throws if the code was wrong or expired.
     */
    public function verifyCode(string $phoneNumberId, string $code): bool
    {
        $this->post("/{$phoneNumberId}/verify_code", [
            'code' => $code,
        ]);

        return true;
    }

    /**
     * Registers the (now-verified) number for Cloud API sending/receiving,
     * setting its two-step verification PIN.
     */
    public function registerPhone(string $phoneNumberId, string $pin): bool
    {
        $this->post("/{$phoneNumberId}/register", [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ]);

        return true;
    }

    /**
     * Subscribes your app to webhooks on the master WABA. This only needs
     * to be called once ever for the WABA (not per phone number) — safe to
     * call again, Meta just returns success if already subscribed.
     */
    public function subscribeWebhooks(): void
    {
        $this->post("/{$this->wabaId}/subscribed_apps", []);
    }

    /**
     * Get phone number details from Meta.
     */
    public function getPhoneNumber(string $phoneNumberId): array
    {
        $response = $this->get("/{$phoneNumberId}");

        return $response;
    }

    protected function post(string $path, array $payload): array
    {
        try {
            $url = $this->baseUrl.$path;
            Log::info('WhatsApp API POST', ['url' => $url, 'payload' => $payload]);

            $response = Http::withToken($this->token)
                ->asJson()
                ->post($url, $payload)
                ->throw();

            $data = $response->json() ?? [];
            Log::info('WhatsApp API response', ['data' => $data]);

            return $data;
        } catch (RequestException $e) {
            $errorData = $e->response->json();
            $message = $errorData['error']['message'] ?? $e->getMessage();

            Log::error('WhatsApp API error', [
                'message' => $message,
                'response' => $errorData,
                'path' => $path,
            ]);

            throw new \RuntimeException($message, previous: $e);
        }
    }

    protected function get(string $path): array
    {
        try {
            $url = $this->baseUrl.$path;
            Log::info('WhatsApp API GET', ['url' => $url]);

            $response = Http::withToken($this->token)
                ->asJson()
                ->get($url)
                ->throw();

            $data = $response->json() ?? [];
            Log::info('WhatsApp API response', ['data' => $data]);

            return $data;
        } catch (RequestException $e) {
            $errorData = $e->response->json();
            $message = $errorData['error']['message'] ?? $e->getMessage();

            Log::error('WhatsApp API error', [
                'message' => $message,
                'response' => $errorData,
                'path' => $path,
            ]);

            throw new \RuntimeException($message, previous: $e);
        }
    }

    /**
     * Get all phone numbers in the WABA.
     */
    public function getPhoneNumbers(): array
    {
        $response = $this->get("/{$this->wabaId}/phone_numbers");

        return $response['data'] ?? [];
    }
}
