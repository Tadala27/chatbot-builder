<template>
    <div v-if="isLoading" class="d-flex justify-center align-center" style="min-height: 400px;">
        <VProgressCircular indeterminate color="primary" size="64" />
    </div>

    <div v-else>
        <VRow class="mt-0">
            <!-- Header -->
            <VCol cols="12" class="mb-2">
                <div class="d-flex justify-space-between align-center">
                    <div>
                        <h3 class="text-h3">Super Admin Dashboard</h3>
                        <p class="text-subtitle-1 text-secondary">
                            System-wide overview and monitoring
                        </p>
                    </div>
                    <VChip color="error" variant="tonal" size="large">
                        <VIcon start>mdi-shield-crown</VIcon>
                        Super Admin
                    </VChip>
                </div>
            </VCol>

            <VCard elevation="0">
                <VRow class="pa-3">

                    <!-- ROW 1: Stats Cards -->
                    <VCol cols="12" md="7">
                        <VRow>
                            <!-- Total Tenants Card -->
                            <VCol cols="4">
                                <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                                    <VCardText class="pa-0">
                                        <div class="d-flex flex-column">
                                            <!-- Top Section: Icon & Text -->
                                            <div class="d-flex align-center pa-4">
                                                <VIcon size="24" color="black" class="mr-1">$homeCity</VIcon>
                                                <h4 class="mb-0">Tenants</h4>
                                            </div>
                                            <!-- Underline -->
                                            <VDivider />
                                            <!-- Bottom Section: Numbers -->
                                            <div class="d-flex align-end justify-space-between pa-3">
                                                <div>
                                                    <h3 class="text-h1 font-weight-regular text-black mb-0">{{
                                                        stats.total_tenants || 0 }}
                                                    </h3>
                                                </div>
                                            </div>
                                        </div>
                                    </VCardText>
                                </VCard>
                            </VCol>

                            <!-- Total Users Card -->
                            <VCol cols="4">
                                <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                                    <VCardText class="pa-0">
                                        <div class="d-flex flex-column">
                                            <!-- Top Section: Icon & Text -->
                                            <div class="d-flex align-center pa-4">
                                                <VIcon size="24" color="black" class="mr-1">$accountGroup</VIcon>
                                                <h4 class=" mb-0">Total Users</h4>
                                            </div>
                                            <!-- Underline -->
                                            <VDivider />
                                            <!-- Bottom Section: Numbers -->
                                            <div class="d-flex flex-column pa-4">

                                                <!-- System Users -->
                                                <div class="d-flex justify-space-between align-center">
                                                    <p class="mb-0">System</p>
                                                    <p class="mb-0">{{ stats.system_users || 0
                                                        }}</p>
                                                </div>

                                                <!-- Tenant Users -->
                                                <div class="d-flex justify-space-between align-center">
                                                    <p class="mb-0">Tenants</p>
                                                    <p class="mb-0">{{ stats.tenant_users || 0
                                                        }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </VCardText>
                                </VCard>
                            </VCol>

                            <!-- Active Subscriptions Card -->
                            <VCol cols="4">
                                <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                                    <VCardText class="pa-0">
                                        <div class="d-flex flex-column">
                                            <!-- Top Section: Icon & Text -->
                                            <div class="d-flex align-center pa-4">
                                                <VIcon size="24" color="black" class="mr-1">$license</VIcon>
                                                <h4 class=" mb-0">Subscriptions</h4>
                                            </div>
                                            <!-- Underline -->
                                            <VDivider />
                                            <!-- Bottom Section: Numbers -->
                                            <div class="d-flex flex-column pa-4">
                                                <!-- Active Subscriptions -->
                                                <div class="d-flex justify-space-between align-center">
                                                    <p class="mb-0">Active</p>
                                                    <p class="mb-0">{{
                                                        stats.active_subscriptions || 0 }}
                                                    </p>
                                                </div>

                                                <!-- Subscription Rate -->
                                                <div class="d-flex justify-space-between align-center">
                                                    <p class=" mb-0">Rate</p>
                                                    <p class=" mb-0">{{
                                                        stats.subscription_percentage ||
                                                        '100%' }}</p>
                                                </div>
                                            </div>
                                        </div>
                                    </VCardText>
                                </VCard>
                            </VCol>
                        </VRow>
                        <VRow>

                            <!-- ROW 2: Attention Items -->
                            <VCol cols="12">
                                <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                                    <VCardText class="pa-0">
                                        <div class="d-flex flex-column">
                                            <!-- Top Section: Icon & Text -->
                                            <div class="d-flex align-center pa-2">
                                                <VIcon size="24" color="black" class="mr-1">$information</VIcon>
                                                <h4 class="font-weight-regular mb-0">Attention</h4>
                                            </div>
                                            <!-- Underline -->
                                        </div>
                                    </VCardText>
                                    <VDivider />
                                    <VCardText v-if="attentionItems.length">
                                        <div class="d-flex align-center mb-3">
                                            <VIcon color="warning" size="24" class="me-2">$alertCircle</VIcon>
                                            <h5 class="text-h5">Items Requiring Attention</h5>
                                        </div>

                                        <VList class="py-0 bg-transparent">
                                            <VListItem v-for="(item, i) in attentionItems" :key="i"
                                                :class="{ 'border-b': i < attentionItems.length - 1 }" class="px-0">
                                                <template #prepend>
                                                    <VIcon :color="item.priority === 'high' ? 'error' : 'warning'"
                                                        size="20">
                                                        {{ item.icon }}
                                                    </VIcon>
                                                </template>

                                                <VListItemTitle>{{ item.title }}</VListItemTitle>
                                                <VListItemSubtitle>{{ item.description }}</VListItemSubtitle>

                                                <template #append>
                                                    <VBtn size="small" variant="tonal" color="primary"
                                                        @click="handleAttentionItem(item)">
                                                        View
                                                    </VBtn>
                                                </template>
                                            </VListItem>
                                        </VList>
                                    </VCardText>
                                    <VCardText v-else>
                                        <div class="d-flex align-center ga-1">
                                            <VIcon icon="$checkCircle" size="20" color="success" />

                                            <div>
                                                Everything looks good!
                                            </div>
                                        </div>
                                    </VCardText>
                                </VCard>
                            </VCol>

                        </VRow>
                    </VCol>

                    <!-- ROW 1: Recent Activity Logs (Summary) -->
                    <VCol cols="12" md="5">
                        <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1" height="100%">
                            <v-card-title class="d-flex align-center pa-2 bg-primary text-white">
                                <v-icon icon="$microscope" size="28" class="mr-1"></v-icon>
                                <h5 class="font-weight-regular mb-0 mt-1">Logs</h5>
                            </v-card-title>

                            <VCardText class="mt-3">
                                <div class="d-flex align-center justify-space-between ga-4 py-4">
                                    <!-- Left: Icon -->
                                    <VAvatar color="primary" variant="tonal" size="48">
                                        <VIcon icon="$microscope" size="24" />
                                    </VAvatar>

                                    <!-- Middle: Text -->
                                    <div class="flex-grow-1">
                                        <div class="text-h6 font-weight-medium">
                                            {{ recentLogs.length.toLocaleString() }} user activity in 2026
                                        </div>

                                        <div class="text-caption text-medium-emphasis">
                                            System and user-generated events
                                        </div>
                                    </div>
                                </div>
                                <VBtn variant="text" color="primary" density="comfortable" class="text-none">
                                    Access log →
                                </VBtn>
                            </VCardText>
                        </VCard>
                    </VCol>


                    <!-- ROW 2: System Monitoring -->
                    <VCol cols="12" md="6">
                        <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                            <VCardText class="pa-0">
                                <div class="d-flex flex-column">
                                    <!-- Top Section: Icon & Text -->
                                    <div class="d-flex align-center pa-2">
                                        <VIcon size="24" color="black" class="mr-1">$monitor</VIcon>
                                        <h4 class="font-weight-regular mb-0">System Monitor</h4>
                                    </div>
                                    <!-- Underline -->
                                </div>
                            </VCardText>
                            <VDivider />
                            <VCardText>
                                <h2 class="font-weight-regular mb-0">System Usage</h2>

                                <VueApexCharts type="line" height="300" :options="activityChartOptions"
                                    :series="activityChartSeries" />


                            </VCardText>
                        </VCard>
                    </VCol>

                    <!-- ROW 3: Storage Monitor -->
                    <VCol cols="12" md="6">
                        <VCard variant="outlined" class="bg-surface" rounded="sm" elevation="1">
                            <VCardText class="pa-0">
                                <div class="d-flex flex-column">
                                    <!-- Top Section: Icon & Text -->
                                    <div class="d-flex align-center pa-2">
                                        <VIcon size="24" color="black" class="mr-1">$server</VIcon>
                                        <h4 class="font-weight-regular mb-0">Storage Monitor</h4>
                                    </div>
                                </div>
                            </VCardText>
                            <VDivider />
                            <VCardText>
                                <!-- Donut Chart -->
                                <div class="d-flex justify-center my-4">
                                    <VueApexCharts type="donut" height="200" :options="storageChartOptions"
                                        :series="storageChartSeries" />
                                </div>

                                <!-- Storage Stats Row -->
                                <VRow class="text-center mb-4">
                                    <VCol cols="4">
                                        <div class="d-flex flex-column align-center">
                                            <VIcon size="20" class="mb-2">$circle</VIcon>
                                            <p class="text-body-2 mb-1">Total</p>
                                            <p class="text-h6 font-weight-bold mb-0">
                                                {{ formatBytes(storageData.total) }}
                                            </p>
                                        </div>
                                    </VCol>
                                    <VCol cols="4">
                                        <div class="d-flex flex-column align-center">
                                            <VIcon size="20" color="primary" class="mb-2">$circle</VIcon>
                                            <p class="text-body-2 mb-1">Used</p>
                                            <p class="text-h6 font-weight-bold mb-0 text-primary">
                                                {{ formatBytes(storageData.used) }}
                                            </p>
                                        </div>
                                    </VCol>
                                    <VCol cols="4">
                                        <div class="d-flex flex-column align-center">
                                            <VIcon size="20" color="success" class="mb-2">$circle</VIcon>
                                            <p class="text-body-2 mb-1">Available</p>
                                            <p class="text-h6 font-weight-bold mb-0 text-success">
                                                {{ formatBytes(storageData.available) }}
                                            </p>
                                        </div>
                                    </VCol>
                                </VRow>

                                <!-- Storage Breakdown Cards -->
                                <VRow>
                                    <VCol cols="6">
                                        <VCard variant="flat" class="bg-grey-lighten-4 pa-3">
                                            <div class="d-flex align-center mb-2">
                                                <VIcon size="24" class="mr-2">$imageMultiple</VIcon>
                                                <h6 class="text-subtitle-2 mb-0">Images</h6>
                                            </div>
                                            <p class="text-h5 font-weight-bold mb-0">
                                                {{ storageBreakdown.avatars_formatted }}
                                            </p>
                                        </VCard>
                                    </VCol>
                                    <VCol cols="6">
                                        <VCard variant="flat" class="bg-grey-lighten-4 pa-3">
                                            <div class="d-flex align-center mb-2">
                                                <VIcon size="24" class="mr-2">$treasureChest</VIcon>
                                                <h6 class="text-subtitle-2 mb-0">Artifacts</h6>
                                            </div>
                                            <p class="text-h5 font-weight-bold mb-0">
                                                {{ storageBreakdown.attachments_formatted }}
                                            </p>
                                        </VCard>
                                    </VCol>
                                </VRow>
                            </VCardText>
                        </VCard>
                    </VCol>
                </VRow>
            </VCard>

        </VRow>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useTheme } from 'vuetify'
