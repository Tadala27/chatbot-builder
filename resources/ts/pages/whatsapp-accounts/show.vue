<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

interface WhatsappAccount {
  id: string;
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
  bots?: any[];
}

interface AccountStats {
  total_bots: number;
  active_bots: number;
  total_conversations: number;
  conversations_today: number;
  conversations_this_month: number;
  quality_rating: string;
  messaging_limit: string | null;
  onboarding_status: string;
  is_healthy: boolean;
  mode: string | null;
  currency: string | null;
  timezone_id: string | null;
  phone_status: string | null;
  name_status: string | null;
  throughput_level: string | null;
}

interface HealthIssue {
  severity: "critical" | "warning" | "info";
  message: string;
}

const route = useRoute();
const router = useRouter();
const accountId = computed(() => route.params.id as string);

const account = ref<WhatsappAccount | null>(null);
const stats = ref<AccountStats | null>(null);
const issues = ref<HealthIssue[]>([]);
const isLoading = ref(true);
const isSyncing = ref(false);
const isRotating = ref(false);
const connectorEndpoint = ref<string | null>(null);
const changeModeDialog = ref(false);
const newMode = ref<"managed_bot" | "connector" | null>(null);
const newWebhookUrl = ref("");
const isChangingMode = ref(false);

const snackbar = ref({
  show: false,
  text: "",
  color: "success" as "success" | "error",
});

function showSnackbar(text: string, color: "success" | "error" = "success") {
  snackbar.value = { show: true, text, color };
}

// ── Labels & colors ───────────────────────────────────────────────────────────

const qualityLabel: Record<string, string> = {
  GREEN: "Good",
  YELLOW: "Fair",
  RED: "At risk",
  UNKNOWN: "Unrated",
};
const qualityColor: Record<string, string> = {
  GREEN: "success",
  YELLOW: "warning",
  RED: "error",
  UNKNOWN: "default",
};

const limitLabels: Record<string, string> = {
  TIER_NOT_STARTED: "Not started",
  TIER_50: "50 / day",
  TIER_250: "250 / day",
  TIER_1K: "1K / day",
  TIER_10K: "10K / day",
  TIER_100K: "100K / day",
  TIER_UNLIMITED: "Unlimited",
};

function limitLabel(limit: string | null): string {
  return limit ? (limitLabels[limit] ?? limit) : "—";
}

const onboardingMeta: Record<string, { text: string; color: string }> = {
  pending_registration: { text: "Registering", color: "warning" },
  pending_payment: { text: "Payment needed", color: "warning" },
  active: { text: "Active", color: "success" },
};

const severityColor: Record<string, string> = {
  critical: "error",
  warning: "warning",
  info: "info",
};
const severityIcon: Record<string, string> = {
  critical: "$alertCircle",
  warning: "$alert",
  info: "$informationOutline",
};

const worstSeverity = computed<string | null>(() => {
  if (issues.value.some((i) => i.severity === "critical")) return "critical";
  if (issues.value.some((i) => i.severity === "warning")) return "warning";
  if (issues.value.length) return "info";
  return null;
});

const curlCommand = computed(() =>
  connectorEndpoint.value
    ? `curl -X POST "${connectorEndpoint.value}" \\\n  -H "X-Connector-Key: <your-key>" \\\n  -H "Content-Type: application/json" \\\n  -d '{ "to": "265997123456", "type": "text", "text": "Hello!" }'`
    : "",
);

// ── Data fetching ─────────────────────────────────────────────────────────────

