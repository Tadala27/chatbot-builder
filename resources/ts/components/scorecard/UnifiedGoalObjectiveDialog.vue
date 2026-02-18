<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import axios from 'axios'
import { useScorecardValidation } from '@/composables/useScorecardValidation'

/* ------------------------------------------------------------------
 * PROPS / EMITS
 * ------------------------------------------------------------------ */

const props = defineProps<{
    modelValue: boolean
    mode: 'add' | 'edit'
    selectedPerspective: any
    selectedGoal: any | null
    tenantConfig: any
    isSaving: boolean
    getAvailableGoalWeight: (perspectiveId: number) => number
    getAvailableObjectiveWeight: (goalId: number) => number
    findGoalById?: (goalId: number) => any
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    'reload': []
    'showSnackbar': [msg: string, color?: string, timeout?: number]
}>()

/* ------------------------------------------------------------------
 * VALIDATION COMPOSABLE
 * ------------------------------------------------------------------ */

const {
    validateObjectiveWeights,
    getAvailableGoalWeight,
    getAvailableObjectiveWeight,
    getPerspectiveValidationState,
    allWeightsValid,
} = useScorecardValidation(
    computed(() => props.selectedPerspective ? [props.selectedPerspective] : []),
    ref(null)
)

/* ------------------------------------------------------------------
 * PERSPECTIVE VALIDATION
 * ------------------------------------------------------------------ */

const perspectiveValidation = computed(() => {
    if (!props.selectedPerspective) return null
    return getPerspectiveValidationState(props.selectedPerspective)
})
const removeObjective = (goalIndex: number, objIndex: number) => {
    form.value.goals[goalIndex].objectives.splice(objIndex, 1)
}

const getRemainingObjectiveWeight = (goal: any, objectiveIndex: number) => {
    const goalWeight = Number(goal.weight)

    // Sum all other objectives except the current one
    const usedByOthers = goal.objectives.reduce((sum, o, i) => {
        if (i === objectiveIndex) return sum
        return sum + Number(o.weight || 0)
    }, 0)

    // Remaining weight for this objective
    return Math.max(0, goalWeight - usedByOthers)
}
const getRemainingObjectiveWeightForHint = (goal: any, objectiveIndex: number) => {
    const remaining = getRemainingObjectiveWeight(goal, objectiveIndex)
    const current = Number(goal.objectives[objectiveIndex].weight || 0)
    return Math.max(0, remaining - current)
}

const getObjectiveWeightState = (goal: any) => {
    return validateObjectiveWeights(goal)
}

/* ------------------------------------------------------------------
 * GOAL WEIGHT VALIDATION
 * ------------------------------------------------------------------ */

const goalWeightValidation = computed(() => {
    if (!props.selectedPerspective || isEditingGoal.value || isAddingToExistingGoal.value) {
        return { valid: true, message: '', color: 'success' }
    }

    const total = totalGoalWeight.value
    const perspectiveWeight = perspectiveWeightNumber.value

    if (Math.abs(total - perspectiveWeight) < 0.01) {
        return {
            valid: true,
            message: `Perfect! Goals total ${total}% = Perspective weight ${perspectiveWeight}%`,
            color: 'success'
        }
    }

    if (total < perspectiveWeight) {
        return {
            valid: false,
            message: `Total: ${total}%. Need ${(perspectiveWeight - total).toFixed(2)}% more`,
            color: 'warning'
        }
    }

    return {
        valid: false,
        message: `Total: ${total}%. ${(total - perspectiveWeight).toFixed(2)}% over limit`,
        color: 'error'
    }
})

const isGoalWeightInvalid = computed(() =>
    !goalWeightValidation.value.valid &&
    !isEditingGoal.value &&
    !isAddingToExistingGoal.value
)

/* ------------------------------------------------------------------
 * LOCAL WEIGHTS VALIDATION
 * ------------------------------------------------------------------ */

