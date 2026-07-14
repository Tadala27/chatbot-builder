<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";
import Hero from "./hero.vue";
import Stat from "./stat.vue";
import EarningsDonut from "./earnings-donut.vue";

const userStore = useUserStore();

// ── Types ─────────────────────────────────────────────────────────────────────
// Kept in lockstep with App\Http\Controllers\Api\DashboardController::index()

interface DashboardData {
  stats: {
    bots: {
      total: number;
      active: number;
      published: number;
      draft: number;
    };
    whatsapp_accounts: number;
    conversations: {
      this_month: number;
      prev_month: number;
      change_pct: number | null;
      active: number;
      completed: number;
      handed_off: number;
    };
    messages: {
      this_month: number;
      prev_month: number;
      change_pct: number | null;
      inbound: number;
      outbound: number;
    };
    users: { total: number; active: number };
  };
  charts: {
    conversations_by_day: { date: string; total: number }[];
    messages_by_day: { date: string; inbound: number; outbound: number }[];
    conversation_status: Record<string, number>;
  };
  top_bots: {
    id: number;
    name: string;
    conversation_count: number;
  }[];
  recent_conversations: {
    id: number;
    bot_id: number;
    bot_version_id: number | null;
    whatsapp_user_name: string | null;
    whatsapp_user_phone: string;
    status: string;
    message_count: number;
    started_at: string;
    last_message_at: string;
    bot?: { id: number; name: string };
  }[];
}

const data = ref<DashboardData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
  try {
    const { data: res } = await axios.get("/tenant/dashboard");
    data.value = res;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? "Failed to load dashboard.";
  } finally {
    loading.value = false;
  }
});

// ── Helpers ───────────────────────────────────────────────────────────────────

const tenant = computed(() => userStore.currentTenant);

const greeting = computed(() => {
  const hour = new Date().getHours();
  const time = hour < 12 ? "morning" : hour < 18 ? "afternoon" : "evening";
  const name = userStore.displayName?.split(" ")[0];
  return `Good ${time}${name ? `, ${name}` : ""}!`;
});

const statusColor: Record<string, string> = {
  active: "success",
  completed: "primary",
  handed_off: "warning",
  abandoned: "error",
};

const fmtDate = (iso: string) =>
  new Date(iso).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    hour: "2-digit",
    minute: "2-digit",
  });

// ── Stat ring percentages (slim rings, top row) ───────────────────────────────

const conversationsPercent = computed(() => {
  if (!data.value) return 0;
  const { this_month, prev_month } = data.value.stats.conversations;
  if (!prev_month) return this_month > 0 ? 100 : 0;
  return Math.min(100, Math.round((this_month / (prev_month * 1.5)) * 100));
});

const messagesPercent = computed(() => {
  if (!data.value) return 0;
  const { inbound, outbound } = data.value.stats.messages;
  const total = inbound + outbound;
  return total > 0
    ? Math.min(100, Math.round((outbound / total) * 100) + 30)
    : 0;
});

const botsPercent = computed(() => {
  if (!data.value || !data.value.stats.bots.total) return 0;
  return Math.round(
    (data.value.stats.bots.active / data.value.stats.bots.total) * 100,
  );
});

// ── Gross total (top-left big number, like "$855.8K Gross Sales") ────────────

const grossTotal = computed(() => {
  if (!data.value) return "0";
  return (
    data.value.stats.conversations.this_month +
    data.value.stats.messages.this_month
  ).toLocaleString();
});

// ── Main area chart (Conversations / Messages dual-line, like Sales/Cost) ────

const mainChartSeries = computed(() => {
  if (!data.value) return [];
  const conv = data.value.charts.conversations_by_day;
  const msg = data.value.charts.messages_by_day;
  return [
    { name: "Conversations", data: conv.map((d) => d.total) },
    { name: "Messages", data: msg.map((d) => d.inbound + d.outbound) },
  ];
});

const mainChartCategories = computed(
  () =>
    data.value?.charts.conversations_by_day.map((d) =>
      new Date(d.date).toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
      }),
    ) ?? [],
);

