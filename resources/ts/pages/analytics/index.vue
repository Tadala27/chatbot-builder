<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";

interface OverviewData {
  total_conversations: number;
  total_messages: number;
  avg_response_time_seconds: number | null;
  completion_rate: number | null;
  conversations_by_day: { date: string; total: number }[];
  events_by_type: Record<string, number>;
}

const data = ref<OverviewData | null>(null);
const isLoading = ref(true);
const error = ref<string | null>(null);

const fetchOverview = async () => {
  isLoading.value = true;
  try {
    const { data: res } = await axios.get("/tenant/analytics/overview");
    data.value = res.data ?? res;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? "Failed to load analytics";
  } finally {
    isLoading.value = false;
  }
};

const exportAnalytics = () => window.open("/tenant/analytics/export", "_blank");

const chartSeries = computed(() => {
  if (!data.value) return [];
  return [{ name: "Conversations", data: data.value.conversations_by_day.map((d) => d.total) }];
});
const chartCategories = computed(() =>
  data.value?.conversations_by_day.map((d) => new Date(d.date).toLocaleDateString("en-GB", { day: "2-digit", month: "short" })) ?? []
);
const chartOptions = computed(() => ({
  chart: { type: "area", toolbar: { show: false } },
  stroke: { curve: "smooth", width: 2 },
  xaxis: { categories: chartCategories.value, labels: { style: { fontSize: "11px" } } },
  colors: ["#5199ae"],
  dataLabels: { enabled: false },
  grid: { borderColor: "#f0f0f0" },
}));

const eventSeries = computed(() => (data.value ? Object.values(data.value.events_by_type) : []));
const eventLabels = computed(() => (data.value ? Object.keys(data.value.events_by_type).map((k) => k.replace(/_/g, " ")) : []));
const eventOptions = computed(() => ({
  chart: { type: "donut" },
  labels: eventLabels.value,
  legend: { position: "bottom" },
  dataLabels: { enabled: true, formatter: (v: number) => `${Math.round(v)}%` },
  plotOptions: { pie: { donut: { size: "60%" } } },
}));

const fmtSeconds = (s: number | null) => {
  if (s === null) return "—";
  if (s < 60) return `${Math.round(s)}s`;
  return `${Math.round(s / 60)}m`;
};

onMounted(fetchOverview);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Analytics</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Performance overview across all bots and flows</p>
      </div>
      <VBtn variant="outlined" prepend-icon="mdi-download" @click="exportAnalytics">Export</VBtn>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VAlert v-else-if="error" color="error" variant="tonal">{{ error }}</VAlert>

    <template v-else-if="data">
      <VRow class="mb-2">
        <VCol cols="12" sm="6" md="3">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-caption text-medium-emphasis text-uppercase">Conversations</p>
            <p class="text-h5 font-weight-bold">{{ data.total_conversations.toLocaleString() }}</p>
          </VCard>
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-caption text-medium-emphasis text-uppercase">Messages</p>
            <p class="text-h5 font-weight-bold">{{ data.total_messages.toLocaleString() }}</p>
          </VCard>
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-caption text-medium-emphasis text-uppercase">Avg Response Time</p>
            <p class="text-h5 font-weight-bold">{{ fmtSeconds(data.avg_response_time_seconds) }}</p>
          </VCard>
        </VCol>
        <VCol cols="12" sm="6" md="3">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-caption text-medium-emphasis text-uppercase">Completion Rate</p>
            <p class="text-h5 font-weight-bold">{{ data.completion_rate !== null ? `${Math.round(data.completion_rate)}%` : "—" }}</p>
          </VCard>
        </VCol>
      </VRow>

      <VRow>
        <VCol cols="12" md="8">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-subtitle-2 font-weight-semibold mb-4">Conversations over time</p>
            <apexchart type="area" height="260" :options="chartOptions" :series="chartSeries" />
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard variant="outlined" elevation="0" class="pa-4">
            <p class="text-subtitle-2 font-weight-semibold mb-4">Events breakdown</p>
            <apexchart v-if="eventSeries.length" type="donut" height="260" :options="eventOptions" :series="eventSeries" />
            <p v-else class="text-caption text-medium-emphasis text-center py-8">No event data yet.</p>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>
