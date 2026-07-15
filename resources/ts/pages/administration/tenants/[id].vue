<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import {
  ArrowLeft,
  VenetianMask,
  Pencil,
  Globe,
  Star,
  Database,
  Layers,
  MessageSquareText,
  CalendarDays,
  ShieldCheck,
  CircleDot,
} from "lucide-vue-next";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const tenant = ref<any>(null);

const tierMeta: Record<
  string,
  { label: string; gradient: string; chip: string }
> = {
  free: {
    label: "Free",
    gradient: "linear-gradient(135deg, #9ca3af, #d1d5db)",
    chip: "ax-chip--muted",
  },
  starter: {
    label: "Starter",
    gradient: "linear-gradient(135deg, #0ea5e9, #7dd3fc)",
    chip: "ax-chip--info",
  },
  professional: {
    label: "Professional",
    gradient: "linear-gradient(135deg, #3f7f91, #5199ae)",
    chip: "ax-chip--blue",
  },
  enterprise: {
    label: "Enterprise",
    gradient: "linear-gradient(135deg, #7c3aed, #a78bfa)",
    chip: "ax-chip--purple",
  },
};
const deploymentChipClass: Record<string, string> = {
  shared: "ax-chip--info",
  dedicated: "ax-chip--warn",
  self_hosted: "ax-chip--muted",
};

const formatDate = (d: string | null) =>
  d
    ? new Date(d).toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric",
      })
    : "—";

const nameHue = (name: string) =>
  [...(name ?? "")].reduce((a, c) => a + c.charCodeAt(0), 0) % 360;
const initials = (name: string) => (name ?? "").slice(0, 2).toUpperCase();

const fetchTenant = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/tenants/${route.params.id}`);
    tenant.value = data;
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
};

const impersonate = async () => {
  try {
    const { data } = await axios.post(
      `/api/admin/tenants/${tenant.value.id}/impersonate`,
    );
    if (!data.tenant_domain) {
      Swal.fire({
        title: "No domain",
        text: "Tenant has no primary domain set.",
        icon: "warning",
      });
      return;
    }
    window.open(
      `https://${data.tenant_domain}/impersonate?token=${data.token}`,
      "_blank",
    );
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to impersonate.",
      icon: "error",
    });
  }
};

onMounted(fetchTenant);
</script>

<template>
  <div class="ax-page">
    <div class="d-flex align-center justify-space-between mb-4 flex-wrap gap-2">
      <VBtn
        variant="text"
        class="ax-vbtn ax-vbtn--ghost"
        @click="router.push('/administration/tenants')"
      >
        <ArrowLeft :size="16" class="mr-1" /> Back
      </VBtn>
      <div v-if="tenant" class="d-flex gap-2">
        <VBtn variant="outlined" class="ax-vbtn" @click="impersonate">
          <VenetianMask :size="16" class="mr-1" /> Impersonate
        </VBtn>
        <VBtn
          color="primary"
          class="ax-vbtn"
          @click="router.push(`/administration/tenants/${tenant.id}/edit`)"
        >
          <Pencil :size="16" class="mr-1" /> Edit
        </VBtn>
      </div>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else-if="tenant">
      <!-- ── Identity banner card ─────────────────────────────────────── -->
      <div class="ax-identity-card mb-4">
        <div
          class="ax-identity-banner"
          :style="`background:${tierMeta[tenant.subscription_tier]?.gradient}`"
        ></div>
        <div class="ax-identity-body">
          <div class="d-flex align-center gap-3 mb-1">
            <div
              class="ax-avatar-lg"
              :style="`background: hsl(${nameHue(tenant.name)}, 48%, 42%)`"
            >
              {{ initials(tenant.name) }}
            </div>
            <div class="flex-fill">
              <div class="d-flex align-center gap-2">
                <p class="ax-t-name mb-0">{{ tenant.name }}</p>
                <span
                  class="ax-status-dot"
                  :class="
                    tenant.is_active
                      ? 'ax-status-dot--on'
                      : 'ax-status-dot--off'
                  "
                />
              </div>
              <p class="ax-t-slug mb-0">{{ tenant.slug }} · {{ tenant.id }}</p>
            </div>
            <span
              :class="[
                'ax-chip text-capitalize',
                tierMeta[tenant.subscription_tier]?.chip,
              ]"
            >
              {{ tierMeta[tenant.subscription_tier]?.label }}
            </span>
          </div>
        </div>
      </div>

      <!-- ── Stat cards ──────────────────────────────────────────────────── -->
      <div class="ax-stat-grid mb-4">
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(14, 165, 233, 0.12); color: #0369a1"
          >
            <Layers :size="16" />
          </div>
          <p class="ax-stat-lbl">Deployment</p>
          <span
            :class="[
              'ax-chip ax-chip--sm text-capitalize',
              deploymentChipClass[tenant.deployment_mode],
            ]"
          >
            {{ tenant.deployment_mode.replace("_", " ") }}
          </span>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(81, 153, 174, 0.12); color: #3f7f91"
          >
            <CalendarDays :size="16" />
          </div>
          <p class="ax-stat-lbl">Expires</p>
          <p class="ax-stat-val">
            {{ formatDate(tenant.subscription_expires_at) }}
          </p>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(124, 58, 237, 0.12); color: #7c3aed"
          >
            <Database :size="16" />
          </div>
          <p class="ax-stat-lbl">DB schema</p>
          <p class="ax-stat-val">
            <code>{{ tenant.db_schema }}</code>
          </p>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(245, 158, 11, 0.12); color: #b45309"
          >
            <MessageSquareText :size="16" />
          </div>
          <p class="ax-stat-lbl">Max flows</p>
          <p class="ax-stat-val">{{ tenant.max_flows }}</p>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(22, 163, 74, 0.12); color: #15803d"
          >
            <MessageSquareText :size="16" />
          </div>
          <p class="ax-stat-lbl">Max conversations / mo</p>
          <p class="ax-stat-val">
            {{ tenant.max_conversations_per_month?.toLocaleString() }}
          </p>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(22, 163, 74, 0.12); color: #15803d"
          >
            <ShieldCheck :size="16" />
          </div>
          <p class="ax-stat-lbl">Subscription active</p>
          <span
            :class="[
              'ax-chip ax-chip--sm',
              tenant.isSubscriptionActive ? 'ax-chip--ok' : 'ax-chip--danger',
            ]"
          >
            {{ tenant.isSubscriptionActive ? "Yes" : "No" }}
          </span>
        </div>
        <div class="ax-stat-card">
          <div
            class="ax-stat-icon"
            style="background: rgba(156, 163, 175, 0.15); color: #6b7280"
          >
            <CalendarDays :size="16" />
          </div>
          <p class="ax-stat-lbl">Created</p>
          <p class="ax-stat-val">{{ formatDate(tenant.created_at) }}</p>
        </div>
      </div>

      <!-- ── Domains — CARDS, not table ─────────────────────────────────── -->
      <p class="ax-card-title mb-2">Domains</p>
      <div class="ax-domain-grid">
        <div v-for="d in tenant.domains" :key="d.id" class="ax-domain-card">
          <div class="ax-domain-icon"><Globe :size="16" /></div>
          <div class="flex-fill min-w-0">
            <p class="ax-domain-name text-truncate mb-0">{{ d.domain }}</p>
            <p class="ax-t-slug mb-0">
              {{ d.is_primary ? "Primary domain" : "Secondary" }}
            </p>
          </div>
          <Star v-if="d.is_primary" :size="16" fill="#5199ae" color="#5199ae" />
        </div>
        <div
          v-if="!tenant.domains?.length"
          class="text-center py-8 text-medium-emphasis"
          style="grid-column: 1 / -1"
        >
          No domains configured.
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap");

