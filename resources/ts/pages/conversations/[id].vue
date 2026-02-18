<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import Swal from 'sweetalert2'
import ScorecardPerspectiveCards from '@/components/scorecard/ScorecardPerspectiveCards.vue'
import UnifiedGoalObjectiveDialog from '@/components/scorecard/UnifiedGoalObjectiveDialog.vue'
import PerspectiveWeightsDialog from '@/components/scorecard/PerspectiveWeightsDialog.vue'
import FullScorecardUploadDialog from '@/components/scorecard/FullScorecardUploadDialog.vue'
import ChangeComparisonDialog from '@/components/scorecard/ChangeComparisonDialog.vue'
import { useScorecardState } from '@/composables/useScorecardState'
import { useScorecardValidation } from '@/composables/useScorecardValidation'

const router = useRouter()
const route = useRoute()
const scorecardId = computed(() => route.params.id as string)

// ========================================================================
// COMPOSABLES
// ========================================================================
const {
    // State
    isLoading,
    isSaving,
    scorecard,
    perspectives,
    tenantConfig,
    permissions,
    snackbar,
    // Computed
    hasScorecard,
    canEdit,
    canSignAsEmployee,
    canSignAsManager,
    canViewChanges,
    canAcceptChanges,
    canRejectChanges,
    isReadOnly,
    isOwner,
    isSupervisor,
    isDraft,
    isSubmitted,
    isManagerReview,
    isPendingEmployeeAcceptance,
    isApproved,
    hasPendingChanges,
    signatureAction,
    // Methods
    initialize,
    reloadScorecard,
    saveGoalsObjectives,
    deleteGoal,
    deleteObjective,
    handleEmployeeInitialSign,
    handleManagerSign,
    handleEmployeeFinalSign,
    getChangeComparison,
    handleRejectChanges
} = useScorecardState(scorecardId.value)

const {
    // Validation
    allWeightsValid,
    validationErrors,
    getPerspectiveValidationState,
    getGoalValidationState,
    getAvailableGoalWeight,
    getAvailableObjectiveWeight,
    distributeGoalWeightsEqually,
    findGoalById,
    initializeWeightTracking
} = useScorecardValidation(perspectives, scorecard)

// ========================================================================
// DIALOG STATE
// ========================================================================
const dialogs = ref({
    unifiedGoalObjective: false,
    perspectiveWeights: false,
    fullScorecardUpload: false,
    changeComparison: false
})

// Initial dialog state factory
const createInitialDialogState = () => ({
    mode: 'add' as 'add' | 'edit',
    selectedPerspective: null as any,
    selectedGoal: null as any,
    selectedObjective: null as any
})

const dialogState = ref(createInitialDialogState())

const openPanels = ref<number[]>([])

// Track if manager has started editing
const hasStartedEditing = ref(false)

// ========================================================================
// WATCH DIALOG STATE - RESET ON CLOSE
// ========================================================================
watch(() => dialogs.value.unifiedGoalObjective, (isOpen) => {
    if (!isOpen) {
        // Reset dialog state when closed
        setTimeout(() => {
            dialogState.value = createInitialDialogState()
        }, 300) // Small delay to allow dialog to close smoothly
    }
})

// ========================================================================
// COMPUTED
// ========================================================================
const employee = computed(() => scorecard.value?.position?.current_holder?.user || null)
const position = computed(() => scorecard.value?.position || null)

const goalsCount = computed(() => {
    return perspectives.value.reduce(
        (total, perspective) => total + (perspective.goals?.length || 0),
        0
    )
})

const objectivesCount = computed(() => {
    return perspectives.value.reduce((total, perspective) => {
        return total + (perspective.goals?.reduce(
            (goalTotal: number, goal: any) => goalTotal + (goal.objectives?.length || 0),
            0
        ) || 0)
    }, 0)
})

const viewType = computed(() => {
    if (isOwner.value) return 'self'
    if (isSupervisor.value) return 'supervisor'
    return 'viewer'
})

const pageTitle = computed(() => {
    if (viewType.value === 'supervisor') {
        return `${employee.value?.firstname || 'Team Member'}'s Scorecard`
    }
    return 'My Scorecard'
})

const pageSubtitle = computed(() => {
    if (viewType.value === 'supervisor') {
        if (isPendingEmployeeAcceptance.value) {
            return 'Waiting for employee to review your changes'
        }
        return canEdit.value ? 'Review and manage performance objectives' : 'View performance objectives'
    }
    if (isPendingEmployeeAcceptance.value) {
        return 'Your manager made changes - please review and respond'
    }
    return canEdit.value ? 'Edit your performance objectives' : 'View your performance objectives'
})

