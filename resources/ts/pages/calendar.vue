<template>
    <div>
        <VCard rounded="0">
            <!-- Responsive Toolbar -->
            <VCardTitle
                class="d-flex flex-column flex-sm-row justify-space-between align-start align-sm-center ga-3 pa-4">
                <div>
                    <h4 class="text-h5 mb-1">Interview Calendar</h4>
                    <p class="text-body-2 text-medium-emphasis mb-0">Manage and schedule interviews</p>
                </div>
                <div class="d-flex flex-column flex-sm-row ga-2 w-100 w-sm-auto">
                    <VBtn color="primary" @click="refreshCalendar" variant="outlined" size="small"
                        class="w-100 w-sm-auto">
                        <VIcon start>$reload</VIcon>
                        Refresh
                    </VBtn>
                </div>
            </VCardTitle>

            <VDivider />

            <!-- Calendar View -->
            <VCardText class="pa-2 pa-sm-4">
                <div class="calendar-wrapper">
                    <FullCalendar ref="fullCalendar" :options="calendarOptions" />
                </div>
            </VCardText>
        </VCard>

        <!-- Create Interview Dialog -->
        <VDialog v-model="showInterviewModal" :max-width="isMobile ? '95%' : '60%'" persistent scrollable
            :fullscreen="isMobile">
            <VCard>
                <VCardTitle class="d-flex justify-space-between align-center pa-4 pa-sm-6">
                    <span class="text-h6 text-sm-h5">Create Interview</span>
                    <VBtn variant="text" icon size="small" @click="closeInterviewModal">
                        <VIcon color="error">$close</VIcon>
                    </VBtn>
                </VCardTitle>
                <VDivider />

                <InterviewCreation v-if="showInterviewModal" :selected-date="selectedDate" :job-id="selectedJobId"
                    :mode="'create'" @interview-created="onInterviewCreated" @close="closeInterviewModal" />
            </VCard>
        </VDialog>

        <!-- Job Selection Dialog -->
        <VDialog v-model="showJobSelectionModal" :max-width="isMobile ? '95%' : '500px'">
            <VCard>
                <VCardTitle class="d-flex justify-space-between align-center pa-4 pa-sm-6">
                    <span class="text-h6 text-sm-h5">Select Job for Interview</span>
                    <VBtn variant="text" icon size="small" @click="closeJobSelectionModal">
                        <VIcon color="error">$close</VIcon>
                    </VBtn>
                </VCardTitle>
                <VDivider />
                <VCardText class="pa-4">
                    <VAutocomplete v-model="selectedJobId" :items="availableJobs" item-title="title" item-value="id"
                        label="Select Job Position" variant="outlined" density="comfortable" :loading="loadingJobs">
                        <template #item="{ props, item }">
                            <VListItem v-bind="props" :disabled="item.raw.hasApplicants === false">
                                <VListItemSubtitle>
                                    {{ item.raw.department?.name }} - {{ item.raw.location }}
                                </VListItemSubtitle>
                            </VListItem>
                        </template>
                    </VAutocomplete>
                </VCardText>
                <VCardActions class="pa-4">
                    <VSpacer></VSpacer>
                    <VBtn text color="grey" @click="closeJobSelectionModal">Cancel</VBtn>
                    <VBtn color="primary" @click="proceedToInterviewCreation" :disabled="!selectedJobId">
                        Continue
                    </VBtn>
                </VCardActions>
            </VCard>
        </VDialog>

        <!-- Snackbar -->
        <VSnackbar v-model="snackbar.show" :color="snackbar.color" location="top right" timeout="3000">
            {{ snackbar.message }}
            <template #actions>
                <VBtn text @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useDisplay } from 'vuetify'
import { useRouter } from 'vue-router'
import FullCalendar from '@fullcalendar/vue3'
import dayGridPlugin from '@fullcalendar/daygrid'
import timeGridPlugin from '@fullcalendar/timegrid'
import interactionPlugin from '@fullcalendar/interaction'
import listPlugin from '@fullcalendar/list'
import InterviewCreation from '@/pages/interviews/create.[id].vue'
import axios from "axios"

