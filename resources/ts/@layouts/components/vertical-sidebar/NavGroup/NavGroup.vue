<script setup>
const props = defineProps({ item: Object })
</script>

<template>
  <VListSubheader
    color="darksecondary"
    class="text-subtitle-2 text-uppercase"
  >
    {{ $t(props.item.header) }}
  </VListSubheader>
</template>
