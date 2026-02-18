<template>
    <v-container v-if="isLoading" class="d-flex justify-center align-center" style="min-height: 400px;">
        <v-progress-circular indeterminate color="primary" size="64" />
    </v-container>
    <div v-else>
        <VRow class="mt-0">
            <!-- Header with FY Selector -->
            <VCol cols="12" class="mb-2">
                <div class="d-flex justify-space-between align-center">
                    <div>
                        <h3 class="text-h3">My Dashboard</h3>
                        <p class="text-subtitle-1 text-secondary">
                            Welcome back, {{ shareholderName }}!
                        </p>
                    </div>
                    <div class="d-flex gap-3 align-center">
                        <!-- Financial Year Selector -->
                        <v-select v-model="selectedFY" :items="financialYears" item-title="title" item-value="value"
                            label="Financial Year" variant="outlined" density="compact" hide-details
                            style="min-width: 200px;" prepend-inner-icon="$calendar" color="primary" />
                        <VChip :color="statusColor" variant="tonal" size="large">
                            <v-icon start>${{ statusIcon }}</v-icon>
                            {{ shareholderStatus }}
                        </VChip>
                    </div>
                </div>
            </VCol>

            <!-- Dividend Info Card (if exists for FY) -->
            <VCol v-if="stats.dividend" cols="12">
                <v-card variant="outlined" :color="stats.dividend.status === 'Paid' ? 'success' : 'warning'">
                    <v-card-text class="py-3">
                        <v-row class="align-center">
                            <v-col cols="12" md="2">
                                <div class="d-flex align-center gap-2">
                                    <v-icon :color="stats.dividend.status === 'Paid' ? 'success' : 'warning'" size="32">
                                        $currencyUsd
                                    </v-icon>
                                    <div>
                                        <p class="text-caption text-medium-emphasis mb-0">Dividend Status</p>
                                        <h6 class="text-h6 mb-0">{{ stats.dividend.status }}</h6>
                                    </div>
                                </div>
                            </v-col>
                            <v-col cols="12" md="2">
                                <p class="text-caption text-medium-emphasis mb-1">Gross Amount</p>
                                <p class="text-subtitle-2 font-weight-bold mb-0">{{
                                    formatCurrency(stats.dividend.declared_amount) }}</p>
                            </v-col>
                            <v-col cols="12" md="2">
                                <p class="text-caption text-medium-emphasis mb-1">Tax Withheld</p>
                                <p class="text-subtitle-2 font-weight-bold text-error mb-0">{{
                                    formatCurrency(stats.dividend.declared_amount - stats.dividend.net_amount) }}</p>
                            </v-col>
                            <v-col cols="12" md="2">
                                <p class="text-caption text-medium-emphasis mb-1">Net Amount</p>
                                <p class="text-subtitle-2 font-weight-bold text-success mb-0">{{
                                    formatCurrency(stats.dividend.net_amount) }}</p>
                            </v-col>
                            <v-col cols="12" md="3">
                                <p class="text-caption text-medium-emphasis mb-1">
                                    {{ stats.dividend.status === 'Paid' ? 'Paid On' : 'Payment Due' }}
                                </p>
                                <p class="text-subtitle-2 mb-0">{{ formatDate(stats.dividend.payment_date) }}</p>
                            </v-col>
                            <v-col cols="12" md="1" class="text-right">
                            </v-col>
                        </v-row>
                    </v-card-text>
                </v-card>
            </VCol>

            <!-- Graph Stats Cards Row -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center mb-5">
                            <h5 class="text-h5 mb-0">My Contributions</h5>
                            <VAutocomplete v-model="contributionPeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="area" height="80" :options="contributionChartOptions"
                            :series="contributionChartSeries" />
                        <div class="d-flex ga-2 justify-center pt-1 pb-4">
                            <h6 class="text-subtitle-1 mb-0">
                                {{ formatCurrency(stats.total_contributions || 0) }}
                            </h6>
                            <p :class="`text-body-1 mb-0 ${getGrowthColor(stats.contribution_growth)}`">
                                <v-icon size="16">{{ getGrowthIcon(stats.contribution_growth) }}</v-icon>
                                {{ formatGrowth(stats.contribution_growth) }}
                            </p>
                        </div>
                        <VBtn color="secondary" variant="outlined" rounded="md" block to="/payments">
                            View History
                        </VBtn>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Pending Invoices -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center mb-5">
                            <h5 class="text-h5 mb-0">Pending Invoices</h5>
                            <VAutocomplete v-model="invoicePeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="bar" height="80" :options="invoiceChartOptions"
                            :series="invoiceChartSeries" />
                        <div class="d-flex ga-2 justify-center py-4">
                            <h6 class="text-subtitle-1 mb-0">
                                {{ formatCurrency(totalPendingAmount) }}
                            </h6>
                            <p class="text-body-1 text-warning mb-0">
                                <v-icon size="16">mdi-file-document</v-icon>
                                {{ pendingInvoices.length }} invoices
                            </p>
                        </div>
                        <VBtn color="warning" variant="outlined" rounded="md" block to="/invoices">
                            View Invoices
                        </VBtn>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Gradient Widget Cards Row -->
            <VCol v-for="(card, i) in widgetCards" :key="i" cols="12" md="4">
                <VCard elevation="0" rounded="lg" :class="`bg-${card.color} widget-gradient overflow-hidden`">
                    <VCard variant="outlined" class="overflow-hidden" rounded="lg">
                        <VCardText class="px-md-9 py-md-8 px-5 py-5">
                            <div class="d-flex justify-space-between">
                                <span class="d-flex">
                                    <SvgSprite :name="card.icon || ''" style="width: 52px; height: 52px"
                                        class="text-white opacity-50" />
                                </span>
                                <div class="text-end">
                                    <h5 class="text-h5 font-weight-medium mb-2 text-white">
                                        {{ card.title }}
                                    </h5>
                                    <h3 class="text-h3 mb-4 text-white">
                                        {{ card.value }}
                                    </h3>
                                    <span class="opacity-80 text-h6 font-weight-regular text-white">{{ card.subtitle
                                    }}</span>
                                </div>
                            </div>
                        </VCardText>
                    </VCard>
                </VCard>
            </VCol>

            <!-- Account Overview Card with Tabs -->
            <VCol cols="12">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardItem class="py-0">
                        <VTabs v-model="accountTab" color="primary" height="58">
                            <VTab value="activity" class="mr-1 py-6" rounded="0">
                                Payment Activity
                            </VTab>
                            <VTab value="summary" class="py-6" rounded="0">
                                Account Summary
                            </VTab>
                        </VTabs>
                    </VCardItem>
                    <VDivider />
                    <VCardText class="rounded-sm border-0 overflow-hidden">
                        <VRow>
                            <VCol cols="12">
                                <VRow>
                                    <!-- Left side: Chart/Content -->
                                    <VCol cols="12" md="8" sm="6" class="pb-sm-3 pb-0">
                                        <div class="d-flex justify-end ga-2 align-center mb-4">
                                            <VAutocomplete v-model="activityPeriod" :items="periodOptions"
                                                color="primary" variant="outlined" hide-details density="compact"
                                                style="max-width: 120px" />
                                        </div>
                                        <VWindow v-model="accountTab">
                                            <!-- Payment Activity Tab -->
                                            <VWindowItem value="activity">
                                                <VueApexCharts type="bar" height="250" :options="activityChartOptions"
                                                    :series="activityChartSeries" />
                                            </VWindowItem>
                                            <!-- Account Summary Tab -->
                                            <VWindowItem value="summary">
                                                <VueApexCharts type="area" height="250" :options="summaryChartOptions"
                                                    :series="summaryChartSeries" />
                                            </VWindowItem>
                                        </VWindow>
                                    </VCol>

                                    <!-- Right side: Summary List -->
                                    <VCol cols="12" md="4" sm="6" class="pt-sm-3 pt-0">
                                        <VList border aria-label="overview list" class="py-0 border-0">
                                            <VListItem class="py-5">
                                                <template #prepend>
                                                    <VAvatar size="40" color="secondary" rounded="md" variant="tonal">
                                                        <SvgSprite name="custom-user-outline"
                                                            style="width: 20px; height: 20px" />
                                                    </VAvatar>
                                                </template>
                                                <div class="text-start">
                                                    <p class="text-body-1 mb-0 text-lightText">Member Number</p>
                                                    <h6 class="text-subtitle-1 ml-auto mb-0">{{ memberNumber }}</h6>
                                                </div>
                                                <template #append>
                                                    <VChip :color="statusColor" size="small" variant="tonal">
                                                        {{ shareholderStatus }}
                                                    </VChip>
                                                </template>
                                            </VListItem>

                                            <VListItem class="py-5 border-top">
                                                <template #prepend>
                                                    <VAvatar size="40" color="secondary" rounded="md" variant="tonal">
                                                        <SvgSprite name="custom-graph-outline"
                                                            style="width: 20px; height: 20px" />
                                                    </VAvatar>
                                                </template>
                                                <div class="text-start">
                                                    <p class="text-body-1 mb-0 text-lightText">Share Units</p>
                                                    <h6 class="text-subtitle-1 ml-auto mb-0">
                                                        {{ stats.share_units || 0 }}
                                                    </h6>
                                                </div>
                                                <template #append>
                                                    <div class="text-end">
                                                        <p
                                                            :class="`text-caption mb-0 ${getGrowthColor(stats.share_growth)}`">
                                                            <v-icon size="14">{{ getGrowthIcon(stats.share_growth)
                                                            }}</v-icon>
                                                            {{ formatGrowth(stats.share_growth) }}
                                                        </p>
                                                    </div>
                                                </template>
                                            </VListItem>

                                            <VListItem class="py-5 border-top">
                                                <template #prepend>
                                                    <VAvatar size="40" color="secondary" rounded="md" variant="tonal">
                                                        <SvgSprite name="custom-home-trending-outline"
                                                            style="width: 20px; height: 20px" />
                                                    </VAvatar>
                                                </template>
                                                <div class="text-start">
                                                    <p class="text-body-1 mb-0 text-lightText">Total Contributions</p>
                                                    <h6 class="text-subtitle-1 ml-auto mb-0">
                                                        {{ formatCurrency(stats.total_contributions || 0) }}
                                                    </h6>
                                                </div>
                                                <template #append>
                                                    <div>
                                                        <h6 class="text-subtitle-1 mb-0 text-end">
                                                            {{ formatGrowth(stats.contribution_growth) }}
                                                        </h6>
                                                        <p :class="`mb-0 ${getGrowthColor(stats.contribution_growth)}`">
                                                            <SvgSprite :name="getGrowthIcon(stats.contribution_growth)"
                                                                style="width: 14px; height: 14px; vertical-align: -3px;transform:rotate(45deg)" />
                                                        </p>
                                                    </div>
                                                </template>
                                            </VListItem>

                                            <VListItem class="py-5 border-top">
                                                <template #prepend>
                                                    <VAvatar size="40" color="secondary" rounded="md" variant="tonal">
                                                        <SvgSprite name="custom-calendar-outline"
                                                            style="width: 20px; height: 20px" />
                                                    </VAvatar>
                                                </template>
                                                <div class="text-start">
                                                    <p class="text-body-1 mb-0 text-lightText">Member Since</p>
                                                    <h6 class="text-subtitle-1 ml-auto mb-0">{{ memberSince }}</h6>
                                                </div>
                                                <template #append>
                                                    <div class="text-end">
                                                        <h6 class="text-subtitle-1 mb-0">
                                                            {{ calculateMembershipYears() }}
                                                        </h6>
                                                        <p class="text-caption mb-0 text-secondary">years</p>
                                                    </div>
                                                </template>
                                            </VListItem>
                                        </VList>
                                    </VCol>
                                </VRow>
                            </VCol>
                        </VRow>
                    </VCardText>
                </VCard>
            </VCol>
        </VRow>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useUserStore } from '@/stores/user'
