import { computed, ref } from "vue";
import type { Ref } from "vue";
import type {
  Scorecard,
  ScorecardPerspective,
  ScorecardGoal,
} from "./useScorecardState";

export interface ValidationState {
  valid: boolean;
  message: string;
  color: string;
  icon: string;
}

export interface WeightValidation extends ValidationState {
  current: number;
  expected: number;
}

/**
 * Scorecard validation composable
 * Handles all weight validation logic
 */
export function useScorecardValidation(
  perspectives: Ref<ScorecardPerspective[]>,
  scorecard: Ref<Scorecard | null>,
) {
  // ========================================================================
  // STATE
  // ========================================================================
  const originalPerspectiveWeights = ref<Map<number | string, number>>(
    new Map(),
  );

  // ========================================================================
  // PERSPECTIVE VALIDATION
  // ========================================================================

  /**
   * Check if perspective weights sum to 100%
   */
  const perspectiveWeightsValid = computed(() => {
    const total = perspectives.value.reduce(
      (sum, p) => sum + Number(p.weight || 0),
      0,
    );
    return Math.abs(total - 100) < 0.01;
  });

  /**
   * Get total weight of all perspectives
   */
  const totalPerspectiveWeight = computed(() => {
    return perspectives.value.reduce(
      (sum, p) => sum + Number(p.weight || 0),
      0,
    );
  });

  /**
   * Get perspective weight validation state
   */
  const getPerspectiveValidationState = (
    perspective: ScorecardPerspective,
  ): ValidationState & {
    goalTotal: number;
  } => {
    const goalTotal =
      perspective.goals?.reduce((sum, g) => sum + Number(g.weight || 0), 0) ||
      0;

    const perspectiveWeight = Number(perspective.weight || 0);
    const hasGoals = (perspective.goals?.length || 0) > 0;

    if (!hasGoals) {
      return {
        valid: true,
        message: "No goals yet",
        color: "info",
        icon: "$information",
        goalTotal: 0,
      };
    }

    if (Math.abs(goalTotal - perspectiveWeight) < 0.01) {
      return {
        valid: true,
        message: "Goals perfectly distributed",
        color: "success",
        icon: "$checkCircle",
        goalTotal,
      };
    }

    if (goalTotal < perspectiveWeight) {
      return {
        valid: false,
        message: `${(perspectiveWeight - goalTotal).toFixed(2)}% remaining`,
        color: "warning",
        icon: "$alertCircle",
        goalTotal,
      };
    }

    return {
      valid: false,
      message: `${(goalTotal - perspectiveWeight).toFixed(2)}% over limit`,
      color: "error",
      icon: "$alert",
      goalTotal,
    };
  };

  /**
   * Check if all perspectives have valid goal weights
   */
  const allPerspectivesValid = computed(() => {
    return perspectives.value.every((p) => {
      const state = getPerspectiveValidationState(p);
      return state.valid || (p.goals?.length || 0) === 0;
    });
  });

  // ========================================================================
  // GOAL VALIDATION
  // ========================================================================

  /**
   * Get goal weight validation state
   */
  const getGoalValidationState = (
    goal: ScorecardGoal,
  ): ValidationState & {
    objTotal: number;
  } => {
    const objTotal =
      goal.objectives?.reduce((sum, obj) => sum + Number(obj.weight || 0), 0) ||
      0;

    const goalWeight = Number(goal.weight || 0);
    const hasObjectives = (goal.objectives?.length || 0) > 0;

    if (!hasObjectives) {
      return {
        valid: true,
        message: "No objectives yet",
        color: "info",
        icon: "$information",
        objTotal: 0,
      };
    }

    if (Math.abs(objTotal - goalWeight) < 0.01) {
      return {
        valid: true,
        message: "Objectives perfectly distributed",
        color: "success",
        icon: "$checkCircle",
        objTotal,
      };
    }

    if (objTotal < goalWeight) {
      return {
        valid: false,
        message: `${(goalWeight - objTotal).toFixed(2)}% remaining`,
        color: "warning",
        icon: "$alertCircle",
        objTotal,
      };
    }

    return {
      valid: false,
      message: `${(objTotal - goalWeight).toFixed(2)}% over limit`,
      color: "error",
      icon: "$alert",
      objTotal,
    };
  };

  /**
   * Check if all goals have valid objective weights
   */
  const allGoalsValid = computed(() => {
    return perspectives.value.every(
      (p) =>
        p.goals?.every((g) => {
          const state = getGoalValidationState(g);
          return state.valid || (g.objectives?.length || 0) === 0;
        }) ?? true,
    );
  });

  // ========================================================================
  // OBJECTIVE VALIDATION
  // ========================================================================

  /**
   * Validate objective weights for a goal
   */
  const validateObjectiveWeights = (goal: ScorecardGoal): WeightValidation => {
    const objTotal =
      goal.objectives?.reduce((sum, obj) => sum + Number(obj.weight || 0), 0) ||
      0;

    const goalWeight = Number(goal.weight || 0);

    if (Math.abs(objTotal - goalWeight) < 0.01) {
      return {
        valid: true,
        message: `Perfect! Objectives total ${objTotal.toFixed(2)}%`,
        color: "success",
        icon: "$checkCircle",
        current: objTotal,
        expected: goalWeight,
      };
    }

    if (objTotal < goalWeight) {
      return {
        valid: false,
        message: `Need ${(goalWeight - objTotal).toFixed(2)}% more`,
        color: "warning",
        icon: "$alertCircle",
        current: objTotal,
        expected: goalWeight,
      };
    }

    return {
      valid: false,
      message: `Over limit by ${(objTotal - goalWeight).toFixed(2)}%`,
      color: "error",
      icon: "$alert",
      current: objTotal,
      expected: goalWeight,
    };
  };

  /**
   * Check if all objectives have valid weights
   */
  const allObjectivesValid = computed(() => {
    return perspectives.value.every(
      (p) =>
        p.goals?.every((g) => {
          const validation = validateObjectiveWeights(g);
          return validation.valid || (g.objectives?.length || 0) === 0;
        }) ?? true,
    );
  });

  // ========================================================================
  // COMBINED VALIDATION
  // ========================================================================

  /**
   * Check if all weights are valid
   */
  const allWeightsValid = computed(() => {
    return (
      perspectiveWeightsValid.value &&
      allPerspectivesValid.value &&
      allGoalsValid.value &&
      allObjectivesValid.value
    );
  });

  /**
   * Get all validation errors
   */
  const validationErrors = computed(() => {
    const errors: string[] = [];

    if (!perspectiveWeightsValid.value) {
      errors.push(
        `Perspective weights total ${totalPerspectiveWeight.value.toFixed(2)}%, must equal 100%`,
      );
    }

    perspectives.value.forEach((p) => {
      const pState = getPerspectiveValidationState(p);
      if (!pState.valid && (p.goals?.length || 0) > 0) {
        errors.push(`${p.perspective_name}: ${pState.message}`);
      }

      p.goals?.forEach((g) => {
        const gState = getGoalValidationState(g);
        if (!gState.valid && (g.objectives?.length || 0) > 0) {
          errors.push(`Goal "${g.description}": ${gState.message}`);
        }
      });
    });

    return errors;
  });

  // ========================================================================
  // AVAILABLE WEIGHT CALCULATIONS
  // ========================================================================

  /**
   * Get available weight for new goals in a perspective
   */
  const getAvailableGoalWeight = (perspectiveId: number | string): number => {
    const perspective = perspectives.value.find((p) => p.id === perspectiveId);
    if (!perspective) return 0;

    const usedWeight =
      perspective.goals?.reduce((sum, g) => sum + Number(g.weight || 0), 0) ||
      0;

    const available = Number(perspective.weight || 0) - usedWeight;
    return Math.max(0, Number(available.toFixed(2)));
  };

  /**
   * Get available weight for new objectives in a goal
   */
  const getAvailableObjectiveWeight = (goalId: number): number => {
    const goal = findGoalById(goalId);
    if (!goal) return 0;

    const usedWeight =
      goal.objectives?.reduce((sum, obj) => sum + Number(obj.weight || 0), 0) ||
      0;

    const available = Number(goal.weight || 0) - usedWeight;
    return Math.max(0, Number(available.toFixed(2)));
  };

  /**
   * Find goal by ID across all perspectives
   */
  const findGoalById = (goalId: number): ScorecardGoal | undefined => {
    for (const perspective of perspectives.value) {
      const goal = perspective.goals?.find((g) => g.id === goalId);
      if (goal) return goal;
    }
    return undefined;
  };

  // ========================================================================
  // WEIGHT DISTRIBUTION HELPERS
  // ========================================================================

  /**
   * Distribute goal weights equally within a perspective
   */
  const distributeGoalWeightsEqually = (perspectiveId: number | string) => {
    const perspective = perspectives.value.find((p) => p.id === perspectiveId);
    if (!perspective || !perspective.goals || perspective.goals.length === 0) {
      return false;
    }

    const goalCount = perspective.goals.length;
    const equalWeight = Number(perspective.weight) / goalCount;

    perspective.goals.forEach((goal, index) => {
      if (index === 0) {
        // First goal gets remainder to ensure exact total
        goal.weight = Number(
          (Number(perspective.weight) - equalWeight * (goalCount - 1)).toFixed(
            2,
          ),
        );
      } else {
        goal.weight = Number(equalWeight.toFixed(2));
      }
    });

    return true;
  };

  /**
   * Distribute objective weights equally within a goal
   */
  const distributeObjectiveWeightsEqually = (goalId: number) => {
    const goal = findGoalById(goalId);
    if (!goal || !goal.objectives || goal.objectives.length === 0) {
      return false;
    }

    const objCount = goal.objectives.length;
    const equalWeight = Number(goal.weight) / objCount;

    goal.objectives.forEach((obj, index) => {
      if (index === 0) {
        // First objective gets remainder
        obj.weight = Number(
          (Number(goal.weight) - equalWeight * (objCount - 1)).toFixed(2),
        );
      } else {
        obj.weight = Number(equalWeight.toFixed(2));
      }
    });

    return true;
  };

  // ========================================================================
  // WEIGHT TRACKING
  // ========================================================================

  /**
   * Initialize weight tracking
   */
  const initializeWeightTracking = () => {
    originalPerspectiveWeights.value.clear();
    perspectives.value.forEach((p) => {
      originalPerspectiveWeights.value.set(p.id, Number(p.weight || 0));
    });
  };

  /**
   * Get perspective weight changes
   */
  const perspectiveWeightChanges = computed(() => {
    const changes: Array<{
      perspectiveId: number | string;
      perspectiveName: string;
      oldWeight: number;
      newWeight: number;
      changed: boolean;
    }> = [];

    perspectives.value.forEach((p) => {
      const oldWeight = originalPerspectiveWeights.value.get(p.id) || 0;
      const newWeight = Number(p.weight || 0);
      changes.push({
        perspectiveId: p.id,
        perspectiveName: p.perspective_name,
        oldWeight,
        newWeight,
        changed: Math.abs(oldWeight - newWeight) > 0.01,
      });
    });

    return changes;
  });

  /**
   * Check if there are weight conflicts
   */
  const hasWeightConflicts = computed(() => {
    return perspectiveWeightChanges.value.some((change) => {
      if (!change.changed) return false;

      const perspective = perspectives.value.find(
        (p) => p.id === change.perspectiveId,
      );
      if (!perspective) return false;

      const goalTotal =
        perspective.goals?.reduce((sum, g) => sum + Number(g.weight || 0), 0) ||
        0;

      return (
        perspective.goals &&
        perspective.goals.length > 0 &&
        Math.abs(goalTotal - change.newWeight) > 0.01
      );
    });
  });

  return {
    // Perspective validation
    perspectiveWeightsValid,
    totalPerspectiveWeight,
    getPerspectiveValidationState,
    allPerspectivesValid,

    // Goal validation
    getGoalValidationState,
    allGoalsValid,

    // Objective validation
    validateObjectiveWeights,
    allObjectivesValid,

    // Combined validation
    allWeightsValid,
    validationErrors,

    // Available weights
    getAvailableGoalWeight,
    getAvailableObjectiveWeight,
    findGoalById,

    // Weight distribution
    distributeGoalWeightsEqually,
    distributeObjectiveWeightsEqually,

    // Weight tracking
    initializeWeightTracking,
    perspectiveWeightChanges,
    hasWeightConflicts,
    originalPerspectiveWeights,
  };
}