const allLocalWeightsValid = computed(() => {
    // 1️⃣ Validate goal weights
    if (!goalWeightValidation.value.valid) return false

    // 2️⃣ Validate all objectives weights for each goal
    for (const goal of form.value.goals) {
        const objState = getObjectiveWeightState(goal)
        if (objState.color === 'error') return false
    }

    // ✅ Everything passed
    return true
})

/* ------------------------------------------------------------------
 * FORM STATE
 * ------------------------------------------------------------------ */

const form = ref<{
    goals: Array<{
        id?: number
        description: string
        weight: number
        objectives: Array<{
            id?: number
            description: string
            objective_type: string
            absolute_value: string | null
            target_type: string
            appraisal_behaviour: string
            weight: number
            requires_proof: boolean
            proof_requirements: string
            targets: Array<{
                id?: number
                threshold_config_id: number | null
                threshold_name: string
                target_value: number
            }>
        }>
    }>
}>({
    goals: []
})

const expandedGoals = ref<number[]>([])
// Dialog title

const dialogTitle = computed(() => {
    if (props.mode === 'edit') {
        return 'Edit Goal & Objectives'
    } else {
        return 'Add Goals & Objectives'
    }
})
/* ------------------------------------------------------------------
 * MATRIX TEMPLATE CONFIG
 * ------------------------------------------------------------------ */

const requiresThresholds = computed(() =>
    props.tenantConfig?.matrix_template?.requires_thresholds ?? false
)

const thresholdConfigs = computed(() =>
    props.tenantConfig?.matrix_template?.threshold_configs || []
)

/* ------------------------------------------------------------------
 * MODE FLAGS
 * ------------------------------------------------------------------ */

const isEditingGoal = computed(() =>
    props.mode === 'edit' && !!props.selectedGoal?.id
)

const isAddingToExistingGoal = computed(() =>
    props.mode === 'add' && !!props.selectedGoal?.id
)

/* ------------------------------------------------------------------
 * WEIGHT HELPERS
 * ------------------------------------------------------------------ */

const perspectiveWeightNumber = computed(() =>
    Number(props.selectedPerspective?.weight ?? 0)
)

const totalGoalWeight = computed(() =>
    form.value.goals.reduce((sum, g) => sum + Number(g.weight || 0), 0)
)

const remainingPerspectiveWeight = computed(() =>
    Math.max(0, perspectiveWeightNumber.value - totalGoalWeight.value)
)

const getGoalMaxWeight = (goalIndex: number) => {
    const current = Number(form.value.goals[goalIndex]?.weight || 0)
    return Number((remainingPerspectiveWeight.value + current).toFixed(2))
}


/* ------------------------------------------------------------------
 * OBJECTIVE NORMALIZATION
 * ------------------------------------------------------------------ */

function normalizeObjective(o: any = {}) {
    return {
        id: o.id,
        description: o.description ?? '',
        weight: Number(o.weight ?? 0),
        objective_type: o.objective_type ?? 'continuous',
        absolute_value: o.absolute_value ?? null,
        target_type: o.target_type ?? 'numeric',
        appraisal_behaviour: o.appraisal_behaviour ?? 'higher_better',
        requires_proof: Boolean(o.requires_proof),
        proof_requirements: o.proof_requirements ?? '',
        targets: mapTargetsToThresholdConfigs(o.targets || [], thresholdConfigs.value),
    }
}

function mapTargetsToThresholdConfigs(existingTargets: any[], configs: any[]) {
    return configs.map((config) => {
        const existing = existingTargets.find(
            t => t.threshold_config_id === config.id
        )

        return {
            threshold_config_id: config.id,
            threshold_name: config.threshold_name,
            description: config.description,
            target_value: Number(existing?.target_value ?? 0),
        }
    })
}

