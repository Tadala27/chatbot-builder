<script setup lang="ts">
definePage({
  meta: {
    layout: 'blank',
    unauthenticatedOnly: false,
  },
})
</script>

<template>
  <VRow no-gutters class="overflow-hidden bg-containerBg" style="min-height: 100vh">
    <VCol class="d-flex align-center justify-center">
      <div class="text-center">
        <div class="errorMedia">
          <img src="@images/maintenance/img-error-500.svg" alt="404">
        </div>
        <h1 class="text-md-h1 text-h2 mt-6 mb-1">
          Internal Server Error
        </h1>
        <p class="text-caption text-lightText">
          Server error 500. we fixing the problem. please try
          <br>again at a later stage.
        </p>
        <VBtn variant="flat" color="primary" rounded="md" class="mt-4" :to="{ name: 'dashboard' }">
          Back To Home
        </VBtn>
      </div>
    </VCol>
  </VRow>
</template>

<style lang="scss">
.errorMedia {
  >img {
    @media (min-width: 0px) {
      width: 310px;
    }

    @media (min-width: 768px) {
      width: 396px;
    }
  }
}
</style>
