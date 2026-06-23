<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface Bot { id: number; name: string; }
interface Variable {
  id: number;
  bot_id: number;
  name: string;
  key: string;
  data_type: "string" | "number" | "boolean" | "json" | "date";
  default_value: string | null;
  save_in: "conversation" | "user_property" | "global";
  is_sensitive: boolean;
  description: string | null;
}

const bots = ref<Bot[]>([]);
const selectedBotId = ref<number | null>(null);
const variables = ref<Variable[]>([]);
const isLoading = ref(true);
const dialog = ref(false);
const saving = ref(false);
const editing = ref<Variable | null>(null);

const form = ref({
  name: "", key: "", data_type: "string", default_value: "", save_in: "conversation", is_sensitive: false, description: "",
});

const dataTypeOptions = ["string", "number", "boolean", "json", "date"];
const saveInOptions = [
  { title: "Conversation (per session)", value: "conversation" },
  { title: "User Property (per user, persists)", value: "user_property" },
  { title: "Global (shared across all users)", value: "global" },
];

const fetchBots = async () => {
  const { data } = await axios.get("/tenant/bots");
  bots.value = data.data ?? data;
  if (bots.value.length) selectedBotId.value = bots.value[0].id;
};

const fetchVariables = async () => {
  if (!selectedBotId.value) return;
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/tenant/bots/${selectedBotId.value}/variables`);
    variables.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
};

const resetForm = () => {
  form.value = { name: "", key: "", data_type: "string", default_value: "", save_in: "conversation", is_sensitive: false, description: "" };
  editing.value = null;
};

const openCreate = () => { resetForm(); dialog.value = true; };
const openEdit = (v: Variable) => {
  editing.value = v;
  form.value = { name: v.name, key: v.key, data_type: v.data_type, default_value: v.default_value ?? "", save_in: v.save_in, is_sensitive: v.is_sensitive, description: v.description ?? "" };
  dialog.value = true;
};

const submit = async () => {
  saving.value = true;
  try {
    if (editing.value) {
      await axios.put(`/tenant/bots/${selectedBotId.value}/variables/${editing.value.id}`, form.value);
    } else {
      await axios.post(`/tenant/bots/${selectedBotId.value}/variables`, form.value);
    }
    dialog.value = false;
    fetchVariables();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to save variable.", icon: "error" });
  } finally {
    saving.value = false;
  }
};

const deleteVariable = async (v: Variable) => {
  const { isConfirmed } = await Swal.fire({ title: "Delete Variable", text: `Delete "${v.name}"?`, icon: "warning", showCancelButton: true, confirmButtonColor: "#ef4444" });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${selectedBotId.value}/variables/${v.id}`);
    fetchVariables();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete variable.", icon: "error" });
  }
};

const saveInLabel = (val: string) => saveInOptions.find((o) => o.value === val)?.title ?? val;

onMounted(async () => { await fetchBots(); fetchVariables(); });
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Variables</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Define custom data fields your bots collect and use</p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" :disabled="!selectedBotId" @click="openCreate">New Variable</VBtn>
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
      @update:model-value="fetchVariables"
    />

    <VCard variant="outlined" elevation="0">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th class="text-left pa-4">Name</th>
            <th class="text-left pa-4">Key</th>
            <th class="text-left pa-4">Type</th>
            <th class="text-left pa-4">Saved In</th>
            <th class="text-center pa-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!variables.length"><td colspan="5" class="text-center py-12 text-grey">No variables defined.</td></tr>
          <tr v-for="v in variables" :key="v.id">
            <td class="pa-4">
              <p class="text-body-2 font-weight-medium mb-0">
                {{ v.name }}
                <VIcon v-if="v.is_sensitive" size="14" color="warning" class="ml-1">mdi-lock</VIcon>
              </p>
              <p v-if="v.description" class="text-caption text-medium-emphasis mb-0">{{ v.description }}</p>
            </td>
            <td class="pa-4"><code>{{ v.key }}</code></td>
            <td class="pa-4"><VChip size="x-small" variant="tonal" class="text-capitalize">{{ v.data_type }}</VChip></td>
            <td class="pa-4 text-caption">{{ saveInLabel(v.save_in) }}</td>
            <td class="pa-4 text-center">
              <VBtn icon size="small" variant="text" @click="openEdit(v)"><VIcon size="18">mdi-pencil</VIcon></VBtn>
              <VBtn icon size="small" variant="text" color="error" @click="deleteVariable(v)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>{{ editing ? "Edit" : "New" }} Variable</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="form.name" label="Name" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="form.key" label="Key" variant="outlined" density="comfortable" class="mb-3" hint="Used in flow logic, e.g. {{memberId}}" persistent-hint />
          <VSelect v-model="form.data_type" :items="dataTypeOptions" label="Data Type" variant="outlined" density="comfortable" class="mb-3 text-capitalize" />
          <VSelect v-model="form.save_in" :items="saveInOptions" label="Save In" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="form.default_value" label="Default Value (optional)" variant="outlined" density="comfortable" class="mb-3" />
          <VTextarea v-model="form.description" label="Description (optional)" rows="2" variant="outlined" density="comfortable" class="mb-3" />
          <VSwitch v-model="form.is_sensitive" label="Sensitive (mask in logs)" color="warning" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="saving" @click="submit">{{ editing ? "Save" : "Create" }}</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
