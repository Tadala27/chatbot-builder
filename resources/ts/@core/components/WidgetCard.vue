<script setup lang="ts">
const props = defineProps({
  title: String,
  subtitle: String,
})
</script>

<template>
  <!-- -------------------------------------------------------------------- -->
  <!-- Card with Header -->
  <!-- -------------------------------------------------------------------- -->
  <VCard
    variant="outlined"
    elevation="0"
    class="bg-surface mb-6 overflow-hidden"
    rounded="lg"
  >
    <VCardItem>
      <VCardTitle class="text-h6">
        {{ props.title }}
      </VCardTitle>
      <p class="text-caption text-lightText mb-0">
        {{ props.subtitle }}
      </p>
    </VCardItem>
    <VDivider />
    <VCardText>
      <slot />
    </VCardText>
  </VCard>
</template>
