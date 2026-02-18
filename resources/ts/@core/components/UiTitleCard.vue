<script setup lang="ts">
const props = defineProps({
  title: String,
  className: String,
})
</script>

<template>
  <VCard
    class="title-card"
    variant="text"
  >
    <VCardItem class="pb-2 px-0 pt-0">
      <VCardTitle class="text-h5">
        {{ props.title }}
      </VCardTitle>
    </VCardItem>
    <VCardText
      variant="outlined"
      :class="`${props.className} overflow-hidden rounded-lg`"
    >
      <slot />
    </VCardText>
  </VCard>
</template>
