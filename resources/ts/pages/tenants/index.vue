<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from "axios"
import moment from 'moment'
import Swal from 'sweetalert2'
import { watchDebounced } from '@vueuse/core'
import DateRangePicker from '@/components/DateRangePicker.vue'
import DatetimePicker from '@/components/DatetimePicker.vue'


const router = useRouter()

// State
const isLoading = ref(true)
const loading = ref(false)
const tenants = ref<any[]>([])
const meta = ref<any>({
    current_page: 1,
    from: 0,
    to: 0,
    per_page: 15,
    total: 0,
    last_page: 1
})

// Filters
const search = ref('')
const statusFilter = ref('All')
const typeFilter = ref('All')

// Pagination
const page = ref(1)
const perPage = ref(15)

// Performance Period Dialog
const periodDialog = ref(false)
const savingPeriod = ref(false)
const isEditMode = ref(false)
const existingPeriod = ref<any>(null)
const dateRange = ref({ start: null, end: null })
const reviewDateRange = ref({ start: null, end: null })
const periodForm = ref({
    tenant_id: null as number | null,
    name: '',
    period_type: 'annual',
    start_date: '',
    end_date: '',
    review_start_date: '',
    review_end_date: '',
    cascade_to_children: true,
    quarters_config: [] as any[]
})

// Quarters configuration
const quartersConfig = ref<any[]>([])
const autoGenerateQuarters = ref(true)

const periodTypeOptions = [
    { title: 'Annual', value: 'annual' },
    { title: 'Semi-Annual', value: 'semi_annual' },
    { title: 'Quarterly', value: 'quarterly' },
    { title: 'Monthly', value: 'monthly' },
    { title: 'Custom', value: 'custom' }
]

// Snackbar
const snackbar = ref({ show: false, message: '', color: 'success', timeout: 4000 })
const showSnackbar = (msg: string, color = 'success', timeout = 4000) => {
    snackbar.value = { show: true, message: msg, color, timeout }
}

// Computed
const statusOptions = ['All', 'Active', 'Inactive']
const typeOptions = ['All', 'Parent', 'Child']
const totalPages = computed(() => meta.value.last_page || 1)

const selectedTenant = computed(() => {
    if (!periodForm.value.tenant_id) return null
    return tenants.value.find(t => t.id === periodForm.value.tenant_id)
})

const isParentTenant = computed(() => {
    return selectedTenant.value && !selectedTenant.value.parent_tenant_id
})

const childrenCount = computed(() => {
    return selectedTenant.value?.children_count || 0
})

const dialogTitle = computed(() => {
    return isEditMode.value ? 'Edit Performance Period' : 'Create Performance Period'
})

const saveButtonText = computed(() => {
    return isEditMode.value ? 'Update Period' : 'Create Period'
})

// Watch date range and auto-generate quarters
watch(dateRange, (newRange) => {
    if (newRange.start) {
        const startYear = moment(newRange.start).year()
        periodForm.value.name = `Fiscal Year ${startYear}`
        periodForm.value.start_date = newRange.start
    }
    if (newRange.end) {
        periodForm.value.end_date = newRange.end
    }

    // Auto-generate quarters when dates change
    if (autoGenerateQuarters.value && newRange.start && newRange.end) {
        generateQuarters()
    }
}, { deep: true })

// Watch review date range
watch(reviewDateRange, (newRange) => {
    if (newRange.start) {
        periodForm.value.review_start_date = newRange.start
    }
    if (newRange.end) {
        periodForm.value.review_end_date = newRange.end
    }
}, { deep: true })

// Watch period type to adjust quarter generation
watch(() => periodForm.value.period_type, () => {
    if (autoGenerateQuarters.value && dateRange.value.start && dateRange.value.end) {
        generateQuarters()
    }
})

// Helpers
const formatDate = (date: string) => {
    if (!date) return ''
    return moment(date).format('DD MMM YYYY')
}

