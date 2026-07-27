<script setup lang="ts">
import { shallowRef } from 'vue'
import Logo from '@images/logos/NICO-Tech.png'


</script>

<template>
  <VFooter class="px-0 footer">
    <VRow class="h-100" justify="center" align="end" no-gutters>
      <VCol cols="4" class="d-flex align-end">
        <img :src="Logo" alt="Logo" width="120" class="rounded-md" />
      </VCol>
      <VCol cols="8" class="d-flex justify-end align-end">
        <p class="text-secondary text-h6 mb-0">
          Copyright © {{ new Date().getFullYear() }}. <a href="https://nicotechnologies.com"
            class="text-secondary text-decoration-underline ms-1" target="_blank" rel="noopener noreferrer">NICO Technologies Ltd</a>. All Rights Reserved.
        </p>
      </VCol>
    </VRow>
  </VFooter>
</template>