import { useTheme } from 'vuetify'
import axios from "axios"

const theme = useTheme()
const userStore = useUserStore()
const isLoading = ref(true)

// Financial Year
const selectedFY = ref<number | null>(null)
const financialYears = ref<any[]>([])

const stats = ref<any>({})
const recentPayments = ref<any[]>([])
const pendingInvoices = ref<any[]>([])
const shareholder = ref<any>(null)

const chartData = ref<any>({
    contributions_trend: { labels: [], data: [] },
    invoices_trend: { labels: [], data: [] },
    activity_trend: { labels: [], data: [] },
})

const accountTab = ref('activity')

// Period selectors
const contributionPeriod = ref('Monthly')
const invoicePeriod = ref('Monthly')
const activityPeriod = ref('Monthly')
const periodOptions = ['Weekly', 'Monthly']

// Widget Cards Data
const widgetCards = computed(() => [
    {
        title: 'Share Units',
        value: stats.value.share_units || 0,
        subtitle: `${formatCurrency(stats.value.share_value || 0)} Value`,
        icon: 'custom-fatrows',
        color: 'primary'
    },
    {
        title: 'Total Subscriptions',
        value: formatCurrency(stats.value.total_subscriptions || 0),
        subtitle: 'Subscription Fees',
        icon: 'custom-dollar-fill',
        color: 'success'
    },
    {
        title: 'Membership',
        value: calculateMembershipYears(),
        subtitle: `Since ${memberSince.value}`,
        icon: 'custom-calendar-outline',
        color: 'warning'
    }
])