const getStatusColor = (isActive: boolean) => {
    return isActive ? 'success' : 'error'
}

const getInitials = (name: string) => {
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
}

const getTenantType = (tenant: any) => {
    return tenant.parent_tenant_id ? 'Subsidiary' : 'Parent'
}

// Generate quarters based on period type
const generateQuarters = () => {
    if (!dateRange.value.start || !dateRange.value.end) return

    const start = moment(dateRange.value.start)
    const end = moment(dateRange.value.end)
    const quarters: any[] = []

    const periodType = periodForm.value.period_type

    switch (periodType) {
        case 'quarterly':
            // Generate 4 quarters
            for (let i = 0; i < 4; i++) {
                const quarterStart = start.clone().add(i * 3, 'months')
                const quarterEnd = i === 3
                    ? end.clone()
                    : start.clone().add((i + 1) * 3, 'months').subtract(1, 'day')

                quarters.push({
                    name: `Q${i + 1}`,
                    start_date: quarterStart.format('YYYY-MM-DD'),
                    end_date: quarterEnd.format('YYYY-MM-DD')
                })
            }
            break

        case 'semi_annual':
            // Generate 2 halves
            const midPoint = start.clone().add(6, 'months')
            quarters.push({
                name: 'H1',
                start_date: start.format('YYYY-MM-DD'),
                end_date: midPoint.clone().subtract(1, 'day').format('YYYY-MM-DD')
            })
            quarters.push({
                name: 'H2',
                start_date: midPoint.format('YYYY-MM-DD'),
                end_date: end.format('YYYY-MM-DD')
            })
            break

        case 'monthly':
            // Generate 12 months
            const totalMonths = end.diff(start, 'months') + 1
            for (let i = 0; i < totalMonths; i++) {
                const monthStart = start.clone().add(i, 'months')
                const monthEnd = i === totalMonths - 1
                    ? end.clone()
                    : monthStart.clone().endOf('month')

                quarters.push({
                    name: monthStart.format('MMM'),
                    start_date: monthStart.format('YYYY-MM-DD'),
                    end_date: monthEnd.format('YYYY-MM-DD')
                })
            }
            break

        case 'annual':
        case 'custom':
        default:
            // Single period or custom (user defines)
            quarters.push({
                name: 'Full Period',
                start_date: start.format('YYYY-MM-DD'),
                end_date: end.format('YYYY-MM-DD')
            })
            break
    }

    quartersConfig.value = quarters
}

const addQuarter = () => {
    quartersConfig.value.push({
        name: `Q${quartersConfig.value.length + 1}`,
        start_date: '',
        end_date: ''
    })
}

const removeQuarter = (index: number) => {
    quartersConfig.value.splice(index, 1)
}

// Fetch
const fetchTenants = async () => {
    loading.value = true
    try {
        const params: any = {
            page: page.value,
            per_page: perPage.value,
            sort_by: 'created_at',
            sort_order: 'desc'
        }

        if (search.value) params.search = search.value
        if (statusFilter.value !== 'All') {
            params.is_active = statusFilter.value === 'Active'
        }
        if (typeFilter.value === 'Parent') {
            params.type = 'parent'
        } else if (typeFilter.value === 'Child') {
            params.type = 'child'
        }

        const { data } = await axios.get('/api/tenants', { params })

        if (data.success && data.data) {
            tenants.value = data.data.data || []
            meta.value = data.data.meta || {
                current_page: 1,
                from: 0,
                to: 0,
                per_page: 15,
                total: 0,
                last_page: 1
            }
        } else {
            tenants.value = data.data?.data || data.data || []
            meta.value = data.meta || data.data?.meta || {
                current_page: 1,
                from: 0,
                to: 0,
                per_page: 15,
                total: 0,
                last_page: 1
            }
        }
    } catch (e: any) {
        showSnackbar('Failed to load tenants', 'error')
        console.error('Fetch error:', e)
    } finally {
        isLoading.value = false
        loading.value = false
    }
}

