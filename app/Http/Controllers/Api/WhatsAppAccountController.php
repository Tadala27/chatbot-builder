<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsappAccount;
use App\Models\Tenant;
use App\Services\FacebookSignupService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WhatsAppAccountController extends Controller
{
    protected FacebookSignupService $facebookService;

    public function __construct(FacebookSignupService $facebookService)
    {
        $this->facebookService = $facebookService;
    }

    /**
     * List WhatsApp accounts for current tenant
     */
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();

        $accounts = WhatsappAccount::where('tenant_id', $tenant->id)
            ->with('flows')
            ->orderBy('created_at', 'desc')
            ->get();

        // Add stats for each account
        $accounts->transform(function ($account) {
            $account->stats = [
                'total_chatbots' => $account->flows()->count(),
                'active_chatbots' => $account->flows()->active()->count(),
                'total_conversations' => $account->conversations()->count(),
                'active_conversations' => $account->conversations()->active()->count(),
            ];
            return $account;
        });

        return response()->json([
            'accounts' => $accounts,
        ]);
    }

    /**
     * Get single WhatsApp account
     */
    public function show(WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $account->load(['flows', 'conversations' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json([
            'account' => $account,
            'stats' => [
                'total_chatbots' => $account->flows()->count(),
                'active_chatbots' => $account->flows()->active()->count(),
                'total_conversations' => $account->conversations()->count(),
                'conversations_today' => $account->conversations()
                    ->whereDate('started_at', today())
                    ->count(),
                'conversations_this_month' => $account->conversations()
                    ->whereMonth('started_at', now()->month)
                    ->count(),
                'is_healthy' => $account->isHealthy(),
                'needs_attention' => $account->needsAttention(),
            ],
        ]);
    }

    /**
     * Get Facebook signup URL
     */
    public function getSignupUrl(): JsonResponse
    {
        $tenant = Tenant::current();

        $signupUrl = $this->facebookService->getSignupUrl($tenant);

        return response()->json([
            'signup_url' => $signupUrl,
        ]);
    }

    /**
     * Handle Facebook callback (after user completes signup)
     */
    public function handleCallback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'required|string',
        ]);

        try {
            $result = $this->facebookService->handleCallback(
                $validated['code'],
                $validated['state']
            );

            return response()->json([
                'message' => 'WhatsApp account connected successfully',
                'whatsapp_account' => $result['whatsapp_account'],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to connect WhatsApp account',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Disconnect WhatsApp account
     */
    public function disconnect(WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        // Check if account has active chatbots
        if ($account->flows()->where('is_active', true)->exists()) {
            return response()->json([
                'message' => 'Cannot disconnect account with active chatbots. Please deactivate all chatbots first.',
            ], 422);
        }

        // Check if account has active conversations
        if ($account->conversations()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot disconnect account with active conversations.',
            ], 422);
        }

        $account->update(['is_active' => false]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($account)
            ->log('WhatsApp account disconnected');

        return response()->json([
            'message' => 'WhatsApp account disconnected successfully',
        ]);
    }

    /**
     * Reconnect WhatsApp account
     */
    public function reconnect(WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $account->update(['is_active' => true]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($account)
            ->log('WhatsApp account reconnected');

        return response()->json([
            'message' => 'WhatsApp account reconnected successfully',
            'account' => $account,
        ]);
    }

    /**
     * Sync account details from WhatsApp API
     */
    public function sync(WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        try {
            $client = new \GuzzleHttp\Client();

            // Get phone number details
            $response = $client->get(
                "https://graph.facebook.com/v18.0/{$account->phone_number_id}",
                [
                    'query' => [
                        'access_token' => decrypt($account->access_token),
                        'fields' => 'verified_name,code_verification_status,display_phone_number,quality_rating,messaging_limit_tier',
                    ],
                ]
            );

            $data = json_decode($response->getBody()->getContents(), true);

            // Update account
            $account->updateFromMeta([
                'verified_name' => $data['verified_name'] ?? $account->verified_name,
                'quality_rating' => $data['quality_rating'] ?? $account->quality_rating,
                'messaging_limit' => $data['messaging_limit_tier'] ?? $account->messaging_limit,
                'metadata' => $data,
            ]);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($account)
                ->log('WhatsApp account synced');

            return response()->json([
                'message' => 'Account synced successfully',
                'account' => $account->fresh(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to sync account',
                'error' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Update account settings
     */
    public function update(Request $request, WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $validated = $request->validate([
            'metadata' => 'sometimes|array',
        ]);

        $account->update($validated);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($account)
            ->log('WhatsApp account updated');

        return response()->json([
            'message' => 'Account updated successfully',
            'account' => $account,
        ]);
    }

    /**
     * Get account health status
     */
    public function health(WhatsappAccount $account): JsonResponse
    {
        $tenant = Tenant::current();

        // Ensure account belongs to current tenant
        if ($account->tenant_id !== $tenant->id) {
            return response()->json(['message' => 'Account not found'], 404);
        }

        $health = [
            'status' => $account->isHealthy() ? 'healthy' : 'needs_attention',
            'quality_rating' => $account->quality_rating,
            'messaging_limit' => $account->messaging_limit,
            'messaging_limit_number' => $account->getMessagingLimitNumber(),
            'is_active' => $account->is_active,
            'last_synced' => $account->last_synced_at,
            'verified_name' => $account->verified_name,
            'issues' => [],
        ];

        // Check for issues
        if ($account->quality_rating === 'RED') {
            $health['issues'][] = [
                'severity' => 'critical',
                'message' => 'Quality rating is RED. Your account may be restricted.',
            ];
        } elseif ($account->quality_rating === 'YELLOW') {
            $health['issues'][] = [
                'severity' => 'warning',
                'message' => 'Quality rating is YELLOW. Improve message quality to avoid restrictions.',
            ];
        }

        if (!$account->is_active) {
            $health['issues'][] = [
                'severity' => 'warning',
                'message' => 'Account is inactive.',
            ];
        }

        if ($account->last_synced_at && $account->last_synced_at->diffInHours(now()) > 24) {
            $health['issues'][] = [
                'severity' => 'info',
                'message' => 'Account has not been synced in over 24 hours.',
            ];
        }

        return response()->json($health);
    }
}