<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import moment from 'moment'
import { watchDebounced } from '@vueuse/core'

const router = useRouter()

// State
const isLoading = ref(true)
const loading = ref(false)
const scorecards = ref<any[]>([])
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
const statusFilter = ref('all')
const periodFilter = ref<number | null>(null)
const performancePeriods = ref<any[]>([])

// Pagination
const page = ref(1)
const perPage = ref(15)

// Snackbar
const snackbar = ref({ show: false, message: '', color: 'success', timeout: 4000 })
const showSnackbar = (msg: string, color = 'success', timeout = 4000) => {
    snackbar.value = { show: true, message: msg, color, timeout }
}

// Computed
const statusOptions = [
    { title: 'All Status', value: 'all' },
    { title: 'Draft', value: 'draft' },
    { title: 'Submitted', value: 'submitted' },
    { title: 'Manager Review', value: 'manager_review' },
    { title: 'Approved', value: 'approved' }
]

const totalPages = computed(() => meta.value.last_page || 1)

// Helpers
const getInitials = (name: string) => {
    if (!name) return '?'
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
}

const formatDate = (date: string) => {
    if (!date) return 'N/A'
    return moment(date).format('DD MMM YYYY')
}

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        'draft': 'warning',
        'submitted': 'info',
        'manager_review': 'primary',
        'approved': 'success',
        'active': 'success'
    }
    return colors[status] || 'default'
}

const getStatusIcon = (status: string) => {
    const icons: Record<string, string> = {
        'draft': '$fileDocumentEdit',
        'submitted': '$sendVariant',
        'manager_review': '$accountEye',
        'approved': '$checkCircle',
        'active': '$checkCircle'
    }
    return icons[status] || '$file'
}

const getStatusText = (status: string) => {
    const texts: Record<string, string> = {
        'draft': 'Draft',
        'submitted': 'Submitted',
        'manager_review': 'Under Review',
        'approved': 'Approved',
        'active': 'Active'
    }
    return texts[status] || status
}

// Fetch
const fetchPerformancePeriods = async () => {
    try {
        const { data } = await axios.get('/api/performance-periods', {
            params: { status: 'active', per_page: 100 }
        })
        performancePeriods.value = data.data?.data || []
    } catch (e) {
        console.error('Failed to load performance periods:', e)
    }
}

const fetchScorecards = async () => {
    loading.value = true
    try {
        const params: any = {
            page: page.value,
            per_page: perPage.value,
            view: 'team' // Important: tells backend to show subordinates
        }

        if (search.value) params.search = search.value
        if (statusFilter.value !== 'all') params.status = statusFilter.value
        if (periodFilter.value) params.period_id = periodFilter.value

        const { data } = await axios.get('/api/subordinates/scorecards', { params })

        if (data.success) {
            scorecards.value = data.data?.data || []
            meta.value = data.data || {
                current_page: 1,
                from: 0,
                to: 0,
                per_page: 15,
                total: 0,
                last_page: 1
            }
        }
    } catch (e: any) {
        showSnackbar('Failed to load scorecards', 'error')
        console.error('Fetch error:', e)
    } finally {
        isLoading.value = false
        loading.value = false
    }
}

// Actions
const viewScorecard = (id: number) => {
    router.push(`/subordinates/${id}`)
}

const clearFilters = () => {
    search.value = ''
    statusFilter.value = 'all'
    periodFilter.value = null
    page.value = 1
}

// Watchers
watchDebounced(
    search,
    () => {
        page.value = 1
        fetchScorecards()
    },
    { debounce: 500 }
)

watch([statusFilter, periodFilter], () => {
    page.value = 1
    fetchScorecards()
})

watch(page, () => {
    fetchScorecards()
})

