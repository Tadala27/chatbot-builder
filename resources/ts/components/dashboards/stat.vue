<script setup lang="ts">
import { computed } from "vue";

interface Props {
  label: string;
  value: string | number;
  percent: number; // 0-100, ring fill
  direction?: "up" | "down"; // arrow inside the ring
  color?: string; // ring stroke color, defaults to primary
}

const props = withDefaults(defineProps<Props>(), {
  direction: "up",
  color: "#273775",
});

const circumference = 2 * Math.PI * 22; // r=22, slim ring matching the reference
const dashOffset = computed(
  () => circumference - (props.percent / 100) * circumference,
);
</script>

<template>
  <VCard variant="flat" border rounded="lg" class="stat-card pa-4">
    <div class="d-flex align-center gap-3">
      <div class="stat-ring">
        <svg width="54" height="54" viewBox="0 0 54 54">
          <circle
            cx="27"
            cy="27"
            r="22"
            fill="none"
            stroke="#e9edf3"
            stroke-width="4"
          />
          <circle
            cx="27"
            cy="27"
            r="22"
            fill="none"
            :stroke="color"
            stroke-width="4"
            stroke-linecap="round"
            :stroke-dasharray="circumference"
            :stroke-dashoffset="dashOffset"
            transform="rotate(-90 27 27)"
            class="stat-progress"
          />
        </svg>
        <VIcon
          :icon="direction === 'up' ? '$arrowTopRight' : '$arrowBottomLeft'"
          size="16"
          :color="color"
          class="stat-icon"
        />
      </div>

      <div class="min-w-0">
        <p class="text-caption text-medium-emphasis mb-0">{{ label }}</p>
        <p class="text-h6 font-weight-bold mb-0">{{ value }}</p>
      </div>
    </div>
  </VCard>
</template>

<style scoped>
.stat-card {
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease;
}
.stat-card:hover {
  box-shadow: 0 6px 18px rgba(39, 55, 117, 0.07);
  transform: translateY(-1px);
}
.stat-ring {
  position: relative;
  width: 54px;
  height: 54px;
  flex-shrink: 0;
}
.stat-progress {
  transition: stroke-dashoffset 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.stat-icon {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
@media (prefers-reduced-motion: reduce) {
  .stat-progress {
    transition: none;
  }
}
</style>
