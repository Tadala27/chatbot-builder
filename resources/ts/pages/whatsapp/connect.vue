<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";

const router = useRouter();

const accounts = ref([]);
const isLoading = ref(false);
const isConnecting = ref(false);
const snackbar = ref({ show: false, message: "", color: "success" });

const fetchAccounts = async () => {
  isLoading.value = true;
  try {
    const response = await axios.get("/api/whatsapp/accounts");
    accounts.value = response.data.accounts;
  } catch (error: any) {
    snackbar.value = {
      show: true,
      message:
        error.response?.data?.message || "Failed to load WhatsApp accounts",
      color: "error",
    };
  } finally {
    isLoading.value = false;
  }
};

const connectWhatsApp = async () => {
  isConnecting.value = true;
  try {
    // Get signup URL from backend
    const response = await axios.get("/api/whatsapp/signup-url");
    const signupUrl = response.data.signup_url;

    // Open Facebook signup in new window
    const width = 600;
    const height = 700;
    const left = screen.width / 2 - width / 2;
    const top = screen.height / 2 - height / 2;

    const popup = window.open(
      signupUrl,
      "whatsapp_signup",
      `width=${width},height=${height},left=${left},top=${top}`,
    );

    // Listen for callback
    const handleCallback = (event: MessageEvent) => {
      if (event.data.type === "whatsapp_connected") {
        popup?.close();
        window.removeEventListener("message", handleCallback);

        snackbar.value = {
          show: true,
          message: "WhatsApp account connected successfully!",
          color: "success",
        };

        fetchAccounts();
      }
    };

    window.addEventListener("message", handleCallback);

    // Check if popup was closed
    const checkClosed = setInterval(() => {
      if (popup?.closed) {
        clearInterval(checkClosed);
        isConnecting.value = false;
        window.removeEventListener("message", handleCallback);
      }
    }, 500);
  } catch (error: any) {
    snackbar.value = {
      show: true,
      message:
        error.response?.data?.message ||
        "Failed to initiate WhatsApp connection",
      color: "error",
    };
    isConnecting.value = false;
  }
};

const syncAccount = async (account: any) => {
  try {
    await axios.post(`/api/whatsapp/accounts/${account.id}/sync`);
    snackbar.value = {
      show: true,
      message: "Account synced successfully!",
      color: "success",
    };
    fetchAccounts();
  } catch (error: any) {
    snackbar.value = {
      show: true,
      message: error.response?.data?.message || "Failed to sync account",
      color: "error",
    };
  }
};

const disconnectAccount = async (account: any) => {
  if (
    !confirm(
      `Are you sure you want to disconnect ${account.display_phone_number}?`,
    )
  ) {
    return;
  }

  try {
    await axios.delete(`/api/whatsapp/accounts/${account.id}`);
    snackbar.value = {
      show: true,
      message: "Account disconnected successfully!",
      color: "success",
    };
    fetchAccounts();
  } catch (error: any) {
    snackbar.value = {
      show: true,
      message: error.response?.data?.message || "Failed to disconnect account",
      color: "error",
    };
  }
};

const getQualityColor = (rating: string) => {
  return rating === "GREEN"
    ? "success"
    : rating === "YELLOW"
      ? "warning"
      : "error";
};

onMounted(() => {
  fetchAccounts();
});
</script>

<template>
  <div>
    <VRow class="mb-4">
      <VCol cols="12">
        <div class="d-flex align-center justify-space-between">
          <div>
            <h2 class="text-h4 mb-2">WhatsApp Accounts</h2>
            <p class="text-medium-emphasis">
              Connect your WhatsApp Business accounts to create chatbots
            </p>
          </div>
          <VBtn
            color="success"
            prepend-icon="$plus"
            size="large"
            @click="connectWhatsApp"
            :loading="isConnecting"
            :disabled="isConnecting"
          >
            Connect WhatsApp
          </VBtn>
        </div>
      </VCol>
    </VRow>

    <!-- Loading State -->
    <VRow v-if="isLoading" justify="center">
      <VCol cols="auto">
        <VProgressCircular indeterminate color="primary" size="64">
          Loading...
        </VProgressCircular>
      </VCol>
    </VRow>

    <!-- Empty State -->
    <VRow v-else-if="accounts.length === 0" justify="center">
      <VCol cols="12" md="8" lg="6">
        <VCard class="pa-8 text-center" variant="outlined">
          <VIcon icon="$whatsapp" size="80" color="success" class="mb-4" />
          <h3 class="text-h5 mb-2">No WhatsApp Accounts Connected</h3>
          <p class="text-medium-emphasis mb-6">
            Connect your WhatsApp Business account to start creating chatbots
          </p>
          <VBtn
            color="success"
            size="large"
            prepend-icon="$plus"
            @click="connectWhatsApp"
            :loading="isConnecting"
          >
            Connect Your First Account
          </VBtn>
        </VCard>
      </VCol>
    </VRow>

    <!-- Accounts Grid -->
    <VRow v-else>
      <VCol
        v-for="account in accounts"
        :key="account.id"
        cols="12"
        md="6"
        lg="4"
      >
        <VCard elevation="2">
          <VCardTitle class="d-flex align-center pa-4">
            <VIcon icon="$whatsapp" color="success" size="32" class="mr-3" />
            <div>
              <div class="text-h6">{{ account.verified_name }}</div>
              <div class="text-caption text-medium-emphasis">
                {{ account.display_phone_number }}
              </div>
            </div>
          </VCardTitle>

          <VDivider />

          <VCardText>
            <VRow dense>
              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-caption text-medium-emphasis">Status</span>
                  <VChip
                    :color="account.is_active ? 'success' : 'error'"
                    size="small"
                  >
                    {{ account.is_active ? "Active" : "Inactive" }}
                  </VChip>
                </div>
              </VCol>

              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-caption text-medium-emphasis">Quality</span>
                  <VChip
                    :color="getQualityColor(account.quality_rating)"
                    size="small"
                  >
                    {{ account.quality_rating }}
                  </VChip>
                </div>
              </VCol>

              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-caption text-medium-emphasis">Limit</span>
                  <span class="text-caption">{{
                    account.messaging_limit
                  }}</span>
                </div>
              </VCol>

              <VCol cols="12">
                <div class="d-flex align-center justify-space-between mb-2">
                  <span class="text-caption text-medium-emphasis"
                    >Chatbots</span
                  >
                  <span class="text-caption">{{
                    account.stats?.total_chatbots || 0
                  }}</span>
                </div>
              </VCol>

              <VCol cols="12">
                <div class="d-flex align-center justify-space-between">
                  <span class="text-caption text-medium-emphasis"
                    >Conversations</span
                  >
                  <span class="text-caption">{{
                    account.stats?.total_conversations || 0
                  }}</span>
                </div>
              </VCol>
            </VRow>
          </VCardText>

          <VDivider />

          <VCardActions class="pa-4">
            <VBtn
              variant="text"
              size="small"
              prepend-icon="$reload"
              @click="syncAccount(account)"
            >
              Sync
            </VBtn>
            <VSpacer />
            <VBtn
              variant="text"
              color="error"
              size="small"
              prepend-icon="$linkOff"
              @click="disconnectAccount(account)"
            >
              Disconnect
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

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
