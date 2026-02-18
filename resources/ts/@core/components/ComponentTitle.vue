<script setup lang="ts">
const props = defineProps({
  title: String,
  subContent: String,
  path: String,
  link: String,
})
</script>

// ===============================|| Component title ||=============================== //
<template>
  <VRow class="mb-0 mt-n3">
    <VCol
      cols="12"
      md="12"
    >
      <VCard
        elevation="0"
        variant="text"
      >
        <VRow
          no-gutters
          class="align-center"
        >
          <VCol sm="12">
            <h2 class="text-h2 mb-2 font-weight-bold">
              {{ props.title }}
            </h2>
            <h6 class="text-h6 text-lightText mb-2">
              {{ props.subContent }}
            </h6>
            <div class="d-flex align-center mb-2 text-caption text-lightText">
              <svg
                class="pc-icon me-2"
                style="width: 14px; height: 14px"
              >
                <use xlink:href="/assets/svg/sprite.svg#custom-code" />
              </svg>
              <span>{{ props.path }}</span>
            </div>
            <VBtn
              :href="props.link"
              class="d-inline-flex align-center font-weight-medium"
              variant="tonal"
              color="secondary"
              rounded="md"
              target="_"
            >
              <svg
                class="pc-icon me-2"
                style="width: 16px; height: 16px"
              >
                <use xlink:href="/assets/svg/sprite.svg#custom-link1" />
              </svg>
              Reference
            </VBtn>
          </VCol>
        </VRow>
      </VCard>
    </VCol>
  </VRow>
</template>