// Computed
const shareholderName = computed(() => {
    if (!shareholder.value) return 'Member'
    return `${shareholder.value.first_name} ${shareholder.value.last_name}`
})

const memberNumber = computed(() => shareholder.value?.member_number || '')

const memberSince = computed(() => {
    if (!shareholder.value?.date_joined) return ''
    return new Date(shareholder.value.date_joined).toLocaleDateString('en-US', { year: 'numeric', month: 'long' })
})

const shareholderStatus = computed(() => shareholder.value?.status || 'Active')

const statusColor = computed(() => {
    const status = shareholderStatus.value.toLowerCase()
    if (status === 'active') return 'success'
    if (status === 'suspended') return 'error'
    if (status === 'inactive') return 'warning'
    return 'secondary'
})

const statusIcon = computed(() => {
    const status = shareholderStatus.value.toLowerCase()
    if (status === 'active') return 'checkCircle'
    if (status === 'suspended') return 'cancel'
    if (status === 'inactive') return 'pauseCircle'
    return 'information'
})

const totalPendingAmount = computed(() => {
    return pendingInvoices.value.reduce((sum, inv) => sum + Number(inv.amount), 0)
})

// Safe chart
const safeChart = (key: string) => {
    const trend = chartData.value[key] || {}
    return {
        labels: Array.isArray(trend.labels) ? trend.labels : [],
        data: Array.isArray(trend.data) ? trend.data : []
    }
}

