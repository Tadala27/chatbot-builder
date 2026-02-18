<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import TipTapEditor from "@/components/TipTapEditor.vue";

const router = useRouter();

const form = ref({
  name: "",
  description: "<p></p>",
  whatsapp_account_id: null as number | null,
  welcome_message: "",
  fallback_message:
    "Sorry, I didn't understand that. Can you please try again?",
  default_language: "en",
  supported_languages: ["en"],
});

const whatsappAccounts = ref([]);
const isLoading = ref(false);
const isSubmitting = ref(false);
const snackbar = ref({ show: false, message: "", color: "success" });

const languageOptions = [
  { title: "English", value: "en" },
  { title: "Chichewa", value: "ny" },
  { title: "French", value: "fr" },
  { title: "Portuguese", value: "pt" },
  { title: "Swahili", value: "sw" },
];

const validateRichText = (
  content: string,
  fieldName: string,
): string | boolean => {
  if (!content || content.trim() === "<p></p>" || content.trim() === "") {
    return `${fieldName} is required`;
  }

  const tempDiv = document.createElement("div");
  tempDiv.innerHTML = content;
  const plainText = tempDiv.textContent?.trim() || "";

  if (plainText.length === 0) {
    return `${fieldName} is required`;
  }

  if (plainText.length < 10) {
    return `${fieldName} must be at least 10 characters`;
  }

  return true;
};

const richTextRules = (fieldName: string) => [
  (v: string) => validateRichText(v || "", fieldName),
];

const requiredRule = (fieldName: string) => [
  (v: any) => !!v || `${fieldName} is required`,
];

const fetchWhatsAppAccounts = async () => {
  try {
    const response = await axios.get("/api/whatsapp/accounts");
    whatsappAccounts.value = response.data.accounts.filter(
      (acc: any) => acc.is_active,
    );

    if (whatsappAccounts.value.length === 0) {
      snackbar.value = {
        show: true,
        message: "Please connect a WhatsApp account first",
        color: "warning",
      };
    }
  } catch (error: any) {
    snackbar.value = {
      show: true,
      message:
        error.response?.data?.message || "Failed to load WhatsApp accounts",
      color: "error",
    };
  }
};

const submit = async () => {
  // Client-side validation
  if (!form.value.name) {
    snackbar.value = {
      show: true,
      message: "Flow name is required",
      color: "error",
    };
    return;
  }

  if (!form.value.whatsapp_account_id) {
    snackbar.value = {
      show: true,
      message: "Please select a WhatsApp account",
      color: "error",
    };
    return;
  }

  const descriptionValidation = validateRichText(
    form.value.description,
    "Description",
  );
  if (typeof descriptionValidation === "string") {
    snackbar.value = {
      show: true,
      message: descriptionValidation,
      color: "error",
    };
    return;
  }

  isSubmitting.value = true;

  try {
    const response = await axios.post("/api/flows", form.value);

    snackbar.value = {
      show: true,
      message: response.data.message || "Flow created successfully!",
      color: "success",
    };

    // Redirect to flow builder
    setTimeout(() => {
      router.push({
        name: "chatbots-id-flow",
        params: { id: response.data.flow.id },
      });
    }, 1000);
  } catch (error: any) {
    if (error.response?.status === 422 && error.response?.data?.errors) {
      const firstError = Object.values(error.response.data.errors).flat()[0];
      snackbar.value = {
        show: true,
        message: firstError || "Validation failed",
        color: "error",
      };
    } else {
      snackbar.value = {
        show: true,
        message: error.response?.data?.message || "Failed to create flow",
        color: "error",
      };
    }
  } finally {
    isSubmitting.value = false;
  }
};

onMounted(() => {
  fetchWhatsAppAccounts();
});
</script>

