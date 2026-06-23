<script lang="ts" setup>
import { ref, onMounted, watch, computed } from "vue";
import { useTheme, useDisplay } from "vuetify";
import LoaderWrapper from "./components/LoaderWrapper.vue";
import ActionEditor from "./components/ActionEditor.vue";
// import Customizer from "./components/CustomizerPanel.vue";
import IconSidebar from "./components/IconSidebar.vue";
import VerticalHeaderVue from "./components/VerticalHeader.vue";
import HorizontalSidebar from "./components/HorizontalSidebar.vue";
import HorizontalHeader from "./components/HorizontalHeader.vue";
import FooterPanel from "./components/Footer.vue";
import { useCustomizerStore } from "@layouts/stores/customizer";
import { DirAttrSet, HexToRgb } from "@/utils/utils";

const theme = useTheme();
const customizer = useCustomizerStore();
const { lgAndUp } = useDisplay();

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

const getStyleObject = () => {
  const condition = true;
  return condition ? dynamicStyle.value : {};
};

const mobileDrawer = ref(false);
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
      <!-- Action editor drawer — same level as Customizer -->
      <ActionEditor />
      <!-- <Customizer /> -->

      <!-- ── Vertical layout: icon sidebar + header ────────────────────── -->
      <template v-if="!customizer.isHorizontalLayout">
        <!-- Desktop: permanent icon sidebar -->
        <IconSidebar v-if="lgAndUp" />

        <!-- Mobile: icon sidebar inside a temporary drawer -->
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

        <VerticalHeaderVue @s-toggle="mobileDrawer = !mobileDrawer" />
      </template>

      <!-- ── Horizontal layout: unchanged ───────────────────────────────── -->
      <template v-else>
        <HorizontalHeader />
        <HorizontalSidebar />
      </template>
      <VMain
        class="page-wrapper"
        :style="
          !customizer.isHorizontalLayout && lgAndUp ? 'padding-left: 30px;' : ''
        "
      >
        <VContainer fluid>
          <div :class="customizer.boxed ? 'maxWidth' : ''">
            <LoaderWrapper />
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
