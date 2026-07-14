<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
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
  currency: string | null;
  timezone_id: string | null;
  account_review_status: string | null;
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

const connectorInfo = ref<{ endpoint_url: string } | null>(null);
const isRotating = ref(false);

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
  UNKNOWN: "grey",
};

const onboardingLabel: Record<string, { text: string; color: string }> = {
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
  info: "$alert",
};

const worstSeverity = computed(() => {
  if (issues.value.some((i) => i.severity === "critical")) return "critical";
  if (issues.value.some((i) => i.severity === "warning")) return "warning";
  if (issues.value.length) return "info";
  return null;
});

const curlCommand = computed(() =>
  connectorInfo.value
    ? `curl -X POST "${connectorInfo.value.endpoint_url}" \\\n  -H "X-Connector-Key: <your key>" \\\n  -H "Content-Type: application/json" \\\n  -d '{ "to": "265997123456", "type": "text", "text": "Hello!" }'`
    : "",
);

const fetchAll = async () => {
  isLoading.value = true;
  try {
    const [{ data: showData }, { data: healthData }] = await Promise.all([
      axios.get(`/tenant/whatsapp-accounts/${accountId.value}`),
      axios.get(`/tenant/whatsapp-accounts/${accountId.value}/health`),
    ]);

    account.value = showData.account;
    stats.value = showData.stats;
    issues.value = healthData.issues ?? [];

    if (account.value?.mode === "connector") {
      const { data } = await axios.get(
        `/tenant/whatsapp-accounts/${accountId.value}/connector-info`,
      );
      connectorInfo.value = { endpoint_url: data.endpoint_url };
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
};

const syncAccount = async () => {
  if (!account.value) return;
  isSyncing.value = true;
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.value.id}/sync`);
    await fetchAll();
  } catch (e: any) {
    Swal.fire({
      title: "Sync failed",
      text: e.response?.data?.message ?? "Something went wrong.",
      icon: "error",
    });
  } finally {
    isSyncing.value = false;
  }
};

const reconnectAccount = async () => {
  if (!account.value) return;
  try {
    await axios.post(`/tenant/whatsapp-accounts/${account.value.id}/reconnect`);
    await fetchAll();
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to reconnect.",
      icon: "error",
    });
  }
};

const disconnectAccount = async () => {
  if (!account.value) return;
  const { isConfirmed } = await Swal.fire({
    title: "Disconnect Account",
    text: `Disconnect ${account.value.display_phone_number ?? account.value.phone_number}? Bots using it will stop working. This does not remove the number from Meta.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
  });
  if (!isConfirmed) return;
  try {
    await axios.post(
      `/tenant/whatsapp-accounts/${account.value.id}/disconnect`,
    );
    await fetchAll();
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to disconnect.",
      icon: "error",
    });
  }
};

const rotateKey = async () => {
  if (!account.value) return;
  const { isConfirmed } = await Swal.fire({
    title: "Rotate API Key",
    text: "The current key stops working immediately.",
    icon: "warning",
    showCancelButton: true,
  });
  if (!isConfirmed) return;

  isRotating.value = true;
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${account.value.id}/rotate-connector-key`,
    );
    Swal.fire({
      title: "New API key (shown once)",
      html: `<code style="word-break:break-all">${data.connector_api_key}</code><p class="mt-3 text-caption">Save this now — it won't be shown again.</p>`,
      icon: "success",
    });
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to rotate key.",
      icon: "error",
    });
  } finally {
    isRotating.value = false;
  }
};

const copy = (text: string | null) => {
  if (!text) return;
  navigator.clipboard.writeText(text);
  Swal.fire({
    title: "Copied",
    icon: "success",
    timer: 1000,
    showConfirmButton: false,
  });
};

const formatDate = (d: string | null) =>
  d
    ? new Date(d).toLocaleString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "Never";

const limitLabel = (limit: string | null) =>
  limit ? limit.replace("TIER_", "").replace("_", " ") : "—";

onMounted(fetchAll);
</script>

