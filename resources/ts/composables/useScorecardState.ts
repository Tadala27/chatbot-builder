import { ref, computed } from "vue";
import axios from "axios";
import type { Ref } from "vue";
import Swal from "sweetalert2";
import { useScorecardValidation } from "./useScorecardValidation";

export interface ScorecardPerspective {
  id: number | string;
  perspective_id: number;
  perspective_name: string;
  weight: number;
  score: number;
  goals?: ScorecardGoal[];
}

export interface ScorecardGoal {
  id?: number;
  description: string;
  weight: number;
  sort_order?: number;
  objectives?: ScorecardObjective[];
  perspective?: any;
}

export interface ScorecardObjective {
  id?: number;
  description: string;
  objective_type: "continuous" | "absolute";
  absolute_value: string | null;
  target_type: "numeric" | "percentage" | "currency" | "boolean";
  appraisal_behaviour: "higher_better" | "higher_bad";
  weight: number;
  requires_proof: boolean;
  proof_requirements: string;
  targets: ScorecardTarget[];
  sort_order?: number;
}

export interface ScorecardTarget {
  id?: number;
  threshold_config_id: number | null;
  threshold_name: string;
  target_value: number;
}

export interface Scorecard {
  id: number | null;
  position: any;
  performancePeriod: any;
  position_id: number;
  financial_year_id: number;
  performance_period_id: number;
  bsc_template_id: number;
  matrix_template_id: number;
  status: string;
  is_contract_signed_by_holder?: boolean;
  is_contract_signed_by_manager?: boolean;
  has_manager_changes?: boolean;
  manager_signature_comments?: string;
  perspectives?: ScorecardPerspective[];
}

export interface TenantConfig {
  matrixTemplate?: {
    requires_thresholds: boolean;
    threshold_configs: any[];
  };
  bscTemplate?: any;
}

export interface ScorecardPermissions {
  can_manage: boolean;
  can_edit: boolean;
  can_sign_as_employee: boolean;
  can_sign_as_manager: boolean;
  can_view_manager_changes: boolean;
  can_accept_manager_changes: boolean;
  can_reject_manager_changes: boolean;
  is_owner: boolean;
  is_supervisor: boolean;
  has_pending_changes: boolean;
}

