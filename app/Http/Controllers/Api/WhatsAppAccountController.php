<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\WhatsappAccount;
use App\Services\FacebookSignupService;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WhatsAppAccountController extends Controller
{
    public function __construct(protected FacebookSignupService $facebookService) {}

    // GET /api/whatsapp-accounts
    public function index(): JsonResponse
    {
        $tenant   = Tenant::current();
        $accounts = WhatsappAccount::where('tenant_id', $tenant->id)
            ->with('bots')
            ->latest()
            ->get()
            ->map(function ($account) {
                $account->stats = [
                    'total_bots'             => $account->bots()->count(),
                    'active_bots'            => $account->bots()->where('is_active', true)->count(),
                    'total_conversations'     => $account->conversations()->count(),
                    'active_conversations'    => $account->conversations()->where('status', 'active')->count(),
                ];
                return $account;
            });

        return response()->json(['data' => $accounts]);
    }

    // GET /api/whatsapp-accounts/{account}
    public function show(WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

        $account->load(['bots', 'conversations' => fn($q) => $q->latest()->limit(10)]);

        return response()->json([
            'account' => $account,
            'stats'   => [
                'total_bots'              => $account->bots()->count(),
                'active_bots'             => $account->bots()->where('is_active', true)->count(),
                'total_conversations'     => $account->conversations()->count(),
                'conversations_today'     => $account->conversations()->whereDate('started_at', today())->count(),
                'conversations_this_month' => $account->conversations()->whereMonth('started_at', now()->month)->count(),
                'quality_rating'          => $account->quality_rating,
                'messaging_limit'         => $account->messaging_limit,
                'is_healthy'              => $account->isHealthy(),
            ],
        ]);
    }

    // GET /api/whatsapp-accounts/signup-url
    public function getSignupUrl(): JsonResponse
    {
        $url = $this->facebookService->getSignupUrl(Tenant::current());
        return response()->json(['signup_url' => $url]);
    }

    // POST /api/whatsapp-accounts/callback
    public function handleCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code'  => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $result = $this->facebookService->handleCallback($validated['code'], $validated['state']);

            return response()->json([
                'message'          => 'WhatsApp account connected successfully.',
                'whatsapp_account' => $result['whatsapp_account'],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect WhatsApp account.',
                'error'   => $e->getMessage(),
            ], 422);
        }
    }

    // PUT /api/whatsapp-accounts/{account}
    public function update(Request $request, WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

        $validated = $request->validate([
            'metadata' => 'sometimes|array',
        ]);

        $account->update($validated);

        activity()->causedBy(auth()->user())->performedOn($account)->log('WhatsApp account updated');

        return response()->json(['message' => 'Account updated.', 'account' => $account]);
    }

    // POST /api/whatsapp-accounts/{account}/disconnect
    public function disconnect(WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

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

        activity()->causedBy(auth()->user())->performedOn($account)->log('WhatsApp account disconnected');

        return response()->json(['message' => 'Account disconnected.']);
    }

    // POST /api/whatsapp-accounts/{account}/reconnect
    public function reconnect(WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

        $account->update(['is_active' => true]);

        activity()->causedBy(auth()->user())->performedOn($account)->log('WhatsApp account reconnected');

        return response()->json(['message' => 'Account reconnected.', 'account' => $account]);
    }

    // POST /api/whatsapp-accounts/{account}/sync
    public function sync(WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

        try {
            $client   = new Client();
            $response = $client->get("https://graph.facebook.com/v18.0/{$account->phone_number_id}", [
                'query' => [
                    'access_token' => decrypt($account->access_token),
                    'fields'       => 'verified_name,display_phone_number,quality_rating,messaging_limit_tier',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $account->update([
                'verified_name'  => $data['verified_name']       ?? $account->verified_name,
                'quality_rating' => strtoupper($data['quality_rating'] ?? $account->quality_rating),
                'messaging_limit' => $data['messaging_limit_tier'] ?? $account->messaging_limit,
                'metadata'       => $data,
                'last_synced_at' => now(),
            ]);

            activity()->causedBy(auth()->user())->performedOn($account)->log('WhatsApp account synced');

            return response()->json(['message' => 'Account synced.', 'account' => $account->fresh()]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Sync failed.', 'error' => $e->getMessage()], 422);
        }
    }

    // GET /api/whatsapp-accounts/{account}/health
    public function health(WhatsappAccount $account): JsonResponse
    {
        $this->authorizeAccount($account);

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
            'status'          => empty($issues) ? 'healthy' : 'needs_attention',
            'quality_rating'  => $account->quality_rating,
            'messaging_limit' => $account->messaging_limit,
            'is_active'       => $account->is_active,
            'last_synced_at'  => $account->last_synced_at?->toIso8601String(),
            'verified_name'   => $account->verified_name,
            'issues'          => $issues,
        ]);
    }

    // -------------------------------------------------------------------------

    private function authorizeAccount(WhatsappAccount $account): void
    {
        if ($account->tenant_id !== Tenant::current()->id) {
            abort(404, 'Account not found.');
        }
    }
}