<template>
  <div class="wa-detail-page">
    <VBtn
      variant="text"
      prepend-icon="$arrowLeft"
      class="mb-4 wa-back-btn"
      @click="router.push({ name: 'whatsapp-accounts' })"
    >
      Back to accounts
    </VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else-if="account && stats">
      <!-- ── Hero ─────────────────────────────────────────────────────────── -->
      <div class="wa-hero mb-6 wa-fade-in">
        <div class="wa-hero-deco" />
        <div class="wa-hero-deco wa-hero-deco--soft" />
        <div class="d-flex flex-wrap align-center justify-space-between gap-4">
          <div>
            <div class="d-flex align-center flex-wrap gap-2 mb-3">
              <span class="wa-mode-badge">
                <VIcon icon="$whatsapp" size="16" color="#27a7df" />
                {{
                  account.mode === "connector"
                    ? "Connector"
                    : account.mode === "managed_bot"
                      ? "Managed Bot"
                      : "Not configured"
                }}
              </span>
              <VChip
                :color="onboardingLabel[account.onboarding_status].color"
                size="x-small"
                variant="flat"
              >
                {{ onboardingLabel[account.onboarding_status].text }}
              </VChip>
              <VChip
                :color="account.is_active ? 'success' : 'error'"
                size="x-small"
                variant="flat"
              >
                {{ account.is_active ? "Active" : "Inactive" }}
              </VChip>
            </div>

            <div class="d-flex align-center gap-2 mb-1">
              <h1 class="text-h4 font-weight-bold text-white wa-number mb-0">
                {{ account.display_phone_number ?? account.phone_number }}
              </h1>
              <VBtn
                icon
                variant="text"
                size="small"
                class="wa-copy-btn"
                @click="
                  copy(account.display_phone_number ?? account.phone_number)
                "
              >
                <VIcon size="16">$contentCopy</VIcon>
              </VBtn>
            </div>
            <p class="text-body-2 wa-name mb-0">
              {{ account.verified_name ?? "Unverified" }}
            </p>
          </div>

          <div class="d-flex flex-wrap gap-2">
            <VBtn
              variant="flat"
              color="white"
              class="wa-hero-btn"
              prepend-icon="$reload"
              :loading="isSyncing"
              @click="syncAccount"
            >
              Sync now
            </VBtn>
            <VBtn
              v-if="!account.is_active"
              variant="tonal"
              color="white"
              class="wa-hero-btn-ghost"
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
      </div>

      <VAlert
        v-if="account.onboarding_status === 'pending_payment'"
        type="warning"
        variant="tonal"
        rounded="lg"
        class="mb-6 wa-fade-in wa-fade-in--1"
      >
        Add a payment method in WhatsApp Manager to activate sending on this
        number.
      </VAlert>

      <!-- ── Health ───────────────────────────────────────────────────────── -->
      <div
        class="wa-health-card mb-6 wa-fade-in wa-fade-in--1"
        :class="
          worstSeverity
            ? `wa-health-card--${worstSeverity}`
            : 'wa-health-card--ok'
        "
      >
        <div class="d-flex align-center gap-2 mb-1">
          <VIcon
            :icon="worstSeverity ? severityIcon[worstSeverity] : '$whatsapp'"
            :color="worstSeverity ? severityColor[worstSeverity] : 'success'"
            size="20"
          />
          <span class="text-subtitle-1 font-weight-medium">
            {{ worstSeverity ? "Needs attention" : "Healthy" }}
          </span>
        </div>

        <template v-if="issues.length">
          <div v-for="(issue, i) in issues" :key="i" class="wa-health-row">
            <span
              class="wa-health-dot"
              :style="{
                background: `rgb(var(--v-theme-${severityColor[issue.severity]}))`,
              }"
            />
            <span class="text-body-2">{{ issue.message }}</span>
          </div>
        </template>
        <p v-else class="text-body-2 text-medium-emphasis mb-0 mt-1">
          No issues found. Quality, delivery, and connection are all in good
          shape.
        </p>
      </div>

      <!-- ── Stats grid ───────────────────────────────────────────────────── -->
      <VRow class="mb-6 wa-fade-in wa-fade-in--2">
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon
              icon="mdi-shield-check-outline"
              size="18"
              class="wa-stat-icon"
            />
            <p class="text-caption text-medium-emphasis mb-1">Quality</p>
            <VChip
              size="small"
              :color="qualityColor[account.quality_rating]"
              variant="flat"
            >
              {{
                qualityLabel[account.quality_rating] ?? account.quality_rating
              }}
            </VChip>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon icon="mdi-speedometer" size="18" class="wa-stat-icon" />
            <p class="text-caption text-medium-emphasis mb-1">
              Messaging limit
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ limitLabel(stats.messaging_limit) }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon icon="mdi-robot-outline" size="18" class="wa-stat-icon" />
            <p class="text-caption text-medium-emphasis mb-1">Bots</p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.active_bots }} / {{ stats.total_bots }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon icon="mdi-chat-outline" size="18" class="wa-stat-icon" />
            <p class="text-caption text-medium-emphasis mb-1">
              Conversations today
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.conversations_today }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon
              icon="mdi-calendar-month-outline"
              size="18"
              class="wa-stat-icon"
            />
            <p class="text-caption text-medium-emphasis mb-1">
              Conversations this month
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.conversations_this_month }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon
              icon="mdi-chat-processing-outline"
              size="18"
              class="wa-stat-icon"
            />
            <p class="text-caption text-medium-emphasis mb-1">
              Total conversations
            </p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ stats.total_conversations }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon icon="mdi-cash-multiple" size="18" class="wa-stat-icon" />
            <p class="text-caption text-medium-emphasis mb-1">Currency</p>
            <p class="text-h6 font-weight-bold mb-0">
              {{ account.currency ?? "—" }}
            </p>
          </div>
        </VCol>
        <VCol cols="6" sm="3">
          <div class="wa-stat-card">
            <VIcon icon="mdi-clock-outline" size="18" class="wa-stat-icon" />
            <p class="text-caption text-medium-emphasis mb-1">Last synced</p>
            <p class="text-body-2 font-weight-medium mb-0">
              {{ formatDate(account.last_synced_at) }}
            </p>
          </div>
        </VCol>
      </VRow>

      <!-- ── Connector setup ──────────────────────────────────────────────── -->
      <div
        v-if="account.mode === 'connector' && connectorInfo"
        class="wa-connector-card mb-6 wa-fade-in wa-fade-in--3"
      >
        <p class="text-subtitle-1 font-weight-medium text-white mb-1">
          Connector setup
        </p>
        <p class="text-body-2 wa-connector-sub mb-4">
          Send a <code>POST</code> request to this URL, with your API key in the
          <code>X-Connector-Key</code> header.
        </p>

        <div class="d-flex align-center gap-2 mb-4">
          <VTextField
            :model-value="connectorInfo.endpoint_url"
            readonly
            variant="outlined"
            density="compact"
            hide-details
            class="wa-connector-field"
          />
          <VBtn
            icon
            variant="tonal"
            color="white"
            @click="copy(connectorInfo.endpoint_url)"
          >
            <VIcon size="18">$contentCopy</VIcon>
          </VBtn>
        </div>

        <div class="wa-code-block mb-2">
          <div class="d-flex justify-space-between align-center mb-2">
            <span class="text-caption wa-code-label">cURL example</span>
            <VBtn
              size="small"
              variant="text"
              class="wa-copy-code-btn"
              prepend-icon="$contentCopy"
              @click="copy(curlCommand)"
            >
              Copy
            </VBtn>
          </div>
          <pre class="wa-code-text">{{ curlCommand }}</pre>
        </div>

        <p class="text-caption wa-connector-sub mb-4">
          Incoming messages on this number are forwarded to
          <code>{{ account.webhook_url }}</code> as Meta's raw JSON payload.
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
      </div>

      <!-- ── Bots on this number ──────────────────────────────────────────── -->
      <VCard
        v-if="account.mode === 'managed_bot'"
        variant="flat"
        border
        rounded="lg"
        class="wa-fade-in wa-fade-in--3"
      >
        <VCardTitle class="text-subtitle-1">Bots on this number</VCardTitle>
        <VDivider />
        <VList v-if="account.bots?.length" density="comfortable">
          <VListItem
            v-for="bot in account.bots"
            :key="bot.id"
            :title="bot.name"
          >
            <template #prepend>
              <VAvatar size="32" color="primary" variant="tonal">
                <VIcon icon="mdi-robot-outline" size="18" />
              </VAvatar>
            </template>
            <template #append>
              <VChip
                size="x-small"
                :color="bot.is_active ? 'success' : 'grey'"
                variant="flat"
              >
                {{ bot.is_active ? "Active" : "Inactive" }}
              </VChip>
            </template>
          </VListItem>
        </VList>
        <div v-else class="wa-empty-state">
          <VIcon icon="mdi-robot-confused-outline" size="32" color="grey" />
          <p class="text-body-2 text-medium-emphasis mt-2 mb-0">
            No bots built here yet. Start one to put this number to work.
          </p>
        </div>
      </VCard>
    </template>
  </div>
