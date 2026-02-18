<script setup>
const props = defineProps({ item: Object, level: Number });
</script>

<template>
  <VListItem
    :to="item.type === 'external' ? '' : item.to"
    :href="item.type === 'external' ? item.to : ''"
    :target="item.type === 'external' ? '_blank' : ''"
    rounded
    color="primary"
    :disabled="item.disabled"
  >
    <VListItemTitle class="d-flex align-center ga-2">
      <VIcon v-if="props.item.icon" :icon="props.item.icon" size="20" />
      {{ $t(item.title) }}
    </VListItemTitle>

    <!-- Caption -->
    <VListItemSubtitle
      v-if="item.subCaption"
      class="text-caption mt-0 hide-menu"
    >
      {{ $t(item.subCaption) }}
    </VListItemSubtitle>

    <!-- Chip -->
    <template v-if="item.chip" #append>
      <VChip
        :color="item.chipColor"
        :border="
          item.chipVariant === 'tonal'
            ? `${item.chipColor} solid thin opacity-50`
            : ''
        "
        class="sidebarchip hide-menu"
        size="small"
        :variant="item.chipVariant"
        :prepend-icon="item.chipIcon"
      >
        {{ item.chip }}
      </VChip>
    </template>
  </VListItem>
</template>
