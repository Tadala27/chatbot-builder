<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    // GET /api/dashboard/stats
    public function stats(): JsonResponse
    {
        $tenant = Tenant::current();

        // Bots are the primary unit. Flows hang off bots.
        $botIds  = $tenant->bots()->pluck('id');
        $flowIds = \App\Models\Flow::whereIn('bot_id', $botIds)->pluck('id');

        return response()->json([
            'bots' => [
                'total'  => $botIds->count(),
                'active' => $tenant->bots()->where('is_active', true)->count(),
            ],
            'flows' => [
                'total'     => $flowIds->count(),
                'published' => \App\Models\Flow::whereIn('bot_id', $botIds)->where('status', 'published')->count(),
                'draft'     => \App\Models\Flow::whereIn('bot_id', $botIds)->where('status', 'draft')->count(),
            ],
            'conversations' => [
                'total'      => $tenant->conversations()->count(),
                'active'     => $tenant->conversations()->where('status', 'active')->count(),
                'today'      => $tenant->conversations()->whereDate('started_at', today())->count(),
                'this_month' => $tenant->conversations()->whereMonth('started_at', now()->month)->count(),
            ],
            'whatsapp_accounts' => [
                'total'  => $tenant->whatsappAccounts()->count(),
                'active' => $tenant->whatsappAccounts()->where('is_active', true)->count(),
            ],
            'usage' => [
                'conversations_used'  => $tenant->conversations()->whereMonth('started_at', now()->month)->count(),
                'conversations_limit' => $tenant->max_conversations_per_month,
                'usage_percentage'    => $tenant->getUsagePercentage(),
            ],
            'recent_conversations' => $tenant->conversations()
                ->with(['flow.bot', 'whatsappAccount'])
                ->latest('last_message_at')
                ->limit(5)
                ->get(),
        ]);
    }
}
