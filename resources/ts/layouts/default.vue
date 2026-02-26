<script lang="ts" setup>
import { onMounted, watch, computed } from "vue";
import { useTheme } from "vuetify";
import LoaderWrapper from "./components/LoaderWrapper.vue";
import ActionEditor from "./components/ActionEditor.vue"; // ✅ ADD THIS
import Customizer from "./components/CustomizerPanel.vue"; // ✅ ADD THIS
import VerticalSidebarVue from "./components/VerticalSidebar.vue";
import VerticalHeaderVue from "./components/VerticalHeader.vue";
import HorizontalSidebar from "./components/HorizontalSidebar.vue";
import HorizontalHeader from "./components/HorizontalHeader.vue";
import FooterPanel from "./components/Footer.vue";
import { useCustomizerStore } from "@layouts/stores/customizer";
import { DirAttrSet, HexToRgb } from "@/utils/utils";

const theme = useTheme();
const customizer = useCustomizerStore();

// Set the initial direction attribute when the component is mounted
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

// Define the computed property to calculate the dynamic style object
const dynamicStyle = computed(() => ({
  "--v-theme-primary": HexToRgb(theme.current.value.colors.primary),
  "--v-theme-darkprimary": HexToRgb(theme.current.value.colors.darkprimary),
  "--v-theme-lightprimary": HexToRgb(theme.current.value.colors.lightprimary),
}));

// Method to conditionally apply the preset class
const getStyleObject = () => {
  // Define your condition here, for example:
  const condition = true; // Replace this with your actual condition
  return condition ? dynamicStyle.value : {};
};
</script>

<template>
  <VLocaleProvider :rtl="customizer.isRtl">
    <VApp
      :style="getStyleObject()"
      :theme="customizer.actTheme"
      :class="[
        customizer.actTheme,
        customizer.fontTheme,
        customizer.miniSidebar ? 'mini-sidebar' : '',
        customizer.isHorizontalLayout ? 'horizontalLayout' : 'verticalLayout',
        customizer.inputBg ? 'inputWithbg' : '',
        customizer.themeContrast ? 'contrast' : '',
      ]"
    >
      <!-- ✅ ADD ACTION EDITOR DRAWER HERE - Same level as Customizer -->
      <ActionEditor />

      <Customizer />
      <VerticalSidebarVue v-if="!customizer.isHorizontalLayout" />
      <VerticalHeaderVue v-if="!customizer.isHorizontalLayout" />
      <HorizontalHeader v-if="customizer.isHorizontalLayout" />
      <HorizontalSidebar v-if="customizer.isHorizontalLayout" />

      <VMain class="page-wrapper">
        <VContainer fluid>
          <div :class="customizer.boxed ? 'maxWidth' : ''">
            <!-- Loader start -->
            <LoaderWrapper />
            <!-- Loader end -->
            <RouterView />
          </div>
        </VContainer>
        <VContainer fluid class="pt-0">
          <div :class="customizer.boxed ? 'maxWidth' : ''">
            <FooterPanel />
          </div>
        </VContainer>
      </VMain>
    </VApp>
  </VLocaleProvider>
</template>
