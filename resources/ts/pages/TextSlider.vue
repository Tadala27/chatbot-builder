<script setup lang="ts">
import { ref } from 'vue'

import Avatar1 from '@images/users/avatar-1.png'
import Avatar2 from '@images/users/avatar-2.png'
import Avatar3 from '@images/users/avatar-3.png'

interface slideType {
  image: string
  title: string
  description: string
}

const slides = ref<slideType[]>([
  {
    image: Avatar1,
    title: 'Allie Grater',
    description:
      'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
  },
  {
    image: Avatar2,
    title: 'Allie Grater',
    description:
      'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
  },
  {
    image: Avatar3,
    title: 'Allie Grater',
    description:
      'Very good customer service!👌 I liked the design and there was nothing wrong, but found out after testing that it did not quite match the functionality and overall design that I needed for my type of software. I therefore contacted customer service and it was no problem even though the deadline for refund had actually expired.😍',
  },
])

const rating = ref(3)
</script>

<template>
  <VCarousel cycle hide-delimiter-background class="custom-delimiter" :show-arrows="false">
    <VCarouselItem v-for="(slide, i) in slides" :key="i">
      <div class="text-center">
        <VAvatar size="52" variant="flat" class="mb-8">
          <img :src="slide.image" width="52" alt="vector">
        </VAvatar>
        <h5 class="text-h5 mb-0">
          {{ slide.title }}
        </h5>
        <p class="text-caption mb-5">
          @alliegrater
        </p>
        <VRating v-model="rating" color="inputBorder" active-color="warning200" density="compact" />
        <p class="text-h6 mt-5">
          {{ slide.description }}
        </p>
      </div>
    </VCarouselItem>
  </VCarousel>
</template>
