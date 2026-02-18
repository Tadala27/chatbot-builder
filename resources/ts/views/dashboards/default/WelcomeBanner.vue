<script setup lang="ts">
// assets
import Banner from '@images/analytics/welcome-banner.png'
</script>

<template>
  <VCard class="welcomeBanner text-white overflow-hidden" elevation="0" rounded="lg">
    <VCardText class="py-5 px-md-12 px-6">
      <VRow>
        <VCol cols="12" xl="6" md="7" sm="10">
          <div class="pb-md-8 pt-md-7 pt-5 pb-6">
            <h2 class="text-sm-h2 text-h3">
              Explore Redesigned NBS Bank
            </h2>
            <p class="text-h6 mb-7">
              The Brand new User Interface with power of Material-UI Components. Explore the Endless possibilities with
              NBS Bank.
            </p>
            <VBtn color="white" variant="outlined" rounded="md">
              Exclusive on Themeforest
            </VBtn>
          </div>
        </VCol>
        <VCol cols="12" xl="6" md="5" class="d-md-block d-none">
          <div class="text-right pr-8">
            <VImg :src="Banner" cover class="ms-auto" width="200" alt="welcome banner" />
          </div>
        </VCol>
      </VRow>
    </VCardText>
  </VCard>
</template>

<style lang="scss">
.welcomeBanner {
  background: rgb(var(--v-theme-darkprimary));
  position: relative;

  &::after {
    content: '';
    background-image: url(@images/analytics/img-dropbox-bg.svg);
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: -1;
    opacity: 0.5;
    background-position: bottom right;
    background-size: 100%;
    background-repeat: no-repeat;
  }
}
</style>
