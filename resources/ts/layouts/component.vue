<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useDisplay } from "vuetify";
import IconSidebar from "./components/IconSidebar.vue";
import AppBarMenu from "./components/AppBarMenu.vue";
import LoaderWrapper from "./components/LoaderWrapper.vue";
import { useCustomizerStore } from "@layouts/stores/customizer";

const customizer = useCustomizerStore();

// Call DirAttrSet to set the initial direction attribute when the component is mounted
onMounted(() => {
  DirAttrSet(customizer.isRtl ? "rtl" : "ltr");
});

// Watch for changes in the isRtl property and update the direction attribute accordingly
watch(
  () => customizer.isRtl,
  (newValue) => {
    DirAttrSet(newValue ? "rtl" : "ltr");
  },
);

const { lgAndUp } = useDisplay();

// Mobile drawer — icon sidebar slides in on small screens
const mobileDrawer = ref(false);
</script>

<template>
  <VLocaleProvider :rtl="customizer.isRtl">
    <VApp
      :theme="customizer.actTheme"
      class="component-wrapper"
      :class="[
        customizer.actTheme,
        customizer.fontTheme,
        customizer.inputBg ? 'inputWithbg' : '',
        customizer.themeContrast ? 'contrast' : '',
      ]"
    >
      <!-- ── Desktop: permanent icon sidebar ────────────────────────────── -->
      <IconSidebar v-if="lgAndUp" />

      <!-- ── Mobile: icon sidebar inside a temporary drawer ────────────── -->
      <VNavigationDrawer
        v-if="!lgAndUp"
        v-model="mobileDrawer"
        temporary
        width="56"
      >
        <IconSidebar
          style="position: static; box-shadow: none; border-right: none"
        />
      </VNavigationDrawer>

      <!-- ── App bar — pass toggle for mobile hamburger ─────────────────── -->
      <AppBarMenu @s-toggle="mobileDrawer = !mobileDrawer" />

      <!-- ── Main content — offset by sidebar width on desktop ──────────── -->
      <VMain class="page-wrapper" :style="lgAndUp ? 'padding-left: 56px;' : ''">
        <VContainer>
          <!-- Loader start -->
          <LoaderWrapper />
          <!-- Loader end -->
          <RouterView />
        </VContainer>
      </VMain>
    </VApp>
  </VLocaleProvider>
</template>
