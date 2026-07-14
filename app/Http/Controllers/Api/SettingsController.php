<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $currentTenant = tenant();

        return response()->json([
            'settings' => $currentTenant->settings ?? [],
            'subscription' => [
                'tier' => $currentTenant->subscription_tier,
                'expires_at' => $currentTenant->subscription_expires_at?->toIso8601String(),
                'is_active' => $currentTenant->isSubscriptionActive(),
                'max_bots' => $currentTenant->max_bots,
                'max_conversations_per_month' => $currentTenant->max_conversations_per_month,
                'usage_percentage' => $currentTenant->getUsagePercentage(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $currentTenant = tenant();

        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $currentTenant->update([
            'settings' => array_merge($currentTenant->settings ?? [], $validated['settings']),
        ]);

        activity()->causedBy(auth()->user())->performedOn($currentTenant)->log('Settings updated');

        return response()->json([
            'message' => 'Settings updated.',
            'settings' => $currentTenant->fresh()->settings,
        ]);
    }
}