// Check if period exists for tenant
const checkExistingPeriod = async (tenantId: number) => {
    try {
        const { data } = await axios.get('/api/performance-periods/check-existing', {
            params: { tenant_id: tenantId }
        })

        if (data.success && data.exists) {
            existingPeriod.value = data.data
            isEditMode.value = true

            // Populate form with existing data
            periodForm.value = {
                tenant_id: data.data.tenant_id,
                name: data.data.name,
                period_type: data.data.period_type,
                start_date: data.data.start_date,
                end_date: data.data.end_date,
                review_start_date: data.data.review_start_date || '',
                review_end_date: data.data.review_end_date || '',
                cascade_to_children: false,
                quarters_config: data.data.quarters_config || []
            }

            // Set date ranges
            dateRange.value = {
                start: data.data.start_date,
                end: data.data.end_date
            }

            if (data.data.review_start_date && data.data.review_end_date) {
                reviewDateRange.value = {
                    start: data.data.review_start_date,
                    end: data.data.review_end_date
                }
            }

            // Set quarters
            if (data.data.quarters_config && data.data.quarters_config.length > 0) {
                quartersConfig.value = data.data.quarters_config
                autoGenerateQuarters.value = false
            } else {
                generateQuarters()
            }

            return true
        } else {
            isEditMode.value = false
            existingPeriod.value = null
            return false
        }
    } catch (e) {
        console.error('Error checking existing period:', e)
        return false
    }
}

// Performance Period Actions
const openPeriodDialog = async (tenant?: any) => {
    resetPeriodForm()

    if (tenant) {
        periodForm.value.tenant_id = tenant.id

        // Check if period already exists
        await checkExistingPeriod(tenant.id)
    }

    periodDialog.value = true
}

const resetPeriodForm = () => {
    dateRange.value = { start: null, end: null }
    reviewDateRange.value = { start: null, end: null }
    quartersConfig.value = []
    autoGenerateQuarters.value = true
    isEditMode.value = false
    existingPeriod.value = null
    periodForm.value = {
        tenant_id: null,
        name: '',
        period_type: 'annual',
        start_date: '',
        end_date: '',
        review_start_date: '',
        review_end_date: '',
        cascade_to_children: true,
        quarters_config: []
    }
}

const savePerformancePeriod = async () => {
    // Validation
    if (!periodForm.value.tenant_id) {
        showSnackbar('Please select a tenant', 'error')
        return
    }
    if (!periodForm.value.start_date || !periodForm.value.end_date) {
        showSnackbar('Please select start and end dates', 'error')
        return
    }

    savingPeriod.value = true

    try {
        // Prepare payload with quarters
        const payload = {
            ...periodForm.value,
            quarters_config: quartersConfig.value.length > 0 ? quartersConfig.value : null,
            cascade_to_children: !isEditMode.value && isParentTenant.value && periodForm.value.cascade_to_children
        }

        let response

        if (isEditMode.value && existingPeriod.value) {
            // Update existing period
            response = await axios.put(`/api/performance-periods/${existingPeriod.value.id}`, payload)
        } else {
            // Create new period
            response = await axios.post('/api/performance-periods', payload)
        }

        const { data } = response

        if (data.success) {
            let message = isEditMode.value
                ? 'Performance period updated successfully'
                : 'Performance period created successfully'

            if (!isEditMode.value && isParentTenant.value && periodForm.value.cascade_to_children && childrenCount.value > 0) {
                message += ` and cascaded to ${childrenCount.value} child tenant(s)`
            }

            showSnackbar(message, 'success')
            periodDialog.value = false
            resetPeriodForm()
        }
    } catch (e: any) {
        const errorMsg = e.response?.data?.message || e.response?.data?.errors
            ? Object.values(e.response.data.errors).flat().join(', ')
            : `Failed to ${isEditMode.value ? 'update' : 'create'} performance period`
        showSnackbar(errorMsg, 'error', 6000)
    } finally {
        savingPeriod.value = false
    }
}

