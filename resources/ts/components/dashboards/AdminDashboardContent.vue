<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";
import Hero from "./hero.vue";
import Stat from "./stat.vue";
import EarningsDonut from "./earnings-donut.vue";

const userStore = useUserStore();

// ── Types ─────────────────────────────────────────────────────────────────────

interface AdminDashboardData {
  stats: {
    tenants: {
      total: number;
      active: number;
      inactive: number;
      new_this_month: number;
      new_prev_month: number;
      change_pct: number | null;
      expiring_soon: number;
      expired: number;
    };
    admins: { total: number; active: number };
    subscription_tiers: Record<string, number>;
    deployment_modes: Record<string, number>;
  };
  charts: {
    tenant_growth: { month: string; total: number }[];
  };
  recent_tenants: {
    id: string;
    name: string;
    slug: string;
    subscription_tier: string;
    is_active: boolean;
    subscription_expires_at: string | null;
    created_at: string;
    contacts?: { name: string }[];
  }[];
  expiring_soon: {
    id: string;
    name: string;
    slug: string;
    subscription_tier: string;
    is_active: boolean;
    subscription_expires_at: string;
    days_left: number;
  }[];
}

const data = ref<AdminDashboardData | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);

onMounted(async () => {
  try {
    const { data: res } = await axios.get("/api/admin/dashboard");
    data.value = res;
  } catch (e: any) {
    error.value = e.response?.data?.message ?? "Failed to load dashboard.";
  } finally {
    loading.value = false;
  }
});

// ── Helpers ───────────────────────────────────────────────────────────────────

const greeting = computed(() => {
  const hour = new Date().getHours();
  const time = hour < 12 ? "morning" : hour < 18 ? "afternoon" : "evening";
  const name = userStore.displayName?.split(" ")[0];
  return `Good ${time}${name ? `, ${name}` : ""}!`;
});

const tierColor: Record<string, string> = {
  free: "default",
  starter: "info",
  professional: "primary",
  enterprise: "success",
};

const fmtMonth = (ym: string) => {
  const [y, m] = ym.split("-");
  return new Date(+y, +m - 1).toLocaleDateString("en-GB", {
    month: "short",
    year: "2-digit",
  });
};

const fmtDate = (iso: string) =>
  new Date(iso).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });

// ── Stat ring percentages ─────────────────────────────────────────────────────

const tenantsActivePercent = computed(() => {
  if (!data.value || !data.value.stats.tenants.total) return 0;
  return Math.round(
    (data.value.stats.tenants.active / data.value.stats.tenants.total) * 100,
  );
});

const growthPercent = computed(() => {
  if (!data.value) return 0;
  const { new_this_month, new_prev_month } = data.value.stats.tenants;
  if (!new_prev_month) return new_this_month > 0 ? 100 : 0;
  return Math.min(
    100,
    Math.round((new_this_month / (new_prev_month * 1.5)) * 100),
  );
});

const adminsActivePercent = computed(() => {
  if (!data.value || !data.value.stats.admins.total) return 0;
  return Math.round(
    (data.value.stats.admins.active / data.value.stats.admins.total) * 100,
  );
});

// ── Gross total ────────────────────────────────────────────────────────────────

const grossTotal = computed(() => {
  if (!data.value) return "0";
  return data.value.charts.tenant_growth
    .reduce((sum, d) => sum + d.total, 0)
    .toLocaleString();
});

// ── Main area chart — tenant growth, dual-rendered as cumulative + monthly ───

const mainChartSeries = computed(() => {
  if (!data.value) return [];
  let cumulative = 0;
  const cumulativeSeries = data.value.charts.tenant_growth.map(
    (d) => (cumulative += d.total),
  );
  return [
    {
      name: "New tenants",
      data: data.value.charts.tenant_growth.map((d) => d.total),
    },
    { name: "Cumulative", data: cumulativeSeries },
  ];
});

