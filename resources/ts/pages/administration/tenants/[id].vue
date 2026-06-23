<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const tenant = ref<any>(null);

const tierColor: Record<string, string> = { free: "default", starter: "info", professional: "primary", enterprise: "success" };
const deploymentColor: Record<string, string> = { shared: "info", dedicated: "warning", self_hosted: "secondary" };

const formatDate = (d: string | null) =>
  d ? new Date(d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "—";

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
    const { data } = await axios.post(`/api/admin/tenants/${tenant.value.id}/impersonate`);
    if (!data.tenant_domain) {
      Swal.fire({ title: "No domain", text: "Tenant has no primary domain set.", icon: "warning" });
      return;
    }
    window.open(`https://${data.tenant_domain}/impersonate?token=${data.token}`, "_blank");
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to impersonate.", icon: "error" });
  }
};

onMounted(fetchTenant);
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-5 flex-wrap gap-2">
      <VBtn variant="text" prepend-icon="mdi-arrow-left" @click="router.push('/administration/tenants')">Back</VBtn>
      <div v-if="tenant" class="d-flex gap-2">
        <VBtn variant="outlined" prepend-icon="mdi-incognito" @click="impersonate">Impersonate</VBtn>
        <VBtn color="primary" prepend-icon="mdi-pencil" @click="router.push(`/administration/tenants/${tenant.id}/edit`)">Edit</VBtn>
      </div>
    </div>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else-if="tenant">
      <VCard variant="outlined" elevation="0" class="mb-4">
        <VCardText class="pa-6">
          <div class="d-flex align-center gap-4 mb-4 flex-wrap">
            <VAvatar size="56" color="primary" variant="tonal">
              <VIcon icon="mdi-domain" size="28" />
            </VAvatar>
            <div>
              <p class="text-h6 mb-0">{{ tenant.name }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ tenant.slug }} · {{ tenant.id }}</p>
            </div>
            <VSpacer />
            <VChip :color="tenant.is_active ? 'success' : 'error'" variant="tonal">
              {{ tenant.is_active ? "Active" : "Inactive" }}
            </VChip>
          </div>

          <VDivider class="mb-4" />

          <VRow>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Deployment</p>
              <VChip :color="deploymentColor[tenant.deployment_mode]" size="small" variant="tonal" class="text-capitalize">
                {{ tenant.deployment_mode.replace("_", " ") }}
              </VChip>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Subscription Tier</p>
              <VChip :color="tierColor[tenant.subscription_tier]" size="small" variant="tonal" class="text-capitalize">
                {{ tenant.subscription_tier }}
              </VChip>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Expires</p>
              <p class="text-body-1">{{ formatDate(tenant.subscription_expires_at) }}</p>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">DB Schema</p>
              <p class="text-body-1"><code>{{ tenant.db_schema }}</code></p>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Max Flows</p>
              <p class="text-body-1">{{ tenant.max_flows }}</p>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Max Conversations / mo</p>
              <p class="text-body-1">{{ tenant.max_conversations_per_month?.toLocaleString() }}</p>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Subscription Active</p>
              <VChip :color="tenant.isSubscriptionActive ? 'success' : 'error'" size="small" variant="tonal">
                {{ tenant.isSubscriptionActive ? "Yes" : "No" }}
              </VChip>
            </VCol>
            <VCol cols="12" sm="6" md="3">
              <p class="text-caption text-medium-emphasis text-uppercase">Created</p>
              <p class="text-body-1">{{ formatDate(tenant.created_at) }}</p>
            </VCol>
          </VRow>
        </VCardText>
      </VCard>

      <VCard variant="outlined" elevation="0">
        <VCardTitle>Domains</VCardTitle>
        <VDivider />
        <VTable density="comfortable">
          <thead>
            <tr><th class="text-left pa-4">Domain</th><th class="text-left pa-4">Primary</th></tr>
          </thead>
          <tbody>
            <tr v-if="!tenant.domains?.length"><td colspan="2" class="text-center py-8 text-grey">No domains configured.</td></tr>
            <tr v-for="d in tenant.domains" :key="d.id">
              <td class="pa-4">{{ d.domain }}</td>
              <td class="pa-4">
                <VChip v-if="d.is_primary" size="small" color="primary" variant="tonal">Primary</VChip>
              </td>
            </tr>
          </tbody>
        </VTable>
      </VCard>
    </template>
  </div>
</template>