import tippy from 'tippy.js'
import 'tippy.js/dist/tippy.css'
import 'tippy.js/themes/light.css'

const { xs } = useDisplay()
const router = useRouter() // Add router for navigation
const isMobile = computed(() => xs.value)

// Reactive data
const fullCalendar = ref(null)
const showInterviewModal = ref(false)
const showJobSelectionModal = ref(false)
const loadingJobs = ref(false)
const selectedDate = ref<string | null>(null)
const selectedJobId = ref<number | null>(null)
const availableJobs = ref([])
const snackbar = ref({ show: false, message: '', color: 'success' })

// Responsive calendar options
const calendarOptions = computed(() => ({
    headerToolbar: {
        left: isMobile.value ? 'prev,next' : 'prev,next today',
        center: 'title',
        right: isMobile.value
            ? 'timeGridDay,listWeek'
            : 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
    },
    plugins: [dayGridPlugin, timeGridPlugin, interactionPlugin, listPlugin],
    initialView: isMobile.value ? 'timeGridDay' : 'timeGridWeek',
    height: 'auto',
    contentHeight: isMobile.value ? 'auto' : 650,
    aspectRatio: isMobile.value ? 1.2 : 1.8,
    initialEvents: [],
    editable: !isMobile.value,
    droppable: !isMobile.value,
    selectable: true,
    selectMirror: true,
    dayMaxEvents: isMobile.value ? 2 : true,
    eventDisplay: 'block',
    weekends: true,
    nowIndicator: true,
    navLinks: !isMobile.value,

    eventTimeFormat: {
        hour: 'numeric',
        minute: '2-digit',
        meridiem: 'short'
    },

    dateClick: (info: any) => {
        const clickedDateTime = new Date(info.date)
        const now = new Date()

        // Reset today's date to midnight for past date comparison
        const today = new Date()
        today.setHours(0, 0, 0, 0)

        // Block past days
        if (clickedDateTime < today) return

        // Block weekends
        const day = clickedDateTime.getDay()
        if (day === 0 || day === 6) return

        // Block past times on the same day
        if (
            clickedDateTime.toDateString() === now.toDateString() &&
            clickedDateTime.getHours() < now.getHours()
        ) {
            return
        }

        selectedDate.value = info.dateStr
        showJobSelectionModal.value = true
    },

    dayCellDidMount: (arg: any) => {
        const cellDate = new Date(arg.date)
        const today = new Date()
        today.setHours(0, 0, 0, 0)
        const isPast = cellDate < today
        const isWeekend = cellDate.getDay() === 0 || cellDate.getDay() === 6
        if (isPast || isWeekend) {
            arg.el.style.backgroundColor = '#f5f5f5'
            arg.el.style.pointerEvents = 'none'
            arg.el.style.opacity = '0.5'
        }
    },

    eventClick: (info: any) => {
        const interviewId = info.event.extendedProps.interviewId
        router.push({ name: 'interviews-details-id', params: { id: interviewId } })
    },

    eventDidMount: (info: any) => {
        if (info.event.extendedProps?.type === 'interview' && !isMobile.value) {
            createInterviewTooltip(info.el, info.event)
        }
    },

    windowResize: () => {
        const calendarApi = fullCalendar.value?.getApi()
        if (calendarApi && window.innerWidth < 600) {
            calendarApi.changeView('timeGridDay')
        }
    }
}))

// Methods
const fetchAvailableJobs = async () => {
    loadingJobs.value = true
    try {
        const response = await axios.get('/api/jobs')
        availableJobs.value = response.data.data || response.data
    } catch (error) {
        console.error('Error fetching jobs:', error)
        snackbar.value = {
            show: true,
            message: 'Failed to load available jobs',
            color: 'error'
        }
    } finally {
        loadingJobs.value = false
    }
}

