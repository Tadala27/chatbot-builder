<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);

const user = ref<any>(null);

const getInitials = (name: string) => {
  const parts = name.trim().split(" ");
  return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
};

const formatDate = (d: string | null) =>
  d ? new Date(d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "Never";

const fetchUser = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/users/${route.params.id}`);
    user.value = data.data ?? data;
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
};

onMounted(fetchUser);
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-5">
      <VBtn variant="text" prepend-icon="mdi-arrow-left" @click="router.push('/administration/users')">Back</VBtn>
      <VBtn v-if="user" color="primary" prepend-icon="mdi-pencil" @click="router.push(`/administration/users/${user.id}/edit`)">
        Edit
      </VBtn>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else-if="user" variant="outlined" elevation="0">
      <VCardText class="pa-6">
        <div class="d-flex align-center gap-4 mb-6">
          <VAvatar size="64" color="primary" variant="tonal">
            <span class="text-h6">{{ getInitials(user.name) }}</span>
          </VAvatar>
          <div>
            <p class="text-h6 mb-0">{{ user.name }}</p>
            <p class="text-body-2 text-medium-emphasis mb-0">{{ user.email }}</p>
          </div>
          <VSpacer />
          <VChip :color="user.is_active ? 'success' : 'error'" variant="tonal">
            {{ user.is_active ? "Active" : "Inactive" }}
          </VChip>
        </div>

        <VDivider class="mb-4" />

        <VRow>
          <VCol cols="12" md="6">
            <p class="text-caption text-medium-emphasis text-uppercase">Roles</p>
            <div>
              <VChip v-for="role in user.roles" :key="role" size="small" color="primary" variant="tonal" class="mr-1 mb-1 text-capitalize">
                {{ role }}
              </VChip>
              <span v-if="!user.roles?.length" class="text-medium-emphasis">No roles assigned</span>
            </div>
          </VCol>
          <VCol cols="12" md="6">
            <p class="text-caption text-medium-emphasis text-uppercase">Last Login</p>
            <p class="text-body-1">{{ formatDate(user.last_login) }}</p>
          </VCol>
          <VCol cols="12" md="6">
            <p class="text-caption text-medium-emphasis text-uppercase">Created</p>
            <p class="text-body-1">{{ formatDate(user.created_at) }}</p>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>
