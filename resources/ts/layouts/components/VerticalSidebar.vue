<script setup lang="ts">
import { onMounted } from 'vue'
import { useCustomizerStore } from '@layouts/stores/customizer'
import { useUserStore } from '@/stores/user'
import { sidebarItems } from '@layouts/components/vertical-sidebar/sidebarItem'
import NavGroup from '@layouts/components/vertical-sidebar/NavGroup/NavGroup.vue'
import NavItem from '@layouts/components/vertical-sidebar/NavItem/NavItem.vue'
import NavCollapse from '@layouts/components/vertical-sidebar/NavCollapse/NavCollapse.vue'
import Logo from '@layouts/components/LogoMain.vue'

const customizer = useCustomizerStore()
const userStore = useUserStore()

// Ensure user is loaded when component mounts
onMounted(async () => {
  if (!userStore.isLoaded) {
    await userStore.fetchUser()
  }
})
</script>

<template>
  <VNavigationDrawer v-model="customizer.sidebarDrawer" left elevation="0" rail-width="90" mobile-breakpoint="lg" app
    width="279" class="leftSidebar" :rail="customizer.miniSidebar" expand-on-hover>
    <!-- Logo part -->

    <!-- Navigation -->
    <div class="pa-5">
      <Logo />
    </div>
    <PerfectScrollbar class="scrollnavbar" :options="{ suppressScrollX: true }">
      <VList aria-busy="true" class="px-2" aria-label="menu list">
        <!-- Loading State -->
        <div v-if="!userStore.isLoaded || userStore.isLoading" class="d-flex justify-center align-center py-8">
          <VProgressCircular indeterminate color="primary" size="32" />
        </div>

        <!-- Error State -->
        <div v-else-if="userStore.error" class="d-flex flex-column align-center py-4 px-4">
          <VIcon color="error" size="32" class="mb-2">$alertCircleOutline</VIcon>
          <span class="text-caption text-center text-error">{{ userStore.error }}</span>
          <VBtn variant="text" size="small" color="primary" class="mt-2" @click="userStore.fetchUser">
            Retry
          </VBtn>
        </div>

        <!-- Menu Items -->
        <template v-else>
          <template v-for="(item, i) in sidebarItems" :key="i">

            <!-- Item Divider -->
            <VDivider v-if="item.divider" class="my-3" />
            <!-- If Has Child -->
            <NavCollapse v-else-if="item.children" class="leftPadding" :item="item" :level="0" />
            <!-- Single Item -->
            <NavItem v-else :item="item" />
          </template>
        </template>
      </VList>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>