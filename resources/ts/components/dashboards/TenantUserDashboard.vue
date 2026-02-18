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
                        <h3 class="text-h3">My Dashboard</h3>
                        <p class="text-subtitle-1 text-secondary">
                            Welcome back, {{ userInfo.name }}
                        </p>
                    </div>
                    <VChip color="primary" variant="tonal" size="large">
                        <VIcon start>mdi-account</VIcon>
                        Employee
                    </VChip>
                </div>
            </VCol>

            <!-- ROW 1: Profile, Fiscal Overview, Performance Overview -->
            <VCol cols="12" md="4">
                <VCard variant="outlined" class="bg-surface" rounded="lg" height="100%">
                    <VCardText class="text-center">
                        <VAvatar size="80" color="primary" class="mb-3">
                            <span class="text-h4 text-white">
                                {{ getInitials(userInfo.name) }}
                            </span>
                        </VAvatar>

                        <h5 class="text-h5 mb-1">{{ userInfo.name }}</h5>
                        <p class="text-body-2 text-medium-emphasis mb-4">{{ userInfo.email }}</p>

                        <VDivider class="my-4" />

                        <VList class="py-0 bg-transparent">
                            <VListItem class="px-0">
                                <template #prepend>
                                    <VIcon color="primary">mdi-briefcase</VIcon>
                                </template>
                                <VListItemTitle class="text-body-2">Position</VListItemTitle>
                                <VListItemSubtitle class="text-body-1 font-weight-medium">
                                    {{ userInfo.position || 'Not assigned' }}
                                </VListItemSubtitle>
                            </VListItem>

                            <VListItem class="px-0">
                                <template #prepend>
                                    <VIcon color="info">mdi-domain</VIcon>
                                </template>
                                <VListItemTitle class="text-body-2">Business Unit</VListItemTitle>
                                <VListItemSubtitle class="text-body-1 font-weight-medium">
                                    {{ userInfo.business_unit || 'Not assigned' }}
                                </VListItemSubtitle>
                            </VListItem>

                            <VListItem class="px-0">
                                <template #prepend>
                                    <VIcon color="success">mdi-chart-line</VIcon>
                                </template>
                                <VListItemTitle class="text-body-2">Level</VListItemTitle>
                                <VListItemSubtitle class="text-body-1 font-weight-medium">
                                    {{ userInfo.level || 'N/A' }}
                                </VListItemSubtitle>
                            </VListItem>
                        </VList>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Fiscal Overview -->
            <VCol cols="12" md="4">
                <VCard variant="outlined" class="bg-primary" rounded="lg" height="100%">
                    <VCardText>
                        <div class="text-center">
                            <VIcon size="48" color="white" class="mb-3">mdi-calendar-month</VIcon>
                            <p class="text-caption text-white opacity-80 mb-1">Financial Year</p>
                            <h4 class="text-h4 text-white mb-4">{{ fiscalInfo.year }}</h4>

                            <VDivider class="my-4 opacity-30" color="white" />

                            <div class="d-flex justify-space-around align-center">
                                <div>
                                    <p class="text-caption text-white opacity-80 mb-1">Current Quarter</p>
                                    <h5 class="text-h5 text-white font-weight-bold">
                                        {{ fiscalInfo.quarter }}
                                    </h5>
                                </div>
                                <VDivider vertical class="mx-2 opacity-30" color="white" />
                                <div>
                                    <p class="text-caption text-white opacity-80 mb-1">Period</p>
                                    <p class="text-body-1 text-white font-weight-medium">
                                        {{ fiscalInfo.period }}
                                    </p>
                                </div>
                            </div>

                            <VChip :color="fiscalInfo.status === 'active' ? 'success' : 'warning'" size="small"
                                class="mt-4">
                                {{ fiscalInfo.status }}
                            </VChip>
                        </div>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Performance Overview -->
            <VCol cols="12" md="4">
                <VCard variant="outlined" class="bg-surface" rounded="lg" height="100%">
                    <VCardText>
                        <h5 class="text-h5 mb-4 text-center">Performance Overview</h5>

                        <div class="mb-4">
                            <VueApexCharts type="radialBar" height="180" :options="performanceRadialOptions"
                                :series="performanceRadialSeries" />
                        </div>

                        <VRow dense>
                            <VCol cols="4" class="text-center">
                                <VIcon color="primary" size="24" class="mb-1">mdi-target</VIcon>
                                <p class="text-caption text-medium-emphasis mb-0">Goals</p>
                                <h6 class="text-h6">{{ performanceInfo.goals }}</h6>
                            </VCol>
                            <VCol cols="4" class="text-center">
                                <VIcon color="info" size="24" class="mb-1">mdi-checkbox-marked-circle</VIcon>
                                <p class="text-caption text-medium-emphasis mb-0">Objectives</p>
                                <h6 class="text-h6">{{ performanceInfo.objectives }}</h6>
                            </VCol>
                            <VCol cols="4" class="text-center">
                                <VIcon color="success" size="24" class="mb-1">mdi-chart-line</VIcon>
                                <p class="text-caption text-medium-emphasis mb-0">BSC Score</p>
                                <h6 class="text-h6">{{ performanceInfo.bsc_score }}%</h6>
                            </VCol>
                        </VRow>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- ROW 2: Progress Trends & Current Quarter Info -->
            <VCol cols="12" md="8">
                <VCard variant="outlined" class="bg-surface" rounded="lg">
                    <VCardText>
                        <h5 class="text-h5 mb-4">Progress Trends by Quarter</h5>

                        <VueApexCharts type="area" height="300" :options="progressTrendsOptions"
                            :series="progressTrendsSeries" />

                        <VRow class="mt-4">
                            <VCol cols="3" class="text-center">
                                <p class="text-caption text-medium-emphasis mb-1">Q1 Average</p>
                                <h6 class="text-h6">{{ quarterAverages.q1 }}%</h6>
                            </VCol>
                            <VCol cols="3" class="text-center">
                                <p class="text-caption text-medium-emphasis mb-1">Q2 Average</p>
                                <h6 class="text-h6">{{ quarterAverages.q2 }}%</h6>
                            </VCol>
                            <VCol cols="3" class="text-center">
                                <p class="text-caption text-medium-emphasis mb-1">Q3 Average</p>
                                <h6 class="text-h6">{{ quarterAverages.q3 }}%</h6>
                            </VCol>
                            <VCol cols="3" class="text-center">
                                <p class="text-caption text-medium-emphasis mb-1">Q4 Average</p>
                                <h6 class="text-h6">{{ quarterAverages.q4 }}%</h6>
                            </VCol>
                        </VRow>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Current Quarter Details -->
            <VCol cols="12" md="4">
                <VCard variant="outlined" class="bg-gradient-success" rounded="lg" height="100%">
                    <VCardText>
                        <div class="text-center text-white">
                            <VIcon size="56" color="white" class="mb-3">mdi-calendar-clock</VIcon>
                            <h4 class="text-h4 mb-2">{{ currentQuarter.quarter }}</h4>
                            <p class="text-subtitle-1 opacity-90 mb-4">{{ currentQuarter.name }}</p>

                            <VDivider class="my-4 opacity-30" color="white" />

                            <div class="text-start">
                                <div class="mb-3">
                                    <p class="text-caption opacity-80 mb-1">Start Date</p>
                                    <p class="text-body-1 font-weight-medium">
                                        {{ formatDate(currentQuarter.start_date) }}
                                    </p>
                                </div>

                                <div class="mb-3">
                                    <p class="text-caption opacity-80 mb-1">End Date</p>
                                    <p class="text-body-1 font-weight-medium">
                                        {{ formatDate(currentQuarter.end_date) }}
                                    </p>
                                </div>

                                <div>
                                    <p class="text-caption opacity-80 mb-1">Days Remaining</p>
                                    <h5 class="text-h5 font-weight-bold">
                                        {{ currentQuarter.days_remaining }} days
                                    </h5>
                                </div>
                            </div>

                            <VBtn color="white" variant="outlined" block class="mt-4" @click="goToScorecard">
                                View Scorecard
                            </VBtn>
                        </div>
                    </VCardText>
                </VCard>
            </VCol>

            <!-- Quick Actions -->
            <VCol cols="12">
                <VCard variant="outlined" class="bg-surface" rounded="lg">
                    <VCardText>
                        <h5 class="text-h5 mb-4">Quick Actions</h5>

                        <VRow>
                            <VCol cols="12" sm="6" md="3">
                                <VBtn color="primary" variant="tonal" block size="large"
                                    prepend-icon="mdi-clipboard-text" @click="goToScorecard">
                                    My Scorecard
                                </VBtn>
                            </VCol>
                            <VCol cols="12" sm="6" md="3">
                                <VBtn color="info" variant="tonal" block size="large" prepend-icon="mdi-chart-bar"
                                    @click="goToPerformance">
                                    Performance
                                </VBtn>
                            </VCol>
                            <VCol cols="12" sm="6" md="3">
                                <VBtn color="success" variant="tonal" block size="large" prepend-icon="mdi-target"
                                    @click="goToGoals">
                                    My Goals
                                </VBtn>
                            </VCol>
                            <VCol cols="12" sm="6" md="3">
                                <VBtn color="warning" variant="tonal" block size="large" prepend-icon="mdi-account"
                                    @click="goToProfile">
                                    My Profile
                                </VBtn>
                            </VCol>
                        </VRow>
                    </VCardText>
                </VCard>
            </VCol>
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
const userInfo = ref<any>({})
const fiscalInfo = ref<any>({})
const performanceInfo = ref<any>({})
const currentQuarter = ref<any>({})
const progressData = ref<any>({ labels: [], data: [] })
const quarterAverages = ref<any>({ q1: 0, q2: 0, q3: 0, q4: 0 })

