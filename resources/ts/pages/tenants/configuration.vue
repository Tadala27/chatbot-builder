<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from "axios"
import Swal from 'sweetalert2'
import moment from 'moment'
import DatetimePicker from '@/components/DatetimePicker.vue'

const router = useRouter()
const isSaving = ref(false)
const isLoading = ref(true)

// Tab navigation
const tabs = ['configuration', 'settings']
const currentTabIndex = ref(0)
const tab = computed({
    get: () => tabs[currentTabIndex.value],
    set: (value) => {
        const index = tabs.findIndex(t => t === value)
        if (index !== -1) currentTabIndex.value = index
    }
})

const nextTab = () => {
    if (currentTabIndex.value < tabs.length - 1) {
        currentTabIndex.value++
    }
}

const previousTab = () => {
    if (currentTabIndex.value > 0) {
        currentTabIndex.value--
    }
}

const isFirstTab = computed(() => currentTabIndex.value === 0)
const isLastTab = computed(() => currentTabIndex.value === tabs.length - 1)

// Form
const form = ref({
    tenant_id: null,
    bsc_template_id: null,
    matrix_template_id: null,
    grade_system_id: null, // ← ADDED
    effective_from: moment().format('YYYY-MM-DD'),
    effective_to: null,
    is_primary: true,
    custom_settings: null
})

// Dropdown Options
const tenants = ref<any[]>([])
const bscTemplates = ref<any[]>([])
const matrixTemplates = ref<any[]>([])
const gradeSystems = ref<any[]>([]) // ← ADDED

// Selected Info (for display)
const selectedTenant = computed(() => {
    return tenants.value.find(t => t.id === form.value.tenant_id)
})

const selectedBscTemplate = computed(() => {
    return bscTemplates.value.find(t => t.id === form.value.bsc_template_id)
})

const selectedMatrixTemplate = computed(() => {
    return matrixTemplates.value.find(t => t.id === form.value.matrix_template_id)
})

const selectedGradeSystem = computed(() => { // ← ADDED
    return gradeSystems.value.find(t => t.id === form.value.grade_system_id)
})

// Fetch data
const fetchData = async () => {
    isLoading.value = true
    try {
        // Fetch tenants
        const { data: tenantsData } = await axios.get('/api/tenants', {
            params: { per_page: 100 }
        })
        tenants.value = tenantsData.data.data || tenantsData.data || []

        // Fetch available templates
        const { data: templatesData } = await axios.get('/api/tenant-configurations/available-templates')

        bscTemplates.value = templatesData.data.bsc_templates || []
        matrixTemplates.value = templatesData.data.matrix_templates || []
        gradeSystems.value = templatesData.data.grade_systems || [] // ← ADDED
    } catch (e: any) {
        Swal.fire({
            title: 'Error',
            text: 'Failed to load data. Please refresh the page.',
            icon: 'error'
        })
        console.error('Fetch error:', e)
    } finally {
        isLoading.value = false
    }
}

// Validation
const validateForm = () => {
    if (!form.value.tenant_id) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Please select a tenant',
            icon: 'error'
        })
        return false
    }

    if (!form.value.bsc_template_id) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Please select a BSC template',
            icon: 'error'
        })
        return false
    }

    if (!form.value.matrix_template_id) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Please select a Matrix template',
            icon: 'error'
        })
        return false
    }

    if (!form.value.effective_from) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Please set an effective from date',
            icon: 'error'
        })
        return false
    }

    if (form.value.effective_to && moment(form.value.effective_to).isBefore(form.value.effective_from)) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Effective to date must be after effective from date',
            icon: 'error'
        })
        return false
    }

    return true
}

