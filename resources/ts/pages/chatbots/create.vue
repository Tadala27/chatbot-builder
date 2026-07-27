<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import PageHeader from "@/components/PageHeader.vue";

const router = useRouter();
const isSaving = ref(false);
const loadingAccounts = ref(true);

const whatsappAccounts = ref<
  { id: number; display_phone_number: string; verified_name: string }[]
>([]);

const form = ref({
  name: "",
  description: "",
  whatsapp_account_id: null as number | null,

  default_language: "en",
  is_active: false,
});

const errors = ref<Record<string, string>>({});

const languageOptions = [
  { title: "English", value: "en" },
  { title: "Chichewa", value: "ny" },
  { title: "French", value: "fr" },
  { title: "Portuguese", value: "pt" },
];

const fetchAccounts = async () => {
  loadingAccounts.value = true;
  try {
    const { data } = await axios.get(
      "/tenant/whatsapp-accounts?mode=managed_bot",
    );
    whatsappAccounts.value = data.data ?? data;
  } finally {
    loadingAccounts.value = false;
  }
};
const submit = async () => {
  errors.value = {};
  if (!form.value.name) {
    errors.value.name = "Bot name is required.";
    return;
  }
  if (!form.value.whatsapp_account_id) {
    errors.value.whatsapp_account_id = "Select a WhatsApp account.";
    return;
  }

  isSaving.value = true;
  try {
    const { data } = await axios.post("/tenant/bots", form.value);
    await Swal.fire({
      title: "Bot Created",
      text: "Your bot has been created.",
      icon: "success",
    });
    router.push(`/chatbots/${data.data?.id ?? data.id}`);
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors)
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
      );
    Swal.fire({
      title: "Error",
      text: err.response?.data?.message ?? "Failed to create bot.",
      icon: "error",
    });
  } finally {
    isSaving.value = false;
  }
};

onMounted(fetchAccounts);
</script>

<template>
  <div>
    <PageHeader
      title="Create New Bot"
      subtitle="Set up a new WhatsApp chatbot for your business"
      icon="$robot"
      back-to="/chatbots"
    />

    <VCard variant="flat" border rounded="lg">
      <VCardText class="pa-6">
        <VRow>
          <VCol cols="12" md="4">
            <VTextField
              v-model="form.name"
              label="Bot Name *"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :error-messages="errors.name"
            />
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.whatsapp_account_id"
              :items="whatsappAccounts"
              item-title="verified_name"
              item-value="id"
              label="WhatsApp Account *"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :loading="loadingAccounts"
              :error-messages="errors.whatsapp_account_id"
            >
              <template #item="{ item, props }">
                <VListItem
                  v-bind="props"
                  :title="item.raw.verified_name"
                  :subtitle="item.raw.display_phone_number"
                />
              </template>
            </VSelect>
          </VCol>
          <VCol cols="12" md="4">
            <VSelect
              v-model="form.default_language"
              :items="languageOptions"
              label="Default Language"
              variant="outlined"
              density="comfortable"
              rounded="lg"
            />
          </VCol>
          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              label="Description"
              rows="2"
              variant="outlined"
              density="comfortable"
              rounded="lg"
            />
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />
      <VCardActions class="pa-6">
        <VBtn variant="text" color="error" @click="router.push('/chatbots')"
          >Cancel</VBtn
        >
        <VSpacer />
        <VBtn
          color="primary"
          variant="flat"
          prepend-icon="$check"
          :loading="isSaving"
          @click="submit"
          >Create Bot</VBtn
        >
      </VCardActions>
    </VCard>
  </div>
</template>
