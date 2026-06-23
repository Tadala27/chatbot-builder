<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";

const router = useRouter();

// ── Types — match TenantController + tenants/domains schema exactly ──────────

interface Domain {
  id: number;
  domain: string;
  is_primary: boolean;
  tenant_id: string;
}

interface Tenant {
  id: string;
  name: string;
  slug: string;
  db_schema: string;
  deployment_mode: "shared" | "dedicated" | "self_hosted";
  is_active: boolean;
  subscription_tier: "free" | "starter" | "professional" | "enterprise";
  subscription_expires_at: string | null;
  max_flows: number;
  max_conversations_per_month: number;
  settings: Record<string, any> | null;
  domains: Domain[];
  domains_count: number;
  created_at: string;
  updated_at: string;
}

interface PaginatedResponse {
  data: Tenant[];
  current_page: number;
  from: number | null;
  to: number | null;
  per_page: number;
  total: number;
  last_page: number;
}

// ── State ─────────────────────────────────────────────────────────────────────

const isLoading = ref(true);
const loading = ref(false);
const tenants = ref<Tenant[]>([]);
const meta = ref({
  current_page: 1,
  from: 0,
  to: 0,
  per_page: 20,
  total: 0,
  last_page: 1,
});

// Filters — match TenantController::index query params exactly
const search = ref("");
const isActiveFilter = ref<"All" | "Active" | "Inactive">("All");
const tierFilter = ref<
  "All" | "free" | "starter" | "professional" | "enterprise"
>("All");

const page = ref(1);
const perPage = ref(20);

// Statistics (TenantController::statistics)
const stats = ref<{
  total: number;
  active: number;
  inactive: number;
  by_tier: Record<string, number>;
  expiring_soon: number;
  expired: number;
} | null>(null);

const tierOptions = ["All", "free", "starter", "professional", "enterprise"];
const statusOptions = ["All", "Active", "Inactive"];

const totalPages = computed(() => meta.value.last_page || 1);

const tierColor: Record<string, string> = {
  free: "default",
  starter: "info",
  professional: "primary",
  enterprise: "success",
};

const deploymentColor: Record<string, string> = {
  shared: "info",
  dedicated: "warning",
  self_hosted: "secondary",
};

// Snackbar
const snackbar = ref({
  show: false,
  message: "",
  color: "success",
  timeout: 4000,
});
const showSnackbar = (msg: string, color = "success", timeout = 4000) => {
  snackbar.value = { show: true, message: msg, color, timeout };
};

// ── Helpers ───────────────────────────────────────────────────────────────────

const getInitials = (name: string) => {
  const parts = name.trim().split(" ");
  if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
  return name.substring(0, 2).toUpperCase();
};

const formatDate = (date: string | null) => {
  if (!date) return "—";
  return new Date(date).toLocaleDateString("en-GB", {
    day: "2-digit",
    month: "short",
    year: "numeric",
  });
};

const primaryDomain = (tenant: Tenant) =>
  tenant.domains?.find((d) => d.is_primary)?.domain ??
  tenant.domains?.[0]?.domain ??
  null;

const isExpiringSoon = (tenant: Tenant) => {
  if (!tenant.subscription_expires_at) return false;
  const days =
    (new Date(tenant.subscription_expires_at).getTime() - Date.now()) /
    86_400_000;
  return days >= 0 && days <= 30;
};

const isExpired = (tenant: Tenant) =>
  !!tenant.subscription_expires_at &&
  new Date(tenant.subscription_expires_at).getTime() < Date.now();

// ── Fetch ─────────────────────────────────────────────────────────────────────

const fetchTenants = async () => {
  loading.value = true;
  try {
    const params: Record<string, any> = {
      page: page.value,
      per_page: perPage.value,
    };

    if (search.value) params.search = search.value;
    if (isActiveFilter.value !== "All")
      params.is_active = isActiveFilter.value === "Active";
    if (tierFilter.value !== "All") params.subscription_tier = tierFilter.value;

    // TenantController::index returns a raw Laravel paginator (not wrapped in
    // { success, data }), so the page/data fields are top-level.
    const { data } = await axios.get<PaginatedResponse>("/api/admin/tenants", {
      params,
    });

    tenants.value = data.data ?? [];
    meta.value = {
      current_page: data.current_page,
      from: data.from ?? 0,
      to: data.to ?? 0,
      per_page: data.per_page,
      total: data.total,
      last_page: data.last_page,
    };
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? "Failed to load tenants",
      "error",
    );
  } finally {
    isLoading.value = false;
    loading.value = false;
  }
};

