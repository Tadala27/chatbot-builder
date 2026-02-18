<template>
    <VMenu v-model="menu" :close-on-content-click="false" location="bottom start">
        <template #activator="{ props: menuProps }">
            <VTextField 
                v-bind="menuProps" 
                :model-value="formattedDateRange" 
                :label="label" 
                :placeholder="placeholder"
                :rules="rules" 
                :variant="variant" 
                :density="density" 
                :disabled="disabled" 
                readonly
            >
                <template #prepend-inner>
                    <div class="d-flex align-center ga-1">
                        <SvgSprite 
                            name="custom-calendar-outline" 
                            class="text-lightText"
                            style="width: 20px; height: 20px" 
                        />
                    </div>
                </template>
                <template #append-inner>
                    <div class="d-flex align-center ga-1">
                        <VBtn 
                            v-if="clearable && (startDate || endDate)" 
                            icon 
                            size="x-small" 
                            variant="text"
                            @click.stop="clearValue"
                        >
                            <VIcon size="18">$close</VIcon>
                        </VBtn>
                    </div>
                </template>
            </VTextField>
        </template>

        <!-- Date Range Picker -->
        <VCard min-width="300" max-width="350">
            <VCardTitle class="d-flex justify-space-between align-center py-2 px-4 bg-surface">
                <span class="text-subtitle-1 font-weight-semibold">Select Date Range</span>
                <VBtn 
                    icon 
                    size="x-small" 
                    variant="text"
                    @click="menu = false"
                >
                    <VIcon size="20">$close</VIcon>
                </VBtn>
            </VCardTitle>
            <VDivider />
            
            <VCardText class="pa-3">
                <!-- Quick Select Options -->
                <div class="mb-3">
                    <div class="text-caption text-medium-emphasis mb-2 font-weight-medium">Quick Select</div>
                    <div class="d-flex flex-wrap gap-2">
                        <VChip
                            v-for="option in quickOptions" 
                            :key="option.label"
                            size="small" 
                            variant="tonal" 
                            color="primary"
                            @click="selectQuickOption(option)"
                            class="cursor-pointer"
                        >
                            {{ option.label }}
                        </VChip>
                    </div>
                </div>

                <VDivider class="my-3" />

                <!-- Single Date Picker with Range Selection -->
                <div class="date-range-picker-wrapper">
                    <VDatePicker
                        v-model="pickerDate"
                        :min="minDate"
                        :max="maxDate"
                        color="primary"
                        hide-header
                        width="100%"
                        elevation="0"
                        class="range-picker"
                        @update:model-value="handleDateSelection"
                    />
                </div>
            </VCardText>

            <VDivider />
            
            <VCardActions class="justify-space-between pa-3 bg-surface">
                <VBtn 
                    variant="text" 
                    color="error"
                    size="small"
                    @click="clearValue"
                    :disabled="!startDate && !endDate"
                >
                    <VIcon start size="18">$close</VIcon>
                    Clear
                </VBtn>
                <VBtn 
                    variant="outlined" 
                    color="secondary"
                    size="small"
                    @click="cancel"
                >
                    Cancel
                </VBtn>
            </VCardActions>
        </VCard>
    </VMenu>
</template>

<script setup lang="ts">
import { ref, computed, watch, onMounted, nextTick } from 'vue'
import { format, differenceInDays, subDays, subMonths, startOfYear, endOfYear, startOfMonth, endOfMonth, isWithinInterval, isSameDay } from 'date-fns'

interface DateRange {
    start: string | null
    end: string | null
}

const props = defineProps({
    modelValue: {
        type: Object as PropType<DateRange>,
        default: () => ({ start: null, end: null })
    },
    label: {
        type: String,
        default: 'Select Date Range'
    },
    placeholder: {
        type: String,
        default: 'Choose date range'
    },
    rules: {
        type: Array,
        default: () => []
    },
    variant: {
        type: String,
        default: 'outlined'
    },
    density: {
        type: String,
        default: 'comfortable'
    },
    disabled: {
        type: Boolean,
        default: false
    },
    clearable: {
        type: Boolean,
        default: true
    },
    minDate: {
        type: [Date, String],
        default: null
    },
    maxDate: {
        type: [Date, String],
        default: null
    },
    dateFormat: {
        type: String,
        default: 'MMM dd, yyyy'
    }
})

const emit = defineEmits(['update:modelValue'])

// State
const menu = ref(false)
const startDate = ref<Date | null>(null)
const endDate = ref<Date | null>(null)
const pickerDate = ref<Date | null>(null)
const isConfirming = ref(false) // Flag to prevent multiple confirmations

