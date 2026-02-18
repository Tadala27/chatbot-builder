<script setup lang="ts">
const props = defineProps({
  title: String,
})
</script>

// ===============================|| Ui Parent Card||=============================== //
<template>
  <VCard
    variant="outlined"
    elevation="0"
    class="bg-surface"
    rounded="lg"
  >
    <VCardItem class="pa-5">
      <div class="d-sm-flex align-center justify-space-between">
        <VCardTitle
          class="text-h4 text-uppercase text-primary"
          style="line-height: 1.57"
        >
          {{ props.title }}
        </VCardTitle>
        <slot name="action" />
      </div>
    </VCardItem>
    <VDivider />
    <VCardText>
      <slot />
    </VCardText>
  </VCard>
</template>