// Tenant Actions
const editTenant = (id: number) => {
    router.push(`/tenants/${id}/edit`)
}

const viewTenant = (id: number) => {
    router.push(`/tenants/${id}`)
}

const deleteTenant = async (id: number) => {
    const { isConfirmed } = await Swal.fire({
        title: 'Delete Tenant',
        text: 'This will remove the tenant and all associated data. This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete!'
    })
    if (!isConfirmed) return

    try {
        await axios.delete(`/api/tenants/${id}`)
        showSnackbar('Tenant deleted successfully', 'success')
        fetchTenants()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || 'Failed to delete tenant', 'error')
    }
}

const toggleStatus = async (tenant: any) => {
    const action = tenant.is_active ? 'deactivate' : 'activate'
    const { isConfirmed } = await Swal.fire({
        title: `${action.charAt(0).toUpperCase() + action.slice(1)} Tenant`,
        text: `Are you sure you want to ${action} ${tenant.name}?`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: `Yes, ${action}!`
    })
    if (!isConfirmed) return

    try {
        await axios.patch(`/api/tenants/${tenant.id}/toggle-status`)
        showSnackbar(`Tenant ${action}d successfully`, 'success')
        fetchTenants()
    } catch (e: any) {
        showSnackbar(e.response?.data?.message || `Failed to ${action} tenant`, 'error')
    }
}

const clearFilters = () => {
    search.value = ''
    statusFilter.value = 'All'
    typeFilter.value = 'All'
    page.value = 1
}

// Watchers
watchDebounced(
    search,
    () => {
        page.value = 1
        fetchTenants()
    },
    { debounce: 500 }
)

watch([statusFilter, typeFilter], () => {
    page.value = 1
    fetchTenants()
})

watch(page, () => {
    fetchTenants()
})

// Lifecycle
onMounted(() => {
    fetchTenants()
})
</script>

