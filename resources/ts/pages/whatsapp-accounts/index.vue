<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

interface WhatsappAccount {
  id: number;
  phone_number: string;
  display_phone_number: string | null;
  verified_name: string | null;
  quality_rating: "GREEN" | "YELLOW" | "RED" | "UNKNOWN";
  whatsapp_business_manager_messaging_limit: string | null;
  onboarding_status: "pending_registration" | "pending_payment" | "active";
  mode: "managed_bot" | "connector" | null;
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
const signupStep = ref<"idle" | "signing_up" | "choosing_mode">("idle");
const pendingAccount = ref<WhatsappAccount | null>(null);
const selectedMode = ref<"managed_bot" | "connector" | null>(null);
const webhookUrl = ref("");
const revealedKey = ref<string | null>(null);

// Tracks whether the FB JS SDK has finished loading + FB.init() has run.
// The Connect button is disabled until this is true so we never trigger a
// login attempt before window.FB actually exists.
const sdkReady = ref(false);

const qualityLabel: Record<string, string> = {
  GREEN: "Good",
  YELLOW: "Fair",
  RED: "At risk",
  UNKNOWN: "Unrated",
};

const qualityColor: Record<string, string> = {
  GREEN: "#3ecf8e",
  YELLOW: "#f5b942",
  RED: "#f56565",
  UNKNOWN: "#8a93a6",
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

// ── Grouping — this is what replaces the horizontal kanban columns with
// vertically-stacked sections. Each account lands in exactly one group. ──

type GroupKey = "registering" | "payment" | "active" | "inactive";

const groupOf = (account: WhatsappAccount): GroupKey => {
  if (!account.is_active) return "inactive";
  if (account.onboarding_status === "pending_registration")
    return "registering";
  if (account.onboarding_status === "pending_payment") return "payment";
  return "active";
};

const sections: {
  key: GroupKey;
  title: string;
  hint: string;
  icon: string;
  accent: string;
  bg: string;
}[] = [
  {
    key: "registering",
    title: "Registering",
    hint: "Meta is finishing verification",
    icon: "$progressClock",
    accent: "#8a7bf0",
    bg: "rgba(138, 123, 240, 0.08)",
  },
  {
    key: "payment",
    title: "Payment needed",
    hint: "Add billing to unlock sending",
    icon: "$creditCardOutline",
    accent: "#f5b942",
    bg: "rgba(245, 185, 66, 0.08)",
  },
  {
    key: "active",
    title: "Active",
    hint: "Sending and receiving normally",
    icon: "$checkCircle",
    accent: "#3ecf8e",
    bg: "rgba(62, 207, 142, 0.08)",
  },
  {
    key: "inactive",
    title: "Disconnected",
    hint: "Not currently in use",
    icon: "$linkOff",
    accent: "#8a93a6",
    bg: "rgba(138, 147, 166, 0.08)",
  },
];

const grouped = computed(() => {
  const map: Record<GroupKey, WhatsappAccount[]> = {
    registering: [],
    payment: [],
    active: [],
    inactive: [],
  };
  for (const account of accounts.value) {
    map[groupOf(account)].push(account);
  }
  return map;
});

// ── Embedded Signup ──────────────────────────────────────────────────────

let sessionPayload: {
  waba_id?: string;
  phone_number_id?: string;
  business_id?: string;
} = {};

let sdkLoadPromise: Promise<void> | null = null;

// Kicks off loading + initializing the FB JS SDK. This is called once on
// mount (not on click) so that by the time the user actually presses
// "Connect Account", window.FB is already available and FB.login() can be
// invoked synchronously inside the click handler. If FB.login() is called
// after an `await` (e.g. awaiting the script load), browsers can lose track
// of the "this came from a real user click" context and silently block the
// popup — no error, it just does nothing. Preloading avoids that entirely.
const loadSdk = () => {
  if (sdkLoadPromise) return sdkLoadPromise;

  sdkLoadPromise = new Promise<void>((resolve, reject) => {
    if (!APP_ID || !CONFIG_ID) {
      reject(
        new Error(
          "Missing VITE_META_APP_ID or VITE_META_ES_CONFIG_ID — check your .env and restart the dev server.",
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
};

const registerMessageListener = () => {
  window.addEventListener("message", (event) => {
    if (!event.origin.endsWith("facebook.com")) return;
    try {
      const data = JSON.parse(event.data);
      if (data.type === "WA_EMBEDDED_SIGNUP" && data.event === "FINISH") {
        sessionPayload = {
          waba_id: data.data.waba_id,
          phone_number_id: data.data.phone_number_id,
          business_id: data.data.business_id,
        };
      }
    } catch {
      /* not a signup message event — ignore */
    }
  });
};

// Handles the FB.login response. Kept separate from the callback passed to
// FB.login because that callback must be a plain (non-async) function — the
// FB SDK does a runtime check on the callback and rejects async functions
// with "Expression is of type asyncfunction, not function". This function
// does all the actual (async) work; launchSignup below just wraps it in a
// plain synchronous function before handing it to FB.login.
const handleSignupResponse = async (response: any) => {
  if (!response.authResponse?.code) {
    signupStep.value = "idle";
    return;
  }

  if (!sessionPayload.waba_id || !sessionPayload.phone_number_id) {
    signupStep.value = "idle";
    Swal.fire({
      title: "Couldn't complete signup",
      text: "We didn't receive your WhatsApp account details. Please try again.",
      icon: "error",
    });
    return;
  }

  try {
    const { data } = await axios.post(
      "/tenant/whatsapp-accounts/embedded-signup/callback",
      {
        code: response.authResponse.code,
        ...sessionPayload,
      },
    );
    pendingAccount.value = data.account;
    signupStep.value = "choosing_mode";
  } catch (e: any) {
    signupStep.value = "idle";
    Swal.fire({
      title: "Signup failed",
      text: e.response?.data?.message ?? "Something went wrong.",
      icon: "error",
    });
  }
};

// IMPORTANT: this is intentionally NOT async, and must not await anything
// before calling window.FB.login(). It has to run synchronously inside the
// click event so the browser still recognizes it as a direct response to
// user input and lets the popup open.
const launchSignup = () => {
  if (!sdkReady.value || !window.FB) {
    Swal.fire({
      title: "Still loading",
      text: "The Facebook SDK hasn't finished loading yet. Please try again in a moment.",
      icon: "info",
    });
    // Kick off (or retry) loading in the background for next time.
    loadSdk().catch((e: Error) => {
      Swal.fire({
        title: "Couldn't load Facebook SDK",
        text: e.message,
        icon: "error",
      });
    });
    return;
  }

  signupStep.value = "signing_up";

  window.FB.login(
    // Plain, non-async function. It fires off handleSignupResponse but does
    // not await it and is not itself declared async — see comment above.
    (response: any) => {
      handleSignupResponse(response);
    },
    {
      config_id: CONFIG_ID,
      response_type: "code",
      override_default_response_type: true,
      extras: { setup: {} },
    },
  );
};

const confirmMode = async () => {
  if (!selectedMode.value || !pendingAccount.value) return;
  if (selectedMode.value === "connector" && !webhookUrl.value) {
    Swal.fire({ title: "Webhook URL required", icon: "warning" });
    return;
  }

  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${pendingAccount.value.id}/choose-mode`,
      { mode: selectedMode.value, webhook_url: webhookUrl.value || undefined },
    );

    revealedKey.value = data.connector_api_key ?? null;
    signupStep.value = "idle";
    pendingAccount.value = null;
    selectedMode.value = null;
    webhookUrl.value = "";
    fetchAccounts();

    if (revealedKey.value) {
      Swal.fire({
        title: "Connector API key (shown once)",
        html: `<code style="word-break:break-all">${revealedKey.value}</code><p class="mt-3 text-caption">Save this now — it won't be shown again.</p>`,
        icon: "success",
      });
    }
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to set mode.",
      icon: "error",
    });
  }
};

// ── Standard account actions (used from the card's quick-action menu) ────

const openAccount = (account: WhatsappAccount) => {
  router.push({ name: "whatsapp-account-detail", params: { id: account.id } });
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
    text: `Disconnect ${account.display_phone_number ?? account.phone_number}? Bots using it will stop working. This does not remove the number from Meta.`,
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

const limitLabel = (limit: string | null) =>
  limit ? limit.replace("TIER_", "").replace("_", " ") : "—";

onMounted(() => {
  registerMessageListener();
  fetchAccounts();
  // Warm up the FB SDK immediately so it's ready before the user clicks
  // "Connect Account". Errors here are surfaced only when the user actually
  // tries to connect (see launchSignup), so a slow/failed load on mount
  // doesn't throw an unsolicited error dialog at page load.
  loadSdk().catch(() => {
    /* surfaced to the user on click instead */
  });
});
</script>

<template>
  <div class="wa-page">
    <!-- ── Inline header — replaces <PageHeader> ─────────────────────────── -->
    <div class="wa-page-header">
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
        :loading="signupStep === 'signing_up'"
        @click="launchSignup"
      >
        Connect Account
      </VBtn>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else>
      <VCard
        v-if="!accounts.length"
        variant="flat"
        border
        rounded="lg"
        class="text-center py-12"
      >
        <VAvatar size="64" color="primary" variant="tonal" class="mx-auto mb-4">
          <VIcon size="32" icon="$whatsapp" />
        </VAvatar>
        <h3 class="text-h6 mb-1">No WhatsApp accounts connected</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">
          Connect a number to start building bots.
        </p>
        <VBtn color="primary" variant="flat" @click="launchSignup">
          Connect your first account
        </VBtn>
      </VCard>

      <!-- ── Vertically-stacked status sections (replaces side-by-side kanban columns) ── -->
      <div v-else class="wa-sections">
        <section
          v-for="section in sections"
          :key="section.key"
          class="wa-section"
          :style="{
            '--wa-accent': section.accent,
            '--wa-accent-bg': section.bg,
          }"
        >
          <div class="wa-section-header">
            <div class="d-flex align-center gap-3">
              <div class="wa-section-icon">
                <VIcon :icon="section.icon" size="18" :color="section.accent" />
              </div>
              <div>
                <div class="d-flex align-center gap-2">
                  <span class="text-subtitle-1 font-weight-bold">{{
                    section.title
                  }}</span>
                  <VChip size="x-small" variant="flat" class="wa-count-chip">
                    {{ grouped[section.key].length }}
                  </VChip>
                </div>
                <span class="text-caption text-medium-emphasis">{{
                  section.hint
                }}</span>
              </div>
            </div>
            <VBtn
              v-if="section.key === 'registering'"
              icon
              size="small"
              variant="text"
              :loading="signupStep === 'signing_up'"
              @click="launchSignup"
            >
              <VIcon icon="$plus" size="20" />
            </VBtn>
          </div>

