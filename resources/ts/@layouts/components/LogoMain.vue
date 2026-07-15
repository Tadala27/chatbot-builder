<script setup lang="ts">
import { computed } from 'vue'
import { useCustomizerStore } from '@layouts/stores/customizer'

// Import both logo variants
import LogoFull from '@images/logos/NICO-Tech.png'
import LogoCollapsed from '@images/logos/logo.png'

const customizer = useCustomizerStore()

// Dynamically select which logo to show
const currentLogo = computed(() =>
  customizer.miniSidebar ? LogoCollapsed : LogoFull
)
</script>

<template>
  <div class="logo">
    <RouterLink :to="{ name: 'dashboard' }" aria-label="logo">
      <img :src="LogoCollapsed" alt="Logo" width="70" height="70" class="rounded-md" />
    </RouterLink>
  </div>
</template>
