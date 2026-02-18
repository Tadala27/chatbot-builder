<script setup>
const props = defineProps({ item: Object, level: Number })
</script>

<template>
  <!-- - Single Item -->
  <RouterLink
    :to="`${item.to}`"
    class="navItemLink rounded-md"
    :disabled="item.disabled"
  >
    <!-- - If icon -->
    <i class="navIcon">
      <SvgSprite
        :name="props.item.icon || ''"
        :level="props.level"
      />
    </i>
    <span>{{ item.title }}</span>
    <!-- - If Caption -->
    <small
      v-if="item.subCaption"
      class="text-caption mt-n1 hide-menu"
    >
      {{ item.subCaption }}
    </small>
    <!-- - If any chip or label -->
    <template v-if="item.chip">
      <VChip
        :color="item.chipColor"
        class="sidebarchip hide-menu ms-auto"
        :size="item.chipIcon ? 'small' : 'small'"
        :variant="item.chipVariant"
        :prepend-icon="item.chipIcon"
        :border="item.chipVariant === 'tonal' ? `${item.chipColor} solid thin opacity-50` : ''"
      >
        {{ item.chip }}
      </VChip>
    </template>
  </RouterLink>
</template>
