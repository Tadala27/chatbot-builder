<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";

const userStore = useUserStore();
definePage({
  meta: { layout: "default" },
});
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

// ── Data ──────────────────────────────────────────────────────────────────────

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

const formatChange = (pct: number | null) => {
  if (pct === null) return { text: "—", color: "default" };
  if (pct > 0) return { text: `+${pct}%`, color: "success" };
  if (pct < 0) return { text: `${pct}%`, color: "error" };
  return { text: "0%", color: "default" };
};

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

// ── Chart options ─────────────────────────────────────────────────────────────

const growthSeries = computed(() => {
  if (!data.value) return [];
  return [
    {
      name: "New tenants",
      data: data.value.charts.tenant_growth.map((d) => d.total),
    },
  ];
});

const growthCategories = computed(
  () => data.value?.charts.tenant_growth.map((d) => fmtMonth(d.month)) ?? [],
);

const growthOptions = computed(() => ({
  chart: { type: "bar", toolbar: { show: false } },
  plotOptions: { bar: { borderRadius: 4, columnWidth: "55%" } },
  xaxis: {
    categories: growthCategories.value,
    labels: { style: { fontSize: "11px" } },
  },
  colors: ["#5199ae"],
  dataLabels: { enabled: false },
  grid: { borderColor: "#f0f0f0" },
  yaxis: {
    labels: { style: { fontSize: "11px" } },
    min: 0,
    forceNiceScale: true,
  },
}));

// Tier donut
const tierSeries = computed(() =>
  data.value ? Object.values(data.value.stats.subscription_tiers) : [],
);
const tierLabels = computed(() =>
  data.value
    ? Object.keys(data.value.stats.subscription_tiers).map(
        (k) => k.charAt(0).toUpperCase() + k.slice(1),
      )
    : [],
);
const tierPieOptions = computed(() => ({
  chart: { type: "donut" },
  labels: tierLabels.value,
  colors: ["#9ca3af", "#60a5fa", "#5199ae", "#22c55e"],
  legend: { position: "bottom" },
  dataLabels: {
    enabled: true,
    formatter: (val: number) => `${Math.round(val)}%`,
  },
  plotOptions: { pie: { donut: { size: "60%" } } },
}));