import axios from 'axios'
import VueApexCharts from 'vue3-apexcharts'

const router = useRouter()
const theme = useTheme()

const isLoading = ref(true)
const stats = ref<any>({})
const recentLogs = ref<any[]>([])
const attentionItems = ref<any[]>([])
const storageByTenant = ref<any[]>([])
const activityData = ref<any>({ labels: [], data: [] })

// Activity Chart
const activityChartSeries = computed(() => [{
    name: 'System Activity',
    data: activityData.value.data || []
}])

const activityChartOptions = computed(() => ({
    chart: {
        type: 'line',
        height: 300,
        toolbar: { show: false },
        zoom: { enabled: false }
    },
    colors: [theme.current.value.colors.primary],
    stroke: {
        curve: 'smooth',
        width: 3
    },
    xaxis: {
        categories: activityData.value.labels || [],
        labels: {
            style: {
                colors: theme.current.value.colors['on-surface']
            }
        }
    },
    yaxis: {
        labels: {
            style: {
                colors: theme.current.value.colors['on-surface']
            }
        }
    },
    grid: {
        borderColor: theme.current.value.colors['on-surface'] + '20'
    },
    tooltip: {
        theme: theme.current.value.dark ? 'dark' : 'light'
    }
}))

// Helpers
const formatTimeAgo = (date: string) => {
    const seconds = Math.floor((new Date().getTime() - new Date(date).getTime()) / 1000)

    if (seconds < 60) return 'Just now'
    if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
    if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
    return `${Math.floor(seconds / 86400)}d ago`
}

