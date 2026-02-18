<?php
// app/Services/BSC/ScorecardBulkUploadService.php

namespace App\Services\BSC;

use App\Models\User;
use App\Models\EmployeeScorecard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScorecardBulkUploadService
{
    /**
     * Process and save bulk scorecard upload
     */
    public function processBulkUpload(
        array $perspectivesData,
        array $metadata,
        User $user
    ): EmployeeScorecard {
        DB::beginTransaction();

        try {
            // Create or get scorecard
            $scorecard = $this->createOrGetScorecard($metadata, $user);

            // Process each perspective
            foreach ($perspectivesData as $perspectiveData) {
                $this->processPerspective($scorecard, $perspectiveData);
            }

            DB::commit();

            return $scorecard->fresh([
                'perspectives.goals.objectives.targets'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk upload failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Create or get existing scorecard
     */
    private function createOrGetScorecard(array $metadata, User $user): EmployeeScorecard
    {
        $position = $user->currentPosition();

        // Check if scorecard already exists
        $existing = EmployeeScorecard::where('position_id', $position->id)
            ->where('financial_year_id', $metadata['financial_year_id'])
            ->first();

        if ($existing) {
            return $existing;
        }

        // Create new scorecard with perspective weights
        return EmployeeScorecard::create([
            'position_id' => $position->id,
            'position_holder_id' => $position->currentHolder?->id,
            'tenant_id' => $user->tenant_id,
            'financial_year_id' => $metadata['financial_year_id'],
            'performance_period_id' => $metadata['performance_period_id'],
            'bsc_template_id' => $metadata['bsc_template_id'],
            'matrix_template_id' => $metadata['matrix_template_id'],
            'status' => 'draft',
            'overall_score' => 0,
            'is_master_scorecard' => $position->isTopManager(),
        ]);
    }

    /**
     * Process a single perspective with its goals and objectives
     */
    private function processPerspective(EmployeeScorecard $scorecard, array $perspectiveData): void
    {
        // Find matching perspective
        $perspective = $scorecard->perspectives()
            ->whereHas('perspective', function ($q) use ($perspectiveData) {
                $q->where('name', 'LIKE', '%' . substr($perspectiveData['perspective_name'], 0, 8) . '%');
            })
            ->first();

        if (!$perspective) {
            Log::warning('Perspective not found', ['name' => $perspectiveData['perspective_name']]);
            return;
        }

        // Update perspective weight
        $perspective->update(['weight' => $perspectiveData['perspective_weight']]);

        // Process goals
        foreach ($perspectiveData['goals'] as $goalData) {
            $this->processGoal($perspective, $goalData);
        }
    }

    /**
     * Process a single goal with its objectives
     */
    private function processGoal($perspective, array $goalData): void
    {
        $goal = $perspective->goals()->create([
            'description' => $goalData['description'],
            'weight' => $goalData['weight'],
            'sort_order' => $perspective->goals()->max('sort_order') + 1,
        ]);

        // Process objectives
        foreach ($goalData['objectives'] as $objectiveData) {
            $this->processObjective($goal, $objectiveData);
        }
    }

    /**
     * Process a single objective with its targets
     */
    private function processObjective($goal, array $objectiveData): void
    {
        $objective = $goal->objectives()->create([
            'description' => $objectiveData['description'],
            'objective_type' => $objectiveData['objective_type'],
            'absolute_value' => $objectiveData['absolute_value'],
            'target_type' => $objectiveData['target_type'],
            'appraisal_behaviour' => $objectiveData['appraisal_behaviour'],
            'weight' => $objectiveData['weight'],
            'requires_proof' => $objectiveData['requires_proof'],
            'proof_requirements' => $objectiveData['proof_requirements'],
            'sort_order' => $goal->objectives()->max('sort_order') + 1,
        ]);

        // Process targets
        foreach ($objectiveData['targets'] as $targetData) {
            $objective->targets()->create([
                'threshold_config_id' => $targetData['threshold_config_id'],
                'threshold_name' => $targetData['threshold_name'],
                'target_value' => $targetData['target_value'],
            ]);
        }
    }
}
