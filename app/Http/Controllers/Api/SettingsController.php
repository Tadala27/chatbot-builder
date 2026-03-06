<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    // GET /api/settings
    public function index(): JsonResponse
    {
        $tenant = Tenant::current();

        return response()->json([
            'settings'     => $tenant->settings ?? [],
            'subscription' => [
                'tier'                        => $tenant->subscription_tier,
                'expires_at'                  => $tenant->subscription_expires_at?->toIso8601String(),
                'is_active'                   => $tenant->isSubscriptionActive(),
                'max_flows'                   => $tenant->max_flows,
                'max_conversations_per_month' => $tenant->max_conversations_per_month,
                'usage_percentage'            => $tenant->getUsagePercentage(),
            ],
        ]);
    }

    // PUT /api/settings
    public function update(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $tenant->update([
            'settings' => array_merge($tenant->settings ?? [], $validated['settings']),
        ]);

        activity()->causedBy(auth()->user())->performedOn($tenant)->log('Settings updated');

        return response()->json([
            'message'  => 'Settings updated.',
            'settings' => $tenant->fresh()->settings,
        ]);
    }
}
