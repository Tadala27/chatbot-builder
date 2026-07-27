<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";

const router = useRouter();

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

const search = ref("");
const isActiveFilter = ref<"All" | "Active" | "Inactive">("All");
const tierFilter = ref<
  "All" | "free" | "starter" | "professional" | "enterprise"
>("All");
const page = ref(1);
const perPage = ref(12);

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

// ── Tier presentation — each tier gets its own personality ──────────────────

const tierMeta: Record<
  string,
  { label: string; icon: string; gradient: string; color: string }
> = {
  free: {
    label: "Free",
    icon: "$leaf",
    gradient: "linear-gradient(135deg, #273775, #27a2da)",
    color: "grey",
  },
  starter: {
    label: "Starter",
    icon: "$rocketLaunchOutline",
    gradient: "linear-gradient(135deg, #273775, #27a2da)",
    color: "light-blue",
  },
  professional: {
    label: "Professional",
    icon: "$briefcaseVariant",
    gradient: "linear-gradient(135deg, #273775, #27a2da)",
    color: "primary",
  },
  enterprise: {
    label: "Enterprise",
    icon: "$castle",
    gradient: "linear-gradient(135deg, #273775, #27a2da)",
    color: "deep-purple",
  },
};

const deploymentMeta: Record<string, { label: string; icon: string }> = {
  shared: { label: "Shared", icon: "$accountGroupOutline" },
  dedicated: { label: "Dedicated", icon: "$server" },
  self_hosted: { label: "Self-hosted", icon: "$homeCityOutline" },
};

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

const nameHue = (name: string) =>
  [...(name ?? "")].reduce((a, c) => a + c.charCodeAt(0), 0) % 360;

