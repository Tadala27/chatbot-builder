<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useDisplay } from 'vuetify'

import VerticalSidebar from './components/ComponentVerticalSidebar.vue'
import AppBarMenu from './components/AppBarMenu.vue'
import LoaderWrapper from './components/LoaderWrapper.vue'
import { useCustomizerStore } from '@layouts/stores/customizer'

const customizer = useCustomizerStore()

// Call DirAttrSet to set the initial direction attribute when the component is mounted
onMounted(() => {
  DirAttrSet(customizer.isRtl ? 'rtl' : 'ltr')
})

// Watch for changes in the isRtl property and update the direction attribute accordingly
watch(() => customizer.isRtl, newValue => {
  DirAttrSet(newValue ? 'rtl' : 'ltr')
})

const { lgAndUp } = useDisplay()
const toggleSide = ref(false)
</script>

<template>
  <VLocaleProvider :rtl="customizer.isRtl">
    <VApp :theme="customizer.actTheme" class="component-wrapper" :class="[
      customizer.actTheme,
      customizer.fontTheme,
      customizer.inputBg ? 'inputWithbg' : '',
      customizer.themeContrast ? 'contrast' : '',
    ]">

      <AppBarMenu @s-toggle="toggleSide = !toggleSide" />
      <VMain class="page-wrapper">
        <VContainer>
          <VRow class="mt-lg-8 mb-0">
            <VCol v-if="!toggleSide && lgAndUp" lg="3">
              <VerticalSidebar />
            </VCol>
            <VCol lg="9">
              <!-- Loader start -->
              <LoaderWrapper />
              <!-- Loader end -->
              <RouterView />
            </VCol>
          </VRow>
        </VContainer>
        <VNavigationDrawer v-if="!lgAndUp" v-model="toggleSide" temporary width="300" top
          style="height: calc(100% - 69px) !important">
          <VerticalSidebar />
        </VNavigationDrawer>
      </VMain>
    </VApp>
  </VLocaleProvider>
</template>
