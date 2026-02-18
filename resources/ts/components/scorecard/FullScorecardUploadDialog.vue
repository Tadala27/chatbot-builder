<script setup lang="ts">
import { ref, watch } from 'vue'
import axios from 'axios'
import Swal from 'sweetalert2'

// ────────────────────────────────────────────────
// Import all needed helpers from utils
// ────────────────────────────────────────────────
import {
    readExcelFile,
    processFullScorecardExcel,
    validatePerspectiveWeights,
} from '@/utils/scorecardUtils'   // adjust path if needed

const props = defineProps<{
    modelValue: boolean
    scorecard: any
    perspectives: any[]
    tenantConfig: any
    isSaving: boolean
}>()

const emit = defineEmits<{
    (e: 'update:modelValue', value: boolean): void
    (e: 'reload'): void
    (e: 'showSnackbar', msg: string, color?: string, timeout?: number): void
}>()

// ────────────────────────────────────────────────
// Local state
// ────────────────────────────────────────────────
const fullScorecardData = ref<{ perspectives: any[] }>({ perspectives: [] })
const fullScorecardUploadedFile = ref<File | null>(null)
const isProcessingFullScorecard = ref(false)
const fullScorecardFileInput = ref<HTMLInputElement | null>(null)

// ────────────────────────────────────────────────
// Reset state when dialog opens
// ────────────────────────────────────────────────
watch(() => props.modelValue, (val) => {
    if (val) {
        fullScorecardUploadedFile.value = null
        fullScorecardData.value = { perspectives: [] }
    }
})

// ────────────────────────────────────────────────
// File upload trigger & handler
// ────────────────────────────────────────────────
const triggerFullScorecardFileUpload = () => {
    fullScorecardFileInput.value?.click()
}

const handleFullScorecardFileUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    const file = target.files?.[0]
    if (!file) return

    fullScorecardUploadedFile.value = file
    isProcessingFullScorecard.value = true

    try {
        const data = await readExcelFile(file)
        const processed = processFullScorecardExcel(data, props.tenantConfig)

        if (!processed || processed.length === 0) {
            emit('showSnackbar', 'No valid perspectives found in the Excel file', 'warning')
            return
        }

        fullScorecardData.value.perspectives = processed

        const totalGoals = processed.reduce((sum, p) => sum + p.goals.length, 0)
        const totalObjectives = processed.reduce(
            (sum, p) => sum + p.goals.reduce((gs: number, g: any) => gs + (g.objectives?.length || 0), 0),
            0
        )

        emit(
            'showSnackbar',
            `Loaded ${processed.length} perspective(s), ${totalGoals} goal(s), ${totalObjectives} objective(s)`,
            'success'
        )
    } catch (error) {
        console.error('Excel processing error:', error)
        emit('showSnackbar', 'Failed to process Excel file', 'error')
    } finally {
        isProcessingFullScorecard.value = false
    }
}