.ax-page {
  font-family: "Outfit", sans-serif;
}
.ax-vbtn {
  text-transform: none;
  font-weight: 600;
  border-radius: 10px;
  letter-spacing: normal;
  display: inline-flex;
  align-items: center;
}
.ax-vbtn--ghost {
  color: inherit;
}

.ax-identity-card {
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 16px;
  overflow: hidden;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.ax-identity-banner {
  height: 56px;
}
.ax-identity-body {
  padding: 18px 22px 22px;
  margin-top: -34px;
}

.ax-avatar-lg {
  width: 60px;
  height: 60px;
  border-radius: 14px;
  color: #fff;
  font-size: 20px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  border: 3px solid var(--bs-body-bg, #fff);
}
.ax-t-name {
  font-weight: 700;
  font-size: 18px;
}
.ax-t-slug {
  font-size: 12px;
  color: var(--bs-secondary-color, #6b7280);
}

.ax-status-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}
.ax-status-dot--on {
  background: #16a34a;
  box-shadow: 0 0 0 3px rgba(22, 163, 74, 0.15);
}
.ax-status-dot--off {
  background: #dc2626;
  box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15);
}

.ax-stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
@media (max-width: 900px) {
  .ax-stat-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 500px) {
  .ax-stat-grid {
    grid-template-columns: 1fr;
  }
}
.ax-stat-card {
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 12px;
  padding: 14px;
  background: var(--bs-body-bg, #fff);
}
.ax-stat-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 8px;
}
.ax-stat-lbl {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--bs-secondary-color, #6b7280);
  margin: 0 0 4px;
}
.ax-stat-val {
  font-size: 13.5px;
  font-weight: 500;
  margin: 0;
}

.ax-card-title {
  font-size: 15px;
  font-weight: 700;
}

.ax-domain-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 12px;
}
.ax-domain-card {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 1px solid var(--bs-border-color, #e5e7eb);
  border-radius: 12px;
  padding: 12px 14px;
  background: var(--bs-body-bg, #fff);
  transition: box-shadow 0.14s;
}
.ax-domain-card:hover {
  box-shadow: 0 4px 14px rgba(0, 0, 0, 0.06);
}
.ax-domain-icon {
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(81, 153, 174, 0.1);
  color: #3f7f91;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ax-domain-name {
  font-size: 13px;
  font-weight: 600;
}

.ax-chip {
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  font-weight: 600;
  padding: 3px 10px;
  border-radius: 20px;
  white-space: nowrap;
}
.ax-chip--sm {
  font-size: 11px;
  padding: 3px 9px;
}
.ax-chip--ok {
  background: rgba(22, 163, 74, 0.1);
  color: #15803d;
}
.ax-chip--warn {
  background: rgba(245, 158, 11, 0.1);
  color: #b45309;
}
.ax-chip--danger {
  background: rgba(220, 38, 38, 0.1);
  color: #dc2626;
}
.ax-chip--info {
  background: rgba(14, 165, 233, 0.1);
  color: #0369a1;
}
.ax-chip--blue {
  background: rgba(81, 153, 174, 0.12);
  color: #2f6676;
}
.ax-chip--purple {
  background: rgba(124, 58, 237, 0.1);
  color: #6d28d9;
}
.ax-chip--muted {
  background: var(--bs-secondary-bg, #f1f3f5);
  color: var(--bs-secondary-color, #6b7280);
}
</style>
