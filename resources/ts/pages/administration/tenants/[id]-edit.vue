<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const isSaving = ref(false);

const form = ref({
  name: "",
  subscription_tier: "free",
  subscription_expires_at: null as string | null,
  max_flows: 3,
  max_conversations_per_month: 1000,
  is_active: true,
});

const errors = ref<Record<string, string>>({});

const tierOptions = [
  { title: "Free", value: "free" },
  { title: "Starter", value: "starter" },
  { title: "Professional", value: "professional" },
  { title: "Enterprise", value: "enterprise" },
];

const fetchTenant = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/tenants/${route.params.id}`);
    form.value = {
      name: data.name,
      subscription_tier: data.subscription_tier,
      subscription_expires_at: data.subscription_expires_at?.slice(0, 10) ?? null,
      max_flows: data.max_flows,
      max_conversations_per_month: data.max_conversations_per_month,
      is_active: data.is_active,
    };
  } finally {
    isLoading.value = false;
  }
};

const submit = async () => {
  errors.value = {};
  isSaving.value = true;
  try {
    await axios.put(`/api/admin/tenants/${route.params.id}`, form.value);
    await Swal.fire({ title: "Saved", text: "Tenant updated successfully.", icon: "success" });
    router.push(`/administration/tenants/${route.params.id}`);
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) errors.value = Object.fromEntries(Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]));
    Swal.fire({ title: "Error", text: err.response?.data?.message ?? "Failed to update tenant.", icon: "error" });
  } finally {
    isSaving.value = false;
  }
};

onMounted(fetchTenant);
</script>

<template>
  <div>
    <VBtn variant="text" prepend-icon="mdi-arrow-left" class="mb-4" @click="router.push(`/administration/tenants/${route.params.id}`)">
      Back
    </VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else variant="outlined" elevation="0">
      <VCardTitle>Edit Tenant</VCardTitle>
      <VDivider />
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12" md="6">
            <VTextField v-model="form.name" label="Company Name" variant="outlined" density="comfortable" :error-messages="errors.name" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="form.subscription_tier" :items="tierOptions" label="Subscription Tier" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="form.subscription_expires_at" type="date" label="Subscription Expires" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VSwitch v-model="form.is_active" label="Active" color="success" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model.number="form.max_flows" type="number" label="Max Flows" variant="outlined" density="comfortable" min="1" :error-messages="errors.max_flows" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model.number="form.max_conversations_per_month" type="number" label="Max Conversations / Month" variant="outlined" density="comfortable" min="0" :error-messages="errors.max_conversations_per_month" />
          </VCol>
        </VRow>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-6">
        <VSpacer />
        <VBtn color="primary" :loading="isSaving" prepend-icon="mdi-check" @click="submit">Save Changes</VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>