          <p
            v-if="!grouped[section.key].length"
            class="text-body-2 text-medium-emphasis wa-empty"
          >
            Nothing here right now.
          </p>

          <div v-else class="wa-card-row">
            <div
              v-for="account in grouped[section.key]"
              :key="account.id"
              class="wa-account-card"
              @click="openAccount(account)"
            >
              <div class="wa-card-deco" />

              <div class="d-flex align-center justify-space-between mb-4">
                <div class="d-flex align-center gap-2">
                  <VIcon icon="$whatsapp" size="20" color="#27a7df" />
                  <span class="text-caption font-weight-medium wa-label">
                    {{
                      account.mode === "connector"
                        ? "Connector"
                        : account.mode === "managed_bot"
                          ? "Managed Bot"
                          : "Not configured"
                    }}
                  </span>
                </div>
                <VMenu>
                  <template #activator="{ props }">
                    <VBtn
                      icon
                      size="x-small"
                      variant="text"
                      class="wa-kebab"
                      v-bind="props"
                      @click.stop
                    >
                      <VIcon icon="$dotsVertical" size="18" />
                    </VBtn>
                  </template>
                  <VList density="compact">
                    <VListItem
                      prepend-icon="$reload"
                      title="Sync"
                      @click="syncAccount(account)"
                    />
                    <VListItem
                      v-if="!account.is_active"
                      prepend-icon="$linkVariant"
                      title="Reconnect"
                      @click="reconnectAccount(account)"
                    />
                    <VListItem
                      v-else
                      prepend-icon="$linkOff"
                      title="Disconnect"
                      base-color="error"
                      @click="disconnectAccount(account)"
                    />
                  </VList>
                </VMenu>
              </div>