// Quick options for common date ranges
const quickOptions = computed(() => {
    const today = new Date()
    return [
        {
            label: 'Today',
            start: today,
            end: today
        },
        {
            label: 'Last 7 Days',
            start: subDays(today, 7),
            end: today
        },
        {
            label: 'Last 30 Days',
            start: subDays(today, 30),
            end: today
        },
        {
            label: 'This Month',
            start: startOfMonth(today),
            end: endOfMonth(today)
        },
        {
            label: 'Last Month',
            start: startOfMonth(subMonths(today, 1)),
            end: endOfMonth(subMonths(today, 1))
        },
        {
            label: 'This Year',
            start: startOfYear(today),
            end: endOfYear(today)
        }
    ]
})

// Computed
const formattedDateRange = computed(() => {
    if (!startDate.value || !endDate.value) return ''
    return `${formatDate(startDate.value)} - ${formatDate(endDate.value)}`
})

const daysDifference = computed(() => {
    if (!startDate.value || !endDate.value) return 0
    return differenceInDays(new Date(endDate.value), new Date(startDate.value)) + 1
})

// Initialize from modelValue
watch(() => props.modelValue, (newValue) => {
    if (newValue?.start) {
        startDate.value = new Date(newValue.start)
    } else {
        startDate.value = null
    }
    if (newValue?.end) {
        endDate.value = new Date(newValue.end)
    } else {
        endDate.value = null
    }
}, { immediate: true, deep: true })

// Auto-confirm when both dates are selected
watch([startDate, endDate], ([newStart, newEnd]) => {
    // Apply visual styling for range
    nextTick(() => {
        applyRangeStyling()
    })
    
    // Only auto-confirm if both dates are set and we're not already confirming
    if (newStart && newEnd && !isConfirming.value) {
        isConfirming.value = true
        // Small delay to show the selection visually before closing
        setTimeout(() => {
            confirmSelection()
            isConfirming.value = false
        }, 300)
    }
})

// Watch menu to apply styling when opened
watch(menu, (isOpen) => {
    if (isOpen) {
        nextTick(() => {
            applyRangeStyling()
        })
    } else {
        // Reset confirming flag when menu closes
        isConfirming.value = false
    }
})

// Methods
const applyRangeStyling = () => {
    if (!startDate.value) return
    
    // Find all date buttons in the picker
    const dateButtons = document.querySelectorAll('.range-picker .v-date-picker-month__day .v-btn')
    
    dateButtons.forEach((btn: Element) => {
        const buttonElement = btn as HTMLButtonElement
        // Get the date value from the button's aria-label or data attribute
        const ariaLabel = buttonElement.getAttribute('aria-label')
        
        if (!ariaLabel) return
        
        // Parse date from aria-label (format varies by locale)
        // We'll use the button's position and reconstruct the date
        const dateText = buttonElement.textContent?.trim()
        if (!dateText) return
        
        // Remove any existing range classes
        buttonElement.classList.remove('in-range', 'range-start', 'range-end')
        buttonElement.style.removeProperty('background-color')
        buttonElement.style.removeProperty('border-radius')
        
        // Try to match with our start/end dates
        // This is a simplified approach - you may need to adjust based on your date format
        const btnDate = new Date(ariaLabel)
        
        if (isNaN(btnDate.getTime())) return
        
        // Check if it's the start date
        if (isSameDay(btnDate, startDate.value)) {
            buttonElement.classList.add('range-start')
            if (!endDate.value || isSameDay(startDate.value, endDate.value)) {
                buttonElement.style.borderRadius = '50%'
            } else {
                buttonElement.style.borderRadius = '50% 0 0 50%'
                buttonElement.style.backgroundColor = 'rgb(var(--v-theme-primary))'
            }
        }
        
        // Check if it's the end date
        if (endDate.value && isSameDay(btnDate, endDate.value) && !isSameDay(btnDate, startDate.value)) {
            buttonElement.classList.add('range-end')
            buttonElement.style.borderRadius = '0 50% 50% 0'
            buttonElement.style.backgroundColor = 'rgb(var(--v-theme-primary))'
        }
        
        // Check if it's in the range
        if (startDate.value && endDate.value && 
            isWithinInterval(btnDate, { start: startDate.value, end: endDate.value }) &&
            !isSameDay(btnDate, startDate.value) && !isSameDay(btnDate, endDate.value)) {
            buttonElement.classList.add('in-range')
            buttonElement.style.backgroundColor = 'rgba(var(--v-theme-primary), 0.12)'
            buttonElement.style.borderRadius = '0'
        }
    })
}

const formatDate = (date: Date | string | null) => {
    if (!date) return ''
    return format(new Date(date), props.dateFormat)
}

