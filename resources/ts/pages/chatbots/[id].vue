<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const bot = ref<any>(null);
const flows = ref<any[]>([]);

const fetchBot = async () => {
  isLoading.value = true;
  try {
    const [{ data: botData }, { data: flowsData }] = await Promise.all([
      axios.get(`/tenant/bots/${route.params.id}`),
      axios.get(`/tenant/bots/${route.params.id}/flows`),
    ]);
    bot.value = botData.data ?? botData;
    flows.value = flowsData.data ?? flowsData;
  } finally {
    isLoading.value = false;
  }
};

const statusColor = (status: string) => ({ draft: "default", published: "success", archived: "error" }[status] ?? "default");

onMounted(fetchBot);
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-5">
      <VBtn variant="text" prepend-icon="mdi-arrow-left" @click="router.push('/chatbots')">Back</VBtn>
      <div v-if="bot" class="d-flex gap-2">
        <VBtn variant="outlined" prepend-icon="mdi-sitemap" @click="router.push(`/chatbots/${bot.id}/flow`)">Flow Builder</VBtn>
        <VBtn color="primary" prepend-icon="mdi-pencil" @click="router.push(`/chatbots/${bot.id}/edit`)">Edit</VBtn>
      </div>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else-if="bot">
      <VCard variant="outlined" elevation="0" class="mb-4">
        <VCardText class="pa-6">
          <div class="d-flex align-center gap-4">
            <VAvatar size="56" color="primary" variant="tonal"><VIcon size="28">mdi-robot-outline</VIcon></VAvatar>
            <div>
              <p class="text-h6 mb-0">{{ bot.name }}</p>
              <p class="text-body-2 text-medium-emphasis mb-0">{{ bot.description || "No description" }}</p>
            </div>
            <VSpacer />
            <VChip :color="bot.is_active ? 'success' : 'error'" variant="tonal">{{ bot.is_active ? "Active" : "Inactive" }}</VChip>
          </div>
        </VCardText>
      </VCard>

      <VCard variant="outlined" elevation="0">
        <VCardTitle class="d-flex justify-space-between align-center">
          Flows
          <VBtn size="small" color="primary" variant="tonal" @click="router.push(`/chatbots/${bot.id}/flow`)">Manage Flows</VBtn>
        </VCardTitle>
        <VDivider />
        <VTable density="comfortable">
          <thead><tr><th class="text-left pa-4">Name</th><th class="text-left pa-4">Status</th><th class="text-left pa-4">Updated</th></tr></thead>
          <tbody>
            <tr v-if="!flows.length"><td colspan="3" class="text-center py-8 text-grey">No flows yet.</td></tr>
            <tr v-for="flow in flows" :key="flow.id">
              <td class="pa-4">{{ flow.name }}</td>
              <td class="pa-4"><VChip :color="statusColor(flow.status)" size="small" variant="tonal" class="text-capitalize">{{ flow.status }}</VChip></td>
              <td class="pa-4 text-caption text-medium-emphasis">{{ new Date(flow.updated_at).toLocaleDateString() }}</td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