              <p class="text-h6 font-weight-bold text-white mb-1 wa-number">
                {{ account.display_phone_number ?? account.phone_number }}
              </p>
              <p class="text-caption wa-name mb-4">
                {{ account.verified_name ?? "Unverified" }}
              </p>

              <div class="wa-progress-track mb-4">
                <div
                  class="wa-progress-fill"
                  :style="{
                    width:
                      account.quality_rating === 'GREEN'
                        ? '100%'
                        : account.quality_rating === 'YELLOW'
                          ? '60%'
                          : account.quality_rating === 'RED'
                            ? '25%'
                            : '10%',
                    background: qualityColor[account.quality_rating],
                  }"
                />
              </div>

              <div
                class="d-flex align-center justify-space-between wa-footer mb-4"
              >
                <div>
                  <p class="text-caption wa-footer-label mb-0">Quality</p>
                  <p class="text-body-2 font-weight-medium text-white mb-0">
                    {{
                      qualityLabel[account.quality_rating] ??
                      account.quality_rating
                    }}
                  </p>
                </div>
                <div class="text-right">
                  <p class="text-caption wa-footer-label mb-0">Limit</p>
                  <p class="text-body-2 font-weight-medium text-white mb-0">
                    {{
                      limitLabel(
                        account.whatsapp_business_manager_messaging_limit,
                      )
                    }}
                  </p>
                </div>
              </div>

              <VDivider class="wa-divider mb-3" />

              <div class="d-flex align-center justify-space-between">
                <span class="text-caption wa-footer-label">
                  Synced {{ formatDate(account.last_synced_at) }}
                </span>
                <VChip
                  size="small"
                  variant="flat"
                  :color="account.is_active ? 'success' : 'error'"
                  class="wa-status-chip"
                >
                  {{ account.is_active ? "Active" : "Inactive" }}
                  <VIcon icon="$chevronRight" size="14" class="ml-1" />
                </VChip>
              </div>
            </div>
          </div>
        </section>
      </div>
    </template>

    <!-- ── Mode selection dialog — shown right after signup completes ────── -->
    <VDialog
      :model-value="signupStep === 'choosing_mode'"
      max-width="480"
      persistent
    >
      <VCard v-if="pendingAccount">
        <VCardTitle>How will you use this number?</VCardTitle>
        <VCardText>
          <VAlert type="success" variant="tonal" density="compact" class="mb-4">
            {{ pendingAccount.display_phone_number }} registered successfully.
          </VAlert>
          <VRadioGroup v-model="selectedMode">
            <VRadio label="Build a bot in this dashboard" value="managed_bot" />
            <VRadio
              label="Relay to my own chatbot via webhook"
              value="connector"
            />
          </VRadioGroup>
          <VTextField
            v-if="selectedMode === 'connector'"
            v-model="webhookUrl"
            label="Your Webhook URL"
            placeholder="https://your-system.com/webhooks/whatsapp"
            variant="outlined"
            density="comfortable"
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn color="primary" :disabled="!selectedMode" @click="confirmMode"
            >Continue</VBtn
          >
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>

