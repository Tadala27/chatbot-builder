<script setup lang="ts">
import { onMounted, ref } from 'vue'

const props = defineProps({
  name: String,
})

const spritePath = ref<string | null>(null)

onMounted(async () => {
  try {
  // Load the SVG sprite dynamically with an absolute path
    spritePath.value = '/assets/svg/sprite.svg'
  }
  catch (error) {
    console.error('Error loading SVG sprite:', error)
  }
})
</script>

<template>
  <svg class="pc-icon">
    <use :xlink:href="`${spritePath}#${props.name}`" />
  </svg>
</template>
