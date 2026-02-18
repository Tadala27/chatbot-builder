<script setup lang="ts">
interface Breadcrumb {
  title: string
  disabled: boolean
  href: string
}

const props = defineProps({
  title: String,
  breadcrumbs: Array as () => Breadcrumb[],
  icon: String,
})
</script>

// ===============================|| Theme Breadcrumb ||=============================== //
<template>
  <VRow class="page-breadcrumb mb-0 mt-n2">
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
            <VBreadcrumbs
              :items="props.breadcrumbs"
              class="text-h6 pa-1 mb-0"
            >
              <template #divider>
                <div class="d-flex align-center">
                  <SvgSprite
                    name="custom-chevron-outline"
                    style="width: 12px; height: 12px"
                  />
                </div>
              </template>
              <template #prepend>
                <RouterLink
                  to="/dashboard"
                  class="text-darkText text-h6 text-decoration-none"
                >
                  Home
                </RouterLink>
                <div class="d-flex align-center px-2">
                  <SvgSprite
                    name="custom-chevron-outline"
                    style="width: 12px; height: 12px"
                  />
                </div>
              </template>
            </VBreadcrumbs>
            <h2 class="text-h2 font-weight-bold mb-0">
              {{ props.title }}
            </h2>
          </VCol>
        </VRow>
      </VCard>
    </VCol>
  </VRow>
</template>

<style lang="scss">
.page-breadcrumb {
  .v-toolbar {
    background: transparent;
  }
}
</style>
