<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { useTheme } from 'vuetify'
import { useCustomizerStore } from '@layouts/stores/customizer'

const customizer = useCustomizerStore()

const contrast = ref(true)
const layout = ref(true)
const rtlmode = ref(true)
const containerbox = ref(true)
const inputbackground = ref(true)

// Define an enum for theme values
enum Theme {
  Light = 'light',
  Dark = 'dark',
  System = 'system',
}

// Define a type for theme color options
interface ThemeColor {
  bg: string
  color: string
  darkColor: string
  lightcolor: string
  lightcolorfordark: string
  tooltipName: string
}

// Define theme colors as ref
const themeColors = ref<ThemeColor[]>([
  {
    bg: 'themeDefault',
    color: '#2a2c7e',
    darkColor: '#3F78FF',
    lightcolor: '#E9F0FF',
    lightcolorfordark: '#18243e',
    tooltipName: 'Preset 1',
  },
  {
    bg: 'themeLightTheme1',
    color: '#3366ff',
    darkColor: '#254EDB',
    lightcolor: '#D6E4FF',
    lightcolorfordark: '#1c2134',
    tooltipName: 'Preset 2',
  },
  {
    bg: 'themeLightTheme2',
    color: '#7265e6',
    darkColor: '#6A5DE3',
    lightcolor: '#EEEDFC',
    lightcolorfordark: '#222130',
    tooltipName: 'Preset 3',
  },
  {
    bg: 'themeLightTheme3',
    color: '#068e44',
    darkColor: '#006933',
    lightcolor: '#E6F3EC',
    lightcolorfordark: '#1a231f',
    tooltipName: 'Preset 4',
  },
  {
    bg: 'themeLightTheme4',
    color: '#3c64d0',
    darkColor: '#2947ab',
    lightcolor: '#f0f6ff',
    lightcolorfordark: '#1d212d',
    tooltipName: 'Preset 5',
  },
  {
    bg: 'themeLightTheme5',
    color: '#f27013',
    darkColor: '#cc5206',
    lightcolor: '#fff4e6',
    lightcolorfordark: '#32221a',
    tooltipName: 'Preset 6',
  },
  {
    bg: 'themeLightTheme6',
    color: '#2aa1af',
    darkColor: '#1a7b8a',
    lightcolor: '#e1f0ef',
    lightcolorfordark: '#1c2628',
    tooltipName: 'Preset 7',
  },
  {
    bg: 'themeLightTheme7',
    color: '#00a854',
    darkColor: '#008245',
    lightcolor: '#d1e8d99c',
    lightcolorfordark: '#1a2721',
    tooltipName: 'Preset 8',
  },
  {
    bg: 'themeLightTheme8',
    color: '#009688',
    darkColor: '#007069',
    lightcolor: '#c1d6d066',
    lightcolorfordark: '#1a2524',
    tooltipName: 'Preset 9',
  },
])

// Get the Vuetify theme instance
const vuetifyTheme = useTheme()

// Define refs for primary and dark primary colors
const customPrimaryColor = ref<string>('#2a2c7e')
const customDarkPrimaryColor = ref<string>('#3F78FF')

const customLightPrimaryColor = ref<string>('#E9F0FF')
const customLightPrimaryColorForDark = ref<string>('#000000')

// Watch for changes in the primary colors and update the theme accordingly
watch([customPrimaryColor, customDarkPrimaryColor, customLightPrimaryColor, customLightPrimaryColorForDark], ([newPrimaryColor, newDarkPrimaryColor, newLightPrimaryColor, newLightPrimaryColorForDark]) => {
  vuetifyTheme.themes.value.light.colors.primary = newPrimaryColor
  vuetifyTheme.themes.value.light.colors.darkprimary = newDarkPrimaryColor
  vuetifyTheme.themes.value.dark.colors.primary = newPrimaryColor
  vuetifyTheme.themes.value.dark.colors.darkprimary = newDarkPrimaryColor

  vuetifyTheme.themes.value.light.colors.lightprimary = newLightPrimaryColor
  vuetifyTheme.themes.value.dark.colors.lightprimary = newLightPrimaryColorForDark
})

// Function to set primary and dark primary colors
const setPrimaryColors = (primaryColor: string, darkPrimaryColor: string, lightPrimaryColor: string, lightPrimaryColorForDark: string) => {
  customPrimaryColor.value = primaryColor
  customDarkPrimaryColor.value = darkPrimaryColor

  customLightPrimaryColor.value = lightPrimaryColor
  customLightPrimaryColorForDark.value = lightPrimaryColorForDark
}

// Ref for the selected color index
const selectedColorIndex = ref<number>(0)