async function fetchAll(): Promise<void> {
  isLoading.value = true;
  try {
    const [showRes, healthRes] = await Promise.all([
      axios.get(`/tenant/whatsapp-accounts/${accountId.value}`),
      axios.get(`/tenant/whatsapp/accounts/${accountId.value}/health`),
    ]);

    account.value = showRes.data.account;
    stats.value = showRes.data.stats;
    issues.value = healthRes.data.issues ?? [];

    if (account.value?.mode === "connector") {
      const { data } = await axios.get(
        `/tenant/whatsapp-accounts/${accountId.value}/connector-info`,
      );
      connectorEndpoint.value = data.endpoint_url ?? null;
    }
  } catch (e: any) {
    Swal.fire({
      title: "Couldn't load this account",
      text: e.response?.data?.message ?? "Something went wrong.",
      icon: "error",
    }).then(() => router.push({ name: "whatsapp-accounts" }));
  } finally {
    isLoading.value = false;
  }
}

// ── Actions ───────────────────────────────────────────────────────────────────

async function syncAccount(): Promise<void> {
  isSyncing.value = true;
  try {
    await axios.post(`/tenant/whatsapp/accounts/${accountId.value}/sync`);
    await fetchAll();
    showSnackbar("Account synced.");
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Sync failed.", "error");
  } finally {
    isSyncing.value = false;
  }
}

async function confirmModeChange(): Promise<void> {
  if (!newMode.value) return;
  if (newMode.value === "connector" && !newWebhookUrl.value.trim()) {
    showSnackbar("A webhook URL is required for connector mode.", "error");
    return;
  }
  isChangingMode.value = true;
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${accountId.value}/choose-mode`,
      { mode: newMode.value, webhook_url: newWebhookUrl.value || undefined },
    );
    changeModeDialog.value = false;
    newMode.value = null;
    newWebhookUrl.value = "";

    if (data.connector_api_key) {
      Swal.fire({
        title: "New connector API key — save this now",
        html: `<code style="word-break:break-all;font-size:13px">${data.connector_api_key}</code><p class="mt-3" style="font-size:13px;opacity:.6">It won't be shown again.</p>`,
        icon: "success",
      });
    } else {
      showSnackbar("Mode updated successfully.");
    }
    await fetchAll();
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? "Failed to change mode.",
      "error",
    );
  } finally {
    isChangingMode.value = false;
  }
}

async function reconnectAccount(): Promise<void> {
  try {
    await axios.post(`/tenant/whatsapp-accounts/${accountId.value}/reconnect`);
    await fetchAll();
    showSnackbar("Account reconnected.");
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Reconnect failed.", "error");
  }
}

async function disconnectAccount(): Promise<void> {
  const { isConfirmed } = await Swal.fire({
    title: "Disconnect this number?",
    text: `${account.value?.display_phone_number ?? account.value?.phone_number} will stop working for all bots. This does not remove it from Meta.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    confirmButtonText: "Disconnect",
  });
  if (!isConfirmed) return;
  try {
    await axios.post(`/tenant/whatsapp-accounts/${accountId.value}/disconnect`);
    await fetchAll();
    showSnackbar("Account disconnected.");
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Disconnect failed.", "error");
  }
}

async function rotateKey(): Promise<void> {
  const { isConfirmed } = await Swal.fire({
    title: "Rotate API key?",
    text: "The current key stops working immediately. Update your integration before rotating.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Rotate",
  });
  if (!isConfirmed) return;
  isRotating.value = true;
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${accountId.value}/rotate-connector-key`,
    );
    Swal.fire({
      title: "New API key — save this now",
      html: `<code style="word-break:break-all;font-size:13px">${data.connector_api_key}</code><p class="mt-3" style="font-size:13px;opacity:.6">It won't be shown again.</p>`,
      icon: "success",
    });
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Key rotation failed.", "error");
  } finally {
    isRotating.value = false;
  }
}

async function copyToClipboard(text: string | null): Promise<void> {
  if (!text) return;

  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
  } else {
    // Fallback for HTTP / non-secure contexts
    const el = document.createElement("textarea");
    el.value = text;
    el.style.position = "fixed";
    el.style.opacity = "0";
    document.body.appendChild(el);
    el.focus();
    el.select();
    document.execCommand("copy");
    document.body.removeChild(el);
  }

  showSnackbar("Copied to clipboard.");
}

