<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import PageHeader from "@/components/PageHeader.vue";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const isSaving = ref(false);
const loadingAccounts = ref(true);

const whatsappAccounts = ref<
  {
    id: string;
    display_phone_number: string;
    verified_name: string;
    phone_number: string;
    is_active: boolean;
  }[]
>([]);

const form = ref({
  name: "",
  description: "",
  whatsapp_account_id: null as string | null,
  default_language: "en",
  is_active: true,
  supported_languages: [] as string[],
  settings: {} as Record<string, any>,
});

const errors = ref<Record<string, string>>({});

const languageOptions = [
  { title: "English", value: "en" },
  { title: "Chichewa", value: "ny" },
  { title: "French", value: "fr" },
  { title: "Portuguese", value: "pt" },
  { title: "Spanish", value: "es" },
  { title: "Arabic", value: "ar" },
];

const fetchBot = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/tenant/bots/${route.params.id}`);
    const b = data.bot ?? data;
    form.value = {
      name: b.name,
      description: b.description ?? "",
      whatsapp_account_id: b.whatsapp_account_id,
      default_language: b.default_language ?? "en",
      is_active: b.is_active ?? true,
      supported_languages: b.supported_languages ?? [],
      settings: b.settings ?? {},
    };
  } catch (err: any) {
    Swal.fire({
      title: "Error",
      text: err.response?.data?.message ?? "Failed to load bot.",
      icon: "error",
    });
    router.push("/chatbots");
  } finally {
    isLoading.value = false;
  }
};

const fetchAccounts = async () => {
  loadingAccounts.value = true;
  try {
    const { data } = await axios.get("/tenant/whatsapp-accounts");
    whatsappAccounts.value = data.data ?? data;
  } catch (err: any) {
    console.error("Failed to load WhatsApp accounts:", err);
  } finally {
    loadingAccounts.value = false;
  }
};

const submit = async () => {
  errors.value = {};

  // Validation
  if (!form.value.name?.trim()) {
    errors.value.name = "Bot name is required.";
    return;
  }
  if (!form.value.whatsapp_account_id) {
    errors.value.whatsapp_account_id = "Please select a WhatsApp account.";
    return;
  }

  isSaving.value = true;
  try {
    // Prepare the update data - only send fields that exist in the migration
    const updateData = {
      name: form.value.name.trim(),
      description: form.value.description?.trim() || null,
      whatsapp_account_id: form.value.whatsapp_account_id,
      default_language: form.value.default_language,
      is_active: form.value.is_active,
      supported_languages:
        form.value.supported_languages.length > 0
          ? form.value.supported_languages
          : null,
      settings:
        Object.keys(form.value.settings).length > 0
          ? form.value.settings
          : null,
    };

    await axios.put(`/tenant/bots/${route.params.id}`, updateData);

    await Swal.fire({
      title: "Saved!",
      text: "Bot updated successfully.",
      icon: "success",
      timer: 1500,
      showConfirmButton: false,
    });

    router.push(`/chatbots/${route.params.id}`);
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
      );
    }
    Swal.fire({
      title: "Error",
      text: err.response?.data?.message ?? "Failed to update bot.",
      icon: "error",
    });
  } finally {
    isSaving.value = false;
  }
};

const goBack = () => {
  router.push(`/chatbots/${route.params.id}`);
};

const formatPhoneNumber = (phone: string) => {
  if (!phone) return "";
  // Format the phone number for display
  return phone.replace(/(\+\d{1,3})(\d{3})(\d{3})(\d{4})/, "$1 $2 $3 $4");
};

onMounted(async () => {
  await Promise.all([fetchAccounts(), fetchBot()]);
});
</script>

<template>
  <div>
    <PageHeader
      title="Edit Bot"
      subtitle="Update your bot's details, settings, and behavior"
      icon="$robot"
      :back-to="`/chatbots/${route.params.id}`"
    />

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VCard v-else variant="flat" border rounded="lg">
      <VCardText class="pa-6">
        <VRow>
          <!-- Bot Name -->
          <VCol cols="12" md="6">
            <VTextField
              v-model="form.name"
              label="Bot Name *"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :error-messages="errors.name"
              placeholder="e.g., Customer Support Bot"
            />
          </VCol>

          <!-- WhatsApp Account -->
          <VCol cols="12" md="6">
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
              :disabled="!whatsappAccounts.length"
            >
              <template #item="{ item, props }">
                <VListItem
                  v-bind="props"
                  :title="
                    item.raw.verified_name ||
                    item.raw.display_phone_number ||
                    item.raw.phone_number
                  "
                  :subtitle="`${item.raw.display_phone_number || item.raw.phone_number}${!item.raw.is_active ? ' (Inactive)' : ''}`"
                >
                  <template #prepend>
                    <VIcon
                      :color="item.raw.is_active ? 'success' : 'error'"
                      :icon="
                        item.raw.is_active
                          ? 'mdi-check-circle'
                          : 'mdi-close-circle'
                      "
                      size="18"
                    />
                  </template>
                </VListItem>
              </template>
              <template #selection="{ item }">
                <div class="d-flex align-center gap-2">
                  <span>{{
                    item.raw.verified_name ||
                    item.raw.display_phone_number ||
                    item.raw.phone_number
                  }}</span>
                  <VChip
                    size="x-small"
                    :color="item.raw.is_active ? 'success' : 'error'"
                    variant="flat"
                  >
                    {{ item.raw.is_active ? "Active" : "Inactive" }}
                  </VChip>
                </div>
              </template>
            </VSelect>
            <div
              v-if="!whatsappAccounts.length && !loadingAccounts"
              class="text-caption text-error mt-1"
            >
              No WhatsApp accounts available. Please connect a number first.
              <router-link to="/whatsapp-accounts" class="text-primary"
                >Connect now</router-link
              >
            </div>
          </VCol>

          <!-- Description -->
          <VCol cols="12">
            <VTextarea
              v-model="form.description"
              label="Description"
              rows="3"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              placeholder="Describe what this bot does and its purpose..."
              :error-messages="errors.description"
            />
          </VCol>

          <!-- Language Settings -->
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.default_language"
              :items="languageOptions"
              label="Default Language"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              :error-messages="errors.default_language"
            />
          </VCol>

          <!-- Supported Languages -->
          <VCol cols="12" md="6">
            <VSelect
              v-model="form.supported_languages"
              :items="languageOptions"
              label="Supported Languages"
              variant="outlined"
              density="comfortable"
              rounded="lg"
              multiple
              chips
              clearable
              hint="Select all languages this bot supports"
              persistent-hint
            >
              <template #selection="{ item, index }">
                <VChip
                  v-if="index < 2"
                  size="small"
                  color="primary"
                  variant="tonal"
                >
                  {{ item.raw.title }}
                </VChip>
                <span
                  v-if="index === 2"
                  class="text-caption text-medium-emphasis"
                >
                  +{{ form.supported_languages.length - 2 }} more
                </span>
              </template>
            </VSelect>
          </VCol>

          <!-- Active Status -->
          <VCol cols="12">
            <VDivider class="mb-4" />
            <div class="d-flex align-center ga-4">
              <VSwitch
                v-model="form.is_active"
                label="Bot Active"
                color="success"
                hide-details
              />
              <VChip
                :color="form.is_active ? 'success' : 'error'"
                size="small"
                variant="flat"
              >
                {{ form.is_active ? "Online" : "Offline" }}
              </VChip>
              <span class="text-caption text-medium-emphasis">
                {{
                  form.is_active
                    ? "Bot will respond to messages"
                    : "Bot is paused"
                }}
              </span>
            </div>
          </VCol>

          <!-- Advanced Settings (Collapsible) -->
          <VCol cols="12">
            <VExpansionPanels variant="accordion" class="mt-2">
              <VExpansionPanel title="Advanced Settings">
                <template #text>
                  <VRow>
                    <VCol cols="12">
                      <p class="text-caption text-medium-emphasis mb-3">
                        Configure advanced bot settings. These are stored in the
                        settings JSON field.
                      </p>
                      <VTextarea
                        v-model="form.settings"
                        label="Settings (JSON)"
                        rows="4"
                        variant="outlined"
                        density="comfortable"
                        rounded="lg"
                        placeholder='{"timeout": 30, "max_retries": 3}'
                      />
                      <div class="text-caption text-medium-emphasis mt-1">
                        ⚠️ Enter valid JSON. Invalid JSON will be ignored.
                      </div>
                    </VCol>
                  </VRow>
                </template>
              </VExpansionPanel>
            </VExpansionPanels>
          </VCol>
        </VRow>
      </VCardText>

      <VDivider />

      <VCardActions class="pa-6">
        <VBtn variant="text" color="error" @click="goBack"> Cancel </VBtn>
        <VSpacer />
        <VBtn
          color="primary"
          variant="flat"
          prepend-icon="mdi-content-save"
          :loading="isSaving"
          @click="submit"
        >
          Save Changes
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>

<style scoped>
/* Style overrides for better appearance */
:deep(.v-expansion-panel) {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
:deep(.v-expansion-panel:not(:last-child)) {
  border-bottom: 0;
}
:deep(.v-expansion-panel__title) {
  font-weight: 500;
  font-size: 14px;
}
</style>
