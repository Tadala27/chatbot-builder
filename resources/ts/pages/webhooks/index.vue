<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

// NOTE: No /tenant/webhooks routes were present in the routes file shared so
// far, even though an `outgoing_webhooks` table exists in your migrations.
// This page is built against the conventional REST shape matching every
// other bot sub-resource (apis, variables, functions) — add the matching
// WebhookController + routes when ready:
//
//   Route::prefix('webhooks')->group(function () {
//       Route::get('/', [WebhookController::class, 'index'])->middleware('permission:view webhooks');
//       Route::post('/', [WebhookController::class, 'store'])->middleware('permission:create webhooks');
//       Route::put('{webhook}', [WebhookController::class, 'update'])->middleware('permission:edit webhooks');
//       Route::delete('{webhook}', [WebhookController::class, 'destroy'])->middleware('permission:delete webhooks');
//   });

interface OutgoingWebhook {
  id: number; flow_id: number | null; name: string; url: string; method: string; is_active: boolean;
}

const webhooks = ref<OutgoingWebhook[]>([]);
const isLoading = ref(true);
const dialog = ref(false);
const submitting = ref(false);
const errorBanner = ref<string | null>(null);

const form = ref({ name: "", url: "", method: "POST", is_active: true });

const fetchWebhooks = async () => {
  isLoading.value = true;
  errorBanner.value = null;
  try {
    const { data } = await axios.get("/tenant/webhooks");
    webhooks.value = data.data ?? data;
  } catch (e: any) {
    if (e.response?.status === 404) {
      errorBanner.value = "Webhook routes are not yet registered on the backend.";
    } else {
      errorBanner.value = e.response?.data?.message ?? "Failed to load webhooks.";
    }
  } finally {
    isLoading.value = false;
  }
};

const submit = async () => {
  submitting.value = true;
  try {
    await axios.post("/tenant/webhooks", form.value);
    dialog.value = false;
    form.value = { name: "", url: "", method: "POST", is_active: true };
    fetchWebhooks();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to create webhook.", icon: "error" });
  } finally {
    submitting.value = false;
  }
};

const deleteWebhook = async (w: OutgoingWebhook) => {
  const { isConfirmed } = await Swal.fire({ title: "Delete Webhook", text: `Delete "${w.name}"?`, icon: "warning", showCancelButton: true, confirmButtonColor: "#ef4444" });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/webhooks/${w.id}`);
    fetchWebhooks();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete.", icon: "error" });
  }
};

onMounted(fetchWebhooks);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Webhooks</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Outgoing webhook endpoints triggered by flow events</p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="dialog = true">New Webhook</VBtn>
    </div>

    <VAlert v-if="errorBanner" type="warning" variant="tonal" class="mb-4">{{ errorBanner }}</VAlert>

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
          <tr v-if="!webhooks.length"><td colspan="5" class="text-center py-12 text-grey">No webhooks configured.</td></tr>
          <tr v-for="w in webhooks" :key="w.id">
            <td class="pa-4">{{ w.name }}</td>
            <td class="pa-4"><VChip size="x-small" variant="tonal">{{ w.method }}</VChip></td>
            <td class="pa-4 text-caption text-truncate" style="max-width: 280px;">{{ w.url }}</td>
            <td class="pa-4"><VChip size="small" :color="w.is_active ? 'success' : 'error'" variant="tonal">{{ w.is_active ? "Active" : "Inactive" }}</VChip></td>
            <td class="pa-4 text-center">
              <VBtn icon size="small" variant="text" color="error" @click="deleteWebhook(w)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>

    <VDialog v-model="dialog" max-width="520">
      <VCard>
        <VCardTitle>New Webhook</VCardTitle>
        <VDivider />
        <VCardText>
          <VTextField v-model="form.name" label="Name" variant="outlined" density="comfortable" class="mb-3" />
          <VTextField v-model="form.url" label="Endpoint URL" variant="outlined" density="comfortable" class="mb-3" placeholder="https://example.com/hook" />
          <VSelect v-model="form.method" :items="['GET', 'POST', 'PUT', 'PATCH']" label="Method" variant="outlined" density="comfortable" class="mb-3" />
          <VSwitch v-model="form.is_active" label="Active" color="success" />
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
