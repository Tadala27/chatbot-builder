<template>
    <VMenu v-model="menu" :close-on-content-click="false" location="bottom start">
        <template #activator="{ props: menuProps }">
            <VTextField v-bind="menuProps" :model-value="formattedDateTime" :label="label" :placeholder="placeholder"
                :rules="rules" :variant="variant" :density="density" :disabled="disabled" readonly>
                <template #prepend-inner>
                    <div class="d-flex align-center ga-1">
                        
                        <SvgSprite name="custom-calendar-plus" class="text-lightText"
                            style="width: 20px; height: 20px" />
                    </div>
                </template>
                <template #append-inner>
                    <div class="d-flex align-center ga-1">
                       <VBtn v-if="clearable && modelValue" icon size="x-small" variant="text"
                            @click.stop="clearValue">
                            <VIcon size="18">$close</VIcon>
                        </VBtn>
                    </div>
                </template>
            </VTextField>
        </template>

        <!-- Date Picker View -->
        <VDatePicker v-if="showDatePicker" min-width="300" v-model="selectedDate" :min="minDate" :max="maxDate"
            color="primary" hide-header @update:model-value="onDateSelected" />

        <!-- Time Picker View (only if dateOnly is false) -->
        <VCard v-if="showTimePicker && !dateOnly" min-width="300">
            <VCardTitle class="d-flex justify-space-between align-center py-2 px-4">
                <VBtn icon size="small" variant="text" @click="backToDatePicker">
                    <VIcon>$arrowLeft</VIcon>
                </VBtn>
                <span class="text-body-1">{{ formatDateOnly(selectedDate) }}</span>
                <VBtn icon size="small" variant="text" @click="confirmSelection">
                    <VIcon color="success">$check</VIcon>
                </VBtn>
            </VCardTitle>
            <VDivider />
            <VCardText class="pa-0">
                <VTimePicker v-model="selectedTime" :allowed-hours="allowedHours" :allowed-minutes="allowedMinutes"
                    format="24hr" color="primary" hide-header />
            </VCardText>
            <VCardActions class="justify-end pa-3">
                <VBtn variant="text" color="error" @click="cancel">
                    Cancel
                </VBtn>
                <VBtn variant="flat" color="primary" @click="confirmSelection">
                    Confirm
                </VBtn>
            </VCardActions>
        </VCard>
    </VMenu>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { format } from 'date-fns'

const props = defineProps({
    modelValue: {
        type: [Date, String, null],
        default: null
    },
    label: {
        type: String,
        default: 'Select Date & Time'
    },
    placeholder: {
        type: String,
        default: 'Choose date and time'
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
        default: false
    },
    dateOnly: {
        type: Boolean,
        default: false
    },
    minDate: {
        type: [Date, String],
        default: null
    },
    maxDate: {
        type: [Date, String],
        default: null
    },
    minTime: {
        type: Object as PropType<{ hour: number; minute: number } | null>,
        default: null
    },
    maxTime: {
        type: Object as PropType<{ hour: number; minute: number } | null>,
        default: null
    },
    timeFormat: {
        type: String,
        default: '24hr',
        validator: (value) => ['24hr', 'ampm'].includes(value)
    },
    dateTimeFormat: {
        type: String,
        default: 'MMM dd, yyyy, HH:mm'
    },
    allowedDates: {
        type: Function,
        default: null
    },
    disabledTimes: {
        type: Array as PropType<Array<{ start: { hour: number; minute: number }; end: { hour: number; minute: number } }>>,
        default: () => []
    },
})

const emit = defineEmits(['update:modelValue'])

// State
const menu = ref(false)
const showDatePicker = ref(true)
const showTimePicker = ref(false)
const selectedDate = ref('')
const selectedTime = ref('')

const isToday = computed(() => {
    if (!selectedDate.value) return false
    const today = new Date()
    const selected = new Date(selectedDate.value)
    return (
        selected.getFullYear() === today.getFullYear() &&
        selected.getMonth() === today.getMonth() &&
        selected.getDate() === today.getDate()
    )
})

const isStartDate = computed(() => {
    if (!props.minDate || !selectedDate.value) return false
    const startDate = new Date(props.minDate)
    const selected = new Date(selectedDate.value)
    return selected.toDateString() === startDate.toDateString()
})

const isEndDate = computed(() => {
    if (!props.maxDate || !selectedDate.value) return false
    const endDate = new Date(props.maxDate)
    const selected = new Date(selectedDate.value)
    return selected.toDateString() === endDate.toDateString()
})

const allowedHours = (hour: number) => {
    if (!selectedDate.value) return true

    const selDate = new Date(selectedDate.value)
    const today = new Date()
    const isToday = selDate.toDateString() === today.toDateString()

    if (isToday && hour < today.getHours()) return false
    if (props.minTime && isStartDate.value && hour < props.minTime.hour) return false
    if (props.maxTime && isEndDate.value && hour > props.maxTime.hour) return false

    if (props.disabledTimes && props.disabledTimes.length > 0) {
        for (const range of props.disabledTimes) {
            const startHour = range.start.hour
            const endHour = range.end.hour

            if (hour > startHour && hour < endHour) return false
            if (hour === startHour && hour === endHour) continue
            if (hour === startHour) return false
            if (hour === endHour) return false
        }
    }

    return true
}