// Submit
const submit = async () => {
    if (!validateForm()) return

    isSaving.value = true
    try {
        await axios.post('/api/tenant-configurations', form.value)

        await Swal.fire({
            title: 'Success!',
            text: 'Tenant configuration created successfully',
            icon: 'success',
            confirmButtonText: 'View Tenants',
        })

        router.push('/tenants')
    } catch (err: any) {
        Swal.fire({
            title: 'Error',
            text: err.response?.data?.message || 'Failed to create configuration',
            icon: 'error',
        })
    } finally {
        isSaving.value = false
    }
}
watch(
    () => form.value.effective_from,
    (newFrom) => {
        if (!newFrom) return

        const fromDate = moment(newFrom)

        // Only auto-set if effective_to is empty or invalid
        if (
            !form.value.effective_to ||
            moment(form.value.effective_to).isBefore(fromDate)
        ) {
            form.value.effective_to = fromDate
                .add(364, 'day')
                .format('YYYY-MM-DD')
        }
    }
)

// Lifecycle
onMounted(() => {
    fetchData()
})
</script>

<template>
    <v-card elevation="0">
        <v-card-title class="d-flex align-center pa-6 bg-primary text-white">
            <v-icon icon="$plus" size="28" class="mr-3"></v-icon>
            <span class="text-h5">Configure Tenant</span>
        </v-card-title>

        <v-divider></v-divider>

        <!-- Loading -->
        <v-container v-if="isLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" size="64" />
        </v-container>

        <!-- Form -->
        <div v-else>
            <v-tabs v-model="tab" color="primary" align-tabs="center" grow>
                <v-tab value="configuration">
                    <v-icon start>$cog</v-icon>
                    Configuration
                </v-tab>

                <v-tab value="settings">
                    <v-icon start>$tune</v-icon>
                    Dates & Settings
                </v-tab>
            </v-tabs>

            <v-divider></v-divider>

            <v-window v-model="tab">
                <!-- Tab 1: Configuration -->
                <v-window-item value="configuration">
                    <v-card-text class="pa-6">
                        <v-row>
                            <v-col cols="12">
                                <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                                    <template #prepend>
                                        <v-icon>$information</v-icon>
                                    </template>
                                    Assign BSC, Performance Matrix, and Grade System templates to a tenant
                                </v-alert>
                            </v-col>

                            <!-- Tenant Selection -->
                            <v-col cols="12" md="6">
                                <v-autocomplete v-model="form.tenant_id" :items="tenants" item-title="name"
                                    item-value="id" label="Select Tenant *" variant="outlined" density="compact"
                                    clearable :hint="selectedTenant ? `Code: ${selectedTenant.code}` : ''"
                                    persistent-hint>

                                </v-autocomplete>
                            </v-col>

                            <!-- BSC Template Selection -->
                            <v-col cols="12" md="6">
                                <v-autocomplete v-model="form.bsc_template_id" :items="bscTemplates" item-title="name"
                                    item-value="id" label="BSC Template *" variant="outlined" density="compact"
                                    clearable :hint="selectedBscTemplate ? `Code: ${selectedBscTemplate.code}` : ''"
                                    persistent-hint>

                                </v-autocomplete>
                            </v-col>

                            <!-- Matrix Template Selection -->
                            <v-col cols="12" md="6">
                                <v-autocomplete v-model="form.matrix_template_id" :items="matrixTemplates"
                                    item-title="name" item-value="id" label="Performance Matrix Template *"
                                    variant="outlined" density="compact" clearable
                                    :hint="selectedMatrixTemplate ? `Code: ${selectedMatrixTemplate.code}` : ''"
                                    persistent-hint>

                                </v-autocomplete>
                            </v-col>

                            <!-- Grade System Selection ← ADDED -->
                            <v-col cols="12" md="6">
                                <v-autocomplete v-model="form.grade_system_id" :items="gradeSystems" item-title="name"
                                    item-value="id" label="Grade System (Optional)" variant="outlined" density="compact"
                                    clearable
                                    :hint="selectedGradeSystem ? `Code: ${selectedGradeSystem.code} - Defines organizational job grades` : 'Select a grade system for position grading'"
                                    persistent-hint>

                                </v-autocomplete>
                            </v-col>

                            <!-- Preview Card -->
                            <v-col cols="12" v-if="form.tenant_id && form.bsc_template_id && form.matrix_template_id">
                                <v-card variant="outlined" color="success">
                                    <v-card-title class="bg-success-lighten-5 text-success">
                                        <v-icon start>$checkCircle</v-icon>
                                        Configuration Preview
                                    </v-card-title>
                                    <v-card-text>
                                        <v-list density="compact">
                                            <v-list-item>
                                                <template #prepend>
                                                    <v-icon>$officeBuilding</v-icon>
                                                </template>
                                                <v-list-item-title>Tenant</v-list-item-title>
                                                <v-list-item-subtitle>{{ selectedTenant?.name }}</v-list-item-subtitle>
                                            </v-list-item>
                                            <v-list-item>
                                                <template #prepend>
                                                    <v-icon>$clipboardText</v-icon>
                                                </template>
                                                <v-list-item-title>BSC Template</v-list-item-title>
                                                <v-list-item-subtitle>{{ selectedBscTemplate?.name
                                                    }}</v-list-item-subtitle>
                                            </v-list-item>
                                            <v-list-item>
                                                <template #prepend>
                                                    <v-icon>$chartTimelineVariant</v-icon>
                                                </template>
                                                <v-list-item-title>Matrix Template</v-list-item-title>
                                                <v-list-item-subtitle>{{ selectedMatrixTemplate?.name
                                                    }}</v-list-item-subtitle>
                                            </v-list-item>
                                            <!-- ← ADDED -->
                                            <v-list-item v-if="form.grade_system_id">
                                                <template #prepend>
                                                    <v-icon>$medal</v-icon>
                                                </template>
                                                <v-list-item-title>Grade System</v-list-item-title>
                                                <v-list-item-subtitle>{{ selectedGradeSystem?.name
                                                    }}</v-list-item-subtitle>
                                            </v-list-item>
                                        </v-list>
                                    </v-card-text>
                                </v-card>
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-window-item>

                <!-- Tab 2: Dates & Settings -->
                <v-window-item value="settings">
                    <v-card-text class="pa-6">
                        <v-row>
                            <v-col cols="12">
                                <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                                    <template #prepend>
                                        <v-icon>$information</v-icon>
                                    </template>
                                    Set the effective period and optional custom settings
                                </v-alert>
                            </v-col>

                            <!-- Effective From -->
                            <v-col cols="12" md="6">
                                <DatetimePicker v-model="form.effective_from" label="Date Joined"
                                    placeholder="Select date" dateOnly variant="outlined" density="compact" clearable
                                    date-time-format="MMM dd, yyyy" required
                                    hint="When this configuration becomes active" persistent-hint />

                            </v-col>

                            <!-- Effective To -->
                            <v-col cols="12" md="6">
                                <DatetimePicker v-model="form.effective_to" label="Date Joined"
                                    placeholder="Select date" dateOnly :min-date="form.effective_from"
                                    variant="outlined" density="compact" clearable date-time-format="MMM dd, yyyy"
                                    required hint="Leave empty for ongoing configuration" persistent-hint />


                            </v-col>

                            <!-- Primary Configuration -->
                            <v-col cols="12" md="6">
                                <v-switch v-model="form.is_primary" label="Set as Primary Configuration" color="primary"
                                    hint="Only one primary configuration per tenant" persistent-hint />
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-window-item>
            </v-window>

            <v-divider></v-divider>

            <!-- Actions -->
            <v-card-actions class="pa-6">
                <v-btn variant="text" color="error" prepend-icon="$close"
                    @click="router.push('/tenants/configuration')">
                    Cancel
                </v-btn>

                <v-spacer></v-spacer>

                <v-btn v-if="!isFirstTab" variant="text" color="grey" prepend-icon="$arrowLeft" size="large"
                    @click="previousTab">
                    Previous
                </v-btn>

                <v-btn v-if="!isLastTab" variant="text" color="primary" append-icon="$arrowRight" size="large"
                    @click="nextTab">
                    Next
                </v-btn>

                <v-btn v-if="isLastTab" color="success" prepend-icon="$check" @click="submit" :loading="isSaving"
                    :disabled="isSaving">
                    Create Configuration
                </v-btn>
            </v-card-actions>
        </div>
    </v-card>
</template>

<style scoped>
.bg-success-lighten-5 {
    background-color: rgba(76, 175, 80, 0.1);
}

pre {
    overflow-x: auto;
}
</style>