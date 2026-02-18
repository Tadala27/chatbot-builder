<script setup lang="ts">
// Assets
import avatarGroup from '@images/users/avatar-group.png'
</script>

<template>
  <VSheet
    rounded="md"
    color="containerBg"
    class="pa-4 ExtraBox hide-menu text-center pb-6"
    border
  >
    <div class="d-flex align-center flex-column">
      <VImg
        :src="avatarGroup"
        alt="book"
        class="mb-3"
        width="169px"
        cover
      />
      <div class="px-3">
        <h5 class="text-h5 mb-1 line-height-none">
          Need Support?
        </h5>
        <small class="text-lightText text-h6">1 Day Response Time</small>
      </div>
    </div>
    <div class="mt-5">
      <a
        href="https://phoenixcoded.authordesk.app/"
        target="_blank"
        rel="noopener noreferrer"
        class="v-btn v-theme--DefaultTheme bg-primary v-btn--density-default v-btn--size-default v-btn--variant-flat primary-shadow rounded-md"
      >Get Support</a>
    </div>
  </VSheet>
</template>

<style lang="scss">
.ExtraBox {
  position: relative;
  overflow: hidden;
}
.line-height-none {
  line-height: normal;
}
</style>
