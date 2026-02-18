<?php

namespace App\Services\BSC;

use App\Models\ScorecardGoal;
use App\Models\EmployeeScorecard;
use App\Models\ScorecardObjective;
use Illuminate\Support\Facades\DB;
use App\Models\TenantConfiguration;
use Illuminate\Support\Facades\Log;
use App\Models\PerformanceMatrixTemplate;
use App\Services\BSC\ScorecardPermissionService;

class ScorecardGoalObjectiveService
{
    protected ScorecardPermissionService $permissionService;

    public function __construct(ScorecardPermissionService $permissionService)
    {
        $this->permissionService = $permissionService;
    }

    /**
     * UNIFIED save method - works for both self and subordinates
     * Permission check is automatic based on the scorecard and user
     */
    public function save(array $payload, $user, ?EmployeeScorecard $scorecard = null): array
    {
        // If scorecard not provided, get it from payload
        if (!$scorecard && isset($payload['scorecardId'])) {
            $scorecard = EmployeeScorecard::find($payload['scorecardId']);
        }

        // Verify permission
        if ($scorecard && !$this->permissionService->canEditScorecard($user, $scorecard)) {
            throw new \Exception('You do not have permission to edit this scorecard.');
        }

        $mode = $payload['mode'];
        $scorecardId = $payload['scorecardId'] ?? null;
        $perspectiveId = $payload['perspectiveId'] ?? null;

        $results = [
            'goals_created' => 0,
            'goals_updated' => 0,
            'objectives_created' => 0,
            'objectives_updated' => 0,
            'objectives_deleted' => 0,
        ];

        DB::beginTransaction();

        try {
            // Get threshold configs ONCE for this tenant
            $thresholdConfigs = $this->getThresholdConfigs($user->tenant_id);

            foreach ($payload['goals'] as $goalData) {
                if (isset($goalData['id']) && $goalData['id']) {
                    // UPDATE EXISTING GOAL
                    $this->updateGoal($goalData, $user, $thresholdConfigs, $results);
                } else {
                    // CREATE NEW GOAL
                    $this->createGoal($goalData, $scorecardId, $perspectiveId, $user, $thresholdConfigs, $results);
                }
            }

            // Recalculate scores
            if ($scorecardId) {
                $this->recalculateScores($scorecardId);
            }

            DB::commit();

            return $results;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Save goals/objectives failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    public function saveGoalsObjectives(array $payload, $user): array
    {
        $mode = $payload['mode'];
        $scorecardId = $payload['scorecardId'] ?? null;
        $perspectiveId = $payload['perspectiveId'] ?? null;

        $results = [
            'goals_created' => 0,
            'goals_updated' => 0,
            'objectives_created' => 0,
            'objectives_updated' => 0,
            'objectives_deleted' => 0,
        ];

        DB::beginTransaction();

        try {
            // Get threshold configs ONCE for this tenant
            $thresholdConfigs = $this->getThresholdConfigs($user->tenant_id);

            foreach ($payload['goals'] as $goalData) {
                if (isset($goalData['id']) && $goalData['id']) {
                    // UPDATE EXISTING GOAL
                    $this->updateGoal($goalData, $user, $thresholdConfigs, $results);
                } else {
                    // CREATE NEW GOAL
                    $this->createGoal($goalData, $scorecardId, $perspectiveId, $user, $thresholdConfigs, $results);
                }
            }

            // Recalculate scores
            if ($scorecardId) {
                $this->recalculateScores($scorecardId);
            }

            DB::commit();

            return $results;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Save goals/objectives failed: ' . $e->getMessage(), [
                'user_id' => $user->id,
                'payload' => $payload,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing goal
     */
    private function updateGoal(
        array $goalData,
        $user,
        $thresholdConfigs,
        array &$results
    ): void {
        $goal = ScorecardGoal::findOrFail($goalData['id']);

        // Verify permissions
        $scorecard = $goal->perspective->scorecard;
        if ($scorecard->tenant_id !== $user->tenant_id) {
            throw new \Exception('Unauthorized access to goal');
        }

        $goal->update([
            'description' => $goalData['description'],
            'weight' => $goalData['weight'],
        ]);
        $results['goals_updated']++;


        $this->syncObjectives($goal, $goalData['objectives'], $thresholdConfigs, $results);

        $goal->recalculateScore();
    }

    /**
     * Create a new goal
     */
    private function createGoal(
        array $goalData,
        ?int $scorecardId,
        $perspectiveId,
        $user,
        $thresholdConfigs,
        array &$results
    ): void {
        if (!$scorecardId) {
            throw new \Exception('Scorecard ID required for new goals');
        }

        if (!$perspectiveId) {
            throw new \Exception('Perspective ID required for new goals');
        }

        $scorecard = EmployeeScorecard::findOrFail($scorecardId);

        // Verify permissions
        if ($scorecard->tenant_id !== $user->tenant_id) {
            throw new \Exception('Unauthorized access to scorecard');
        }

        $perspective = $scorecard->perspectives()->findOrFail($perspectiveId);

        $maxSortOrder = $perspective->goals()->max('sort_order') ?? 0;

        $goal = $perspective->goals()->create([
            'description' => $goalData['description'],
            'weight' => $goalData['weight'],
            'sort_order' => $maxSortOrder + 1,
        ]);
        $results['goals_created']++;

        // Create objectives
        if (!empty($goalData['objectives'])) {
            $this->createObjectives($goal, $goalData['objectives'], $thresholdConfigs, $results);
        }
    }

    /**
     * Sync objectives for a goal - handles create/update/delete
     */
    private function syncObjectives(
        ScorecardGoal $goal,
        array $objectivesData,
        $thresholdConfigs,
        array &$results
    ): void {
        $existingObjectiveIds = $goal->objectives->pluck('id')->toArray();
        $processedObjectiveIds = [];

        foreach ($objectivesData as $objData) {
            if (isset($objData['id']) && $objData['id']) {
                // UPDATE EXISTING OBJECTIVE
                $objective = $goal->objectives()->findOrFail($objData['id']);

                $objective->update([
                    'description' => $objData['description'],
                    'objective_type' => $objData['objective_type'],
                    'absolute_value' => $objData['absolute_value'] ?? null,
                    'target_type' => $objData['target_type'],
                    'appraisal_behaviour' => $objData['appraisal_behaviour'],
                    'weight' => $objData['weight'],
                    'requires_proof' => $objData['requires_proof'] ?? false,
                    'proof_requirements' => $objData['proof_requirements'] ?? null,
                ]);

                // ✅ CRITICAL: Sync targets properly
                if (isset($objData['targets'])) {
                    $this->syncTargets($objective, $objData['targets'], $thresholdConfigs);
                }

                $processedObjectiveIds[] = $objData['id'];
                $results['objectives_updated']++;
            } else {
                // CREATE NEW OBJECTIVE
                $maxSortOrder = $goal->objectives()->max('sort_order') ?? 0;

                $objective = $goal->objectives()->create([
                    'description' => $objData['description'],
                    'objective_type' => $objData['objective_type'],
                    'absolute_value' => $objData['absolute_value'] ?? null,
                    'target_type' => $objData['target_type'],
                    'appraisal_behaviour' => $objData['appraisal_behaviour'],
                    'weight' => $objData['weight'],
                    'requires_proof' => $objData['requires_proof'] ?? false,
                    'proof_requirements' => $objData['proof_requirements'] ?? null,
                    'sort_order' => $maxSortOrder + 1,
                ]);

                // ✅ CRITICAL: Create targets properly
                if (isset($objData['targets'])) {
                    $this->createTargets($objective, $objData['targets'], $thresholdConfigs);
                }

                $results['objectives_created']++;
            }
        }

        // Delete objectives that were removed
        $objectivesToDelete = array_diff($existingObjectiveIds, $processedObjectiveIds);
        if (!empty($objectivesToDelete)) {
            ScorecardObjective::whereIn('id', $objectivesToDelete)->delete();
            $results['objectives_deleted'] += count($objectivesToDelete);
        }
    }

    /**
     * Create objectives for a new goal
     */
    private function createObjectives(
        ScorecardGoal $goal,
        array $objectivesData,
        $thresholdConfigs,
        array &$results
    ): void {
        foreach ($objectivesData as $index => $objData) {
            $objective = $goal->objectives()->create([
                'description' => $objData['description'],
                'objective_type' => $objData['objective_type'],
                'absolute_value' => $objData['absolute_value'] ?? null,
                'target_type' => $objData['target_type'],
                'appraisal_behaviour' => $objData['appraisal_behaviour'],
                'weight' => $objData['weight'],
                'requires_proof' => $objData['requires_proof'] ?? false,
                'proof_requirements' => $objData['proof_requirements'] ?? null,
                'sort_order' => $index + 1,
            ]);

            if (isset($objData['targets'])) {
                $this->createTargets($objective, $objData['targets'], $thresholdConfigs);
            }

            $results['objectives_created']++;
        }
    }

    /**
     * ✅ IMPROVED: Sync targets for an objective
     * This properly handles multi-threshold configurations
     */
    private function syncTargets(
        ScorecardObjective $objective,
        array $targetsData,
        $thresholdConfigs
    ): void {
        // Delete all existing targets
        $objective->targets()->delete();

        // Create fresh targets from the payload
        foreach ($targetsData as $targetData) {
            $thresholdConfigId = null;
            $thresholdName = $targetData['threshold_name'] ?? 'Target';

            // Match threshold config by name
            if ($thresholdConfigs && $thresholdConfigs->count() > 0) {
                $matchingConfig = $thresholdConfigs->first(function ($config) use ($thresholdName) {
                    return strcasecmp($config->threshold_name, $thresholdName) === 0;
                });

                $thresholdConfigId = $matchingConfig ? $matchingConfig->id : null;
            }

            // ✅ CRITICAL: Ensure target_value is properly cast
            $targetValue = $targetData['target_value'] ?? 0;

            // Handle boolean targets
            if ($objective->target_type === 'boolean') {
                $targetValue = (int) $targetValue; // 0 or 1
            } else {
                $targetValue = (float) $targetValue;
            }

            $objective->targets()->create([
                'threshold_config_id' => $thresholdConfigId,
                'threshold_name' => $thresholdName,
                'target_value' => $targetValue,
            ]);
        }
    }

    /**
     * ✅ IMPROVED: Create targets for a new objective
     */
    private function createTargets(
        ScorecardObjective $objective,
        array $targetsData,
        $thresholdConfigs
    ): void {
        foreach ($targetsData as $targetData) {
            $thresholdConfigId = null;
            $thresholdName = $targetData['threshold_name'] ?? 'Target';

            // Match threshold config by name
            if ($thresholdConfigs && $thresholdConfigs->count() > 0) {
                $matchingConfig = $thresholdConfigs->first(function ($config) use ($thresholdName) {
                    return strcasecmp($config->threshold_name, $thresholdName) === 0;
                });

                $thresholdConfigId = $matchingConfig ? $matchingConfig->id : null;
            }

            // ✅ CRITICAL: Ensure target_value is properly cast
            $targetValue = $targetData['target_value'] ?? 0;

            // Handle boolean targets
            if ($objective->target_type === 'boolean') {
                $targetValue = (int) $targetValue; // 0 or 1
            } else {
                $targetValue = (float) $targetValue;
            }

            $objective->targets()->create([
                'threshold_config_id' => $thresholdConfigId,
                'threshold_name' => $thresholdName,
                'target_value' => $targetValue,
            ]);
        }
    }

    /**
     * ✅ IMPROVED: Get threshold configurations for tenant
     * Returns properly ordered configs
     */
    private function getThresholdConfigs(int $tenantId)
    {
        $tenantConfig = TenantConfiguration::where('tenant_id', $tenantId)->first();

        if (!$tenantConfig || !$tenantConfig->matrix_template_id) {
            return collect();
        }

        $matrixTemplate = PerformanceMatrixTemplate::with(['thresholdConfigs' => function ($query) {
            $query->orderBy('sort_order', 'asc');
        }])->find($tenantConfig->matrix_template_id);

        return $matrixTemplate ? ($matrixTemplate->thresholdConfigs ?? collect()) : collect();
    }

    /**
     * Recalculate scores for a scorecard
     */
    private function recalculateScores(int $scorecardId): void
    {
        $scorecard = EmployeeScorecard::find($scorecardId);

        if (!$scorecard) {
            return;
        }

        foreach ($scorecard->perspectives as $perspective) {
            $perspective->recalculateScore();
        }

        $scorecard->recalculateScores();
    }

    /**
     * Delete goal with permission check
     */
    public function deleteGoal(int $goalId, $user): bool
    {
        $goal = ScorecardGoal::findOrFail($goalId);
        $scorecard = $goal->perspective->scorecard;

        if (!$this->permissionService->canEditScorecard($user, $scorecard)) {
            throw new \Exception('You do not have permission to delete this goal.');
        }

        DB::beginTransaction();
        try {
            $goal->delete();

            // Recalculate scores
            $goal->perspective->recalculateScore();
            $scorecard->recalculateScores();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete goal: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Delete objective with permission check
     */
    public function deleteObjective(int $objectiveId, $user): bool
    {
        $objective = ScorecardObjective::findOrFail($objectiveId);
        $scorecard = $objective->goal->perspective->scorecard;

        if (!$this->permissionService->canEditScorecard($user, $scorecard)) {
            throw new \Exception('You do not have permission to delete this objective.');
        }

        DB::beginTransaction();
        try {
            $goal = $objective->goal;
            $perspective = $goal->perspective;

            $objective->targets()->delete();
            $objective->delete();

            $goal->recalculateScore();
            $perspective->recalculateScore();
            $scorecard->recalculateScores();

            DB::commit();
            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete objective: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Build success message from results
     */
    public function buildSuccessMessage(array $results): string
    {
        $messages = [];

        if ($results['goals_created'] > 0) {
            $messages[] = "{$results['goals_created']} goal(s) created";
        }
        if ($results['goals_updated'] > 0) {
            $messages[] = "{$results['goals_updated']} goal(s) updated";
        }
        if ($results['objectives_created'] > 0) {
            $messages[] = "{$results['objectives_created']} objective(s) created";
        }
        if ($results['objectives_updated'] > 0) {
            $messages[] = "{$results['objectives_updated']} objective(s) updated";
        }
        if ($results['objectives_deleted'] > 0) {
            $messages[] = "{$results['objectives_deleted']} objective(s) deleted";
        }

        return !empty($messages) ? implode(', ', $messages) : 'No changes made';
    }
}