<template>
    <div>
        <!-- Loading -->
        <v-container v-if="isLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" size="64" />
        </v-container>

        <!-- Main Content -->
        <div v-else>
            <!-- Header -->
            <div class="d-flex justify-space-between align-center mb-5">
                <div>
                    <h1 class="text-h4">Tenants</h1>
                    <p class="text-subtitle-1 text-medium-emphasis">Manage companies and subsidiaries</p>
                </div>
                <v-btn color="primary" @click="router.push('/tenants/create')" prepend-icon="$plus">
                    Add Tenant
                </v-btn>
            </div>

            <!-- Filters -->
            <v-row class="">
                <v-col cols="12" md="6">
                    <v-text-field v-model="search" label="Search tenants..." prepend-inner-icon="$magnify"
                        :loading="loading" variant="outlined" clearable hide-details
                        placeholder="Name, code, or email..." />
                </v-col>

                <v-col cols="12" md="2">
                    <v-select v-model="statusFilter" :items="statusOptions" label="Status" variant="outlined"
                        hide-details />
                </v-col>

                <v-col cols="12" md="2">
                    <v-select v-model="typeFilter" :items="typeOptions" label="Type" variant="outlined" hide-details />
                </v-col>

                <v-col cols="12" md="2" class="d-flex align-center">
                    <v-btn color="secondary" variant="text" @click="clearFilters" :loading="loading" :disabled="loading"
                        block>
                        Clear
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Table -->
            <v-card class="mt-4" elevation="0" variant="outlined">
                <v-table density="comfortable">
                    <thead class="bg-surface">
                        <tr class="text-secondary">
                            <th class="text-left pa-4">Company</th>
                            <th class="text-left pa-4">Type</th>
                            <th class="text-left pa-4">Contact</th>
                            <th class="text-left pa-4">Users</th>
                            <th class="text-left pa-4">Status</th>
                            <th class="text-center pa-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Empty State -->
                        <tr v-if="!tenants.length">
                            <td colspan="6" class="text-center py-12">
                                <v-icon size="64" color="grey-lighten-1">$officeBuilding</v-icon>
                                <h3 class="text-h6 text-grey mt-4">No tenants found</h3>
                                <p class="text-grey">
                                    {{ search ? 'Try adjusting your filters' : 'No tenants registered yet' }}
                                </p>
                                <v-btn color="primary" variant="outlined" @click="clearFilters" class="mt-4">
                                    Clear Filters
                                </v-btn>
                            </td>
                        </tr>

                        <!-- Rows -->
                        <tr v-for="tenant in tenants" :key="tenant.id">
                            <!-- Company Info -->
                            <td class="pa-4">
                                <div class="d-flex align-center gap-3">
                                    <v-avatar color="primary" variant="tonal" size="40">
                                        <span class="text-subtitle-2">
                                            {{ getInitials(tenant.name) }}
                                        </span>
                                    </v-avatar>
                                    <div>
                                        <p class="text-subtitle-2 font-weight-medium mb-0">
                                            {{ tenant.name }}
                                        </p>
                                        <p class="text-caption text-medium-emphasis mb-0">{{ tenant.code }}</p>
                                    </div>
                                </div>
                            </td>

                            <!-- Type -->
                            <td class="pa-4">
                                <v-chip :color="tenant.parent_tenant_id ? 'warning' : 'info'" size="small"
                                    variant="tonal">
                                    <v-icon start size="14">
                                        {{ tenant.parent_tenant_id ? '$siteMap' : '$domain' }}
                                    </v-icon>
                                    {{ getTenantType(tenant) }}
                                </v-chip>
                                <p v-if="tenant.parent" class="text-caption text-medium-emphasis mt-1 mb-0">
                                    Parent: {{ tenant.parent.name }}
                                </p>
                            </td>

                            <!-- Contact -->
                            <td class="pa-4">
                                <div>
                                    <p class="mb-1">{{ tenant.email || 'N/A' }}</p>
                                    <p class="text-caption text-medium-emphasis mb-0">{{ tenant.phone || 'N/A' }}</p>
                                </div>
                            </td>

                            <!-- Users -->
                            <td class="pa-4">
                                <div class="d-flex align-center">
                                    <v-icon size="16" class="mr-1">$accountGroup</v-icon>
                                    <span class="text-body-2">{{ tenant.users_count || 0 }} users</span>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="pa-4">
                                <v-chip :color="getStatusColor(tenant.is_active)" size="small" variant="tonal">
                                    <v-icon start size="14">
                                        {{ tenant.is_active ? '$checkCircle' : '$cancel' }}
                                    </v-icon>
                                    {{ tenant.is_active ? 'Active' : 'Inactive' }}
                                </v-chip>
                            </td>

                            <!-- Actions -->
                            <td class="pa-4 text-center">
                                <v-menu>
                                    <template #activator="{ props }">
                                        <v-btn v-bind="props" icon variant="text" color="grey" size="small">
                                            <v-icon>$dotsVertical</v-icon>
                                        </v-btn>
                                    </template>
                                    <v-list density="compact">
                                        <v-list-item @click="viewTenant(tenant.id)">
                                            <template #prepend>
                                                <v-icon size="small">$eye</v-icon>
                                            </template>
                                            <v-list-item-title>View Details</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item @click="editTenant(tenant.id)">
                                            <template #prepend>
                                                <v-icon size="small">$pencil</v-icon>
                                            </template>
                                            <v-list-item-title>Edit</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item @click="openPeriodDialog(tenant)">
                                            <template #prepend>
                                                <v-icon size="small">$calendar</v-icon>
                                            </template>
                                            <v-list-item-title>Manage Period</v-list-item-title>
                                        </v-list-item>
                                        <v-list-item @click="toggleStatus(tenant)">
                                            <template #prepend>
                                                <v-icon size="small">
                                                    {{ tenant.is_active ? '$cancel' : '$checkCircle' }}
                                                </v-icon>
                                            </template>
                                            <v-list-item-title>
                                                {{ tenant.is_active ? 'Deactivate' : 'Activate' }}
                                            </v-list-item-title>
                                        </v-list-item>
                                        <v-divider />
                                        <v-list-item @click="deleteTenant(tenant.id)" class="text-error">
                                            <template #prepend>
                                                <v-icon size="small">$trashCan</v-icon>
                                            </template>
                                            <v-list-item-title>Delete</v-list-item-title>
                                        </v-list-item>
                                    </v-list>
                                </v-menu>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <v-card-text v-if="tenants.length" class="pt-4">
                    <v-row class="align-center text-center text-sm-start" justify="space-between">
                        <v-col cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                            <span class="text-medium-emphasis">
                                Showing {{ meta.from || 0 }}–{{ meta.to || 0 }}
                                of {{ meta.total || 0 }} tenants
                            </span>
                        </v-col>
                        <v-col cols="12" sm="6" class="d-flex justify-center justify-sm-end">
                            <v-pagination v-model="page" :length="totalPages" :total-visible="7" rounded="circle"
                                density="comfortable" variant="outlined" color="primary" />
                        </v-col>
                    </v-row>
                </v-card-text>
            </v-card>
        </div>

        <!-- Create/Edit Performance Period Dialog -->
        <v-dialog v-model="periodDialog" max-width="800px" persistent scrollable>
            <v-card>
                <v-card-title class="d-flex justify-space-between align-center">
                    <div>
                        <span class="text-h5">{{ dialogTitle }}</span>
                        <v-chip v-if="isEditMode" color="info" size="small" class="ml-2">Editing</v-chip>
                    </div>
                    <v-btn icon="$close" variant="text" @click="periodDialog = false" :disabled="savingPeriod" />
                </v-card-title>

                <v-divider />

                <v-card-text class="pt-6" style="max-height: 70vh;">
                    <v-form>
                        <!-- Tenant Selection -->
                        <v-select v-model="periodForm.tenant_id" :items="tenants" item-title="name" item-value="id"
                            label="Select Tenant *" density="compact" variant="outlined"
                            :disabled="savingPeriod || isEditMode" class="mb-4">
                            <template #item="{ item, props }">
                                <v-list-item v-bind="props">
                                    <template #prepend>
                                        <v-avatar color="primary" variant="tonal" size="32">
                                            <span class="text-caption">{{ getInitials(item.raw.name) }}</span>
                                        </v-avatar>
                                    </template>
                                    <template #append v-if="!item.raw.parent_tenant_id">
                                        <v-chip size="x-small" color="info">Parent</v-chip>
                                    </template>
                                </v-list-item>
                            </template>
                        </v-select>

                        <!-- Edit Mode Alert -->
                        <v-alert v-if="isEditMode" type="info" variant="tonal" class="mb-4" density="compact">
                            <div class="text-body-2">
                                Editing existing performance period for {{ selectedTenant?.name }}
                            </div>
                        </v-alert>

                        <!-- Cascade Alert for Parent Tenants (Create mode only) -->
                        <v-alert v-if="!isEditMode && isParentTenant && childrenCount > 0" type="info" variant="tonal"
                            class="mb-4" density="compact">
                            <div class="d-flex align-center justify-space-between">
                                <span class="text-body-2">
                                    This parent tenant has {{ childrenCount }} child tenant(s)
                                </span>
                            </div>
                        </v-alert>

                        <!-- Period Type -->
                        <v-select v-model="periodForm.period_type" :items="periodTypeOptions" label="Period Type *"
                            variant="outlined" :disabled="savingPeriod" class="mb-4" />

                        <!-- Date Range Picker -->
                        <DateRangePicker density="compact" v-model="dateRange" label="Performance Period *"
                            placeholder="Select start and end dates" :disabled="savingPeriod" class="mb-4" />

                        <!-- Auto-generated Period Name Display -->
                        <v-text-field density="compact" v-model="periodForm.name" label="Period Name" variant="outlined"
                            :disabled="true" readonly class="mb-4" hint="Auto-generated based on fiscal year"
                            persistent-hint />

                        <!-- Review Date Range Picker -->
                        <DateRangePicker density="compact" v-model="reviewDateRange" label="Review Period (Optional)"
                            placeholder="Select review start and end dates" :disabled="savingPeriod" class="mb-4" />

                        <!-- Quarters Configuration -->
                        <v-card variant="outlined" class="mb-4">
                            <v-card-title class="d-flex justify-space-between align-center">
                                <span class="text-subtitle-1">Quarters Configuration</span>
                                <v-switch v-model="autoGenerateQuarters" label="Auto-generate" color="primary"
                                    hide-details density="compact" :disabled="savingPeriod" />
                            </v-card-title>
                            <v-divider />
                            <v-card-text>
                                <div v-if="!autoGenerateQuarters" class="mb-3">
                                    <v-btn color="primary" variant="outlined" size="small" @click="addQuarter"
                                        :disabled="savingPeriod" prepend-icon="$plus">
                                        Add Quarter
                                    </v-btn>
                                </div>

                                <div v-for="(quarter, index) in quartersConfig" :key="index" class="mb-3">
                                    <v-row>
                                        <v-col cols="3">
                                            <v-text-field v-model="quarter.name" label="Name" variant="outlined"
                                                :disabled="savingPeriod || autoGenerateQuarters" density="compact"
                                                hide-details />
                                        </v-col>
                                        <v-col cols="4">
                                            <DatetimePicker v-model="quarter.start_date" label="Start Date"
                                                placeholder="Select date" dateOnly :max-date="today" variant="outlined"
                                                density="compact" clearable date-time-format="MMM dd, yyyy"
                                                :disabled="savingPeriod || autoGenerateQuarters" />

                                        </v-col>
                                        <v-col cols="4">
                                            <DatetimePicker v-model="quarter.end_date" label="End Date"
                                                placeholder="Select date" dateOnly :max-date="today" variant="outlined"
                                                density="compact" clearable date-time-format="MMM dd, yyyy"
                                                :disabled="savingPeriod || autoGenerateQuarters" />

                                        </v-col>
                                        <v-col v-if="!autoGenerateQuarters" cols="1" class="d-flex align-center">
                                            <v-btn icon="$trashCan" variant="text" color="error" size="small"
                                                @click="removeQuarter(index)" :disabled="savingPeriod" />
                                        </v-col>
                                    </v-row>
                                </div>

                                <v-alert v-if="quartersConfig.length === 0" type="info" variant="tonal"
                                    density="compact">
                                    Set date range to auto-generate quarters or add them manually
                                </v-alert>
                            </v-card-text>
                        </v-card>

                        <!-- Cascade Checkbox (only for parent tenants in create mode) -->
                        <v-checkbox v-if="!isEditMode && isParentTenant && childrenCount > 0"
                            v-model="periodForm.cascade_to_children" :disabled="savingPeriod" color="primary"
                            hide-details>
                            <template #label>
                                <span class="text-body-2">
                                    Create this period for all {{ childrenCount }} child tenant(s)
                                </span>
                            </template>
                        </v-checkbox>
                    </v-form>
                </v-card-text>

                <v-divider />

                <v-card-actions class="px-6 py-4">
                    <v-spacer />
                    <v-btn variant="text" @click="periodDialog = false" :disabled="savingPeriod">
                        Cancel
                    </v-btn>
                    <v-btn color="primary" variant="flat" @click="savePerformancePeriod" :loading="savingPeriod">
                        {{ saveButtonText }}
                    </v-btn>
                </v-card-actions>
            </v-card>
        </v-dialog>

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
.gap-3 {
    gap: 12px;
}
</style>