const fetchStatistics = async () => {
  try {
    const { data } = await axios.get("/api/admin/tenants/statistics");
    stats.value = data;
  } catch (e) {
    console.error("Failed to load statistics", e);
  }
};

// ── Actions ───────────────────────────────────────────────────────────────────

const viewTenant = (id: string) => router.push(`/administration/tenants/${id}`);
const editTenant = (id: string) =>
  router.push(`/administration/tenants/${id}/edit`);
const createTenant = () => router.push("/administration/tenants/create");

const toggleStatus = async (tenant: Tenant) => {
  const action = tenant.is_active ? "deactivate" : "activate";
  const { isConfirmed } = await Swal.fire({
    title: `${action.charAt(0).toUpperCase() + action.slice(1)} Tenant`,
    text: `Are you sure you want to ${action} ${tenant.name}?`,
    icon: "question",
    showCancelButton: true,
    confirmButtonText: `Yes, ${action}!`,
  });
  if (!isConfirmed) return;

  try {
    await axios.patch(`/api/admin/tenants/${tenant.id}/${action}`);
    showSnackbar(`Tenant ${action}d successfully`);
    fetchTenants();
    fetchStatistics();
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? `Failed to ${action} tenant`,
      "error",
    );
  }
};

const deleteTenant = async (tenant: Tenant) => {
  const { isConfirmed } = await Swal.fire({
    title: "Delete Tenant",
    html: `This will <strong>soft-delete</strong> <code>${tenant.name}</code>.<br/>If the tenant uses <strong>shared</strong> deployment mode, force-deleting later will drop its database entirely. This action cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    cancelButtonColor: "#6b7280",
    confirmButtonText: "Yes, delete!",
  });
  if (!isConfirmed) return;

  try {
    await axios.delete(`/api/admin/tenants/${tenant.id}`);
    showSnackbar("Tenant deleted successfully");
    fetchTenants();
    fetchStatistics();
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? "Failed to delete tenant",
      "error",
    );
  }
};

const impersonate = async (tenant: Tenant) => {
  try {
    const { data } = await axios.post(
      `/api/admin/tenants/${tenant.id}/impersonate`,
    );
    const domain = data.tenant_domain;

    if (!domain) {
      showSnackbar(
        "Tenant has no primary domain set — cannot impersonate.",
        "error",
      );
      return;
    }

    await Swal.fire({
      title: "Impersonation token issued",
      html: `Token expires in <strong>${Math.floor(data.expires_in / 60)} minutes</strong>.<br/>Opening <code>${domain}</code>…`,
      icon: "success",
      timer: 2500,
      showConfirmButton: false,
    });

    window.open(`https://${domain}/impersonate?token=${data.token}`, "_blank");
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? "Failed to issue impersonation token",
      "error",
    );
  }
};

const clearFilters = () => {
  search.value = "";
  isActiveFilter.value = "All";
  tierFilter.value = "All";
  page.value = 1;
};

// ── Watchers ──────────────────────────────────────────────────────────────────

watchDebounced(
  search,
  () => {
    page.value = 1;
    fetchTenants();
  },
  { debounce: 500 },
);
watchDebounced(
  [isActiveFilter, tierFilter],
  () => {
    page.value = 1;
    fetchTenants();
  },
  { debounce: 0 },
);
watchDebounced(page, () => fetchTenants(), { debounce: 0 });

onMounted(() => {
  fetchTenants();
  fetchStatistics();
});
</script>

