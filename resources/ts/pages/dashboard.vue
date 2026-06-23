<script setup lang="ts">
// resources/ts/pages/dashboard.vue

import { useUserStore } from "@/stores/user";
import TenantDashboardContent from "@/components/dashboards/TenantDashboardContent.vue";
import AdminDashboardContent from "@/components/dashboards/AdminDashboardContent.vue";

const userStore = useUserStore();
</script>

<template>
  <VContainer fluid class="pa-6">
    <AdminDashboardContent v-if="userStore.isSystemUser" />
    <TenantDashboardContent v-else />
  </VContainer>
</template>
