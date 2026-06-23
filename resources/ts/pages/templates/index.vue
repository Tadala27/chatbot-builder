<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface MessageTemplate {
  id: number; name: string; category: "utility" | "marketing" | "authentication";
  language: string; template_type: string; status: "draft" | "pending" | "approved" | "rejected";
}

const templates = ref<MessageTemplate[]>([]);
const isLoading = ref(true);
const dialog = ref(false);
const submitting = ref(false);

const categoryColor: Record<string, string> = { utility: "info", marketing: "warning", authentication: "secondary" };
const statusColor: Record<string, string> = { draft: "default", pending: "warning", approved: "success", rejected: "error" };

const form = ref({ name: "", category: "utility", language: "en", template_type: "text", content: { body: "" } });

const fetchTemplates = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/message-templates");
    templates.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
};

const submit = async () => {
  submitting.value = true;
  try {
    await axios.post("/tenant/message-templates", form.value);
    dialog.value = false;
    form.value = { name: "", category: "utility", language: "en", template_type: "text", content: { body: "" } };
    fetchTemplates();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to create template.", icon: "error" });
  } finally {
    submitting.value = false;
  }
};

const deleteTemplate = async (t: MessageTemplate) => {
  const { isConfirmed } = await Swal.fire({ title: "Delete Template", text: `Delete "${t.name}"?`, icon: "warning", showCancelButton: true, confirmButtonColor: "#ef4444" });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/message-templates/${t.id}`);
    fetchTemplates();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete.", icon: "error" });
  }
};

onMounted(fetchTemplates);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Message Templates</h1>
        <p class="text-subtitle-1 text-medium-emphasis">WhatsApp HSM templates pending or approved by Meta</p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="dialog = true">New Template</VBtn>
    </div>

    <VCard variant="outlined" elevation="0">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th class="text-left pa-4">Name</th>
            <th class="text-left pa-4">Category</th>
            <th class="text-left pa-4">Language</th>
            <th class="text-left pa-4">Status</th>
            <th class="text-center pa-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!templates.length"><td colspan="5" class="text-center py-12 text-grey">No templates yet.</td></tr>
          <tr v-for="t in templates" :key="t.id">
            <td class="pa-4">{{ t.name }}</td>
            <td class="pa-4"><VChip size="x-small" :color="categoryColor[t.category]" variant="tonal" class="text-capitalize">{{ t.category }}</VChip></td>
            <td class="pa-4 text-uppercase text-caption">{{ t.language }}</td>
            <td class="pa-4"><VChip size="small" :color="statusColor[t.status]" variant="tonal" class="text-capitalize">{{ t.status }}</VChip></td>
            <td class="pa-4 text-center">
              <VBtn icon size="small" variant="text" color="error" @click="deleteTemplate(t)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="560">
      <VCard>
        <VCardTitle>New Message Template</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="form.name" label="Name" variant="outlined" density="comfortable" class="mb-3" />
          <VSelect v-model="form.category" :items="['utility', 'marketing', 'authentication']" label="Category" variant="outlined" density="comfortable" class="mb-3 text-capitalize" />
          <VTextField v-model="form.language" label="Language Code" variant="outlined" density="comfortable" class="mb-3" placeholder="en" />
          <VTextarea v-model="form.content.body" label="Body Text" rows="3" variant="outlined" density="comfortable" />
        </VCardText>
        <VDivider />
        <VCardActions class="pa-4">
          <VSpacer />
          <VBtn variant="text" @click="dialog = false">Cancel</VBtn>
          <VBtn color="primary" :loading="submitting" @click="submit">Create</VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
