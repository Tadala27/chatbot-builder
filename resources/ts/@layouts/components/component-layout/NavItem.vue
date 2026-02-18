<script setup>
const props = defineProps({ item: Object, level: Number })
</script>

<template>
  <!-- - Single Item -->
  <VListItem
    :to="item.type === 'external' ? '' : item.to"
    :href="item.type === 'external' ? item.to : ''"
    class="mb-1 rounded-md"
    color="primary"
    :disabled="item.disabled"
    :target="item.type === 'external' ? '_blank' : ''"
  >
    <VListItemTitle class="text-h6 font-weight-medium text-lightText">
      {{ $t(props.item.title) }}
      <!-- -If any chip or label -->
      <VChip
        v-if="item.chip"
        :color="item.chipColor"
        :border="item.chipVariant === 'tonal' ? `${item.chipColor} solid thin opacity-50` : ''"
        class="sidebarchip hide-menu ms-2"
        size="small"
        :variant="item.chipVariant"
        :prepend-icon="item.chipIcon"
      >
        {{ item.chip }}
      </VChip>
    </VListItemTitle>
  </VListItem>
</template>