// Status badge configuration
const statusConfig = computed(() => {
    const status = scorecard.value?.status || 'draft'
    const configs: Record<string, any> = {
        draft: {
            color: 'warning',
            icon: '$pencil',
            text: 'Draft',
            description: 'Working on scorecard'
        },
        submitted: {
            color: 'info',
            icon: '$send',
            text: 'Submitted',
            description: 'Waiting for manager review'
        },
        manager_review: {
            color: 'primary',
            icon: '$accountSupervisor',
            text: 'Under Manager Review',
            description: 'Manager is reviewing'
        },
        pending_employee_acceptance: {
            color: 'orange',
            icon: '$alert',
            text: 'Pending Your Review',
            description: 'Manager made changes - please review'
        },
        approved: {
            color: 'success',
            icon: '$checkCircle',
            text: 'Approved',
            description: 'Fully signed by both parties'
        }
    }
    return configs[status] || configs.draft
})

// ========================================================================
// DIALOG HANDLERS
// ========================================================================

const openAddGoalsObjectives = (perspective: any) => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }

    // Reset and set new state
    dialogState.value = {
        mode: 'add',
        selectedPerspective: perspective,
        selectedGoal: null,
        selectedObjective: null
    }
    dialogs.value.unifiedGoalObjective = true
}

const openEditGoal = (goal: any) => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }

    // Find the perspective for this goal
    const perspective = perspectives.value.find(p =>
        p.goals?.some(g => g.id === goal.id)
    )

    // Reset and set new state
    dialogState.value = {
        mode: 'edit',
        selectedPerspective: perspective || null,
        selectedGoal: goal,
        selectedObjective: null
    }
    dialogs.value.unifiedGoalObjective = true
}

const openEditWeights = () => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }


    dialogs.value.perspectiveWeights = true
}

const openFullScorecardUpload = () => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }
    dialogs.value.fullScorecardUpload = true
}

const openChangeComparison = async () => {
    if (!canViewChanges.value) {
        showSnackbar('No changes to view', 'info')
        return
    }
    dialogs.value.changeComparison = true
}

// ========================================================================
// DELETE HANDLERS
// ========================================================================

const handleDeleteGoal = async (goalId: number) => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }

    const { isConfirmed } = await Swal.fire({
        title: 'Delete Goal',
        text: 'This will remove the goal and all associated objectives. Continue?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Yes, delete!'
    })

    if (isConfirmed) {
        await deleteGoal(goalId)
    }
}

const handleDeleteObjective = async (objectiveId: number) => {
    if (!canEdit.value) {
        showSnackbar('You do not have permission to edit this scorecard', 'error')
        return
    }

    const { isConfirmed } = await Swal.fire({
        title: 'Delete Objective',
        text: 'This will remove the objective. Continue?',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Yes, delete!'
    })

    if (isConfirmed) {
        await deleteObjective(objectiveId)
    }
}

// ========================================================================
// SIGNATURE HANDLERS - Called from ScorecardPerspectiveCards
// ========================================================================

const onEmployeeInitialSign = async () => {
    const success = await handleEmployeeInitialSign()
    if (success) {
        await reloadScorecard()
    }
}

const onManagerSign = async () => {
    const success = await handleManagerSign()
    if (success) {
        await reloadScorecard()
    }
}

const onEmployeeFinalSign = async () => {
    const success = await handleEmployeeFinalSign()
    if (success) {
        await reloadScorecard()
    }
}

const onRejectChanges = async () => {
    const success = await handleRejectChanges()
    if (success) {
        await reloadScorecard()
    }
}

// ========================================================================
// HELPERS
// ========================================================================

const showSnackbar = (message: string, color = 'success') => {
    snackbar.value = { show: true, message, color, timeout: 4000 }
}

const goBack = () => {
    if (viewType.value === 'supervisor') {
        router.push('/subordinates/scorecards')
    } else {
        router.push('/scorecards')
    }
}

// ========================================================================
// LIFECYCLE
// ========================================================================

onMounted(async () => {
    await initialize()
    initializeWeightTracking()
    openPanels.value = perspectives.value.map((_, index) => index)

    // Track if already in manager_review
    if (isManagerReview.value) {
        hasStartedEditing.value = true
    }
})
</script>

