import { ref, reactive, computed } from "vue";
import { defineStore } from "pinia";
import config from "@config";
import { DirAttrSet } from "@/utils/utils";

export const useCustomizerStore = defineStore("customizer", () => {
  const sidebarDrawer = ref(config.sidebarDrawer);
  const customizerDrawer = ref(config.customizerDrawer);
  const miniSidebar = ref(config.miniSidebar);
  const isHorizontalLayout = ref(config.isHorizontalLayout);
  const actTheme = ref(config.actTheme);
  const fontTheme = ref(config.fontTheme);
  const inputBg = ref(config.inputBg);
  const themeContrast = ref(config.themeContrast);
  const boxed = ref(config.boxed);
  const isRtl = ref(config.isRtl);

  const SET_SIDEBAR_DRAWER = () => {
    sidebarDrawer.value = !sidebarDrawer.value;
  };

  const SET_MINI_SIDEBAR = (payload: boolean) => {
    miniSidebar.value = payload;
  };

  const SET_CUSTOMIZER_DRAWER = (payload: boolean) => {
    customizerDrawer.value = payload;
  };

  const SET_LAYOUT = (payload: boolean) => {
    isHorizontalLayout.value = payload;
  };

  const SET_THEME = (payload: string) => {
    actTheme.value = payload;
  };

  const SET_FONT = (payload: string) => {
    fontTheme.value = payload;
  };

  const SET_DIRECTION = (dir: "ltr" | "rtl") => {
    isRtl.value = dir === "rtl";
    DirAttrSet(dir);
  };

  return {
    sidebarDrawer,
    customizerDrawer,
    miniSidebar,
    isHorizontalLayout,
    actTheme,
    fontTheme,
    inputBg,
    themeContrast,
    boxed,
    isRtl,
    SET_SIDEBAR_DRAWER,
    SET_MINI_SIDEBAR,
    SET_CUSTOMIZER_DRAWER,
    SET_LAYOUT,
    SET_THEME,
    SET_FONT,
    SET_DIRECTION,
  };
});
