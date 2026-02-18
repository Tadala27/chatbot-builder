<?php

namespace App\Services\BSC;

use App\Models\EmployeeScorecard;
use Illuminate\Support\Facades\Log;

class ScorecardChangeTrackingService
{
    /**
     * Create snapshot of current scorecard state before manager makes changes
     */
    public function createSnapshot(EmployeeScorecard $scorecard): array
    {
        return [
            'perspectives' => $scorecard->perspectives->map(function ($perspective) {
                return [
                    'id' => $perspective->id,
                    'name' => $perspective->perspective_name,
                    'weight' => $perspective->weight,
                    'goals' => $perspective->goals->map(function ($goal) {
                        return [
                            'id' => $goal->id,
                            'description' => $goal->description,
                            'weight' => $goal->weight,
                            'objectives' => $goal->objectives->map(function ($objective) {
                                return [
                                    'id' => $objective->id,
                                    'description' => $objective->description,
                                    'weight' => $objective->weight,
                                    'objective_type' => $objective->objective_type,
                                    'target_type' => $objective->target_type,
                                    'appraisal_behaviour' => $objective->appraisal_behaviour,
                                    'absolute_value' => $objective->absolute_value,
                                    'targets' => $objective->targets->map(function ($target) {
                                        return [
                                            'id' => $target->id,
                                            'threshold_name' => $target->threshold_name,
                                            'target_value' => $target->target_value,
                                        ];
                                    })->toArray(),
                                ];
                            })->toArray(),
                        ];
                    })->toArray(),
                ];
            })->toArray(),
            'snapshot_taken_at' => now()->toISOString(),
        ];
    }

    /**
     * Compare current scorecard state with snapshot to detect changes
     */
    public function detectChanges(EmployeeScorecard $scorecard, ?array $snapshot): array
    {
        if (!$snapshot) {
            return [
                'has_changes' => false,
                'changes' => [],
            ];
        }

        $changes = [];
        $currentData = $this->createSnapshot($scorecard);

        // Compare perspectives
        $changes['perspectives'] = $this->comparePerspectives(
            $snapshot['perspectives'] ?? [],
            $currentData['perspectives']
        );

        $hasChanges = !empty($changes['perspectives']['added']) ||
            !empty($changes['perspectives']['removed']) ||
            !empty($changes['perspectives']['modified']);

        return [
            'has_changes' => $hasChanges,
            'changes' => $changes,
            'snapshot_date' => $snapshot['snapshot_taken_at'] ?? null,
            'current_date' => $currentData['snapshot_taken_at'],
        ];
    }

    /**
     * Compare perspectives between snapshots
     */
    private function comparePerspectives(array $original, array $current): array
    {
        $changes = [
            'added' => [],
            'removed' => [],
            'modified' => [],
        ];

        $originalIds = collect($original)->pluck('id')->toArray();
        $currentIds = collect($current)->pluck('id')->toArray();

        // Find added perspectives
        $addedIds = array_diff($currentIds, $originalIds);
        foreach ($addedIds as $id) {
            $perspective = collect($current)->firstWhere('id', $id);
            $changes['added'][] = [
                'type' => 'perspective',
                'id' => $id,
                'name' => $perspective['name'],
                'data' => $perspective,
            ];
        }

        // Find removed perspectives
        $removedIds = array_diff($originalIds, $currentIds);
        foreach ($removedIds as $id) {
            $perspective = collect($original)->firstWhere('id', $id);
            $changes['removed'][] = [
                'type' => 'perspective',
                'id' => $id,
                'name' => $perspective['name'],
                'data' => $perspective,
            ];
        }

        // Find modified perspectives
        $commonIds = array_intersect($originalIds, $currentIds);
        foreach ($commonIds as $id) {
            $originalPerspective = collect($original)->firstWhere('id', $id);
            $currentPerspective = collect($current)->firstWhere('id', $id);

            $perspectiveChanges = $this->comparePerspectiveData($originalPerspective, $currentPerspective);

            if (!empty($perspectiveChanges)) {
                $changes['modified'][] = [
                    'type' => 'perspective',
                    'id' => $id,
                    'name' => $currentPerspective['name'],
                    'changes' => $perspectiveChanges,
                ];
            }
        }

        return $changes;
    }

