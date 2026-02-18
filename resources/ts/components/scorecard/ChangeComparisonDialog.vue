<script setup lang="ts">
import { ref, watch } from 'vue'

const props = defineProps<{
    modelValue: boolean
    scorecardId: number | null
    getChangeComparison: () => Promise<any>
}>()

const emit = defineEmits<{
    'update:modelValue': [value: boolean]
}>()

const isLoading = ref(false)
const comparisonData = ref<any>(null)

const localValue = ref(props.modelValue)

watch(() => props.modelValue, (newVal) => {
    localValue.value = newVal
    if (newVal) {
        loadComparison()
    }
})

watch(localValue, (newVal) => {
    emit('update:modelValue', newVal)
})

const loadComparison = async () => {
    if (!props.scorecardId) return

    isLoading.value = true
    try {
        const data = await props.getChangeComparison()
        comparisonData.value = data
    } catch (error) {
        console.error('Failed to load comparison:', error)
    } finally {
        isLoading.value = false
    }
}

const getChangeTypeColor = (type: string) => {
    if (type === 'added') return 'success'
    if (type === 'removed') return 'error'
    if (type === 'modified') return 'warning'
    return 'default'
}

const getChangeTypeIcon = (type: string) => {
    if (type === 'added') return '$plus'
    if (type === 'removed') return '$minus'
    if (type === 'modified') return '$pencil'
    return '$information'
}

const hasChanges = (changes: any) => {
    if (!changes) return false
    return (changes.added?.length > 0) || (changes.removed?.length > 0) || (changes.modified?.length > 0)
}

const formatValue = (value: any, type?: string) => {
    if (value === null || value === undefined) return 'N/A'
    if (type === 'percentage') return `${value}%`
    if (type === 'currency') return `K${value}`
    return value
}
</script>

