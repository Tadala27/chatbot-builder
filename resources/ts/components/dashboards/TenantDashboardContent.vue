<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";

const userStore = useUserStore();

// ── Types ─────────────────────────────────────────────────────────────────────

interface DashboardData {
  stats: {
    bots: { total: number; active: number };
    flows: { total: number; published: number; draft: number };
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
  top_bots: { id: number; name: string; conversation_count: number }[];
  recent_conversations: {
    id: number;
    flow_id: number;
    whatsapp_user_name: string | null;
    whatsapp_user_phone: string;
    status: string;
    message_count: number;
    started_at: string;
    last_message_at: string;
    flow?: { id: number; name: string };
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

const formatChange = (pct: number | null) => {
  if (pct === null) return { text: "—", color: "default" };
  if (pct > 0) return { text: `+${pct}%`, color: "success" };
  if (pct < 0) return { text: `${pct}%`, color: "error" };
  return { text: "0%", color: "default" };
};

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

const convSeries = computed(() => {
  if (!data.value) return [];
  return [
    {
      name: "Conversations",
      data: data.value.charts.conversations_by_day.map((d) => d.total),
    },
  ];
});

const convCategories = computed(
  () =>
    data.value?.charts.conversations_by_day.map((d) =>
      new Date(d.date).toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
      }),
    ) ?? [],
);

const msgSeries = computed(() => {
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

const msgCategories = computed(
  () =>
    data.value?.charts.messages_by_day.map((d) =>
      new Date(d.date).toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
      }),
    ) ?? [],
);

const statusPieSeries = computed(() =>
  data.value ? Object.values(data.value.charts.conversation_status) : [],
);
const statusPieLabels = computed(() =>
  data.value ? Object.keys(data.value.charts.conversation_status) : [],
);

const convChartOptions = computed(() => ({
  chart: {
    type: "area",
    toolbar: { show: false },
    sparkline: { enabled: false },
  },
  stroke: { curve: "smooth", width: 2 },
  fill: {
    type: "gradient",
    gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05 },
  },
  xaxis: {
    categories: convCategories.value,
    labels: { rotate: -45, style: { fontSize: "11px" } },
  },
  yaxis: { labels: { style: { fontSize: "11px" } } },
  colors: ["#5199ae"],
  tooltip: { x: { show: true } },
  grid: { borderColor: "#f0f0f0" },
  dataLabels: { enabled: false },
}));

const msgChartOptions = computed(() => ({
  chart: { type: "bar", toolbar: { show: false }, stacked: false },
  plotOptions: { bar: { borderRadius: 4, columnWidth: "55%" } },
  xaxis: {
    categories: msgCategories.value,
    labels: { style: { fontSize: "11px" } },
  },
  colors: ["#5199ae", "#3f7f91"],
  dataLabels: { enabled: false },
  grid: { borderColor: "#f0f0f0" },
  legend: { position: "top" },
}));

const pieChartOptions = computed(() => ({
  chart: { type: "donut" },
  labels: statusPieLabels.value,
  colors: ["#22c55e", "#5199ae", "#f59e0b", "#ef4444"],
  legend: { position: "bottom" },
  dataLabels: {
    enabled: true,
    formatter: (val: number) => `${Math.round(val)}%`,
  },
  plotOptions: { pie: { donut: { size: "60%" } } },
}));
</script>