<template>
  <div>
    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Tenants</h1>
        <p class="text-subtitle-1 text-medium-emphasis">
          Manage tenant organisations, domains, and provisioning
        </p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="createTenant">
        Add Tenant
      </VBtn>
    </div>

    <!-- ── Stat strip ─────────────────────────────────────────────────── -->
    <VRow v-if="stats" class="mb-4">
      <VCol cols="12" sm="6" md="3">
        <VCard variant="flat" border rounded="lg" class="pa-4">
          <p class="text-caption text-medium-emphasis text-uppercase">Total</p>
          <p class="text-h5 font-weight-bold">{{ stats.total }}</p>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="flat" border rounded="lg" class="pa-4">
          <p class="text-caption text-medium-emphasis text-uppercase">
            Active / Inactive
          </p>
          <p class="text-h5 font-weight-bold">
            <span class="text-success">{{ stats.active }}</span> /
            <span class="text-error">{{ stats.inactive }}</span>
          </p>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="flat" border rounded="lg" class="pa-4">
          <p class="text-caption text-medium-emphasis text-uppercase">
            Expiring (30d)
          </p>
          <p
            class="text-h5 font-weight-bold"
            :class="stats.expiring_soon > 0 ? 'text-warning' : ''"
          >
            {{ stats.expiring_soon }}
          </p>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard variant="flat" border rounded="lg" class="pa-4">
          <p class="text-caption text-medium-emphasis text-uppercase">
            Expired
          </p>
          <p
            class="text-h5 font-weight-bold"
            :class="stats.expired > 0 ? 'text-error' : ''"
          >
            {{ stats.expired }}
          </p>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Filters ────────────────────────────────────────────────────── -->
    <VRow>
      <VCol cols="12" md="5">
        <VTextField
          v-model="search"
          label="Search by name or slug…"
          prepend-inner-icon="mdi-magnify"
          :loading="loading"
          variant="outlined"
          clearable
          hide-details
          density="comfortable"
        />
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VSelect
          v-model="isActiveFilter"
          :items="statusOptions"
          label="Status"
          variant="outlined"
          hide-details
          density="comfortable"
        />
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VSelect
          v-model="tierFilter"
          :items="tierOptions"
          label="Subscription Tier"
          variant="outlined"
          hide-details
          density="comfortable"
          class="text-capitalize"
        />
      </VCol>
      <VCol cols="12" md="1" class="d-flex align-center">
        <VBtn
          color="secondary"
          variant="text"
          :loading="loading"
          :disabled="loading"
          block
          @click="clearFilters"
        >
          Clear
        </VBtn>
      </VCol>
    </VRow>

    <!-- ── Table ──────────────────────────────────────────────────────── -->
    <VCard class="mt-4" elevation="0" variant="outlined">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>

      <VTable v-else density="comfortable">
        <thead class="bg-surface">
          <tr class="text-secondary">
            <th class="text-left pa-4">Tenant</th>
            <th class="text-left pa-4">Domain</th>
            <th class="text-left pa-4">Deployment</th>
            <th class="text-left pa-4">Tier</th>
            <th class="text-left pa-4">Expires</th>
            <th class="text-left pa-4">Status</th>
            <th class="text-center pa-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!tenants.length">
            <td colspan="7" class="text-center py-12">
              <VIcon size="64" color="grey-lighten-1">mdi-domain</VIcon>
              <h3 class="text-h6 text-grey mt-4">No tenants found</h3>
              <p class="text-grey">
                {{
                  search
                    ? "Try adjusting your filters"
                    : "No tenants registered yet"
                }}
              </p>
              <VBtn
                color="primary"
                variant="outlined"
                class="mt-4"
                @click="clearFilters"
                >Clear Filters</VBtn
              >
            </td>
          </tr>

          <tr v-for="tenant in tenants" :key="tenant.id">
            <!-- Tenant -->
            <td class="pa-4">
              <div class="d-flex align-center gap-3">
                <VAvatar color="primary" variant="tonal" size="40">
                  <span class="text-subtitle-2">{{
                    getInitials(tenant.name)
                  }}</span>
                </VAvatar>
                <div>
                  <p class="text-subtitle-2 font-weight-medium mb-0">
                    {{ tenant.name }}
                  </p>
                  <p class="text-caption text-medium-emphasis mb-0">
                    {{ tenant.slug }} · {{ tenant.id }}
                  </p>
                </div>
              </div>
            </td>

            <!-- Domain -->
            <td class="pa-4">
              <p v-if="primaryDomain(tenant)" class="text-body-2 mb-0">
                {{ primaryDomain(tenant) }}
              </p>
              <VChip v-else size="x-small" color="warning" variant="tonal"
                >No domain set</VChip
              >
              <p
                v-if="tenant.domains_count > 1"
                class="text-caption text-medium-emphasis mt-1 mb-0"
              >
                +{{ tenant.domains_count - 1 }} more
              </p>
            </td>

            <!-- Deployment mode -->
            <td class="pa-4">
              <VChip
                :color="deploymentColor[tenant.deployment_mode]"
                size="small"
                variant="tonal"
                class="text-capitalize"
              >
                {{ tenant.deployment_mode.replace("_", " ") }}
              </VChip>
            </td>

            <!-- Tier -->
            <td class="pa-4">
              <VChip
                :color="tierColor[tenant.subscription_tier]"
                size="small"
                variant="tonal"
                class="text-capitalize"
              >
                {{ tenant.subscription_tier }}
              </VChip>
            </td>

            <!-- Expires -->
            <td class="pa-4">
              <p
                class="text-body-2 mb-0"
                :class="
                  isExpired(tenant)
                    ? 'text-error'
                    : isExpiringSoon(tenant)
                      ? 'text-warning'
                      : ''
                "
              >
                {{ formatDate(tenant.subscription_expires_at) }}
              </p>
            </td>

            <!-- Status -->
            <td class="pa-4">
              <VChip
                :color="tenant.is_active ? 'success' : 'error'"
                size="small"
                variant="tonal"
              >
                <VIcon start size="14">{{
                  tenant.is_active ? "mdi-check-circle" : "mdi-cancel"
                }}</VIcon>
                {{ tenant.is_active ? "Active" : "Inactive" }}
              </VChip>
            </td>

            <!-- Actions -->
            <td class="pa-4 text-center">
              <VMenu>
                <template #activator="{ props }">
                  <VBtn
                    v-bind="props"
                    icon
                    variant="text"
                    color="grey"
                    size="small"
                  >
                    <VIcon>mdi-dots-vertical</VIcon>
                  </VBtn>
                </template>
                <VList density="compact">
                  <VListItem @click="viewTenant(tenant.id)">
                    <template #prepend
                      ><VIcon size="small">mdi-eye</VIcon></template
                    >
                    <VListItemTitle>View Details</VListItemTitle>
                  </VListItem>
                  <VListItem @click="editTenant(tenant.id)">
                    <template #prepend
                      ><VIcon size="small">mdi-pencil</VIcon></template
                    >
                    <VListItemTitle>Edit</VListItemTitle>
                  </VListItem>
                  <VListItem @click="impersonate(tenant)">
                    <template #prepend
                      ><VIcon size="small">mdi-incognito</VIcon></template
                    >
                    <VListItemTitle>Impersonate</VListItemTitle>
                  </VListItem>
                  <VListItem @click="toggleStatus(tenant)">
                    <template #prepend>
                      <VIcon size="small">{{
                        tenant.is_active ? "mdi-cancel" : "mdi-check-circle"
                      }}</VIcon>
                    </template>
                    <VListItemTitle>{{
                      tenant.is_active ? "Deactivate" : "Activate"
                    }}</VListItemTitle>
                  </VListItem>
                  <VDivider />
                  <VListItem class="text-error" @click="deleteTenant(tenant)">
                    <template #prepend
                      ><VIcon size="small">mdi-trash-can</VIcon></template
                    >
                    <VListItemTitle>Delete</VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </td>
          </tr>
        </tbody>
      </VTable>

      <!-- Pagination -->
      <VCardText v-if="tenants.length" class="pt-4">
        <VRow
          class="align-center text-center text-sm-start"
          justify="space-between"
        >
          <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
            <span class="text-medium-emphasis">
              Showing {{ meta.from || 0 }}–{{ meta.to || 0 }} of
              {{ meta.total || 0 }} tenants
            </span>
          </VCol>
          <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
            <VPagination
              v-model="page"
              :length="totalPages"
              :total-visible="7"
              rounded="circle"
              density="comfortable"
              variant="outlined"
              color="primary"
            />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Snackbar -->
    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      :timeout="snackbar.timeout"
      location="top right"
    >
      {{ snackbar.message }}
      <template #actions>
        <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
      </template>
    </VSnackbar>
  </div>
</template>

<style scoped>
.gap-3 {
  gap: 12px;
}
</style>
