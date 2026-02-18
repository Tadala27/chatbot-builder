<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
    perspectives: any[]
    scorecard: any
    openPanels: number[]
    getPerspectiveValidationState: (perspective: any) => any
    getGoalValidationState: (goal: any) => any
    getAvailableGoalWeight: (perspectiveId: number | string) => number
    distributeGoalWeightsEqually: (perspectiveId: number | string) => boolean
    readOnly?: boolean
    // NEW: Signature-related props
    allWeightsValid?: boolean
    canSignAsEmployee?: boolean
    canSignAsManager?: boolean
    signatureAction?: 'employee_initial' | 'manager' | 'employee_final' | null
}>()

const emit = defineEmits<{
    'open-add-goals': [perspective: any]
    'open-edit-goal': [goal: any]
    'delete-goal': [goalId: number]
    'delete-objective': [objectiveId: number]
    'employee-initial-sign': []
    'manager-sign': []
    'employee-final-sign': []
}>()

const getPerspectiveColor = (code: string) => {
    const colors: Record<string, string> = {
        'FIN': 'success',
        'CUST': 'info',
        'INT': 'warning',
        'L&G': 'purple'
    }
    return colors[code] || 'primary'
}

const allTargetsEqual = (targets: any[]) => {
    if (!targets || targets.length === 0) return true
    const firstValue = targets[0].target_value
    return targets.every((t: any) => Math.abs(Number(t.target_value) - Number(firstValue)) < 0.01)
}

const formatTarget = (value: number | null, type: string) => {
    if (value === null || value === undefined) return 'N/A'
    const numValue = Number(value)

    switch (type) {
        case 'currency':
            return `K${numValue.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`
        case 'percentage':
            return `${numValue}%`
        case 'boolean':
            return numValue ? 'Yes' : 'No'
        default:
            return numValue.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
    }
}

const getThresholdColor = (thresholdName: string) => {
    const name = thresholdName.toLowerCase()
    if (name.includes('stretch') || name.includes('excellence')) return 'success'
    if (name.includes('standard') || name.includes('target')) return 'primary'
    if (name.includes('threshold') || name.includes('minimum')) return 'warning'
    return 'default'
}

// Status configuration
const statusConfig = computed(() => {
    const status = props.scorecard?.status || 'draft'
    const configs: Record<string, any> = {
        draft: { color: 'warning', icon: '$pencil', text: 'Draft' },
        submitted: { color: 'info', icon: '$sendVariant', text: 'Submitted' },
        manager_review: { color: 'primary', icon: '$accountSupervisor', text: 'Manager Review' },
        pending_employee_acceptance: { color: 'orange', icon: '$alert', text: 'Pending Review' },
        approved: { color: 'success', icon: '$checkCircle', text: 'Approved' }
    }
    return configs[status] || configs.draft
})

// Show signature indicators
const showSignatures = computed(() => {
    return props.scorecard?.is_contract_signed_by_holder || props.scorecard?.is_contract_signed_by_manager
})

// NEW: Sign button configuration
const signButtonConfig = computed(() => {
    if (!props.signatureAction) return null

    const configs = {
        employee_initial: {
            text: 'Sign Contract',
            color: 'success',
            icon: '$fileSign',
            disabled: !props.allWeightsValid,
            tooltip: !props.allWeightsValid ? 'Please fix all weight validation errors before signing' : ''
        },
        manager: {
            text: 'Sign Contract',
            color: 'success',
            icon: '$checkCircle',
            disabled: false,
            tooltip: ''
        },
        employee_final: {
            text: 'Accept & Sign',
            color: 'success',
            icon: '$checkCircle',
            disabled: false,
            tooltip: ''
        }
    }

    return configs[props.signatureAction] || null
})

// Handle sign button click
const handleSignClick = () => {
    if (!props.signatureAction) return

    if (props.signatureAction === 'employee_initial') {
        emit('employee-initial-sign')
    } else if (props.signatureAction === 'manager') {
        emit('manager-sign')
    } else if (props.signatureAction === 'employee_final') {
        emit('employee-final-sign')
    }
}
</script>

