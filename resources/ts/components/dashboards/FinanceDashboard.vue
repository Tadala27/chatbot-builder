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
                        <h3 class="text-h3">Finance Dashboard</h3>
                        <p class="text-subtitle-1 text-secondary">
                            Financial analytics and revenue tracking
                        </p>
                    </div>
                    <div class="d-flex gap-3 align-center">
                        <!-- Financial Year Selector -->
                        <v-select v-model="selectedFY" :items="financialYears" item-title="title" item-value="value"
                            label="Financial Year" variant="outlined" density="compact" hide-details
                            style="min-width: 200px;" prepend-inner-icon="$calendar" color="primary" />
                        <VChip color="primary" variant="tonal" size="large">
                            {{ userRole }}
                        </VChip>
                    </div>
                </div>
            </VCol>

            <!-- FY Info Card -->
            <VCol v-if="currentFY" cols="12">
                <VCard variant="outlined" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center">
                            <div class="d-flex align-center gap-2">
                                <v-icon color="primary" size="32">mdi-calendar-range</v-icon>
                                <div>
                                    <p class="text-caption text-medium-emphasis mb-0">Financial Year</p>
                                    <h5 class="text-h5 mb-0">{{ currentFY.name }}</h5>
                                </div>
                            </div>
                            <v-chip :color="getFYStatusColor(currentFY.status)" size="small" variant="tonal">
                                {{ currentFY.status }}
                            </v-chip>
                        </div>
                    </VCardText>
                    <VCardItem class="pt-0">
                        <VRow>
                            <VCol cols="12" md="3" sm="6">
                                <VRow class="align-end">
                                    <VCol cols="6">
                                        <p class="text-body-1 mb-0">Total Revenue</p>
                                        <h5 class="text-h5 mb-0">{{ formatCurrency(currentFY.total_revenue) }}</h5>
                                    </VCol>
                                    <VCol cols="6">
                                        <VueApexCharts type="area" height="60" class="overview-chart"
                                            :options="fyRevenueChartOptions" :series="fyRevenueChartSeries" />
                                    </VCol>
                                </VRow>
                            </VCol>
                            <VCol cols="12" md="3" sm="6">
                                <VRow class="align-end">
                                    <VCol cols="6">
                                        <p class="text-body-1 mb-0">Net Profit</p>
                                        <h5 class="text-h5 mb-0 text-success">{{ formatCurrency(currentFY.net_profit) }}
                                        </h5>
                                    </VCol>
                                    <VCol cols="6">
                                        <VueApexCharts type="area" height="60" class="overview-chart"
                                            :options="fyProfitChartOptions" :series="fyProfitChartSeries" />
                                    </VCol>
                                </VRow>
                            </VCol>
                            <VCol cols="12" md="3" sm="6">
                                <VRow class="align-end">
                                    <VCol cols="6">
                                        <p class="text-body-1 mb-0">Dividend Pool</p>
                                        <h5 class="text-h5 mb-0">{{ formatCurrency(currentFY.dividend_pool) }}</h5>
                                    </VCol>
                                    <VCol cols="6">
                                        <VueApexCharts type="area" height="60" class="overview-chart"
                                            :options="fyDividendPoolChartOptions" :series="fyDividendPoolChartSeries" />
                                    </VCol>
                                </VRow>
                            </VCol>
                            <VCol cols="12" md="3" sm="6" class="text-end">
                                <VBtn color="primary" variant="flat" rounded="md" to="/financial-years">
                                    <v-icon start>$cog</v-icon>
                                    Manage FY
                                </VBtn>
                            </VCol>
                        </VRow>
                    </VCardItem>
                </VCard>
            </VCol>

            <!-- Graph Stats Cards Row -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center mb-5">
                            <h5 class="text-h5 mb-0">Total Revenue</h5>
                            <VAutocomplete v-model="revenuePeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="area" height="80" :options="revenueChartOptions"
                            :series="revenueChartSeries" />
                        <div class="d-flex ga-2 justify-center py-4">
                            <h6 class="text-subtitle-1 mb-0">
                                {{ formatCurrency(stats.total_revenue || 0) }}
                            </h6>
                            <p :class="`text-body-1 mb-0 ${getGrowthColor(stats.revenue_growth)}`">
                                <v-icon size="16">{{ getGrowthIcon(stats.revenue_growth) }}</v-icon>
                                {{ formatGrowth(stats.revenue_growth) }}
                            </p>
                        </div>
                        <VBtn color="secondary" variant="outlined" rounded="md" block to="/payments">
                            View More
                        </VBtn>
                    </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12" md="6">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center mb-5">
                            <h5 class="text-h5 mb-0">Collections</h5>
                            <VAutocomplete v-model="collectionPeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="bar" height="80" :options="collectionChartOptions"
                            :series="collectionChartSeries" />
                        <div class="d-flex flex-column align-center pt-1 pb-4">
                            <div class="d-flex ga-2 justify-center mb-2">
                                <h6 class="text-subtitle-1 mb-0">
                                    {{ formatCurrency(stats.collections || 0) }}
                                </h6>
                                <p :class="`text-body-1 mb-0 ${getGrowthColor(stats.collection_growth)}`">
                                    <v-icon size="16">{{ getGrowthIcon(stats.collection_growth) }}</v-icon>
                                    {{ formatGrowth(stats.collection_growth) }}
                                </p>
                            </div>
                        </div>
                        <VBtn color="secondary" variant="outlined" rounded="md" block to="/payments">
                            View More
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

        </VRow>

        <VRow class="match-height">
            <!-- Recent Transactions -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" class="bg-surface rounded-lg h-100 d-flex flex-column">
                    <div>
                        <VCardText class="pb-2">
                            <div class="d-flex justify-space-between align-center">
                                <h5 class="text-h5 mb-0">Recent Transactions</h5>
                                <VMenu width="150">
                                    <template #activator="{ props }">
                                        <VBtn icon color="secondary" variant="text" rounded="md" size="small"
                                            v-bind="props">
                                            <v-icon>mdi-dots-vertical</v-icon>
                                        </VBtn>
                                    </template>
                                    <VList elevation="24" class="pa-3" rounded="md">
                                        <VListItem v-for="(item, index) in ['Today', 'This Week', 'This Month']"
                                            :key="index" density="compact" rounded="md" color="secondary"
                                            @click="filterTransactions(item)">
                                            <VListItemTitle class="text-h6 text-lightText">{{ item }}</VListItemTitle>
                                        </VListItem>
                                    </VList>
                                </VMenu>
                            </div>
                        </VCardText>
                        <div class="px-6 pb-3">
                            <VTabs v-model="transactionTab" color="primary" class="mb-3">
                                <VTab value="all" class="font-weight-medium">All Transactions</VTab>
                                <VTab value="contributions" class="font-weight-medium">Contributions</VTab>
                                <VTab value="fees" class="font-weight-medium">Fees</VTab>
                            </VTabs>
                            <VDivider />
                        </div>
                    </div>

                    <div class="flex-grow-1 overflow-y-auto px-6 pt-4" style="min-height: 300px;">
                        <VWindow v-model="transactionTab" class="h-100">
                            <VWindowItem value="all">
                                <VList border rounded="lg" class="py-0 bg-transparent">
                                    <VListItem v-if="!recentTransactions.length" class="text-center py-12">
                                        <p class="text-secondary text-body-1">No recent transactions</p>
                                    </VListItem>
                                    <VListItem v-for="transaction in recentTransactions" :key="transaction.payment_id"
                                        class="py-4 border-b">
                                        <template #prepend>
                                            <VAvatar size="40" :color="getPaymentColor(transaction.payment_type)"
                                                variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(transaction.shareholder?.first_name,
                                                        transaction.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ transaction.shareholder?.first_name }} {{
                                                transaction.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ transaction.shareholder?.member_number }} • {{ transaction.payment_type
                                            }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold text-success mb-0">
                                                    {{ formatCurrency(transaction.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(transaction.transaction_date) }}
                                                </p>
                                            </div>
                                        </template>
                                    </VListItem>
                                </VList>
                            </VWindowItem>

                            <VWindowItem value="contributions">
                                <VList border rounded="lg" class="py-0">
                                    <VListItem v-if="!contributionTransactions.length" class="text-center py-8">
                                        <p class="text-secondary">No contribution transactions</p>
                                    </VListItem>
                                    <VListItem v-for="transaction in contributionTransactions"
                                        :key="transaction.payment_id" class="py-4 px-6">
                                        <template #prepend>
                                            <VAvatar size="40" color="success" variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(transaction.shareholder?.first_name,
                                                        transaction.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ transaction.shareholder?.first_name }} {{
                                                transaction.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ transaction.shareholder?.member_number }} • {{ transaction.payment_type
                                            }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold mb-0 text-success">
                                                    {{ formatCurrency(transaction.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(transaction.transaction_date) }}
                                                </p>
                                            </div>
                                        </template>
                                    </VListItem>
                                </VList>
                            </VWindowItem>

                            <VWindowItem value="fees">
                                <VList border rounded="lg" class="py-0">
                                    <VListItem v-if="!feeTransactions.length" class="text-center py-8">
                                        <p class="text-secondary">No fee transactions</p>
                                    </VListItem>
                                    <VListItem v-for="transaction in feeTransactions" :key="transaction.payment_id"
                                        class="py-4 px-6">
                                        <template #prepend>
                                            <VAvatar size="40" color="warning" variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(transaction.shareholder?.first_name,
                                                        transaction.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ transaction.shareholder?.first_name }} {{
                                                transaction.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ transaction.shareholder?.member_number }} • {{ transaction.payment_type
                                            }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold mb-0 text-warning">
                                                    {{ formatCurrency(transaction.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(transaction.transaction_date) }}
                                                </p>
                                            </div>
                                        </template>
                                    </VListItem>
                                </VList>
                            </VWindowItem>
                        </VWindow>
                    </div>

                    <div class="border-t">
                        <div class="pa-6 pt-4">
                            <VRow>
                                <VCol cols="12" sm="6" class="pb-3 pb-sm-0">
                                    <VBtn color="secondary" variant="outlined" rounded="md" block to="/payments">
                                        Payment History
                                    </VBtn>
                                </VCol>
                                <VCol cols="12" sm="6">
                                    <VBtn color="primary" variant="flat" rounded="md" block to="/payments/create">
                                        Record New Payment
                                    </VBtn>
                                </VCol>
                            </VRow>
                        </div>
                    </div>
                </VCard>
            </VCol>

            <!-- Payment Methods & Collection Rate -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" class="bg-surface rounded-lg h-100 d-flex flex-column">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center">
                            <h5 class="text-h5 mb-0">Payment Methods</h5>
                            <VChip color="success" size="small" variant="tonal">
                                {{ selectedFY ? 'FY ' + currentFY?.year : 'All Time' }}
                            </VChip>
                        </div>
                    </VCardText>
                    <VCardItem class="pa-0">
                        <div v-if="!paymentMethodsData.length" class="text-center py-12">
                            <p class="text-secondary">No payment data in this period</p>
                        </div>
                        <div class="apexchart-center">
                            <VueApexCharts type="donut" height="340" :options="paymentMethodsChartOptions"
                                :series="paymentMethodsChartSeries" />
                        </div>
                        <VRow class="mt-4 mx-0 mb-0 px-4 pb-4">
                            <VCol cols="6">
                                <VBtn color="secondary" variant="outlined" rounded="md" block to="/payments">
                                    View All
                                </VBtn>
                            </VCol>
                            <VCol cols="6" class="ps-0">
                                <VBtn color="primary" variant="flat" rounded="md" block to="/invoices">
                                    View Invoices
                                </VBtn>
                            </VCol>
                        </VRow>

                    </VCardItem>
                </VCard>
            </VCol>
        </VRow>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useUserStore } from '@/stores/user'
import axios from "axios"
import { useTheme } from 'vuetify'

const theme = useTheme()
const userStore = useUserStore()
const userRole = computed(() => userStore.user?.user_role || 'Finance')

const isLoading = ref(true)

// Financial Year
const selectedFY = ref<number | null>(null)
const financialYears = ref<any[]>([])
const currentFY = ref<any>(null)

// Stats & Data
const stats = ref<any>({})
const recentTransactions = ref<any[]>([])
const paymentMethodsData = ref<any[]>([])
const chartData = ref<any>({
    revenue_trend: { labels: [], data: [] },
    collection_trend: { labels: [], data: [] },
    outstanding_trend: { labels: [], data: [] },
    overdue_trend: { labels: [], data: [] },
})

const transactionTab = ref('all')

// Period selectors
const revenuePeriod = ref('Monthly')
const collectionPeriod = ref('Monthly')
const periodOptions = ['Weekly', 'Monthly']

// Widget Cards Data
const widgetCards = computed(() => [
    {
        title: 'Outstanding',
        value: formatCurrency(stats.value.outstanding || 0),
        subtitle: `${stats.value.pending_invoices || 0} Pending`,
        icon: 'custom-invoice',
        color: 'warning'
    },
    {
        title: 'Overdue Amount',
        value: formatCurrency(stats.value.overdue || 0),
        subtitle: `${stats.value.overdue_invoices || 0} Overdue`,
        icon: 'custom-dollar-fill',
        color: 'error'
    },
    {
        title: 'Collection Rate',
        value: `${stats.value.collection_rate || 0}%`,
        subtitle: `${stats.value.collection_count || 0} Payments`,
        icon: 'custom-fatrows',
        color: 'success'
    }
])

// Safe chart data
const safeChart = (key: string) => {
    const trend = chartData.value[key] || {}
    return {
        labels: Array.isArray(trend.labels) ? trend.labels : [],
        data: Array.isArray(trend.data) ? trend.data : []
    }
}

// Chart Series
const revenueChartSeries = computed(() => [{
    name: 'Revenue',
    data: safeChart('revenue_trend').data
}])

const collectionChartSeries = computed(() => [{
    name: 'Collections',
    data: safeChart('collection_trend').data
}])

const paymentMethodsChartSeries = computed(() =>
    paymentMethodsData.value.slice(0, 6).map(m => Number(m.value) || 0)
)

const chartColors = computed(() => [
    'rgba(var(--v-theme-primary), 1)',
    'rgba(var(--v-theme-primary), 0.7)',
    'rgba(var(--v-theme-primary), 0.5)',
    'rgba(var(--v-theme-primary), 0.4)',
    'rgba(var(--v-theme-primary), 0.3)',
    'rgba(var(--v-theme-primary), 0.2)',
])

// Chart Options
const revenueChartOptions = computed(() => ({
    chart: { type: 'area', height: 80, toolbar: { show: false }, sparkline: { enabled: true } },
    colors: [theme.current.value.colors.success],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
    xaxis: {
        categories: safeChart('revenue_trend').labels,
        labels: { show: false }
    },
    yaxis: { show: false },
    grid: { show: false },
    dataLabels: { enabled: false },
    tooltip: {
        y: {
            formatter: (val: number) => {
                const period = revenuePeriod.value === 'Weekly' ? 'day' : 'month'
                return `${formatCurrency(val * 1000)} this ${period}`
            }
        },
        x: {
            formatter: (val: any, opts: any) => {
                const label = safeChart('revenue_trend').labels[opts.dataPointIndex]
                return revenuePeriod.value === 'Weekly' ? label : `${label} revenue`
            }
        }
    }
}))

const collectionChartOptions = computed(() => ({
    chart: {
        type: 'bar',
        height: 80,
        sparkline: { enabled: true },
        toolbar: { show: false }
    },
    dataLabels: { enabled: false },
    plotOptions: {
        bar: {
            borderRadius: 3,
            columnWidth: '60%',
        }
    },
    colors: ['rgba(var(--v-theme-primary), 0.85)'],
    xaxis: {
        categories: safeChart('collection_trend').labels,
        labels: { show: false }
    },
    yaxis: {
        show: false,
        min: -0.5,
        forceNiceScale: false
    },
    grid: {
        show: false,
        padding: {
            top: 0,
            bottom: 0,
            left: 0,
            right: 0
        }
    },
    states: {
        hover: {
            filter: {
                type: 'darken',
                value: 0.15,
            }
        }
    },
    tooltip: {
        y: {
            formatter: (val: number) => {
                const displayVal = val < 0 ? 0 : Math.round(val);
                return `${displayVal} payments`
            }
        }
    }
}))

const paymentMethodsChartOptions = computed(() => ({
    chart: { type: 'donut', height: 340 },
    labels: paymentMethodsData.value.slice(0, 6).map(m => m.name),
    colors: chartColors.value,
    legend: { show: false },
    dataLabels: { enabled: false },
    plotOptions: {
        pie: {
            donut: {
                size: '70%',
                labels: {
                    show: true,
                    total: {
                        show: true,
                        label: 'Total',
                        fontSize: '16px',
                        fontWeight: 600,
                        formatter: () => formatCurrency(
                            paymentMethodsData.value.reduce((sum, m) => sum + (Number(m.value) || 0), 0)
                        ),
                    },
                },
            },
        },
    },
    tooltip: { y: { formatter: (val: number) => formatCurrency(val) } },
}))

// FY Mini Chart Options
const fyRevenueChartOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 60,
        fontFamily: 'inherit',
        toolbar: { show: false },
        sparkline: { enabled: true },
        offsetX: -20,
    },
    colors: [theme.current.value.colors.primary],
    dataLabels: { enabled: false },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            type: 'vertical',
            inverseColors: false,
            opacityFrom: 0.5,
            opacityTo: 0,
        },
    },
    stroke: { curve: 'smooth', width: 2 },
    grid: { show: false },
    xaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    tooltip: { x: { show: false } },
}))