export function useScorecardState(scorecardId?: string | number) {
  // ========================================================================
  // STATE
  // ========================================================================
  const isLoading = ref(true);
  const isSaving = ref(false);
  const scorecard: Ref<Scorecard | null> = ref(null);
  const perspectives: Ref<ScorecardPerspective[]> = ref([]);
  const tenantConfig: Ref<TenantConfig | null> = ref(null);
  const permissions = ref<ScorecardPermissions>({
    can_manage: false,
    can_edit: false,
    can_sign_as_employee: false,
    can_sign_as_manager: false,
    can_view_manager_changes: false,
    can_accept_manager_changes: false,
    can_reject_manager_changes: false,
    is_owner: false,
    is_supervisor: false,
    has_pending_changes: false,
  });

  // Snackbar state
  const snackbar = ref({
    show: false,
    message: "",
    color: "success",
    timeout: 4000,
  });

  // ========================================================================
  // INTEGRATE VALIDATION COMPOSABLE
  // ========================================================================
  const validation = useScorecardValidation(perspectives, scorecard);

  const {
    allWeightsValid,
    validationErrors,
    getPerspectiveValidationState,
    getGoalValidationState,
    getAvailableGoalWeight,
    getAvailableObjectiveWeight,
    distributeGoalWeightsEqually,
    findGoalById,
    initializeWeightTracking,
  } = validation;

  // ========================================================================
  // COMPUTED
  // ========================================================================
  const hasScorecard = computed(() => !!scorecard.value?.id);
  const canEdit = computed(() => permissions.value.can_edit);
  const canSignAsEmployee = computed(
    () => permissions.value.can_sign_as_employee,
  );
  const canSignAsManager = computed(
    () => permissions.value.can_sign_as_manager,
  );
  const canViewChanges = computed(
    () => permissions.value.can_view_manager_changes,
  );
  const canAcceptChanges = computed(
    () => permissions.value.can_accept_manager_changes,
  );
  const canRejectChanges = computed(
    () => permissions.value.can_reject_manager_changes,
  );
  const isReadOnly = computed(() => !permissions.value.can_edit);
  const isOwner = computed(() => permissions.value.is_owner);
  const isSupervisor = computed(() => permissions.value.is_supervisor);
  const hasPendingChanges = computed(
    () => permissions.value.has_pending_changes,
  );

  const requiresThresholds = computed(
    () => tenantConfig.value?.matrixTemplate?.requires_thresholds ?? false,
  );

  const thresholdConfigs = computed(
    () => tenantConfig.value?.matrixTemplate?.threshold_configs || [],
  );

  const scorecardStatus = computed(() => scorecard.value?.status || "draft");
  const isDraft = computed(() => scorecardStatus.value === "draft");
  const isSubmitted = computed(() => scorecardStatus.value === "submitted");
  const isManagerReview = computed(
    () => scorecardStatus.value === "manager_review",
  );
  const isPendingEmployeeAcceptance = computed(
    () => scorecardStatus.value === "pending_employee_acceptance",
  );
  const isApproved = computed(() => scorecardStatus.value === "approved");

  // NEW: Determine what signature action is available
  const signatureAction = computed(() => {
    if (isOwner.value) {
      if (canSignAsEmployee.value && isDraft.value) {
        return "employee_initial"; // First signature
      }
      if (canSignAsEmployee.value && isPendingEmployeeAcceptance.value) {
        return "employee_final"; // Accept manager changes
      }
    }
    if (isSupervisor.value) {
      if (canSignAsManager.value && isManagerReview.value) {
        return "manager"; // Manager signature
      }
    }
    return null;
  });

  // ========================================================================
  // METHODS - Data Fetching
  // ========================================================================

  /**
   * Fetch tenant configuration
   */
  const fetchTenantConfig = async (tenantId?: number) => {
    try {
      let targetTenantId = tenantId;

      if (!targetTenantId) {
        const userResponse = await axios.get("/api/auth/user");
        targetTenantId = userResponse.data.data.tenant_id;
      }

      const { data } = await axios.get(
        `/api/tenants/${targetTenantId}/configuration`,
      );
      tenantConfig.value = data.data;
      return data.data;
    } catch (error: any) {
      showSnackbar("Failed to load tenant configuration", "error");
      throw error;
    }
  };

  /**
   * Load scorecard by ID - Use manager-view endpoint for supervisors
   */
  const loadScorecard = async (id?: string | number) => {
    isLoading.value = true;
    try {
      const targetId = id || scorecardId;

      if (!targetId) {
        // Load current user's scorecard
        const { data } = await axios.get("/api/scorecards/current");

        if (data.data) {
          scorecard.value = data.data;
          perspectives.value = data.data.perspectives || [];
          permissions.value = data.permissions || permissions.value;
        } else {
          // Create initial scorecard structure
          const userResponse = await axios.get("/api/auth/user");
          const user = userResponse.data.data;

          scorecard.value = {
            id: null,
            position: { name: user.position?.name || "Your Position" },
            performancePeriod: {
              name: data.config?.performancePeriod?.name || "Current Period",
            },
            position_id: data.position_id,
            financial_year_id: data.financial_year_id,
            performance_period_id: data.performance_period_id,
            bsc_template_id: data.config?.bscTemplate?.id,
            matrix_template_id: data.config?.matrixTemplate?.id,
            status: "draft",
          };

          perspectives.value =
            data.config?.bscTemplate?.perspectives?.map(
              (p: any, index: number) => ({
                id: p.id,
                perspective_id: p.id,
                perspective_name: p.name,
                weight: p.default_weight || 25,
                score: 0,
                goals: [],
              }),
            ) || [];
        }
      } else {
        // NEW: Use manager-view endpoint for better handling
        try {
          const { data } = await axios.get(
            `/api/scorecards/${targetId}/manager-view`,
          );

          if (data.success) {
            scorecard.value = data.data;
            perspectives.value = data.data.perspectives || [];
            permissions.value = data.permissions || permissions.value;
          }
        } catch (error: any) {
          // Fallback to regular endpoint if manager-view fails
          const { data } = await axios.get(`/api/scorecards/${targetId}`);

          if (data.success) {
            scorecard.value = data.data;
            perspectives.value = data.data.perspectives || [];
            permissions.value = data.permissions || permissions.value;
          }
        }
      }

      // Initialize weight tracking after loading
      initializeWeightTracking();
    } catch (error: any) {
      showSnackbar(
        error.response?.data?.message || "Failed to load scorecard",
        "error",
      );
      throw error;
    } finally {
      isLoading.value = false;
    }
  };

  /**
   * Reload scorecard data
   */
  const reloadScorecard = async () => {
    if (scorecard.value?.id) {
      await loadScorecard(scorecard.value.id);
    } else {
      await loadScorecard();
    }
  };

  // ========================================================================
  // METHODS - Perspective Operations
  // ========================================================================

  /**
   * Update perspective weights
   */
  const updatePerspectiveWeights = async (
    weights: Array<{ id: number | string; weight: number }>,
  ) => {
    if (!scorecard.value?.id) {
      showSnackbar("No scorecard to update", "error");
      return false;
    }

    try {
      await axios.put(
        `/api/scorecards/${scorecard.value.id}/perspectives/weights`,
        { perspectives: weights },
      );

      await reloadScorecard();
      showSnackbar("Perspective weights updated successfully", "success");
      return true;
    } catch (error: any) {
      showSnackbar(
        error.response?.data?.message || "Failed to update weights",
        "error",
      );
      return false;
    }
  };

  // ========================================================================
  // METHODS - Goal & Objective Operations
  // ========================================================================

  /**
   * Save goals and objectives - UNIFIED endpoint
   */
  const saveGoalsObjectives = async (payload: {
    mode: "add" | "edit";
    scorecardId?: number;
    perspectiveId?: number | string;
    goals: any[];
  }) => {
    isSaving.value = true;
    try {
      await axios.post("/api/scorecards/goals-objectives/save", {
        ...payload,
        scorecardId: payload.scorecardId || scorecard.value?.id,
      });

      await reloadScorecard();
      showSnackbar("Saved successfully", "success");
      return true;
    } catch (error: any) {
      const message = error.response?.data?.message || "Failed to save";
      showSnackbar(message, "error");
      throw error;
    } finally {
      isSaving.value = false;
    }
  };

  /**
   * Delete a goal
   */
  const deleteGoal = async (goalId: number) => {
    try {
      await axios.delete(`/api/scorecards/goals/${goalId}`);
      await reloadScorecard();
      showSnackbar("Goal deleted successfully", "success");
      return true;
    } catch (error: any) {
      showSnackbar(
        error.response?.data?.message || "Failed to delete goal",
        "error",
      );
      return false;
    }
  };

  /**
   * Delete an objective
   */
  const deleteObjective = async (objectiveId: number) => {
    try {
      await axios.delete(`/api/scorecards/objectives/${objectiveId}`);
      await reloadScorecard();
      showSnackbar("Objective deleted successfully", "success");
      return true;
    } catch (error: any) {
      showSnackbar(
        error.response?.data?.message || "Failed to delete objective",
        "error",
      );
      return false;
    }
  };

  // ========================================================================
  // METHODS - NEW SIGNATURE WORKFLOW
  // ========================================================================

  /**
   * Unified sign contract - handles all signature types
   */
  const signContract = async (signatureType: string, comments?: string) => {
    if (!scorecard.value?.id) {
      showSnackbar("No scorecard to sign", "error");
      return false;
    }

    try {
      const { data } = await axios.post(
        `/api/scorecards/${scorecard.value.id}/sign-contract`,
        {
          signature_type: signatureType,
          comments: comments || "",
        },
      );

      return data;
    } catch (error: any) {
      throw error;
    }
  };

  /**
   * Handle employee initial signature
   */
  const handleEmployeeInitialSign = async () => {
    // Validation checks
    if (!allWeightsValid.value) {
      await Swal.fire({
        title: "Invalid Weights",
        text: "Please fix all weight validation errors before signing.",
        icon: "error",
        confirmButtonColor: "rgba(var(--v-theme-error))",
        customClass: {
          confirmButton: "text-white",
        },
      });
      return false;
    }

    const hasObjectives = perspectives.value.some((p) =>
      p.goals?.some((g: any) => g.objectives?.length > 0),
    );

    if (!hasObjectives) {
      await Swal.fire({
        title: "No Objectives",
        text: "Please add at least one objective before signing.",
        icon: "error",
        confirmButtonColor: "rgba(var(--v-theme-error))",
        customClass: {
          confirmButton: "text-white",
        },
      });
      return false;
    }

    // Confirmation
    const result = await Swal.fire({
      title: "Sign & Submit Scorecard?",
      html: `
        <div style="text-align: left; margin-top: 10px;">
          <p style="margin-bottom: 12px;">By signing, you confirm:</p>
          <ul style="margin-left: 20px; margin-bottom: 12px;">
            <li>All objectives and targets are accurate</li>
            <li>You understand your performance will be measured against these targets</li>
          </ul>
          <p style="color: rgba(var(--v-theme-warning)); font-weight: 500; margin-top: 16px;">
            Your manager will be notified for review
          </p>
        </div>
      `,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Yes, Sign",
      cancelButtonText: "Cancel",
      confirmButtonColor: "rgba(var(--v-theme-primary))",
      cancelButtonColor: "rgba(var(--v-theme-error))",
      customClass: {
        confirmButton: "text-white",
        cancelButton: "text-white",
      },
      showLoaderOnConfirm: true,
      preConfirm: async () => {
        try {
          return await signContract("employee_initial");
        } catch (error: any) {
          Swal.showValidationMessage(
            error.response?.data?.message || "Failed to sign",
          );
          return false;
        }
      },
    });

    if (result.isConfirmed && result.value?.success) {
      await Swal.fire({
        title: "Submitted!",
        html: `
          <p>Your scorecard has been submitted to your manager.</p>
          ${result.value.notification_sent ? '<p style="color: rgba(var(--v-theme-success)); margin-top: 10px;">✓ Manager notified</p>' : ""}
        `,
        icon: "success",
        confirmButtonColor: "rgba(var(--v-theme-success))",
        customClass: {
          confirmButton: "text-white",
        },
        timer: 3000,
        timerProgressBar: true,
      });

      await reloadScorecard();
      return true;
    }

    return false;
  };

  /**
   * Handle manager signature
   */
  const handleManagerSign = async () => {
    const result = await Swal.fire({
      title: "Sign Scorecard",
      html: `
        <div style="text-align: left;">
          <p style="margin-bottom: 16px;">
            You are about to sign <strong>${scorecard.value?.position?.current_holder?.user?.firstname}'s</strong> scorecard.
          </p>
          <p style="color: rgba(var(--v-theme-light)); font-size: 14px; margin-bottom: 12px;">
            ${
              scorecard.value?.has_manager_changes
                ? "You made changes - employee will need to review and accept them"
                : "No changes made - scorecard will be fully approved"
            }
          </p>
          <textarea 
            id="swal-comments" 
            class="swal2-input" 
            placeholder="Comments (optional)" 
            rows="4"
            style="height: 100px; resize: vertical;"
          ></textarea>
        </div>
      `,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Sign & Submit",
      cancelButtonText: "Cancel",
      confirmButtonColor: "rgba(var(--v-theme-primary))",
      cancelButtonColor: "rgba(var(--v-theme-error))",
      customClass: {
        confirmButton: "text-white",
        cancelButton: "text-white",
      },
      preConfirm: () => {
        const comments =
          (document.getElementById("swal-comments") as HTMLTextAreaElement)
            ?.value || "";
        return { comments };
      },
      showLoaderOnConfirm: true,
    });

    if (result.isConfirmed) {
      try {
        const response = await signContract("manager", result.value.comments);

        if (response.success) {
          const message = response.has_changes
            ? "Changes sent to employee for review"
            : "Scorecard fully approved!";

          await Swal.fire({
            title: "Signed!",
            text: message,
            icon: "success",
            confirmButtonColor: "rgba(var(--v-theme-primary))",
            customClass: {
              confirmButton: "text-white",
            },
            timer: 3000,
            timerProgressBar: true,
          });

          await reloadScorecard();
          return true;
        }
      } catch (error: any) {
        await Swal.fire({
          title: "Error",
          text: error.response?.data?.message || "Failed to sign",
          icon: "error",
          confirmButtonColor: "rgba(var(--v-theme-error))",
          customClass: {
            confirmButton: "text-white",
          },
        });
      }
    }

    return false;
  };

  /**
   * Handle employee final signature (accept changes)
   */
  const handleEmployeeFinalSign = async () => {
    const result = await Swal.fire({
      title: "Accept Manager's Changes?",
      html: `
        <p>By signing, you accept all changes made by your manager.</p>
        <p style="margin-top: 12px; color: rgba(var(--v-theme-error));">
          ✓ The scorecard will be fully approved
        </p>
      `,
      icon: "question",
      showCancelButton: true,
      confirmButtonText: "Accept & Sign",
      cancelButtonText: "Cancel",
      confirmButtonColor: "rgba(var(--v-theme-primary))",
      cancelButtonColor: "rgba(var(--v-theme-error))",
      customClass: {
        confirmButton: "text-white",
        cancelButton: "text-white",
      },
      showLoaderOnConfirm: true,
      preConfirm: async () => {
        try {
          return await signContract("employee_final");
        } catch (error: any) {
          Swal.showValidationMessage(
            error.response?.data?.message || "Failed to sign",
          );
          return false;
        }
      },
    });

    if (result.isConfirmed && result.value?.success) {
      await Swal.fire({
        title: "Approved!",
        text: "You have accepted the changes. The scorecard is now fully approved.",
        icon: "success",
        confirmButtonColor: "rgba(var(--v-theme-success))",
        timer: 3000,
        timerProgressBar: true,
      });

      await reloadScorecard();
      return true;
    }

    return false;
  };

  /**
   * Get change comparison data
   */
  const getChangeComparison = async () => {
    if (!scorecard.value?.id) {
      return null;
    }

    try {
      const { data } = await axios.get(
        `/api/scorecards/${scorecard.value.id}/change-comparison`,
      );
      return data.data;
    } catch (error: any) {
      showSnackbar("Failed to load changes", "error");
      return null;
    }
  };

  /**
   * Reject manager changes
   */
  const rejectManagerChanges = async (comments: string) => {
    if (!scorecard.value?.id) {
      showSnackbar("No scorecard to update", "error");
      return false;
    }

    try {
      const { data } = await axios.post(
        `/api/scorecards/${scorecard.value.id}/reject-manager-changes`,
        { comments },
      );

      return data;
    } catch (error: any) {
      throw error;
    }
  };

  /**
   * Handle reject manager changes with confirmation
   */
  const handleRejectChanges = async () => {
    const result = await Swal.fire({
      title: "Reject Manager's Changes?",
      html: `
        <div style="text-align: left;">
          <p style="margin-bottom: 16px;">
            The scorecard will be returned to draft status.
          </p>
          <textarea 
            id="swal-rejection-comments" 
            class="swal2-input" 
            placeholder="Explain why you're rejecting (required)" 
            rows="4"
            style="height: 100px; resize: vertical;"
          ></textarea>
        </div>
      `,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Reject Changes",
      cancelButtonText: "Cancel",
      confirmButtonColor: "rgba(var(--v-theme-primary))",
      cancelButtonColor: "rgba(var(--v-theme-error))",
      customClass: {
        confirmButton: "text-white",
        cancelButton: "text-white",
      },
      preConfirm: () => {
        const comments =
          (
            document.getElementById(
              "swal-rejection-comments",
            ) as HTMLTextAreaElement
          )?.value || "";
        if (!comments || comments.length < 10) {
          Swal.showValidationMessage(
            "Please provide detailed feedback (minimum 10 characters)",
          );
          return false;
        }
        return { comments };
      },
    });

    if (result.isConfirmed && result.value) {
      try {
        const response = await rejectManagerChanges(result.value.comments);

        if (response.success) {
          await Swal.fire({
            title: "Changes Rejected",
            text: "The scorecard has been returned to draft. You can now make revisions.",
            icon: "info",
            confirmButtonColor: "rgba(var(--v-theme-primary))",
            customClass: {
              confirmButton: "text-white",
            },
          });

          await reloadScorecard();
          return true;
        }
      } catch (error: any) {
        await Swal.fire({
          title: "Error",
          text: error.response?.data?.message || "Failed to reject changes",
          icon: "error",
          confirmButtonColor: "rgba(var(--v-theme-error))",
          customClass: {
            confirmButton: "text-white",
          },
        });
      }
    }

    return false;
  };

  // ========================================================================
  // METHODS - Utility
  // ========================================================================

  /**
   * Show snackbar notification
   */
  const showSnackbar = (
    message: string,
    color: string = "success",
    timeout: number = 4000,
  ) => {
    snackbar.value = {
      show: true,
      message,
      color,
      timeout,
    };
  };

  /**
   * Get status badge configuration
   */
  const getStatusConfig = (status: string) => {
    const config: Record<
      string,
      { color: string; icon: string; text: string }
    > = {
      draft: { color: "warning", icon: "$pencil", text: "Draft" },
      submitted: { color: "info", icon: "$send", text: "Submitted" },
      manager_review: {
        color: "primary",
        icon: "$accountSupervisor",
        text: "Manager Review",
      },
      pending_employee_acceptance: {
        color: "orange",
        icon: "$alert",
        text: "Pending Your Review",
      },
      approved: { color: "success", icon: "$checkCircle", text: "Approved" },
    };
    return (
      config[status] || { color: "default", icon: "$information", text: status }
    );
  };

  // ========================================================================
  // INITIALIZATION
  // ========================================================================

  /**
   * Initialize the scorecard state
   */
  const initialize = async (id?: string | number) => {
    try {
      if (id || scorecardId) {
        await loadScorecard(id || scorecardId);
        if (scorecard.value?.tenant_id) {
          await fetchTenantConfig(scorecard.value.tenant_id);
        }
      } else {
        await fetchTenantConfig();
        await loadScorecard();
      }
    } catch (error) {
      console.error("Initialization error:", error);
    }
  };

  return {
    // State
    isLoading,
    isSaving,
    scorecard,
    perspectives,
    permissions,
    tenantConfig,
    snackbar,

    // Validation
    ...validation,

    // Computed
    hasScorecard,
    isReadOnly,
    requiresThresholds,
    thresholdConfigs,
    canEdit,
    canSignAsEmployee,
    canSignAsManager,
    canViewChanges,
    canAcceptChanges,
    canRejectChanges,
    isOwner,
    isSupervisor,
    hasPendingChanges,
    scorecardStatus,
    isDraft,
    isSubmitted,
    isManagerReview,
    isPendingEmployeeAcceptance,
    isApproved,
    signatureAction,

    // Methods
    initialize,
    reloadScorecard,
    loadScorecard,
    fetchTenantConfig,
    updatePerspectiveWeights,
    saveGoalsObjectives,
    deleteGoal,
    deleteObjective,
    signContract,
    handleEmployeeInitialSign,
    handleManagerSign,
    handleEmployeeFinalSign,
    getChangeComparison,
    rejectManagerChanges,
    handleRejectChanges,
    showSnackbar,
    getStatusConfig,
  };
}
