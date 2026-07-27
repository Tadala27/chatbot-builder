<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\WhatsappPhoneIndexSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        protected WhatsappPhoneIndexSync $phoneIndex,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = WhatsappAccount::with('bots')->latest();

        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }

        $accounts = $query->get()->map(function ($account) {
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
     * Locally-derived health summary, built from data already stored on the
     * account (i.e. whatever the last sync pulled in). For a live pull from
     * Meta, see WhatsappRegistrationController::health() / ::sync().
     */
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

    /**
     * Get the raw sync data already stored in metadata for debugging.
     * (Read-only — does not call Meta. For a live pull, see
     * WhatsappRegistrationController::sync().).
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

    private function buildSendEndpoint(): string
    {
        return rtrim(config('app.url'), '/').'/api/connector/messages';
    }
}