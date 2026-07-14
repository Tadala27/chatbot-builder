<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\WhatsappCloudApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Backs the 4-step phone registration form for the tech-provider model.
 *
 * Endpoints:
 *   POST   /tenant/api/whatsapp/register/add-number    → addNumber()
 *   POST   /tenant/api/whatsapp/register/request-code  → requestCode()
 *   POST   /tenant/api/whatsapp/register/verify-code   → verifyCode()
 *   POST   /tenant/api/whatsapp/register/complete       → completeRegistration()
 *   GET    /tenant/api/whatsapp/accounts/{id}/health    → health()
 *   POST   /tenant/api/whatsapp/accounts/{id}/sync      → sync()
 */
class WhatsappRegistrationController extends Controller
{
    public function __construct(
        private WhatsappCloudApiService $api
    ) {
    }

    // ── Step 1: add number to WABA ────────────────────────────────────────

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

        // Prevent duplicate registration of a number already in this tenant
        if (WhatsappAccount::where('phone_number', $e164)->exists()) {
            return response()->json([
                'message' => 'This phone number is already registered in your account.',
            ], 422);
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
            'mode' => 'managed_bot',
            'access_token' => config('services.whatsapp.system_user_token'),
            'webhook_verify_token' => config('services.whatsapp.verify_token'),
            'is_active' => false,
            'onboarding_status' => 'pending',
            'onboarding_method' => 'registered_number', // Using the manual method
        ]);

        activity()->causedBy(auth()->user())->performedOn($account)
            ->log('Phone number added to WABA');

        return response()->json([
            'message' => 'Phone number added successfully. Please request verification code.',
            'account' => $account,
            'phone_number_id' => $phoneNumberId,
        ], 201);
    }
    // ── Step 2: request OTP code ──────────────────────────────────────────

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

    // ── Step 3: submit OTP code ───────────────────────────────────────────

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

    // ── Step 4: register + set PIN ────────────────────────────────────────

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

    // ── Account health ────────────────────────────────────────────────────

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

    /**
     * Pull the latest metadata from Meta and update the local record.
     * Call this from a scheduled command or manually from the UI.
     */
    public function sync(WhatsappAccount $account): JsonResponse
    {
        try {
            $meta = $this->api->getPhoneNumber($account->phone_number_id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $account->update([
            'verified_name' => $meta['verified_name'] ?? $account->verified_name,
            'quality_rating' => $meta['quality_rating'] ?? $account->quality_rating,
            'display_phone_number' => $meta['display_phone_number'] ?? $account->display_phone_number,
            'last_synced_at' => now(),
        ]);

        return response()->json([
            'account' => $account->fresh(),
            'health' => $this->normaliseHealth($meta),
        ]);
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
}
