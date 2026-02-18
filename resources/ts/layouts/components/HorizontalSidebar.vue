<script setup lang="ts">
import { shallowRef } from 'vue'
import { useDisplay } from 'vuetify'
import VerticalSidebar from './VerticalSidebar.vue'
import { useCustomizerStore } from '@layouts/stores/customizer'
import HorizontalItems from '@layouts/components/horizontal-sidebar/horizontalItems'
import NavItem from '@layouts/components/horizontal-sidebar/NavItem/NavItem.vue'
import NavCollapse from '@layouts/components/horizontal-sidebar/NavCollapse/NavCollapse.vue'

const customizer = useCustomizerStore()
const sidebarMenu = shallowRef(HorizontalItems)
const { mdAndUp } = useDisplay()
</script>

<template>
  <template v-if="mdAndUp">
    <div class="horizontalMenu">
      <VContainer
        fluid
        class="py-0"
      >
        <ul
          class="ga-1 horizontal-navbar px-0"
          :class="customizer.boxed ? 'maxWidth' : ''"
        >
          <!-- - Menu Loop -->
          <li
            v-for="(item, i) in sidebarMenu"
            :key="i"
            class="navItem"
          >
            <!-- - If Has Child -->
            <NavCollapse
              v-if="item.children"
              :item="item"
              :level="0"
            />
            <!-- - Single Item -->
            <NavItem
              v-else
              :item="item"
            />
            <!-- - End Single Item -->
          </li>
        </ul>
      </VContainer>
    </div>
  </template>
  <div
    v-else
    class="mobile-menu"
  >
    <VerticalSidebar />
  </div>
</template>