function getDefaultTargets() {
    if (requiresThresholds.value) {
        return thresholdConfigs.value.map((t: any) => ({
            threshold_config_id: t.id,
            threshold_name: t.threshold_name,
            target_value: Number(t.target_value ?? 0),
        }))
    }

    return [{
        threshold_config_id: null,
        threshold_name: 'Target',
        target_value: 0,
    }]
}

/* ------------------------------------------------------------------
 * ADD / REMOVE
 * ------------------------------------------------------------------ */

const addGoal = () => {
    const availableWeight = props.getAvailableGoalWeight(props.selectedPerspective.id)

    form.value.goals.unshift({
        description: '',
        weight: Number(availableWeight),
        objectives: [{
            description: '',
            objective_type: 'continuous',
            absolute_value: null,
            target_type: 'numeric',
            appraisal_behaviour: 'higher_better',
            weight: 0,
            requires_proof: false,
            proof_requirements: '',
            targets: getDefaultTargets(),
        }]
    })
}

const removeGoal = (index: number) => {
    form.value.goals.splice(index, 1)
}

const addObjective = (goalIndex: number) => {
    const goal = form.value.goals[goalIndex]

    const available = goal.id
        ? getAvailableObjectiveWeight(goal.id)
        : Number(goal.weight) -
        goal.objectives.reduce((s, o) => s + Number(o.weight || 0), 0)

    if (available <= 0) {
        emit('showSnackbar', 'No remaining weight for objectives', 'warning')
        return
    }

    goal.objectives.unshift({
        description: '',
        objective_type: 'continuous',
        absolute_value: null,
        target_type: 'numeric',
        appraisal_behaviour: 'higher_better',
        weight: Number(available.toFixed(2)),
        requires_proof: false,
        proof_requirements: '',
        targets: getDefaultTargets(),
    })
}



/* ------------------------------------------------------------------
 * UI HELPERS
 * ------------------------------------------------------------------ */

function getThresholdColor(thresholdName: string): string {
    const name = thresholdName.toLowerCase()
    if (name.includes('stretch') || name.includes('excellence')) return 'success'
    if (name.includes('standard') || name.includes('target')) return 'primary'
    if (name.includes('threshold') || name.includes('minimum')) return 'warning'
    return 'default'
}

function getFieldMd(objective: any, field: string) {
    if (requiresThresholds.value) {
        if (objective.objective_type === 'continuous') return 6
        if (objective.objective_type === 'absolute') {
            if (field === 'absolute_value' || field === 'weight') return 6
            return 4
        }
    }
    return objective.objective_type === 'absolute' ? 4 : 6
}

/* ------------------------------------------------------------------
 * WATCHERS
 * ------------------------------------------------------------------ */

watch(() => form.value.goals.length, (len) => {
    expandedGoals.value = Array.from({ length: len }, (_, i) => i)
}, { immediate: true })

watch(() => props.modelValue, (open) => {
    if (!open) return

    if (props.mode === 'edit' && props.selectedGoal) {
        form.value.goals = [{
            id: props.selectedGoal.id,
            description: props.selectedGoal.description,
            weight: Number(props.selectedGoal.weight),
            objectives: (props.selectedGoal.objectives || []).map(normalizeObjective),
        }]
        return
    }

    if (props.mode === 'add' && props.selectedPerspective) {
        const availableWeight = props.getAvailableGoalWeight(props.selectedPerspective.id)

        const newGoal = {
            description: '',
            weight: Number(availableWeight),
            objectives: []
        }

        form.value.goals.unshift(newGoal)

        newGoal.objectives.unshift({
            description: '',
            objective_type: 'continuous',
            absolute_value: null,
            target_type: 'numeric',
            appraisal_behaviour: 'higher_better',
            weight: Number(availableWeight ?? 0),
            requires_proof: false,
            proof_requirements: '',
            targets: getDefaultTargets(),
        })
    }
}, { immediate: true })

/* ------------------------------------------------------------------
 * SAVE
 * ------------------------------------------------------------------ */