const fetchInterviews = async () => {
    try {
        const response = await axios.get('/api/interviews')
        const interviews = response.data.data

        const calendarApi = fullCalendar.value?.getApi()
        if (calendarApi) {
            calendarApi.removeAllEvents()

            interviews.forEach(interview => {
                calendarApi.addEvent({
                    id: `interview-${interview.id}`,
                    title: isMobile.value
                        ? interview.title
                        : `${interview.title}`,
                    start: interview.starts_at,
                    end: interview.ends_at,
                    className: 'bg-primary text-white',
                    extendedProps: {
                        type: 'interview',
                        interviewId: interview.id,
                        interviewType: interview.type,
                        candidatesCount: interview.candidates_count || 0,
                        jobTitle: interview.job_post?.title || 'Unknown Position',
                        status: interview.status,
                        fullData: interview
                    }
                })
            })
        }
    } catch (error) {
        console.error('Error fetching interviews:', error)
        snackbar.value = {
            show: true,
            message: 'Failed to load interviews',
            color: 'error'
        }
    }
}

const refreshCalendar = async () => {
    await fetchInterviews()
    snackbar.value = {
        show: true,
        message: 'Calendar refreshed',
        color: 'success'
    }
}

const closeJobSelectionModal = () => {
    showJobSelectionModal.value = false
    selectedJobId.value = null
    selectedDate.value = null
}

const proceedToInterviewCreation = () => {
    showJobSelectionModal.value = false
    showInterviewModal.value = true
}

const closeInterviewModal = () => {
    showInterviewModal.value = false
    selectedDate.value = null
    selectedJobId.value = null
}

const onInterviewCreated = (interview: any) => {
    closeInterviewModal()
    snackbar.value = {
        show: true,
        message: 'Interview created successfully!',
        color: 'success'
    }
    fetchInterviews()
}

const formatTime = (date) => {
    if (!date) return ''
    return new Date(date).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit'
    })
}

const createInterviewTooltip = (element: HTMLElement, event: any) => {
    const props = event.extendedProps
    const tooltipContent = `
        <div style="padding: 12px; min-width: 250px;">
            <div style="font-weight: 600; margin-bottom: 8px;">${event.title}</div>
            <div style="font-size: 12px; color: #666;">
                ${formatTime(event.start)} → ${formatTime(event.end)}
            </div>
            <div style="margin-top: 8px; font-size: 12px; text-transform: capitalize;">
                <strong>Type:</strong> ${props.interviewType}<br>
                <strong>Candidates:</strong> ${props.candidatesCount}
            </div>
        </div>
    `

    tippy(element, {
        content: tooltipContent,
        allowHTML: true,
        theme: 'light',
        placement: 'top',
        arrow: true
    })
}

onMounted(async () => {
    await Promise.all([
        fetchAvailableJobs(),
        fetchInterviews()
    ])
})
</script>

<style scoped>
:deep(.fc) {
    font-family: inherit;
    font-size: 14px;
}

:deep(.fc-header-toolbar) {
    margin-bottom: 1rem !important;
    padding: 0.75rem 1rem !important;
    background: transparent !important;
    border-bottom: 2px solid rgba(var(--v-theme-primary), var(--v-border-opacity));
    flex-wrap: wrap;
}

:deep(.fc-toolbar-title) {
    font-size: 1.375rem !important;
    font-weight: 400 !important;
    color: rgb(var(--v-theme-on-surface)) !important;
}

:deep(.fc-button) {
    background: transparent !important;
    border: 1px solid rgba(var(--v-theme-secondary), var(--v-border-opacity)) !important;
    color: rgb(var(--v-theme-on-surface)) !important;
    text-transform: capitalize !important;
    font-weight: 400 !important;
    padding: 0.375rem 0.75rem !important;
    border-radius: 4px !important;
    transition: all 0.15s ease !important;
    box-shadow: none !important;
    font-size: 0.75rem !important;
}

:deep(.fc-button:hover) {
    background: rgba(var(--v-theme-on-surface), 0.04) !important;
    border-color: rgba(var(--v-theme-on-surface), 0.2) !important;
}

