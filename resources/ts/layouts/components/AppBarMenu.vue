<script setup lang="ts">
import { ref } from 'vue'
import { useDisplay } from 'vuetify'
import { useIspValue } from '@/utils/useIspValue'
import Logo from '@layouts/components/LogoMain.vue'

defineEmits(['sToggle'])

const url = ref<string>() // Define url as a ref

const { ispValue } = useIspValue()

if (ispValue.value === true)
  url.value = 'https://1.envato.market/6e0Arm'
else
  url.value = 'https://1.envato.market/zNkqj6'

const { mdAndUp } = useDisplay()
</script>

<template>
  <VAppBar
    elevation="0"
    flat
    height="69"
    class="app-header border-bottom position-fixed"
    color="containerBg"
    border="0"
  >
    <VContainer class="fill-height maxWidth">
      <div class="d-flex align-center ga-2 w-100">
        <Logo />
        <!-- - /Search part -->
        <VSpacer />
        <!-- ---------------------------------------------- -->
        <!-- - right part -->
        <!-- ---------------------------------------------- -->
        <template v-if="mdAndUp">
          <VBtn
            variant="text"
            class="font-weight-medium"
            :to="ispValue === true ? '/dashboards/default?isp=1' : '/dashboards/default'"
            target="_"
          >
            Dashboard
          </VBtn>
          <VBtn
            variant="text"
            class="font-weight-medium"
            color="primary"
            :to="ispValue === true ? '/forms/components/buttons?isp=1' : '/forms/components/buttons'"
          >
            Components
          </VBtn>
          <VBtn
            variant="text"
            class="font-weight-medium"
            href="https://phoenixcoded.gitbook.io/able-pro"
            target="_"
          >
            Documentation
          </VBtn>
          <VBtn
            icon
            variant="tonal"
            target="_"
            aria-label="github link"
            href="https://github.com/phoenixcoded/able-pro-free-admin-dashboard-template"
          >
            <SvgSprite
              name="custom-github-outline"
              style="width: 20px; height: 20px"
            />
          </VBtn>
          <VBtn
            variant="flat"
            class="font-weight-medium ml-4"
            height="42px"
            color="success"
            rounded="md"
            :href="url"
            target="_"
          >
            <template #prepend>
              <SvgSprite
                name="custom-link2"
                style="width: 20px; height: 20px"
              />
            </template>
            Purchase Now
          </VBtn>
          <VBtn
            icon
            rounded="sm"
            variant="text"
            size="small"
            class="d-lg-none d-md-block"
            @click="$emit('sToggle')"
          >
            <SvgSprite
              name="custom-menu-outline"
              style="width: 20px; height: 20px"
            />
          </VBtn>
        </template>
        <template v-else>
          <VBtn
            variant="outlined"
            color="warning"
            rounded="md"
            :to="ispValue === true ? '/dashboards/default?isp=1' : '/dashboards/default'"
            target="_"
          >
            Dashboard
          </VBtn>
          <VBtn
            icon
            rounded="sm"
            variant="text"
            size="small"
            @click="$emit('sToggle')"
          >
            <SvgSprite
              name="custom-menu-outline"
              style="width: 20px; height: 20px"
            />
          </VBtn>
        </template>
      </div>
    </VContainer>
  </VAppBar>
</template>
