<script setup lang="ts">
interface Props {
  displayPhoneNumber: string | null;
  verifiedName: string | null;
  qualityRating: string;
  isActive: boolean;
}

defineProps<Props>();

const qualityLabel: Record<string, string> = {
  GREEN: "Good",
  YELLOW: "Fair",
  RED: "At risk",
  UNKNOWN: "Unrated",
};
</script>

<template>
  <div class="wa-card">
    <div class="wa-card-deco" />
    <div class="d-flex align-center justify-space-between mb-6">
      <div class="d-flex align-center gap-2">
        <VIcon icon="$whatsapp" size="20" color="#27a7df" />
        <span class="text-caption font-weight-medium wa-label"
          >WhatsApp Account</span
        >
      </div>
      <VChip
        :color="isActive ? 'success' : 'error'"
        size="x-small"
        variant="flat"
      >
        {{ isActive ? "Active" : "Inactive" }}
      </VChip>
    </div>

    <p class="text-h5 font-weight-bold text-white mb-1 wa-number">
      {{ displayPhoneNumber ?? "Not connected" }}
    </p>
    <p class="text-caption wa-name mb-6">{{ verifiedName ?? "Unverified" }}</p>

    <div class="d-flex align-center justify-space-between wa-footer">
      <div>
        <p class="text-caption wa-footer-label mb-0">Quality rating</p>
        <p class="text-body-2 font-weight-medium text-white mb-0">
          {{ qualityLabel[qualityRating] ?? qualityRating }}
        </p>
      </div>
      <div class="text-right">
        <p class="text-caption wa-footer-label mb-0">Platform</p>
        <p class="text-body-2 font-weight-medium text-white mb-0">Cloud API</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wa-card {
  position: relative;
  border-radius: 16px;
  padding: 24px 26px;
  height: 100%;
  background: linear-gradient(145deg, #131c33 0%, #1c2a5c 55%, #273775 100%);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.wa-card-deco {
  position: absolute;
  width: 220px;
  height: 220px;
  border-radius: 50%;
  background: rgba(39, 167, 223, 0.18);
  top: -120px;
  right: -60px;
  filter: blur(8px);
}
.wa-label {
  color: rgba(255, 255, 255, 0.7);
}
.wa-number {
  letter-spacing: 0.5px;
}
.wa-name {
  color: rgba(255, 255, 255, 0.65);
}
.wa-footer {
  margin-top: auto;
}
.wa-footer-label {
  color: rgba(255, 255, 255, 0.55);
}
</style>