// Set the default primary and dark primary colors
setPrimaryColors(themeColors.value[selectedColorIndex.value].color, themeColors.value[selectedColorIndex.value].darkColor, themeColors.value[selectedColorIndex.value].lightcolor, themeColors.value[selectedColorIndex.value].lightcolorfordark)

// Function to select a color and update the primary and dark primary colors
const selectColor = (color: string, darkColor: string, lightcolor: string, lightcolorfordark: string, index: number) => {
  selectedColorIndex.value = index
  setPrimaryColors(color, darkColor, lightcolor, lightcolorfordark)
}

// Define font family options
const fontFamily = ref<string[]>(['Inter-var', 'Inter', 'Roboto', 'Poppins', 'Public sans'])

// for system mode
// Function to update the theme based on the user's preferred color scheme
const updateTheme = () => {
  const preferredColorScheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
  if (customizer.actTheme === Theme.System)
    customizer.SET_THEME(preferredColorScheme)
}

// Call updateTheme on component mounted
onMounted(() => {
  updateTheme()

  // Watch for changes in the user's preferred color scheme
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', updateTheme)
})

// Watch for changes in the customizer's theme setting
watch(() => customizer.actTheme, (newValue, oldValue) => {
  if (newValue === Theme.System && newValue !== oldValue)
    updateTheme()
})

// Function to clear options and reset to default
function clearOptions() {
  customizer.actTheme = 'light'
  customizer.isHorizontalLayout = false
  customizer.inputBg = false
  customizer.themeContrast = false
  customizer.boxed = false
  customizer.fontTheme = 'Inter-var'
  customizer.isRtl = false

  // Reset the selected color index to default (0)
  selectedColorIndex.value = 0

  // Set the default primary and dark primary colors
  setPrimaryColors(themeColors.value[0].color, themeColors.value[0].darkColor, themeColors.value[0].lightcolor, themeColors.value[0].lightcolorfordark)
}
</script>

