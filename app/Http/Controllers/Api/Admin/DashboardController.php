<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard.
     *
     * Returns all stats needed to render the super-admin dashboard.
     * Runs entirely on the landlord (central) database.
     */
    public function index(): JsonResponse
    {
        $user = Auth::guard('system')->user();

        Log::debug([
            'user' => $user->toArray(),
            'permissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'roles' => $user->getRoleNames()->toArray(),
        ]);
        $now = now();
        $start = $now->copy()->startOfMonth();
        $prev = $now->copy()->subMonth()->startOfMonth();
        $prevEnd = $now->copy()->subMonth()->endOfMonth();

        // ── Tenants ───────────────────────────────────────────────────────
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();

        $newThisMonth = Tenant::whereBetween('created_at', [$start, $now])->count();
        $newPrevMonth = Tenant::whereBetween('created_at', [$prev, $prevEnd])->count();
        $tenantsChange = $this->percentChange($newPrevMonth, $newThisMonth);

        // Subscription tier breakdown
        $tierBreakdown = Tenant::select('subscription_tier', DB::raw('COUNT(*) as total'))
            ->groupBy('subscription_tier')
            ->pluck('total', 'subscription_tier');

        // Expiring subscriptions (next 30 days)
        $expiringSoon = Tenant::where('is_active', true)
            ->whereNotNull('subscription_expires_at')
            ->whereBetween('subscription_expires_at', [$now, $now->copy()->addDays(30)])
            ->count();

        // Expired / suspended
        $expiredTenants = Tenant::where('is_active', true)
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<', $now)
            ->count();

        // ── Central users (system admins / ops) ───────────────────────────
        $totalAdmins = User::count();
        $activeAdmins = User::where('is_active', true)->count();

        // ── Tenant growth chart (last 12 months) ──────────────────────────
        $growthRaw = Tenant::select(
            DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
            DB::raw('COUNT(*) as total')
        )
            ->where('created_at', '>=', $now->copy()->subMonths(11)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->keyBy('month')
            ->map(fn ($r) => (int) $r->total);

        $growthChart = [];
        for ($i = 11; $i >= 0; --$i) {
            $month = $now->copy()->subMonths($i)->format('Y-m');
            $growthChart[] = [
                'month' => $month,
                'total' => $growthRaw[$month] ?? 0,
            ];
        }

        // ── New tenants this month ─────────────────────────────────────────
        $recentTenants = Tenant::select('id', 'name', 'slug', 'subscription_tier',
            'is_active', 'subscription_expires_at', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // ── Tenants expiring soon (detail list) ───────────────────────────
        $expiringList = Tenant::select('id', 'name', 'slug', 'subscription_tier',
            'subscription_expires_at', 'is_active')
            ->where('is_active', true)
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '>=', $now)
            ->where('subscription_expires_at', '<=', $now->copy()->addDays(30))
            ->orderBy('subscription_expires_at')
            ->limit(10)
            ->get()
            ->map(fn ($t) => array_merge($t->toArray(), [
                'days_left' => (int) $now->diffInDays($t->subscription_expires_at),
            ]));

        // ── Deployment mode breakdown ─────────────────────────────────────
        $deploymentBreakdown = Tenant::select('deployment_mode', DB::raw('COUNT(*) as total'))
            ->groupBy('deployment_mode')
            ->pluck('total', 'deployment_mode');

        return response()->json([
            'stats' => [
                'tenants' => [
                    'total' => $totalTenants,
                    'active' => $activeTenants,
                    'inactive' => $totalTenants - $activeTenants,
                    'new_this_month' => $newThisMonth,
                    'new_prev_month' => $newPrevMonth,
                    'change_pct' => $tenantsChange,
                    'expiring_soon' => $expiringSoon,
                    'expired' => $expiredTenants,
                ],
                'admins' => [
                    'total' => $totalAdmins,
                    'active' => $activeAdmins,
                ],
                'subscription_tiers' => $tierBreakdown,
                'deployment_modes' => $deploymentBreakdown,
            ],
            'charts' => [
                'tenant_growth' => $growthChart,
            ],
            'recent_tenants' => $recentTenants,
            'expiring_soon' => $expiringList,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function percentChange(int $prev, int $current): ?float
    {
        if ($prev === 0) {
            return $current > 0 ? 100.0 : null;
        }

        return round((($current - $prev) / $prev) * 100, 1);
    }
}