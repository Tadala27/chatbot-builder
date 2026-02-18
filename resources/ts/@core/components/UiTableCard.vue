<script setup lang="ts">
const props = defineProps({
  title: String,
})
</script>

<template>
  <!-- ---------------------------------------------------- -->
  <!-- Table Card -->
  <!-- ---------------------------------------------------- -->
  <VCard
    variant="outlined"
    elevation="0"
    class="bg-surface overflow-hidden"
  >
    <VCardItem>
      <VCardTitle class="text-h6">
        {{ props.title }}
      </VCardTitle>
    </VCardItem>
    <VDivider />

    <slot />
  </VCard>
</template>
