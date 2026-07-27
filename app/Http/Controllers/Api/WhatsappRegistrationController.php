<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Models\WhatsappPhoneIndex;
use App\Services\Bot\MetaEmbeddedSignupService;
use App\Services\WhatsappCloudApiService;
use App\Services\WhatsappPhoneIndexSync;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Everything to do with registering a WhatsApp number with Meta and keeping
 * it in sync (manual OTP registration, Embedded Signup, mode selection,
 * health/sync pulls from the Graph API, and the debug tools that hit Meta
 * directly). Local, non-Meta account management lives in
 * WhatsAppAccountController.
 */
class WhatsappRegistrationController extends Controller
{
    public function __construct(
        private WhatsappCloudApiService $api,
        protected WhatsappPhoneIndexSync $phoneIndex,
    ) {
    }

    // ── Manual registration: Step 1 — add number to WABA ───────────────────

    public function addNumber(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country_code' => 'required|string|max:4',
            'local_number' => 'required|string|max:15',
            'display_name' => 'required|string|max:255',
            'migrate' => 'boolean',
        ]);

        // Strip any non-digit characters the user might paste in
        $cc = preg_replace('/\D/', '', $validated['country_code']);
        $local = preg_replace('/\D/', '', $validated['local_number']);
        $e164 = "+{$cc}{$local}";

        // Prevent duplicate registration of a number, either in this tenant
        // or in the cross-tenant phone index.
        try {
            $this->assertPhoneNumberNotRegistered($e164);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $result = $this->api->addPhoneNumber(
                $cc,
                $local,
                $validated['display_name']
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Check if the response contains the expected data
        if (empty($result) || !isset($result['id'])) {
            Log::error('Invalid response from Meta API', ['response' => $result]);

            return response()->json([
                'message' => 'Unexpected response from WhatsApp API. Please try again.',
            ], 500);
        }

        $phoneNumberId = $result['id'];

        // Create the local record immediately so subsequent steps have
        // something to update. Status is 'pending' until verification.
        $account = WhatsappAccount::create([
            'waba_id' => config('services.whatsapp.waba_id'),
            'phone_number_id' => $phoneNumberId,
            'phone_number' => $e164,
            'display_phone_number' => $e164,
            'verified_name' => $validated['display_name'],
            'access_token' => config('services.whatsapp.system_user_token'),
            'webhook_verify_token' => config('services.whatsapp.verify_token'),
            'is_active' => false,
            'onboarding_status' => 'pending',
            'onboarding_method' => 'registered_number',
            'messaging_limit' => 'TIER_250',
        ]);

        activity()->causedBy(auth()->user())->performedOn($account)
            ->log('Phone number added to WABA');

        return response()->json([
            'message' => 'Phone number added successfully. Please request verification code.',
            'account' => $account,
            'phone_number_id' => $phoneNumberId,
        ], 201);
    }

    // ── Manual registration: Step 2 — request OTP code ──────────────────────

    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_account_id' => 'required|exists:whatsapp_accounts,id',
            'method' => 'required|in:sms,voice',
            'language' => 'nullable|string|max:10',
        ]);

        $account = WhatsappAccount::findOrFail($validated['whatsapp_account_id']);

        // Make sure the account hasn't already been registered
        if ($account->is_active) {
            return response()->json([
                'message' => 'This number is already active and registered.',
            ], 422);
        }

        try {
            $this->api->requestVerificationCode(
                $account->phone_number_id,
                $validated['method'],
                $validated['language'] ?? 'en_US'
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account->update([
            'verification_method' => $validated['method'],
            'onboarding_status' => 'code_requested',
        ]);

        return response()->json([
            'message' => 'Verification code sent successfully. Please check your phone.',
        ]);
    }

    // ── Manual registration: Step 3 — submit OTP code ───────────────────────

    public function verifyCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_account_id' => 'required|string|exists:whatsapp_accounts,id',
            'code' => 'required|string|min:6|max:6',
        ]);

        $account = WhatsappAccount::findOrFail($validated['whatsapp_account_id']);

        try {
            $this->api->verifyCode($account->phone_number_id, $validated['code']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $account->update(['onboarding_status' => 'verified']);

        return response()->json(['message' => 'Code verified. Please set your 2FA PIN.']);
    }

    // ── Manual registration: Step 4 — register + set PIN ────────────────────

    public function completeRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'whatsapp_account_id' => 'required|string|exists:whatsapp_accounts,id',
            'pin' => 'required|string|size:6|regex:/^\d{6}$/',
        ]);

        $account = WhatsappAccount::findOrFail($validated['whatsapp_account_id']);

        if (!in_array($account->onboarding_status, ['verified', 'failed'])) {
            return response()->json([
                'message' => 'Please verify the OTP code before completing registration.',
            ], 422);
        }

        try {
            $this->api->registerPhone($account->phone_number_id, $validated['pin']);
        } catch (\RuntimeException $e) {
            $account->update(['onboarding_status' => 'failed']);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Fetch fresh metadata now that the number is live — quality rating,
        // verified name status, etc.
        try {
            $meta = $this->api->getPhoneNumber($account->phone_number_id);
        } catch (\RuntimeException $e) {
            Log::warning('[WhatsApp] Could not fetch metadata after registration', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
            $meta = [];
        }

        $account->update([
            'phone_number_pin' => $validated['pin'], // cast as encrypted on the model
            'is_active' => true,
            'onboarding_status' => 'active',
            'registered_at' => now(),
            'verified_name' => $meta['verified_name'] ?? $account->verified_name,
            'quality_rating' => $meta['quality_rating'] ?? 'UNKNOWN',
        ]);

        activity()->causedBy(auth()->user())->performedOn($account)
            ->log('Phone number registered on Cloud API');

        return response()->json([
            'message' => 'Phone number registered successfully.',
            'account' => $account->fresh(),
        ]);
    }

    // ── Embedded Signup registration ────────────────────────────────────────

    public function embeddedSignupCallback(Request $request, MetaEmbeddedSignupService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'business_id' => ['sometimes', 'string'],
        ]);

        try {
            $this->assertPhoneNotAlreadyClaimed($validated['phone_number_id']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $account = $onboarding->onboard($validated);

            activity()->causedBy(Auth::guard('tenant')->user())
                ->performedOn($account)
                ->log('WhatsApp number onboarded via Embedded Signup');

            return response()->json([
                'message' => 'Number registered. Choose how you want to use it.',
                'account' => $account,
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function chooseMode(Request $request, WhatsappAccount $account): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'in:managed_bot,connector'],
            'webhook_url' => ['required_if:mode,connector', 'url'],
        ]);

        // ← remove the if ($account->mode !== null) guard entirely

        $previousMode = $account->mode;

        $account->mode = $validated['mode'];

        if ($validated['mode'] === 'connector') {
            $account->webhook_url = $validated['webhook_url'];
            $account->save();

            // Only rotate key if switching TO connector for the first time
            $apiKey = $previousMode !== 'connector'
                ? $account->rotateConnectorApiKey()
                : null;

            $this->phoneIndex->upsert($account->fresh());

            return response()->json([
                'message' => 'Connector mode enabled.',
                'account' => $account->fresh(),
                'connector_api_key' => $apiKey,
                'endpoint_url' => $this->buildSendEndpoint(),
            ]);
        }

        // Switching to managed_bot — clear connector fields
        $account->webhook_url = null;
        $account->connector_api_key = null;
        $account->save();

        $this->phoneIndex->upsert($account->fresh());

        return response()->json(['message' => 'Managed bot mode enabled.', 'account' => $account->fresh()]);
    }

    // ── Account health (live pull from Meta) ────────────────────────────────

    /**
     * Fetch and return the current health/metadata from Meta for a registered
     * number — quality rating, name status, throughput tier, etc.
     * Does NOT update the local record (use sync() for that).
     */
    public function health(WhatsappAccount $account): JsonResponse
    {
        try {
            $meta = $this->api->getPhoneNumber($account->phone_number_id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        return response()->json([
            'account' => $account,
            'meta' => $meta,
            'health' => $this->normaliseHealth($meta),
        ]);
    }

    // ── Sync account data from Meta ─────────────────────────────────────────

    public function sync(WhatsappAccount $account): JsonResponse
    {
        try {
            // Check if account has an access token
            if (empty($account->access_token)) {
                return response()->json([
                    'message' => 'Sync failed.',
                    'error' => 'Account has no access token. Please reconnect or re-onboard this number.',
                ], 422);
            }

            $client = new Client(['timeout' => 15]);
            $apiVersion = config('services.meta.api_version', 'v21.0');

            // Decrypt the access token
            try {
                $token = $account->access_token;
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Sync failed.',
                    'error' => 'Access token is corrupted. Please reconnect this number.',
                ], 422);
            }

            // First, test if the token is valid
            try {
                $testResponse = $client->get("https://graph.facebook.com/{$apiVersion}/me", [
                    'headers' => ['Authorization' => "Bearer {$token}"],
                ]);
                $testData = json_decode($testResponse->getBody()->getContents(), true);

                if (isset($testData['error'])) {
                    return response()->json([
                        'message' => 'Sync failed.',
                        'error' => 'Access token is invalid or expired: '.($testData['error']['message'] ?? 'Unknown error'),
                    ], 422);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'message' => 'Sync failed.',
                    'error' => 'Failed to validate access token: '.$e->getMessage(),
                ], 422);
            }

            // Fetch phone number details
            $phoneFields = 'verified_name,display_phone_number,quality_rating,status,'
                .'platform_type,throughput,code_verification_status,name_status,'
                .'whatsapp_business_manager_messaging_limit,health_status';

            $phoneResponse = $client->get("https://graph.facebook.com/{$apiVersion}/{$account->phone_number_id}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query' => ['fields' => $phoneFields],
            ]);
            $phoneData = json_decode($phoneResponse->getBody()->getContents(), true);

            if (isset($phoneData['error'])) {
                return response()->json([
                    'message' => 'Sync failed.',
                    'error' => 'Meta API error (phone): '.($phoneData['error']['message'] ?? 'Unknown error'),
                ], 422);
            }

            // Fetch WABA details
            $wabaResponse = $client->get("https://graph.facebook.com/{$apiVersion}/{$account->waba_id}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query' => ['fields' => 'currency,timezone_id,account_review_status'],
            ]);
            $wabaData = json_decode($wabaResponse->getBody()->getContents(), true);

            if (isset($wabaData['error'])) {
                return response()->json([
                    'message' => 'Sync failed.',
                    'error' => 'Meta API error (WABA): '.($wabaData['error']['message'] ?? 'Unknown error'),
                ], 422);
            }

            $canSend = $phoneData['health_status']['can_send_message'] ?? null;

            // Get the messaging limit from the correct field name
            $messagingLimit = $phoneData['whatsapp_business_manager_messaging_limit'] ?? null;

            // Map tier values to your enum values - support all possible values
            $messagingLimitMapped = $this->mapMessagingLimit($messagingLimit);

            // Prepare metadata - store all extra fields here
            $metadata = array_merge($account->metadata ?? [], [
                'phone_status' => $phoneData['status'] ?? null,
                'platform_type' => $phoneData['platform_type'] ?? null,
                'throughput_level' => $phoneData['throughput']['level'] ?? $phoneData['throughput'] ?? null,
                'code_verification_status' => $phoneData['code_verification_status'] ?? null,
                'name_status' => $phoneData['name_status'] ?? null,
                'account_review_status' => $wabaData['account_review_status'] ?? null,
                'currency' => $wabaData['currency'] ?? null,
                'timezone_id' => $wabaData['timezone_id'] ?? null,
                'can_send_message' => $canSend,
                'messaging_limit_raw' => $messagingLimit,
                'last_sync' => now()->toIso8601String(),
                'sync_data' => [
                    'phone' => $phoneData,
                    'waba' => $wabaData,
                ],
            ]);

            // Update only the fields that exist in the table
            $account->update([
                'verified_name' => $phoneData['verified_name'] ?? $account->verified_name,
                'display_phone_number' => $phoneData['display_phone_number'] ?? $account->display_phone_number,
                'quality_rating' => strtoupper($phoneData['quality_rating'] ?? $account->quality_rating),
                'messaging_limit' => $messagingLimitMapped,
                'metadata' => $metadata,
                'last_synced_at' => now(),
                'onboarding_status' => $this->determineOnboardingStatus($account, $canSend),
            ]);

            // Update phone index if mode is set
            if (!$this->phoneIndex->hasIndex($account) && $account->mode !== null) {
                $this->phoneIndex->upsert($account->fresh());
            }

            activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account synced');

            return response()->json([
                'message' => 'Account synced successfully.',
                'account' => $account->fresh(),
                'meta' => [
                    'phone_data' => $phoneData,
                    'waba_data' => $wabaData,
                ],
            ]);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            $errorMessage = $data['error']['message'] ?? $e->getMessage();
            $errorCode = $data['error']['code'] ?? null;

            Log::error('WhatsApp sync failed', [
                'account_id' => $account->id,
                'phone_number' => $account->phone_number,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            return response()->json([
                'message' => 'Sync failed.',
                'error' => $errorMessage,
                'code' => $errorCode,
            ], 422);
        } catch (ServerException $e) {
            Log::error('WhatsApp sync failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Sync failed.',
                'error' => 'Meta server error. Please try again later.',
            ], 503);
        } catch (\Exception $e) {
            Log::error('WhatsApp sync failed', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Sync failed.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    // ── Debug tools (hit Meta directly) ─────────────────────────────────────

    /**
     * Test the access token for debugging purposes.
     */
    public function testToken(WhatsappAccount $account): JsonResponse
    {
        try {
            if (empty($account->access_token)) {
                return response()->json([
                    'valid' => false,
                    'message' => 'No access token found.',
                ], 422);
            }

            $token = decrypt($account->access_token);
            $apiVersion = config('services.meta.api_version', 'v21.0');

            $client = new Client(['timeout' => 15]);
            $response = $client->get("https://graph.facebook.com/{$apiVersion}/me", [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return response()->json([
                'valid' => true,
                'message' => 'Token is valid',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'valid' => false,
                'message' => 'Token is invalid',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Test if the phone number exists in Meta for debugging.
     */
    public function testPhoneNumber(WhatsappAccount $account): JsonResponse
    {
        try {
            if (empty($account->access_token)) {
                return response()->json([
                    'exists' => false,
                    'message' => 'No access token found.',
                ], 422);
            }

            $token = decrypt($account->access_token);
            $apiVersion = config('services.meta.api_version', 'v21.0');

            $client = new Client(['timeout' => 15]);
            $response = $client->get("https://graph.facebook.com/{$apiVersion}/{$account->phone_number_id}", [
                'headers' => ['Authorization' => "Bearer {$token}"],
                'query' => ['fields' => 'verified_name,display_phone_number,quality_rating,status'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return response()->json([
                'exists' => true,
                'message' => 'Phone number exists in Meta',
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'exists' => false,
                'message' => 'Phone number not found or error occurred',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Normalise Meta's raw metadata into a consistent shape for the health
     * dashboard panel — label, colour, and a short explanation for each field.
     */
    private function normaliseHealth(array $meta): array
    {
        return [
            'quality_rating' => [
                'value' => $meta['quality_rating'] ?? 'UNKNOWN',
                'color' => match ($meta['quality_rating'] ?? '') {
                    'GREEN' => 'success',
                    'YELLOW' => 'warning',
                    'RED' => 'error',
                    default => 'default',
                },
                'label' => match ($meta['quality_rating'] ?? '') {
                    'GREEN' => 'High quality',
                    'YELLOW' => 'Medium quality — review recent messages',
                    'RED' => 'Low quality — number may be restricted soon',
                    default => 'Unknown',
                },
            ],
            'name_status' => [
                'value' => $meta['name_status'] ?? 'UNKNOWN',
                'color' => match ($meta['name_status'] ?? '') {
                    'APPROVED' => 'success',
                    'PENDING_REVIEW' => 'warning',
                    'DECLINED' => 'error',
                    default => 'default',
                },
                'label' => match ($meta['name_status'] ?? '') {
                    'APPROVED' => 'Display name approved',
                    'PENDING_REVIEW' => 'Display name under review',
                    'DECLINED' => 'Display name rejected — update required',
                    default => 'Unknown',
                },
            ],
            'status' => [
                'value' => $meta['status'] ?? 'UNKNOWN',
                'color' => match ($meta['status'] ?? '') {
                    'CONNECTED' => 'success',
                    'OFFLINE' => 'error',
                    'PENDING' => 'warning',
                    default => 'default',
                },
            ],
            'messaging_limit_tier' => $meta['messaging_limit_tier'] ?? null,
            'throughput' => $meta['throughput'] ?? null,
        ];
    }

    /**
     * Map Meta's messaging limit values to your enum values.
     */
    private function mapMessagingLimit(?string $limit): string
    {
        if (empty($limit)) {
            return 'TIER_1K';
        }

        // Map all possible values to your enum
        return match (strtoupper($limit)) {
            'TIER_250', 'DEVELOPMENT', 'LIMITED' => 'TIER_1K',
            'TIER_2K' => 'TIER_2K',
            'TIER_10K', 'STANDARD', 'HIGH' => 'TIER_10K',
            'TIER_100K' => 'TIER_100K',
            'TIER_UNLIMITED', 'UNLIMITED' => 'TIER_UNLIMITED',
            default => 'TIER_1K',
        };
    }

    /**
     * Determine the onboarding status based on account state and Meta's health status.
     */
    private function determineOnboardingStatus(WhatsappAccount $account, $canSend): string
    {
        // If the account is already active, keep it unless there's a problem
        if ($account->onboarding_status === 'active') {
            // If we have health status and sending is not available, maybe it's suspended
            if ($canSend && $canSend !== 'AVAILABLE') {
                return 'suspended';
            }

            return 'active';
        }

        // If sending is available, mark as active
        if ($canSend === 'AVAILABLE') {
            return 'active';
        }

        // If we have a payment issue
        if ($account->onboarding_status === 'pending_payment') {
            return 'pending_payment';
        }

        // If the number is verified but not yet active
        $metadata = $account->metadata ?? [];
        if (($metadata['code_verification_status'] ?? null) === 'VERIFIED') {
            return 'verified';
        }

        // Default to pending
        return $account->onboarding_status ?? 'pending';
    }

    private function buildSendEndpoint(): string
    {
        return rtrim(config('app.url'), '/').'/api/connector/messages';
    }

    private function assertPhoneNotAlreadyClaimed(string $phoneNumberId): void
    {
        // 1. Check the central phone index (cross-tenant guard)
        $claimedCentrally = WhatsappPhoneIndex::where('phone_number_id', $phoneNumberId)
            ->where('is_active', true)
            ->exists();

        if ($claimedCentrally) {
            throw new \RuntimeException('This WhatsApp number is already connected to another account. Each number can only be active in one place at a time.');
        }

        // 2. Check the current tenant's own DB (idempotency guard)
        if (WhatsappAccount::where('phone_number_id', $phoneNumberId)->exists()) {
            throw new \RuntimeException('This WhatsApp number is already connected to your account.');
        }
    }

    private function assertPhoneNumberNotRegistered(string $e164PhoneNumber): void
    {
        $claimedCentrally = WhatsappPhoneIndex::where('phone_number', $e164PhoneNumber)
            ->where('is_active', true)
            ->exists();

        if ($claimedCentrally) {
            throw new \RuntimeException('This phone number is already registered on another account. Contact support if you believe this is an error.');
        }

        if (WhatsappAccount::where('phone_number', $e164PhoneNumber)->exists()) {
            throw new \RuntimeException('This phone number is already registered on your account.');
        }
    }
}