<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Bot\MetaEmbeddedSignupService;
use App\Services\WhatsappPhoneIndexSync;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        protected WhatsappPhoneIndexSync $phoneIndex,
    ) {
    }

    public function index(): JsonResponse
    {
        $accounts = WhatsappAccount::with('bots')
            ->latest()
            ->get()
            ->map(function ($account) {
                $account->stats = [
                    'total_bots' => $account->bots()->count(),
                    'active_bots' => $account->bots()->where('is_active', true)->count(),
                    'total_conversations' => $account->conversations()->count(),
                    'active_conversations' => $account->conversations()->where('status', 'active')->count(),
                ];

                return $account;
            });

        return response()->json(['data' => $accounts]);
    }

    /**
     * Step 1–3 of Tech Provider onboarding, triggered by the frontend after
     * the FB SDK's Embedded Signup flow hands back a code + asset IDs via
     * the WA_EMBEDDED_SIGNUP postMessage event.
     */
    public function embeddedSignupCallback(Request $request, MetaEmbeddedSignupService $onboarding): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'waba_id' => ['required', 'string'],
            'phone_number_id' => ['required', 'string'],
            'business_id' => ['sometimes', 'string'],
        ]);

        if (WhatsappAccount::where('phone_number_id', $validated['phone_number_id'])->exists()) {
            return response()->json([
                'message' => 'This number is already connected to your account.',
            ], 422);
        }

        try {
            $account = $onboarding->onboard($validated);

            activity()->causedBy(Auth::guard('tenant')->user())
                ->performedOn($account)
                ->log('WhatsApp number onboarded via Embedded Signup');

            return response()->json([
                'message' => 'Number registered. Choose how you want to use it, then add a payment method to activate sending.',
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

        if ($account->mode !== null) {
            return response()->json(['message' => 'Mode has already been set for this account.'], 422);
        }

        $account->mode = $validated['mode'];

        if ($validated['mode'] === 'connector') {
            $account->webhook_url = $validated['webhook_url'];
            $account->save();

            $apiKey = $account->rotateConnectorApiKey();
            $this->phoneIndex->upsert($account->fresh());

            activity()->causedBy(Auth::guard('tenant')->user())
                ->performedOn($account)
                ->log('Connector mode enabled');

            return response()->json([
                'message' => 'Connector mode enabled.',
                'account' => $account->fresh(),
                'connector_api_key' => $apiKey,
                'endpoint_url' => $this->buildSendEndpoint(),
            ]);
        }

        $account->save();

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($account)
            ->log('Managed bot mode enabled');

        return response()->json(['message' => 'Managed bot mode enabled.', 'account' => $account]);
    }

    /**
     * Connector setup instructions for a SPECIFIC account.
     */
    public function connectorInfo(WhatsappAccount $account): JsonResponse
    {
        if (!$account->isConnectorMode()) {
            return response()->json(['message' => 'This account is not in connector mode.'], 422);
        }

        return response()->json([
            'account' => $account,
            'endpoint_url' => $this->buildSendEndpoint(),
        ]);
    }

    public function show(WhatsappAccount $account): JsonResponse
    {
        $account->load(['bots', 'conversations' => fn ($q) => $q->latest()->limit(10)]);

        return response()->json([
            'account' => $account,
            'stats' => [
                'total_bots' => $account->bots()->count(),
                'active_bots' => $account->bots()->where('is_active', true)->count(),
                'total_conversations' => $account->conversations()->count(),
                'conversations_today' => $account->conversations()->whereDate('started_at', today())->count(),
                'conversations_this_month' => $account->conversations()->whereMonth('started_at', now()->month)->count(),
                'quality_rating' => $account->quality_rating,
                'messaging_limit' => $account->messaging_limit,
                'onboarding_status' => $account->onboarding_status,
                'is_healthy' => $account->isHealthy(),
                'mode' => $account->mode,
                'phone_status' => $account->getPhoneStatusAttribute(),
                'code_verification_status' => $account->getCodeVerificationStatusAttribute(),
                'name_status' => $account->getNameStatusAttribute(),
                'platform_type' => $account->getPlatformTypeAttribute(),
                'throughput_level' => $account->getThroughputLevelAttribute(),
                'account_review_status' => $account->getAccountReviewStatusAttribute(),
                'currency' => $account->getCurrencyAttribute(),
                'timezone_id' => $account->getTimezoneIdAttribute(),
            ],
        ]);
    }

    public function update(Request $request, WhatsappAccount $account): JsonResponse
    {
        $validated = $request->validate([
            'metadata' => 'sometimes|array',
            'webhook_url' => 'sometimes|url',
            'is_active' => 'sometimes|boolean',
        ]);

        $account->update($validated);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account updated');

        return response()->json(['message' => 'Account updated.', 'account' => $account]);
    }

    public function disconnect(WhatsappAccount $account): JsonResponse
    {
        if ($account->bots()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Deactivate all bots on this account before disconnecting.',
            ], 422);
        }

        if ($account->conversations()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot disconnect while there are active conversations.',
            ], 422);
        }

        $account->update(['is_active' => false]);

        // Remove from both central indexes
        $this->phoneIndex->remove($account);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account disconnected');

        return response()->json(['message' => 'Account disconnected locally. To fully remove the number from Meta, do so via WhatsApp Manager.']);
    }

    public function reconnect(WhatsappAccount $account): JsonResponse
    {
        $account->update(['is_active' => true]);

        $this->phoneIndex->upsert($account);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account reconnected');

        return response()->json(['message' => 'Account reconnected.', 'account' => $account]);
    }

    /**
     * Sync account data from Meta API.
     */
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
            'TIER_1K', 'DEVELOPMENT', 'LIMITED' => 'TIER_1K',
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

    public function health(WhatsappAccount $account): JsonResponse
    {
        $metadata = $account->metadata ?? [];
        $issues = [];

        // Check if there's a payment issue
        if ($account->onboarding_status === 'pending_payment') {
            $issues[] = ['severity' => 'critical', 'message' => 'No payment method on file — this number cannot send messages yet.'];
        }

        // Check quality rating
        if ($account->quality_rating === 'RED') {
            $issues[] = ['severity' => 'critical', 'message' => 'Quality rating is RED — account may be restricted.'];
        } elseif ($account->quality_rating === 'YELLOW') {
            $issues[] = ['severity' => 'warning', 'message' => 'Quality rating is YELLOW — improve message quality.'];
        }

        // Check phone status from metadata
        if (($metadata['phone_status'] ?? null) === 'RESTRICTED') {
            $issues[] = ['severity' => 'critical', 'message' => 'Number is restricted — messaging limit reached.'];
        }

        // Check if account is inactive
        if (!$account->is_active) {
            $issues[] = ['severity' => 'warning', 'message' => 'Account is inactive.'];
        }

        // Check if account hasn't been synced recently
        if ($account->last_synced_at?->diffInHours(now()) > 24) {
            $issues[] = ['severity' => 'info', 'message' => 'Account has not been synced in over 24 hours.'];
        }

        // Check if there are any SIP errors in the health status
        $syncData = $metadata['sync_data'] ?? [];
        $phoneData = $syncData['phone'] ?? [];
        $healthStatus = $phoneData['health_status'] ?? [];
        $entities = $healthStatus['entities'] ?? [];

        foreach ($entities as $entity) {
            if (!empty($entity['errors'])) {
                foreach ($entity['errors'] as $error) {
                    // Only show SIP errors as info since they don't affect messaging
                    if (strpos($error['error_description'] ?? '', 'SIP') !== false) {
                        $issues[] = [
                            'severity' => 'info',
                            'message' => $error['error_description'].' — '.($error['possible_solution'] ?? ''),
                        ];
                    }
                }
            }
        }

        // Get can_send_message from metadata
        $canSendMessage = $metadata['can_send_message'] ?? null;

        return response()->json([
            'status' => empty($issues) ? 'healthy' : 'needs_attention',
            'mode' => $account->mode,
            'onboarding_status' => $account->onboarding_status,
            'quality_rating' => $account->quality_rating,
            'messaging_limit' => $account->messaging_limit,
            'can_send_message' => $canSendMessage,
            'is_active' => $account->is_active,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'verified_name' => $account->verified_name,
            'phone_status' => $metadata['phone_status'] ?? null,
            'code_verification_status' => $metadata['code_verification_status'] ?? null,
            'name_status' => $metadata['name_status'] ?? null,
            'account_review_status' => $metadata['account_review_status'] ?? null,
            'platform_type' => $metadata['platform_type'] ?? null,
            'throughput_level' => $metadata['throughput_level'] ?? null,
            'currency' => $metadata['currency'] ?? null,
            'timezone_id' => $metadata['timezone_id'] ?? null,
            'issues' => $issues,
        ]);
    }

    public function rotateConnectorKey(WhatsappAccount $account): JsonResponse
    {
        if (!$account->isConnectorMode()) {
            return response()->json(['message' => 'Account is not in connector mode.'], 422);
        }

        $newKey = $account->rotateConnectorApiKey();

        $this->phoneIndex->syncKeyIndex($account->fresh());

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('Connector API key rotated');

        return response()->json([
            'message' => 'Connector API key rotated. Update your integration immediately — the old key no longer works.',
            'connector_api_key' => $newKey,
        ]);
    }

    private function buildSendEndpoint(): string
    {
        return rtrim(config('app.url'), '/').'/api/connector/messages';
    }

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

    /**
     * Get the raw sync data from metadata for debugging.
     */
    public function syncData(WhatsappAccount $account): JsonResponse
    {
        $metadata = $account->metadata ?? [];

        return response()->json([
            'account_id' => $account->id,
            'phone_number' => $account->phone_number,
            'last_synced_at' => $account->last_synced_at,
            'sync_data' => $metadata['sync_data'] ?? null,
            'raw_metadata' => $metadata,
        ]);
    }
}
