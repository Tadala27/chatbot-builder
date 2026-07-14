<script setup lang="ts">
interface Props {
  title: string;
  subtitle?: string;
  icon?: string;
  actionLabel?: string;
  actionIcon?: string;
  backTo?: string;
}

defineProps<Props>();
defineEmits<{ action: [] }>();
</script>

<template>
  <div class="page-header mb-6">
    <div class="page-header-deco" />
    <div
      class="d-flex align-center justify-space-between flex-wrap gap-4 page-header-content"
    >
      <div class="d-flex align-center gap-3">
        <RouterLink v-if="backTo" :to="backTo" class="back-btn me-2">
          <VIcon icon="$arrowLeft" size="18" />
        </RouterLink>
        <VAvatar v-if="icon" size="44" class="page-header-icon me-2">
          <VIcon :icon="icon" size="22" color="white" />
        </VAvatar>
        <div>
          <h1 class="text-h5 font-weight-bold text-white mb-0">{{ title }}</h1>
          <p v-if="subtitle" class="text-body-2 page-header-subtitle mb-0">
            {{ subtitle }}
          </p>
        </div>
      </div>
      <VBtn
        v-if="actionLabel"
        variant="flat"
        class="page-header-cta"
        :prepend-icon="actionIcon"
        @click="$emit('action')"
      >
        {{ actionLabel }}
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
.page-header {
  position: relative;
  border-radius: 18px;
  padding: 22px 28px;
  background: linear-gradient(
    120deg,
    #1c2a5c 0%,
    #273775 40%,
    #2d6fa8 75%,
    #27a7df 100%
  );
  overflow: hidden;
}
.page-header-content {
  position: relative;
  z-index: 1;
}
.page-header-subtitle {
  color: rgba(255, 255, 255, 0.78);
}
.page-header-icon {
  background: rgba(255, 255, 255, 0.16);
}
.page-header-cta {
  background: rgba(255, 255, 255, 0.95) !important;
  color: #273775 !important;
  font-weight: 600;
}
.back-btn {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.14);
  display: flex;
  align-items: center;
  justify-content: center;
  color: white;
  text-decoration: none;
  transition: background 0.15s ease;
}
.back-btn:hover {
  background: rgba(255, 255, 255, 0.24);
}
.page-header-deco {
  position: absolute;
  width: 200px;
  height: 200px;
  border-radius: 50%;
  background: rgba(39, 167, 223, 0.22);
  top: -120px;
  right: -20px;
  filter: blur(20px);
  pointer-events: none;
}
@media (max-width: 600px) {
  .page-header {
    padding: 18px 18px;
  }
}
</style>
