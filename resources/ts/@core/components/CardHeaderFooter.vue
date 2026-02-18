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
  >
    <VCardItem>
      <VCardTitle class="text-h6">
        {{ props.title }}
      </VCardTitle>
    </VCardItem>
    <VDivider />
    <VCardText>
      <slot />
    </VCardText>
    <VDivider />
    <VCardActions>
      <slot name="footer" />
    </VCardActions>
  </VCard>
</template>