<template>
    <v-dialog v-model="localValue" max-width="1200" scrollable>
        <v-card>
            <v-card-title class="bg-info text-white d-flex align-center">
                <v-icon class="mr-2">$compareHorizontal</v-icon>
                <span>Manager's Changes</span>
                <v-spacer />
                <v-btn icon variant="text" @click="localValue = false">
                    <v-icon>$close</v-icon>
                </v-btn>
            </v-card-title>

            <v-divider />

            <!-- Loading State -->
            <v-card-text v-if="isLoading" class="pa-8 text-center">
                <v-progress-circular indeterminate color="primary" size="64" />
                <p class="text-body-1 mt-4 text-medium-emphasis">Loading changes...</p>
            </v-card-text>

            <!-- Comparison Content -->
            <v-card-text v-else-if="comparisonData" class="pa-0" style="max-height: 600px;">
                <!-- Manager Comments -->
                <div v-if="comparisonData.manager_comments" class="pa-4 bg-grey-lighten-5 border-bottom">
                    <div class="text-subtitle-2 font-weight-bold mb-2">Manager's Feedback:</div>
                    <p class="text-body-2 mb-0">{{ comparisonData.manager_comments }}</p>
                </div>

                <!-- Changes Summary -->
                <div class="pa-4 border-bottom">
                    <div class="text-subtitle-1 font-weight-bold mb-3">Summary of Changes</div>
                    <div class="d-flex gap-3">
                        <v-chip v-if="comparisonData.changes.perspectives?.added?.length" size="small" color="success"
                            variant="tonal">
                            <v-icon start size="small">$plus</v-icon>
                            {{ comparisonData.changes.perspectives.added.length }} Added
                        </v-chip>
                        <v-chip v-if="comparisonData.changes.perspectives?.removed?.length" size="small" color="error"
                            variant="tonal">
                            <v-icon start size="small">$minus</v-icon>
                            {{ comparisonData.changes.perspectives.removed.length }} Removed
                        </v-chip>
                        <v-chip v-if="comparisonData.changes.perspectives?.modified?.length" size="small"
                            color="warning" variant="tonal">
                            <v-icon start size="small">$pencil</v-icon>
                            {{ comparisonData.changes.perspectives.modified.length }} Modified
                        </v-chip>
                    </div>
                </div>

                <!-- Detailed Changes -->
                <div class="pa-4">
                    <!-- No changes message -->
                    <v-alert v-if="!comparisonData.has_changes" type="info" variant="tonal" class="mb-4">
                        No changes were made by the manager.
                    </v-alert>

                    <!-- Perspectives Changes -->
                    <div v-for="perspective in comparisonData.changes.perspectives?.modified" :key="perspective.id"
                        class="mb-6">
                        <div class="text-h6 font-weight-bold mb-3">
                            {{ perspective.name }}
                        </div>

                        <!-- Weight Change -->
                        <div v-if="perspective.changes.weight" class="mb-4 pa-3 rounded bg-warning-lighten-5">
                            <div class="d-flex align-center gap-2 mb-2">
                                <v-icon color="warning">$pencil</v-icon>
                                <span class="font-weight-medium">Weight Changed</span>
                            </div>
                            <div class="text-body-2">
                                <span class="text-decoration-line-through text-error">{{
                                    perspective.changes.weight.from }}%</span>
                                <v-icon size="small" class="mx-2">$arrowRight</v-icon>
                                <span class="font-weight-bold text-success">{{ perspective.changes.weight.to }}%</span>
                            </div>
                        </div>

                        <!-- Goal Changes -->
                        <div v-if="perspective.changes.goals">
                            <!-- Added Goals -->
                            <div v-if="perspective.changes.goals.added?.length" class="mb-3">
                                <div class="text-subtitle-2 font-weight-medium text-success mb-2">
                                    <v-icon size="small" class="mr-1">$plus</v-icon>
                                    Added Goals
                                </div>
                                <v-card v-for="goal in perspective.changes.goals.added" :key="goal.id"
                                    variant="outlined" class="mb-2 border-success">
                                    <v-card-text class="pa-3">
                                        <div class="d-flex justify-space-between">
                                            <span class="font-weight-medium">{{ goal.description }}</span>
                                            <v-chip size="small" color="success" variant="flat">
                                                {{ goal.weight }}%
                                            </v-chip>
                                        </div>
                                        <div v-if="goal.objectives_count"
                                            class="text-caption text-medium-emphasis mt-1">
                                            {{ goal.objectives_count }} objectives
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </div>

                            <!-- Removed Goals -->
                            <div v-if="perspective.changes.goals.removed?.length" class="mb-3">
                                <div class="text-subtitle-2 font-weight-medium text-error mb-2">
                                    <v-icon size="small" class="mr-1">$minus</v-icon>
                                    Removed Goals
                                </div>
                                <v-card v-for="goal in perspective.changes.goals.removed" :key="goal.id"
                                    variant="outlined" class="mb-2 border-error">
                                    <v-card-text class="pa-3 text-decoration-line-through text-medium-emphasis">
                                        <div class="d-flex justify-space-between">
                                            <span>{{ goal.description }}</span>
                                            <v-chip size="small" color="error" variant="flat">
                                                {{ goal.weight }}%
                                            </v-chip>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </div>

                            <!-- Modified Goals -->
                            <div v-if="perspective.changes.goals.modified?.length" class="mb-3">
                                <div class="text-subtitle-2 font-weight-medium text-warning mb-2">
                                    <v-icon size="small" class="mr-1">$pencil</v-icon>
                                    Modified Goals
                                </div>
                                <v-card v-for="goal in perspective.changes.goals.modified" :key="goal.id"
                                    variant="outlined" class="mb-2 border-warning">
                                    <v-card-text class="pa-3">
                                        <div class="font-weight-medium mb-2">{{ goal.description }}</div>

                                        <!-- Goal Description Change -->
                                        <div v-if="goal.changes.description" class="mb-2">
                                            <div class="text-caption text-medium-emphasis">Description:</div>
                                            <div class="text-body-2">
                                                <span class="text-decoration-line-through text-error">{{
                                                    goal.changes.description.from }}</span>
                                                <v-icon size="small" class="mx-2">$arrowRight</v-icon>
                                                <span class="text-success">{{ goal.changes.description.to }}</span>
                                            </div>
                                        </div>

                                        <!-- Goal Weight Change -->
                                        <div v-if="goal.changes.weight" class="mb-2">
                                            <div class="text-caption text-medium-emphasis">Weight:</div>
                                            <div class="text-body-2">
                                                <span class="text-decoration-line-through text-error">{{
                                                    goal.changes.weight.from }}%</span>
                                                <v-icon size="small" class="mx-2">$arrowRight</v-icon>
                                                <span class="text-success">{{ goal.changes.weight.to }}%</span>
                                            </div>
                                        </div>

                                        <!-- Objective Changes -->
                                        <div v-if="goal.changes.objectives">
                                            <!-- Added Objectives -->
                                            <div v-if="goal.changes.objectives.added?.length" class="mt-3 pa-2 rounded"
                                                style="background-color: rgba(76, 175, 80, 0.1);">
                                                <div class="text-caption font-weight-medium text-success mb-1">
                                                    + {{ goal.changes.objectives.added.length }} Objective(s) Added
                                                </div>
                                                <div v-for="obj in goal.changes.objectives.added" :key="obj.id"
                                                    class="text-caption ml-3">
                                                    • {{ obj.description }} ({{ obj.weight }}%)
                                                </div>
                                            </div>

                                            <!-- Removed Objectives -->
                                            <div v-if="goal.changes.objectives.removed?.length"
                                                class="mt-2 pa-2 rounded"
                                                style="background-color: rgba(244, 67, 54, 0.1);">
                                                <div class="text-caption font-weight-medium text-error mb-1">
                                                    - {{ goal.changes.objectives.removed.length }} Objective(s) Removed
                                                </div>
                                                <div v-for="obj in goal.changes.objectives.removed" :key="obj.id"
                                                    class="text-caption ml-3 text-decoration-line-through">
                                                    • {{ obj.description }} ({{ obj.weight }}%)
                                                </div>
                                            </div>

                                            <!-- Modified Objectives -->
                                            <div v-if="goal.changes.objectives.modified?.length" class="mt-2">
                                                <div class="text-caption font-weight-medium text-warning mb-1">
                                                    ✎ {{ goal.changes.objectives.modified.length }} Objective(s)
                                                    Modified
                                                </div>
                                                <v-expansion-panels variant="accordion" class="mt-1">
                                                    <v-expansion-panel v-for="obj in goal.changes.objectives.modified"
                                                        :key="obj.id">
                                                        <v-expansion-panel-title class="pa-2 text-caption">
                                                            {{ obj.description }}
                                                        </v-expansion-panel-title>
                                                        <v-expansion-panel-text>
                                                            <div v-for="(change, field) in obj.changes" :key="field"
                                                                class="text-caption mb-1">
                                                                <strong class="text-capitalize">{{ field }}:</strong>
                                                                <span
                                                                    class="text-decoration-line-through text-error ml-2">
                                                                    {{ formatValue(change.from) }}
                                                                </span>
                                                                <v-icon size="x-small" class="mx-1">$arrowRight</v-icon>
                                                                <span class="text-success">
                                                                    {{ formatValue(change.to) }}
                                                                </span>
                                                            </div>
                                                        </v-expansion-panel-text>
                                                    </v-expansion-panel>
                                                </v-expansion-panels>
                                            </div>
                                        </div>
                                    </v-card-text>
                                </v-card>
                            </div>
                        </div>
                    </div>
                </div>
            </v-card-text>

            <!-- Error State -->
            <v-card-text v-else class="pa-8 text-center">
                <v-icon size="64" color="error">$alertCircle</v-icon>
                <p class="text-body-1 mt-4">Failed to load changes</p>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="localValue = false">
                    Close
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.border-bottom {
    border-bottom: 1px solid rgba(0, 0, 0, 0.12);
}

.border-success {
    border-color: rgb(var(--v-theme-success)) !important;
}

.border-error {
    border-color: rgb(var(--v-theme-error)) !important;
}

.border-warning {
    border-color: rgb(var(--v-theme-warning)) !important;
}

.bg-warning-lighten-5 {
    background-color: rgba(var(--v-theme-warning), 0.1);
}
</style>