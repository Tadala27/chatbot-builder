<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue'
import sidebarItems from '@layouts/components/component-layout/sidebarItem'

import NavTitle from '@layouts/components/component-layout/NavTitle.vue'
import NavItem from '@layouts/components/component-layout/NavItem.vue'

const originalSidebarMenu = shallowRef(sidebarItems)
const searchValue = ref('')

const sidebarMenu = computed(() => {
  return originalSidebarMenu.value.filter(item => {
  // Check if item and item.title are defined
    if (item && item.title)
      return item.title.toLowerCase().includes(searchValue.value.toLowerCase())
    else if (item && item.header)
      return item.header.toLowerCase().includes(searchValue.value.toLowerCase())

    // If item or item.title is undefined, exclude it from the filtered list
    return false
  })
})
</script>

<template>
  <VCard
    variant="outlined"
    class="bg-surface br-0 position-sticky componentSidebar"
  >
    <VCardText class="pt-5 px-0">
      <div class="px-5">
        <VTextField
          v-model="searchValue"
          single-line
          variant="outlined"
          placeholder="Search Components"
        >
          <template #prepend-inner>
            <SvgSprite
              name="custom-search"
              class="text-lightText"
              style="width: 18px; height: 18px"
            />
          </template>
        </VTextField>
      </div>
      <VDivider />
      <!-- ---------------------------------------------- -->
      <!-- - Navigation -->
      <!-- ---------------------------------------------- -->
      <PerfectScrollbar style="height: calc(100% - 79px)" :options="{ suppressScrollX: true }">
        <VList
          aria-busy="true"
          aria-label="menu list"
          class="px-6"
        >
          <!-- - Menu Loop -->
          <template
            v-for="(item, i) in sidebarMenu"
            :key="i"
          >
            <NavTitle
              v-if="item && item.header"
              :key="item.title"
              :item="item"
            />
            <NavItem
              v-else
              :item="item"
            />
          </template>
        </VList>
      </PerfectScrollbar>
    </VCardText>
  </VCard>
</template>

<style lang="scss">
.componentSidebar {
  @media (min-width: 1280px) {
    top: 100px;
  }
}
</style>
