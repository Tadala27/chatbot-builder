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
  description: "",
  welcome_message: "",
  fallback_message: "",
  default_language: "en",
  is_active: true,
});

const errors = ref<Record<string, string>>({});

const languageOptions = [
  { title: "English", value: "en" },
  { title: "Chichewa", value: "ny" },
  { title: "French", value: "fr" },
  { title: "Portuguese", value: "pt" },
];

const fetchBot = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/tenant/bots/${route.params.id}`);
    const b = data.data ?? data;
    form.value = {
      name: b.name,
      description: b.description ?? "",
      welcome_message: b.welcome_message ?? "",
      fallback_message: b.fallback_message ?? "",
      default_language: b.default_language,
      is_active: b.is_active,
    };
  } finally {
    isLoading.value = false;
  }
};

const submit = async () => {
  errors.value = {};
  isSaving.value = true;
  try {
    await axios.put(`/tenant/bots/${route.params.id}`, form.value);
    await Swal.fire({ title: "Saved", text: "Bot updated.", icon: "success", timer: 1500, showConfirmButton: false });
    router.push(`/chatbots/${route.params.id}`);
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) errors.value = Object.fromEntries(Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]));
    Swal.fire({ title: "Error", text: err.response?.data?.message ?? "Failed to update bot.", icon: "error" });
  } finally {
    isSaving.value = false;
  }
};

onMounted(fetchBot);
</script>

<template>
  <div>
    <VBtn variant="text" prepend-icon="mdi-arrow-left" class="mb-4" @click="router.push(`/chatbots/${route.params.id}`)">Back</VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else variant="outlined" elevation="0">
      <VCardTitle>Edit Bot</VCardTitle>
      <VDivider />
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12" md="6">
            <VTextField v-model="form.name" label="Bot Name" variant="outlined" density="comfortable" :error-messages="errors.name" />
          </VCol>
          <VCol cols="12" md="6">
            <VSelect v-model="form.default_language" :items="languageOptions" label="Default Language" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12">
            <VTextarea v-model="form.description" label="Description" rows="2" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="form.welcome_message" label="Welcome Message" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12" md="6">
            <VTextField v-model="form.fallback_message" label="Fallback Message" variant="outlined" density="comfortable" />
          </VCol>
          <VCol cols="12">
            <VSwitch v-model="form.is_active" label="Active" color="success" />
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
