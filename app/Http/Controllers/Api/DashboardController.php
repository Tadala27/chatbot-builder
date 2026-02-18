<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function stats(): JsonResponse
    {
        $tenant = Tenant::current();

        $stats = [
            'flows' => [  // was: 'chatbots'
                'total' => $tenant->flows()->count(),
                'active' => $tenant->flows()->published()->count(),  // was: where('is_active', true)
                'draft' => $tenant->flows()->draft()->count(),
            ],
            'conversations' => [
                'total' => $tenant->conversations()->count(),
                'active' => $tenant->conversations()->where('status', 'active')->count(),
                'today' => $tenant->conversations()->whereDate('started_at', today())->count(),
                'this_month' => $tenant->conversations()->whereMonth('started_at', now()->month)->count(),
            ],
            'whatsapp_accounts' => [
                'total' => $tenant->whatsappAccounts()->count(),
                'active' => $tenant->whatsappAccounts()->where('is_active', true)->count(),
            ],
            'usage' => [
                'conversations_used' => $tenant->conversations()->whereMonth('created_at', now()->month)->count(),
                'conversations_limit' => $tenant->max_conversations_per_month,
                'usage_percentage' => $tenant->getUsagePercentage(),
                'remaining' => $tenant->getConversationsThisMonth(),
            ],
           'recent_conversations' => $tenant->conversations()->with(['flow', 'whatsappAccount'])->latest('last_message_at')->limit(5)->get(),
        ];

        return response()->json($stats);
    }
}