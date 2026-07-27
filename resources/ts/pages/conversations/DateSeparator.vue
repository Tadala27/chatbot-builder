<script setup lang="ts">
/**
 * DateSeparator.vue
 *
 * Renders a thin visual date chip between message groups AND acts as a
 * sentinel for useStickyDate — it carries [data-date-separator] so the
 * scroll watcher can find it.
 *
 * The chip itself is NOT sticky (that's handled by the parent's overlay).
 * This is purely a positional marker + a subtle divider line.
 */
defineProps<{ label: string }>();
</script>

<template>
  <div
    class="date-sep"
    :data-date-separator="label"
    :aria-label="`Messages from ${label}`"
  >
    <span class="date-sep__pill">{{ label }}</span>
  </div>
</template>

<style scoped>
.date-sep {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 10px 0 6px;
  pointer-events: none;
  user-select: none;
}
.date-sep__pill {
  font-size: 11.5px;
  font-weight: 500;
  color: #6b7a87;
  background: rgba(255, 255, 255, 0.75);
  backdrop-filter: blur(4px);
  border: 1px solid rgba(0, 0, 0, 0.07);
  border-radius: 999px;
  padding: 3px 10px;
  letter-spacing: 0.01em;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
}
</style>