<!-- --------------------------------- -->
<!-- Customizer -->
<!-- --------------------------------- -->
<template>
  <VBtn class="customizer-btn ml-sm-2 ml-1" icon color="surface" aria-label="customizer button" rounded="sm"
    variant="flat" @click.stop="customizer.SET_CUSTOMIZER_DRAWER(!customizer.customizerDrawer)">
    <div class="text-primary">
      <SvgSprite name="custom-setting-2" style="width: 28px; height: 28px" />
    </div>
  </VBtn>
  <VNavigationDrawer v-model="customizer.customizerDrawer" app temporary elevation="24" location="end" border="0"
    width="360" class="customizer-panel" style="z-index: 1024 !important">
    <VRow class="ma-0">
      <VCol cols="12" class="pa-0">
        <div class="pa-5 d-flex justify-space-between align-center">
          <div class="text-h5">
            Settings
          </div>
          <div>
            <VBtn variant="text" icon aria-label="close" color="error" rounded="md" density="compact"
              @click.stop="customizer.SET_CUSTOMIZER_DRAWER(!customizer.customizerDrawer)">
              <SvgSprite name="custom-close" style="width: 18px; height: 18px; transform: rotate(45deg)" />
            </VBtn>
          </div>
        </div>
      </VCol>
    </VRow>
    <PerfectScrollbar style="height: calc(100vh - 69px)">
      <div>
        <VRow class="ma-0">
          <VCol cols="12" class="pa-0">
            <div class="px-6">
              <!-- --------------------------------- -->
              <!-- start Light/Dark/System Mode -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0">
                Theme Mode
              </h6>
              <p class="text-caption mb-0">
                Choose light or dark mode
              </p>
              <VRadioGroup v-model="customizer.actTheme" class="custom-radio mx-n2 mt-2 mb-3" hide-details>
                <VRadio :value="Theme.Light" color="primary" class="ma-2 pa-1 text-center" label="Light"
                  style="height: 60px;display: flex;">
                  <SvgSprite name="custom-sun" style="width: 24px; height: 24px" />
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Light</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="Theme.Dark" color="primary" class="ma-2 pa-1 text-center" label="Dark"
                  style="height: 60px;display: flex;">
                  <SvgSprite name="custom-moon-fill" class="text-darkText" style="width: 24px; height: 24px" />
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Dark</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="Theme.System" color="primary" class="ma-2 pa-1 text-center" label="System"
                  style="height: 60px;display: flex;">
                  <SvgSprite name="custom-setting-fill" class="text-darkText" style="width: 24px; height: 24px" />
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">System</span>
                  </VTooltip>
                </VRadio>
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- end Light/Dark/System Mode -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- Theme contrast -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Theme Contrast
              </h6>
              <p class="text-caption mb-0">
                Choose theme contrast/shadow
              </p>
              <VRadioGroup v-model="customizer.themeContrast" class="custom-radio mx-n2 mt-2 mb-3" hide-details>
                <VRadio :value="contrast" color="primary" class="ma-2 pa-1 text-center" label="contrast"
                  style="height: 60px;display: flex;">
                  <SvgSprite name="custom-contrast-fill" style="width: 24px; height: 24px" />
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Contrast</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="false" color="primary" class="ma-2 pa-1 text-center" label="default"
                  style="height: 60px;display: flex;">
                  <SvgSprite name="custom-contrast-outline" style="width: 24px; height: 24px" />
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Default</span>
                  </VTooltip>
                </VRadio>
              </VRadioGroup>
              <!-- --------------------------------- -->
              <!-- end Theme contrast -->
              <!-- --------------------------------- -->
              <VDivider />
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Menu Orientation
              </h6>
              <p class="text-caption mb-0">
                Choose Vertical or Horizontal Menu Orientation
              </p>
              <!-- sidebar layout -->
              <VRadioGroup v-model="customizer.isHorizontalLayout" class="custom-radio mx-n2 mt-2 mb-3" hide-details>
                <VRadio :value="false" color="primary" class="ma-2 text-center" label="Vertical">
                  <img src="@images/customizer/vertical.svg" alt="menu layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Vertical</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="layout" color="primary" class="ma-2 text-center" label="Horizontal">
                  <img src="@images/customizer/horizontal.svg" alt="menu layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Horizontal</span>
                  </VTooltip>
                </VRadio>
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- Preset color -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Color Scheme
              </h6>
              <p class="text-caption mb-0">
                Choose your primary theme color
              </p>
              <VCardItem class="pa-0 mt-3 mb-4">
                <VCardText class="px-1 py-0 colors-scheme">
                  <VItemGroup mandatory class="d-flex flex-wrap ga-1">
                    <VItem v-for="(theme, index) in themeColors" :key="theme.color">
                      <div class="d-flex flex-column bg-gray100 rounded-md"
                        :value="{ primary: theme.color, darkPrimary: theme.darkColor, lightprimary: theme.lightcolor || theme.lightcolorfordark }"
                        :class="{
                          Selected: vuetifyTheme.current.value.colors.primary === theme.color,
                        }"
                        @click="selectColor(theme.color, theme.darkColor, theme.lightcolor, theme.lightcolorfordark, index)">
                        <div :class="theme.bg" style="padding: 8px; height: 68px; border-radius: 4px">
                          <div class="text-surface">
                            <SvgSprite :class="{
                              'd-none': vuetifyTheme.current.value.colors.primary !== theme.color,
                            }" name="custom-checked" style="width: 24px; height: 24px" />
                          </div>
                        </div>
                        <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                          <span class="text-caption">{{ theme.tooltipName }}</span>
                        </VTooltip>
                      </div>
                    </VItem>
                  </VItemGroup>
                </VCardText>
              </VCardItem>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- end Preset color -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- RTL layout -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Theme Layout
              </h6>
              <p class="text-caption mb-0">
                Choose your layout
              </p>
              <VRadioGroup v-model="customizer.isRtl" class="custom-radio mx-n2 mt-2 mb-3" hide-details>
                <VRadio :value="false" color="primary" class="ma-2 text-center" label="LTR">
                  <img src="@images/customizer/vertical.svg" alt="layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">LTR</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="rtlmode" color="primary" class="ma-2 text-center" label="RTL">
                  <img src="@images/customizer/rtl.svg" alt="layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">RTL</span>
                  </VTooltip>
                </VRadio>
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- RTL layout -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- Boxed Container -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Layout Width
              </h6>
              <p class="text-caption mb-0">
                Choose fluid or container layout
              </p>
              <VRadioGroup v-model="customizer.boxed" class="custom-radio mx-n2 mt-2 mb-3" hide-details>
                <VRadio :value="false" color="primary" class="ma-2 text-center" label="Fluid">
                  <img src="@images/customizer/fluid.svg" alt="layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Fluid</span>
                  </VTooltip>
                </VRadio>
                <VRadio :value="containerbox" color="primary" class="ma-2 text-center" label="Container">
                  <img src="@images/customizer/container.svg" alt="layout">
                  <VTooltip activator="parent" location="bottom" content-class="smallTooltip">
                    <span class="text-caption">Container</span>
                  </VTooltip>
                </VRadio>
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- End Box Container -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- Input Outlined With Filled -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Input Background
              </h6>
              <p class="text-caption mb-0">
                Choose option with background or without background for input
              </p>
              <VRadioGroup v-model="customizer.inputBg" class="custom-radio input-radio mx-n2 mt-2 mb-4" hide-details>
                <VRadio :value="inputbackground" color="primary" class="ma-2 input-bg px-3" label="With Background" />
                <VRadio :value="false" color="primary" class="ma-2 without-bg px-3" label="No Background" />
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- End Input Outlined With Filled -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <!-- Font Family -->
              <!-- --------------------------------- -->
              <h6 class="text-subtitle-1 mb-0 mt-5">
                Font Family
              </h6>
              <p class="text-caption mb-0">
                Choose your font family.
              </p>
              <VRadioGroup v-model="customizer.fontTheme" hide-details class="custom-font mt-2 mb-3">
                <VRadio v-for="font in fontFamily" :key="font" :value="font" color="primary" class="mb-2 text-center">
                  <template #label>
                    <h5 :class="`${font} text-h5 mb-0`">
                      Aa
                    </h5>
                    <span class="text-caption text-lightText">{{ font }}</span>
                  </template>
                </VRadio>
              </VRadioGroup>
              <VDivider />
              <!-- --------------------------------- -->
              <!-- end Font Family -->
              <!-- --------------------------------- -->
              <!-- --------------------------------- -->
              <div class="d-flex py-4">
                <VBtn color="error" variant="tonal" rounded="md" block @click="clearOptions">
                  Reset
                </VBtn>
              </div>
            </div>
          </VCol>
        </VRow>
      </div>
    </PerfectScrollbar>
  </VNavigationDrawer>
