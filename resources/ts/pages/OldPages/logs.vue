<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from "axios"

import moment from 'moment'
import Swal from 'sweetalert2'

const isLoading = ref(true)
const logs = ref<any[]>([])
const meta = ref<any>({})
const searchQuery = ref('')
const filters = ref({
    log_name: '',
    causer_id: null as number | null,
    subject_type: '',
    date_from: '',
    date_to: ''
})
const filterOptions = ref({
    log_names: [],
    causers: [],
    subject_types: []
})
const snackbar = ref({
    show: false,
    message: '',
    color: 'error', // default
    timeout: 4000
})

const showSnackbar = (message: string, color = 'error', timeout = 4000) => {
    snackbar.value = { show: true, message, color, timeout }
}

const currentPage = ref(1)
const perPage = ref(10)

// Fetch logs
const fetchLogs = async () => {
    try {
        const params = {
            page: currentPage.value,
            per_page: perPage.value,
            search: searchQuery.value || undefined,
            ...filters.value
        }

        const { data } = await axios.get('/api/activity-logs', { params })
        logs.value = data.data
        meta.value = data.meta

        isLoading.value = false
    } catch (error: any) {

        if (error.response?.status === 403) {
            Swal.fire({
                title: 'Access Denied',
                text: error.response?.data?.message || 'You do not have permission to view these logs.',
                icon: 'error',
                timer: 2000,
                showConfirmButton: false,
                timerProgressBar: true
            }).then(() => {
                window.history.back()
            })
            return
        }

        const msg = error.response?.data?.message || 'Failed to load activity logs'
        showSnackbar(msg, 'error')

    }
}

const isSearchLoading = ref(false)
let debounceTimer: ReturnType<typeof setTimeout> | null = null

const debouncedFetchLogs = () => {
    if (debounceTimer) clearTimeout(debounceTimer)

    isSearchLoading.value = true

    debounceTimer = setTimeout(() => {
        currentPage.value = 1
        fetchLogs().finally(() => {
            isSearchLoading.value = false
        })
    }, 3000)
}

onUnmounted(() => {
    if (debounceTimer) clearTimeout(debounceTimer)
})
// In <script setup>
const formatLogName = (value: string): string => {
    if (!value) return ''
    return value
        .split('_')
        .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
        .join(' ')
}

const fetchFilters = async () => {
    const { data } = await axios.get('/api/activity-logs/filters')

    const formattedLogNames = (data.log_names || []).map((name: string) => ({
        title: formatLogName(name),
        value: name
    }))

    filterOptions.value = {
        ...data,
        log_names: formattedLogNames
    }
}
// Reset filters
const clearFilters = () => {
    searchQuery.value = ''
    filters.value = {
        log_name: '',
        causer_id: null,
        subject_type: '',
        date_from: '',
        date_to: ''
    }
    currentPage.value = 1
    fetchLogs()
}

// Format properties
const formatProperties = (props: any) => {
    if (!props) return ''
    try {
        const parsed = typeof props === 'string' ? JSON.parse(props) : props
        if (parsed.attributes && parsed.old) {
            const changes = []
            for (const key in parsed.attributes) {
                if (parsed.old[key] !== parsed.attributes[key]) {
                    changes.push(`${key}: ${parsed.old[key]} → ${parsed.attributes[key]}`)
                }
            }
            return changes.join('; ')
        }
        return JSON.stringify(parsed)
    } catch {
        return String(props)
    }
}
// Add this function inside <script setup>
const truncate = (text: string, length: number) => {
    if (!text) return ''
    return text.length <= length ? text : text.slice(0, length) + '...'
}
// Extract initials from full name string
const getInitials = (name: string | null | undefined): string => {
    if (!name) return '?'

    const parts = name.trim().split(/\s+/)
    const first = parts[0]?.[0] || ''
    const last = parts.length > 1 ? parts[parts.length - 1]?.[0] : ''
    return (first + last).toUpperCase() || '?'
}
const onSearchClear = () => {
    searchQuery.value = ''
    currentPage.value = 1

    // Cancel pending debounce
    if (debounceTimer) {
        clearTimeout(debounceTimer)
        debounceTimer = null
    }

    // Reset loading state
    isSearchLoading.value = false

    // Fetch immediately with empty search
    fetchLogs()
}
onMounted(() => {
    fetchFilters()
    fetchLogs()
})
</script>

