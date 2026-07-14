<script setup lang="ts">
interface Props {
  greeting: string;
  subtitle: string;
  ctaLabel?: string;
  ctaIcon?: string;
}

defineProps<Props>();
defineEmits<{ cta: [] }>();
</script>

<template>
  <div class="hero-banner mb-6">
    <div class="hero-sweep" />
    <div class="hero-deco hero-deco--1" />
    <div class="hero-deco hero-deco--2" />
    <div
      class="d-flex align-center justify-space-between flex-wrap gap-4 hero-content"
    >
      <div>
        <h1 class="text-h4 font-weight-bold text-white mb-1">{{ greeting }}</h1>
        <p class="text-body-2 hero-subtitle mb-0">{{ subtitle }}</p>
      </div>
      <VBtn
        v-if="ctaLabel"
        variant="flat"
        size="small"
        class="hero-cta"
        :prepend-icon="ctaIcon"
        @click="$emit('cta')"
      >
        {{ ctaLabel }}
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
.hero-banner {
  position: relative;
  border-radius: 20px;
  padding: 36px 40px;
  background: linear-gradient(
    120deg,
    #1c2a5c 0%,
    #273775 35%,
    #2d6fa8 70%,
    #27a7df 100%
  );
  overflow: hidden;
  min-height: 150px;
  display: flex;
  align-items: center;
}
.hero-sweep {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    115deg,
    transparent 30%,
    rgba(255, 255, 255, 0.08) 45%,
    transparent 60%
  );
}
.hero-content {
  position: relative;
  z-index: 1;
  width: 100%;
}
.hero-subtitle {
  color: rgba(255, 255, 255, 0.8);
}
.hero-cta {
  background: rgba(255, 255, 255, 0.16) !important;
  color: #fff !important;
  font-weight: 500;
  backdrop-filter: blur(4px);
}
.hero-deco {
  position: absolute;
  border-radius: 50%;
  pointer-events: none;
}
.hero-deco--1 {
  width: 320px;
  height: 320px;
  background: rgba(255, 255, 255, 0.05);
  top: -180px;
  right: -40px;
}
.hero-deco--2 {
  width: 200px;
  height: 200px;
  background: rgba(39, 167, 223, 0.3);
  bottom: -130px;
  right: 160px;
  filter: blur(24px);
}
@media (max-width: 600px) {
  .hero-banner {
    padding: 28px 22px;
  }
}
</style>
