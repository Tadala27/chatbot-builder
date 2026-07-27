<script setup lang="ts">
import { computed, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import ConnectAccountDialog from "./connect.vue";

interface WhatsappAccount {
  id: string;
  phone_number: string;
  display_phone_number: string | null;
  verified_name: string | null;
  quality_rating: "GREEN" | "YELLOW" | "RED" | "UNKNOWN";
  whatsapp_business_manager_messaging_limit: string | null;
  onboarding_status: "pending_registration" | "pending_payment" | "active";
  mode: "managed_bot" | "connector" | "undefined" | null;
  webhook_url: string | null;
  is_active: boolean;
  last_synced_at: string | null;
}

declare global {
  interface Window {
    FB: any;
    fbAsyncInit: () => void;
  }
}

const APP_ID = import.meta.env.VITE_META_APP_ID;
const CONFIG_ID = import.meta.env.VITE_META_ES_CONFIG_ID;

const router = useRouter();

const accounts = ref<WhatsappAccount[]>([]);
const isLoading = ref(true);
const dialogOpen = ref(false);
const sdkReady = ref(false);
const connectDialog = ref<InstanceType<typeof ConnectAccountDialog> | null>(
  null,
);
const setupAccount = ref<{ id: string; phone: string } | null>(null);

const snackbar = ref({
  show: false,
  text: "",
  color: "success" as "success" | "error",
});

function showSnackbar(text: string, color: "success" | "error" = "success") {
  snackbar.value = { show: true, text, color };
}

function notifyError(e: any, fallback: string) {
  showSnackbar(e.response?.data?.message ?? fallback, "error");
}

let sdkLoadPromise: Promise<void> | null = null;

function loadSdk(): Promise<void> {
  if (sdkLoadPromise) return sdkLoadPromise;
  sdkLoadPromise = new Promise<void>((resolve, reject) => {
    if (!APP_ID || !CONFIG_ID) {
      reject(
        new Error(
          "Missing VITE_META_APP_ID or VITE_META_ES_CONFIG_ID — check your .env.",
        ),
      );
      return;
    }
    if (window.FB) {
      sdkReady.value = true;
      resolve();
      return;
    }
    window.fbAsyncInit = () => {
      window.FB.init({
        appId: APP_ID,
        autoLogAppEvents: true,
        xfbml: true,
        version: "v25.0",
      });
      sdkReady.value = true;
      resolve();
    };
    const script = document.createElement("script");
    script.src = "https://connect.facebook.net/en_US/sdk.js";
    script.async = true;
    script.defer = true;
    script.onerror = () =>
      reject(new Error("Failed to load the Facebook JS SDK."));
    document.body.appendChild(script);
  });
  return sdkLoadPromise;
}

function registerMessageListener(): void {
  window.addEventListener("message", (event) => {
    if (!event.origin.endsWith("facebook.com")) return;
    try {
      const data = JSON.parse(event.data);
      if (data.type === "WA_EMBEDDED_SIGNUP" && data.event === "FINISH") {
        connectDialog.value?.registerSessionPayload({
          waba_id: data.data.waba_id,
          phone_number_id: data.data.phone_number_id,
          business_id: data.data.business_id,
        });
      }
    } catch {
      /* not a signup event */
    }
  });
}

async function fetchAccounts(): Promise<void> {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/whatsapp-accounts");
    accounts.value = data.data ?? data;
  } finally {
    isLoading.value = false;
  }
}

type GroupKey = "active" | "registering" | "payment" | "inactive";

function groupOf(account: WhatsappAccount): GroupKey {
  if (!account.is_active) return "inactive";
  if (account.onboarding_status === "pending_registration")
    return "registering";
  if (account.onboarding_status === "pending_payment") return "payment";
  return "active";
}

const sections: {
  key: GroupKey;
  title: string;
  hint: string;
  color: string;
  icon: string;
}[] = [
  {
    key: "active",
    title: "Active",
    hint: "Sending and receiving normally",
    color: "success",
    icon: "$checkCircle",
  },
  {
    key: "registering",
    title: "Registering",
    hint: "Meta is finishing verification",
    color: "secondary",
    icon: "$progressClock",
  },
  {
    key: "payment",
    title: "Payment needed",
    hint: "Add billing to unlock sending",
    color: "warning",
    icon: "$creditCardOutline",
  },
  {
    key: "inactive",
    title: "Disconnected",
    hint: "Not currently in use",
    color: "error",
    icon: "$linkOff",
  },
];

const grouped = computed(() => {
  const map: Record<GroupKey, WhatsappAccount[]> = {
    active: [],
    registering: [],
    payment: [],
    inactive: [],
  };
  for (const account of accounts.value) map[groupOf(account)].push(account);
  return map;
});

const visibleSections = computed(() =>
  sections.filter((s) => grouped.value[s.key].length > 0),
);

function openAccount(account: WhatsappAccount): void {
  if (account.mode === "undefined") {
    // Account exists but mode was never chosen — complete setup inline
    setupAccount.value = {
      id: account.id,
      phone: account.display_phone_number ?? account.phone_number,
    };
    dialogOpen.value = true;
    return;
  }

  router.push(`/whatsapp-account/${account.id}/detail`);
}