// Lifecycle
onMounted(async () => {
    await fetchPerformancePeriods()
    await fetchScorecards()
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
            <div class="mb-5">
                <h1 class="text-h4 mb-2">Team Scorecards</h1>
                <p class="text-subtitle-1 text-medium-emphasis">
                    Manage and review your team members' performance scorecards
                </p>
            </div>

            <!-- Filters -->
            <v-row class="mb-4">
                <v-col cols="12" md="5">
                    <v-text-field v-model="search" label="Search team members..." prepend-inner-icon="$magnify"
                        :loading="loading" variant="outlined" clearable hide-details
                        placeholder="Name, position, or business unit..." density="comfortable" />
                </v-col>

                <v-col cols="12" md="3">
                    <v-select v-model="statusFilter" :items="statusOptions" label="Status" variant="outlined"
                        hide-details density="comfortable" />
                </v-col>

                <v-col cols="12" md="3">
                    <v-select v-model="periodFilter" :items="performancePeriods" item-title="name" item-value="id"
                        label="Performance Period" variant="outlined" clearable hide-details density="comfortable" />
                </v-col>

                <v-col cols="12" md="1" class="d-flex align-center">
                    <v-btn color="secondary" variant="text" @click="clearFilters" :loading="loading" :disabled="loading"
                        block density="comfortable">
                        Clear
                    </v-btn>
                </v-col>
            </v-row>

            <!-- Summary Stats -->
            <v-row class="mb-4">
                <v-col cols="12" md="3">
                    <v-card variant="outlined">
                        <v-card-text class="d-flex align-center">
                            <v-avatar color="primary" variant="tonal" size="48" class="mr-3">
                                <v-icon size="24">$accountGroup</v-icon>
                            </v-avatar>
                            <div>
                                <p class="text-h5 font-weight-bold mb-0">{{ meta.total || 0 }}</p>
                                <p class="text-caption text-medium-emphasis mb-0">Total Team Members</p>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card variant="outlined">
                        <v-card-text class="d-flex align-center">
                            <v-avatar color="info" variant="tonal" size="48" class="mr-3">
                                <v-icon size="24">$sendVariant</v-icon>
                            </v-avatar>
                            <div>
                                <p class="text-h5 font-weight-bold mb-0">
                                    {{scorecards.filter(s => s.status === 'submitted').length}}
                                </p>
                                <p class="text-caption text-medium-emphasis mb-0">Pending Review</p>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card variant="outlined">
                        <v-card-text class="d-flex align-center">
                            <v-avatar color="warning" variant="tonal" size="48" class="mr-3">
                                <v-icon size="24">$accountEye</v-icon>
                            </v-avatar>
                            <div>
                                <p class="text-h5 font-weight-bold mb-0">
                                    {{scorecards.filter(s => s.status === 'manager_review').length}}
                                </p>
                                <p class="text-caption text-medium-emphasis mb-0">Under Review</p>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>

                <v-col cols="12" md="3">
                    <v-card variant="outlined">
                        <v-card-text class="d-flex align-center">
                            <v-avatar color="success" variant="tonal" size="48" class="mr-3">
                                <v-icon size="24">$checkCircle</v-icon>
                            </v-avatar>
                            <div>
                                <p class="text-h5 font-weight-bold mb-0">
                                    {{scorecards.filter(s => s.status === 'approved').length}}
                                </p>
                                <p class="text-caption text-medium-emphasis mb-0">Approved</p>
                            </div>
                        </v-card-text>
                    </v-card>
                </v-col>
            </v-row>

            <!-- Table -->
            <v-card elevation="0" variant="outlined">
                <v-table density="comfortable">
                    <thead class="bg-surface">
                        <tr class="text-secondary">
                            <th class="text-left pa-4">Employee</th>
                            <th class="text-left pa-4">Position</th>
                            <th class="text-left pa-4">Period</th>
                            <th class="text-left pa-4">Status</th>
                            <th class="text-left pa-4">Score</th>
                            <th class="text-left pa-4">Submitted</th>
                            <th class="text-center pa-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Empty State -->
                        <tr v-if="!scorecards.length">
                            <td colspan="7" class="text-center py-12">
                                <v-icon size="64" color="grey-lighten-1">$accountGroupOutline</v-icon>
                                <h3 class="text-h6 text-grey mt-4">No team scorecards found</h3>
                                <p class="text-grey">
                                    {{ search ? 'Try adjusting your filters' : 'No scorecards available for your team'
                                    }}
                                </p>
                                <v-btn v-if="search || statusFilter !== 'all' || periodFilter" color="primary"
                                    variant="outlined" @click="clearFilters" class="mt-4">
                                    Clear Filters
                                </v-btn>
                            </td>
                        </tr>

                        <!-- Rows -->
                        <tr v-for="scorecard in scorecards" :key="scorecard.id" class="cursor-pointer"
                            @click="viewScorecard(scorecard.id)">
                            <!-- Employee -->
                            <td class="pa-4">
                                <div class="d-flex align-center gap-3">
                                    <v-avatar color="primary" variant="tonal" size="40">
                                        <span class="text-subtitle-2">
                                            {{ getInitials(scorecard.position?.current_holder?.user?.firstname || '',
                                                scorecard.position?.current_holder?.user?.lastname || '') || 'N/A' }}
                                        </span>
                                    </v-avatar>
                                    <div>
                                        <p class="text-subtitle-2 font-weight-medium mb-0">
                                            {{ scorecard.position?.current_holder?.user ?
                                                `${scorecard.position?.current_holder?.user?.firstname || ''}
                                            ${scorecard.position?.current_holder?.user?.lastname || ''}` : 'No Holder'
                                            }}
                                        </p>
                                        <p class="text-caption text-medium-emphasis mb-0">
                                            {{ scorecard.position?.current_holder?.user?.email || 'N/A' }}
                                        </p>
                                    </div>
                                </div>
                            </td>

                            <!-- Position -->
                            <td class="pa-4">
                                <div>
                                    <p class="text-body-2 font-weight-medium mb-0">
                                        {{ scorecard.position?.name || 'N/A' }}
                                    </p>
                                    <p class="text-caption text-medium-emphasis mb-0">
                                        {{ scorecard.position?.business_unit?.name || 'N/A' }}
                                    </p>
                                </div>
                            </td>

                            <!-- Period -->
                            <td class="pa-4">
                                <div>
                                    <p class="text-body-2 mb-0">{{ scorecard.performance_period?.name || 'N/A' }}</p>
                                    <p class="text-caption text-medium-emphasis mb-0">
                                        {{ scorecard.financial_year?.name || 'N/A' }}
                                    </p>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="pa-4">
                                <v-chip :color="getStatusColor(scorecard.status)" size="small" variant="tonal">
                                    <v-icon start size="14">{{ getStatusIcon(scorecard.status) }}</v-icon>
                                    {{ getStatusText(scorecard.status) }}
                                </v-chip>
                            </td>

                            <!-- Score -->
                            <td class="pa-4">
                                <div class="d-flex align-center">
                                    <v-progress-circular :model-value="scorecard.overall_score || 0"
                                        :color="scorecard.overall_score >= 80 ? 'success' : scorecard.overall_score >= 60 ? 'warning' : 'error'"
                                        size="32" width="4" class="mr-2">
                                        <span class="text-caption">{{ Math.round(scorecard.overall_score || 0) }}</span>
                                    </v-progress-circular>
                                    <!-- <span class="text-body-2">{{ (scorecard.overall_score || 0).toFixed(1) }}%</span> -->
                                </div>
                            </td>

                            <!-- Submitted Date -->
                            <td class="pa-4">
                                <span class="text-body-2">{{ formatDate(scorecard.submitted_at) }}</span>
                            </td>

                            <!-- Actions -->
                            <td class="pa-4 text-center" @click.stop>
                                <v-btn color="primary" variant="tonal" size="small" @click="viewScorecard(scorecard.id)"
                                    prepend-icon="$eye">
                                    Review
                                </v-btn>
                            </td>
                        </tr>
                    </tbody>
                </v-table>

                <!-- Pagination -->
                <v-card-text v-if="scorecards.length" class="pt-4">
                    <v-row class="align-center text-center text-sm-start" justify="space-between">
                        <v-col cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                            <span class="text-medium-emphasis">
                                Showing {{ meta.from || 0 }}–{{ meta.to || 0 }}
                                of {{ meta.total || 0 }} scorecards
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

.cursor-pointer {
    cursor: pointer;
}

.cursor-pointer:hover {
    background-color: rgba(var(--v-theme-primary), 0.04);
}
</style>