// Performance Radial Chart
const performanceRadialSeries = computed(() => [performanceInfo.value.progress || 0])

const performanceRadialOptions = computed(() => ({
    chart: {
        type: 'radialBar'
    },
    plotOptions: {
        radialBar: {
            hollow: {
                size: '60%'
            },
            dataLabels: {
                name: {
                    fontSize: '14px',
                    color: theme.current.value.colors['on-surface']
                },
                value: {
                    fontSize: '24px',
                    fontWeight: 'bold',
                    color: theme.current.value.colors.primary,
                    formatter: (val: number) => val.toFixed(0) + '%'
                }
            }
        }
    },
    colors: [theme.current.value.colors.primary],
    labels: ['Progress']
}))

// Progress Trends Chart
const progressTrendsSeries = computed(() => [{
    name: 'Performance',
    data: progressData.value.data || []
}])

const progressTrendsOptions = computed(() => ({
    chart: {
        type: 'area',
        height: 300,
        toolbar: { show: false },
        zoom: { enabled: false }
    },
    colors: [theme.current.value.colors.success],
    stroke: {
        curve: 'smooth',
        width: 3
    },
    fill: {
        type: 'gradient',
        gradient: {
            shadeIntensity: 1,
            opacityFrom: 0.6,
            opacityTo: 0.1
        }
    },
    xaxis: {
        categories: progressData.value.labels || [],
        labels: {
            style: {
                colors: theme.current.value.colors['on-surface']
            }
        }
    },
    yaxis: {
        min: 0,
        max: 100,
        labels: {
            formatter: (val: number) => val.toFixed(0) + '%',
            style: {
                colors: theme.current.value.colors['on-surface']
            }
        }
    },
    grid: {
        borderColor: theme.current.value.colors['on-surface'] + '20'
    },
    tooltip: {
        theme: theme.current.value.dark ? 'dark' : 'light',
        y: {
            formatter: (val: number) => val.toFixed(1) + '%'
        }
    }
}))