const fyDividendPoolChartSeries = computed(() => [{
    name: 'Dividend Pool',
    data: chartData.value.fy_dividend_trend?.data || [8, 20, 12, 28, 18, 38, 25]
}])

// Add these missing computed properties after your existing chart series:

const fyRevenueChartSeries = computed(() => [{
    name: 'Revenue',
    data: chartData.value.fy_revenue_trend?.data || [5, 25, 15, 35, 20, 45, 30]
}])

const fyProfitChartOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 60,
        fontFamily: 'inherit',
        toolbar: { show: false },
        sparkline: { enabled: true },
        offsetX: -20,
    },
    colors: [theme.current.value.colors.success],
    dataLabels: { enabled: false },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            type: 'vertical',
            inverseColors: false,
            opacityFrom: 0.5,
            opacityTo: 0,
        },
    },
    stroke: { curve: 'smooth', width: 2 },
    grid: { show: false },
    xaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    tooltip: { x: { show: false } },
}))

const fyProfitChartSeries = computed(() => [{
    name: 'Profit',
    data: chartData.value.fy_profit_trend?.data || [10, 15, 25, 20, 30, 40, 35]
}])

const fyDividendPoolChartOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 60,
        fontFamily: 'inherit',
        toolbar: { show: false },
        sparkline: { enabled: true },
        offsetX: -20,
    },
    colors: [theme.current.value.colors.info],
    dataLabels: { enabled: false },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            type: 'vertical',
            inverseColors: false,
            opacityFrom: 0.5,
            opacityTo: 0,
        },
    },
    stroke: { curve: 'smooth', width: 2 },
    grid: { show: false },
    xaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    yaxis: { labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
    tooltip: { x: { show: false } },
}))