<style scoped>
.wa-page-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 28px;
  gap: 16px;
  flex-wrap: wrap;
}

/* ── Sections stack top-to-bottom instead of columns sitting side-by-side ── */
.wa-sections {
  display: flex;
  flex-direction: column;
  gap: 28px;
}

.wa-section {
  border-radius: 18px;
  padding: 20px 22px 22px;
  background: var(--wa-accent-bg, rgba(0, 0, 0, 0.02));
  border: 1px solid rgba(0, 0, 0, 0.06);
}

.wa-section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.wa-section-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(255, 255, 255, 0.7);
  border: 1px solid rgba(0, 0, 0, 0.06);
}

.wa-count-chip {
  background: rgba(0, 0, 0, 0.06) !important;
  font-weight: 600;
}

.wa-empty {
  padding: 8px 2px 4px;
}

/* Cards within a section wrap horizontally; sections themselves are vertical */
.wa-card-row {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}

.wa-account-card {
  position: relative;
  border-radius: 16px;
  padding: 20px 22px;
  cursor: pointer;
  background: linear-gradient(145deg, #131c33 0%, #1c2a5c 55%, #273775 100%);
  overflow: hidden;
  transition:
    transform 0.15s ease,
    box-shadow 0.15s ease;
}
.wa-account-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 24px rgba(19, 28, 51, 0.25);
}
.wa-card-deco {
  position: absolute;
  width: 200px;
  height: 200px;
  border-radius: 50%;
  background: rgba(39, 167, 223, 0.18);
  top: -110px;
  right: -50px;
  filter: blur(8px);
}
.wa-label {
  color: rgba(255, 255, 255, 0.7);
}
.wa-kebab {
  color: rgba(255, 255, 255, 0.8) !important;
}
.wa-number {
  letter-spacing: 0.3px;
}
.wa-name {
  color: rgba(255, 255, 255, 0.65);
}
.wa-progress-track {
  height: 6px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.12);
  overflow: hidden;
}
.wa-progress-fill {
  height: 100%;
  border-radius: 999px;
  transition: width 0.3s ease;
}
.wa-footer-label {
  color: rgba(255, 255, 255, 0.5);
}
.wa-divider {
  border-color: rgba(255, 255, 255, 0.12) !important;
}
.wa-status-chip {
  font-weight: 600;
}
</style>