// Chart Series
const contributionChartSeries = computed(() => [{ name: 'Contributions', data: safeChart('contributions_trend').data }])
const invoiceChartSeries = computed(() => [{ name: 'Invoices', data: safeChart('invoices_trend').data }])
const activityChartSeries = computed(() => [{ name: 'Payments', data: safeChart('activity_trend').data }])
const summaryChartSeries = computed(() => [{ name: 'Contributions', data: safeChart('contributions_trend').data }])

// Chart Options
const contributionChartOptions = computed(() => ({
    chart: { type: 'area', height: 80, toolbar: { show: false }, sparkline: { enabled: true } },
    colors: [theme.current.value.colors.success],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
    xaxis: { categories: safeChart('contributions_trend').labels },
    yaxis: { show: false },
    grid: { show: false },
    dataLabels: { enabled: false },
    tooltip: { y: { formatter: (val: number) => formatCurrency(val * 1000) } }
}))

const invoiceChartOptions = computed(() => ({
    chart: { type: 'bar', height: 80, sparkline: { enabled: true }, toolbar: { show: false } },
    dataLabels: { enabled: false },
    plotOptions: { bar: { borderRadius: 3, columnWidth: '60%' } },
    colors: ['rgba(var(--v-theme-warning), 0.85)'],
    xaxis: { categories: safeChart('invoices_trend').labels },
    yaxis: { show: false },
    grid: { show: false },
    tooltip: { y: { formatter: (val: number) => `${val} invoices` } }
}))