// Helpers
const getInitials = (name: string) => {
    if (!name) return 'U'
    const parts = name.split(' ')
    if (parts.length >= 2) {
        return (parts[0][0] + parts[1][0]).toUpperCase()
    }
    return name.substring(0, 2).toUpperCase()
}

const formatDate = (date: string) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    })
}

// Navigation
const goToScorecard = () => router.push('/scorecard')
const goToPerformance = () => router.push('/performance')
const goToGoals = () => router.push('/goals')
const goToProfile = () => router.push('/profile')

// Fetch Data
const fetchDashboardStats = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/dashboard/stats')

        userInfo.value = data.data.user_info || {}
        fiscalInfo.value = data.data.cards?.find((c: any) => c.type === 'period') || {}
        performanceInfo.value = data.data.cards?.find((c: any) => c.type === 'performance')?.metrics?.reduce((acc: any, m: any) => {
            if (m.label === 'Goals') acc.goals = m.value
            if (m.label === 'Objectives') acc.objectives = m.value
            if (m.label === 'BSC Score') acc.bsc_score = parseInt(m.value)
            return acc
        }, {}) || {}

        performanceInfo.value.progress = data.data.cards?.find((c: any) => c.type === 'performance')?.progress || 0

        currentQuarter.value = data.data.current_quarter || {}
        progressData.value = data.data.progress_trends || { labels: [], data: [] }
        quarterAverages.value = data.data.quarter_averages || { q1: 0, q2: 0, q3: 0, q4: 0 }

    } catch (error) {
        console.error('Failed to load dashboard:', error)
    } finally {
        isLoading.value = false
    }
}

onMounted(fetchDashboardStats)
</script>

<style scoped>
.bg-gradient-success {
    background: linear-gradient(135deg, rgb(var(--v-theme-success)) 0%, rgb(var(--v-theme-success-darken-1)) 100%);
}
</style>