const formatDate = (date: string | null) => {
  if (!date) return "No expiry";
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

const daysLeft = (tenant: Tenant) => {
  if (!tenant.subscription_expires_at) return null;
  return Math.ceil(
    (new Date(tenant.subscription_expires_at).getTime() - Date.now()) /
      86_400_000,
  );
};

const expiryColor = (tenant: Tenant) => {
  const d = daysLeft(tenant);
  if (d === null) return "success";
  if (d < 0) return "error";
  if (d <= 14) return "warning";
  return "success";
};

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
      timer: 2200,
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

const goToPage = (p: number) => {
  if (p < 1 || p > meta.value.last_page) return;
  page.value = p;
};

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
  <div class="pa-1">
    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="d-flex align-start justify-space-between flex-wrap ga-4 mb-4">
      <div>
        <h1 class="text-h5 font-weight-bold mb-1">Tenants</h1>
        <p class="text-body-2 text-medium-emphasis mb-0">
          Every organisation running on your platform, at a glance.
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="$plus"
        rounded="md"
        @click="createTenant"
        >Add Tenant</VBtn
      >
    </div>

    <!-- ── Stat strip ─────────────────────────────────────────────────── -->
    <VRow v-if="stats" class="mb-2" dense>
      <VCol cols="12" sm="6" md="3">
        <VCard
          rounded="pill"
          variant="outlined"
          class="d-flex align-center pa-2"
        >
          <VAvatar color="info" variant="tonal" size="34" class="mr-3">
            <VIcon size="18">$domain</VIcon>
          </VAvatar>
          <div>
            <p class="text-subtitle-1 font-weight-bold mb-0 lh-1">
              {{ stats.total }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">Total tenants</p>
          </div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard
          rounded="pill"
          variant="outlined"
          class="d-flex align-center pa-2"
        >
          <VAvatar color="success" variant="tonal" size="34" class="mr-3">
            <VIcon size="18">$checkDecagram</VIcon>
          </VAvatar>
          <div>
            <p class="text-subtitle-1 font-weight-bold mb-0 lh-1">
              {{ stats.active }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">Active</p>
          </div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard
          rounded="pill"
          variant="outlined"
          class="d-flex align-center pa-2"
        >
          <VAvatar color="warning" variant="tonal" size="34" class="mr-3">
            <VIcon size="18">$clockAlertOutline</VIcon>
          </VAvatar>
          <div>
            <p class="text-subtitle-1 font-weight-bold mb-0 lh-1">
              {{ stats.expiring_soon }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">Expiring soon</p>
          </div>
        </VCard>
      </VCol>
      <VCol cols="12" sm="6" md="3">
        <VCard
          rounded="pill"
          variant="outlined"
          class="d-flex align-center pa-2"
        >
          <VAvatar color="error" variant="tonal" size="34" class="mr-3">
            <VIcon size="18">$alertCircleOutline</VIcon>
          </VAvatar>
          <div>
            <p class="text-subtitle-1 font-weight-bold mb-0 lh-1">
              {{ stats.expired }}
            </p>
            <p class="text-caption text-medium-emphasis mb-0">Expired</p>
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Filter bar ─────────────────────────────────────────────────── -->
    <div class="d-flex align-center ga-3 flex-wrap mb-4 mt-2">
      <VTextField
        v-model="search"
        placeholder="Search tenants by name or slug…"
        prepend-inner-icon="$magnify"
        variant="solo-filled"
        density="comfortable"
        rounded="pill"
        flat
        hide-details
        single-line
        style="max-width: 340px; min-width: 220px"
      />
      <VSelect
        v-model="isActiveFilter"
        :items="statusOptions"
        variant="solo-filled"
        density="comfortable"
        rounded="pill"
        flat
        hide-details
        style="max-width: 160px"
      />
      <VSelect
        v-model="tierFilter"
        :items="tierOptions"
        variant="solo-filled"
        density="comfortable"
        rounded="pill"
        flat
        hide-details
        class="text-capitalize"
        style="max-width: 180px"
      />
      <VBtn variant="text" size="small" @click="clearFilters">Clear</VBtn>
    </div>

    <!-- ── Loading ────────────────────────────────────────────────────── -->
    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <!-- ── Empty state ────────────────────────────────────────────────── -->
    <VCard v-else-if="!tenants.length" variant="flat" class="text-center py-12">
      <VAvatar color="info" variant="tonal" size="60" class="mx-auto mb-3">
        <VIcon size="30">$domainOff</VIcon>
      </VAvatar>
      <p class="text-subtitle-1 font-weight-bold mb-0">No tenants found</p>
      <p class="text-body-2 text-medium-emphasis mb-2">
        {{
          search ? "Try adjusting your filters" : "No tenants registered yet"
        }}
      </p>
      <VBtn variant="outlined" size="small" @click="clearFilters"
        >Clear filters</VBtn
      >
    </VCard>

    <!-- ── Card grid ──────────────────────────────────────────────────── -->
    <VRow v-else dense>
      <VCol v-for="tenant in tenants" :key="tenant.id" cols="12" sm="6" md="4">
        <VCard
          rounded="md"
          class="d-flex flex-column fill-height"
          :class="!tenant.is_active ? 'opacity-60' : ''"
        >
          <!-- top banner strip in tier gradient -->
          <div
            class="d-flex align-center pa-3 ga-2"
            :style="`background:${tierMeta[tenant.subscription_tier]?.gradient}`"
          >
            <VAvatar color="rgba(255,255,255,0.22)" size="24">
              <VIcon size="16" color="white">{{
                tierMeta[tenant.subscription_tier]?.icon
              }}</VIcon>
            </VAvatar>
            <span class="text-caption font-weight-bold text-white flex-fill">{{
              tierMeta[tenant.subscription_tier]?.label
            }}</span>
            <VMenu location="bottom end">
              <template #activator="{ props }">
                <VBtn
                  v-bind="props"
                  icon="$dotsVertical"
                  size="small"
                  variant="tonal"
                  color="white"
                  density="comfortable"
                  @click.stop
                />
              </template>
              <VList density="compact">
                <VListItem @click="viewTenant(tenant.id)">
                  <template #prepend><VIcon size="small">$eye</VIcon></template>
                  <VListItemTitle>View details</VListItemTitle>
                </VListItem>
                <VListItem @click="editTenant(tenant.id)">
                  <template #prepend
                    ><VIcon size="small">$pencil</VIcon></template
                  >
                  <VListItemTitle>Edit</VListItemTitle>
                </VListItem>
                <VListItem @click="impersonate(tenant)">
                  <template #prepend
                    ><VIcon size="small">$incognito</VIcon></template
                  >
                  <VListItemTitle>Impersonate</VListItemTitle>
                </VListItem>
                <VListItem @click="toggleStatus(tenant)">
                  <template #prepend
                    ><VIcon size="small">{{
                      tenant.is_active ? "$cancel" : "$checkCircle"
                    }}</VIcon></template
                  >
                  <VListItemTitle>{{
                    tenant.is_active ? "Deactivate" : "Activate"
                  }}</VListItemTitle>
                </VListItem>
                <VDivider />
                <VListItem class="text-error" @click="deleteTenant(tenant)">
                  <template #prepend
                    ><VIcon size="small">$trashCan</VIcon></template
                  >
                  <VListItemTitle>Delete</VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </div>

          <VCardText
            class="flex-fill"
            style="cursor: pointer"
            @click="viewTenant(tenant.id)"
          >
            <div class="d-flex align-center ga-3 mb-3">
              <VAvatar
                rounded="lg"
                size="42"
                :style="`background: hsl(${nameHue(tenant.name)}, 55%, 45%)`"
              >
                <span class="text-body-2 font-weight-bold text-white">{{
                  getInitials(tenant.name)
                }}</span>
              </VAvatar>
              <div class="flex-fill min-w-0">
                <p class="text-body-2 font-weight-bold text-truncate mb-0">
                  {{ tenant.name }}
                </p>
                <p class="text-caption text-medium-emphasis text-truncate mb-0">
                  {{ tenant.slug }}
                </p>
              </div>
              <VIcon :color="tenant.is_active ? 'success' : 'error'" size="12"
                >$circle</VIcon
              >
            </div>

            <VChip
              variant="tonal"
              color="default"
              size="small"
              prepend-icon="$web"
              class="mb-3"
              label
            >
              {{ primaryDomain(tenant) ?? "No domain set" }}
            </VChip>

            <div class="d-flex ga-2 flex-wrap mb-3">
              <VChip
                size="small"
                :color="tenant.is_active ? 'success' : 'error'"
                variant="tonal"
              >
                {{ tenant.is_active ? "Active" : "Inactive" }}
              </VChip>
              <VChip
                size="small"
                variant="tonal"
                color="default"
                :prepend-icon="deploymentMeta[tenant.deployment_mode]?.icon"
              >
                {{ deploymentMeta[tenant.deployment_mode]?.label }}
              </VChip>
            </div>

            <div
              class="d-flex align-center ga-1 text-caption font-weight-medium"
              :class="`text-${expiryColor(tenant)}`"
            >
              <VIcon size="14">{{
                daysLeft(tenant) !== null && daysLeft(tenant)! < 0
                  ? "$alertCircle"
                  : "$calendarClock"
              }}</VIcon>
              <span>{{ formatDate(tenant.subscription_expires_at) }}</span>
              <span
                v-if="daysLeft(tenant) !== null && daysLeft(tenant)! >= 0"
                class="text-medium-emphasis font-weight-regular"
              >
                · {{ daysLeft(tenant) }}d left
              </span>
            </div>
          </VCardText>

          <VDivider />
          <VCardActions class="pa-0">
            <VBtn
              variant="text"
              rounded="0"
              size="small"
              class="flex-fill"
              prepend-icon="$eyeOutline"
              @click.stop="viewTenant(tenant.id)"
              >View</VBtn
            >
            <VDivider vertical />
            <VBtn
              variant="text"
              rounded="0"
              size="small"
              class="flex-fill"
              prepend-icon="$pencilOutline"
              @click.stop="editTenant(tenant.id)"
              >Edit</VBtn
            >
            <VDivider vertical />
            <VBtn
              variant="text"
              rounded="0"
              size="small"
              class="flex-fill"
              prepend-icon="$incognito"
              @click.stop="impersonate(tenant)"
              >Enter</VBtn
            >
          </VCardActions>
        </VCard>
      </VCol>

      <!-- ── Add-tenant ghost card ──────────────────────────────────────── -->
      <VCol cols="12" sm="6" md="4" lg="3">
        <VCard
          variant="outlined"
          rounded="lg"
          class="d-flex flex-column align-center justify-center text-medium-emphasis fill-height"
          style="min-height: 180px; border-style: dashed; cursor: pointer"
          @click="createTenant"
        >
          <VIcon size="30">$plusCircleOutline</VIcon>
          <p class="mb-0 mt-1 text-body-2 font-weight-medium">
            Add a new tenant
          </p>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Pagination ─────────────────────────────────────────────────── -->
    <div
      v-if="tenants.length"
      class="d-flex align-center justify-space-between flex-wrap ga-2 py-4"
    >
      <p class="text-caption text-medium-emphasis mb-0">
        Showing {{ meta.from || 0 }}–{{ meta.to || 0 }} of
        {{ meta.total || 0 }} tenants
      </p>
      <VPagination
        v-model="meta.current_page"
        :length="meta.last_page"
        :total-visible="5"
        density="comfortable"
        rounded="circle"
        @update:model-value="goToPage"
      />
    </div>

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
