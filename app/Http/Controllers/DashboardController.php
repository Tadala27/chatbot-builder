<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Tenant;
use Illuminate\Http\Request;
use App\Models\EmployeeScorecard;
use App\Models\PerformancePeriod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    public function stats(Request $request)
    {
        $user = auth()->user();
        $role = $user->user_role;

        // Get role-specific dashboard data
        switch ($role) {
            case 'Super Admin':
                return $this->superAdminStats($user);
            case 'Finance Admin':
                return $this->financeAdminStats($user);
            case 'Group Admin':
                return $this->groupAdminStats($user);
            case 'Tenant Admin':
                return $this->tenantAdminStats($user);
            case 'Tenant User':
            default:
                return $this->tenantUserStats($user);
        }
    }

    private function superAdminStats(User $user)
    {
        // Get all tenants
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();

        // Get all users
        $totalUsers = User::count();
        $systemUsers = User::whereNull('tenant_id')->count();
        $tenantUsers = User::whereNotNull('tenant_id')->count();

        // Get recent activity logs
        $recentLogs = Activity::with('causer')
            ->latest()
            ->limit(5)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'description' => $log->description,
                    'event' => $log->event,
                    'causer' => $log->causer ? [
                        'email' => $log->causer->email,
                        'name' => $log->causer->full_name ?? $log->causer->email
                    ] : null,
                    'created_at' => $log->created_at->toIso8601String()
                ];
            });

        // Get attention items
        $attentionItems = $this->getSuperAdminAttentionItems();

        // Get system activity data (last 7 days)
        $activityChart = $this->getSystemActivityChart();

        // Get storage breakdown
        $storageBreakdown = $this->getStorageBreakdown();

        // Calculate total storage
        $totalStorageLimit = 107374182400; // 100 GB default
        $totalStorageUsed = $storageBreakdown['avatars']['size'] + $storageBreakdown['attachments']['size'];
        $totalStorageAvailable = $totalStorageLimit - $totalStorageUsed;

        // Calculate subscription percentage
        $subscriptionPercentage = $totalTenants > 0
            ? round(($activeTenants / $totalTenants) * 100, 1)
            : 100;

        return response()->json([
            'success' => true,
            'data' => [
                'cards' => [
                    [
                        'total_tenants' => $totalTenants,
                        'system_users' => $systemUsers,
                        'tenant_users' => $tenantUsers,
                        'active_subscriptions' => $activeTenants,
                        'subscription_percentage' => $subscriptionPercentage . '%'
                    ]
                ],
                'recent_activity' => $recentLogs,
                'attention_items' => $attentionItems,
                'activity_chart' => $activityChart,
                'storage' => [
                    'total' => $totalStorageLimit,
                    'used' => $totalStorageUsed,
                    'available' => $totalStorageAvailable,
                    'percentage' => $totalStorageLimit > 0 ? round(($totalStorageUsed / $totalStorageLimit) * 100, 2) : 0,
                    'breakdown' => $storageBreakdown
                ]
            ]
        ]);
    }

    private function getStorageBreakdown()
    {
        // Calculate actual storage usage
        $avatarsSize = 0;
        $attachmentsSize = 0;

        try {
            // Calculate avatars storage (user profile pictures)
            $avatars = User::whereNotNull('avatar_path')->pluck('avatar_path');
            foreach ($avatars as $avatar) {
                if (Storage::exists($avatar)) {
                    $avatarsSize += Storage::size($avatar);
                }
            }

            // Calculate attachments storage (objective_attachments table)
            if (Schema::hasTable('objective_attachments')) {
                $attachments = DB::table('objective_attachments')
                    ->whereNotNull('file_path')
                    ->pluck('file_path');

                foreach ($attachments as $attachment) {
                    if (Storage::exists($attachment)) {
                        $attachmentsSize += Storage::size($attachment);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Storage calculation failed: ' . $e->getMessage());
        }

        return [
            'avatars' => [
                'size' => $avatarsSize,
                'formatted' => $this->formatBytes($avatarsSize),
                'description' => 'User profile pictures and avatars'
            ],
            'attachments' => [
                'size' => $attachmentsSize,
                'formatted' => $this->formatBytes($attachmentsSize),
                'description' => 'Objective attachments and documents'
            ]
        ];
    }

    private function formatBytes($bytes, $precision = 2)
    {
        if ($bytes === 0) {
            return '0 Bytes';
        }

        $k = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes) / log($k));

        return round($bytes / pow($k, $i), $precision) . ' ' . $sizes[$i];
    }

    private function getSuperAdminAttentionItems()
    {
        $items = [];

        // Check for inactive tenants
        $inactiveTenants = Tenant::where('is_active', false)->count();
        if ($inactiveTenants > 0) {
            $items[] = [
                'title' => 'Inactive Tenants',
                'description' => "{$inactiveTenants} tenant(s) are currently inactive",
                'icon' => 'mdi-office-building-off',
                'priority' => 'high',
                'route' => '/admin/tenants?status=inactive'
            ];
        }

        // Check for locked users
        $lockedUsers = User::whereNotNull('locked_until')
            ->where('locked_until', '>', now())
            ->count();
        if ($lockedUsers > 0) {
            $items[] = [
                'title' => 'Locked User Accounts',
                'description' => "{$lockedUsers} user account(s) are currently locked",
                'icon' => 'mdi-lock',
                'priority' => 'medium',
                'route' => '/admin/users?status=locked'
            ];
        }

        return $items;
    }

    private function getSystemActivityChart()
    {
        $data = [];
        $labels = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $labels[] = $date->format('M d');

            $count = Activity::whereDate('created_at', $date)->count();
            $data[] = $count;
        }

        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    private function tenantUserStats(User $user)
    {
        // Get user's current position
        $position = $user->currentPosition();
        $positionId = $position ? $position->id : null;

        // Get current performance period
        $currentPeriod = $this->getCurrentPerformancePeriod($user->tenant_id);

        // Get user's scorecard
        $scorecard = null;
        $goals = 0;
        $objectives = 0;
        $bscScore = 0;
        $progress = 0;

        if ($positionId && $currentPeriod) {
            $scorecard = EmployeeScorecard::where('position_id', $positionId)
                ->where('performance_period_id', $currentPeriod->id)
                ->first();

            if ($scorecard) {
                $stats = $this->calculateScorecardStats($scorecard);
                $goals = $stats['goals'];
                $objectives = $stats['objectives'];
                $bscScore = $stats['bsc_score'];
                $progress = $stats['progress'];
            }
        }

        // Get current quarter info
        $currentQuarter = $this->getCurrentQuarterInfo($currentPeriod);

        // Get progress trends
        $progressTrends = $this->getUserProgressTrends($user, $positionId);

        // Get quarter averages
        $quarterAverages = $this->getUserQuarterAverages($user, $positionId);

        // Get business unit
        $businessUnit = $position ? $position->businessUnit : null;

        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'Tenant User',
                'user_info' => [
                    'name' => $user->full_name,
                    'email' => $user->email,
                    'position' => $position ? $position->name : 'No position assigned',
                    'business_unit' => $businessUnit ? $businessUnit->name : 'Not assigned',
                    'level' => $user->level,
                    'employee_number' => $user->employee_number
                ],
                'cards' => [
                    [
                        'type' => 'profile',
                        'name' => $user->full_name,
                        'email' => $user->email,
                        'position' => $position ? $position->name : 'No position assigned',
                        'business_unit' => $businessUnit ? $businessUnit->name : 'Not assigned',
                        'level' => $user->level
                    ],
                    [
                        'type' => 'period',
                        'year' => $currentPeriod ? $currentPeriod->financialYear->year : 'Not set',
                        'quarter' => $this->getCurrentQuarter(),
                        'period' => $currentPeriod ? $currentPeriod->name : 'No active period',
                        'status' => $currentPeriod ? $currentPeriod->status : 'inactive'
                    ],
                    [
                        'type' => 'performance',
                        'metrics' => [
                            ['label' => 'Goals', 'value' => $goals, 'icon' => 'mdi-target', 'color' => 'primary'],
                            ['label' => 'Objectives', 'value' => $objectives, 'icon' => 'mdi-checkbox-marked-circle', 'color' => 'info'],
                            ['label' => 'BSC Score', 'value' => $bscScore . '%', 'icon' => 'mdi-chart-line', 'color' => 'success']
                        ],
                        'progress' => $progress
                    ]
                ],
                'current_quarter' => $currentQuarter,
                'progress_trends' => $progressTrends,
                'quarter_averages' => $quarterAverages,
                'scorecard' => $scorecard ? [
                    'id' => $scorecard->id,
                    'status' => $scorecard->status
                ] : null
            ]
        ]);
    }

    private function getCurrentPerformancePeriod($tenantId)
    {
        return PerformancePeriod::where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->with('financialYear')
            ->first();
    }

    private function getCurrentQuarter()
    {
        $month = date('n');
        if ($month >= 1 && $month <= 3) return 'Q1';
        if ($month >= 4 && $month <= 6) return 'Q2';
        if ($month >= 7 && $month <= 9) return 'Q3';
        return 'Q4';
    }

    private function getCurrentQuarterInfo($period)
    {
        if (!$period) {
            return [
                'quarter' => $this->getCurrentQuarter(),
                'name' => 'No active period',
                'start_date' => null,
                'end_date' => null,
                'days_remaining' => 0
            ];
        }

        $daysRemaining = now()->diffInDays($period->end_date, false);

        return [
            'quarter' => $this->getCurrentQuarter(),
            'name' => $period->name,
            'start_date' => $period->start_date->toIso8601String(),
            'end_date' => $period->end_date->toIso8601String(),
            'days_remaining' => max(0, $daysRemaining)
        ];
    }

    private function getUserProgressTrends($user, $positionId)
    {
        if (!$positionId) {
            return ['labels' => [], 'data' => []];
        }

        // Get last 6 months of scorecard data
        $data = [];
        $labels = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $labels[] = $month->format('M');

            // Get average score for that month
            $avgScore = EmployeeScorecard::where('position_id', $positionId)
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->avg('overall_score');

            $data[] = $avgScore ? round($avgScore, 1) : 0;
        }

        return [
            'labels' => $labels,
            'data' => $data
        ];
    }

    private function getUserQuarterAverages($user, $positionId)
    {
        if (!$positionId) {
            return ['q1' => 0, 'q2' => 0, 'q3' => 0, 'q4' => 0];
        }

        $currentYear = now()->year;

        return [
            'q1' => $this->getQuarterAverage($positionId, $currentYear, 1, 3),
            'q2' => $this->getQuarterAverage($positionId, $currentYear, 4, 6),
            'q3' => $this->getQuarterAverage($positionId, $currentYear, 7, 9),
            'q4' => $this->getQuarterAverage($positionId, $currentYear, 10, 12)
        ];
    }

    private function getQuarterAverage($positionId, $year, $startMonth, $endMonth)
    {
        $avg = EmployeeScorecard::where('position_id', $positionId)
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', '>=', $startMonth)
            ->whereMonth('created_at', '<=', $endMonth)
            ->avg('overall_score');

        return $avg ? round($avg, 1) : 0;
    }

    private function calculateScorecardStats($scorecard)
    {
        $goals = 0;
        $objectives = 0;
        $progress = 0;

        // Count goals and objectives from perspectives
        $perspectives = $scorecard->perspectives ?? [];

        foreach ($perspectives as $perspective) {
            if (isset($perspective['goals'])) {
                $goals += count($perspective['goals']);

                foreach ($perspective['goals'] as $goal) {
                    if (isset($goal['objectives'])) {
                        $objectives += count($goal['objectives']);
                    }
                }
            }
        }

        // Calculate progress (objectives completed)
        if ($objectives > 0) {
            $completed = 0;
            foreach ($perspectives as $perspective) {
                if (isset($perspective['goals'])) {
                    foreach ($perspective['goals'] as $goal) {
                        if (isset($goal['objectives'])) {
                            foreach ($goal['objectives'] as $objective) {
                                if (isset($objective['achievement_percentage']) && $objective['achievement_percentage'] >= 100) {
                                    $completed++;
                                }
                            }
                        }
                    }
                }
            }
            $progress = round(($completed / $objectives) * 100);
        }

        return [
            'goals' => $goals,
            'objectives' => $objectives,
            'bsc_score' => $scorecard->overall_score ?? 0,
            'progress' => $progress
        ];
    }

    private function groupAdminStats(User $user)
    {
        // TODO: Implement Group Admin stats
        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'Group Admin',
                'cards' => [],
                'recent_activity' => [],
                'attention_items' => [],
                'activity_chart' => ['labels' => [], 'data' => []],
                'storage' => [
                    'total' => 0,
                    'used' => 0,
                    'available' => 0,
                    'percentage' => 0,
                    'breakdown' => []
                ]
            ]
        ]);
    }

    private function tenantAdminStats(User $user)
    {
        // TODO: Implement Tenant Admin stats
        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'Tenant Admin',
                'cards' => [],
                'recent_activity' => [],
                'attention_items' => [],
                'activity_chart' => ['labels' => [], 'data' => []],
                'storage' => [
                    'total' => 0,
                    'used' => 0,
                    'available' => 0,
                    'percentage' => 0,
                    'breakdown' => []
                ]
            ]
        ]);
    }

    private function financeAdminStats(User $user)
    {
        // TODO: Implement Finance Admin stats
        return response()->json([
            'success' => true,
            'data' => [
                'role' => 'Finance Admin',
                'cards' => [],
                'recent_activity' => [],
                'attention_items' => [],
                'activity_chart' => ['labels' => [], 'data' => []],
                'storage' => [
                    'total' => 0,
                    'used' => 0,
                    'available' => 0,
                    'percentage' => 0,
                    'breakdown' => []
                ]
            ]
        ]);
    }
}
