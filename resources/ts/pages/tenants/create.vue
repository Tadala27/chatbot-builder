<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import axios from "axios"
import Swal from 'sweetalert2'

const router = useRouter()
const isSaving = ref(false)

// Tab navigation
const tabs = ['basic', 'contact', 'settings']
const currentTabIndex = ref(0)
const tab = computed({
    get: () => tabs[currentTabIndex.value],
    set: (value) => {
        const index = tabs.findIndex(t => t === value)
        if (index !== -1) currentTabIndex.value = index
    }
})

// Navigation functions
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
    name: '',
    code: '',
    email: '',
    phone: '',
    address: '',
    country: 'Malawi',
    description: '',
    parent_tenant_id: null,
    is_active: true,
    storage_limit: 10737418240, // 10GB default
    subscription_status: 'active',
    // Settings
    settings: {
        allow_user_registration: false,
        require_email_verification: true,
        enable_two_factor: false,
        default_role: 'Tenant User',
    },
    grading_config: {
        grading_system: 'alphabetical',
        performance_rating_system: '5-point',
    }
})

// Parent tenants (for subsidiaries)
const parentTenants = ref<any[]>([])
const loadingParents = ref(false)

const fetchParentTenants = async () => {
    loadingParents.value = true
    try {
        const { data } = await axios.get('/api/tenants?type=parent')
        parentTenants.value = data.data.data || []
    } catch (e) {
        console.error('Failed to load parent tenants:', e)
    } finally {
        loadingParents.value = false
    }
}

// Options
const countryOptions = [
    'Malawi',
    'South Africa',
    'Zimbabwe',
    'Zambia',
    'Tanzania',
    'Kenya',
    'Other'
]

const gradingSystemOptions = [
    { title: 'Alphabetical (A1-E3)', value: 'alphabetical' },
    { title: 'Managerial (M1-M8)', value: 'managerial' },
    { title: 'Professional (P1-P9)', value: 'professional' }
]

const ratingSystemOptions = [
    { title: '5-Point Scale', value: '5-point' },
    { title: '4-Point Scale', value: '4-point' },
    { title: '3-Point Scale', value: '3-point' },
    { title: 'Percentage (0-100%)', value: 'percentage' }
]

const defaultRoleOptions = [
    'Tenant User',
    'Tenant Admin'
]

// Storage limit presets
const storageLimitPresets = [
    { title: '5 GB', value: 5368709120 },
    { title: '10 GB', value: 10737418240 },
    { title: '20 GB', value: 21474836480 },
    { title: '50 GB', value: 53687091200 },
    { title: '100 GB', value: 107374182400 }
]

