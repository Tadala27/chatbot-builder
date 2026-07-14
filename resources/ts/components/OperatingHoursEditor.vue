<script setup lang="ts">
import { computed } from "vue";
import { DAYS_OF_WEEK, type DaySchedule, type OperatingHours } from "@/composables/botSettings";

const props = defineProps<{
  modelValue: OperatingHours;
}>();

const emit = defineEmits<{
  "update:modelValue": [value: OperatingHours];
}>();

const dayLabels: Record<string, string> = {
  monday: "Monday",
  tuesday: "Tuesday",
  wednesday: "Wednesday",
  thursday: "Thursday",
  friday: "Friday",
  saturday: "Saturday",
  sunday: "Sunday",
};

const timezones = computed(() => {
  // Intl.supportedValuesOf isn't available in every target browser; fall
  // back to a short common list if it's missing rather than crash.
  try {
    return (Intl as any).supportedValuesOf?.("timeZone") ?? fallbackTimezones;
  } catch {
    return fallbackTimezones;
  }
});

const fallbackTimezones = [
  "UTC",
  "Africa/Blantyre",
  "Africa/Johannesburg",
  "Africa/Lagos",
  "Africa/Nairobi",
  "Europe/London",
  "America/New_York",
];

function updateDay(day: keyof OperatingHours, patch: Partial<DaySchedule>) {
  emit("update:modelValue", {
    ...props.modelValue,
    [day]: { ...props.modelValue[day], ...patch },
  });
}

function applyToAll(day: keyof OperatingHours) {
  const source = props.modelValue[day];
  const next = { ...props.modelValue };
  for (const d of DAYS_OF_WEEK) {
    next[d] = { ...source };
  }
  emit("update:modelValue", next);
}
</script>

<template>
  <div class="hours-editor">
    <div v-for="day in DAYS_OF_WEEK" :key="day" class="hours-row">
      <label class="hours-row__toggle">
        <input
          type="checkbox"
          :checked="modelValue[day].enabled"
          @change="updateDay(day, { enabled: ($event.target as HTMLInputElement).checked })"
        />
        <span class="hours-row__day">{{ dayLabels[day] }}</span>
      </label>

      <template v-if="modelValue[day].enabled">
        <input
          type="time"
          class="hours-row__time"
          :value="modelValue[day].open ?? ''"
          @change="updateDay(day, { open: ($event.target as HTMLInputElement).value })"
        />
        <span class="hours-row__sep">–</span>
        <input
          type="time"
          class="hours-row__time"
          :value="modelValue[day].close ?? ''"
          @change="updateDay(day, { close: ($event.target as HTMLInputElement).value })"
        />
        <select
          class="hours-row__tz"
          :value="modelValue[day].timezone ?? ''"
          @change="updateDay(day, { timezone: ($event.target as HTMLSelectElement).value || null })"
        >
          <option value="">Account default</option>
          <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
        </select>
        <button type="button" class="hours-row__copy" title="Apply this time to every day" @click="applyToAll(day)">
          Apply to all
        </button>
      </template>
      <span v-else class="hours-row__closed">Closed</span>
    </div>
  </div>
</template>

<style scoped>
.hours-editor {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.hours-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 4px;
  border-bottom: 1px solid var(--color-border, #ececec);
}
.hours-row:last-child {
  border-bottom: none;
}

.hours-row__toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 130px;
  flex-shrink: 0;
  cursor: pointer;
}

.hours-row__day {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-text-primary, #16191c);
}

.hours-row__time {
  border: 1px solid var(--color-border, #ececec);
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 13px;
  font-family: inherit;
}

.hours-row__sep {
  color: var(--color-text-tertiary, #b8c0bb);
}

.hours-row__tz {
  border: 1px solid var(--color-border, #ececec);
  border-radius: 8px;
  padding: 6px 8px;
  font-size: 13px;
  font-family: inherit;
  max-width: 220px;
}

.hours-row__copy {
  border: none;
  background: transparent;
  color: var(--color-online, #1fc06b);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;
  margin-left: auto;
}

.hours-row__closed {
  font-size: 13px;
  color: var(--color-text-tertiary, #b8c0bb);
  margin-left: auto;
}
</style>