:deep(.fc-button:active),
:deep(.fc-button-active) {
    background: rgba(var(--v-theme-primary), 0.08) !important;
    color: rgb(var(--v-theme-primary)) !important;
    border-color: rgb(var(--v-theme-primary)) !important;
}

:deep(.fc-button:disabled) {
    opacity: 0.38;
    cursor: not-allowed;
}

:deep(.fc-scrollgrid) {
    border: none !important;
    border-radius: 0 !important;
}

:deep(.fc-scrollgrid td),
:deep(.fc-scrollgrid th) {
    border-color: rgba(var(--v-theme-secondary), 0.12) !important;
}

:deep(.fc-col-header-cell) {
    background: transparent !important;
    border: none !important;
    padding: 0.75rem 0.5rem !important;
    font-weight: 500 !important;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.8px;
    color: rgba(var(--v-theme-on-surface), 0.6);
}

:deep(.fc-daygrid-day) {
    background: transparent;
}

:deep(.fc-daygrid-day:hover) {
    background: rgba(var(--v-theme-on-surface), 0.02);
}

:deep(.fc-daygrid-day-number) {
    padding: 0.25rem !important;
    font-weight: 400;
    font-size: 13px;
    color: rgb(var(--v-theme-on-surface));
}

:deep(.fc-day-today) {
    background: rgba(var(--v-theme-primary), 0.04) !important;
}

:deep(.fc-day-today .fc-daygrid-day-number) {
    background: rgb(var(--v-theme-primary));
    color: white !important;
    border-radius: 50%;
    width: 26px;
    height: 26px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 500;
}

:deep(.fc-day-disabled) {
    background: rgba(var(--v-theme-on-surface), 0.02) !important;
    opacity: 1;
}

:deep(.fc-day-disabled .fc-daygrid-day-number) {
    color: rgba(var(--v-theme-on-surface), 0.38);
}

:deep(.fc-event) {
    border: none !important;
    border-left: 3px solid !important;
    border-radius: 3px !important;
    padding: 2px 6px !important;
    font-weight: 400 !important;
    font-size: 12px !important;
    margin-bottom: 2px !important;
    box-shadow: none !important;
    transition: box-shadow 0.15s ease !important;
}

:deep(.fc-event:hover) {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.08) !important;
    cursor: pointer;
}

:deep(.fc-event.bg-primary) {
    background: rgba(var(--v-theme-primary), 0.15) !important;
    border-left-color: rgb(var(--v-theme-primary)) !important;
    color: rgb(var(--v-theme-primary)) !important;
}

:deep(.fc-event.bg-success) {
    background: rgba(var(--v-theme-success), 0.15) !important;
    border-left-color: rgb(var(--v-theme-success)) !important;
    color: rgb(var(--v-theme-success)) !important;
}

:deep(.fc-event.bg-warning) {
    background: rgba(var(--v-theme-warning), 0.15) !important;
    border-left-color: rgb(var(--v-theme-warning)) !important;
    color: rgb(var(--v-theme-warning)) !important;
}

:deep(.fc-event.bg-error) {
    background: rgba(var(--v-theme-error), 0.15) !important;
    border-left-color: rgb(var(--v-theme-error)) !important;
    color: rgb(var(--v-theme-error)) !important;
}

:deep(.fc-event.bg-info) {
    background: rgba(var(--v-theme-info), 0.15) !important;
    border-left-color: rgb(var(--v-theme-info)) !important;
    color: rgb(var(--v-theme-info)) !important;
}

:deep(.fc-timegrid-slot) {
    height: 3rem !important;
    border-color: rgba(var(--v-theme-secondary), 0.12) !important;
}

:deep(.fc-timegrid-slot-label) {
    font-size: 11px;
    color: rgba(var(--v-theme-on-surface), 0.6);
    font-weight: 400;
    padding-right: 8px;
}

:deep(.fc-timegrid-axis) {
    font-size: 11px;
}

:deep(.fc-timegrid-now-indicator-line) {
    border-color: rgb(var(--v-theme-error)) !important;
    border-width: 2px !important;
}

