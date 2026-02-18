<script setup lang="ts">
import { useDisplay } from 'vuetify'
import { useIspValue } from '@/utils/useIspValue'

const { xs } = useDisplay()

const { ispValue } = useIspValue()
</script>

<template>
  <VBtn
    v-if="!xs"
    color="error"
    :href="ispValue === true ? 'https://1.envato.market/6e0Arm' : 'https://1.envato.market/zNkqj6'"
    target="_"
    variant="flat"
    size="large"
    class="floting-button"
    rounded="pill"
  >
    <template #prepend>
      <SvgSprite name="custom-shopping-cart" />
    </template>
    Buy now
    <VTooltip
      activator="parent"
      aria-label="tooltip"
      content-class="smallTooltip"
      location="top"
    >
      Buy now
    </VTooltip>
  </VBtn>
  <VBtn
    v-if="xs"
    color="error"
    :href="ispValue === true ? 'https://1.envato.market/6e0Arm' : 'https://1.envato.market/zNkqj6'"
    target="_"
    variant="flat"
    size="large"
    class="floting-button"
    icon
  >
    <SvgSprite name="custom-shopping-cart" />
  </VBtn>
</template>

<style lang="scss" scoped>
.floting-button {
  position: fixed;
  bottom: 50px;
  right: 30px;
  z-index: 1023;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0% {
    box-shadow: 0 0 0 0 rgba(var(--v-theme-error), 0.3);
  }

  70% {
    box-shadow: 0 0 0 20px rgba(var(--v-theme-error), 0);
  }

  100% {
    box-shadow: 0 0 0 0 rgba(var(--v-theme-error), 0);
  }
}
</style>
