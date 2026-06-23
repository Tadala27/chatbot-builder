<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Services\Bot\FacebookSignupService;
use App\Services\MetaPhoneNumberLookupService;
use App\Services\WhatsappPhoneIndexSync;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WhatsAppAccountController extends Controller
{
    public function __construct(
        protected FacebookSignupService $facebookService,
        protected WhatsappPhoneIndexSync $phoneIndex,
    ) {}

    // ── GET /tenant/whatsapp-accounts ──────────────────────────────────────
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

    // ── GET /tenant/whatsapp-accounts/connector ────────────────────────────
    // Returns the tenant's single connector-mode account (if any) and the
    // fixed send endpoint URL. Never returns the API key — that's shown
    // once, only at connectConnector()/rotateConnectorKey() time.
    public function connectorAccount(): JsonResponse
    {
        $account = WhatsappAccount::where('mode', 'connector')->first();

        if (!$account) {
            return response()->json(['account' => null]);
        }

        return response()->json([
            'account' => $account,
            'endpoint_url' => $this->buildSendEndpoint(),
        ]);
    }

    // ── POST /tenant/whatsapp-accounts/connect-connector ───────────────────
    // Phone number + webhook URL only. No OAuth, no tenant-supplied Meta
    // credentials. Resolves phone_number_id via the platform's own Tech
    // Provider Meta credentials (MetaPhoneNumberLookupService). One
    // connector account per tenant — blocks a second.
    public function connectConnector(
        Request $request,
        MetaPhoneNumberLookupService $lookup,
    ): JsonResponse {
        if (WhatsappAccount::where('mode', 'connector')->exists()) {
            return response()->json([
                'message' => 'You already have a connected number. Disconnect it first if you want to connect a different one.',
            ], 422);
        }

        $validated = $request->validate([
            'phone_number' => ['required', 'string'],
            'webhook_url' => ['required', 'url'],
        ]);

        $resolved = $lookup->resolveByPhoneNumber($validated['phone_number']);

        if (!$resolved) {
            return response()->json([
                'message' => "We couldn't find [{$validated['phone_number']}] under our WhatsApp Business " .
                    'platform. Make sure the number has been registered with us first, then try again.',
            ], 422);
        }

        if (WhatsappAccount::where('phone_number_id', $resolved['phone_number_id'])->exists()) {
            return response()->json([
                'message' => 'This phone number is already connected.',
            ], 422);
        }

        $account = WhatsappAccount::create([
            'phone_number_id' => $resolved['phone_number_id'],
            'waba_id' => $resolved['waba_id'],
            'phone_number' => preg_replace('/\D/', '', $resolved['display_phone_number']),
            'display_phone_number' => $resolved['display_phone_number'],
            'verified_name' => $resolved['verified_name'],
            'quality_rating' => $resolved['quality_rating'],
            'webhook_url' => $validated['webhook_url'],
            'is_active' => true,
            'mode' => 'connector',
        ]);

        $apiKey = $account->rotateConnectorApiKey();

        // Populates BOTH central indexes — phone (inbound) and key (outbound).
        $this->phoneIndex->upsert($account->fresh());

        activity()->causedBy(Auth::guard('tenant')->user())
            ->performedOn($account)
            ->log('WhatsApp number connected in connector mode');

        return response()->json([
            'message' => 'Number connected successfully.',
            'account' => $account->fresh(),
            'connector_api_key' => $apiKey,
            'endpoint_url' => $this->buildSendEndpoint(),
        ], 201);
    }

    // ── GET /tenant/whatsapp-accounts/signup-url ───────────────────────────
    // Managed-bot accounts only — embedded signup OAuth flow.
    public function getSignupUrl(): JsonResponse
    {
        $url = $this->facebookService->getSignupUrl(tenant());

        return response()->json(['signup_url' => $url]);
    }

    // ── POST /tenant/whatsapp-accounts/callback ────────────────────────────
    public function handleCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $result = $this->facebookService->handleCallback($validated['code'], $validated['state']);

            return response()->json([
                'message' => 'WhatsApp account connected successfully.',
                'whatsapp_account' => $result['whatsapp_account'],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect WhatsApp account.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    // ── GET /tenant/whatsapp-accounts/{account} ────────────────────────────
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
                'is_healthy' => $account->isHealthy(),
                'mode' => $account->mode,
            ],
        ]);
    }

    // ── PUT /tenant/whatsapp-accounts/{account} ────────────────────────────
    public function update(Request $request, WhatsappAccount $account): JsonResponse
    {
        $validated = $request->validate([
            'metadata' => 'sometimes|array',
        ]);

        $account->update($validated);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account updated');

        return response()->json(['message' => 'Account updated.', 'account' => $account]);
    }

    // ── POST /tenant/whatsapp-accounts/{account}/disconnect ────────────────
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

        // Remove from both central indexes — inbound stops forwarding,
        // outbound key stops authenticating.
        $this->phoneIndex->remove($account);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account disconnected');

        return response()->json(['message' => 'Account disconnected.']);
    }

    // ── POST /tenant/whatsapp-accounts/{account}/reconnect ─────────────────
    public function reconnect(WhatsappAccount $account): JsonResponse
    {
        $account->update(['is_active' => true]);

        $this->phoneIndex->upsert($account);

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account reconnected');

        return response()->json(['message' => 'Account reconnected.', 'account' => $account]);
    }

    // ── POST /tenant/whatsapp-accounts/{account}/sync ──────────────────────
    // Managed-bot only — connector accounts have no access_token to sync with.
    public function sync(WhatsappAccount $account): JsonResponse
    {
        if (!$account->hasOwnAccessToken()) {
            return response()->json(['message' => 'Sync is not available for connector-mode accounts.'], 422);
        }

        try {
            $client = new Client();
            $response = $client->get("https://graph.facebook.com/v18.0/{$account->phone_number_id}", [
                'query' => [
                    'access_token' => decrypt($account->access_token),
                    'fields' => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $account->update([
                'verified_name' => $data['verified_name'] ?? $account->verified_name,
                'quality_rating' => strtoupper($data['quality_rating'] ?? $account->quality_rating),
                'messaging_limit' => $data['messaging_limit_tier'] ?? $account->messaging_limit,
                'metadata' => $data,
                'last_synced_at' => now(),
            ]);

            activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('WhatsApp account synced');

            return response()->json(['message' => 'Account synced.', 'account' => $account->fresh()]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Sync failed.', 'error' => $e->getMessage()], 422);
        }
    }

    // ── GET /tenant/whatsapp-accounts/{account}/health ─────────────────────
    public function health(WhatsappAccount $account): JsonResponse
    {
        $issues = [];

        if ($account->quality_rating === 'RED') {
            $issues[] = ['severity' => 'critical', 'message' => 'Quality rating is RED — account may be restricted.'];
        } elseif ($account->quality_rating === 'YELLOW') {
            $issues[] = ['severity' => 'warning', 'message' => 'Quality rating is YELLOW — improve message quality.'];
        }

        if (!$account->is_active) {
            $issues[] = ['severity' => 'warning', 'message' => 'Account is inactive.'];
        }

        if ($account->last_synced_at?->diffInHours(now()) > 24) {
            $issues[] = ['severity' => 'info', 'message' => 'Account has not been synced in over 24 hours.'];
        }

        return response()->json([
            'status' => empty($issues) ? 'healthy' : 'needs_attention',
            'mode' => $account->mode,
            'quality_rating' => $account->quality_rating,
            'messaging_limit' => $account->messaging_limit,
            'is_active' => $account->is_active,
            'last_synced_at' => $account->last_synced_at?->toIso8601String(),
            'verified_name' => $account->verified_name,
            'issues' => $issues,
        ]);
    }

    // ── POST /tenant/whatsapp-accounts/{account}/rotate-connector-key ──────
    public function rotateConnectorKey(WhatsappAccount $account): JsonResponse
    {
        if (!$account->isConnectorMode()) {
            return response()->json(['message' => 'Account is not in connector mode.'], 422);
        }

        $newKey = $account->rotateConnectorApiKey();

        // Critical: re-sync the key index immediately, or the OLD key
        // keeps resolving (stale index row) even though the account's
        // actual connector_api_key has changed.
        $this->phoneIndex->syncKeyIndex($account->fresh());

        activity()->causedBy(Auth::guard('tenant')->user())->performedOn($account)->log('Connector API key rotated');

        return response()->json([
            'message' => 'Connector API key rotated. Update your integration immediately — the old key no longer works.',
            'connector_api_key' => $newKey,
        ]);
    }

    /**
     * Same URL for EVERY tenant — no slug, no per-tenant path segment.
     * The X-Connector-Key header alone determines tenant resolution
     * (see ResolveTenantFromConnectorKey).
     */
    private function buildSendEndpoint(): string
    {
        return rtrim(config('app.url'), '/') . '/api/connector/messages';
    }
}