<template>
  <!-- ── Header ─────────────────────────────────────────────────────── -->
  <div class="d-flex align-center justify-space-between mb-6 flex-wrap gap-3">
    <div>
      <h1 class="text-h5 font-weight-bold">Dashboard</h1>
      <p class="text-body-2 text-medium-emphasis mt-1">
        {{ tenant?.name ?? "Your organisation" }} ·
        {{
          new Date().toLocaleDateString("en-GB", {
            month: "long",
            year: "numeric",
          })
        }}
      </p>
    </div>
    <VChip
      v-if="tenant?.subscription_tier"
      color="primary"
      variant="tonal"
      size="small"
      class="text-capitalize"
    >
      {{ tenant.subscription_tier }}
    </VChip>
  </div>

  <!-- ── Loading / error ────────────────────────────────────────────── -->
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
    <!-- ── Stat cards row ────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <!-- Conversations -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                Conversations
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.conversations.this_month.toLocaleString() }}
              </p>
              <p class="text-caption mt-1">
                This month ·
                <span
                  :class="`text-${formatChange(data.stats.conversations.change_pct).color}`"
                >
                  {{ formatChange(data.stats.conversations.change_pct).text }}
                </span>
                vs last
              </p>
            </div>
            <VAvatar color="primary" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-message-processing-outline" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="d-flex gap-4 text-caption text-medium-emphasis">
            <span
              ><span class="text-success font-weight-medium">{{
                data.stats.conversations.active
              }}</span>
              active</span
            >
            <span
              ><span class="text-primary font-weight-medium">{{
                data.stats.conversations.completed
              }}</span>
              completed</span
            >
            <span
              ><span class="text-warning font-weight-medium">{{
                data.stats.conversations.handed_off
              }}</span>
              handed off</span
            >
          </div>
        </VCard>
      </VCol>

      <!-- Messages -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                Messages
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.messages.this_month.toLocaleString() }}
              </p>
              <p class="text-caption mt-1">
                This month ·
                <span
                  :class="`text-${formatChange(data.stats.messages.change_pct).color}`"
                >
                  {{ formatChange(data.stats.messages.change_pct).text }}
                </span>
                vs last
              </p>
            </div>
            <VAvatar color="info" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-chat-outline" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="d-flex gap-4 text-caption text-medium-emphasis">
            <span
              ><span class="font-weight-medium"
                >↓ {{ data.stats.messages.inbound.toLocaleString() }}</span
              >
              in</span
            >
            <span
              ><span class="font-weight-medium"
                >↑ {{ data.stats.messages.outbound.toLocaleString() }}</span
              >
              out</span
            >
          </div>
        </VCard>
      </VCol>

      <!-- Bots -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                Bots
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.bots.total }}
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                {{ data.stats.bots.active }} active ·
                {{ data.stats.bots.total - data.stats.bots.active }} inactive
              </p>
            </div>
            <VAvatar color="success" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-robot-outline" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="d-flex gap-4 text-caption text-medium-emphasis">
            <span
              ><span class="text-primary font-weight-medium">{{
                data.stats.flows.published
              }}</span>
              published flows</span
            >
            <span
              ><span class="font-weight-medium">{{
                data.stats.flows.draft
              }}</span>
              drafts</span
            >
          </div>
        </VCard>
      </VCol>

      <!-- WhatsApp & Users -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                WhatsApp Accounts
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.whatsapp_accounts }}
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                Connected &amp; active
              </p>
            </div>
            <VAvatar
              color="success"
              variant="tonal"
              size="44"
              rounded="lg"
              style="background: #25d366 !important; opacity: 0.85"
            >
              <VIcon icon="mdi-whatsapp" color="white" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="text-caption text-medium-emphasis">
            <span class="font-weight-medium">{{
              data.stats.users.active
            }}</span>
            / {{ data.stats.users.total }} team members active
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Charts row ──────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <!-- Conversations over 30 days -->
      <VCol cols="12" md="8">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Conversations — last 30 days
          </p>
          <apexchart
            type="area"
            height="220"
            :options="convChartOptions"
            :series="convSeries"
          />
        </VCard>
      </VCol>

      <!-- Status donut -->
      <VCol cols="12" md="4">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Conversation status
          </p>
          <apexchart
            v-if="statusPieSeries.length"
            type="donut"
            height="220"
            :options="pieChartOptions"
            :series="statusPieSeries"
          />
          <div
            v-else
            class="d-flex align-center justify-center"
            style="height: 220px"
          >
            <p class="text-caption text-medium-emphasis">No data yet</p>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Messages + Top bots -->
    <VRow class="mb-2">
      <!-- Messages by day -->
      <VCol cols="12" md="7">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Messages — last 7 days
          </p>
          <apexchart
            type="bar"
            height="220"
            :options="msgChartOptions"
            :series="msgSeries"
          />
        </VCard>
      </VCol>

      <!-- Top bots -->
      <VCol cols="12" md="5">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Top bots this month
          </p>
          <div v-if="data.top_bots.length">
            <div
              v-for="(bot, i) in data.top_bots"
              :key="bot.id"
              class="d-flex align-center gap-3 mb-3"
            >
              <VAvatar
                size="32"
                color="primary"
                variant="tonal"
                class="text-caption font-weight-bold"
              >
                {{ i + 1 }}
              </VAvatar>
              <div class="flex-grow-1 min-w-0">
                <p class="text-body-2 font-weight-medium text-truncate">
                  {{ bot.name }}
                </p>
                <VProgressLinear
                  :model-value="bot.conversation_count"
                  :max="data.top_bots[0].conversation_count || 1"
                  color="primary"
                  rounded
                  height="4"
                  class="mt-1"
                />
              </div>
              <span class="text-caption text-medium-emphasis">{{
                bot.conversation_count
              }}</span>
            </div>
          </div>
          <p v-else class="text-caption text-medium-emphasis">
            No conversations this month.
          </p>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Recent conversations table ─────────────────────────────── -->
    <VRow>
      <VCol cols="12">
        <VCard variant="flat" border rounded="lg">
          <div class="d-flex align-center justify-space-between px-5 pt-5 pb-3">
            <p class="text-subtitle-2 font-weight-semibold">
              Recent conversations
            </p>
            <RouterLink
              to="/conversations"
              class="text-caption text-primary text-decoration-none"
            >
              View all →
            </RouterLink>
          </div>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th>User</th>
                <th>Flow</th>
                <th>Status</th>
                <th class="text-right">Messages</th>
                <th class="text-right">Last activity</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="conv in data.recent_conversations" :key="conv.id">
                <td>
                  <div class="d-flex align-center gap-2 py-1">
                    <VAvatar
                      size="28"
                      color="secondary"
                      variant="tonal"
                      class="text-caption"
                    >
                      {{
                        (conv.whatsapp_user_name ??
                          conv.whatsapp_user_phone)[0].toUpperCase()
                      }}
                    </VAvatar>
                    <div>
                      <p class="text-body-2">
                        {{ conv.whatsapp_user_name ?? "—" }}
                      </p>
                      <p class="text-caption text-medium-emphasis">
                        {{ conv.whatsapp_user_phone }}
                      </p>
                    </div>
                  </div>
                </td>
                <td class="text-body-2">{{ conv.flow?.name ?? "—" }}</td>
                <td>
                  <VChip
                    :color="statusColor[conv.status] ?? 'default'"
                    size="x-small"
                    variant="tonal"
                    class="text-capitalize"
                  >
                    {{ conv.status.replace("_", " ") }}
                  </VChip>
                </td>
                <td class="text-right text-body-2">
                  {{ conv.message_count }}
                </td>
                <td class="text-right text-caption text-medium-emphasis">
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
