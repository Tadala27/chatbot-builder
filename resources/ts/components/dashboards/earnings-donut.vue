<script setup lang="ts">
import { computed } from "vue";

interface Segment {
  label: string;
  value: number;
  color: string;
}

const props = defineProps<{ segments: Segment[] }>();

const total = computed(() =>
  props.segments.reduce((sum, s) => sum + s.value, 0),
);

// Build stroke-dasharray offsets for a multi-segment ring.
const circumference = 2 * Math.PI * 52;

const segmentsWithOffsets = computed(() => {
  let cumulative = 0;
  return props.segments.map((seg) => {
    const fraction = total.value > 0 ? seg.value / total.value : 0;
    const length = fraction * circumference;
    const offset = cumulative;
    cumulative += length;
    return { ...seg, length, offset };
  });
});
</script>

<template>
  <div class="d-flex align-center gap-6">
    <div class="donut-wrap">
      <svg width="130" height="130" viewBox="0 0 130 130">
        <circle
          cx="65"
          cy="65"
          r="52"
          fill="none"
          stroke="#eef1f6"
          stroke-width="10"
        />
        <circle
          v-for="seg in segmentsWithOffsets"
          :key="seg.label"
          cx="65"
          cy="65"
          r="52"
          fill="none"
          :stroke="seg.color"
          stroke-width="10"
          stroke-linecap="round"
          :stroke-dasharray="`${seg.length} ${circumference - seg.length}`"
          :stroke-dashoffset="-seg.offset"
          transform="rotate(-90 65 65)"
          class="donut-segment"
        />
      </svg>
    </div>

    <div class="d-flex flex-column ga-3">
      <div
        v-for="seg in segments"
        :key="seg.label"
        class="d-flex align-center gap-2"
      >
        <span class="legend-dot" :style="{ backgroundColor: seg.color }" />
        <div>
          <p class="text-caption text-medium-emphasis mb-0">{{ seg.label }}</p>
          <p class="text-body-2 font-weight-bold mb-0">
            {{ seg.value.toLocaleString() }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.donut-wrap {
  flex-shrink: 0;
}
.donut-segment {
  transition: stroke-dasharray 0.8s cubic-bezier(0.4, 0, 0.2, 1);
}
.legend-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
@media (prefers-reduced-motion: reduce) {
  .donut-segment {
    transition: none;
  }
}
</style>
