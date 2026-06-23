<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PlatformAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $period = $request->period ?? '30d';
        $from   = $this->periodToDate($period);

        return response()->json([
            'period'   => $period,
            'from'     => $from->toDateString(),
            'to'       => now()->toDateString(),
            'tenants'  => $this->tenantStats($from),
            'growth'   => $this->growthStats($from),
            'tiers'    => $this->tierBreakdown(),
        ]);
    }

    private function tenantStats(\Carbon\Carbon $from): array
    {
        return Cache::remember("platform.analytics.tenants.{$from->toDateString()}", 300, function () use ($from) {
            return [
                'total'        => Tenant::count(),
                'active'       => Tenant::where('is_active', true)->count(),
                'new_in_period' => Tenant::where('created_at', '>=', $from)->count(),
                'churned'      => Tenant::onlyTrashed()
                    ->where('deleted_at', '>=', $from)
                    ->count(),
            ];
        });
    }

    private function growthStats(\Carbon\Carbon $from): array
    {
        // New tenants grouped by week over the period
        return Cache::remember("platform.analytics.growth.{$from->toDateString()}", 300, function () use ($from) {
            return Tenant::query()
                ->selectRaw('DATE_FORMAT(created_at, "%Y-%u") as week, COUNT(*) as count')
                ->where('created_at', '>=', $from)
                ->groupBy('week')
                ->orderBy('week')
                ->get()
                ->toArray();
        });
    }

    private function tierBreakdown(): array
    {
        return Cache::remember('platform.analytics.tiers', 60, function () {
            return Tenant::query()
                ->selectRaw('subscription_tier, COUNT(*) as count')
                ->groupBy('subscription_tier')
                ->pluck('count', 'subscription_tier')
                ->toArray();
        });
    }

    private function periodToDate(string $period): \Carbon\Carbon
    {
        return match ($period) {
            '7d'  => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            '1y'  => now()->subYear(),
            default => now()->subDays(30),
        };
    }
}