// Deployment donut
const deploymentSeries = computed(() =>
  data.value ? Object.values(data.value.stats.deployment_modes) : [],
);
const deploymentLabels = computed(() =>
  data.value
    ? Object.keys(data.value.stats.deployment_modes).map((k) =>
        k.replace("_", " "),
      )
    : [],
);
const deploymentOptions = computed(() => ({
  chart: { type: "donut" },
  labels: deploymentLabels.value,
  colors: ["#5199ae", "#3f7f91", "#6fb2c5"],
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
      <h1 class="text-h5 font-weight-bold">System Overview</h1>
      <p class="text-body-2 text-medium-emphasis mt-1">
        Logged in as {{ userStore.displayName }} · Super Admin
      </p>
    </div>
    <VChip
      color="error"
      variant="tonal"
      size="small"
      prepend-icon="mdi-shield-account"
    >
      Admin Portal
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
    <!-- ── Stat cards ────────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <!-- Total tenants -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                Total Tenants
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.tenants.total }}
              </p>
              <p class="text-caption mt-1">
                <span
                  :class="`text-${formatChange(data.stats.tenants.change_pct).color}`"
                >
                  {{ formatChange(data.stats.tenants.change_pct).text }}
                </span>
                vs last month
              </p>
            </div>
            <VAvatar color="primary" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-domain" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="d-flex gap-4 text-caption text-medium-emphasis">
            <span
              ><span class="text-success font-weight-medium">{{
                data.stats.tenants.active
              }}</span>
              active</span
            >
            <span
              ><span class="text-error font-weight-medium">{{
                data.stats.tenants.inactive
              }}</span>
              inactive</span
            >
          </div>
        </VCard>
      </VCol>

      <!-- New this month -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                New This Month
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.tenants.new_this_month }}
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                {{ data.stats.tenants.new_prev_month }} last month
              </p>
            </div>
            <VAvatar color="success" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-domain-plus" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="text-caption text-medium-emphasis">
            Signed up in
            {{
              new Date().toLocaleDateString("en-GB", {
                month: "long",
                year: "numeric",
              })
            }}
          </div>
        </VCard>
      </VCol>

      <!-- Expiring soon -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                Expiring (30 days)
              </p>
              <p
                class="text-h4 font-weight-bold"
                :class="
                  data.stats.tenants.expiring_soon > 0 ? 'text-warning' : ''
                "
              >
                {{ data.stats.tenants.expiring_soon }}
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                subscriptions
              </p>
            </div>
            <VAvatar color="warning" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-clock-alert-outline" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="text-caption text-medium-emphasis">
            <span class="text-error font-weight-medium">{{
              data.stats.tenants.expired
            }}</span>
            already expired
          </div>
        </VCard>
      </VCol>

      <!-- Admins -->
      <VCol cols="12" sm="6" lg="3">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <div class="d-flex align-start justify-space-between">
            <div>
              <p
                class="text-caption text-medium-emphasis text-uppercase font-weight-medium mb-1"
              >
                System Admins
              </p>
              <p class="text-h4 font-weight-bold">
                {{ data.stats.admins.total }}
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                {{ data.stats.admins.active }} active accounts
              </p>
            </div>
            <VAvatar color="secondary" variant="tonal" size="44" rounded="lg">
              <VIcon icon="mdi-shield-account-outline" />
            </VAvatar>
          </div>
          <VDivider class="my-3" />
          <div class="text-caption text-medium-emphasis">
            Central / landlord database users
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Charts ────────────────────────────────────────────────────── -->
    <VRow class="mb-2">
      <!-- Growth bar -->
      <VCol cols="12" md="8">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Tenant growth — last 12 months
          </p>
          <apexchart
            type="bar"
            height="220"
            :options="growthOptions"
            :series="growthSeries"
          />
        </VCard>
      </VCol>

      <!-- Tier breakdown -->
      <VCol cols="12" md="4">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Subscription tiers
          </p>
          <apexchart
            v-if="tierSeries.length"
            type="donut"
            height="220"
            :options="tierPieOptions"
            :series="tierSeries"
          />
          <div
            v-else
            class="d-flex align-center justify-center"
            style="height: 220px"
          >
            <p class="text-caption text-medium-emphasis">No data</p>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Deployment modes + expiring detail -->
    <VRow class="mb-2">
      <VCol cols="12" md="4">
        <VCard variant="flat" border rounded="lg" class="pa-5">
          <p class="text-subtitle-2 font-weight-semibold mb-4">
            Deployment modes
          </p>
          <apexchart
            v-if="deploymentSeries.length"
            type="donut"
            height="220"
            :options="deploymentOptions"
            :series="deploymentSeries"
          />
          <div
            v-else
            class="d-flex align-center justify-center"
            style="height: 220px"
          >
            <p class="text-caption text-medium-emphasis">No data</p>
          </div>
        </VCard>
      </VCol>

      <!-- Expiring soon list -->
      <VCol cols="12" md="8">
        <VCard variant="flat" border rounded="lg">
          <div class="d-flex align-center justify-space-between px-5 pt-5 pb-3">
            <p class="text-subtitle-2 font-weight-semibold">
              Subscriptions expiring soon
            </p>
            <RouterLink
              to="/administration/tenants"
              class="text-caption text-primary text-decoration-none"
            >
              Manage tenants →
            </RouterLink>
          </div>
          <VDivider />
          <div v-if="data.expiring_soon.length">
            <VTable density="compact">
              <thead>
                <tr>
                  <th>Tenant</th>
                  <th>Tier</th>
                  <th class="text-right">Expires</th>
                  <th class="text-right">Days left</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="t in data.expiring_soon" :key="t.id">
                  <td>
                    <p class="text-body-2 font-weight-medium">{{ t.name }}</p>
                    <p class="text-caption text-medium-emphasis">
                      {{ t.slug }}
                    </p>
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
                  <td class="text-right text-caption">
                    {{ fmtDate(t.subscription_expires_at) }}
                  </td>
                  <td class="text-right">
                    <VChip
                      :color="
                        t.days_left <= 7
                          ? 'error'
                          : t.days_left <= 14
                            ? 'warning'
                            : 'default'
                      "
                      size="x-small"
                      variant="tonal"
                    >
                      {{ t.days_left }}d
                    </VChip>
                  </td>
                </tr>
              </tbody>
            </VTable>
          </div>
          <p v-else class="text-caption text-medium-emphasis pa-5">
            No subscriptions expiring in the next 30 days.
          </p>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Recent tenants table ──────────────────────────────────────── -->
    <VRow>
      <VCol cols="12">
        <VCard variant="flat" border rounded="lg">
          <div class="d-flex align-center justify-space-between px-5 pt-5 pb-3">
            <p class="text-subtitle-2 font-weight-semibold">
              Recently created tenants
            </p>
            <RouterLink
              to="/administration/tenants"
              class="text-caption text-primary text-decoration-none"
            >
              View all →
            </RouterLink>
          </div>
          <VDivider />
          <VTable density="compact">
            <thead>
              <tr>
                <th>Name</th>
                <th>Slug</th>
                <th>Tier</th>
                <th>Status</th>
                <th class="text-right">Expires</th>
                <th class="text-right">Created</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="t in data.recent_tenants" :key="t.id">
                <td class="text-body-2 font-weight-medium">{{ t.name }}</td>
                <td class="text-caption text-medium-emphasis">
                  {{ t.slug }}
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
                <td>
                  <VChip
                    :color="t.is_active ? 'success' : 'error'"
                    size="x-small"
                    variant="tonal"
                  >
                    {{ t.is_active ? "Active" : "Inactive" }}
                  </VChip>
                </td>
                <td class="text-right text-caption text-medium-emphasis">
                  {{
                    t.subscription_expires_at
                      ? fmtDate(t.subscription_expires_at)
                      : "—"
                  }}
                </td>
                <td class="text-right text-caption text-medium-emphasis">
                  {{ fmtDate(t.created_at) }}
                </td>
              </tr>
            </tbody>
          </VTable>
        </VCard>
      </VCol>
    </VRow>
  </template>
</template>