:deep(.fc-timegrid-now-indicator-arrow) {
    border-color: rgb(var(--v-theme-error)) !important;
    border-width: 6px !important;
}

:deep(.fc-list-event) {
    text-transform: capitalize !important;
}

:deep(.fc-list-event:hover td) {
    background: rgba(var(--v-theme-on-surface), 0.02) !important;
}

:deep(.fc-list-event-dot) {
    border-width: 5px !important;
}

:deep(.fc-list-day-cushion) {
    background: rgba(var(--v-theme-on-surface), 0.04) !important;
    color: rgb(var(--v-theme-on-surface)) !important;
    padding: 0.5rem 1rem !important;
    font-weight: 500;
    font-size: 12px;
}

:deep(.fc-scroller)::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

:deep(.fc-scroller)::-webkit-scrollbar-track {
    background: transparent;
}

:deep(.fc-scroller)::-webkit-scrollbar-thumb {
    background: rgba(var(--v-theme-on-surface), 0.2);
    border-radius: 3px;
}

:deep(.fc-scroller)::-webkit-scrollbar-thumb:hover {
    background: rgba(var(--v-theme-on-surface), 0.3);
}

:deep(.fc-more-link) {
    color: rgba(var(--v-theme-on-surface), 0.6) !important;
    font-weight: 400 !important;
    font-size: 12px !important;
}

:deep(.fc-more-link:hover) {
    color: rgb(var(--v-theme-primary)) !important;
}

:deep(.fc-popover) {
    border-radius: 8px !important;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15) !important;
    border: 1px solid rgba(var(--v-theme-secondary), var(--v-border-opacity)) !important;
    background: rgb(var(--v-theme-surface)) !important;
}

:deep(.fc-popover-header) {
    background: rgb(var(--v-theme-surface)) !important;
    color: rgb(var(--v-theme-on-surface)) !important;
    padding: 0.75rem 1rem !important;
    font-weight: 500;
    border-bottom: 1px solid rgba(var(--v-theme-secondary), var(--v-border-opacity));
}

:deep(.fc-event) {
    animation: none;
}

:deep(.fc-event-title) {
    font-weight: 400;
    overflow: hidden;
    text-overflow: ellipsis;
    color: black !important;
    text-transform: capitalize !important;
}

:deep(.fc-event-time) {
    font-size: 11px;
    font-weight: 500;
    color: black !important;
}

.VCard {
    border-radius: 8px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12) !important;
}

:deep(.fc-view-harness) {
    background: transparent;
}

:deep(.fc-daygrid-week-number) {
    background: transparent;
    color: rgba(var(--v-theme-on-surface), 0.38);
    font-weight: 400;
    font-size: 11px;
}

:deep(.fc-timegrid-divider) {
    padding: 0 !important;
}

:deep(.fc-timegrid-body) {
    border-top: 1px solid rgba(var(--v-theme-secondary), 0.12) !important;
}

@media (max-width: 768px) {
    :deep(.fc-header-toolbar) {
        flex-direction: column !important;
        gap: 0.75rem;
        padding: 0.5rem !important;
    }

    :deep(.fc-toolbar-title) {
        font-size: 1rem !important;
    }

    :deep(.fc-button) {
        padding: 0.25rem 0.5rem !important;
        font-size: 0.7rem !important;
    }

    :deep(.fc-event) {
        padding: 2px 4px !important;
        font-size: 10px !important;
    }

    :deep(.fc-timegrid-slot) {
        height: 2rem !important;
    }

    :deep(.fc-timegrid-slot-label) {
        font-size: 10px;
    }

    :deep(.fc-col-header-cell) {
        font-size: 10px;
        padding: 0.5rem 0.25rem !important;
    }

    :deep(.fc-daygrid-day-number) {
        font-size: 12px;
    }

    :deep(.fc-day-today .fc-daygrid-day-number) {
        width: 20px;
        height: 20px;
        font-size: 12px;
    }
}
</style>