const save = async () => {
    if (!form.value.goals.length) {
        emit('showSnackbar', 'Please add at least one goal', 'error')
        return
    }

    try {
        const payload = {
            mode: props.mode,
            scorecardId: props.selectedGoal?.perspective?.scorecard?.id,
            perspectiveId: props.selectedPerspective?.id,
            goals: form.value.goals.map(goal => ({
                id: goal.id,
                description: goal.description,
                weight: Number(goal.weight),
                objectives: goal.objectives.map(obj => ({
                    ...obj,
                    weight: Number(obj.weight),
                    targets: obj.targets.map(t => ({
                        ...t,
                        target_value: Number(t.target_value),
                    })),
                })),
            })),
        }

        await axios.post('/api/scorecards/goals-objectives/save', payload)

        emit('reload')
        emit('update:modelValue', false)
        emit('showSnackbar', 'Saved successfully', 'success')
    } catch (e: any) {
        emit(
            'showSnackbar',
            e.response?.data?.message || 'Failed to save',
            'error'
        )
    }
}
</script>

<template>
    <v-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)" max-width="900"
        scrollable persistent>
        <v-card>
            <v-card-title class="d-flex justify-space-between align-center">
                <span>{{ dialogTitle }}</span>
                <v-btn icon="$close" variant="text" @click="emit('update:modelValue', false)" :disabled="isSaving" />
            </v-card-title>

            <v-divider />

            <v-container fluid class="pa-0" v-if="!isEditingGoal && !isAddingToExistingGoal && perspectiveValidation">
                <v-row class="ma-0">
                    <v-col cols="12" class="pa-0">
                        <v-alert :type="perspectiveValidation.color" variant="tonal" density="compact"
                            class="mb-0 rounded-0">
                            <div class="d-flex align-center justify-space-between w-100">
                                <strong>{{ perspectiveValidation.message }}</strong>

                                <v-btn variant="flat" color="primary" size="small" @click="addGoal"
                                    :disabled="perspectiveValidation.goalTotal >= props.selectedPerspective.weight">
                                    <v-icon start>$plus</v-icon>
                                    Add Goal
                                </v-btn>
                            </div>
                        </v-alert>
                    </v-col>
                </v-row>
            </v-container>

            <v-divider />

            <v-card-text style="max-height: 70vh;">
                <!-- Goals Loop -->
                <v-expansion-panels v-model="expandedGoals" variant="accordion" multiple>
                    <v-expansion-panel v-for="(goal, goalIndex) in form.goals" :key="goalIndex">
                        <v-expansion-panel-title>
                            <div class="d-flex align-center justify-space-between w-100 pr-4">
                                <div>
                                    <strong>{{ goal.id ? 'Goal' : `New Goal ${goalIndex + 1}` }}:</strong>
                                    <span class="ml-2">{{ goal.description || '(Untitled)' }}</span>
                                </div>
                                <div class="d-flex align-center gap-2">
                                    <v-chip size="small" color="primary">{{ goal.weight }}%</v-chip>
                                    <v-chip size="small" color="info">{{ goal.objectives?.length || 0 }} obj</v-chip>
                                    <v-btn v-if="!goal.id && form.goals.length > 1" icon size="x-small" variant="text"
                                        color="error" @click.stop="removeGoal(goalIndex)">
                                        <v-icon>$close</v-icon>
                                    </v-btn>
                                </div>
                            </div>
                        </v-expansion-panel-title>

                        <v-expansion-panel-text>
                            <!-- Goal Details -->
                            <v-row class="mb-4">
                                <v-col cols="12" md="8">
                                    <v-text-field v-model="goal.description" label="Goal Description *"
                                        variant="outlined" density="compact" />
                                </v-col>
                                <v-col cols="12" md="4">
                                    <v-number-input v-model="goal.weight" type="number" label="Weight %" suffix="%"
                                        variant="outlined" density="compact" :min="0" :max="getGoalMaxWeight(goalIndex)"
                                        :step="0.01" :precision="2" control-variant="split" :error="isGoalWeightInvalid"
                                        :error-messages="isGoalWeightInvalid ? goalWeightValidation.message : []" :hint="!isGoalWeightInvalid
                                            ? `Remaining: ${remainingPerspectiveWeight.toFixed(2)}%`
                                            : ''
                                            " persistent-hint />
                                </v-col>
                            </v-row>

                            <v-divider class="mb-4" />

                            <!-- Objectives Section -->
                            <div class="mb-3 d-flex justify-space-between align-center">
                                <h6 class="text-subtitle-2">Objectives ({{ goal.objectives?.length || 0 }})</h6>
                                <v-btn size="small" color="primary" variant="tonal" @click="addObjective(goalIndex)">
                                    <v-icon start>$plus</v-icon>Add Objective
                                </v-btn>
                            </div>

                            <!-- Objectives List -->
                            <v-card v-for="(objective, objIndex) in goal.objectives" :key="objIndex" variant="outlined"
                                class="mb-3">
                                <v-card-title class="d-flex justify-space-between align-center py-2 bg-grey-lighten-5">
                                    <span class="text-subtitle-2">Objective {{ objIndex + 1 }}</span>
                                    <v-btn icon size="x-small" variant="text" color="error"
                                        @click="removeObjective(goalIndex, objIndex)">
                                        <v-icon>$close</v-icon>
                                    </v-btn>
                                </v-card-title>
                                <v-card-text>
                                    <v-row class="mt-2">
                                        <v-col cols="8">
                                            <v-text-field v-model="objective.description"
                                                label="Objective Description *" variant="outlined" density="compact" />
                                        </v-col>
                                        <v-col cols="4">
                                            <v-switch v-model="objective.requires_proof" label="Proof Required"
                                                color="warning" density="compact" hide-details />
                                        </v-col>

                                        <v-col cols="12" :md="getFieldMd(objective, 'objective_type')">
                                            <v-select v-model="objective.objective_type" :items="[
                                                { title: 'Continuous', value: 'continuous' },
                                                { title: 'Absolute', value: 'absolute' }
                                            ]" label="Objective Type" variant="outlined" density="compact" />
                                        </v-col>

                                        <v-col cols="12" :md="getFieldMd(objective, 'target_type')">
                                            <v-select v-model="objective.target_type" :items="[
                                                { title: 'Numeric', value: 'numeric' },
                                                { title: 'Percentage', value: 'percentage' },
                                                { title: 'Currency', value: 'currency' },
                                                { title: 'Yes/No', value: 'boolean' }
                                            ]" label="Target Type" variant="outlined" density="compact" />
                                        </v-col>

                                        <v-col cols="12" :md="getFieldMd(objective, 'appraisal_behaviour')">
                                            <v-select v-model="objective.appraisal_behaviour" :items="[
                                                { title: 'Higher is Better ↑', value: 'higher_better' },
                                                { title: 'Higher is Bad ↓', value: 'higher_bad' }
                                            ]" label="Behaviour" variant="outlined" density="compact" />
                                        </v-col>

                                        <v-col cols="12" md="6" v-if="objective.objective_type === 'absolute'">
                                            <v-select v-model="objective.absolute_value" :items="[
                                                { title: 'Quarter 1', value: 'Q1' },
                                                { title: 'Quarter 2', value: 'Q2' },
                                                { title: 'Quarter 3', value: 'Q3' },
                                                { title: 'Quarter 4', value: 'Q4' }
                                            ]" label="Absolute Value" variant="outlined" density="compact" />
                                        </v-col>

                                        <!-- Single Target -->
                                        <v-col v-if="!requiresThresholds" cols="12"
                                            :md="objective.objective_type === 'absolute' ? 4 : 6">
                                            <v-select v-if="objective.target_type === 'boolean'"
                                                v-model="objective.targets[0].target_value" :items="[
                                                    { title: 'Yes', value: 1 },
                                                    { title: 'No', value: 0 }
                                                ]" label="Target Value" variant="outlined" density="compact" />
                                            <v-number-input v-else v-model.number="objective.targets[0].target_value"
                                                label="Target Value" variant="outlined" density="compact" :min="0"
                                                :step="0.01" :precision="2" control-variant="split" hide-details />
                                        </v-col>

                                        <v-col cols="12" :md="getFieldMd(objective, 'weight')">
                                            <v-number-input v-model="objective.weight" type="number" label="Weight %"
                                                suffix="%" variant="outlined" density="compact" :min="0"
                                                :max="getRemainingObjectiveWeight(goal, objIndex)" :step="0.01"
                                                :precision="2" control-variant="split"
                                                :error="getObjectiveWeightState(goal).color === 'error'"
                                                :error-messages="getObjectiveWeightState(goal).color === 'error'
                                                    ? getObjectiveWeightState(goal).message
                                                    : []"
                                                :hint="`Remaining: ${getRemainingObjectiveWeightForHint(goal, objIndex).toFixed(2)}%`"
                                                persistent-hint />
                                        </v-col>
                                        <!-- Proof Requirements -->
                                        <v-col cols="12" v-if="objective.requires_proof">
                                            <v-textarea v-model="objective.proof_requirements"
                                                label="Proof Requirements" variant="outlined" rows="2"
                                                density="compact" />
                                        </v-col>

                                        <!-- Multiple Thresholds -->
                                        <v-col v-if="requiresThresholds" cols="12">
                                            <v-divider class="mb-3" />
                                            <div class="d-flex align-center justify-space-between mb-3">
                                                <h6 class="text-subtitle-2">Target Values</h6>
                                                <v-chip size="x-small" color="info" variant="tonal">
                                                    {{ thresholdConfigs.length }} Thresholds
                                                </v-chip>
                                            </div>
                                            <v-row dense>
                                                <v-col cols="6" md="4"
                                                    v-for="(target, targetIndex) in objective.targets"
                                                    :key="targetIndex">
                                                    <v-select v-if="objective.target_type === 'boolean'"
                                                        v-model="target.target_value" :items="[
                                                            { title: 'Yes', value: 1 },
                                                            { title: 'No', value: 0 }
                                                        ]" :label="target.threshold_name" variant="outlined"
                                                        density="compact">
                                                        <template #prepend-inner>
                                                            <v-icon :color="getThresholdColor(target.threshold_name)"
                                                                size="small">
                                                                $bullseyeArrow
                                                            </v-icon>
                                                        </template>
                                                    </v-select>
                                                    <v-number-input v-else v-model="target.target_value"
                                                        :label="target.threshold_name" :hint="target.description"
                                                        type="number" variant="outlined" density="compact"
                                                        persistent-hint control-variant="split">
                                                        <template #prepend-inner>
                                                            <v-icon :color="getThresholdColor(target.threshold_name)"
                                                                size="small">
                                                                $bullseyeArrow
                                                            </v-icon>
                                                        </template>
                                                    </v-number-input>
                                                </v-col>
                                            </v-row>
                                        </v-col>
                                    </v-row>
                                </v-card-text>
                            </v-card>

                            <v-alert v-if="!goal.objectives?.length" type="info" variant="tonal" density="compact">
                                No objectives added. Click "Add Objective" to create one.
                            </v-alert>
                        </v-expansion-panel-text>
                    </v-expansion-panel>
                </v-expansion-panels>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="emit('update:modelValue', false)" :disabled="isSaving">
                    Cancel
                </v-btn>
                <v-btn color="primary" variant="flat" @click="save" :loading="isSaving"
                    :disabled="!allLocalWeightsValid">
                    <v-icon start>$contentSave</v-icon>
                    Save
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>