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
                        <h3 class="text-h3">Admin Dashboard</h3>
                        <p class="text-subtitle-1 text-secondary">
                            Complete overview of TMCC membership and financial activities
                        </p>
                    </div>
                    <div class="d-flex gap-3 align-center">
                        <!-- Financial Year Selector -->
                        <v-select v-model="selectedFY" :items="financialYears" item-title="title" item-value="value"
                            label="Financial Year" variant="outlined" density="compact" hide-details
                            style="min-width: 200px;" prepend-inner-icon="$calendar" color="primary" />
                        <VChip color="primary" variant="tonal" size="large" v-if="userRole === 'SystemOwner'">
                            System Owner
                        </VChip>
                        <VChip v-else color="primary" variant="tonal" size="large">
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
                                 <SvgSprite name="custom-calendar-plus" class="text-lightText"
                            style="width: 40px; height: 40px" />
                                <div class="mx-2">
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
                                            :options="revenueChartOptions" :series="revenueChartSeries" />
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
                                            :options="profitChartOptions" :series="profitChartSeries" />
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
                                            :options="dividendPoolChartOptions" :series="dividendPoolChartSeries" />
                                    </VCol>
                                </VRow>
                            </VCol>
                            <VCol cols="12" md="3" sm="6" class="text-end">
                                <VBtn color="primary" variant="flat" rounded="md" @click="goToFYManagement">
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
                            <h5 class="text-h5 mb-0">Total Shareholders</h5>
                            <VAutocomplete v-model="shareholderPeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="bar" height="80" :options="shareholderChartOptions"
                            :series="shareholderChartSeries" />
                        <div class="d-flex ga-2 justify-center py-4">
                            <h6 class="text-subtitle-1 mb-0">
                                {{ formatNumber(stats.shareholders?.total || 0) }}
                            </h6>
                            <p :class="`text-body-1 mb-0 ${getGrowthColor(stats.shareholders?.growth_rate)}`">
                                <v-icon size="16">{{ getGrowthIcon(stats.shareholders?.growth_rate) }}</v-icon>
                                {{ formatGrowth(stats.shareholders?.growth_rate) }}
                            </p>
                        </div>
                        <VBtn color="secondary" variant="outlined" rounded="md" block to="/shareholders">
                            View More
                        </VBtn>
                    </VCardText>
                </VCard>
            </VCol>

            <VCol cols="12" md="6">
                <VCard variant="outlined" elevation="0" class="bg-surface" rounded="lg">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center mb-5">
                            <h5 class="text-h5 mb-0">Contributions</h5>
                            <VAutocomplete v-model="contributionPeriod" :items="periodOptions" color="primary"
                                variant="outlined" hide-details density="compact" style="max-width: 120px" />
                        </div>
                        <VueApexCharts type="area" height="80" :options="contributionChartOptions"
                            :series="contributionChartSeries" />
                        <div class="d-flex flex-column align-center pt-1 pb-4">
                            <div class="d-flex ga-2 justify-center mb-2">
                                <h6 class="text-subtitle-1 mb-0">
                                    {{ formatCurrency(stats.contributions?.total || 0) }}
                                </h6>
                                <p :class="`text-body-1 mb-0 ${getGrowthColor(stats.contributions?.growth_rate)}`">
                                    <v-icon size="16">{{ getGrowthIcon(stats.contributions?.growth_rate) }}</v-icon>
                                    {{ formatGrowth(stats.contributions?.growth_rate) }}
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
            <!-- Recent Payments -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" class="bg-surface rounded-lg h-100 d-flex flex-column">
                    <div>
                        <VCardText class="pb-2">
                            <div class="d-flex justify-space-between align-center">
                                <h5 class="text-h5 mb-0">Recent Payments</h5>
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
                                            @click="filterPayments(item)">
                                            <VListItemTitle class="text-h6 text-lightText">{{ item }}</VListItemTitle>
                                        </VListItem>
                                    </VList>
                                </VMenu>
                            </div>
                        </VCardText>
                        <div class="px-6 pb-3">
                            <VTabs v-model="paymentTab" color="primary" class="mb-3">
                                <VTab value="all" class="font-weight-medium">All Payments</VTab>
                                <VTab value="contributions" class="font-weight-medium">Contributions</VTab>
                                <VTab value="fees" class="font-weight-medium">Fees</VTab>
                            </VTabs>
                            <VDivider />
                        </div>
                    </div>

                    <div class="flex-grow-1 overflow-y-auto px-6 pt-4" style="min-height: 300px;">
                        <VWindow v-model="paymentTab" class="h-100">
                            <VWindowItem value="all">
                                <VList border rounded="lg" class="py-0 bg-transparent">
                                    <VListItem v-if="!recentPayments.length" class="text-center py-12">
                                        <p class="text-secondary text-body-1">No recent payments</p>
                                    </VListItem>
                                    <VListItem v-for="payment in recentPayments" :key="payment.payment_id"
                                        class="py-4 border-b">
                                        <template #prepend>
                                            <VAvatar size="40" :color="getPaymentColor(payment.payment_type)"
                                                variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(payment.shareholder?.first_name,
                                                        payment.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ payment.shareholder?.first_name }} {{ payment.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ payment.shareholder?.member_number }} • {{ payment.payment_type }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold text-success mb-0">
                                                    {{ formatCurrency(payment.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(payment.transaction_date) }}
                                                </p>
                                            </div>
                                        </template>
                                    </VListItem>
                                </VList>
                            </VWindowItem>

                            <VWindowItem value="contributions">
                                <VList border rounded="lg" class="py-0">
                                    <VListItem v-if="!contributionPayments.length" class="text-center py-8">
                                        <p class="text-secondary">No contribution payments</p>
                                    </VListItem>
                                    <VListItem v-for="payment in contributionPayments" :key="payment.payment_id"
                                        class="py-4 px-6">
                                        <template #prepend>
                                            <VAvatar size="40" color="success" variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(payment.shareholder?.first_name,
                                                        payment.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ payment.shareholder?.first_name }} {{ payment.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ payment.shareholder?.member_number }} • {{ payment.payment_type }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold mb-0 text-success">
                                                    {{ formatCurrency(payment.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(payment.transaction_date) }}
                                                </p>
                                            </div>
                                        </template>
                                    </VListItem>
                                </VList>
                            </VWindowItem>

                            <VWindowItem value="fees">
                                <VList border rounded="lg" class="py-0">
                                    <VListItem v-if="!feePayments.length" class="text-center py-8">
                                        <p class="text-secondary">No fee payments</p>
                                    </VListItem>
                                    <VListItem v-for="payment in feePayments" :key="payment.payment_id"
                                        class="py-4 px-6">
                                        <template #prepend>
                                            <VAvatar size="40" color="warning" variant="tonal" rounded="md">
                                                <span class="text-h6">
                                                    {{ getInitials(payment.shareholder?.first_name,
                                                        payment.shareholder?.last_name) }}
                                                </span>
                                            </VAvatar>
                                        </template>
                                        <VListItemTitle class="text-subtitle-1 mb-0">
                                            {{ payment.shareholder?.first_name }} {{ payment.shareholder?.last_name }}
                                        </VListItemTitle>
                                        <VListItemSubtitle class="text-caption text-lightText">
                                            {{ payment.shareholder?.member_number }} • {{ payment.payment_type }}
                                        </VListItemSubtitle>
                                        <template #append>
                                            <div class="text-end">
                                                <p class="text-subtitle-1 font-weight-bold mb-0 text-warning">
                                                    {{ formatCurrency(payment.amount) }}
                                                </p>
                                                <p class="text-caption text-lightText mb-0">
                                                    {{ formatDate(payment.transaction_date) }}
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

            <!-- Top Contributors -->
            <VCol cols="12" md="6">
                <VCard variant="outlined" class="bg-surface rounded-lg h-100 d-flex flex-column">
                    <VCardText>
                        <div class="d-flex justify-space-between align-center">
                            <h5 class="text-h5 mb-0">Top Contributors</h5>
                            <VChip color="success" size="small" variant="tonal">
                                {{ selectedFY ? 'FY ' + currentFY?.year : 'All Time' }}
                            </VChip>
                        </div>
                    </VCardText>
                    <VCardItem class="pa-0">
                        <div class="apexchart-center">
                            <VueApexCharts type="donut" height="340" :options="topContributorsChartOptions"
                                :series="topContributorsChartSeries" />
                        </div>
                        <VRow class="mt-4 mx-0 mb-0 px-4 pb-4">
                            <VCol cols="6">
                                <VBtn color="secondary" variant="outlined" rounded="md" block to="/shareholders">
                                    View All
                                </VBtn>
                            </VCol>
                            <VCol cols="6" class="ps-0">
                                <VBtn color="primary" variant="flat" rounded="md" block to="/shareholders/create">
                                    Add Shareholder
                                </VBtn>
                            </VCol>
                        </VRow>
                        <div v-if="!topContributors.length" class="text-center py-12">
                            <p class="text-secondary">No contributors in this period</p>
                        </div>
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
import { useRouter } from 'vue-router'
import { useTheme } from 'vuetify'

const theme = useTheme()
const router = useRouter()
const userStore = useUserStore()
const userRole = computed(() => userStore.user?.user_role || 'Admin')

const isLoading = ref(true)

// Financial Year
const selectedFY = ref<number | null>(null)
const financialYears = ref<any[]>([])
const currentFY = ref<any>(null)

// Stats & Data
const stats = ref<any>({})
const recentPayments = ref<any[]>([])
const topContributors = ref<any[]>([])
const chartData = ref<any>({
    shareholders_trend: { labels: [], data: [] },
    contributions_trend: { labels: [], data: [] },
})

const paymentTab = ref('all')

// Period selectors
const shareholderPeriod = ref('Monthly')
const contributionPeriod = ref('Monthly')
const periodOptions = ['Weekly', 'Monthly']

// Widget Cards Data
const widgetCards = computed(() => [
    {
        title: 'Pending Invoices',
        value: formatCurrency(stats.value.invoices?.pending_amount || 0),
        subtitle: `${stats.value.invoices?.pending || 0} Invoices`,
        icon: 'custom-invoice',
        color: 'warning'
    },
    {
        title: 'Dividends Paid',
        value: formatCurrency(stats.value.dividends?.paid_amount || 0),
        subtitle: `${stats.value.dividends?.paid || 0} Shareholders`,
        icon: 'custom-dollar-fill',
        color: 'success'
    },
    {
        title: 'Total Share Value',
        value: formatCurrency(stats.value.share_capital?.total_value || 0),
        subtitle: `${formatNumber(stats.value.share_capital?.total_units || 0)} Units`,
        icon: 'custom-fatrows',
        color: 'primary'
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
const shareholderChartSeries = computed(() => [{
    name: 'Shareholders',
    data: safeChart('shareholders_trend').data
}])

const contributionChartSeries = computed(() => [{
    name: 'Contributions',
    data: safeChart('contributions_trend').data
}])

const topContributorsChartSeries = computed(() =>
    topContributors.value.slice(0, 6).map(c => Number(c.total_share_value) || 0)
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
const shareholderChartOptions = computed(() => ({
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
            // Show background bars for all columns

        }
    },
    colors: ['rgba(var(--v-theme-primary), 0.85)'],
    xaxis: {
        categories: safeChart('shareholders_trend').labels,
        labels: { show: false }
    },
    yaxis: {
        show: false,
        // This trick makes 0 values show a minimal visible bar
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
                // Convert negative values back to 0 for display
                const displayVal = val < 0 ? 0 : Math.round(val);
                return `${displayVal} shareholders`
            }
        }
    }
}))

const contributionChartOptions = computed(() => ({
    chart: { type: 'area', height: 80, toolbar: { show: false }, sparkline: { enabled: true } },
    colors: [theme.current.value.colors.success],
    stroke: { curve: 'smooth', width: 2 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.1 } },
    xaxis: {
        categories: safeChart('contributions_trend').labels,
        labels: { show: false }
    },
    yaxis: { show: false },
    grid: { show: false },
    dataLabels: { enabled: false },
    tooltip: {
        y: {
            formatter: (val: number) => {
                const period = contributionPeriod.value === 'Weekly' ? 'day' : 'month'
                return `${formatCurrency(val * 1000)} this ${period}`
            }
        },
        x: {
            formatter: (val: any, opts: any) => {
                const label = safeChart('contributions_trend').labels[opts.dataPointIndex]
                return contributionPeriod.value === 'Weekly' ? label : `${label} contributions`
            }
        }
    }
}))

const topContributorsChartOptions = computed(() => ({
    chart: { type: 'donut', height: 340 },
    labels: topContributors.value.slice(0, 6).map(c => `${c.first_name} ${c.last_name.split(' ')[0]}`),
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
                            topContributors.value.reduce((sum, c) => sum + (Number(c.total_share_value) || 0), 0)
                        ),
                    },
                },
            },
        },
    },
    tooltip: { y: { formatter: (val: number) => formatCurrency(val) } },
}))

