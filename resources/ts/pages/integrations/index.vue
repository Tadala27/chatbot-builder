<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface Bot { id: number; name: string; }
interface ApiIntegration {
  id: number; bot_id: number; name: string; method: string; url: string; is_active: boolean;
}

const bots = ref<Bot[]>([]);
const selectedBotId = ref<number | null>(null);
const apis = ref<ApiIntegration[]>([]);
const isLoading = ref(true);
const testing = ref<number | null>(null);

const methodColor: Record<string, string> = { GET: "info", POST: "success", PUT: "warning", PATCH: "warning", DELETE: "error" };

const fetchBots = async () => {
  const { data } = await axios.get("/tenant/bots");
  bots.value = data.data ?? data;
  if (bots.value.length) selectedBotId.value = bots.value[0].id;
};

const fetchApis = async () => {
  if (!selectedBotId.value) return;
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/tenant/bots/${selectedBotId.value}/apis`);
    apis.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
};

const testApi = async (api: ApiIntegration) => {
  testing.value = api.id;
  try {
    const { data } = await axios.post(`/tenant/bots/${selectedBotId.value}/apis/${api.id}/test`);
    Swal.fire({ title: "Test Result", html: `<pre style="text-align:left;white-space:pre-wrap;">${JSON.stringify(data, null, 2)}</pre>`, icon: "success" });
  } catch (e: any) {
    Swal.fire({ title: "Test Failed", text: e.response?.data?.message ?? "Request failed.", icon: "error" });
  } finally {
    testing.value = null;
  }
};

const deleteApi = async (api: ApiIntegration) => {
  const { isConfirmed } = await Swal.fire({ title: "Delete Integration", text: `Delete "${api.name}"?`, icon: "warning", showCancelButton: true, confirmButtonColor: "#ef4444" });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${selectedBotId.value}/apis/${api.id}`);
    fetchApis();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete.", icon: "error" });
  }
};

onMounted(async () => { await fetchBots(); fetchApis(); });
</script>

<template>
  <div>
    <div class="mb-5">
      <h1 class="text-h4">Integrations</h1>
      <p class="text-subtitle-1 text-medium-emphasis">Reusable API call definitions your flows can invoke</p>
    </div>

    <VSelect
      v-model="selectedBotId"
      :items="bots"
      item-title="name"
      item-value="id"
      label="Bot"
      variant="outlined"
      density="comfortable"
      class="mb-4"
      style="max-width: 320px;"
      @update:model-value="fetchApis"
    />

    <VCard variant="outlined" elevation="0">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th class="text-left pa-4">Name</th>
            <th class="text-left pa-4">Method</th>
            <th class="text-left pa-4">URL</th>
            <th class="text-left pa-4">Status</th>
            <th class="text-center pa-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!apis.length"><td colspan="5" class="text-center py-12 text-grey">No integrations defined.</td></tr>
          <tr v-for="api in apis" :key="api.id">
            <td class="pa-4">{{ api.name }}</td>
            <td class="pa-4"><VChip size="x-small" :color="methodColor[api.method]" variant="tonal">{{ api.method }}</VChip></td>
            <td class="pa-4 text-caption text-truncate" style="max-width: 280px;">{{ api.url }}</td>
            <td class="pa-4"><VChip size="small" :color="api.is_active ? 'success' : 'error'" variant="tonal">{{ api.is_active ? "Active" : "Inactive" }}</VChip></td>
            <td class="pa-4 text-center">
              <VBtn icon size="small" variant="text" :loading="testing === api.id" @click="testApi(api)"><VIcon size="18">mdi-play</VIcon></VBtn>
              <VBtn icon size="small" variant="text" color="error" @click="deleteApi(api)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