<template>
    <div>
        <v-container v-if="isLoading" class="d-flex justify-center py-8">
            <v-progress-circular indeterminate color="primary" size="64"></v-progress-circular>
        </v-container>

        <div v-else>
            <div class="d-flex justify-space-between align-center mb-5">
                <div>
                    <h1 class="text-h4">Activity Logs</h1>
                    <p class="text-subtitle-1 text-medium-emphasis">Audit trail of all system actions</p>
                </div>
            </div>

            <v-row>
                <v-col cols="12" md="4">
                    <div class="position-relative">
                        <v-text-field v-model="searchQuery" label="Search logs..." prepend-inner-icon="$magnify"
                            variant="outlined" clearable :loading="isSearchLoading" @input="debouncedFetchLogs()"
                            @click:clear="onSearchClear" />
                    </div>
                </v-col>
                <v-col cols="12" md="3">
                    <v-autocomplete v-model="filters.log_name" :items="filterOptions.log_names" item-title="title"
                        item-value="value" label="Log Name" variant="outlined" clearable
                        @update:model-value="currentPage = 1; fetchLogs()" />
                </v-col>
                <v-col cols="12" md="3">
                    <v-autocomplete v-model="filters.causer_id" :items="filterOptions.causers" item-title="name"
                        item-value="id" label="Causer" variant="outlined" clearable
                        @update:model-value="currentPage = 1; fetchLogs()" />
                </v-col>
                <v-col cols="12" md="2" class="mt-2">
                    <v-btn variant="text" @click="clearFilters" size="small">Clear</v-btn>
                </v-col>
            </v-row>

            <!-- Table -->
            <v-table density="comfortable" class="bordered-table">
                <thead class="bg-gray text-uppercase">
                    <tr>
                        <th class="text-left pa-3">Action</th>
                        <th class="text-left pa-3">By</th>
                        <th class="text-left pa-3">On</th>
                        <th class="text-left pa-3">Changes</th>
                        <th class="text-left pa-3">When</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!logs.length">
                        <td colspan="5" class="text-center py-8">
                            <v-icon size="64" color="grey-lighten-1">$fileDocumentOutline</v-icon>
                            <h3 class="text-h6 text-grey mt-4">No logs found</h3>
                        </td>
                    </tr>
                    <tr v-for="log in logs" :key="log.id">
                        <!-- Action (Description) -->
                        <td class="pa-3">
                            <v-tooltip location="top">
                                <template #activator="{ props }">
                                    <p v-bind="props" class="text-body-2 mb-0 text-medium-emphasis cursor-pointer"
                                        :title="log.description">
                                        {{ truncate(log.description, 60) }}
                                    </p>
                                </template>
                                <span>{{ log.description }}</span>
                            </v-tooltip>
                        </td>
                        <td class="pa-3">
                            <div class="d-flex align-center">
                                <!-- Avatar: Image or Initials -->
                                <v-avatar size="32" :color="log.causer?.avatar ? undefined : 'primary'" class="me-2">
                                    <v-img v-if="log.causer?.avatar" :src="log.causer.avatar" cover />
                                    <span v-else class="text-white font-weight-medium" style="font-size: 0.75rem;">
                                        {{ getInitials(log.causer_name) }}
                                    </span>
                                </v-avatar>

                                <!-- Name -->
                                <span class="text-body-2">{{ log.causer_name }}</span>
                            </div>
                        </td>
                        <td class="pa-3">
                            <code class="text-caption">{{ log.subject_type_short }}</code>
                            <br>
                            <small>{{ log.subject_name }}</small>
                        </td>
                        <td class="pa-3">
                            <div class="text-caption text-medium-emphasis"
                                style="max-width: 300px; white-space: pre-wrap;">
                                {{ log.changes || '—' }}
                            </div>
                        </td>
                        <td class="pa-3 text-caption">
                            {{ log.formatted_date }}
                        </td>
                    </tr>
                </tbody>
            </v-table>

            <v-card-text class="pt-4">
                <VRow class="align-center text-center text-sm-start" justify="space-between">

                    <!-- Left: Info -->
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                        <span class="text-medium-emphasis">
                            Showing {{ meta.from }} to {{ meta.to }} of {{ meta.total }} logs
                        </span>
                    </VCol>

                    <!-- Right: Pagination -->
                    <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
                        <v-pagination v-model="currentPage" :length="meta.last_page" :total-visible="5"
                            @update:model-value="fetchLogs" rounded="circle" density="comfortable" color="primary"
                            variant="outlined" />
                    </VCol>

                </VRow>
            </v-card-text>


        </div>
    </div>
    <!-- Snackbar for alerts -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="snackbar.timeout" location="top right"
        transition="slide-y-transition">
        {{ snackbar.message }}
        <template #actions>
            <v-btn variant="text" @click="snackbar.show = false">
                Close
            </v-btn>
        </template>
    </v-snackbar>
</template>

<style scoped>
.bordered-table {
    border: 1px solid var(--v-theme-border);
}

.bg-gray {
    background-color: rgb(245, 245, 245);
}

.cursor-pointer {
    cursor: pointer;
}

.text-body-2 {
    line-height: 1.4;
}
</style>