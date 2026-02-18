<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useTheme } from 'vuetify'
import { useCustomizerStore } from '@layouts/stores/customizer'
import axios from "axios"


const theme = useTheme()
const customizer = useCustomizerStore()

const tab = ref(0)
const isLoading = ref(true)
const statsData = ref<any>(null)
const selectedPeriod = ref('30')

// Computed values from stats
const totalTenants = computed(() => statsData.value?.tenants?.total || 0)
const activeTenants = computed(() => statsData.value?.tenants?.active || 0)
const totalUsers = computed(() => statsData.value?.users?.total || 0)
const usersWithoutTenant = computed(() => statsData.value?.users?.without_tenant || 0)
const tenantGrowthRate = computed(() => statsData.value?.tenants?.growth_rate || 0)
const userGrowthRate = computed(() => statsData.value?.users?.growth_rate || 0)
const totalConnections = computed(() => statsData.value?.assignments?.total_connections || 0)
const avgUsersPerTenant = computed(() => Math.round(statsData.value?.assignments?.avg_users_per_tenant || 0))
const totalStorage = computed(() => statsData.value?.resources?.total_storage_used || 0)

const tenantsTimelineData = computed(() =>
    statsData.value?.timeline?.tenants_by_month
        ? Object.values(statsData.value.timeline.tenants_by_month)
        : []
)

const usersTimelineData = computed(() =>
    statsData.value?.timeline?.users_by_month
        ? Object.values(statsData.value.timeline.users_by_month)
        : []
)

const timelineMonths = computed(() =>
    statsData.value?.timeline?.tenants_by_month
        ? Object.keys(statsData.value.timeline.tenants_by_month)
        : []
)

const statusDistribution = computed(() => {
    if (!statsData.value?.status_distribution) return { series: [], labels: [] }
    const dist = statsData.value.status_distribution
    return {
        series: Object.values(dist) as number[],
        labels: Object.keys(dist).map(k => k.charAt(0).toUpperCase() + k.slice(1))
    }
})

const topTenants = computed(() => statsData.value?.top_tenants || [])

// Chart series - computed to prevent mutations
const tenantChartSeries = computed(() => [{
    name: 'Tenants',
    data: tenantsTimelineData.value.slice(-12)
}])

const userChartSeries = computed(() => [{
    name: 'Users',
    data: usersTimelineData.value.slice(-7)
}])

const connectionChartSeries = computed(() => {
    const statusData = statsData.value?.status_distribution || {}
    return [{
        data: [
            { x: 'Active', y: [0, statusData.active || 0] },
            { x: 'Trial', y: [0, statusData.trial || 0] },
            { x: 'Inactive', y: [0, statusData.inactive || 0] }
        ]
    }]
})

const overviewChartSeries = computed(() => [{
    name: 'Tenants',
    data: tenantsTimelineData.value.slice(-6)
}, {
    name: 'Users',
    data: usersTimelineData.value.slice(-6)
}])

const pieChartSeries = computed(() =>
    statusDistribution.value.series.length > 0
        ? statusDistribution.value.series
        : [0, 0, 0, 0]
)

const radialChartSeries = computed(() => [
    totalTenants.value > 0
        ? Math.round((activeTenants.value / totalTenants.value) * 100)
        : 0
])

// Fetch dashboard statistics
const fetchDashboardStats = async () => {
    isLoading.value = true
    try {
        const response = await axios.get(`/api/admin/dashboard/stats?period=${selectedPeriod.value}`)
        statsData.value = response.data
    } catch (error) {
        console.error('Error fetching dashboard stats:', error)
    } finally {
        isLoading.value = false
    }
}

onMounted(() => {
    fetchDashboardStats()
})

watch(selectedPeriod, () => {
    fetchDashboardStats()
})

// Chart Options - static objects
const tenantChartOptions = {
    chart: {
        type: 'bar',
        height: 80,
        fontFamily: 'inherit',
        sparkline: { enabled: true }
    },
    dataLabels: { enabled: false },
    plotOptions: {
        bar: { borderRadius: 2, columnWidth: '80%' }
    },
    colors: ['rgba(var(--v-theme-primary), var(--v-medium-opacity))'],
    stroke: { curve: 'smooth', width: 0 },
    tooltip: {
        fixed: { enabled: false },
        x: { show: false }
    }
}

const userChartOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 80,
        fontFamily: 'inherit',
        foreColor: '#a1aab2',
        toolbar: { show: false }
    },
    colors: [theme.current.value.colors.success],
    stroke: { curve: 'smooth', width: 1 },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.4,
            opacityTo: 0.2,
            stops: [0, 100]
        }
    },
    grid: {
        show: false,
        padding: { top: -28, bottom: -15, left: -10 }
    },
    xaxis: {
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { show: false },
        tooltip: { enabled: false }
    },
    yaxis: {
        axisBorder: { show: false },
        axisTicks: { show: false },
        labels: { show: false }
    },
    dataLabels: { enabled: false },
    tooltip: {
        fixed: { enabled: false },
        x: { show: false },
        y: { title: { formatter: () => '' } }
    }
}))

const connectionChartOptions = {
    chart: {
        type: 'rangeBar',
        height: 90,
        fontFamily: 'inherit',
        foreColor: '#a1aab2',
        sparkline: { enabled: true },
        toolbar: { show: false }
    },
    colors: ['rgba(var(--v-theme-warning), var(--v-medium-opacity))'],
    plotOptions: {
        bar: { columnWidth: '30%', borderRadius: 5, horizontal: false }
    }
}

const pieChartOptions = computed(() => ({
    chart: {
        type: 'pie',
        height: 350,
        width: 350,
        fontFamily: 'inherit',
        foreColor: 'rgba(var(--v-theme-darkText), var(--v-high-opacity))'
    },
    labels: statusDistribution.value.labels.length > 0
        ? statusDistribution.value.labels
        : ['Active', 'Trial', 'Suspended', 'Inactive'],
    colors: [
        'rgba(var(--v-theme-success), var(--v-high-opacity))',
        'rgba(var(--v-theme-warning), var(--v-half-opacity))',
        'rgba(var(--v-theme-error), var(--v-high-opacity))',
        'rgba(var(--v-theme-info), var(--v-half-opacity))'
    ],
    legend: { show: false },
    responsive: [
        { breakpoint: 600, options: { chart: { width: 250, height: 250 } } }
    ]
}))

const radialChartOptions = {
    chart: {
        type: 'radialBar',
        width: 136,
        height: 150,
        offsetX: -25,
        offsetY: -10,
        fontFamily: 'inherit'
    },
    colors: ['rgba(var(--v-theme-success), var(--v-high-opacity))'],
    plotOptions: {
        radialBar: {
            offsetY: 0,
            hollow: { margin: 5, size: '60%' },
            track: {
                show: true,
                background: 'rgba(var(--v-theme-success), var(--v-high-opacity))',
                opacity: 0.3,
                strokeWidth: '50%'
            },
            dataLabels: {
                name: { show: false },
                value: {
                    formatter: (v: number) => `${v}%`,
                    fontSize: '20px',
                    show: true,
                    offsetY: 5,
                    fontWeight: 700,
                    color: 'rgba(var(--v-theme-success), var(--v-high-opacity))'
                }
            }
        }
    },
    grid: { padding: { right: -50, left: -35 } },
    legend: { show: false }
}

const overviewTabChartOptions = computed(() => ({
    chart: {
        type: 'bar',
        height: 250,
        fontFamily: 'inherit',
        foreColor: 'rgba(var(--v-theme-secondary), var(--v-high-opacity))',
        toolbar: { show: false }
    },
    colors: [
        'rgba(var(--v-theme-primary), var(--v-high-opacity))',
        'rgba(var(--v-theme-darkprimary), var(--v-half-opacity))'
    ],
    dataLabels: { enabled: false },
    plotOptions: {
        bar: {
            horizontal: false,
            columnWidth: '55%',
            borderRadius: 4,
            borderRadiusApplication: 'end'
        }
    },
    stroke: { show: true, width: 3, colors: ['transparent'] },
    fill: { opacity: [1, 0.5] },
    grid: {
        borderColor: 'rgba(var(--v-theme-borderLight), var(--v-high-opacity))',
        strokeDashArray: 4,
        padding: { bottom: -10 }
    },
    xaxis: {
        categories: timelineMonths.value.slice(-6),
        axisBorder: { show: false },
        axisTicks: { show: false }
    },
    legend: {
        show: true,
        position: 'top',
        horizontalAlign: 'left'
    }
}))

