<script setup lang="ts">
interface Breadcrumb {
  title: string
  disabled: boolean
  href: string
}

const props = defineProps({
  title: String,
  description: String,
  breadcrumbs: Array as () => Breadcrumb[],
  icon: String,
  showBackButton: {
    type: Boolean,
    default: true
  }
})

const goBack = () => {
  window.history.back()
}
</script>

// ===============================|| Theme Breadcrumb ||=============================== //
<template>
  <VRow class="page-breadcrumb mb-0 mt-n2">
    <VCol cols="12" md="12">
      <VCard elevation="0" variant="text">
        <VRow no-gutters class="align-center">
          <VCol sm="12">
            <!-- Breadcrumbs Row with Back Button -->
            <div class="d-flex align-center justify-space-between w-100">
              <VBreadcrumbs :items="props.breadcrumbs" class="text-h6 pa-1 mb-0 flex-grow-1">
                <template #divider>
                  <div class="d-flex align-center">
                    <SvgSprite name="custom-chevron-outline" style="width: 12px; height: 12px" />
                  </div>
                </template>
                <template #prepend>
                  <RouterLink to="/dashboard" class="text-darkText text-h6 text-decoration-none">
                    Home
                  </RouterLink>
                  <div class="d-flex align-center px-2">
                    <SvgSprite name="custom-chevron-outline" style="width: 12px; height: 12px" />
                  </div>
                </template>
              </VBreadcrumbs>

              <v-tooltip location="top">
                <template #activator="{ props: tip }">
                  <VBtn v-if="showBackButton" icon variant="text" color="default" size="small"
                    class="text-lightText ml-2" @click="goBack" title="Go back">
                    <VIcon size="20">$arrowLeft</VIcon>
                  </VBtn>
                </template>
                Go Back
              </v-tooltip>
              <!-- Back Button -->

            </div>

            <!-- Title and Description -->
            <div class="mt-2">
              <h2 class="text-h2 font-weight-bold mb-1">
                {{ props.title }}
              </h2>
              <p v-if="props.description" class="text-body-1 text-medium-emphasis mb-0">
                {{ props.description }}
              </p>
            </div>
          </VCol>
        </VRow>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss" scoped>
.page-breadcrumb {
  .v-toolbar {
    background: transparent;
  }
}
</style>