</template>

<style scoped>
.wa-detail-page {
  max-width: 1100px;
  margin: 0 auto;
}

.wa-back-btn {
  color: rgba(var(--v-theme-on-surface), 0.7);
}

/* ── Hero ──────────────────────────────────────────────────────────────── */
.wa-hero {
  position: relative;
  border-radius: 20px;
  padding: 32px 32px 30px;
  background: linear-gradient(145deg, #131c33 0%, #1c2a5c 55%, #273775 100%);
  overflow: hidden;
}
.wa-hero-deco {
  position: absolute;
  width: 280px;
  height: 280px;
  border-radius: 50%;
  background: rgba(39, 167, 223, 0.18);
  top: -140px;
  right: -60px;
  filter: blur(10px);
  pointer-events: none;
}
.wa-hero-deco--soft {
  width: 180px;
  height: 180px;
  top: auto;
  bottom: -100px;
  left: -60px;
  right: auto;
  background: rgba(39, 167, 223, 0.1);
}
.wa-mode-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px 3px 8px;
  border-radius: 999px;
  background: rgba(39, 167, 223, 0.14);
  border: 1px solid rgba(39, 167, 223, 0.3);
  color: rgba(255, 255, 255, 0.85);
  font-size: 12px;
  font-weight: 500;
}
.wa-hero-btn {
  color: #131c33 !important;
  font-weight: 600;
}
.wa-hero-btn-ghost {
  border: 1px solid rgba(255, 255, 255, 0.25);
}
.wa-number {
  letter-spacing: 0.3px;
}
.wa-name {
  color: rgba(255, 255, 255, 0.65);
}
.wa-copy-btn {
  color: rgba(255, 255, 255, 0.55) !important;
  transition: color 0.15s ease;
}
.wa-copy-btn:hover {
  color: #27a7df !important;
}

