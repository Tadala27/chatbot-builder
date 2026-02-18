<?php

namespace App\Services\BSC;

use App\Models\User;
use App\Models\Position;
use App\Models\FinancialYear;
use App\Models\EmployeeScorecard;
use App\Models\PerformancePeriod;
use App\Models\TenantConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScorecardSetupService
{
    protected ScorecardPermissionService $permissionService;

    public function __construct(ScorecardPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }
    /**
     * Get or auto-create the current scorecard for the user
     * Always returns an EmployeeScorecard (or throws exception)
     */
    public function getSetupData(User $user): array
    {
        $position = $user->currentPosition();
        if (!$position) {
            throw new \Exception('No active position assigned. Cannot access or create scorecard.');
        }

        $tenant = $user->tenant;
        if (!$tenant) {
            throw new \Exception('User is not attached to any tenant.');
        }

        // Get tenant configuration (primary + active)
        $config = $this->getActiveTenantConfiguration($tenant->id);
        if (!$config || !$config->bsc_template_id || !$config->matrix_template_id) {
            throw new \Exception('Missing or invalid tenant configuration (BSC/Matrix template).');
        }

        // Determine current financial year
        $fy = $this->getCurrentFinancialYear($tenant->id);
        if (!$fy) {
            throw new \Exception('No current or active financial year found.');
        }

        // Find or create scorecard
        $scorecard = $this->findOrCreateScorecard($user, $position, $tenant, $fy, $config);

        // Load necessary relations
        $scorecard->load([
            'position.businessUnit',
            'position.currentHolder.user',
            'financialYear',
            'performancePeriod',
            'bscTemplate',
            'matrixTemplate',
            'perspectives.goals.objectives.targets.thresholdConfig',
            'perspectives.goals.objectives.initiatives',
            'perspectives.goals.objectives.currentProgress',
        ]);

        $hasGoals = $scorecard->perspectives->sum(
            fn($p) => $p->goals->count()
        ) > 0;

        $permissions = $this->permissionService->getScorecardPermissions($user, $scorecard);

        return [
            'success'     => true,
            'has_goals'   => $hasGoals,
            'data'        => $scorecard,
            'permissions' => $permissions,
        ];
    }

    private function getActiveTenantConfiguration(int $tenantId): ?TenantConfiguration
    {
        return TenantConfiguration::where('tenant_id', $tenantId)
            ->where('is_primary', true)
            ->where('effective_from', '<=', now())
            ->where(function ($q) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->with(['bscTemplate.perspectives', 'matrixTemplate.thresholdConfigs'])
            ->first();
    }

    private function getCurrentFinancialYear(int $tenantId): ?FinancialYear
    {
        return FinancialYear::where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('is_current', true)
                    ->orWhereRaw('? BETWEEN start_date AND end_date', [now()->toDateString()]);
            })
            ->orderByDesc('start_date')
            ->first();
    }

    private function findOrCreateScorecard(
        User $user,
        Position $position,
        $tenant,
        FinancialYear $fy,
        TenantConfiguration $config
    ): EmployeeScorecard {
        $scorecard = EmployeeScorecard::where([
            'position_id'       => $position->id,
            'financial_year_id' => $fy->id,
        ])->first();

        if ($scorecard) {
            return $scorecard;
        }

        // Auto-creation only allowed if FY permits it
        if (!$fy->canCreateScorecards()) {
            throw new \Exception("Scorecard creation is not allowed for financial year '{$fy->name}'.");
        }

        $period = $this->getActivePerformancePeriod($fy->id);
        if (!$period) {
            throw new \Exception("No active/default performance period found for financial year '{$fy->name}'.");
        }

        DB::beginTransaction();
        try {
            $scorecard = EmployeeScorecard::create([
                'position_id'           => $position->id,
                'position_holder_id'    => $user->currentPositionHolder?->id,
                'tenant_id'             => $tenant->id,
                'financial_year_id'     => $fy->id,
                'performance_period_id' => $period->id,
                'bsc_template_id'       => $config->bsc_template_id,
                'matrix_template_id'    => $config->matrix_template_id,
                'status'                => 'draft',
                'overall_score'         => 0,
                'is_master_scorecard'   => $position->isTopManager(),
                'parent_scorecard_id'   => null,
            ]);

            $template = $scorecard->bscTemplate;
            if ($template) {
                foreach ($template->perspectives()->orderBy('sort_order')->get() as $p) {
                    $scorecard->perspectives()->create([
                        'perspective_id'   => $p->id,
                        'perspective_name' => $p->name,
                        'weight'           => $p->default_weight ?? 25,
                        'score'            => 0,
                        'sort_order'       => $p->sort_order,
                    ]);
                }
            }

            DB::commit();

            Log::info("Auto-created scorecard for user", [
                'user_id'      => $user->id,
                'scorecard_id' => $scorecard->id,
                'fy_id'        => $fy->id,
            ]);

            return $scorecard;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to auto-create scorecard", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            throw $e; // let controller handle
        }
    }

    private function getActivePerformancePeriod(int $fyId): ?PerformancePeriod
    {
        return PerformancePeriod::where('financial_year_id', $fyId)
            ->where('status', 'active')
            ->orderByDesc('start_date')
            ->first()
            ?? PerformancePeriod::where('financial_year_id', $fyId)
            ->orderByDesc('start_date')
            ->first();
    }
}
