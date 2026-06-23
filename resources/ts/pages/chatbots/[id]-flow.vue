<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const botId = route.params.id as string;

const isLoading = ref(true);
const bot = ref<any>(null);
const flows = ref<any[]>([]);

const createDialog = ref(false);
const newFlowName = ref("");
const creating = ref(false);

const statusColor = (status: string) => ({ draft: "default", published: "success", archived: "error" }[status] ?? "default");

const fetchData = async () => {
  isLoading.value = true;
  try {
    const [{ data: botData }, { data: flowsData }] = await Promise.all([
      axios.get(`/tenant/bots/${botId}`),
      axios.get(`/tenant/bots/${botId}/flows`),
    ]);
    bot.value = botData.data ?? botData;
    flows.value = flowsData.data ?? flowsData;
  } finally {
    isLoading.value = false;
  }
};

const createFlow = async () => {
  if (!newFlowName.value) return;
  creating.value = true;
  try {
    const { data } = await axios.post(`/tenant/bots/${botId}/flows`, { name: newFlowName.value });
    createDialog.value = false;
    newFlowName.value = "";
    const flow = data.data ?? data;
    // Jump straight into the builder for the new flow
    router.push(`/chatbots/${botId}/flow/${flow.id}/builder`);
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to create flow.", icon: "error" });
  } finally {
    creating.value = false;
  }
};

const duplicateFlow = async (flow: any) => {
  try {
    await axios.post(`/tenant/bots/${botId}/flows/${flow.id}/duplicate`);
    fetchData();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to duplicate flow.", icon: "error" });
  }
};

const unpublishFlow = async (flow: any) => {
  try {
    await axios.post(`/tenant/bots/${botId}/flows/${flow.id}/unpublish`);
    fetchData();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to unpublish flow.", icon: "error" });
  }
};

const deleteFlow = async (flow: any) => {
  const { isConfirmed } = await Swal.fire({
    title: "Delete Flow",
    text: `Delete "${flow.name}"? This cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
  });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${botId}/flows/${flow.id}`);
    fetchData();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete flow.", icon: "error" });
  }
};

onMounted(fetchData);
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-3">
      <div>
        <VBtn variant="text" prepend-icon="mdi-arrow-left" @click="router.push(`/chatbots/${botId}`)">Back</VBtn>
        <h1 class="text-h4 mt-2">Flows — {{ bot?.name }}</h1>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="createDialog = true">New Flow</VBtn>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <VCol v-if="!flows.length" cols="12">
        <VCard variant="outlined" class="text-center py-12">
          <VIcon size="64" color="grey-lighten-1">mdi-sitemap</VIcon>
          <h3 class="text-h6 text-grey mt-4">No flows yet</h3>
          <VBtn color="primary" variant="outlined" class="mt-4" @click="createDialog = true">Create your first flow</VBtn>
        </VCard>
      </VCol>

      <VCol v-for="flow in flows" :key="flow.id" cols="12" sm="6" md="4">
        <VCard variant="outlined" elevation="0">
          <VCardText>
            <div class="d-flex justify-space-between align-center mb-2">
              <VChip :color="statusColor(flow.status)" size="small" variant="tonal" class="text-capitalize">{{ flow.status }}</VChip>
              <VMenu>
                <template #activator="{ props }">
                  <VBtn v-bind="props" icon size="small" variant="text"><VIcon size="18">mdi-dots-vertical</VIcon></VBtn>
                </template>
                <VList density="compact">
                  <VListItem @click="duplicateFlow(flow)">
                    <template #prepend><VIcon size="small">mdi-content-copy</VIcon></template>
                    <VListItemTitle>Duplicate</VListItemTitle>
                  </VListItem>
                  <VListItem v-if="flow.status === 'published'" @click="unpublishFlow(flow)">
                    <template #prepend><VIcon size="small">mdi-eye-off</VIcon></template>
                    <VListItemTitle>Unpublish</VListItemTitle>
                  </VListItem>
                  <VDivider />
                  <VListItem class="text-error" @click="deleteFlow(flow)">
                    <template #prepend><VIcon size="small">mdi-trash-can</VIcon></template>
                    <VListItemTitle>Delete</VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </div>
            <p class="text-subtitle-1 font-weight-medium mb-1">{{ flow.name }}</p>
            <p class="text-caption text-medium-emphasis mb-0">{{ flow.description || "No description" }}</p>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4">
            <VBtn block color="primary" variant="tonal" @click="router.push(`/chatbots/${botId}/flow/${flow.id}/builder`)">
              Open in Builder
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VDialog v-model="createDialog" max-width="480">
      <VCard>
        <VCardTitle>New Flow</VCardTitle>
        <VCardText>
          <VTextField v-model="newFlowName" label="Flow Name" variant="outlined" autofocus @keyup.enter="createFlow" />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn variant="text" @click="createDialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="creating" :disabled="!newFlowName" @click="createFlow">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
