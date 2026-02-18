<script setup>
import NavItem from "../NavItem/NavItem.vue";

const Vprops = defineProps({ item: Object, level: Number });
</script>

<template>
  <VListGroup no-action>
    <!-- Dropdown -->
    <template #activator="{ props }">
      <VListItem v-bind="props" :value="item.title" rounded color="primary">
        <!-- Title + Icon INLINE -->
        <VListItemTitle
          class="d-flex align-center ga-2 me-auto"
          :style="{ paddingLeft: `${Vprops.level * 12}px` }"
        >
          <VIcon :icon="item.icon || ''" size="20" />
          {{ $t(item.title) }}
        </VListItemTitle>

        <!-- Caption -->
        <VListItemSubtitle
          v-if="item.subCaption"
          class="text-caption mt-0 hide-menu"
        >
          {{ item.subCaption }}
        </VListItemSubtitle>
      </VListItem>
    </template>

    <!-- Sub Items -->
    <template v-for="(subitem, i) in item.children" :key="i">
      <NavCollapse
        v-if="subitem.children"
        :item="subitem"
        :level="Vprops.level + 1"
      />
      <NavItem v-else :item="subitem" :level="Vprops.level + 1" />
    </template>
  </VListGroup>
</template>