// Validation
const validateForm = () => {
    if (!form.value.name) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Company name is required',
            icon: 'error'
        })
        return false
    }

    if (!form.value.code) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Company code is required',
            icon: 'error'
        })
        return false
    }

    if (!form.value.email) {
        Swal.fire({
            title: 'Validation Error',
            text: 'Email is required',
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
        const payload = {
            ...form.value,
            // Convert storage limit to bytes
            storage_limit: parseInt(form.value.storage_limit as any),
        }

        await axios.post('/api/tenants', payload)

        await Swal.fire({
            title: 'Success!',
            text: 'Tenant created successfully',
            icon: 'success',
            confirmButtonText: 'View Tenants',
        })

        router.push('/tenants')
    } catch (err: any) {
        Swal.fire({
            title: 'Error',
            text: err.response?.data?.message || 'Failed to create tenant',
            icon: 'error',
        })
    } finally {
        isSaving.value = false
    }
}

// Load parent tenants on mount
fetchParentTenants()
</script>

<template>
    <v-card elevation="0">
        <v-card-title class="d-flex align-center pa-6 bg-primary text-white">
            <v-icon icon="$plus" size="28" class="mr-3"></v-icon>
            <span class="text-h5">Create New Tenant</span>
        </v-card-title>

        <v-divider></v-divider>

        <v-tabs v-model="tab" color="primary" align-tabs="center" grow>
            <v-tab value="basic">
                <v-icon start>$officeBuilding</v-icon>
                Basic Information
            </v-tab>

            <v-tab value="contact">
                <v-icon start>$phone</v-icon>
                Contact Details
            </v-tab>

            <v-tab value="settings">
                <v-icon start>$cog</v-icon>
                Configuration
            </v-tab>
        </v-tabs>

        <v-divider></v-divider>

        <v-window v-model="tab">
            <!-- Tab 1: Basic Information -->
            <v-window-item value="basic">
                <v-card-text class="pa-6">
                    <v-row>
                        <v-col cols="12">
                            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                                <template #prepend>
                                    <v-icon>$information</v-icon>
                                </template>
                                Enter the company's basic information
                            </v-alert>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.name" label="Company Name *" variant="outlined"
                                density="compact" required placeholder="e.g., Nico Holdings" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.code" label="Company Code *" variant="outlined"
                                density="compact" required placeholder="e.g., NICOHLD"
                                hint="Unique identifier (uppercase, no spaces)" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-autocomplete v-model="form.parent_tenant_id" :items="parentTenants" item-title="name"
                                item-value="id" label="Parent Company (Optional)" variant="outlined" density="compact"
                                clearable :loading="loadingParents" hint="Leave empty if this is a parent company">
                                <template #prepend-inner>
                                    <v-icon size="small">$domain</v-icon>
                                </template>
                            </v-autocomplete>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.country" :items="countryOptions" label="Country" variant="outlined"
                                density="compact" />
                        </v-col>

                        <v-col cols="12">
                            <v-textarea v-model="form.description" label="Description (Optional)" variant="outlined"
                                rows="3" density="compact" placeholder="Brief description about the company..." />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-switch v-model="form.is_active" label="Active Status" color="success"
                                hint="Inactive tenants cannot access the system" persistent-hint />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-window-item>

            <!-- Tab 2: Contact Details -->
            <v-window-item value="contact">
                <v-card-text class="pa-6">
                    <v-row>
                        <v-col cols="12">
                            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                                <template #prepend>
                                    <v-icon>$information</v-icon>
                                </template>
                                Company contact information and address
                            </v-alert>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.email" label="Email Address *" type="email" variant="outlined"
                                density="compact" required placeholder="info@company.com" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-text-field v-model="form.phone" label="Phone Number" placeholder="+265 999 123 456"
                                variant="outlined" density="compact" />
                        </v-col>

                        <v-col cols="12">
                            <v-textarea v-model="form.address" label="Physical Address" variant="outlined" rows="3"
                                density="compact" placeholder="Street address, city, postal code..." />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-window-item>

            <!-- Tab 3: Configuration -->
            <v-window-item value="settings">
                <v-card-text class="pa-6">
                    <v-row>
                        <v-col cols="12">
                            <v-alert type="info" variant="tonal" density="compact" class="mb-4">
                                <template #prepend>
                                    <v-icon>$information</v-icon>
                                </template>
                                System configuration and settings
                            </v-alert>
                        </v-col>

                        <!-- Storage Settings -->
                        <v-col cols="12">
                            <h6 class="text-h6 mb-3">Storage Settings</h6>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.storage_limit" :items="storageLimitPresets" label="Storage Limit"
                                variant="outlined" density="compact">
                                <template #prepend-inner>
                                    <v-icon size="small">$database</v-icon>
                                </template>
                            </v-select>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.subscription_status"
                                :items="['active', 'expired', 'suspended', 'cancelled']" label="Subscription Status"
                                variant="outlined" density="compact" />
                        </v-col>

                        <!-- Grading Configuration -->
                        <v-col cols="12">
                            <h6 class="text-h6 mb-3 mt-4">Performance Management</h6>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.grading_config.grading_system" :items="gradingSystemOptions"
                                label="Grading System" variant="outlined" density="compact" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.grading_config.performance_rating_system"
                                :items="ratingSystemOptions" label="Performance Rating" variant="outlined"
                                density="compact" />
                        </v-col>

                        <!-- User Settings -->
                        <v-col cols="12">
                            <h6 class="text-h6 mb-3 mt-4">User Management</h6>
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-select v-model="form.settings.default_role" :items="defaultRoleOptions"
                                label="Default User Role" variant="outlined" density="compact" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-switch v-model="form.settings.require_email_verification"
                                label="Require Email Verification" color="primary" density="compact" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-switch v-model="form.settings.allow_user_registration"
                                label="Allow User Self-Registration" color="primary" density="compact" />
                        </v-col>

                        <v-col cols="12" md="6">
                            <v-switch v-model="form.settings.enable_two_factor" label="Enable Two-Factor Authentication"
                                color="primary" density="compact" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-window-item>
        </v-window>

        <v-divider></v-divider>

        <!-- Actions -->
        <v-card-actions class="pa-6">
            <v-btn variant="text" color="error" prepend-icon="$close" @click="router.push('/tenants')">
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
                Create Tenant
            </v-btn>
        </v-card-actions>
    </v-card>
</template>