<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";

const userStore = useUserStore();
const isLoading = ref(true);
const saving = ref(false);

const snackbar = ref({ show: false, message: "", color: "success" });
const notify = (message: string, color = "success") => { snackbar.value = { show: true, message, color }; };

const form = ref<Record<string, any>>({
  timezone: "Africa/Blantyre",
  currency: "MWK",
  notification_email: "",
});

const fetchSettings = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/settings");
    form.value = { ...form.value, ...(data.data ?? data) };
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to load settings", "error");
  } finally {
    isLoading.value = false;
  }
};

const saveSettings = async () => {
  saving.value = true;
  try {
    await axios.put("/tenant/settings", form.value);
    notify("Settings saved successfully");
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to save settings", "error");
  } finally {
    saving.value = false;
  }
};

onMounted(fetchSettings);
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-h4">Settings</h1>
      <p class="text-subtitle-1 text-medium-emphasis">
        {{ userStore.currentTenant?.name ?? "Organisation" }} configuration
      </p>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else variant="outlined" elevation="0" max-width="640">
      <VCardTitle>General</VCardTitle>
      <VDivider />
      <VCardText class="pa-6">
        <VTextField v-model="form.timezone" label="Timezone" variant="outlined" density="comfortable" class="mb-4" placeholder="Africa/Blantyre" />
        <VTextField v-model="form.currency" label="Currency Code" variant="outlined" density="comfortable" class="mb-4" placeholder="MWK" />
        <VTextField v-model="form.notification_email" label="Notification Email" variant="outlined" density="comfortable" type="email" />
      </VCardText>
      <VDivider />
      <VCardActions class="pa-6">
        <VSpacer />
        <VBtn color="primary" :loading="saving" prepend-icon="mdi-check" @click="saveSettings">Save Settings</VBtn>
      </VCardActions>
    </VCard>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" location="top right" timeout="4000">
      {{ snackbar.message }}
    </VSnackbar>
  </div>
</template>