async function syncAccount(account: WhatsappAccount): Promise<void> {
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/sync`);
    await fetchAccounts();
    showSnackbar("Account synced.");
  } catch (e: any) {
    notifyError(e, "Sync failed.");
  }
}

async function reconnectAccount(account: WhatsappAccount): Promise<void> {
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/reconnect`);
    await fetchAccounts();
    showSnackbar("Account reconnected.");
  } catch (e: any) {
    notifyError(e, "Reconnect failed.");
  }
}

async function disconnectAccount(account: WhatsappAccount): Promise<void> {
  const { isConfirmed } = await Swal.fire({
    title: "Disconnect this number?",
    text: `${account.display_phone_number ?? account.phone_number} will stop working for all bots. This does not remove it from Meta.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    confirmButtonText: "Disconnect",
  });
  if (!isConfirmed) return;
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.id}/disconnect`);
    await fetchAccounts();
    showSnackbar("Account disconnected.");
  } catch (e: any) {
    notifyError(e, "Disconnect failed.");
  }
}

const qualityChipColor: Record<string, string> = {
  GREEN: "success",
  YELLOW: "warning",
  RED: "error",
  UNKNOWN: "default",
};
const qualityLabel: Record<string, string> = {
  GREEN: "Good quality",
  YELLOW: "Fair quality",
  RED: "At risk",
  UNKNOWN: "Unrated",
};
const modeLabel: Record<string, string> = {
  managed_bot: "Managed bot",
  connector: "Connector",
};

function limitLabel(limit: string | null): string {
  if (!limit) return "—";
  return limit.replace("TIER_", "").replace(/_/g, " ") + " / day";
}

function formatDate(d: string | null): string {
  if (!d) return "Never synced";
  return new Date(d).toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });
}

watch(dialogOpen, (open) => {
  if (!open) setupAccount.value = null;
});

onMounted(() => {
  registerMessageListener();
  fetchAccounts();
  loadSdk().catch(() => {
    /* surfaced on click */
  });
});
</script>

