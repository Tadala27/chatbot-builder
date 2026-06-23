<script setup lang="ts">
definePage({
  meta: {
    layout: 'blank',
    public: true,
  },
})
</script>

<template>
  <VRow no-gutters class="overflow-hidden bg-containerBg" style="min-height: 100vh">
    <VCol class="d-flex align-center justify-center">
      <div class="text-center">
        <div class="CardMediaWrapper">
          <img src="@images/maintenance/img-error-404.svg" alt="404">
        </div>
        <h1 class="text-h1 mt-16 mb-2">
          Page Not Found
        </h1>
        <p class="text-h6 text-lightText">
          The page you are looking was moved, removed, <br>renamed, or might never exist!
        </p>
        <VBtn variant="flat" color="primary" class="mt-3" rounded="md" :to="{ name: 'dashboard' }">
          Back To Home
        </VBtn>
      </div>
    </VCol>
  </VRow>
</template>

<style lang="scss">
.CardMediaWrapper {
  max-width: 720px;
  margin: 0 auto;
  position: relative;

  >img {
    @media (min-width: 0px) {
      width: 250px;
      height: 130px;
    }

    @media (min-width: 768px) {
      width: 590px;
      height: 300px;
    }
  }
}
</style>