// FY Mini Chart Options
const revenueChartOptions = computed(() => ({
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

const revenueChartSeries = computed(() => [{
    name: 'Revenue',
    data: chartData.value.fy_revenue_trend?.data || [5, 25, 15, 35, 20, 45, 30]
}])

const profitChartOptions = computed(() => ({
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

const profitChartSeries = computed(() => [{
    name: 'Profit',
    data: chartData.value.fy_profit_trend?.data || [10, 15, 25, 20, 30, 40, 35]
}])

const dividendPoolChartOptions = computed(() => ({
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

const dividendPoolChartSeries = computed(() => [{
    name: 'Dividend Pool',
    data: chartData.value.fy_dividend_trend?.data || [8, 20, 12, 28, 18, 38, 25]
}])

// Payment filters
const contributionPayments = computed(() =>
    recentPayments.value.filter(p => ['Share Purchase', 'Contribution', 'Capital Contribution'].includes(p.payment_type))
)

const feePayments = computed(() =>
    recentPayments.value.filter(p => ['Subscription Fee', 'Processing Fee', 'Other Fee'].includes(p.payment_type))
)

// Helpers
const formatNumber = (num: number) => new Intl.NumberFormat().format(num)
const formatCurrency = (amount: number) => `MWK ${new Intl.NumberFormat().format(amount || 0)}`
const formatDate = (date: string) => new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
const getInitials = (first?: string, last?: string) => `${first?.[0] || ''}${last?.[0] || ''}`.toUpperCase()
const getPaymentColor = (type: string) => ['Share Purchase', 'Contribution', 'Capital Contribution'].includes(type) ? 'success' : 'warning'
const formatGrowth = (rate: number) => `${Math.abs(rate || 0)}%`
const getGrowthColor = (rate: number) => !rate || rate === 0 ? 'text-secondary' : rate > 0 ? 'text-success' : 'text-error'
const getGrowthIcon = (rate: number) => !rate || rate === 0 ? 'mdi-minus' : rate > 0 ? 'mdi-trending-up' : 'mdi-trending-down'
const getFYStatusColor = (status: string) => ({ 'Active': 'success', 'Closed': 'error', 'Draft': 'secondary' }[status] || 'secondary')

const goToFYManagement = () => router.push('/financial-years')
const filterPayments = (filter: string) => {
    console.log('Filter payments:', filter)
    // Implement filter logic here
}

// Fetch Data
const fetchDashboardStats = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/dashboard/stats', {
            params: {
                financial_year_id: selectedFY.value,
                shareholder_period: shareholderPeriod.value,
                contribution_period: contributionPeriod.value,
            }
        })

        stats.value = data.data.stats
        recentPayments.value = data.data.recent_payments || []
        topContributors.value = data.data.top_contributors || []
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
        console.error('Failed to load dashboard:', error)
    } finally {
        isLoading.value = false
    }
}

watch([shareholderPeriod, contributionPeriod, selectedFY], fetchDashboardStats)

onMounted(fetchDashboardStats)
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

.widget-gradient {
    background: linear-gradient(135deg, rgba(var(--v-theme-on-surface), 0.9) 0%, rgba(var(--v-theme-on-surface), 0.7) 100%);
}

.gap-3 {
    gap: 12px;
}
</style>