// ── Formatting ────────────────────────────────────────────────────────────────

function formatDate(d: string | null): string {
  if (!d) return "Never";
  return new Date(d).toLocaleString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

onMounted(fetchAll);
</script>

<template>
  <div class="detail-page">
    <VBtn
      variant="text"
      prepend-icon="$arrowLeft"
      class="mb-5"
      @click="router.push({ name: 'whatsapp-accounts' })"
    >
      Back to accounts
    </VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-16">
      <VProgressCircular indeterminate color="primary" size="40" />
    </div>

    <template v-else-if="account && stats">
      <!-- ── Page header ──────────────────────────────────────────────────── -->
      <div
        class="d-flex align-start justify-space-between gap-4 flex-wrap mb-6"
      >
        <div>
          <div class="d-flex align-center gap-2 mb-2 flex-wrap">
            <VChip
              size="small"
              variant="tonal"
              :color="account.is_active ? 'success' : 'error'"
            >
              {{ account.is_active ? "Active" : "Inactive" }}
            </VChip>
            <VChip
              size="small"
              variant="tonal"
              :color="onboardingMeta[account.onboarding_status]?.color"
            >
              {{ onboardingMeta[account.onboarding_status]?.text }}
            </VChip>
            <VChip
              v-if="account.mode"
              size="small"
              variant="tonal"
              color="primary"
            >
              {{ account.mode === "managed_bot" ? "Managed bot" : "Connector" }}
            </VChip>
          </div>

          <div class="d-flex align-center gap-2">
            <h1 class="text-h5 font-weight-bold mb-0">
              {{ account.display_phone_number ?? account.phone_number }}
            </h1>
            <VBtn
              icon
              size="x-small"
              variant="text"
              @click="
                copyToClipboard(
                  account.display_phone_number ?? account.phone_number,
                )
              "
            >
              <VIcon icon="$contentCopy" size="15" />
            </VBtn>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0 mt-1">
            {{ account.verified_name ?? "Unverified name" }}
          </p>
        </div>

        <div class="d-flex gap-2 flex-wrap">
          <VBtn
            variant="tonal"
            prepend-icon="$reload"
            :loading="isSyncing"
            @click="syncAccount"
          >
            Sync now
          </VBtn>
          <VBtn
            variant="tonal"
            prepend-icon="$swap"
            @click="changeModeDialog = true"
          >
            Change mode
          </VBtn>
          <VBtn
            v-if="!account.is_active"
            variant="tonal"
            color="primary"
            prepend-icon="$linkVariant"
            @click="reconnectAccount"
          >
            Reconnect
          </VBtn>
          <VBtn
            v-else
            variant="tonal"
            color="error"
            prepend-icon="$linkOff"
            @click="disconnectAccount"
          >
            Disconnect
          </VBtn>
        </div>
      </div>

      <!-- Payment warning -->
      <VAlert
        v-if="account.onboarding_status === 'pending_payment'"
        type="warning"
        variant="tonal"
        rounded="lg"
        class="mb-6"
        title="Payment required"
        text="Add a payment method in WhatsApp Manager to activate sending on this number."
      />

      <!-- ── Health ───────────────────────────────────────────────────────── -->
      <VCard
        variant="outlined"
        rounded="lg"
        class="mb-6"
        :color="worstSeverity ? severityColor[worstSeverity] : undefined"
      >
        <VCardText>
          <div class="d-flex align-center gap-2 mb-3">
            <VIcon
              :icon="
                worstSeverity ? severityIcon[worstSeverity] : '$checkCircle'
              "
              :color="worstSeverity ? severityColor[worstSeverity] : 'success'"
              size="20"
            />
            <span class="text-subtitle-2 font-weight-bold">
              {{ worstSeverity ? "Needs attention" : "All systems healthy" }}
            </span>
          </div>

          <div v-if="issues.length" class="d-flex flex-column gap-2">
            <div
              v-for="(issue, i) in issues"
              :key="i"
              class="d-flex align-start gap-3"
            >
              <VChip
                :color="severityColor[issue.severity]"
                size="x-small"
                variant="tonal"
                class="mt-0-5 flex-shrink-0"
              >
                {{ issue.severity }}
              </VChip>
              <span class="text-body-2">{{ issue.message }}</span>
            </div>
          </div>
          <p v-else class="text-body-2 text-medium-emphasis mb-0">
            Quality, delivery, and connection are all in good shape.
          </p>
        </VCardText>
      </VCard>

      <!-- ── Stats grid ───────────────────────────────────────────────────── -->
      <VRow class="mb-6">
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">Quality</p>
            <VChip
              size="small"
              :color="qualityColor[account.quality_rating]"
              variant="tonal"
            >
              {{
                qualityLabel[account.quality_rating] ?? account.quality_rating
              }}
            </VChip>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">
              Messaging limit
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ limitLabel(stats.messaging_limit) }}
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">Active bots</p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.active_bots }}
              <span
                class="text-body-2 text-medium-emphasis font-weight-regular"
              >
                / {{ stats.total_bots }} total
              </span>
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">
              Conversations today
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.conversations_today }}
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">This month</p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.conversations_this_month }}
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">
              Total conversations
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.total_conversations }}
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">Currency</p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.currency ?? "—" }}
            </p>
          </VCard>
        </VCol>
        <VCol cols="6" sm="4" md="3">
          <VCard variant="outlined" rounded="lg" class="pa-4 stat-card">
            <p class="text-caption text-medium-emphasis mb-1">Last synced</p>
            <p class="text-body-2 font-weight-medium mb-0">
              {{ formatDate(account.last_synced_at) }}
            </p>
          </VCard>
        </VCol>
      </VRow>

      <!-- ── Connector setup ──────────────────────────────────────────────── -->
      <VCard
        v-if="account.mode === 'connector' && connectorEndpoint"
        variant="outlined"
        rounded="lg"
        class="mb-6"
      >
        <VCardTitle class="text-subtitle-1 font-weight-bold pt-4 px-4 pb-0">
          Connector setup
        </VCardTitle>
        <VCardSubtitle class="px-4 pb-3">
          POST to this endpoint with your API key in the
          <code>X-Connector-Key</code> header.
        </VCardSubtitle>
        <VDivider />
        <VCardText>
          <p
            class="text-caption text-medium-emphasis text-uppercase mb-2"
            style="letter-spacing: 0.05em"
          >
            Endpoint URL
          </p>
          <div class="d-flex align-center gap-2 mb-4">
            <VTextField
              :model-value="connectorEndpoint"
              readonly
              variant="outlined"
              density="compact"
              hide-details
              class="flex-grow-1"
            />
            <VBtn
              icon
              variant="tonal"
              size="small"
              @click="copyToClipboard(connectorEndpoint)"
            >
              <VIcon icon="$contentCopy" size="16" />
            </VBtn>
          </div>

          <p
            class="text-caption text-medium-emphasis text-uppercase mb-2"
            style="letter-spacing: 0.05em"
          >
            cURL example
          </p>
          <div class="code-block mb-4">
            <div class="d-flex align-center justify-space-between mb-2">
              <VBtn
                size="x-small"
                variant="text"
                prepend-icon="$contentCopy"
                @click="copyToClipboard(curlCommand)"
              >
                Copy
              </VBtn>
            </div>
            <pre class="code-text">{{ curlCommand }}</pre>
          </div>

          <p class="text-body-2 text-medium-emphasis mb-4">
            Incoming messages are forwarded to
            <code class="text-body-2">{{ account.webhook_url }}</code
            >.
          </p>

          <VBtn
            variant="tonal"
            color="warning"
            size="small"
            prepend-icon="$reload"
            :loading="isRotating"
            @click="rotateKey"
          >
            Rotate API key
          </VBtn>
        </VCardText>
      </VCard>

      <!-- ── Bots on this number ──────────────────────────────────────────── -->
      <VCard
        v-if="account.mode === 'managed_bot'"
        variant="outlined"
        rounded="lg"
      >
        <VCardTitle class="text-subtitle-1 font-weight-bold">
          Bots on this number
        </VCardTitle>
        <VDivider />
        <VList v-if="account.bots?.length" density="comfortable">
          <VListItem
            v-for="bot in account.bots"
            :key="bot.id"
            :title="bot.name"
            :subtitle="bot.description ?? undefined"
          >
            <template #prepend>
              <VAvatar size="34" color="primary" variant="tonal" rounded="lg">
                <VIcon icon="$robot" size="18" />
              </VAvatar>
            </template>
            <template #append>
              <VChip
                size="x-small"
                :color="bot.is_active ? 'success' : 'default'"
                variant="tonal"
              >
                {{ bot.is_active ? "Active" : "Inactive" }}
              </VChip>
            </template>
          </VListItem>
        </VList>
        <div
          v-else
          class="d-flex flex-column align-center text-center pa-10 gap-3"
        >
          <VIcon icon="$robot" size="36" color="medium-emphasis" />
          <p class="text-body-2 text-medium-emphasis mb-0">
            No bots on this number yet.
          </p>
        </div>
      </VCard>
    </template>

    <VDialog v-model="changeModeDialog" max-width="440" persistent>
      <VCard rounded="lg" class="pa-6">
        <h3 class="text-h6 font-weight-bold mb-1">Change account mode</h3>
        <p class="text-body-2 text-medium-emphasis mb-4">
          Switching from connector to managed bot (or back) will affect active
          integrations.
        </p>

        <div class="d-flex flex-column gap-3 mb-4">
          <VCard
            variant="outlined"
            :color="newMode === 'managed_bot' ? 'primary' : undefined"
            class="pa-3 cursor-pointer"
            @click="
              newMode = 'managed_bot';
              newWebhookUrl = '';
            "
          >
            <p class="text-body-2 font-weight-semibold mb-0">
              Build a bot here
            </p>
            <p class="text-caption text-medium-emphasis mb-0">
              Use the bot builder in this dashboard.
            </p>
          </VCard>
          <VCard
            variant="outlined"
            :color="newMode === 'connector' ? 'primary' : undefined"
            class="pa-3 cursor-pointer"
            @click="newMode = 'connector'"
          >
            <p class="text-body-2 font-weight-semibold mb-0">
              Connect my own system
            </p>
            <p class="text-caption text-medium-emphasis mb-0">
              Forward messages via webhook.
            </p>
          </VCard>
        </div>

        <VTextField
          v-if="newMode === 'connector'"
          v-model="newWebhookUrl"
          label="Webhook URL"
          variant="outlined"
          density="comfortable"
          class="mb-4"
        />

        <div class="d-flex justify-end gap-2">
          <VBtn
            variant="text"
            @click="
              changeModeDialog = false;
              newMode = null;
            "
            >Cancel</VBtn
          >
          <VBtn
            color="primary"
            variant="flat"
            :disabled="!newMode"
            :loading="isChangingMode"
            @click="confirmModeChange"
          >
            Confirm
          </VBtn>
        </div>
      </VCard>
    </VDialog>
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
          size="small"
          variant="text"
          icon="$close"
          @click="snackbar.show = false"
        />
      </template>
    </VSnackbar>
  </div>
</template>

<style scoped>
.stat-card {
  height: 100%;
  transition: box-shadow 0.15s ease;
}

.stat-card:hover {
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.07) !important;
}

.mt-0-5 {
  margin-top: 2px;
}

.code-block {
  border-radius: 10px;
  padding: 14px 16px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.code-text {
  white-space: pre-wrap;
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono",
    monospace;
  font-size: 12.5px;
  line-height: 1.6;
  color: rgb(var(--v-theme-on-surface));
}

.gap-2 {
  gap: 8px;
}
</style>