// Utility functions
const formatNumber = (num: number) => new Intl.NumberFormat().format(num)
const formatStorage = (gb: number) => `${gb.toFixed(2)} GB`

const getStatusColor = (status: string) => {
    switch (status) {
        case 'active': return 'success'
        case 'trial': return 'warning'
        case 'suspended': return 'error'
        case 'inactive': return 'secondary'
        default: return 'secondary'
    }
}
</script>

<template>
    <v-container v-if="isLoading" class="d-flex justify-center align-center" style="min-height: 400px;">
        <v-progress-circular indeterminate color="primary" size="64" />
    </v-container>

    <VRow v-else class="mt-0">
        <!-- Period Selector -->
        <VCol cols="12" class="d-flex justify-end mb-n4">
            <VAutocomplete v-model="selectedPeriod" :items="[
                { title: 'Last 7 Days', value: '7' },
                { title: 'Last 30 Days', value: '30' },
                { title: 'Last 90 Days', value: '90' },
            ]" item-title="title" item-value="value" label="Period" density="compact" variant="outlined" hide-details
                style="max-width: 200px" />
        </VCol>

        <!-- Total Tenants -->
        <VCol cols="12" lg="4" md="6">
            <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                <VCardText>
                    <div class="d-flex justify-space-between align-center mb-5">
                        <h5 class="text-h5 mb-0">Total Tenants</h5>
                        <VAvatar size="40" variant="tonal" color="primary" rounded="md">
                            <SvgSprite name="custom-home-trending-outline" style="width: 20px; height: 20px" />
                        </VAvatar>
                    </div>
                    <VueApexCharts type="bar" height="80" :options="tenantChartOptions" :series="tenantChartSeries" />
                    <div class="d-flex ga-2 justify-center py-4">
                        <h6 class="text-subtitle-1 mb-0">{{ formatNumber(totalTenants) }}</h6>
                        <p class="text-body-1 mb-0" :class="tenantGrowthRate >= 0 ? 'text-success' : 'text-error'">
                            <SvgSprite :name="tenantGrowthRate >= 0 ? 'custom-rise-outline' : 'custom-fall-outline'"
                                style="width: 16px; height: 16px; transform: rotate(45deg)" />
                            {{ Math.abs(tenantGrowthRate) }}%
                        </p>
                    </div>
                    <VBtn color="secondary" variant="outlined" rounded="md" block to="/admin/tenants">View All</VBtn>
                </VCardText>
            </VCard>
        </VCol>

        <!-- Total Users -->
        <VCol cols="12" lg="4" md="6">
            <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                <VCardText>
                    <div class="d-flex justify-space-between align-center mb-5">
                        <h5 class="text-h5 mb-0">Total Users</h5>
                        <VAvatar size="40" variant="tonal" color="success" rounded="md">
                            <SvgSprite name="custom-user-outline" style="width: 20px; height: 20px" />
                        </VAvatar>
                    </div>
                    <VueApexCharts type="area" height="80" :options="userChartOptions" :series="userChartSeries" />
                    <div class="d-flex ga-2 justify-center pt-1 pb-4">
                        <h6 class="text-subtitle-1 mb-0">{{ formatNumber(totalUsers) }}</h6>
                        <p class="text-body-1 mb-0" :class="userGrowthRate >= 0 ? 'text-success' : 'text-error'">
                            <SvgSprite :name="userGrowthRate >= 0 ? 'custom-rise-outline' : 'custom-fall-outline'"
                                style="width: 16px; height: 16px; transform: rotate(45deg)" />
                            {{ Math.abs(userGrowthRate) }}%
                        </p>
                    </div>
                    <VBtn color="secondary" variant="outlined" rounded="md" block to="/admin/users">View All</VBtn>
                </VCardText>
            </VCard>
        </VCol>

        <!-- Unassigned Users -->
        <VCol cols="12" lg="4" md="6">
            <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                <VCardText>
                    <div class="d-flex justify-space-between align-center mb-5">
                        <h5 class="text-h5 mb-0">Unassigned Users</h5>
                        <VAvatar size="40" variant="tonal" color="warning" rounded="md">
                            <SvgSprite name="custom-alert-outline" style="width: 20px; height: 20px" />
                        </VAvatar>
                    </div>
                    <VueApexCharts type="rangeBar" height="90" :options="connectionChartOptions"
                        :series="connectionChartSeries" />
                    <div class="d-flex ga-2 justify-center pt-1 pb-4">
                        <h6 class="text-subtitle-1 mb-0">{{ formatNumber(usersWithoutTenant) }}</h6>
                        <p class="text-body-1 text-lightText mb-0">users</p>
                    </div>
                    <VBtn color="warning" variant="outlined" rounded="md" block to="/admin/users/without-tenants">
                        Assign Tenants
                    </VBtn>
                </VCardText>
            </VCard>
        </VCol>

        <!-- Overview Tabs -->
        <VCol cols="12">
            <VCard variant="outlined" class="bg-surface" rounded="lg">
                <VCardItem class="py-0">
                    <VTabs v-model="tab" color="primary" height="58">
                        <VTab value="0" class="mr-1 py-6" rounded="0">Overview</VTab>
                        <VTab value="1" class="py-6" rounded="0">Tenants</VTab>
                        <VTab value="2" class="py-6" rounded="0">Users</VTab>
                        <VTab value="3" class="py-6" rounded="0">Activity</VTab>
                    </VTabs>
                </VCardItem>
                <VDivider />
                <VCardText class="rounded-md overflow-hidden">
                    <VRow>
                        <VCol cols="12" md="8" sm="6" class="pb-sm-3 pb-0">
                            <VWindow v-model="tab">
                                <VWindowItem value="0">
                                    <VueApexCharts type="bar" height="250" :options="overviewTabChartOptions"
                                        :series="overviewChartSeries" />
                                </VWindowItem>
                                <VWindowItem value="1">
                                    <VueApexCharts type="bar" height="250" :options="overviewTabChartOptions"
                                        :series="[{ name: 'Tenants', data: tenantsTimelineData.slice(-6) }]" />
                                </VWindowItem>
                                <VWindowItem value="2">
                                    <VueApexCharts type="bar" height="250" :options="overviewTabChartOptions"
                                        :series="[{ name: 'Users', data: usersTimelineData.slice(-6) }]" />
                                </VWindowItem>
                                <VWindowItem value="3">
                                    <VueApexCharts type="bar" height="250" :options="overviewTabChartOptions"
                                        :series="overviewChartSeries" />
                                </VWindowItem>
                            </VWindow>
                        </VCol>

                        <VCol cols="12" md="4" sm="6" class="pt-sm-3 pt-0">
                            <VList border class="py-0">
                                <VListItem v-for="(item, index) in [
                                    { label: 'Active Tenants', value: activeTenants, color: 'success', icon: 'custom-home-trending-outline' },
                                    { label: 'Trial Tenants', value: statsData?.tenants?.trial || 0, color: 'warning', icon: 'custom-clock-outline' },
                                    { label: 'Suspended', value: statsData?.tenants?.suspended || 0, color: 'error', icon: 'custom-alert-outline' },
                                    { label: 'Inactive Tenants', value: statsData?.tenants?.inactive || 0, color: 'secondary', icon: 'custom-user-outline' }
                                ]" :key="index" class="py-5">
                                    <template #prepend>
                                        <VAvatar size="40" :color="item.color" rounded="md" variant="tonal">
                                            <SvgSprite :name="item.icon" style="width: 20px; height: 20px" />
                                        </VAvatar>
                                    </template>
                                    <div class="text-start">
                                        <p class="text-body-1 mb-0 text-lightText">{{ item.label }}</p>
                                        <h6 class="text-subtitle-1 ml-auto mb-0">{{ formatNumber(item.value) }}</h6>
                                    </div>
                                </VListItem>
                            </VList>
                        </VCol>
                    </VRow>
                </VCardText>
            </VCard>
        </VCol>

        <!-- Status Pie -->
        <VCol cols="12" xl="6" md="6">
            <VCard variant="outlined" class="bg-surface" rounded="lg">
                <VCardText>
                    <div class="d-flex justify-space-between align-center">
                        <h5 class="text-h5 mb-0">Tenant Status Distribution</h5>
                    </div>
                </VCardText>
                <VCardItem class="pa-0 chart-visible">
                    <div class="apexchart-center">
                        <VueApexCharts v-if="statusDistribution.series.length > 0" type="pie" height="350"
                            :options="pieChartOptions" :series="pieChartSeries" />
                        <div v-else class="text-center py-8">
                            <p class="text-body-1 text-lightText mb-0">No data available</p>
                        </div>
                    </div>
                    <VRow v-if="statusDistribution.labels.length > 0" class="mt-2 mx-0 mb-0 px-3 pb-3">
                        <VCol v-for="(label, index) in statusDistribution.labels" :key="index" cols="6">
                            <VCard variant="outlined" rounded="lg">
                                <VCardItem class="py-3">
                                    <div class="d-flex justify-center">
                                        <VAvatar size="6" :color="getStatusColor(label.toLowerCase())" variant="flat"
                                            class="mt-2 me-3" />
                                        <div>
                                            <p class="text-body-1 mb-0">{{ label }}</p>
                                            <h6 class="text-subtitle-1 mb-0">{{
                                                formatNumber(statusDistribution.series[index] ||
                                                    0) }}</h6>
                                        </div>
                                    </div>
                                </VCardItem>
                            </VCard>
                        </VCol>
                    </VRow>
                </VCardItem>
            </VCard>
        </VCol>

        <!-- Radial + Top Tenants -->
        <VCol cols="12" xl="6" md="6">
            <VRow>
                <VCol cols="12">
                    <VCard variant="outlined" class="bg-surface" rounded="lg">
                        <VCardText class="py-5 d-flex align-center">
                            <VueApexCharts type="radialBar" height="150" width="150" :options="radialChartOptions"
                                :series="radialChartSeries" />
                            <div class="ms-4">
                                <p class="text-body-1 mb-0">Active Tenant Rate</p>
                                <h6 class="text-subtitle-1 mb-0">{{ activeTenants }} of {{ totalTenants }}</h6>
                            </div>
                        </VCardText>
                    </VCard>
                </VCol>

                <VCol cols="12">
                    <VCard variant="outlined" class="bg-surface" rounded="lg">
                        <VCardText class="pb-2 d-flex justify-space-between align-center">
                            <h5 class="text-h5 mb-0">Top Tenants</h5>
                        </VCardText>
                        <VCardItem class="pa-0">
                            <VList border rounded="lg" class="py-0" style="max-height: 300px; overflow-y: auto;">
                                <VListItem v-for="(tenant, i) in topTenants" :key="i" class="py-2 px-6">
                                    <template #prepend>
                                        <VAvatar size="40" color="primary" rounded="md" variant="tonal">
                                            <span class="text-subtitle-2">{{ tenant.name?.charAt(0) }}</span>
                                        </VAvatar>
                                    </template>
                                    <span class="text-body-1 mb-0">{{ tenant.name }}</span>
                                    <p class="text-caption mb-0">{{ tenant.users_count }} users</p>
                                    <template #append>
                                        <VChip :color="getStatusColor(tenant.status)" variant="tonal" size="small"
                                            rounded="md">
                                            {{ tenant.status }}
                                        </VChip>
                                    </template>
                                </VListItem>
                                <VListItem v-if="topTenants.length === 0" class="py-6 text-center">
                                    <p class="text-body-1 text-lightText mb-0">No tenants found</p>
                                </VListItem>
                            </VList>
                        </VCardItem>
                    </VCard>
                </VCol>
            </VRow>
        </VCol>
    </VRow>
</template>

<style lang="scss">
.chart-visible {
    .v-card-item__content {
        overflow: visible;
    }
}

.radial-widget {
    flex-shrink: 0;
}

.apexchart-center {
    display: flex;
    justify-content: center;
    align-items: center;
}
</style>