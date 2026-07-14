import { type ThemeDefinition, createVuetify } from "vuetify";
import { aliases, mdi } from "vuetify/iconsets/mdi-svg";
import * as components from "vuetify/components";
import * as directives from "vuetify/directives";
import { icons } from "./mdi-icon";

export const PrimaryColor = "#273775";
export const PrimaryDarkColor = "#1c2a5c";
export const PrimaryLightColor = "#6e8bab";
export const PrimaryLightColorForDark = "#18243e";

// info is now the carrier of the secondary brand color (#27a7df) — sky
// blue. secondary itself stays the neutral slate it always was; components
// that previously used color="secondary" for the brand accent should
// switch to color="info" instead (see GaugeStat.vue / dashboard updates).
export const InfoColor = "#27a7df";
export const InfoDarkColor = "#1f8bb8";
export const InfoLightColor = "#7fc8e8";
export const InfoLightColorForDark = "#123347";

const light: ThemeDefinition = {
  dark: false,
  colors: {
    primary: PrimaryColor,
    secondary: "#6e8bab",
    info: InfoColor,
    success: "#85bc33",
    warning: "#e58a00",
    error: "#dc2626",
    lightprimary: PrimaryLightColor,
    lightsecondary: "#fefefe",
    lightinfo: "#d6eef8",
    lightsuccess: "#d7efc2",
    lighterror: "#f5bebe",
    lightwarning: "#f7dcb3",
    darkText: "#0b3f74",
    lightText: "#6e8bab",
    darkprimary: PrimaryDarkColor,
    darksecondary: "#305b89",
    darkinfo: InfoDarkColor,
    darksuccess: "#6ba02e",
    darkwarning: "#de7700",
    darkerror: "#d31c1c",
    borderLight: "#e8ebee",
    inputBorder: "#BEC8D0",
    containerBg: "#F8F9FA",
    gray: "#f1f2f4",
    surface: "#fff",
    "on-surface-variant": "#fff",
    facebook: "#4267b2",
    twitter: "#1da1f2",
    linkedin: "#0e76a8",
    gray100: "#f3f5f7",
    primary200: "#6e8bab",
    secondary200: "#d8dadd",
    warning200: "#faaf00",
    white: "#ffffffff",
  },
  variables: {
    "border-color": "#e8ebee",
    "carousel-control-size": 10,
    gradient:
      "linear-gradient(to right, rgb(var(--v-theme-darkprimary)), rgb(var(--v-theme-primary)))",
    "card-shadow": "0px 8px 24px rgba(19, 25, 32, 0.08)",
    "shadow-key-umbra-color": "#13192014",
    "high-opacity": 1,
    "medium-opacity": 0.85,
    "half-opacity": 0.5,
    "shadow-opacity": 0.08,
  },
};

const dark: ThemeDefinition = {
  dark: true,
  colors: {
    primary: PrimaryColor,
    secondary: "#6e8bab",
    info: InfoColor,
    success: "#85bc33",
    warning: "#e58a00",
    error: "#dc2626",
    lightprimary: PrimaryLightColorForDark,
    lightsecondary: "#131920",
    lightinfo: InfoLightColorForDark,
    lightsuccess: "#cce5c0",
    lighterror: "#c3a4a4",
    lightwarning: "#fbf1e0",
    darkprimary: PrimaryDarkColor,
    darksecondary: "#305b89",
    darkinfo: InfoDarkColor,
    darksuccess: "#6ba02e",
    darkwarning: "#de7700",
    darkerror: "#d31c1c",
    darkText: "#fefefe",
    lightText: "#becdd2",
    borderLight: "#29313b",
    inputBorder: "#3E4853",
    containerBg: "#131920",
    surface: "#1D2630",
    background: "#1D2630",
    "on-surface-variant": "#1D2630",
    facebook: "#4267b2",
    twitter: "#1da1f2",
    linkedin: "#0e76a8",
    gray100: "#1D2630",
    primary200: "#305b89",
    secondary200: "#3E4853",
    warning200: "#faaf00",
  },
  variables: {
    "border-color": "#e8ebee",
    gradient:
      "linear-gradient(to right, rgb(var(--v-theme-darkprimary)), rgb(var(--v-theme-primary)))",
    "card-shadow": "0px 8px 24px rgba(62, 72, 83, 0.3)",
    "high-opacity": 1,
    "medium-opacity": 0.85,
    "half-opacity": 0.5,
    "shadow-opacity": 0.35,
  },
};

export default createVuetify({
  components,
  directives,
  icons: {
    defaultSet: "mdi",
    aliases: {
      ...aliases,
      ...icons,
    },
    sets: {
      mdi,
    },
  },
  theme: {
    defaultTheme: "light",
    themes: {
      light,
      dark,
    },
  },
  defaults: {
    VBtn: {},
    VCard: {
      rounded: "md",
    },
    VTextField: {
      rounded: "lg",
    },
    VTooltip: {
      location: "top",
    },
  },
});