const mainChartCategories = computed(
  () => data.value?.charts.tenant_growth.map((d) => fmtMonth(d.month)) ?? [],
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

// ── Subscription tiers donut ──────────────────────────────────────────────────

const tierSegments = computed(() => {
  if (!data.value) return [];
  const colors = ["#9ca3af", "#27a7df", "#273775", "#22c55e"];
  return Object.entries(data.value.stats.subscription_tiers).map(
    ([label, value], i) => ({
      label: label.charAt(0).toUpperCase() + label.slice(1),
      value,
      color: colors[i % colors.length],
    }),
  );
});

// ── Deployment modes bar ──────────────────────────────────────────────────────

const deploymentOptions = computed(() => ({
  chart: { type: "bar", toolbar: { show: false } },
  plotOptions: {
    bar: { borderRadius: 3, columnWidth: "45%", distributed: true },
  },
  xaxis: {
    categories: data.value
      ? Object.keys(data.value.stats.deployment_modes).map((k) =>
          k.replace("_", " "),
        )
      : [],
    labels: { style: { fontSize: "11px", colors: "#9aa3b2" } },
    axisBorder: { show: false },
    axisTicks: { show: false },
  },
  yaxis: { show: false },
  colors: ["#273775", "#27a7df", "#7fc8e8"],
  dataLabels: { enabled: false },
  grid: { show: false },
  legend: { show: false },
}));

const deploymentSeries = computed(() => [
  {
    name: "Tenants",
    data: data.value ? Object.values(data.value.stats.deployment_modes) : [],
  },
]);

const daysLeftColor = (days: number) =>
  days <= 7 ? "error" : days <= 14 ? "warning" : "default";

const initials = (name: string) => name.slice(0, 2).toUpperCase();
</script>

<template>
  <div>
    <Hero
      :greeting="greeting"
      subtitle="Here's how your platform is growing across every tenant."
      cta-label="Admin Portal"
      cta-icon="$shieldAccount"
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
            label="Total tenants"
            :value="data.stats.tenants.total"
            :percent="tenantsActivePercent"
            direction="up"
            color="#273775"
          />
        </VCol>
        <VCol cols="12" sm="4">
          <Stat
            label="New this month"
            :value="data.stats.tenants.new_this_month"
            :percent="growthPercent"
            direction="up"
            color="#27a7df"
          />
        </VCol>
        <VCol cols="12" sm="4">
          <Stat
            label="System admins"
            :value="data.stats.admins.total"
            :percent="adminsActivePercent"
            direction="down"
            color="#7fc8e8"
          />
        </VCol>
      </VRow>

      <!-- ── Main chart + expiring summary card ─────────────────────────── -->
      <VRow class="mb-2">
        <VCol cols="12" md="8">
          <VCard variant="flat" border rounded="lg" class="pa-5 h-100">
            <div
              class="d-flex align-center justify-space-between mb-1 flex-wrap gap-2"
            >
              <div>
                <p class="text-h5 font-weight-bold mb-0">{{ grossTotal }}</p>
                <p class="text-caption text-medium-emphasis mb-0">
                  Tenant growth — last 12 months
                </p>
              </div>
              <div class="d-flex align-center gap-4">
                <span class="d-flex align-center gap-1 text-caption">
                  <span class="legend-dot" style="background: #273775" /> New
                </span>
                <span class="d-flex align-center gap-1 text-caption">
                  <span class="legend-dot" style="background: #27a7df" />
                  Cumulative
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

        <VCol cols="12" md="4">
          <div class="expiring-card">
            <div class="expiring-card-deco" />
            <div class="d-flex align-center justify-space-between mb-6">
              <div class="d-flex align-center gap-2">
                <VIcon
                  icon="mdi-clock-alert-outline"
                  size="20"
                  color="#27a7df"
                />
                <span class="text-caption font-weight-medium expiring-label"
                  >Subscriptions</span
                >
              </div>
              <VChip
                :color="
                  data.stats.tenants.expiring_soon > 0 ? 'warning' : 'success'
                "
                size="x-small"
                variant="flat"
              >
                {{
                  data.stats.tenants.expiring_soon > 0
                    ? "Action needed"
                    : "All clear"
                }}
              </VChip>
            </div>

            <p class="text-h4 font-weight-bold text-white mb-1">
              {{ data.stats.tenants.expiring_soon }}
            </p>
            <p class="text-caption expiring-name mb-6">
              Expiring in the next 30 days
            </p>

            <div
              class="d-flex align-center justify-space-between expiring-footer"
            >
              <div>
                <p class="text-caption expiring-footer-label mb-0">
                  Already expired
                </p>
                <p class="text-body-2 font-weight-medium text-white mb-0">
                  {{ data.stats.tenants.expired }}
                </p>
              </div>
              <div class="text-right">
                <p class="text-caption expiring-footer-label mb-0">
                  Active tenants
                </p>
                <p class="text-body-2 font-weight-medium text-white mb-0">
                  {{ data.stats.tenants.active }}
                </p>
              </div>
            </div>
          </div>
        </VCol>
      </VRow>

      <!-- ── Tiers donut + Deployment bar ─────────────────────────────── -->
      <VRow class="mb-2">
        <VCol cols="12" md="5">
          <VCard variant="flat" border rounded="lg" class="pa-5 h-100">
            <p class="text-subtitle-2 font-weight-semibold mb-4">
              Subscription tiers
            </p>
            <EarningsDonut
              v-if="tierSegments.length"
              :segments="tierSegments"
            />
            <p v-else class="text-caption text-medium-emphasis">No data</p>
          </VCard>
        </VCol>

        <VCol cols="12" md="7">
          <VCard variant="flat" border rounded="lg" class="pa-5 h-100">
            <p class="text-subtitle-2 font-weight-semibold mb-4">
              Deployment modes
            </p>
            <apexchart
              type="bar"
              height="180"
              :options="deploymentOptions"
              :series="deploymentSeries"
            />
          </VCard>
        </VCol>
      </VRow>

      <!-- ── Tenants table (Companies/Contacts/Order/Completion shape) ──── -->
      <VRow>
        <VCol cols="12">
          <VCard variant="flat" border rounded="lg">
            <div class="px-5 pt-5 pb-3">
              <p class="text-subtitle-2 font-weight-semibold mb-0">
                Recently created tenants
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ data.stats.tenants.new_this_month }} new this month
              </p>
            </div>
            <VDivider />
            <VTable density="comfortable">
              <thead>
                <tr>
                  <th>Tenant</th>
                  <th>Contacts</th>
                  <th>Tier</th>
                  <th>Subscription health</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in data.recent_tenants" :key="t.id">
                  <td>
                    <div class="d-flex align-center gap-2 py-1">
                      <VAvatar
                        size="32"
                        color="primary"
                        variant="tonal"
                        rounded="lg"
                      >
                        <VIcon icon="mdi-domain" size="16" />
                      </VAvatar>
                      <div>
                        <p class="text-body-2 font-weight-medium mb-0">
                          {{ t.name }}
                        </p>
                        <p class="text-caption text-medium-emphasis mb-0">
                          {{ t.slug }}
                        </p>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="d-flex avatar-stack">
                      <VAvatar
                        v-for="(c, i) in (t.contacts ?? []).slice(0, 3)"
                        :key="i"
                        size="26"
                        :color="i % 2 === 0 ? 'primary' : 'info'"
                        variant="tonal"
                        class="text-caption stacked-avatar"
                      >
                        {{ initials(c.name) }}
                      </VAvatar>
                      <span
                        v-if="!t.contacts?.length"
                        class="text-caption text-medium-emphasis"
                        >—</span
                      >
                    </div>
                  </td>
                  <td>
                    <VChip
                      :color="tierColor[t.subscription_tier] ?? 'default'"
                      size="x-small"
                      variant="tonal"
                      class="text-capitalize"
                    >
                      {{ t.subscription_tier }}
                    </VChip>
                  </td>
                  <td style="min-width: 180px">
                    <div class="d-flex align-center gap-2">
                      <VChip
                        :color="t.is_active ? 'success' : 'error'"
                        size="x-small"
                        variant="tonal"
                      >
                        {{ t.is_active ? "Active" : "Inactive" }}
                      </VChip>
                      <span class="text-caption text-medium-emphasis">
                        {{
                          t.subscription_expires_at
                            ? fmtDate(t.subscription_expires_at)
                            : "No expiry"
                        }}
                      </span>
                    </div>
                  </td>
                </tr>
                <tr v-if="!data.recent_tenants.length">
                  <td
                    colspan="4"
                    class="text-center text-caption text-medium-emphasis py-6"
                  >
                    No tenants yet.
                  </td>
                </tr>
              </tbody>
            </VTable>
          </VCard>
        </VCol>
      </VRow>
    </template>
  </div>
</template>

<style scoped>
.legend-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  display: inline-block;
}
.avatar-stack .stacked-avatar:not(:first-child) {
  margin-left: -8px;
  border: 2px solid white;
}
.expiring-card {
  position: relative;
  border-radius: 16px;
  padding: 24px 26px;
  height: 100%;
  background: linear-gradient(145deg, #131c33 0%, #1c2a5c 55%, #273775 100%);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.expiring-card-deco {
  position: absolute;
  width: 220px;
  height: 220px;
  border-radius: 50%;
  background: rgba(39, 167, 223, 0.18);
  top: -120px;
  right: -60px;
  filter: blur(8px);
}
.expiring-label {
  color: rgba(255, 255, 255, 0.7);
}
.expiring-name {
  color: rgba(255, 255, 255, 0.65);
}
.expiring-footer {
  margin-top: auto;
}
.expiring-footer-label {
  color: rgba(255, 255, 255, 0.55);
}
</style>
