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
  { label: string; icon: string; gradient: string; chip: string }
> = {
  free: {
    label: "Free",
    icon: "mdi-leaf",
    gradient: "linear-gradient(135deg, #9ca3af, #d1d5db)",
    chip: "tc-chip--muted",
  },
  starter: {
    label: "Starter",
    icon: "mdi-rocket-launch-outline",
    gradient: "linear-gradient(135deg, #0ea5e9, #7dd3fc)",
    chip: "tc-chip--info",
  },
  professional: {
    label: "Professional",
    icon: "mdi-briefcase-variant",
    gradient: "linear-gradient(135deg, #3f7f91, #5199ae)",
    chip: "tc-chip--blue",
  },
  enterprise: {
    label: "Enterprise",
    icon: "mdi-castle",
    gradient: "linear-gradient(135deg, #7c3aed, #a78bfa)",
    chip: "tc-chip--purple",
  },
};

const deploymentMeta: Record<string, { label: string; icon: string }> = {
  shared: { label: "Shared", icon: "mdi-account-group-outline" },
  dedicated: { label: "Dedicated", icon: "mdi-server" },
  self_hosted: { label: "Self-hosted", icon: "mdi-home-city-outline" },
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

const expiryTone = (tenant: Tenant) => {
  const d = daysLeft(tenant);
  if (d === null) return "tc-expiry--ok";
  if (d < 0) return "tc-expiry--danger";
  if (d <= 14) return "tc-expiry--warn";
  return "tc-expiry--ok";
};

const visiblePages = computed(() => {
  const total = meta.value.last_page ?? 1;
  const current = meta.value.current_page ?? 1;
  const pages = [];
  for (let p = Math.max(1, current - 2); p <= Math.min(total, current + 2); p++)
    pages.push(p);
  return pages;
});

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
  <div class="tc-page">
    <!-- ── Header ─────────────────────────────────────────────────────── -->
    <div class="tc-header mb-4">
      <div>
        <h1 class="tc-title">Tenants</h1>
        <p class="tc-subtitle">
          Every organisation running on your platform, at a glance.
        </p>
      </div>
      <VBtn
        color="primary"
        prepend-icon="mdi-plus"
        class="tc-vbtn"
        @click="createTenant"
        >Add Tenant</VBtn
      >
    </div>

    <!-- ── Stat strip ─────────────────────────────────────────────────── -->
    <div v-if="stats" class="tc-stat-row mb-4">
      <div class="tc-stat-pill">
        <div
          class="tc-stat-icon"
          style="background: rgba(81, 153, 174, 0.12); color: #3f7f91"
        >
          <VIcon size="18">mdi-domain</VIcon>
        </div>
        <div>
          <p class="tc-stat-val">{{ stats.total }}</p>
          <p class="tc-stat-lbl">Total tenants</p>
        </div>
      </div>
      <div class="tc-stat-pill">
        <div
          class="tc-stat-icon"
          style="background: rgba(22, 163, 74, 0.12); color: #15803d"
        >
          <VIcon size="18">mdi-check-decagram</VIcon>
        </div>
        <div>
          <p class="tc-stat-val">{{ stats.active }}</p>
          <p class="tc-stat-lbl">Active</p>
        </div>
      </div>
      <div class="tc-stat-pill">
        <div
          class="tc-stat-icon"
          style="background: rgba(245, 158, 11, 0.12); color: #b45309"
        >
          <VIcon size="18">mdi-clock-alert-outline</VIcon>
        </div>
        <div>
          <p class="tc-stat-val">{{ stats.expiring_soon }}</p>
          <p class="tc-stat-lbl">Expiring soon</p>
        </div>
      </div>
      <div class="tc-stat-pill">
        <div
          class="tc-stat-icon"
          style="background: rgba(220, 38, 38, 0.12); color: #dc2626"
        >
          <VIcon size="18">mdi-alert-circle-outline</VIcon>
        </div>
        <div>
          <p class="tc-stat-val">{{ stats.expired }}</p>
          <p class="tc-stat-lbl">Expired</p>
        </div>
      </div>
    </div>

    <!-- ── Filter bar ─────────────────────────────────────────────────── -->
    <div class="tc-filter-bar mb-4">
      <div class="tc-search-wrap">
        <VIcon icon="mdi-magnify" size="18" />
        <input
          v-model="search"
          type="text"
          placeholder="Search tenants by name or slug…"
          class="tc-search-input"
        />
      </div>
      <select v-model="isActiveFilter" class="tc-select">
        <option v-for="o in statusOptions" :key="o" :value="o">{{ o }}</option>
      </select>
      <select v-model="tierFilter" class="tc-select text-capitalize">
        <option v-for="o in tierOptions" :key="o" :value="o">{{ o }}</option>
      </select>
      <VBtn
        variant="text"
        class="tc-vbtn tc-vbtn--ghost"
        size="small"
        @click="clearFilters"
        >Clear</VBtn
      >
    </div>

    <!-- ── Loading ────────────────────────────────────────────────────── -->
    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <!-- ── Empty state ────────────────────────────────────────────────── -->
    <div v-else-if="!tenants.length" class="tc-empty">
      <div class="tc-empty-icon"><VIcon size="30">mdi-domain-off</VIcon></div>
      <p class="tc-empty-title">No tenants found</p>
      <p class="tc-empty-sub">
        {{
          search ? "Try adjusting your filters" : "No tenants registered yet"
        }}
      </p>
      <VBtn
        variant="outlined"
        class="tc-vbtn mt-2"
        size="small"
        @click="clearFilters"
        >Clear filters</VBtn
      >
    </div>

    <!-- ── Card grid ──────────────────────────────────────────────────── -->
    <div v-else class="tc-grid">
      <div
        v-for="tenant in tenants"
        :key="tenant.id"
        class="tc-card"
        :class="!tenant.is_active ? 'tc-card--inactive' : ''"
      >
        <!-- top banner strip in tier gradient -->
        <div
          class="tc-card-banner"
          :style="`background:${tierMeta[tenant.subscription_tier]?.gradient}`"
        >
          <div class="tc-card-banner-icon">
            <VIcon size="16" color="white">{{
              tierMeta[tenant.subscription_tier]?.icon
            }}</VIcon>
          </div>
          <span class="tc-card-banner-label">{{
            tierMeta[tenant.subscription_tier]?.label
          }}</span>
          <VMenu location="bottom end">
            <template #activator="{ props }">
              <button v-bind="props" class="tc-card-menu-btn" @click.stop>
                <VIcon size="18" color="white">mdi-dots-vertical</VIcon>
              </button>
            </template>
            <VList density="compact">
              <VListItem @click="viewTenant(tenant.id)">
                <template #prepend
                  ><VIcon size="small">mdi-eye</VIcon></template
                >
                <VListItemTitle>View details</VListItemTitle>
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
                <template #prepend
                  ><VIcon size="small">{{
                    tenant.is_active ? "mdi-cancel" : "mdi-check-circle"
                  }}</VIcon></template
                >
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
        </div>

        <div class="tc-card-body" @click="viewTenant(tenant.id)">
          <div class="d-flex align-center gap-3 mb-3">
            <div
              class="tc-avatar"
              :style="`background: hsl(${nameHue(tenant.name)}, 55%, 45%)`"
            >
              {{ getInitials(tenant.name) }}
            </div>
            <div class="flex-fill min-w-0">
              <p class="tc-t-name text-truncate mb-0">{{ tenant.name }}</p>
              <p class="tc-t-slug text-truncate mb-0">{{ tenant.slug }}</p>
            </div>
            <span
              v-if="!tenant.is_active"
              class="tc-status-dot tc-status-dot--off"
              title="Inactive"
            />
            <span
              v-else
              class="tc-status-dot tc-status-dot--on"
              title="Active"
            />
          </div>

          <div class="tc-domain-row">
            <VIcon size="14" color="#9ca3af">mdi-web</VIcon>
            <span v-if="primaryDomain(tenant)" class="text-truncate">{{
              primaryDomain(tenant)
            }}</span>
            <span v-else class="text-medium-emphasis">No domain set</span>
          </div>

          <div class="tc-mini-chip-row">
            <span
              class="tc-chip tc-chip--sm"
              :class="tenant.is_active ? 'tc-chip--ok' : 'tc-chip--danger'"
            >
              {{ tenant.is_active ? "Active" : "Inactive" }}
            </span>
            <span class="tc-chip tc-chip--sm tc-chip--muted">
              <VIcon size="11" class="mr-1">{{
                deploymentMeta[tenant.deployment_mode]?.icon
              }}</VIcon>
              {{ deploymentMeta[tenant.deployment_mode]?.label }}
            </span>
          </div>

          <div class="tc-expiry-row" :class="expiryTone(tenant)">
            <VIcon size="14">{{
              daysLeft(tenant) !== null && daysLeft(tenant)! < 0
                ? "mdi-alert-circle"
                : "mdi-calendar-clock"
            }}</VIcon>
            <span>{{ formatDate(tenant.subscription_expires_at) }}</span>
            <span
              v-if="daysLeft(tenant) !== null && daysLeft(tenant)! >= 0"
              class="tc-expiry-days"
            >
              · {{ daysLeft(tenant) }}d left
            </span>
          </div>
        </div>

        <div class="tc-card-footer">
          <button class="tc-footer-btn" @click.stop="viewTenant(tenant.id)">
            <VIcon size="15">mdi-eye-outline</VIcon> View
          </button>
          <div class="tc-footer-divider" />
          <button class="tc-footer-btn" @click.stop="editTenant(tenant.id)">
            <VIcon size="15">mdi-pencil-outline</VIcon> Edit
          </button>
          <div class="tc-footer-divider" />
          <button class="tc-footer-btn" @click.stop="impersonate(tenant)">
            <VIcon size="15">mdi-incognito</VIcon> Enter
          </button>
        </div>
      </div>

      <!-- ── Add-tenant ghost card ──────────────────────────────────────── -->
      <div class="tc-add-card" @click="createTenant">
        <VIcon size="30">mdi-plus-circle-outline</VIcon>
        <p class="mb-0 mt-1">Add a new tenant</p>
      </div>
    </div>

    <!-- ── Pagination ─────────────────────────────────────────────────── -->
    <div v-if="tenants.length" class="tc-pagination">
      <p class="tc-t-slug mb-0">
        Showing {{ meta.from || 0 }}–{{ meta.to || 0 }} of
        {{ meta.total || 0 }} tenants
      </p>
      <div class="d-flex align-center gap-1">
        <button
          class="tc-page-btn"
          :disabled="meta.current_page === 1"
          @click="goToPage(meta.current_page - 1)"
        >
          <VIcon size="16">mdi-chevron-left</VIcon>
        </button>
        <button
          v-if="meta.current_page > 3"
          class="tc-page-btn"
          @click="goToPage(1)"
        >
          1
        </button>
        <span v-if="meta.current_page > 3" class="tc-t-slug px-1">…</span>
        <button
          v-for="p in visiblePages"
          :key="p"
          :class="['tc-page-btn', p === meta.current_page ? 'active' : '']"
          @click="goToPage(p)"
        >
          {{ p }}
        </button>
        <span
          v-if="meta.current_page < meta.last_page - 2"
          class="tc-t-slug px-1"
          >…</span
        >
        <button
          v-if="meta.current_page < meta.last_page - 2"
          class="tc-page-btn"
          @click="goToPage(meta.last_page)"
        >
          {{ meta.last_page }}
        </button>
        <button
          class="tc-page-btn"
          :disabled="meta.current_page === meta.last_page"
          @click="goToPage(meta.current_page + 1)"
        >
          <VIcon size="16">mdi-chevron-right</VIcon>
        </button>
      </div>
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

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap");

.tc-page {
  font-family: "Outfit", sans-serif;
}

.tc-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
}
.tc-title {
  font-size: 24px;
  font-weight: 800;
  margin: 0 0 4px;
}
.tc-subtitle {
  font-size: 13px;
  color: var(--bs-secondary-color, #6b7280);
  margin: 0;
}

.tc-vbtn {
  text-transform: none;
  font-weight: 600;
  border-radius: 10px;
  letter-spacing: normal;
}
.tc-vbtn--ghost {
  color: inherit;
}

/* Stat pills */
.tc-stat-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
}
.tc-stat-pill {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bs-body-bg, #fff);
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 999px;
  padding: 8px 18px 8px 8px;
  flex: 1 1 200px;
}
.tc-stat-icon {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tc-stat-val {
  font-size: 17px;
  font-weight: 800;
  margin: 0;
  line-height: 1;
}
.tc-stat-lbl {
  font-size: 11px;
  color: var(--bs-secondary-color, #6b7280);
  margin: 2px 0 0;
}

/* Filter bar */
.tc-filter-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.tc-search-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--bs-secondary-bg, #f8f9fb);
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 999px;
  padding: 9px 16px;
  flex: 1;
  min-width: 220px;
  max-width: 340px;
  color: var(--bs-secondary-color, #6b7280);
}
.tc-search-input {
  border: none;
  background: transparent;
  outline: none;
  font-size: 13px;
  width: 100%;
  font-family: "Outfit", sans-serif;
}
.tc-select {
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 999px;
  background: var(--bs-secondary-bg, #f8f9fb);
  font-size: 13px;
  padding: 9px 14px;
  cursor: pointer;
  outline: none;
  font-family: "Outfit", sans-serif;
}

/* Empty state */
.tc-empty {
  text-align: center;
  padding: 60px 20px;
}
.tc-empty-icon {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  margin: 0 auto 12px;
  background: rgba(81, 153, 174, 0.1);
  color: #3f7f91;
  display: flex;
  align-items: center;
  justify-content: center;
}
.tc-empty-title {
  font-weight: 700;
  font-size: 16px;
  margin: 0;
}
.tc-empty-sub {
  font-size: 13px;
  color: var(--bs-secondary-color, #6b7280);
  margin: 2px 0 0;
}

/* Grid */
.tc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 18px;
}

/* Card */
.tc-card {
  background: var(--bs-body-bg, #fff);
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 16px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.tc-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 24px rgba(0, 0, 0, 0.09);
}
.tc-card--inactive {
  opacity: 0.62;
}

.tc-card-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  color: #fff;
}
.tc-card-banner-icon {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.22);
  display: flex;
  align-items: center;
  justify-content: center;
}
.tc-card-banner-label {
  font-size: 12px;
  font-weight: 700;
  flex: 1;
}
.tc-card-menu-btn {
  background: rgba(255, 255, 255, 0.18);
  border: none;
  border-radius: 8px;
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.tc-card-menu-btn:hover {
  background: rgba(255, 255, 255, 0.32);
}

.tc-card-body {
  padding: 16px;
  cursor: pointer;
  flex: 1;
}

.tc-avatar {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  color: #fff;
  font-size: 15px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tc-t-name {
  font-weight: 700;
  font-size: 14.5px;
}
.tc-t-slug {
  font-size: 11.5px;
  color: var(--bs-secondary-color, #6b7280);
}

.tc-status-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  flex-shrink: 0;
}
.tc-status-dot--on {
  background: #16a34a;
  box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15);
}
.tc-status-dot--off {
  background: #dc2626;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}

.tc-domain-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  color: var(--bs-secondary-color, #6b7280);
  background: var(--bs-secondary-bg, #f8f9fb);
  border-radius: 8px;
  padding: 7px 10px;
  margin-bottom: 10px;
}

.tc-mini-chip-row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}

.tc-expiry-row {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
}
.tc-expiry--ok {
  color: #15803d;
}
.tc-expiry--warn {
  color: #b45309;
}
.tc-expiry--danger {
  color: #dc2626;
}
.tc-expiry-days {
  color: var(--bs-secondary-color, #6b7280);
  font-weight: 500;
}

.tc-card-footer {
  display: flex;
  border-top: 1px solid var(--bs-border-color, #e5e7eb);
}
.tc-footer-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  border: none;
  background: transparent;
  padding: 10px 4px;
  font-size: 12px;
  font-weight: 600;
  color: var(--bs-secondary-color, #6b7280);
  cursor: pointer;
  font-family: "Outfit", sans-serif;
  transition:
    background 0.12s,
    color 0.12s;
}
.tc-footer-btn:hover {
  background: rgba(81, 153, 174, 0.08);
  color: #3f7f91;
}
.tc-footer-divider {
  width: 1px;
  background: var(--bs-border-color, #e5e7eb);
}

/* Add-tenant ghost card */
.tc-add-card {
  border: 2px dashed var(--bs-border-color, #d1d5db);
  border-radius: 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  color: var(--bs-secondary-color, #6b7280);
  cursor: pointer;
  min-height: 180px;
  font-size: 13px;
  font-weight: 600;
  transition:
    border-color 0.12s,
    color 0.12s,
    background 0.12s;
}
.tc-add-card:hover {
  border-color: #5199ae;
  color: #3f7f91;
  background: rgba(81, 153, 174, 0.05);
}

/* Chips */
.tc-chip {
  display: inline-flex;
  align-items: center;
  font-weight: 600;
  border-radius: 20px;
  white-space: nowrap;
}
.tc-chip--sm {
  font-size: 10.5px;
  padding: 3px 9px;
}
.tc-chip--ok {
  background: rgba(22, 163, 74, 0.1);
  color: #15803d;
}
.tc-chip--warn {
  background: rgba(245, 158, 11, 0.1);
  color: #b45309;
}
.tc-chip--danger {
  background: rgba(220, 38, 38, 0.1);
  color: #dc2626;
}
.tc-chip--info {
  background: rgba(14, 165, 233, 0.1);
  color: #0369a1;
}
.tc-chip--blue {
  background: rgba(81, 153, 174, 0.12);
  color: #2f6676;
}
.tc-chip--purple {
  background: rgba(124, 58, 237, 0.1);
  color: #6d28d9;
}
.tc-chip--muted {
  background: var(--bs-secondary-bg, #f1f3f5);
  color: var(--bs-secondary-color, #6b7280);
}

/* Pagination */
.tc-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 4px;
  flex-wrap: wrap;
  gap: 8px;
}
.tc-page-btn {
  min-width: 32px;
  height: 32px;
  border-radius: 8px;
  border: 1px solid var(--bs-border-color, #e5e7eb);
  background: var(--bs-body-bg, #fff);
  color: var(--bs-secondary-color, #6b7280);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 13px;
  font-family: "Outfit", sans-serif;
  padding: 0 6px;
  transition: background 0.12s;
}
.tc-page-btn:hover:not(:disabled) {
  background: var(--bs-secondary-bg, #f8f9fb);
}
.tc-page-btn.active {
  background: #5199ae;
  color: #fff;
  border-color: #5199ae;
  font-weight: 600;
}
.tc-page-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
</style>