<template>
  <VRow v-if="isLoading" justify="center">
    <VCol cols="auto">
      <VProgressCircular indeterminate color="primary" size="64">
        Loading...
      </VProgressCircular>
    </VCol>
  </VRow>

  <div v-else>
    <VRow class="mb-4">
      <VCol cols="12">
        <h2 class="text-h4 mb-2">Create New Flow</h2>
        <p class="text-medium-emphasis">
          Set up a new WhatsApp conversation flow for your business
        </p>
      </VCol>
    </VRow>

    <VCard elevation="2">
      <VCardText class="pa-6">
        <VForm @submit.prevent="submit">
          <!-- Basic Information -->
          <VRow>
            <VCol cols="12">
              <h3 class="text-h6 mb-4">Basic Information</h3>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name"
                label="Flow Name *"
                placeholder="e.g., Customer Support Flow"
                variant="outlined"
                :rules="requiredRule('Flow name')"
                required
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.whatsapp_account_id"
                :items="whatsappAccounts"
                item-title="verified_name"
                item-value="id"
                label="WhatsApp Account *"
                placeholder="Select WhatsApp account"
                variant="outlined"
                :rules="requiredRule('WhatsApp account')"
                required
              >
                <template #item="{ item, props }">
                  <VListItem v-bind="props">
                    <template #prepend>
                      <VIcon icon="mdi-whatsapp" color="success" />
                    </template>
                    <VListItemSubtitle>{{
                      item.raw.display_phone_number
                    }}</VListItemSubtitle>
                  </VListItem>
                </template>
              </VSelect>
            </VCol>

            <VCol cols="12">
              <TipTapEditor
                v-model="form.description"
                label="Description *"
                placeholder="Describe what your flow does..."
                :rules="richTextRules('Description')"
              />
            </VCol>
          </VRow>

          <VDivider class="my-6" />

          <!-- Messages -->
          <VRow>
            <VCol cols="12">
              <h3 class="text-h6 mb-4">Messages</h3>
            </VCol>

            <VCol cols="12" md="6">
              <VTextarea
                v-model="form.welcome_message"
                label="Welcome Message"
                placeholder="Hi! Welcome to our service..."
                variant="outlined"
                rows="3"
                hint="The first message users will see"
                persistent-hint
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextarea
                v-model="form.fallback_message"
                label="Fallback Message *"
                placeholder="Sorry, I didn't understand..."
                variant="outlined"
                rows="3"
                :rules="requiredRule('Fallback message')"
                hint="Shown when bot doesn't understand input"
                persistent-hint
                required
              />
            </VCol>
          </VRow>

          <VDivider class="my-6" />

          <!-- Language Settings -->
          <VRow>
            <VCol cols="12">
              <h3 class="text-h6 mb-4">Language Settings</h3>
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.default_language"
                :items="languageOptions"
                item-title="title"
                item-value="value"
                label="Default Language *"
                variant="outlined"
                :rules="requiredRule('Default language')"
                required
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.supported_languages"
                :items="languageOptions"
                item-title="title"
                item-value="value"
                label="Supported Languages"
                variant="outlined"
                multiple
                chips
                closable-chips
                hint="Select all languages your flow will support"
                persistent-hint
              >
                <template #chip="{ item, props }">
                  <VChip v-bind="props" size="small" color="primary">
                    {{ item.title }}
                  </VChip>
                </template>
              </VSelect>
            </VCol>
          </VRow>

          <VDivider class="my-6" />

          <!-- Actions -->
          <VRow>
            <VCol cols="12" class="d-flex justify-end gap-3">
              <VBtn
                variant="outlined"
                size="large"
                @click="router.push({ name: 'flows' })"
                :disabled="isSubmitting"
              >
                Cancel
              </VBtn>
              <VBtn
                type="submit"
                color="primary"
                size="large"
                prepend-icon="mdi-plus"
                :loading="isSubmitting"
                :disabled="isSubmitting"
              >
                Create Flow
              </VBtn>
            </VCol>
          </VRow>
        </VForm>
      </VCardText>
    </VCard>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      :timeout="4000"
      location="top right"
    >
      {{ snackbar.message }}
      <template #actions>
        <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
      </template>
    </VSnackbar>
  </div>
</template>

<style scoped>
.gap-3 {
  gap: 12px;
}
</style>
