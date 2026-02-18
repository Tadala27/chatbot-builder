<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useScorecardState } from '@/composables/useScorecardState'

const props = defineProps<{
    modelValue: boolean
    scorecard: any
    perspectives: any[]
    isSaving: boolean
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
    'reload': []
}>()

// Use scorecard state for operations
const { updatePerspectiveWeights, showSnackbar } = useScorecardState()

// ========================================================================
// STATE
// ========================================================================

// Form state - local copy of perspective weights
const weightForm = ref<Array<{
    id: number | string
    name: string
    weight: number
}>>([])

// ========================================================================
// COMPUTED
// ========================================================================

/**
 * Total weight of all perspectives
 */
const totalWeight = computed(() => {
    return weightForm.value.reduce((sum, p) => sum + Number(p.weight), 0)
})

/**
 * Check if weights are valid (sum to 100%)
 */
const isValid = computed(() => {
    return Math.abs(totalWeight.value - 100) < 0.01
})

/**
 * Validation message
 */
const validationMessage = computed(() => {
    if (isValid.value) {
        return 'Total: 100% ✓ Valid'
    }
    if (totalWeight.value > 100) {
        return `${(totalWeight.value - 100).toFixed(2)}% over limit`
    }
    return `${(100 - totalWeight.value).toFixed(2)}% remaining`
})

// ========================================================================
// WATCHERS
// ========================================================================

/**
 * Initialize form when dialog opens
 */
watch(() => props.modelValue, (val) => {
    if (val) {
        weightForm.value = props.perspectives.map(p => ({
            id: p.id,
            name: p.perspective_name,
            weight: Number(p.weight)
        }))
    }
})

// ========================================================================
// METHODS
// ========================================================================

/**
 * Distribute weights equally across all perspectives
 */
const distributeEqually = () => {
    const count = weightForm.value.length
    const equalWeight = 100 / count

    weightForm.value.forEach((p, index) => {
        if (index === 0) {
            // First item gets the remainder to ensure exact 100%
            p.weight = Number((100 - (equalWeight * (count - 1))).toFixed(2))
        } else {
            p.weight = Number(equalWeight.toFixed(2))
        }
    })
}

/**
 * Adjust weight by increment
 */
const adjustWeight = (perspective: any, delta: number) => {
    const newWeight = Number(perspective.weight) + delta
    if (newWeight >= 0 && newWeight <= 100) {
        perspective.weight = Number(newWeight.toFixed(2))
    }
}

/**
 * Get color for visual distribution bar
 */
function getPerspectiveBarColor(index: number): string {
    const colors = ['primary', 'secondary', 'warning', 'error', 'success', 'info']
    return colors[index % colors.length]
}

/**
 * Save weights to backend
 */
const saveWeights = async () => {
    if (!isValid.value) {
        showSnackbar('Total weight must equal 100%', 'error')
        return
    }

    // Check for conflicts with existing goals
    const changes = weightForm.value.map(item => {
        const perspective = props.perspectives.find(p => p.id === item.id)
        if (!perspective) return null

        const goalTotal = perspective.goals?.reduce(
            (sum: number, g: any) => sum + Number(g.weight || 0),
            0
        ) || 0

        const willConflict = perspective.goals?.length > 0 &&
            Math.abs(goalTotal - item.weight) > 0.01

        return {
            perspectiveName: item.name,
            newWeight: item.weight,
            goalTotal,
            willConflict,
            affectedGoals: perspective.goals?.length || 0
        }
    }).filter(c => c?.willConflict)

    // Prepare weights for API
    const weights = weightForm.value.map(item => ({
        id: item.id,
        weight: Number(item.weight)
    }))

    // Call composable method
    const success = await updatePerspectiveWeights(weights)

    if (success) {
        emit('reload')
        emit('update:modelValue', false)

        if (changes.length > 0) {
            showSnackbar(
                'Weights updated. Please fix goal weights to match.',
                'warning',
                6000
            )
        } else {
            showSnackbar('Weights updated successfully', 'success')
        }
    }
}

/**
 * Close dialog
 */
const close = () => {
    emit('update:modelValue', false)
}
</script>