// ────────────────────────────────────────────────
// Save with validation - USING UNIFIED ENDPOINT
// ────────────────────────────────────────────────
const saveFullScorecard = async () => {
    if (!fullScorecardData.value.perspectives.length) {
        emit('showSnackbar', 'No data loaded. Upload file first.', 'error')
        return
    }

    // Use utility for validation
    const validationErrors = validatePerspectiveWeights(fullScorecardData.value.perspectives)

    if (validationErrors.length > 0) {
        const errorListHtml = validationErrors.map(err => `<li style="margin-bottom: 6px;">${err}</li>`).join('')

        const result = await Swal.fire({
            title: 'Weight Distribution Issues',
            html: `
        <div style="text-align:left; max-height:300px; overflow-y:auto;">
          <p style="margin-bottom:12px; font-weight:500;">The uploaded scorecard has the following issues:</p>
          <ul style="padding-left:20px; margin:0;">${errorListHtml}</ul>
          <p style="margin-top:16px; color:#e65100; font-weight:500;">
            Saving with invalid weights may cause scoring calculation issues later.
          </p>
        </div>
      `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Save Anyway',
            cancelButtonText: 'Go Back & Fix',
            confirmButtonColor: '#f57c00',
            cancelButtonColor: '#757575',
            reverseButtons: true,
        })

        if (!result.isConfirmed) return

        emit('showSnackbar', 'Saving with invalid weights – please review later', 'warning', 6000)
    }

    try {
        let currentScorecardId = props.scorecard?.id

        // Create scorecard if needed (with weights from Excel)
        if (!currentScorecardId) {
            const user = (await axios.get('/api/auth/user')).data.data
            const resp = await axios.post('/api/scorecards', {
                position_id: user.current_position_id,
                financial_year_id: props.scorecard.financial_year_id,
                performance_period_id: props.scorecard.performance_period_id,
                bsc_template_id: props.scorecard.bsc_template_id,
                matrix_template_id: props.scorecard.matrix_template_id,
                perspective_weights: fullScorecardData.value.perspectives.map(p => ({
                    perspective_name: p.perspective_name,
                    weight: p.perspective_weight,
                })),
            })
            currentScorecardId = resp.data.data.id
        }

        // ✅ NEW: Process all perspectives using unified endpoint
        // Send all goals and objectives for each perspective in ONE API call per perspective
        for (const pData of fullScorecardData.value.perspectives) {
            const perspective = props.perspectives.find((p: any) =>
                p.perspective_name.toLowerCase().includes(pData.perspective_name.toLowerCase().slice(0, 6)) ||
                pData.perspective_name.toLowerCase().includes(p.perspective_name.toLowerCase().slice(0, 6))
            )

            if (!perspective) {
                console.warn(`No matching perspective found for: ${pData.perspective_name}`)
                continue
            }

            // ✅ Send all goals with their objectives in a SINGLE API call
            if (pData.goals.length > 0) {
                await axios.post('/api/scorecards/goals-objectives/save', {
                    mode: 'add',
                    type: 'both',
                    scorecardId: currentScorecardId,
                    perspectiveId: perspective.id,
                    goals: pData.goals.map((goal: any) => ({
                        description: goal.description,
                        weight: goal.weight,
                        objectives: goal.objectives.map((obj: any) => ({
                            description: obj.description,
                            objective_type: obj.objective_type,
                            absolute_value: obj.absolute_value,
                            target_type: obj.target_type,
                            appraisal_behaviour: obj.appraisal_behaviour,
                            weight: obj.weight,
                            requires_proof: obj.requires_proof,
                            proof_requirements: obj.proof_requirements,
                            targets: obj.targets
                        }))
                    }))
                })
            }
        }

        emit('reload')
        emit('update:modelValue', false)
        emit('showSnackbar', 'Full scorecard imported successfully!', 'success')
    } catch (err: any) {
        console.error('Full scorecard save failed:', err)
        emit('showSnackbar', err.response?.data?.message || 'Failed to save scorecard', 'error')
    }
}

// ────────────────────────────────────────────────
// Template download (still local — or move to utils if preferred)
// ────────────────────────────────────────────────
const downloadTemplate = async () => {
    try {
        const response = await axios.get('/api/scorecards/download-template', { responseType: 'blob' })
        const url = window.URL.createObjectURL(new Blob([response.data]))
        const link = document.createElement('a')
        link.href = url
        link.setAttribute('download', `BSC_Full_Template_${Date.now()}.xlsx`)
        document.body.appendChild(link)
        link.click()
        link.remove()
        window.URL.revokeObjectURL(url)
        emit('showSnackbar', 'Template downloaded successfully', 'success')
    } catch (error) {
        emit('showSnackbar', 'Failed to download template', 'error')
    }
}
</script>