/* ── Health ────────────────────────────────────────────────────────────── */
.wa-health-card {
  border-radius: 14px;
  padding: 18px 20px;
  border: 1px solid rgba(var(--v-border-color), 0.14);
  border-left: 3px solid transparent;
}
.wa-health-card--ok {
  border-left-color: rgb(var(--v-theme-success));
  background: rgba(var(--v-theme-success), 0.06);
}
.wa-health-card--info {
  border-left-color: rgb(var(--v-theme-info));
}
.wa-health-card--warning {
  border-left-color: rgb(var(--v-theme-warning));
  background: rgba(var(--v-theme-warning), 0.05);
}
.wa-health-card--critical {
  border-left-color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.05);
}
.wa-health-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 0 6px 28px;
}
.wa-health-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

/* ── Stat cards ────────────────────────────────────────────────────────── */
.wa-stat-card {
  border-radius: 14px;
  padding: 16px;
  height: 100%;
  border: 1px solid rgba(var(--v-border-color), 0.14);
  transition:
    border-color 0.15s ease,
    transform 0.15s ease;
}
.wa-stat-card:hover {
  border-color: rgba(39, 167, 223, 0.35);
  transform: translateY(-1px);
}
.wa-stat-icon {
  color: rgba(39, 167, 223, 0.8);
  margin-bottom: 8px;
}

/* ── Connector card ────────────────────────────────────────────────────── */
.wa-connector-card {
  border-radius: 16px;
  padding: 24px 26px;
  background: linear-gradient(145deg, #131c33 0%, #1c2a5c 55%, #273775 100%);
}
.wa-connector-sub {
  color: rgba(255, 255, 255, 0.6);
}
.wa-connector-field :deep(input) {
  color: #fff;
}
.wa-code-block {
  border-radius: 10px;
  padding: 14px 16px;
  background: rgba(0, 0, 0, 0.28);
  border: 1px solid rgba(255, 255, 255, 0.08);
}
.wa-code-label {
  color: rgba(255, 255, 255, 0.45);
  text-transform: uppercase;
  letter-spacing: 0.06em;
}
.wa-code-text {
  white-space: pre-wrap;
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono",
    monospace;
  font-size: 12.5px;
  line-height: 1.6;
  color: #7fd4f5;
}
.wa-copy-code-btn {
  color: rgba(255, 255, 255, 0.6) !important;
}

/* ── Empty state ───────────────────────────────────────────────────────── */
.wa-empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 24px;
}

/* ── Motion ────────────────────────────────────────────────────────────── */
.wa-fade-in {
  animation: wa-fade-in-up 0.35s ease both;
}
.wa-fade-in--1 {
  animation-delay: 0.05s;
}
.wa-fade-in--2 {
  animation-delay: 0.1s;
}
.wa-fade-in--3 {
  animation-delay: 0.15s;
}
@keyframes wa-fade-in-up {
  from {
    opacity: 0;
    transform: translateY(6px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
@media (prefers-reduced-motion: reduce) {
  .wa-fade-in,
  .wa-fade-in--1,
  .wa-fade-in--2,
  .wa-fade-in--3 {
    animation: none;
  }
}
</style>