const formatBytes = (bytes: number) => {
    if (bytes === 0) return '0 Bytes'
    const k = 1024
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

const getStoragePercentage = (tenant: any) => {
    return (tenant.storage_used / tenant.storage_limit) * 100
}

const getStorageColor = (percentage: number) => {
    if (percentage >= 90) return 'error'
    if (percentage >= 75) return 'warning'
    return 'success'
}

const getLogColor = (event: string) => {
    const colors: any = {
        'created': 'success',
        'updated': 'info',
        'deleted': 'error',
        'login': 'primary',
        'logout': 'secondary'
    }
    return colors[event] || 'secondary'
}

const getLogIcon = (event: string) => {
    const icons: any = {
        'created': 'mdi-plus-circle',
        'updated': 'mdi-pencil',
        'deleted': 'mdi-delete',
        'login': 'mdi-login',
        'logout': 'mdi-logout'
    }
    return icons[event] || 'mdi-information'
}

const goToActivityLogs = () => {
    router.push('/admin/activity-logs')
}

const handleAttentionItem = (item: any) => {
    if (item.route) {
        router.push(item.route)
    }
}

const storageData = ref({
    total: 107374182400, // 100 GB in bytes
    used: 0,
    available: 107374182400
})

const storageBreakdown = ref({
    avatars: 0,
    avatars_formatted: '0 MB',
    attachments: 0,
    attachments_formatted: '0 KB'
})

// Storage Donut Chart
const storageChartSeries = computed(() => [
    storageData.value.used,
    storageData.value.available
])

const storageChartOptions = computed(() => ({
    chart: {
        type: 'donut'
    },
    labels: ['Used', 'Available'],
    colors: [
        theme.current.value.colors.primary,
        theme.current.value.colors['grey-lighten-2']
    ],
    plotOptions: {
        pie: {
            donut: {
                size: '75%',
                labels: {
                    show: false
                }
            }
        }
    },
    dataLabels: {
        enabled: false
    },
    legend: {
        show: false
    },
    stroke: {
        width: 0
    },
    tooltip: {
        y: {
            formatter: (val: number) => formatBytes(val)
        }
    }
}))

// Update fetchDashboardStats function
const fetchDashboardStats = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/dashboard/stats')

        stats.value = data.data.cards?.[0] || {}
        recentLogs.value = data.data.recent_activity || []
        attentionItems.value = data.data.attention_items || []
        activityData.value = data.data.activity_chart || { labels: [], data: [] }

        // Storage data
        if (data.data.storage) {
            storageData.value = {
                total: data.data.storage.total || 107374182400,
                used: data.data.storage.used || 0,
                available: data.data.storage.available || 107374182400
            }

            if (data.data.storage.breakdown) {
                const breakdown = data.data.storage.breakdown
                storageBreakdown.value = {
                    avatars: breakdown.avatars?.size || 0,
                    avatars_formatted: breakdown.avatars?.formatted || '0 MB',
                    attachments: breakdown.attachments?.size || 0,
                    attachments_formatted: breakdown.attachments?.formatted || '0 KB'
                }
            }
        }
    } catch (error) {
        console.error('Failed to load dashboard:', error)
    } finally {
        isLoading.value = false
    }
}

onMounted(fetchDashboardStats)
</script>

<style scoped>
.border-b {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>