// Transaction filters
const contributionTransactions = computed(() =>
    recentTransactions.value.filter(t => ['Share Purchase', 'Contribution', 'Capital Contribution'].includes(t.payment_type))
)

const feeTransactions = computed(() =>
    recentTransactions.value.filter(t => ['Subscription Fee', 'Processing Fee', 'Other Fee'].includes(t.payment_type))
)

// Helpers
const formatCurrency = (amount: number) => `MWK ${new Intl.NumberFormat().format(amount || 0)}`
const formatDate = (date: string) => new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
const getInitials = (first?: string, last?: string) => `${first?.[0] || ''}${last?.[0] || ''}`.toUpperCase()
const getPaymentColor = (type: string) => ['Share Purchase', 'Contribution', 'Capital Contribution'].includes(type) ? 'success' : 'warning'
const formatGrowth = (rate: number) => `${Math.abs(rate || 0)}%`
const getGrowthColor = (rate: number) => !rate || rate === 0 ? 'text-secondary' : rate > 0 ? 'text-success' : 'text-error'
const getGrowthIcon = (rate: number) => !rate || rate === 0 ? 'mdi-minus' : rate > 0 ? 'mdi-trending-up' : 'mdi-trending-down'
const getFYStatusColor = (status: string) => ({ 'Active': 'success', 'Closed': 'error', 'Draft': 'secondary' }[status] || 'secondary')

