<script setup lang="ts">
import { ref, onMounted, computed } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const botId = computed(() => route.params.id as string);

const bot = ref<any>(null);
const flows = ref<any[]>([]);
const isLoading = ref(true);
const search = ref("");
const statusFilter = ref("all");
const createFlowDialog = ref(false);
const snack = ref({ show: false, msg: "", color: "success" });

const newFlow = ref({ name: "", description: "", slug: "" });
const isCreating = ref(false);

const toast = (msg: string, color = "success") => {
  snack.value = { show: true, msg, color };
};

const filteredFlows = computed(() => {
  return flows.value.filter((f) => {
    const matchesSearch =
      !search.value ||
      f.name.toLowerCase().includes(search.value.toLowerCase());
    const matchesStatus =
      statusFilter.value === "all" || f.status === statusFilter.value;
    return matchesSearch && matchesStatus;
  });
});

const statusCounts = computed(() => ({
  all: flows.value.length,
  draft: flows.value.filter((f) => f.status === "draft").length,
  published: flows.value.filter((f) => f.status === "published").length,
  archived: flows.value.filter((f) => f.status === "archived").length,
}));

async function load() {
  isLoading.value = true;
  try {
    const [botRes, flowRes] = await Promise.all([
      axios.get(`/api/bots/${botId.value}`),
      axios.get(`/api/bots/${botId.value}/flows`),
    ]);
    bot.value = botRes.data.bot;
    flows.value = flowRes.data.data || flowRes.data;
  } catch {
    toast("Failed to load", "error");
  } finally {
    isLoading.value = false;
  }
}

async function createFlow() {
  if (!newFlow.value.name) return;
  isCreating.value = true;
  try {
    const res = await axios.post(`/api/bots/${botId.value}/flows`, newFlow.value);
    flows.value.unshift(res.data.flow);
    createFlowDialog.value = false;
    newFlow.value = { name: "", description: "", slug: "" };
    toast("Flow created!");
  } catch (e: any) {
    toast(e.response?.data?.message || "Failed to create flow", "error");
  } finally {
    isCreating.value = false;
  }
}

async function deleteFlow(flow: any) {
  if (!confirm(`Delete "${flow.name}"?`)) return;
  try {
    await axios.delete(`/api/bots/${botId.value}/flows/${flow.id}`);
    flows.value = flows.value.filter((f) => f.id !== flow.id);
    toast("Flow deleted");
  } catch {
    toast("Failed to delete", "error");
  }
}

function openBuilder(flowId: string) {
  router.push({ name: "flowbuilder", params: { botId: botId.value, flowId } });
}

const statusColor: Record<string, string> = {
  published: "success",
  draft: "warning",
  archived: "default",
};

onMounted(load);
</script>