<template>
  <div>
    <div class="d-flex align-start justify-space-between gap-4 mb-6 flex-wrap">
      <div>
        <h1 class="text-h5 font-weight-bold mb-1">WhatsApp Accounts</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Manage connected WhatsApp Business numbers
        </p>
      </div>
      <VBtn
        color="primary"
        variant="flat"
        prepend-icon="$plus"
        @click="dialogOpen = true"
      >
        Connect Account
      </VBtn>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-16">
      <VProgressCircular indeterminate color="primary" size="40" />
    </div>

    <template v-else>
      <!-- Empty state -->
      <VCard
        v-if="!accounts.length"
        variant="flat"
        border
        rounded="xl"
        class="text-center pa-12"
      >
        <VAvatar size="56" color="success" variant="tonal" class="mx-auto mb-4">
          <VIcon size="28" color="success">
            <svg
              viewBox="0 0 24 24"
              fill="none"
              xmlns="http://www.w3.org/2000/svg"
            >
              <path
                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a8 8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"
                fill="currentColor"
              />
              <path
                d="M12 2C6.477 2 2 6.477 2 12c0 1.89.522 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"
                stroke="currentColor"
                stroke-width="1.5"
                fill="none"
              />
            </svg>
          </VIcon>
        </VAvatar>
        <h3 class="text-h6 font-weight-bold mb-2">No numbers connected yet</h3>
        <p
          class="text-body-2 text-medium-emphasis mb-6"
          style="max-width: 340px; margin-inline: auto"
        >
          Connect a WhatsApp Business number to start building bots and handling
          conversations.
        </p>
        <VBtn color="primary" variant="flat" @click="dialogOpen = true">
          Connect your first number
        </VBtn>
      </VCard>

      <!-- Sections -->
      <div v-else class="d-flex flex-column gap-8">
        <div v-for="section in visibleSections" :key="section.key">
          <!-- Section label -->
          <div class="d-flex align-center gap-2 mb-3">
            <VIcon :icon="section.icon" :color="section.color" size="16" />
            <span
              class="text-caption font-weight-bold text-uppercase"
              style="letter-spacing: 0.06em"
            >
              {{ section.title }}
            </span>
            <VChip
              size="x-small"
              variant="tonal"
              :color="section.color"
              class="ml-1"
            >
              {{ grouped[section.key].length }}
            </VChip>
            <span class="text-caption text-medium-emphasis ml-1"
              >— {{ section.hint }}</span
            >
          </div>

          <!-- Cards grid -->
          <div class="account-grid">
            <VCard
              v-for="account in grouped[section.key]"
              :key="account.id"
              variant="outlined"
              rounded="lg"
              class="account-card"
              @click="openAccount(account)"
            >
              <VCardText class="pa-4">
                <!-- Top row: avatar + number + menu -->
                <div class="d-flex align-start justify-space-between mb-3">
                  <div class="d-flex align-center gap-3">
                    <VAvatar
                      size="40"
                      color="success"
                      variant="tonal"
                      rounded="lg"
                    >
                      <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                      >
                        <path
                          d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51a8 8 0 00-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"
                          fill="#1fc06b"
                        />
                        <path
                          d="M12 2C6.477 2 2 6.477 2 12c0 1.89.522 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"
                          stroke="#1fc06b"
                          stroke-width="1.5"
                          fill="none"
                        />
                      </svg>
                    </VAvatar>
                    <div>
                      <p
                        class="text-body-1 font-weight-semibold mb-0"
                        style="line-height: 1.3"
                      >
                        {{
                          account.display_phone_number ?? account.phone_number
                        }}
                      </p>
                      <p class="text-caption text-medium-emphasis mb-0">
                        {{ account.verified_name ?? "Unverified name" }}
                      </p>
                    </div>
                  </div>

                  <VMenu location="bottom end">
                    <template #activator="{ props }">
                      <VBtn
                        icon
                        size="x-small"
                        variant="text"
                        v-bind="props"
                        @click.stop
                      >
                        <VIcon icon="$dotsVertical" size="16" />
                      </VBtn>
                    </template>
                    <VList density="compact" min-width="160">
                      <VListItem
                        prepend-icon="$reload"
                        title="Sync"
                        @click.stop="syncAccount(account)"
                      />
                      <VListItem
                        v-if="!account.is_active"
                        prepend-icon="$linkVariant"
                        title="Reconnect"
                        @click.stop="reconnectAccount(account)"
                      />
                      <VListItem
                        v-else
                        prepend-icon="$linkOff"
                        title="Disconnect"
                        base-color="error"
                        @click.stop="disconnectAccount(account)"
                      />
                    </VList>
                  </VMenu>
                </div>

                <VDivider class="mb-3" />

                <!-- Chips row -->
                <div class="d-flex flex-wrap gap-2 mb-3">
                  <VChip
                    v-if="account.mode"
                    size="small"
                    variant="tonal"
                    color="primary"
                    prepend-icon="$robot"
                  >
                    {{ modeLabel[account.mode] ?? account.mode }}
                  </VChip>
                  <VChip
                    v-else
                    size="small"
                    variant="tonal"
                    prepend-icon="$alertCircleOutline"
                  >
                    Not configured
                  </VChip>
                  <VChip
                    size="small"
                    variant="tonal"
                    :color="qualityChipColor[account.quality_rating]"
                  >
                    {{ qualityLabel[account.quality_rating] }}
                  </VChip>
                </div>

                <!-- Stats row -->
                <div class="d-flex align-center justify-space-between">
                  <div>
                    <p class="text-caption text-medium-emphasis mb-0">
                      Daily limit
                    </p>
                    <p class="text-body-2 font-weight-medium mb-0">
                      {{
                        limitLabel(
                          account.whatsapp_business_manager_messaging_limit,
                        )
                      }}
                    </p>
                  </div>
                  <VDivider vertical class="mx-3" style="height: 28px" />
                  <div class="text-right">
                    <p class="text-caption text-medium-emphasis mb-0">
                      Last synced
                    </p>
                    <p class="text-body-2 font-weight-medium mb-0">
                      {{ formatDate(account.last_synced_at) }}
                    </p>
                  </div>
                </div>
              </VCardText>

              <!-- Status footer -->
              <VCardActions class="pa-0">
                <div
                  class="account-card__footer"
                  :class="`account-card__footer--${section.color}`"
                >
                  <VIcon :icon="section.icon" size="13" />
                  <span>{{ section.title }}</span>
                </div>
              </VCardActions>
            </VCard>
          </div>
        </div>
      </div>
    </template>

    <ConnectAccountDialog
      ref="connectDialog"
      v-model="dialogOpen"
      :sdk-ready="sdkReady"
      :setup-account="setupAccount"
      @connected="fetchAccounts"
    />

    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      timeout="4000"
      location="top right"
      closable
    >
      {{ snackbar.text }}
      <template #actions>
        <VBtn
          variant="text"
          size="small"
          icon="$close"
          @click="snackbar.show = false"
        />
      </template>
    </VSnackbar>
  </div>
</template>

<style scoped>
.gap-8 {
  gap: 32px;
}

.account-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
}

.account-card {
  cursor: pointer;
  transition:
    box-shadow 0.15s ease,
    transform 0.15s ease;
}

.account-card:hover {
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08) !important;
  transform: translateY(-1px);
}

.account-card__footer {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 7px 16px;
  font-size: 12px;
  font-weight: 600;
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.account-card__footer--success {
  color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.05);
}
.account-card__footer--warning {
  color: rgb(var(--v-theme-warning));
  background: rgba(var(--v-theme-warning), 0.05);
}
.account-card__footer--error {
  color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.05);
}
.account-card__footer--secondary {
  color: rgb(var(--v-theme-secondary));
  background: rgba(var(--v-theme-secondary), 0.05);
}

@media (max-width: 600px) {
  .account-grid {
    grid-template-columns: 1fr;
  }
}
</style>