const activityChartOptions = computed(() => ({
    chart: { type: 'bar', height: 250, toolbar: { show: false } },
    colors: ['rgba(var(--v-theme-primary), 1)'],
    plotOptions: { bar: { horizontal: false, columnWidth: '55%', borderRadius: 4 } },
    dataLabels: { enabled: false },
    stroke: { show: true, width: 2, colors: ['transparent'] },
    grid: { borderColor: 'rgba(var(--v-theme-borderLight), 1)', strokeDashArray: 4 },
    xaxis: { categories: safeChart('activity_trend').labels },
    yaxis: { title: { text: 'Number of Payments' } },
    tooltip: { y: { formatter: (val: number) => `${val} payments` } }
}))

const summaryChartOptions = computed(() => ({
    chart: { type: 'area', height: 250, toolbar: { show: false } },
    colors: [theme.current.value.colors.success],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.1 } },
    dataLabels: { enabled: false },
    grid: { borderColor: 'rgba(var(--v-theme-borderLight), 1)', strokeDashArray: 4 },
    xaxis: { categories: safeChart('contributions_trend').labels },
    yaxis: { title: { text: 'Amount (K)' } },
    tooltip: { y: { formatter: (val: number) => formatCurrency(val * 1000) } }
}))

// Helpers
const formatCurrency = (amount: number) => `MWK ${new Intl.NumberFormat().format(amount || 0)}`
const formatDate = (date: string) => date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : ''
const formatGrowth = (rate: number) => `${Math.abs(rate || 0)}%`
const getGrowthColor = (rate: number) => !rate || rate === 0 ? 'text-secondary' : rate > 0 ? 'text-success' : 'text-error'
const getGrowthIcon = (rate: number) => !rate || rate === 0 ? 'custom-minus-square-outline' : rate > 0 ? 'custom-rise-outline' : 'custom-fall-outline'

const calculateMembershipYears = () => {
    if (!shareholder.value?.date_joined) return 0
    const joinDate = new Date(shareholder.value.date_joined)
    const today = new Date()
    return today.getFullYear() - joinDate.getFullYear()
}

// Fetch Data
const fetchDashboardData = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/dashboard/shareholder', {
            params: {
                financial_year_id: selectedFY.value,
                contribution_period: contributionPeriod.value,
                invoice_period: invoicePeriod.value,
                activity_period: activityPeriod.value,
            }
        })

        shareholder.value = data.data.shareholder
        stats.value = data.data.stats
        recentPayments.value = data.data.recent_payments || []
        pendingInvoices.value = data.data.pending_invoices || []
        chartData.value = data.data.chart_data || {}

        if (data.data.available_years) {
            financialYears.value = [
                { value: null, title: 'All Years' },
                ...data.data.available_years.map((fy: any) => ({
                    value: fy.id,
                    title: `FY ${fy.year} - ${fy.name}`
                }))
            ]
        }
    } catch (error) {
        console.error('Failed to fetch shareholder dashboard:', error)
    } finally {
        isLoading.value = false
    }
}

watch([contributionPeriod, invoicePeriod, activityPeriod, selectedFY], fetchDashboardData)

onMounted(fetchDashboardData)
</script>

<style scoped>
.widget-gradient {
    background: linear-gradient(135deg, rgba(var(--v-theme-on-surface), 0.9) 0%, rgba(var(--v-theme-on-surface), 0.7) 100%);
}

.gap-3 {
    gap: 12px;
}
</style>