const mainChartOptions = computed(() => ({
  chart: { type: "area", toolbar: { show: false } },
  stroke: { curve: "smooth", width: 2.5 },
  fill: {
    type: "gradient",
    gradient: { shadeIntensity: 1, opacityFrom: 0.25, opacityTo: 0.02 },
  },
  xaxis: {
    categories: mainChartCategories.value,
    labels: { style: { fontSize: "11px", colors: "#9aa3b2" } },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { labels: { style: { fontSize: "11px", colors: "#9aa3b2" } } },
  colors: ["#273775", "#27a7df"],
  legend: { show: false },
  tooltip: { x: { show: true } },
  grid: { borderColor: "#f0f2f6", strokeDashArray: 3 },
  dataLabels: { enabled: false },
}));

// ── Earnings donut (Conversation status, repurposed) ──────────────────────────

const earningsSegments = computed(() => {
  if (!data.value) return [];
  const statusEntries = Object.entries(data.value.charts.conversation_status);
  const colors = ["#273775", "#27a7df", "#f59e0b", "#ef4444"];
  return statusEntries.map(([label, value], i) => ({
    label: label.replace("_", " "),
    value,
    color: colors[i % colors.length],
  }));
});

// ── Conversions-style bar chart (Messages in/out, like the S-M-T-W bars) ─────

const conversionsChartOptions = computed(() => ({
  chart: { type: "bar", toolbar: { show: false }, stacked: true },
  plotOptions: { bar: { borderRadius: 3, columnWidth: "45%" } },
  xaxis: {
    categories:
      data.value?.charts.messages_by_day.map((d) =>
        new Date(d.date)
          .toLocaleDateString("en-GB", { weekday: "short" })
          .charAt(0),
      ) ?? [],
    labels: { style: { fontSize: "11px", colors: "#9aa3b2" } },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { show: false },
  colors: ["#273775", "#27a7df"],
  dataLabels: { enabled: false },
  grid: { show: false },
  legend: { show: false },
}));

const conversionsSeries = computed(() => {
  if (!data.value) return [];
  return [
    {
      name: "Inbound",
      data: data.value.charts.messages_by_day.map((d) => d.inbound),
    },
    {
      name: "Outbound",
      data: data.value.charts.messages_by_day.map((d) => d.outbound),
    },
  ];
});

// ── Bots table (Companies/Contacts/Order shape, trimmed to available fields) ─

const initials = (name: string, fallback = "??") =>
  (name || fallback).slice(0, 2).toUpperCase();
</script>

<template>
  <!-- ── Hero ───────────────────────────────────────────────────────── -->
  <Hero
    :greeting="greeting"
    subtitle="We're here to help your bots have better conversations, for free."
    :cta-label="
      tenant?.subscription_tier
        ? tenant.subscription_tier.charAt(0).toUpperCase() +
          tenant.subscription_tier.slice(1) +
          ' plan'
        : undefined
    "
    cta-icon="$bullhornOutline"
  />

  <div
    v-if="loading"
    class="d-flex justify-center align-center"
    style="min-height: 300px"
  >
    <VProgressCircular indeterminate color="primary" size="48" />
  </div>

  <VAlert v-else-if="error" color="error" variant="tonal" class="mb-6">{{
    error
  }}</VAlert>

  <template v-else-if="data">
    <!-- ── Slim stat row ─────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <VCol cols="12" sm="4">
        <Stat
          label="Conversations"
          :value="data.stats.conversations.this_month.toLocaleString()"
          :percent="conversationsPercent"
          direction="up"
          color="#273775"
        />
      </VCol>
      <VCol cols="12" sm="4">
        <Stat
          label="Messages"
          :value="data.stats.messages.this_month.toLocaleString()"
          :percent="messagesPercent"
          direction="down"
          color="#27a7df"
        />
      </VCol>
      <VCol cols="12" sm="4">
        <Stat
          label="Active bots"
          :value="data.stats.bots.active"
          :percent="botsPercent"
          direction="down"
          color="#7fc8e8"
        />
      </VCol>
    </VRow>

    <!-- ── Main chart ──────────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <VCol cols="12">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div
            class="d-flex align-center justify-space-between mb-1 flex-wrap gap-2"
          >
            <div>
              <p class="text-h5 font-weight-bold mb-0">{{ grossTotal }}</p>
              <p class="text-caption text-medium-emphasis mb-0">
                Conversations &amp; messages — last 30 days
              </p>
            </div>
            <div class="d-flex align-center gap-4">
              <span class="d-flex align-center gap-1 text-caption">
                <span class="legend-dot" style="background: #273775" />
                Conversations
              </span>
              <span class="d-flex align-center gap-1 text-caption">
                <span class="legend-dot" style="background: #27a7df" /> Messages
              </span>
            </div>
          </div>
          <apexchart
            type="area"
            height="240"
            :options="mainChartOptions"
            :series="mainChartSeries"
          />
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Earnings donut + Conversions bar ────────────────────────────── -->
    <VRow class="mb-2">
      <VCol cols="12" md="5">
        <VCard variant="flat" border rounded="lg" class="pa-5 h-100">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Conversation status
          </p>
          <EarningsDonut
            v-if="earningsSegments.length"
            :segments="earningsSegments"
          />
          <p v-else class="text-caption text-medium-emphasis">No data yet</p>
        </VCard>
      </VCol>

      <VCol cols="12" md="7">
        <VCard variant="flat" border rounded="lg" class="pa-5 h-100">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Messages — last 7 days
          </p>
          <apexchart
            type="bar"
            height="180"
            :options="conversionsChartOptions"
            :series="conversionsSeries"
          />
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Bots table — only fields the API actually returns ──────────── -->
    <VRow class="mb-2">
      <VCol cols="12">
        <VCard variant="flat" border rounded="lg">
          <div class="px-5 pt-5 pb-3">
            <p class="text-subtitle-2 font-weight-semibold mb-0">Top Bots</p>
            <p class="text-caption text-medium-emphasis mb-0">
              {{ data.top_bots.length }} bot(s) this month
            </p>
          </div>
          <VDivider />
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>Bot</th>
                <th>Conversations this month</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bot in data.top_bots" :key="bot.id">
                <td>
                  <div class="d-flex align-center gap-2 py-1">
                    <VAvatar
                      size="32"
                      color="primary"
                      variant="tonal"
                      rounded="lg"
                    >
                      <VIcon icon="$robotOutline" size="16" />
                    </VAvatar>
                    <span class="text-body-2 font-weight-medium">{{
                      bot.name
                    }}</span>
                  </div>
                </td>
                <td class="text-body-2 text-medium-emphasis">
                  {{ bot.conversation_count }}
                </td>
              </tr>
              <tr v-if="!data.top_bots.length">
                <td
                  colspan="2"
                  class="text-center text-caption text-medium-emphasis py-6"
                >
                  No bots yet.
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Recent conversations ─────────────────────────────────────────── -->
    <VRow>
      <VCol cols="12">
        <VCard variant="flat" border rounded="lg">
          <div class="px-5 pt-5 pb-3">
            <p class="text-subtitle-2 font-weight-semibold mb-0">
              Recent conversations
            </p>
          </div>
          <VDivider />
          <VTable density="comfortable">
            <thead>
              <tr>
                <th>User</th>
                <th>Bot</th>
                <th>Status</th>
                <th>Messages</th>
                <th>Last message</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="conv in data.recent_conversations" :key="conv.id">
                <td>
                  <div class="d-flex align-center gap-2 py-1">
                    <VAvatar
                      size="26"
                      color="info"
                      variant="tonal"
                      class="text-caption"
                    >
                      {{
                        initials(
                          conv.whatsapp_user_name ?? conv.whatsapp_user_phone,
                        )
                      }}
                    </VAvatar>
                    <span class="text-body-2">
                      {{ conv.whatsapp_user_name ?? conv.whatsapp_user_phone }}
                    </span>
                  </div>
                </td>
                <td class="text-body-2 text-medium-emphasis">
                  {{ conv.bot?.name ?? "—" }}
                </td>
                <td>
                  <VChip
                    size="small"
                    :color="statusColor[conv.status] ?? 'default'"
                    variant="tonal"
                  >
                    {{ conv.status.replace("_", " ") }}
                  </VChip>
                </td>
                <td class="text-body-2 text-medium-emphasis">
                  {{ conv.message_count }}
                </td>
                <td class="text-body-2 text-medium-emphasis">
                  {{ fmtDate(conv.last_message_at) }}
                </td>
              </tr>
              <tr v-if="!data.recent_conversations.length">
                <td
                  colspan="5"
                  class="text-center text-caption text-medium-emphasis py-6"
                >
                  No conversations yet.
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </template>
</template>

<style scoped>
.legend-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
</style>