<template>
    <div>
        <!-- Loading -->
        <v-container v-if="isLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" size="64" />
        </v-container>

        <!-- Main Content -->
        <div v-else-if="scorecard">
            <!-- Header with Back Button -->
            <div class="d-flex align-center mb-4">
                <v-btn icon variant="text" @click="goBack" class="mr-2">
                    <v-icon>$arrowLeft</v-icon>
                </v-btn>
                <div class="flex-grow-1">
                    <h1 class="text-h4 mb-1">{{ pageTitle }}</h1>
                    <p class="text-subtitle-1 text-medium-emphasis mb-0">
                        {{ pageSubtitle }}
                    </p>
                </div>
                <div class="d-flex gap-2">
                    <!-- Status Chip -->
                    <v-chip :color="statusConfig.color" variant="flat" :prepend-icon="statusConfig.icon">
                        {{ statusConfig.text }}
                    </v-chip>

                    <!-- Edit Actions - Available when canEdit -->
                    <template v-if="canEdit && !isPendingEmployeeAcceptance">
                        <v-btn color="secondary" variant="outlined" @click="openEditWeights"
                            prepend-icon="$scaleUnbalanced">
                            Edit Weights
                        </v-btn>
                        <v-btn color="success" variant="outlined" @click="openFullScorecardUpload"
                            prepend-icon="$upload">
                            Upload Scorecard
                        </v-btn>
                    </template>

                    <!-- View Changes Button (for employees with pending changes) -->
                    <v-btn v-if="canViewChanges && hasPendingChanges" color="info" variant="outlined"
                        @click="openChangeComparison" prepend-icon="$compare">
                        View Changes
                    </v-btn>

                    <!-- Reject Changes Button -->
                    <v-btn v-if="canRejectChanges && hasPendingChanges" color="error" variant="outlined"
                        @click="onRejectChanges" prepend-icon="$close">
                        Reject Changes
                    </v-btn>
                </div>
            </div>

            <!-- Summary Cards -->
            <v-row class="mb-4">
                <!-- Profile Card -->
                <v-col cols="12" md="4">
                    <v-card variant="outlined" class="h-100">
                        <v-card-text class="pa-6">
                            <v-chip color="primary" size="small" class="mb-4">
                                {{ isSupervisor ? 'Team Member' : 'Profile' }}
                            </v-chip>

                            <div class="d-flex align-center mb-4">
                                <v-avatar size="56" color="grey-lighten-3" class="mr-4">
                                    <v-icon size="32" color="grey-darken-1">$account</v-icon>
                                </v-avatar>

                                <div class="flex-grow-1">
                                    <div class="text-h6 font-weight-medium">
                                        {{ employee?.firstname }} {{ employee?.lastname }}
                                    </div>
                                    <div class="text-body-2 text-medium-emphasis mt-1">
                                        <v-icon size="18" class="mr-1">$domain</v-icon>
                                        {{ position?.name }}
                                    </div>
                                    <div class="text-body-2 text-medium-emphasis">
                                        <v-icon size="18" class="mr-1">$siteMap</v-icon>
                                        {{ position?.business_unit?.name }}
                                    </div>
                                </div>
                            </div>

                            <!-- Supervisor indicator -->
                            <v-chip v-if="isSupervisor && !isOwner" size="small" color="info" variant="tonal"
                                class="mt-3">
                                <v-icon start size="small">$accountSupervisor</v-icon>
                                You are the supervisor
                            </v-chip>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Goals Summary -->
                <v-col cols="12" md="4">
                    <v-card variant="outlined" class="h-100">
                        <v-card-text class="pa-6">
                            <div class="d-flex justify-space-between align-start">
                                <div>
                                    <div class="text-h2 font-weight-bold text-primary">{{ goalsCount }}</div>
                                    <div class="text-subtitle-1 text-primary">Goals</div>
                                </div>
                                <v-icon size="24">$chartBox</v-icon>
                            </div>
                            <v-divider class="my-4" />
                            <div class="d-flex justify-space-between align-center">
                                <span class="text-body-1">{{ objectivesCount }} Objectives</span>
                                <v-icon size="24">$dotsHorizontal</v-icon>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <!-- Perspectives Summary -->
                <v-col cols="12" md="4">
                    <v-card variant="outlined" class="h-100">
                        <v-card-text class="pa-6">
                            <v-list density="compact" class="bg-transparent">
                                <v-list-item v-for="(p, index) in perspectives" :key="p.id">
                                    <template #prepend>
                                        <v-avatar size="32" color="grey-darken-3" class="text-white">
                                            {{ index + 1 }}
                                        </v-avatar>
                                    </template>
                                    <v-list-item-title>{{ p.perspective_name }}</v-list-item-title>
                                    <template #append>
                                        <v-chip rounded="sm" :color="p.color" variant="flat">
                                            {{ p.weight }}%
                                        </v-chip>
                                    </template>
                                </v-list-item>
                            </v-list>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Manager Changes Alert -->
            <v-alert v-if="hasPendingChanges && isPendingEmployeeAcceptance && isOwner" type="warning" variant="tonal"
                prominent class="mb-4">
                <template #prepend>
                    <v-icon size="large">$alert</v-icon>
                </template>
                <v-alert-title class="text-h6 mb-2">Manager Made Changes</v-alert-title>
                <div class="text-body-2">
                    <p class="mb-2">Your manager has reviewed and modified your scorecard.</p>
                    <p class="mb-3">Please review the changes and decide whether to accept or reject them.</p>
                    <div class="d-flex gap-2">
                        <v-btn color="info" variant="flat" prepend-icon="$compare" @click="openChangeComparison">
                            View Changes
                        </v-btn>
                        <v-btn color="success" variant="outlined" prepend-icon="$checkCircle"
                            @click="onEmployeeFinalSign">
                            Accept Changes
                        </v-btn>
                        <v-btn color="error" variant="outlined" prepend-icon="$close" @click="onRejectChanges">
                            Reject Changes
                        </v-btn>
                    </div>
                </div>
            </v-alert>

            <!-- Manager Waiting Alert -->
            <v-alert v-if="isPendingEmployeeAcceptance && isSupervisor" type="info" variant="tonal" class="mb-4">
                Waiting for employee to review and respond to your changes.
            </v-alert>

            <!-- Validation Banner - Only show to users who can edit -->
            <v-alert v-if="!allWeightsValid && hasScorecard && canEdit" type="error" variant="tonal" prominent
                class="mb-4">
                <template #prepend>
                    <v-icon size="large">$alertCircle</v-icon>
                </template>
                <v-alert-title class="text-h6 mb-2">Invalid Weight Distribution</v-alert-title>
                <div class="text-body-2">
                    <p class="mb-2">Weight validation errors must be fixed before signing:</p>
                    <ul class="ml-4">
                        <li v-for="(error, index) in validationErrors" :key="index">{{ error }}</li>
                    </ul>
                </div>
            </v-alert>

            <!-- Read-only info banner -->
            <v-alert v-else-if="!canEdit && hasScorecard && !isApproved && !isPendingEmployeeAcceptance" type="info"
                variant="tonal" density="compact" class="mb-4">
                <v-icon start>$information</v-icon>
                {{ isSupervisor ?
                    'This scorecard is in read-only mode. Use the sign button to complete your review.' :
                    'This scorecard is currently being reviewed by your manager.' }}
            </v-alert>

            <!-- Scorecard Content - UPDATED with signature handlers -->
            <ScorecardPerspectiveCards :perspectives="perspectives" :scorecard="scorecard" :open-panels="openPanels"
                :get-perspective-validation-state="getPerspectiveValidationState"
                :get-goal-validation-state="getGoalValidationState" :get-available-goal-weight="getAvailableGoalWeight"
                :distribute-goal-weights-equally="distributeGoalWeightsEqually" :read-only="isReadOnly"
                :all-weights-valid="allWeightsValid" :can-sign-as-employee="canSignAsEmployee"
                :can-sign-as-manager="canSignAsManager" :signature-action="signatureAction"
                @open-add-goals="openAddGoalsObjectives" @open-edit-goal="openEditGoal" @delete-goal="handleDeleteGoal"
                @delete-objective="handleDeleteObjective" @employee-initial-sign="onEmployeeInitialSign"
                @manager-sign="onManagerSign" @employee-final-sign="onEmployeeFinalSign" />

            <!-- Dialogs -->
            <UnifiedGoalObjectiveDialog v-model="dialogs.unifiedGoalObjective" :mode="dialogState.mode"
                :scorecard="scorecard" :selected-perspective="dialogState.selectedPerspective"
                :selected-goal="dialogState.selectedGoal" :tenant-config="tenantConfig" :is-saving="isSaving"
                :get-available-goal-weight="getAvailableGoalWeight"
                :get-available-objective-weight="getAvailableObjectiveWeight" :find-goal-by-id="findGoalById"
                @reload="reloadScorecard" />

            <PerspectiveWeightsDialog v-model="dialogs.perspectiveWeights" :scorecard="scorecard"
                :perspectives="perspectives" :is-saving="isSaving" @reload="reloadScorecard" />

            <FullScorecardUploadDialog v-model="dialogs.fullScorecardUpload" :scorecard="scorecard"
                :perspectives="perspectives" :tenant-config="tenantConfig" :is-saving="isSaving"
                @reload="reloadScorecard" />

            <ChangeComparisonDialog v-model="dialogs.changeComparison" :scorecard-id="scorecard.id"
                :get-change-comparison="getChangeComparison" />
        </div>

        <!-- Snackbar -->
        <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <v-btn variant="text" @click="snackbar.show = false">Close</v-btn>
            </template>
        </v-snackbar>
    </div>
</template>

<style scoped>
.gap-2 {
    gap: 8px;
}

.h-100 {
    height: 100%;
}
</style>