    /**
     * Compare individual perspective data
     */
    private function comparePerspectiveData(array $original, array $current): array
    {
        $changes = [];

        // Check weight change
        if ($original['weight'] != $current['weight']) {
            $changes['weight'] = [
                'from' => $original['weight'],
                'to' => $current['weight'],
            ];
        }

        // Compare goals
        $goalChanges = $this->compareGoals($original['goals'] ?? [], $current['goals'] ?? []);
        if (!empty($goalChanges['added']) || !empty($goalChanges['removed']) || !empty($goalChanges['modified'])) {
            $changes['goals'] = $goalChanges;
        }

        return $changes;
    }

    /**
     * Compare goals between snapshots
     */
    private function compareGoals(array $original, array $current): array
    {
        $changes = [
            'added' => [],
            'removed' => [],
            'modified' => [],
        ];

        $originalIds = collect($original)->pluck('id')->toArray();
        $currentIds = collect($current)->pluck('id')->toArray();

        // Find added goals
        $addedIds = array_diff($currentIds, $originalIds);
        foreach ($addedIds as $id) {
            $goal = collect($current)->firstWhere('id', $id);
            $changes['added'][] = [
                'type' => 'goal',
                'id' => $id,
                'description' => $goal['description'],
                'weight' => $goal['weight'],
                'objectives_count' => count($goal['objectives'] ?? []),
            ];
        }

        // Find removed goals
        $removedIds = array_diff($originalIds, $currentIds);
        foreach ($removedIds as $id) {
            $goal = collect($original)->firstWhere('id', $id);
            $changes['removed'][] = [
                'type' => 'goal',
                'id' => $id,
                'description' => $goal['description'],
                'weight' => $goal['weight'],
            ];
        }

        // Find modified goals
        $commonIds = array_intersect($originalIds, $currentIds);
        foreach ($commonIds as $id) {
            $originalGoal = collect($original)->firstWhere('id', $id);
            $currentGoal = collect($current)->firstWhere('id', $id);

            $goalChanges = $this->compareGoalData($originalGoal, $currentGoal);

            if (!empty($goalChanges)) {
                $changes['modified'][] = [
                    'type' => 'goal',
                    'id' => $id,
                    'description' => $currentGoal['description'],
                    'changes' => $goalChanges,
                ];
            }
        }

        return $changes;
    }

    /**
     * Compare individual goal data
     */
    private function compareGoalData(array $original, array $current): array
    {
        $changes = [];

        if ($original['description'] !== $current['description']) {
            $changes['description'] = [
                'from' => $original['description'],
                'to' => $current['description'],
            ];
        }

        if ($original['weight'] != $current['weight']) {
            $changes['weight'] = [
                'from' => $original['weight'],
                'to' => $current['weight'],
            ];
        }

        // Compare objectives
        $objectiveChanges = $this->compareObjectives(
            $original['objectives'] ?? [],
            $current['objectives'] ?? []
        );

        if (!empty($objectiveChanges['added']) || !empty($objectiveChanges['removed']) || !empty($objectiveChanges['modified'])) {
            $changes['objectives'] = $objectiveChanges;
        }

        return $changes;
    }

