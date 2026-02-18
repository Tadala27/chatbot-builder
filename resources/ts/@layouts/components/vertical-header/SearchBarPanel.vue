<template>
  <!-- ---------------------------------------------- -->
  <!-- searchbar -->
  <!-- ---------------------------------------------- -->
  <VTextField
    persistent-placeholder
    placeholder="Ctrl + k"
    color="primary"
    variant="outlined"
    hide-details
  >
    <template #prepend-inner>
      <div class="text-lightText d-flex align-center">
        <SvgSprite
          name="custom-search"
          style="width: 16px; height: 16px"
        />
      </div>
    </template>
  </VTextField>
</template>