const allowedMinutes = (minute: number) => {
    if (!selectedTime.value || !selectedDate.value) return true

    const selHour = parseInt(selectedTime.value.split(':')[0])
    const selDate = new Date(selectedDate.value)
    const today = new Date()
    const isToday = selDate.toDateString() === today.toDateString()

    if (isToday && selHour === today.getHours() && minute < today.getMinutes()) return false

    if (props.minTime && isStartDate.value && selHour === props.minTime.hour) {
        if (minute < props.minTime.minute) return false
    }

    if (props.maxTime && isEndDate.value && selHour === props.maxTime.hour) {
        if (minute > props.maxTime.minute) return false
    }

    if (props.disabledTimes && props.disabledTimes.length > 0) {
        for (const range of props.disabledTimes) {
            if (selHour === range.start.hour && selHour === range.end.hour) {
                if (minute >= range.start.minute && minute < range.end.minute) return false
            }
            if (selHour === range.start.hour) {
                if (minute >= range.start.minute) return false
            }
            if (selHour === range.end.hour) {
                if (minute < range.end.minute) return false
            }
        }
    }

    return true
}

// Initialize from modelValue
watch(() => props.modelValue, (newValue) => {
    if (newValue) {
        const date = new Date(newValue)
        selectedDate.value = date
        const hours = String(date.getHours()).padStart(2, '0')
        const minutes = String(date.getMinutes()).padStart(2, '0')
        selectedTime.value = `${hours}:${minutes}`
    } else {
        selectedDate.value = null
        selectedTime.value = null
    }
}, { immediate: true })

// Methods
const formatDateOnly = (date) => {
    if (!date) return ''
    return format(new Date(date), 'EEEE, MMM dd, yyyy')
}

const onDateSelected = (date) => {
    selectedDate.value = date

    // If dateOnly mode, confirm immediately without showing time picker
    if (props.dateOnly) {
        confirmDateOnly()
        return
    }

    // Otherwise, proceed to time picker
    if (!selectedTime.value) {
        const now = new Date()
        const hours = String(now.getHours()).padStart(2, '0')
        const minutes = String(now.getMinutes()).padStart(2, '0')
        selectedTime.value = `${hours}:${minutes}`
    }

    setTimeout(() => {
        showDatePicker.value = false
        showTimePicker.value = true
    }, 300)
}

const confirmDateOnly = () => {
    if (selectedDate.value) {
        const date = new Date(selectedDate.value)
        // Set time to midnight for date-only mode
        date.setHours(0, 0, 0, 0)

        const localDateString = format(date, "yyyy-MM-dd'T'HH:mm:ssXXX", {
            timeZone: 'Africa/Blantyre'
        })

        emit('update:modelValue', localDateString)
        closeMenu()
    }
}

const backToDatePicker = () => {
    showTimePicker.value = false
    showDatePicker.value = true
}

const confirmSelection = () => {
    if (selectedDate.value && selectedTime.value) {
        const [hours, minutes] = selectedTime.value.split(':').map(Number)
        const date = new Date(selectedDate.value)
        date.setHours(hours)
        date.setMinutes(minutes)
        date.setSeconds(0)
        date.setMilliseconds(0)

        const localDateString = format(date, "yyyy-MM-dd'T'HH:mm:ssXXX", {
            timeZone: 'Africa/Blantyre'
        })

        emit('update:modelValue', localDateString)
        closeMenu()
    }
}

const formattedDateTime = computed(() => {
    if (!selectedDate.value) return ''

    // For date-only mode, show only date
    if (props.dateOnly) {
        const date = new Date(selectedDate.value)
        return format(date, props.dateTimeFormat, {
            timeZone: 'Africa/Blantyre'
        })
    }

    // For date-time mode, show both
    if (!selectedTime.value) return ''

    const [hours, minutes] = selectedTime.value.split(':').map(Number)
    const date = new Date(selectedDate.value)
    date.setHours(hours)
    date.setMinutes(minutes)

    return format(date, props.dateTimeFormat, {
        timeZone: 'Africa/Blantyre'
    })
})

const cancel = () => {
    if (props.modelValue) {
        const date = new Date(props.modelValue)
        selectedDate.value = date
        const hours = String(date.getHours()).padStart(2, '0')
        const minutes = String(date.getMinutes()).padStart(2, '0')
        selectedTime.value = `${hours}:${minutes}`
    }
    closeMenu()
}

const closeMenu = () => {
    menu.value = false
    setTimeout(() => {
        showTimePicker.value = false
        showDatePicker.value = true
    }, 200)
}

watch(menu, (isOpen) => {
    if (!isOpen) {
        // For dateOnly mode, if date is selected, confirm it
        if (props.dateOnly && selectedDate.value) {
            confirmDateOnly()
        } else if (selectedDate.value && selectedTime.value) {
            confirmSelection()
        } else if (!props.modelValue) {
            selectedDate.value = null
            selectedTime.value = null
        }
    }
})

const clearValue = () => {
    selectedDate.value = null
    selectedTime.value = null
    emit('update:modelValue', null)
}
</script>

<style scoped>
/* Add any custom styles if needed */
</style>