</template>

<style lang="scss">
@keyframes progress-circular-rotate {
  100% {
    transform: rotate(270deg);
  }
}

.customizer-btn {
  top: 14%;
  position: fixed;
  right: 0;
  z-index: 1009;
  padding: 17px;
  --v-btn-height: unset;
  border-top-left-radius: 30px !important;
  border-bottom-left-radius: 30px !important;
  box-shadow: rgb(var(--v-card-shadow));

  [dir="rtl"] & {
    right: unset;
    left: 0;
    border-radius: 0 30px 30px 0 !important;
  }

  .pc-icon {
    animation: progress-circular-rotate 1.4s linear infinite;
    transform-origin: center center;
    transition: all 0.2s ease-in-out;
  }
}

.customizer-accordion {
  .v-expansion-panel-title {
    padding: 16px;
  }

  .v-expansion-panel {
    border-width: 0;
    border-top: 1px solid rgb(var(--v-theme-borderLight));
    border-radius: 0;

    &.v-expansion-panel--active {
      .v-expansion-panel-title--active {
        .v-expansion-panel-title__overlay {
          background: transparent;
        }
      }
    }

    .v-expansion-panel-text__wrapper {
      padding: 16px;
      border-top: none;
    }
  }

  .v-expansion-panel-title {
    &:hover {
      >.v-expansion-panel-title__overlay {
        opacity: 0;
      }
    }
  }
}

.custom-radio {
  .v-selection-control-group {
    flex-direction: row;

    .v-selection-control {
      align-items: center;
      justify-content: center;
      flex: 1 0 0%;
      display: block;
      border-radius: 12px;
      box-shadow: 0 0 0 2px rgb(var(--v-theme-borderLight));

      .v-label {
        opacity: 0;
        position: absolute;
        width: 100%;
        height: 100%;
        left: 0;
        top: 0;
      }

      &.v-selection-control--dirty {
        box-shadow: 0 0 0 2px rgb(var(--v-theme-primary));
        width: 100%;
        flex: 1;
      }

      .v-selection-control__wrapper {
        z-index: 1;
        width: unset;
        height: unset;
        display: block;

        img {
          height: 62px;
          margin: 8px;
        }

        .v-selection-control__input {
          opacity: 0;
          position: absolute;
          width: 100%;
          height: 100%;
          top: 0;
          left: 0;
        }
      }
    }
  }
}

.colors-scheme {
  .Selected {
    width: 78px;
  }
}

.input-bg {
  background-color: rgb(var(--v-theme-containerBg)) !important;
}

.without-bg {
  background-color: rgb(var(--v-theme-surface)) !important;
}

.input-radio {
  .v-selection-control-group {
    .v-selection-control {
      height: 30px;
      width: 100%;
      border-radius: 4px;

      .v-label {
        padding-top: 0;
        opacity: 1;
        position: relative;
        font-size: 0.75rem;
      }
    }
  }
}

.custom-font {
  .v-selection-control-group {
    flex-direction: row;
    flex-wrap: wrap;

    .v-selection-control__wrapper {
      display: none;
    }

    .v-selection-control {
      border-radius: 4px;
      margin: 6px;
      padding: 8px;
      min-width: 80px;
      box-shadow: 0 0 0 2px rgb(var(--v-theme-borderLight));

      .v-label {
        display: block;
        text-align: center;
        --v-medium-emphasis-opacity: 1;
      }

      &.v-selection-control--dirty {
        box-shadow: 0 0 0 2px rgb(var(--v-theme-primary));
      }
    }
  }

  .v-selection-control {
    justify-content: center;
  }
}
</style>