    /**
     * Compare objectives between snapshots
     */
    private function compareObjectives(array $original, array $current): array
    {
        $changes = [
            'added' => [],
            'removed' => [],
            'modified' => [],
        ];

        $originalIds = collect($original)->pluck('id')->toArray();
        $currentIds = collect($current)->pluck('id')->toArray();

        // Find added objectives
        $addedIds = array_diff($currentIds, $originalIds);
        foreach ($addedIds as $id) {
            $objective = collect($current)->firstWhere('id', $id);
            $changes['added'][] = [
                'type' => 'objective',
                'id' => $id,
                'description' => $objective['description'],
                'weight' => $objective['weight'],
            ];
        }

        // Find removed objectives
        $removedIds = array_diff($originalIds, $currentIds);
        foreach ($removedIds as $id) {
            $objective = collect($original)->firstWhere('id', $id);
            $changes['removed'][] = [
                'type' => 'objective',
                'id' => $id,
                'description' => $objective['description'],
                'weight' => $objective['weight'],
            ];
        }

        // Find modified objectives
        $commonIds = array_intersect($originalIds, $currentIds);
        foreach ($commonIds as $id) {
            $originalObjective = collect($original)->firstWhere('id', $id);
            $currentObjective = collect($current)->firstWhere('id', $id);

            $objectiveChanges = $this->compareObjectiveData($originalObjective, $currentObjective);

            if (!empty($objectiveChanges)) {
                $changes['modified'][] = [
                    'type' => 'objective',
                    'id' => $id,
                    'description' => $currentObjective['description'],
                    'changes' => $objectiveChanges,
                ];
            }
        }

        return $changes;
    }

    /**
     * Compare individual objective data
     */
    private function compareObjectiveData(array $original, array $current): array
    {
        $changes = [];

        $fields = ['description', 'weight', 'objective_type', 'target_type', 'appraisal_behaviour', 'absolute_value'];

        foreach ($fields as $field) {
            if (($original[$field] ?? null) != ($current[$field] ?? null)) {
                $changes[$field] = [
                    'from' => $original[$field] ?? null,
                    'to' => $current[$field] ?? null,
                ];
            }
        }

        // Compare targets
        $targetChanges = $this->compareTargets(
            $original['targets'] ?? [],
            $current['targets'] ?? []
        );

        if (!empty($targetChanges)) {
            $changes['targets'] = $targetChanges;
        }

        return $changes;
    }

    /**
     * Compare targets
     */
    private function compareTargets(array $original, array $current): array
    {
        $changes = [
            'added' => [],
            'removed' => [],
            'modified' => [],
        ];

        $originalIds = collect($original)->pluck('id')->toArray();
        $currentIds = collect($current)->pluck('id')->toArray();

        // Find added targets
        $addedIds = array_diff($currentIds, $originalIds);
        foreach ($addedIds as $id) {
            $target = collect($current)->firstWhere('id', $id);
            $changes['added'][] = $target;
        }

        // Find removed targets
        $removedIds = array_diff($originalIds, $currentIds);
        foreach ($removedIds as $id) {
            $target = collect($original)->firstWhere('id', $id);
            $changes['removed'][] = $target;
        }

        // Find modified targets
        $commonIds = array_intersect($originalIds, $currentIds);
        foreach ($commonIds as $id) {
            $originalTarget = collect($original)->firstWhere('id', $id);
            $currentTarget = collect($current)->firstWhere('id', $id);

            if ($originalTarget['target_value'] != $currentTarget['target_value']) {
                $changes['modified'][] = [
                    'id' => $id,
                    'threshold_name' => $currentTarget['threshold_name'],
                    'from' => $originalTarget['target_value'],
                    'to' => $currentTarget['target_value'],
                ];
            }
        }

        return $changes;
    }

    /**
     * Log change for audit trail
     */
    public function logChange(EmployeeScorecard $scorecard, string $action, array $details, $userId): void
    {
        $changeLog = $scorecard->change_log ? json_decode($scorecard->change_log, true) : [];

        $changeLog[] = [
            'action' => $action,
            'user_id' => $userId,
            'details' => $details,
            'timestamp' => now()->toISOString(),
        ];

        $scorecard->change_log = json_encode($changeLog);
        $scorecard->save();
    }
}
