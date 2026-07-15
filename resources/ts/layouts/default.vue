<script lang="ts" setup>
import { ref, onMounted, watch, computed } from "vue";
import { useTheme, useDisplay } from "vuetify";
import LoaderWrapper from "./components/LoaderWrapper.vue";
import ActionEditor from "./components/ActionTree.vue";
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

onMounted(() => {
  DirAttrSet(customizer.isRtl ? "rtl" : "ltr");
});

watch(
  () => customizer.isRtl,
  (newValue) => {
    DirAttrSet(newValue ? "rtl" : "ltr");
  },
);

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
      <ActionEditor />
      <!-- <Customizer /> -->

      <template v-if="!customizer.isHorizontalLayout">
        <IconSidebar />

        <VerticalHeaderVue @s-toggle="mobileDrawer = !mobileDrawer" />
      </template>

      <template v-else>
        <HorizontalHeader />
        <HorizontalSidebar />
      </template>

      <VMain
        class="page-wrapper"
        :style="!customizer.isHorizontalLayout ? 'padding-left: 30px;' : ''"
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