<template>
    <v-card variant="outlined">
        <v-card-title class="bg-primary text-white d-flex align-center">
            <v-icon class="mr-2">$clipboardTextOutline</v-icon>
            <span>Goals & Objectives Detail</span>
            <v-spacer />

            <!-- Sign Button (shown when available) -->
            <v-btn v-if="signButtonConfig" :color="signButtonConfig.color" variant="flat"
                :prepend-icon="signButtonConfig.icon" @click="handleSignClick" :disabled="signButtonConfig.disabled"
                class="mr-2">
                {{ signButtonConfig.text }}
                <v-tooltip v-if="signButtonConfig.tooltip" activator="parent" location="bottom">
                    {{ signButtonConfig.tooltip }}
                </v-tooltip>
            </v-btn>

            <!-- Status Chip -->
            <v-chip size="small" :color="statusConfig.color" variant="flat" :prepend-icon="statusConfig.icon">
                {{ statusConfig.text }}
            </v-chip>

            <!-- Signature Indicators -->
            <div v-if="showSignatures" class="d-flex gap-2 ml-2">
                <v-tooltip text="Employee signed" location="bottom">
                    <template #activator="{ props: tooltipProps }">
                        <v-chip v-if="scorecard?.is_contract_signed_by_holder" v-bind="tooltipProps" size="small"
                            color="white" variant="tonal" text-color="success">
                            <v-icon start size="small">$accountCheck</v-icon>
                            Employee
                        </v-chip>
                    </template>
                </v-tooltip>

                <v-tooltip text="Manager signed" location="bottom">
                    <template #activator="{ props: tooltipProps }">
                        <v-chip v-if="scorecard?.is_contract_signed_by_manager" v-bind="tooltipProps" size="small"
                            color="white" variant="tonal" text-color="success">
                            <v-icon start size="small">$accountMultipleCheck</v-icon>
                            Manager
                        </v-chip>
                    </template>
                </v-tooltip>
            </div>

            <!-- Read Only Chip -->
            <v-chip v-if="readOnly" size="small" color="white" variant="tonal" text-color="primary" class="ml-2">
                <v-icon start size="small">$eye</v-icon>
                Read Only
            </v-chip>
        </v-card-title>

        <v-card-text class="pa-0">
            <v-expansion-panels :model-value="openPanels" variant="accordion" multiple>
                <v-expansion-panel v-for="(perspective, pIndex) in perspectives" :key="perspective.id" :value="pIndex">
                    <!-- Perspective Title with Validation -->
                    <v-expansion-panel-title
                        :class="{ 'bg-error-lighten-5': !getPerspectiveValidationState(perspective).valid }">
                        <div class="d-flex align-center justify-space-between w-100 pr-4">
                            <div class="d-flex align-center gap-3">
                                <v-avatar :color="getPerspectiveColor(perspective.perspective_id)" variant="tonal"
                                    size="40">
                                    <v-icon>$bullseyeArrow</v-icon>
                                </v-avatar>
                                <div>
                                    <div class="d-flex align-center gap-2">
                                        <strong>{{ perspective.perspective_name }}</strong>
                                        <v-tooltip :text="getPerspectiveValidationState(perspective).message">
                                            <template #activator="{ props: tooltipProps }">
                                                <v-icon v-bind="tooltipProps"
                                                    :color="getPerspectiveValidationState(perspective).color"
                                                    size="small">
                                                    {{ getPerspectiveValidationState(perspective).icon }}
                                                </v-icon>
                                            </template>
                                        </v-tooltip>
                                    </div>
                                    <div class="text-caption text-medium-emphasis">
                                        Weight: {{ perspective.weight }}% |
                                        {{ perspective.goals?.length || 0 }} goals |
                                        Score: {{ perspective.score || 0 }}%
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex align-center gap-2">
                                <v-chip v-if="perspective.goals?.length > 0" size="small"
                                    :color="getPerspectiveValidationState(perspective).color" variant="tonal">
                                    Goals: {{ getPerspectiveValidationState(perspective).goalTotal }}%
                                </v-chip>
                                <v-chip size="small" :color="getPerspectiveColor(perspective.perspective_id)">
                                    {{ perspective.weight }}%
                                </v-chip>
                            </div>
                        </div>
                    </v-expansion-panel-title>

                    <v-expansion-panel-text>
                        <!-- Validation Alert - Only show if not read-only -->
                        <v-alert v-if="!getPerspectiveValidationState(perspective).valid && !readOnly" type="error"
                            variant="tonal" density="compact" class="mb-3">
                            <div class="d-flex justify-space-between align-center">
                                <span class="text-caption">
                                    Goals total {{ getPerspectiveValidationState(perspective).goalTotal }}%
                                    of {{ perspective.weight }}%
                                </span>
                                <v-btn size="x-small" variant="text" color="error"
                                    @click="distributeGoalWeightsEqually(perspective.id)">
                                    Auto-Fix
                                </v-btn>
                            </div>
                        </v-alert>

                        <!-- Add Goals Button - Only show if not read-only -->
                        <div v-if="!readOnly" class="d-flex justify-space-between align-center mb-3">
                            <div class="text-subtitle-2">
                                Goals & Objectives
                                <v-chip v-if="getAvailableGoalWeight(perspective.id) > 0" size="x-small" color="info"
                                    variant="tonal" class="ml-2">
                                    {{ getAvailableGoalWeight(perspective.id) }}% available
                                </v-chip>
                            </div>
                            <v-btn color="primary" size="small" variant="tonal"
                                @click="emit('open-add-goals', perspective)">
                                <v-icon start>$plus</v-icon>
                                Add Goals
                            </v-btn>
                        </div>

                        <!-- Goals Table -->
                        <div v-for="goal in perspective.goals" :key="goal.id" class="mb-4">
                            <!-- Goal Header with Validation -->
                            <div class="d-flex align-center justify-space-between pa-3 rounded mb-2"
                                :class="getGoalValidationState(goal).valid ? 'bg-grey-lighten-4' : 'bg-error-lighten-5'">
                                <div>
                                    <div class="d-flex align-center gap-2">
                                        <h6 class="text-subtitle-1 font-weight-bold">{{ goal.description }}</h6>
                                        <v-tooltip v-if="!getGoalValidationState(goal).valid"
                                            text="Objective weights don't match goal weight">
                                            <template #activator="{ props: tooltipProps }">
                                                <v-icon v-bind="tooltipProps" color="error" size="small">
                                                    $alertCircle
                                                </v-icon>
                                            </template>
                                        </v-tooltip>
                                    </div>
                                    <p class="text-caption text-medium-emphasis mb-0">
                                        Weight: {{ goal.weight }}% |
                                        {{ goal.objectives?.length || 0 }} Objectives
                                        <span v-if="!getGoalValidationState(goal).valid" class="text-error ml-1">
                                            | Obj Total: {{ getGoalValidationState(goal).objTotal }}% (should be {{
                                                goal.weight }}%)
                                        </span>
                                    </p>
                                </div>
                                <!-- Action buttons - Only show if not read-only -->
                                <div v-if="!readOnly" class="d-flex gap-2">
                                    <v-btn color="primary" size="small" variant="text" icon
                                        @click="emit('open-edit-goal', goal)">
                                        <v-icon>$pencil</v-icon>
                                    </v-btn>
                                    <v-btn color="error" size="small" variant="text" icon
                                        @click="emit('delete-goal', goal.id)">
                                        <v-icon>$trashCan</v-icon>
                                    </v-btn>
                                </div>
                                <!-- Read-only indicator -->
                                <v-chip v-else size="small" color="default" variant="text">
                                    <v-icon size="small">$eye</v-icon>
                                </v-chip>
                            </div>

                            <!-- Objectives Table -->
                            <v-table v-if="goal.objectives?.length" density="compact" class="border rounded"
                                style="table-layout: fixed;">
                                <colgroup>
                                    <col style="width: 40%;" />
                                    <col style="width: 12%;" />
                                    <col style="width: 16%;" />
                                    <col style="width: 8%;" />
                                    <col style="width: 8%;" />
                                    <col style="width: 8%;" />
                                </colgroup>

                                <thead>
                                    <tr>
                                        <th>Objective</th>
                                        <th class="text-center">Type</th>
                                        <th class="text-center">Target</th>
                                        <th class="text-center">Weight</th>
                                        <th class="text-center">Proof</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>

                                <tbody>
                                    <tr v-for="objective in goal.objectives" :key="objective.id">
                                        <td>
                                            <div>
                                                <p class="font-weight-medium mb-1"
                                                    style="white-space: normal; word-break: break-word;">
                                                    {{ objective.description }}
                                                </p>
                                            </div>
                                        </td>

                                        <td class="text-center">
                                            <v-chip size="small" rounded="sm"
                                                :color="objective.objective_type === 'continuous' ? 'info' : 'warning'"
                                                class="text-capitalize">
                                                {{ objective.objective_type }}
                                                {{ objective.absolute_value ? `- ${objective.absolute_value}` : '' }}
                                            </v-chip>
                                        </td>

                                        <td class="text-center">
                                            <div v-if="objective.targets && objective.targets.length">
                                                <span v-if="allTargetsEqual(objective.targets)">
                                                    {{
                                                        formatTarget(
                                                            objective.targets[0].target_value,
                                                            objective.target_type
                                                        )
                                                    }}
                                                </span>
                                                <v-menu v-else>
                                                    <template #activator="{ props: menuProps }">
                                                        <v-chip v-bind="menuProps" size="small" color="info"
                                                            append-icon="$chevronDown">
                                                            {{ objective.targets.length }} thresholds
                                                        </v-chip>
                                                    </template>
                                                    <v-card min-width="200">
                                                        <v-list density="compact">
                                                            <v-list-subheader>Target Thresholds</v-list-subheader>
                                                            <v-list-item v-for="target in objective.targets"
                                                                :key="target.id">
                                                                <template #prepend>
                                                                    <v-icon size="small"
                                                                        :color="getThresholdColor(target.threshold_name)">
                                                                        $bullseyeArrow
                                                                    </v-icon>
                                                                </template>
                                                                <v-list-item-title class="text-body-2">
                                                                    {{ target.threshold_name }}
                                                                </v-list-item-title>
                                                                <v-list-item-subtitle class="font-weight-medium">
                                                                    {{
                                                                        formatTarget(
                                                                            target.target_value,
                                                                            objective.target_type
                                                                        )
                                                                    }}
                                                                </v-list-item-subtitle>
                                                            </v-list-item>
                                                        </v-list>
                                                    </v-card>
                                                </v-menu>
                                            </div>
                                            <span v-else class="text-medium-emphasis">No targets</span>
                                        </td>

                                        <td class="text-center">
                                            {{ objective.weight }}%
                                        </td>

                                        <td class="text-center">
                                            <div v-if="objective.requires_proof"
                                                class="d-flex align-center justify-center gap-1">
                                                <v-tooltip location="top">
                                                    <template #activator="{ props: tooltipProps }">
                                                        <div v-bind="tooltipProps"
                                                            class="d-flex align-center cursor-pointer text-medium-emphasis">
                                                            <v-icon size="small" color="warning" class="mr-1">
                                                                $fileDocument
                                                            </v-icon>
                                                        </div>
                                                    </template>
                                                    {{ objective.proof_requirements }}
                                                </v-tooltip>
                                            </div>
                                            <span v-else class="text-medium-emphasis">—</span>
                                        </td>

                                        <td class="text-center">
                                            <!-- Delete button only if not read-only -->
                                            <div v-if="!readOnly" class="d-flex align-center justify-center gap-1">
                                                <v-btn icon variant="text" size="x-small" color="error"
                                                    @click="emit('delete-objective', objective.id)">
                                                    <v-icon size="small">$trashCan</v-icon>
                                                </v-btn>
                                            </div>
                                            <span v-else class="text-medium-emphasis">—</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>

                            <v-alert v-else type="info" variant="tonal" density="compact">
                                No objectives added for this goal
                            </v-alert>
                        </div>

                        <v-alert v-if="!perspective.goals?.length" type="info" variant="tonal">
                            No goals added for this perspective.
                        </v-alert>
                    </v-expansion-panel-text>
                </v-expansion-panel>
            </v-expansion-panels>
        </v-card-text>
    </v-card>
</template>

<style scoped>
.gap-2 {
    gap: 8px;
}

.gap-1 {
    gap: 4px;
}

.gap-3 {
    gap: 12px;
}

.border {
    border: 1px solid rgba(0, 0, 0, 0.12);
}

.border-error {
    border-color: rgb(var(--v-theme-error)) !important;
}

.bg-error-lighten-5 {
    background-color: rgba(var(--v-theme-error), 0.05);
}
</style>