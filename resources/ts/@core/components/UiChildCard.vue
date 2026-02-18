<script setup lang="ts">
const props = defineProps({
  title: String,
})
</script>

<template>
  <VCard
    variant="outlined"
    class="bg-surface"
    rounded="lg"
  >
    <VCardItem class="py-3">
      <VCardTitle class="text-h5">
        {{ props.title }}
      </VCardTitle>
    </VCardItem>
    <VDivider />
    <VCardText>
      <slot />
    </VCardText>
  </VCard>
</template>