<template>
    <v-dialog :model-value="modelValue" @update:model-value="emit('update:modelValue', $event)" max-width="900"
        scrollable persistent>
        <v-card>
            <v-card-title class="d-flex justify-space-between align-center">
                <span>Upload Full Scorecard (All Perspectives)</span>
                <v-btn icon="$close" variant="text" @click="emit('update:modelValue', false)" :disabled="isSaving" />
            </v-card-title>

            <v-divider />

            <v-card-text style="max-height: 70vh;">
                <v-alert type="info" variant="tonal" class="mb-4">
                    <template #prepend>
                        <v-icon>$information</v-icon>
                    </template>
                    <div>
                        <p class="font-weight-bold mb-2">Full Scorecard Upload Instructions:</p>
                        <ul class="text-body-2">
                            <li>Download the template which includes ALL perspectives</li>
                            <li>Fill in goals and objectives for each perspective</li>
                            <li>Upload the complete file — system will process all perspectives automatically</li>
                            <li>Goal weights are distributed within each perspective</li>
                            <li>Objective weights must sum to 100% for each goal</li>
                            <li><strong>All goals and objectives are sent in batch for optimal performance</strong></li>
                        </ul>
                    </div>
                </v-alert>

                <v-row>
                    <v-col cols="12" class="d-flex gap-2">
                        <v-btn color="secondary" variant="outlined" @click="downloadTemplate"
                            prepend-icon="$trayArrowDown">
                            Download Full Template
                        </v-btn>
                        <v-btn color="primary" variant="flat" @click="triggerFullScorecardFileUpload"
                            prepend-icon="$upload" :loading="isProcessingFullScorecard">
                            Upload Excel File
                        </v-btn>
                        <input ref="fullScorecardFileInput" type="file" accept=".xlsx,.xls"
                            @change="handleFullScorecardFileUpload" style="display: none" />
                    </v-col>

                    <v-col cols="12" v-if="fullScorecardUploadedFile">
                        <v-alert type="success" variant="tonal">
                            <template #prepend>
                                <v-icon>$checkCircle</v-icon>
                            </template>
                            <div>
                                <strong>File uploaded:</strong> {{ fullScorecardUploadedFile.name }}<br />
                                <strong>Processed:</strong>
                                {{ fullScorecardData.perspectives.length }} perspective(s) with
                                {{fullScorecardData.perspectives.reduce((sum: number, p: any) => sum + p.goals.length,
                                    0)}} goal(s)
                                and
                                {{fullScorecardData.perspectives.reduce(
                                    (sum: number, p: any) =>
                                        sum + p.goals.reduce((gSum: number, g: any) => gSum + (g.objectives?.length ||
                                            0), 0),
                                    0
                                )}} objective(s)
                            </div>
                        </v-alert>
                    </v-col>

                    <!-- Preview -->
                    <v-col cols="12" v-if="fullScorecardData.perspectives.length > 0">
                        <v-card variant="outlined">
                            <v-card-title class="bg-grey-lighten-4">
                                <v-icon start>$eye</v-icon>
                                Preview: Uploaded Data
                            </v-card-title>
                            <v-card-text class="pa-4">
                                <v-expansion-panels variant="accordion">
                                    <v-expansion-panel v-for="(perspective, pIndex) in fullScorecardData.perspectives"
                                        :key="pIndex">
                                        <v-expansion-panel-title>
                                            <strong>{{ perspective.perspective_name }}</strong>
                                            <v-chip size="small" class="ml-2">
                                                {{ perspective.goals.length }} goals
                                            </v-chip>
                                        </v-expansion-panel-title>
                                        <v-expansion-panel-text>
                                            <v-list density="compact">
                                                <v-list-item v-for="(goal, gIndex) in perspective.goals" :key="gIndex">
                                                    <template #prepend>
                                                        <v-icon size="small" color="success">$bullseyeArrow</v-icon>
                                                    </template>
                                                    <v-list-item-title>{{ goal.description }}</v-list-item-title>
                                                    <v-list-item-subtitle>
                                                        Weight: {{ goal.weight }}% | {{ goal.objectives?.length || 0 }}
                                                        objectives
                                                    </v-list-item-subtitle>
                                                </v-list-item>
                                            </v-list>
                                        </v-expansion-panel-text>
                                    </v-expansion-panel>
                                </v-expansion-panels>
                            </v-card-text>
                        </v-card>
                    </v-col>
                </v-row>
            </v-card-text>

            <v-divider />

            <v-card-actions class="pa-4">
                <v-spacer />
                <v-btn variant="text" @click="emit('update:modelValue', false)" :disabled="isSaving">
                    Cancel
                </v-btn>
                <v-btn color="primary" variant="flat" @click="saveFullScorecard" :loading="isSaving"
                    :disabled="!fullScorecardData.perspectives.length">
                    <v-icon start>$contentSave</v-icon>
                    Save Full Scorecard
                </v-btn>
            </v-card-actions>
        </v-card>
    </v-dialog>
</template>

<style scoped>
.gap-2 {
    gap: 8px;
}
</style>