const handleDateClick = (date: Date) => {
    if (!startDate.value || (startDate.value && endDate.value)) {
        // Start new selection
        startDate.value = date
        endDate.value = null
    } else {
        // Select end date
        if (date < startDate.value) {
            // If selected date is before start, swap them
            endDate.value = startDate.value
            startDate.value = date
        } else {
            endDate.value = date
        }
    }
}

const handleDateSelection = (date: Date | null) => {
    if (date) {
        handleDateClick(date)
        // Apply styling after selection
        nextTick(() => {
            applyRangeStyling()
        })
    }
}

const getDayClasses = (date: Date) => {
    if (!startDate.value) return ''
    
    const classes: string[] = []
    
    // Check if date is the start date
    if (isSameDay(date, startDate.value)) {
        classes.push('range-start')
    }
    
    // Check if date is the end date
    if (endDate.value && isSameDay(date, endDate.value)) {
        classes.push('range-end')
    }
    
    // Check if date is within the range
    if (startDate.value && endDate.value) {
        if (isWithinInterval(date, { start: startDate.value, end: endDate.value })) {
            classes.push('in-range')
        }
    }
    
    return classes.join(' ')
}

const selectQuickOption = (option: any) => {
    startDate.value = option.start
    endDate.value = option.end
    // Auto-confirm will trigger via watcher
}

const confirmSelection = () => {
    if (startDate.value && endDate.value && menu.value) {
        const start = new Date(startDate.value)
        const end = new Date(endDate.value)
        
        // Set to start of day for start date
        start.setHours(0, 0, 0, 0)
        
        // Set to end of day for end date
        end.setHours(23, 59, 59, 999)
        
        emit('update:modelValue', {
            start: format(start, 'yyyy-MM-dd'),
            end: format(end, 'yyyy-MM-dd')
        })
        menu.value = false
    }
}

const cancel = () => {
    // Reset to original values
    if (props.modelValue?.start) {
        startDate.value = new Date(props.modelValue.start)
    } else {
        startDate.value = null
    }
    
    if (props.modelValue?.end) {
        endDate.value = new Date(props.modelValue.end)
    } else {
        endDate.value = null
    }
    
    isConfirming.value = false
    menu.value = false
}

const clearValue = () => {
    startDate.value = null
    endDate.value = null
    isConfirming.value = false
    emit('update:modelValue', { start: null, end: null })
}
</script>

<style scoped>
.text-lightText {
    color: rgba(var(--v-theme-on-surface), 0.6);
}

.cursor-pointer {
    cursor: pointer;
}

.gap-2 {
    gap: 8px;
}

.date-range-picker-wrapper {
    position: relative;
}

/* Range highlighting using CSS - targets Vuetify's date picker buttons */
:deep(.range-picker) {
    .v-date-picker-month {
        padding: 4px;
    }
    
    .v-date-picker-month__day {
        height: 36px;
        width: 36px;
        font-size: 0.875rem;
        margin: 2px;
    }
    
    .v-date-picker-header {
        padding: 8px;
    }
    
    .v-picker__body {
        padding: 8px;
    }

    /* Add range background to selected dates */
    .v-btn--selected {
        border-radius: 50% !important;
        background-color: rgb(var(--v-theme-primary)) !important;
        color: rgb(var(--v-theme-on-primary)) !important;
    }
}

/* Custom range highlighting - apply to parent wrapper */
.date-range-picker-wrapper :deep(.v-date-picker-month__day) {
    position: relative;
}

/* Apply background color to range days using data attributes or classes added via JavaScript */
.date-range-picker-wrapper.has-range :deep(.v-date-picker-month__day .v-btn) {
    position: relative;
    transition: all 0.2s ease;
}
/* Target your custom date picker wrapper */
.date-range-picker-wrapper :deep(.v-date-picker-month__day .v-btn) {
    border-radius: 50% !important; /* Make all buttons circular */
    color: inherit; /* Default text color */
}

/* Start date */
.date-range-picker-wrapper :deep(.range-start) {
    background-color: rgb(var(--v-theme-primary)) !important;
    color: white !important; /* White text on first date */
    border-radius: 50% !important;
}

/* End date */
.date-range-picker-wrapper :deep(.range-end) {
    background-color: rgb(var(--v-theme-primary)) !important;
    color: white !important; /* White text on last date */
    border-radius: 50% !important;
}

/* Intermediate range days */
.date-range-picker-wrapper :deep(.in-range) {
    background-color: rgba(var(--v-theme-primary), 0.12) !important;
    color: inherit;
    border-radius: 50% !important; /* Make in-range days circular too */
}

</style>