<script setup lang="ts">
const props = defineProps({
  title: String,
})
</script>

<template>
  <!-- -------------------------------------------------------------------- -->
  <!-- Card with Header & Footer -->
  <!-- -------------------------------------------------------------------- -->
  <VCard
    variant="outlined"
    elevation="0"
    class="bg-surface"
    rounded="lg"
  >
    <VCardItem>
      <div class="d-flex justify-space-between align-center">
        <VCardTitle class="text-h6">
          {{ props.title }}
        </VCardTitle>
        <slot name="header" />
      </div>
    </VCardItem>
    <VDivider />
    <VCardText class="pa-0">
      <slot />
    </VCardText>
  </VCard>
</template>