<template>
    <v-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)" max-width="700"
        persistent>
        <v-card>
            <!-- Header -->
            <v-card-title class="d-flex justify-space-between align-center bg-primary text-white">
                <div class="d-flex align-center">
                    <v-icon start>$scaleUnbalanced</v-icon>
                    <span>Edit Perspective Weights</span>
                </div>
                <v-btn icon="$close" variant="text" @click="close" :disabled="isSaving" color="white" />
            </v-card-title>

            <v-divider />

            <v-card-text class="pt-4">
                <!-- Total Weight Summary -->
                <v-alert :type="isValid ? 'success' : 'error'" variant="tonal" density="compact" class="mb-4">
                    <div class="d-flex align-center justify-space-between">
                        <div>
                            <strong>Total Weight: {{ totalWeight.toFixed(2) }}%</strong>
                            <span class="ml-2" :class="isValid ? 'text-success' : 'text-error'">
                                {{ validationMessage }}
                            </span>
                        </div>
                        <v-btn size="small" variant="flat" color="secondary" @click="distributeEqually">
                            <v-icon start>$autoFix</v-icon>
                            Distribute Equally
                        </v-btn>
                    </div>
                </v-alert>

                <!-- Weight Input Rows -->
                <v-row v-for="(item, index) in weightForm" :key="item.id" class="mb-2">
                    <v-col cols="12" md="6">
                        <v-text-field :model-value="item.name" readonly label="Perspective" variant="outlined"
                            density="comfortable" hide-details>
                            <template #prepend-inner>
                                <v-icon size="small" :color="getPerspectiveBarColor(index)">
                                    $bullseyeArrow
                                </v-icon>
                            </template>
                        </v-text-field>
                    </v-col>
                    <v-col cols="12" md="6">
                        <v-number-input v-model="item.weight" label="Weight %" suffix="%" variant="outlined"
                            density="comfortable" :min="0" :max="100" :step="0.01" :precision="2"
                            control-variant="split" hide-details />
                    </v-col>
                </v-row>

                <!-- Visual Weight Distribution -->
                <v-divider class="my-4" />
                <div class="mb-2 text-subtitle-2">Visual Distribution</div>

                <!-- Stacked Progress Bar -->
                <div class="position-relative mb-3">
                    <v-progress-linear :model-value="100" height="18" rounded class="weight-progress-bar">
                        <template #default>
                            <div class="d-flex w-100 h-100">
                                <div v-for="(item, index) in weightForm" :key="item.id" :style="{
                                    width: `${item.weight}%`,
                                    opacity: item.weight > 0 ? 1 : 0.3,
                                    transition: 'all 0.3s ease'
                                }"
                                    :class="`bg-${getPerspectiveBarColor(index)} striped-bg d-flex align-center justify-center`">
                                    <span v-if="item.weight > 5"
                                        class="weight-bar-label text-white font-weight-medium text-caption">
                                        {{ item.weight }}%
                                    </span>
                                </div>
                            </div>
                        </template>
                    </v-progress-linear>
                </div>

                <!-- Legend -->
                <div class="d-flex flex-wrap gap-2">
                    <v-chip v-for="(item, index) in weightForm" :key="item.id" size="x-small"
                        :color="getPerspectiveBarColor(index)" variant="flat">
                        {{ item.name }}: {{ item.weight }}%
                    </v-chip>
                </div>
            </v-card-text>

            <v-divider />

            <!-- Actions -->
            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="close" :disabled="isSaving">
                    Cancel
                </v-btn>
                <v-btn color="primary" variant="flat" @click="saveWeights" :loading="isSaving" :disabled="!isValid">
                    <v-icon start>$contentSave</v-icon>
                    Save Weights
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.gap-2 {
    gap: 8px;
}

.striped-bg {
    background-image: repeating-linear-gradient(45deg,
            rgba(255, 255, 255, 0.25),
            rgba(255, 255, 255, 0.25) 10px,
            rgba(255, 255, 255, 0.15) 10px,
            rgba(255, 255, 255, 0.15) 20px);
}

.weight-progress-bar {
    overflow: hidden;
}

.weight-bar-label {
    font-size: 0.75rem;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}
</style>