const filterTransactions = (filter: string) => {
    console.log('Filter transactions:', filter)
    // Implement filter logic here
}

// Fetch Data
const fetchFinanceDashboard = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/dashboard/stats', {
            params: {
                financial_year_id: selectedFY.value,
                revenue_period: revenuePeriod.value,
                collection_period: collectionPeriod.value,
            }
        })

        stats.value = data.data.stats
        recentTransactions.value = data.data.recent_payments || []
        paymentMethodsData.value = data.data.payment_methods || []
        chartData.value = data.data.chart_data || {}
        currentFY.value = data.data.financial_year

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
        console.error('Failed to load finance dashboard:', error)
    } finally {
        isLoading.value = false
    }
}

watch([revenuePeriod, collectionPeriod, selectedFY], fetchFinanceDashboard)

onMounted(fetchFinanceDashboard)
</script>

<style scoped>
.match-height {
    display: flex;
}

.match-height>.v-col {
    display: flex;
}

.match-height .v-card {
    width: 100%;
}

.apexchart-center {
    display: flex;
    justify-content: center;
}

.gap-3 {
    gap: 12px;
}

.widget-gradient {
    background: linear-gradient(135deg, rgba(var(--v-theme-on-surface), 0.9) 0%, rgba(var(--v-theme-on-surface), 0.7) 100%);
}
</style>