<template>
  <div class="bot-flows-page" v-if="!isLoading">
    <!-- Header -->
    <div class="page-header mb-6">
      <VBtn variant="text" size="small" prepend-icon="mdi-arrow-left" @click="router.push('/bots')"
        class="mb-3 text-medium-emphasis">
        All Bots
      </VBtn>
      <div class="d-flex align-center justify-space-between flex-wrap gap-3">
        <div class="d-flex align-center gap-4">
          <div class="bot-avatar">
            <VIcon icon="mdi-robot-outline" size="28" color="primary" />
          </div>
          <div>
            <h1 class="text-h5 font-weight-bold">{{ bot?.name }}</h1>
            <div class="d-flex align-center gap-2 mt-1">
              <VChip size="x-small" :color="bot?.whatsapp_phone_number_id ? 'success' : 'default'" variant="tonal"
                prepend-icon="mdi-whatsapp">
                {{ bot?.whatsapp_phone_number_id ? 'Connected' : 'Not connected' }}
              </VChip>
              <span class="text-caption text-medium-emphasis">{{ flows.length }} flows</span>
            </div>
          </div>
        </div>
        <VBtn color="primary" rounded="lg" prepend-icon="$plus" @click="createFlowDialog = true">
          New Flow
        </VBtn>
      </div>
    </div>

    <!-- Stats row -->
    <VRow class="mb-5" dense>
      <VCol v-for="stat in [
        { label: 'Total Flows', value: statusCounts.all, icon: 'mdi-sitemap-outline', color: 'primary' },
        { label: 'Published', value: statusCounts.published, icon: 'mdi-check-circle-outline', color: 'success' },
        { label: 'Drafts', value: statusCounts.draft, icon: 'mdi-pencil-outline', color: 'warning' },
      ]" :key="stat.label" cols="12" sm="4">
        <VCard elevation="0" border rounded="xl" class="stat-card pa-4">
          <div class="d-flex align-center justify-space-between">
            <div>
              <div class="text-h4 font-weight-bold">{{ stat.value }}</div>
              <div class="text-body-2 text-medium-emphasis">{{ stat.label }}</div>
            </div>
            <VIcon :icon="stat.icon" :color="stat.color" size="28" />
          </div>
        </VCard>
      </VCol>
    </VRow>

    <!-- Filters -->
    <div class="d-flex align-center gap-3 mb-5 flex-wrap">
      <VTextField v-model="search" placeholder="Search flows..." variant="outlined" density="compact" rounded="lg"
        prepend-inner-icon="mdi-magnify" hide-details style="max-width: 280px;" />
      <div class="status-tabs">
        <VChip v-for="s in ['all', 'draft', 'published', 'archived']" :key="s" size="small"
          :variant="statusFilter === s ? 'flat' : 'outlined'" :color="statusFilter === s ? 'primary' : 'default'"
          class="mr-1" @click="statusFilter = s">
          {{ s.charAt(0).toUpperCase() + s.slice(1) }}
          <span class="ml-1 text-caption">({{ statusCounts[s as keyof typeof statusCounts] }})</span>
        </VChip>
      </div>
    </div>

    <!-- Flows grid -->
    <VRow v-if="filteredFlows.length > 0" dense>
      <VCol v-for="flow in filteredFlows" :key="flow.id" cols="12" sm="6" lg="4">
        <VCard elevation="0" border rounded="xl" class="flow-card" @click="openBuilder(flow.id)">
          <VCardText class="pa-5">
            <div class="d-flex align-start justify-space-between mb-3">
              <div class="flow-card__icon">
                <VIcon icon="mdi-sitemap-outline" size="20" color="primary" />
              </div>
              <div class="d-flex align-center gap-1">
                <VChip size="x-small" :color="statusColor[flow.status] || 'default'" variant="tonal">
                  {{ flow.status }}
                </VChip>
                <VMenu>
                  <template #activator="{ props }">
                    <VBtn icon="mdi-dots-vertical" size="x-small" variant="text" v-bind="props" @click.stop />
                  </template>
                  <VList density="compact" rounded="lg" elevation="8">
                    <VListItem prepend-icon="mdi-pencil-outline" title="Open Builder" @click="openBuilder(flow.id)" />
                    <VListItem prepend-icon="mdi-content-copy" title="Duplicate" />
                    <VDivider class="my-1" />
                    <VListItem prepend-icon="$trashCan" title="Delete" base-color="error"
                      @click.stop="deleteFlow(flow)" />
                  </VList>
                </VMenu>
              </div>
            </div>

            <h3 class="text-subtitle-1 font-weight-semibold mb-1">{{ flow.name }}</h3>
            <p class="text-body-2 text-medium-emphasis mb-3 description-clamp">{{ flow.description || 'No description'
            }}</p>

            <div class="d-flex align-center gap-3 text-caption text-medium-emphasis">
              <div class="d-flex align-center gap-1">
                <VIcon icon="mdi-source-branch" size="14" />
                <span>v{{ flow.latest_version?.version_number || 1 }}</span>
              </div>
              <div class="d-flex align-center gap-1">
                <VIcon icon="mdi-clock-outline" size="14" />
                <span>{{ new Date(flow.updated_at).toLocaleDateString() }}</span>
              </div>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Empty state -->
    <div v-else class="empty-state text-center py-16">
      <VIcon icon="mdi-sitemap-outline" size="64" color="medium-emphasis" class="mb-4" />
      <h3 class="text-h6 mb-2">No flows yet</h3>
      <p class="text-medium-emphasis mb-5">Create your first flow to start building conversations</p>
      <VBtn color="primary" rounded="lg" prepend-icon="$plus" @click="createFlowDialog = true">
        Create Flow
      </VBtn>
    </div>
  </div>

  <!-- Loading -->
  <div v-else class="d-flex align-center justify-center" style="height: 400px;">
    <VProgressCircular indeterminate color="primary" size="48" />
  </div>

  <!-- Create Flow Dialog -->
  <VDialog v-model="createFlowDialog" max-width="520" rounded="xl">
    <VCard rounded="xl" elevation="24">
      <VCardTitle class="pa-6 pb-0 d-flex align-center gap-3">
        <div class="dialog-icon bg-primary-subtle">
          <VIcon icon="mdi-sitemap-outline" color="primary" size="20" />
        </div>
        <div>
          <div class="text-h6">Create New Flow</div>
          <div class="text-caption text-medium-emphasis">Add a conversation flow to {{ bot?.name }}</div>
        </div>
      </VCardTitle>
      <VCardText class="pa-6">
        <VTextField v-model="newFlow.name" label="Flow Name *" placeholder="e.g. Customer Onboarding" variant="outlined"
          rounded="lg" class="mb-4" autofocus />
        <VTextarea v-model="newFlow.description" label="Description" placeholder="What does this flow do?"
          variant="outlined" rounded="lg" rows="3" class="mb-4" />
        <VTextField v-model="newFlow.slug" label="Slug (optional)" placeholder="customer-onboarding" variant="outlined"
          rounded="lg" hint="Auto-generated from name if left blank" persistent-hint />
      </VCardText>
      <VCardActions class="pa-6 pt-0 d-flex gap-2">
        <VSpacer />
        <VBtn variant="outlined" rounded="lg" @click="createFlowDialog = false">Cancel</VBtn>
        <VBtn color="primary" rounded="lg" :disabled="!newFlow.name" :loading="isCreating" @click="createFlow">
          Create Flow
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" :timeout="3000" location="top right" rounded="lg">
    {{ snack.msg }}
  </VSnackbar>
</template>

<style scoped>
.bot-flows-page {
  padding: 28px 24px;
}

.bot-avatar {
  width: 52px;
  height: 52px;
  border-radius: 14px;
  background: rgba(var(--v-theme-primary), 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

.stat-card {
  transition: box-shadow 0.2s;
}

.stat-card:hover {
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08) !important;
}

.flow-card {
  cursor: pointer;
  transition: all 0.2s;
}

.flow-card:hover {
  border-color: rgba(var(--v-theme-primary), 0.5) !important;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08) !important;
  transform: translateY(-1px);
}

.flow-card__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}

.description-clamp {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.dialog-icon {
  width: 40px;
  height: 40px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.bg-primary-subtle {
  background: rgba(var(--v-theme-primary), 0.1);
}

.gap-2 {
  gap: 8px;
}

.gap-3 {
  gap: 12px;
}

.gap-4 {
  gap: 16px;
}
</style>
