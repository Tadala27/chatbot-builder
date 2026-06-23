<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface Bot { id: number; name: string; }
interface CustomFunction {
  id: number; bot_id: number; name: string; slug: string; description: string | null;
  function_type: "javascript" | "webhook" | "built_in"; code: string | null;
  return_type: string | null; is_active: boolean;
}
interface BuiltInFunction {
  id: number; name: string; category: string; description: string | null; syntax: string | null;
}

const bots = ref<Bot[]>([]);
const selectedBotId = ref<number | null>(null);
const functions = ref<CustomFunction[]>([]);
const builtIns = ref<BuiltInFunction[]>([]);
const tab = ref("custom");
const isLoading = ref(true);
const isLoadingBuiltIns = ref(true);

const testDialog = ref(false);
const testTarget = ref<CustomFunction | null>(null);
const testArgs = ref("{}");
const testResult = ref<any>(null);
const testing = ref(false);

const typeColor: Record<string, string> = { javascript: "warning", webhook: "info", built_in: "secondary" };

const fetchBots = async () => {
  const { data } = await axios.get("/tenant/bots");
  bots.value = data.data ?? data;
  if (bots.value.length) selectedBotId.value = bots.value[0].id;
};

const fetchFunctions = async () => {
  if (!selectedBotId.value) return;
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/tenant/bots/${selectedBotId.value}/functions`);
    functions.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
};

const fetchBuiltIns = async () => {
  isLoadingBuiltIns.value = true;
  try {
    const { data } = await axios.get("/tenant/built-in-functions");
    builtIns.value = data.data ?? data;
  } finally {
    isLoadingBuiltIns.value = false;
  }
};

const openTest = (fn: CustomFunction) => {
  testTarget.value = fn;
  testArgs.value = "{}";
  testResult.value = null;
  testDialog.value = true;
};

const runTest = async () => {
  if (!testTarget.value) return;
  testing.value = true;
  try {
    const args = JSON.parse(testArgs.value);
    const { data } = await axios.post(`/tenant/bots/${selectedBotId.value}/functions/${testTarget.value.id}/test`, { arguments: args });
    testResult.value = data;
  } catch (e: any) {
    testResult.value = { error: e.response?.data?.message ?? e.message };
  } finally {
    testing.value = false;
  }
};

const deleteFunction = async (fn: CustomFunction) => {
  const { isConfirmed } = await Swal.fire({ title: "Delete Function", text: `Delete "${fn.name}"?`, icon: "warning", showCancelButton: true, confirmButtonColor: "#ef4444" });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${selectedBotId.value}/functions/${fn.id}`);
    fetchFunctions();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete function.", icon: "error" });
  }
};

onMounted(async () => { await fetchBots(); fetchFunctions(); fetchBuiltIns(); });
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Functions</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Custom logic and platform built-ins for your flows</p>
      </div>
    </div>

    <VTabs v-model="tab" class="mb-4">
      <VTab value="custom">Custom Functions</VTab>
      <VTab value="built-in">Built-in Functions</VTab>
    </VTabs>

    <VWindow v-model="tab">
      <!-- Custom -->
      <VWindowItem value="custom">
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
          @update:model-value="fetchFunctions"
        />

        <VCard variant="outlined" elevation="0">
          <div v-if="isLoading" class="d-flex justify-center py-12">
            <VProgressCircular indeterminate color="primary" size="48" />
          </div>
          <VTable v-else density="comfortable">
            <thead>
              <tr>
                <th class="text-left pa-4">Name</th>
                <th class="text-left pa-4">Type</th>
                <th class="text-left pa-4">Status</th>
                <th class="text-center pa-4">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!functions.length"><td colspan="4" class="text-center py-12 text-grey">No custom functions yet.</td></tr>
              <tr v-for="fn in functions" :key="fn.id">
                <td class="pa-4">
                  <p class="text-body-2 font-weight-medium mb-0">{{ fn.name }}</p>
                  <p v-if="fn.description" class="text-caption text-medium-emphasis mb-0">{{ fn.description }}</p>
                </td>
                <td class="pa-4"><VChip size="x-small" :color="typeColor[fn.function_type]" variant="tonal" class="text-capitalize">{{ fn.function_type.replace("_", " ") }}</VChip></td>
                <td class="pa-4"><VChip size="small" :color="fn.is_active ? 'success' : 'error'" variant="tonal">{{ fn.is_active ? "Active" : "Inactive" }}</VChip></td>
                <td class="pa-4 text-center">
                  <VBtn icon size="small" variant="text" @click="openTest(fn)"><VIcon size="18">mdi-play</VIcon></VBtn>
                  <VBtn icon size="small" variant="text" color="error" @click="deleteFunction(fn)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VWindowItem>

      <!-- Built-in -->
      <VWindowItem value="built-in">
        <div v-if="isLoadingBuiltIns" class="d-flex justify-center py-12">
          <VProgressCircular indeterminate color="primary" size="48" />
        </div>
        <VRow v-else>
          <VCol v-for="fn in builtIns" :key="fn.id" cols="12" sm="6" md="4">
            <VCard variant="outlined" elevation="0">
              <VCardText>
                <VChip size="x-small" variant="tonal" class="text-capitalize mb-2">{{ fn.category.replace("_", " ") }}</VChip>
                <p class="text-subtitle-2 font-weight-medium mb-1">{{ fn.name }}</p>
                <p class="text-caption text-medium-emphasis mb-2">{{ fn.description }}</p>
                <code v-if="fn.syntax" class="text-caption">{{ fn.syntax }}</code>
              </VCardText>
            </VCard>
          </VCol>
        </VRow>
      </VWindowItem>
    </VWindow>

    <VDialog v-model="testDialog" max-width="560">
      <VCard>
        <VCardTitle>Test "{{ testTarget?.name }}"</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextarea v-model="testArgs" label="Arguments (JSON)" rows="4" variant="outlined" class="mb-3" />
          <VAlert v-if="testResult" :type="testResult.error ? 'error' : 'success'" variant="tonal" density="compact">
            <pre class="text-caption" style="white-space: pre-wrap;">{{ JSON.stringify(testResult, null, 2) }}</pre>
          </VAlert>
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="testDialog = false">Close</VBtn>
          <VBtn color="primary" :loading="testing" @click="runTest">Run Test</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
