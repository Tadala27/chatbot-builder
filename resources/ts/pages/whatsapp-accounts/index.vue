<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface WhatsappAccount {
  id: number;
  phone_number: string;
  display_phone_number: string | null;
  verified_name: string | null;
  quality_rating: "GREEN" | "YELLOW" | "RED" | "UNKNOWN";
  messaging_limit: string;
  is_active: boolean;
  last_synced_at: string | null;
}

const accounts = ref<WhatsappAccount[]>([]);
const isLoading = ref(true);
const connecting = ref(false);

const qualityColor: Record<string, string> = {
  GREEN: "success",
  YELLOW: "warning",
  RED: "error",
  UNKNOWN: "default",
};

const fetchAccounts = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/whatsapp-accounts");
    accounts.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
};

const connectAccount = async () => {
  connecting.value = true;
  try {
    const { data } = await axios.get("/tenant/whatsapp-accounts/signup-url");
    window.open(data.url ?? data.data?.url, "_blank", "width=600,height=700");
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to get signup URL.",
      icon: "error",
    });
  } finally {
    connecting.value = false;
  }
};

const syncAccount = async (account: WhatsappAccount) => {
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/sync`);
    fetchAccounts();
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to sync account.",
      icon: "error",
    });
  }
};

const reconnectAccount = async (account: WhatsappAccount) => {
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/reconnect`);
    fetchAccounts();
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to reconnect.",
      icon: "error",
    });
  }
};

const disconnectAccount = async (account: WhatsappAccount) => {
  const { isConfirmed } = await Swal.fire({
    title: "Disconnect Account",
    text: `Disconnect ${account.display_phone_number ?? account.phone_number}? Bots using it will stop working.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
  });
  if (!isConfirmed) return;
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/disconnect`);
    fetchAccounts();
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to disconnect.",
      icon: "error",
    });
  }
};

const formatDate = (d: string | null) =>
  d
    ? new Date(d).toLocaleString("en-GB", {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "Never";

onMounted(fetchAccounts);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">WhatsApp Accounts</h1>
        <p class="text-subtitle-1 text-medium-emphasis">
          Manage connected WhatsApp Business numbers
        </p>
      </div>
      <VBtn
        color="success"
        prepend-icon="mdi-whatsapp"
        :loading="connecting"
        @click="connectAccount"
        >Connect Account</VBtn
      >
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <VCol v-if="!accounts.length" cols="12">
        <VCard variant="outlined" class="text-center py-12">
          <VIcon size="64" color="grey-lighten-1">mdi-whatsapp</VIcon>
          <h3 class="text-h6 text-grey mt-4">No WhatsApp accounts connected</h3>
          <VBtn
            color="success"
            variant="outlined"
            class="mt-4"
            @click="connectAccount"
            >Connect your first account</VBtn
          >
        </VCard>
      </VCol>

      <VCol v-for="account in accounts" :key="account.id" cols="12" md="6">
        <VCard variant="outlined" elevation="0">
          <VCardText>
            <div class="d-flex align-center gap-3 mb-3">
              <VAvatar color="success" variant="tonal" size="44"
                ><VIcon
                  color="white"
                  style="background: #25d366; border-radius: 50%; padding: 4px"
                  >mdi-whatsapp</VIcon
                ></VAvatar
              >
              <div class="flex-grow-1">
                <p class="text-subtitle-1 font-weight-medium mb-0">
                  {{ account.verified_name || "Unverified" }}
                </p>
                <p class="text-caption text-medium-emphasis mb-0">
                  {{ account.display_phone_number || account.phone_number }}
                </p>
              </div>
              <VChip
                :color="account.is_active ? 'success' : 'error'"
                size="small"
                variant="tonal"
              >
                {{ account.is_active ? "Active" : "Inactive" }}
              </VChip>
            </div>
            <VDivider class="my-2" />
            <div
              class="d-flex gap-4 text-caption text-medium-emphasis flex-wrap"
            >
              <span
                >Quality:
                <VChip
                  :color="qualityColor[account.quality_rating]"
                  size="x-small"
                  variant="tonal"
                  >{{ account.quality_rating }}</VChip
                ></span
              >
              <span
                >Limit:
                {{
                  account.messaging_limit.replace("TIER_", "").replace("_", " ")
                }}</span
              >
              <span>Synced: {{ formatDate(account.last_synced_at) }}</span>
            </div>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4">
            <VBtn
              size="small"
              variant="text"
              prepend-icon="mdi-refresh"
              @click="syncAccount(account)"
              >Sync</VBtn
            >
            <VBtn
              size="small"
              variant="text"
              prepend-icon="mdi-link-variant"
              @click="reconnectAccount(account)"
              >Reconnect</VBtn
            >
            <VSpacer />
            <VBtn
              size="small"
              variant="text"
              color="error"
              prepend-icon="mdi-link-off"
              @click="disconnectAccount(account)"
              >Disconnect</VBtn
            >
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
