<!-- FlowBuilder.vue -->
<script setup lang="ts">
import { ref, computed, onMounted, watch, onBeforeUnmount } from "vue";
import { useRoute, useRouter, onBeforeRouteLeave } from "vue-router";
import axios from "axios";
import FlowNodeCard from "@/components/bots/FlowNodeCard.vue";
import AddNodeMenu from "@/components/bots/AddNodeMenu.vue";
import FlowBuilderToolbar from "@/components/bots/FlowBuilderToolbar.vue";
import FlowBuilderSidebar from "@/components/bots/ValidationPanel.vue";

import { useActionEditorStore } from "@/stores/actionEditor";
import { buildAutoSavePayload, parseDialogsFromBackend } from "@/components/bots/types";
import type { FlowNode, NodeKind, SavedResponse } from "@/components/bots/types";
import { useNodeDefaults } from "@/composables/useNodeDefaults";
import { useEditGuard } from "@/composables/useEditGuard";
import { useNodeHelpers } from "@/composables/useNodeHelpers";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();

const botId = computed(() => route.params.botId as string);
const activeFlowId = ref(route.params.flowId as string);

const { getNodeDefaults } = useNodeDefaults();
const actionEditorStore = useActionEditorStore();
const { canEdit, isReadOnly } = useEditGuard();
const { cleanDanglingReferences } = useNodeHelpers();

// ── State ─────────────────────────────────────────────────────────────────────
const bot = ref<any>(null);
const flows = ref<any[]>([]);
const flow = ref<any>(null);
const flowVersion = ref<any>(null);
const flowVersions = ref<any[]>([]);
const selectedVersionId = ref<number | null>(null);
const nodes = ref<FlowNode[]>([]);

const isLoading = ref(true);
const isLoadingFlows = ref(true);
const saveStatus = ref<"saved" | "saving" | "unsaved">("saved");
const hasUnsavedChanges = ref(false);
const availableVariables = ref<string[]>([]);
const customFunctions = ref<any[]>([]);
const apiIntegrations = ref<any[]>([]);
const expandedNodes = ref<Record<string, boolean>>({});
const snack = ref({ show: false, msg: "", color: "success" });
const createFlowDialog = ref(false);
const newFlow = ref({ name: "", description: "" });
const isCreatingFlow = ref(false);
const flowSearch = ref("");
const addMenuState = ref({ show: false, afterIndex: -1, x: 0, y: 0 });

// Auto-save timers
const EDIT_DEBOUNCE = 30_000;
const BG_SAVE_INTERVAL = 5 * 60 * 1000;
let editTimer: ReturnType<typeof setTimeout> | null = null;
let bgTimer: ReturnType<typeof setInterval> | null = null;
let isSaving = false;
// Prevents the version watcher from re-fetching when we set selectedVersionId programmatically
let suppressVersionWatch = false;

const toast = (msg: string, color = "success") => (snack.value = { show: true, msg, color });

// ── Computed ──────────────────────────────────────────────────────────────────

const readOnly = computed(() => flowVersion.value ? isReadOnly(flowVersion.value) : false);

const filteredFlows = computed(() =>
  flows.value.filter(f => !flowSearch.value || f.name.toLowerCase().includes(flowSearch.value.toLowerCase()))
);

const nodeOptions = computed(() => [
  { value: "", title: "— none —" },
  ...nodes.value.map(n => ({ value: n.id, title: `${n.kind} (#${n.id.slice(-5)})` })),
]);

const savedResponses = computed<SavedResponse[]>(() => {
  const out: SavedResponse[] = [];
  for (const node of nodes.value) {
    const short = node.id.slice(-5);
    if (node.kind === "buttons") {
      for (const btn of node.buttons ?? []) {
        if (btn.saveResponse)
          out.push({ nodeId: node.id, optionId: btn.id, label: `${btn.label} – buttons(${short})`, nodeLabel: node.label ?? node.kind });
      }
    }
    if (node.kind === "list") {
      for (const row of (node.action?.sections ?? []).flatMap(s => s.rows)) {
        if (row.saveResponse)
          out.push({ nodeId: node.id, optionId: row.id, label: `${row.title} – list(${short})`, nodeLabel: node.label ?? node.kind });
      }
    }
  }
  return out;
});

const versionColor: Record<string, string> = { published: "success", draft: "warning", locked: "default" };

const saveIndicatorColor = computed(() =>
  saveStatus.value === "saved" ? "success" : saveStatus.value === "saving" ? "info" : "warning"
);
const saveIndicatorIcon = computed(() =>
  saveStatus.value === "saved" ? "$checkCircleOutline" : saveStatus.value === "saving" ? "$loading" : "$circleMedium"
);
const saveIndicatorText = computed(() =>
  saveStatus.value === "saved" ? "Saved" : saveStatus.value === "saving" ? "Saving…" : "Unsaved"
);

// ── Smart version picker ──────────────────────────────────────────────────────

function pickBestVersion(versions: any[]): any | null {
  if (!versions.length) return null;
  const sorted = [...versions].sort((a, b) => b.version_number - a.version_number);
  const published = sorted.filter(v => v.status === "published");
  const drafts = sorted.filter(v => v.status === "draft");
  const latestPublished = published[0] ?? null;
  const newerDrafts = latestPublished
    ? drafts.filter(d => d.version_number > latestPublished.version_number)
    : drafts;
  if (newerDrafts.length) return newerDrafts[0];
  if (latestPublished) return latestPublished;
  return sorted[0];
}

// ── Auto-save ─────────────────────────────────────────────────────────────────

function scheduleEditSave() {
  if (flowVersion.value?.status === "published") return;
  if (editTimer) clearTimeout(editTimer);
  saveStatus.value = "unsaved";
  hasUnsavedChanges.value = true;
  editTimer = setTimeout(performSave, EDIT_DEBOUNCE);
}

async function performSave(force = false) {
  if (isSaving) return;
  if (!hasUnsavedChanges.value && !force) return;
  if (flowVersion.value?.status === "published") return;
  isSaving = true;
  saveStatus.value = "saving";
  try {
    await axios.post(
      `/api/bots/${botId.value}/flows/${activeFlowId.value}/builder/save`,
      buildAutoSavePayload(nodes.value)
    );
    saveStatus.value = "saved";
    hasUnsavedChanges.value = false;
  } catch {
    saveStatus.value = "unsaved";
  } finally {
    isSaving = false;
  }
}

function loadNodesFromData(data: any) {
  const raw = data.dialogs ?? data.nodes ?? [];
  nodes.value = parseDialogsFromBackend(raw);
  if (!nodes.value.find(n => n.isFirstNode) && nodes.value.length > 0)
    nodes.value[0].isFirstNode = true;
  nodes.value.forEach(n => (expandedNodes.value[n.id] = true));
}

watch(nodes, () => { if (!isLoading.value) scheduleEditSave(); }, { deep: true });

// ── Data loading ──────────────────────────────────────────────────────────────

async function loadBotFlows() {
  isLoadingFlows.value = true;
  try {
    const [botRes, flowsRes] = await Promise.all([
      axios.get(`/api/bots/${botId.value}`),
      axios.get(`/api/bots/${botId.value}/flows`),
    ]);
    bot.value = botRes.data.bot;
    flows.value = flowsRes.data.data ?? flowsRes.data;
    apiIntegrations.value = botRes.data.bot.apis ?? [];
  } finally {
    isLoadingFlows.value = false;
  }
}

async function loadFlow(flowId: string) {
  isLoading.value = true;
  try {
    await loadVersions(flowId);
    const best = pickBestVersion(flowVersions.value);

    const [defaultRes, varsRes, funcRes] = await Promise.all([
      axios.get(`/api/bots/${botId.value}/flows/${flowId}/builder`),
      axios.get(`/api/bots/${botId.value}/flows/${flowId}/builder/variables`),
      axios.get(`/api/bots/${botId.value}/flows/${flowId}/builder/functions`),
    ]);

    flow.value = defaultRes.data.flow;
    availableVariables.value = (varsRes.data.variables ?? []).map((v: any) => v.key);
    customFunctions.value = (varsRes.data.functions ?? []).map((f: any) => f.name);

    const defaultVersionId = defaultRes.data.version?.id;
    if (best && best.id !== defaultVersionId) {
      const vRes = await axios.get(
        `/api/bots/${botId.value}/flows/${flowId}/builder/versions/${best.id}`
      );
      flowVersion.value = vRes.data.version;
      loadNodesFromData(vRes.data);
    } else {
      flowVersion.value = defaultRes.data.version;
      loadNodesFromData(defaultRes.data);
    }

    suppressVersionWatch = true;
    selectedVersionId.value = flowVersion.value?.id ?? null;
    suppressVersionWatch = false;

    saveStatus.value = "saved";
    hasUnsavedChanges.value = false;
  } catch {
    toast("Failed to load flow", "error");
  } finally {
    isLoading.value = false;
  }
}

async function loadVersions(flowId: string) {
  const res = await axios.get(`/api/bots/${botId.value}/flows/${flowId}/builder/versions`);
  flowVersions.value = res.data.versions ?? [];
}

// ── Flow & Version actions ────────────────────────────────────────────────────

async function switchFlow(flowId: string) {
  if (flowId === activeFlowId.value) return;
  if (hasUnsavedChanges.value) {
    const r = await Swal.fire({
      title: "Unsaved Changes", text: "Switch flow? Unsaved changes will be lost.",
      icon: "warning", showCancelButton: true,
      confirmButtonText: "Switch", cancelButtonText: "Stay",
      confirmButtonColor: "rgb(var(--v-theme-primary))",
    });
    if (!r.isConfirmed) return;
  }
  activeFlowId.value = flowId;
  router.replace({ params: { botId: botId.value, flowId } });
  await loadFlow(flowId);
}

async function createFlow() {
  if (!newFlow.value.name) return;
  isCreatingFlow.value = true;
  try {
    const res = await axios.post(`/api/bots/${botId.value}/flows`, newFlow.value);
    flows.value.unshift(res.data.flow);
    createFlowDialog.value = false;
    newFlow.value = { name: "", description: "" };
    toast("Flow created!");
    await switchFlow(res.data.flow.id);
  } catch (e: any) {
    toast(e.response?.data?.message ?? "Failed", "error");
  } finally {
    isCreatingFlow.value = false;
  }
}

async function switchVersion(versionId: number) {
  if (!versionId || versionId === flowVersion.value?.id) return;
  isLoading.value = true;
  try {
    const res = await axios.get(
      `/api/bots/${botId.value}/flows/${activeFlowId.value}/builder/versions/${versionId}`
    );
    flowVersion.value = res.data.version;
    loadNodesFromData(res.data);
    hasUnsavedChanges.value = false;
    saveStatus.value = "saved";
    toast(`Viewing v${flowVersion.value.version_number}`);
  } catch {
    toast("Failed to switch version", "error");
  } finally {
    isLoading.value = false;
  }
}

watch(selectedVersionId, id => {
  if (suppressVersionWatch) return;
  if (id && id !== flowVersion.value?.id) switchVersion(id);
});

async function createNewVersion() {
  const r = await Swal.fire({
    title: "Create New Version", text: "Branch a new draft from the current version.",
    showCancelButton: true, confirmButtonText: "Create",
    confirmButtonColor: "rgb(var(--v-theme-primary))",
  });
  if (!r.isConfirmed) return;
  try {
    const res = await axios.post(
      `/api/bots/${botId.value}/flows/${activeFlowId.value}/builder/versions`,
      { source_version_id: flowVersion.value?.id, changelog: r.value || null }
    );
    toast("New version created!");
    await loadVersions(activeFlowId.value);
    const newVerId = res.data.version.id;
    suppressVersionWatch = true;
    selectedVersionId.value = newVerId;
    suppressVersionWatch = false;
    await switchVersion(newVerId);
  } catch (e: any) {
    toast(e.response?.data?.message ?? "Failed", "error");
  }
}

async function publish() {
  const r = await Swal.fire({
    title: "Publish Flow", text: "This makes this version live for all users.",
    icon: "warning", showCancelButton: true, confirmButtonText: "Publish",
    confirmButtonColor: "rgb(var(--v-theme-primary))",
  });
  if (!r.isConfirmed) return;
  try {
    if (hasUnsavedChanges.value) await performSave(true);
    await axios.post(`/api/bots/${botId.value}/flows/${activeFlowId.value}/builder/publish`);
    toast("Flow published!");
    await loadFlow(activeFlowId.value);
  } catch (e: any) {
    toast(e.response?.data?.message ?? "Failed to publish", "error");
  }
}

// ── Node actions ──────────────────────────────────────────────────────────────

function openAddMenu(afterIndex: number, event: MouseEvent) {
  event.stopPropagation();
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
  addMenuState.value = { show: true, afterIndex, x: rect.left, y: rect.bottom + 8 };
}

async function addNode(kind: NodeKind, afterIndex: number) {
  if (!(await canEdit(flowVersion, createNewVersion))) { addMenuState.value.show = false; return; }
  const newNode = getNodeDefaults(kind, nodes.value.length === 0);
  nodes.value.splice(afterIndex + 1, 0, newNode);
  expandedNodes.value[newNode.id] = true;
  addMenuState.value.show = false;
  toast(`${kind} node added`);
}

async function deleteNode(id: string) {
  if (!(await canEdit(flowVersion, createNewVersion))) return;
  const node = nodes.value.find(n => n.id === id);
  if (node?.isFirstNode && nodes.value.length > 1) { toast("Assign a new entry point first", "error"); return; }
  nodes.value = nodes.value.filter(n => n.id !== id);
  cleanDanglingReferences(nodes.value, id);
}

async function moveNode(index: number, direction: -1 | 1) {
  if (!(await canEdit(flowVersion, createNewVersion))) return;
  const target = index + direction;
  if (target < 0 || target >= nodes.value.length) return;
  [nodes.value[index], nodes.value[target]] = [nodes.value[target], nodes.value[index]];
}

async function setFirstNode(nodeId: string) {
  if (!(await canEdit(flowVersion, createNewVersion))) return;
  nodes.value.forEach(n => (n.isFirstNode = n.id === nodeId));
}

async function toggleHandoff(node: FlowNode) {
  if (!(await canEdit(flowVersion, createNewVersion))) return;
  node.triggersHandoff = !node.triggersHandoff;
}

async function openActionEditor(node: FlowNode, button?: any, row?: any) {
  if (!(await canEdit(flowVersion, createNewVersion))) return;
  actionEditorStore.openActionEditor({
    targetNode: node,
    targetButton: button,
    targetRow: row,
    availableVariables: availableVariables.value,
    savedResponses: savedResponses.value,
    customFunctions: customFunctions.value,
    apiIntegrations: apiIntegrations.value,
    nodeOptions: nodeOptions.value,
  });
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onBeforeRouteLeave((to, from, next) => {
  if (!hasUnsavedChanges.value) { next(); return; }
  Swal.fire({
    title: "Unsaved Changes", text: "Leave without saving?",
    icon: "warning", showCancelButton: true,
    confirmButtonText: "Leave", cancelButtonText: "Stay",
    confirmButtonColor: "rgb(var(--v-theme-primary))",
  }).then(r => r.isConfirmed ? next() : next(false));
});

onBeforeUnmount(() => {
  if (editTimer) clearTimeout(editTimer);
  if (bgTimer) clearInterval(bgTimer);
});

onMounted(async () => {
  await loadBotFlows();
  await loadFlow(activeFlowId.value);
  bgTimer = setInterval(() => performSave(), BG_SAVE_INTERVAL);
});
</script>

<template>
  <VLayout class="fill-height" style="overflow: hidden;">

    <!-- ── Sidebar ─────────────────────────────────────────────────────────── -->
    <VNavigationDrawer permanent width="240" location="left" border="end" elevation="0" class="d-flex flex-column"
      style="height:100vh;">
      <div class="shrink-0">
        <VListItem prepend-icon="$robot" :title="bot?.name ?? 'Bot'" density="compact" class="py-3" />
        <VDivider />
        <div class="pa-2">
          <VTextField v-model="flowSearch" placeholder="Search flows…" variant="outlined" density="compact" rounded="lg"
            prepend-inner-icon="$magnify" hide-details>
            <template #append-inner>
              <VTooltip text="New flow" location="top">
                <template #activator="{ props }">
                  <VIcon v-bind="props" icon="$plus" size="18" class="cursor-pointer"
                    @click.stop="createFlowDialog = true" />
                </template>
              </VTooltip>
            </template>
          </VTextField>
        </div>
        <VDivider />
      </div>

      <div class="flex-grow-1 min-h-0">
        <PerfectScrollbar class="h-100">
          <div v-if="isLoadingFlows" class="d-flex justify-center pa-8">
            <VProgressCircular size="36" indeterminate color="primary" />
          </div>
          <VList density="compact" v-else>
            <VListItem v-for="f in filteredFlows" :key="f.id" :active="f.id === activeFlowId" color="primary"
              class="mb-1" @click="switchFlow(f.id)">
              <template #prepend>
                <VChip color="primary" variant="text" size="small" rounded="lg">
                  <VIcon icon="$siteMapOutline" size="16" />
                </VChip>
              </template>
              <VListItemTitle class="text-body-2 mx-1">{{ f.name }}</VListItemTitle>
              <template #append>
                <VChip :color="f.status === 'published' ? 'success' : f.status === 'draft' ? 'warning' : 'default'"
                  size="x-small" variant="tonal">{{ f.status }}</VChip>
              </template>
            </VListItem>
            <VListItem v-if="!filteredFlows.length" disabled>
              <VListItemTitle class="text-caption text-medium-emphasis text-center py-8">
                No flows found
              </VListItemTitle>
            </VListItem>
          </VList>
        </PerfectScrollbar>
      </div>

      <div class="shrink-0">
        <VDivider />
        <VListItem class="text-medium-emphasis"
          @click="router.push({ name: 'bots-id-settings', params: { id: botId } })">
          <template #prepend>
            <VChip color="primary" variant="text" size="small" rounded="lg">
              <VIcon icon="$cogOutline" size="18" />
            </VChip>
          </template>
          <VListItemTitle class="mx-1 text-h6 font-weight-normal">Bot Settings</VListItemTitle>
        </VListItem>
      </div>
    </VNavigationDrawer>

    <!-- ── Main ───────────────────────────────────────────────────────────── -->
    <VMain class="d-flex flex-column" style="height:100vh; overflow:hidden;">

      <FlowBuilderToolbar :flow="flow" :flow-version="flowVersion" :flow-versions="flowVersions"
        :selected-version-id="selectedVersionId" :save-status="saveStatus" :save-indicator-color="saveIndicatorColor"
        :save-indicator-icon="saveIndicatorIcon" :save-indicator-text="saveIndicatorText" :version-color="versionColor"
        @update:selectedVersionId="v => { suppressVersionWatch = false; selectedVersionId = v; }"
        @createNewVersion="createNewVersion" @publish="publish" />

      <!-- Scrollable canvas -->
      <PerfectScrollbar class="flex-grow-1">
        <div style="background:rgb(var(--v-theme-background));" :style="readOnly ? { cursor: 'not-allowed' } : {}">
          <div :style="readOnly ? { pointerEvents: 'none', userSelect: 'none', opacity: '0.85' } : {}"
            style="min-height:100vh; padding-bottom:80px;">

            <div v-if="isLoading" class="d-flex flex-column align-center justify-center" style="height:70vh;">
              <VProgressCircular size="56" indeterminate color="primary" />
              <p class="mt-4 text-medium-emphasis">Loading flow…</p>
            </div>

            <div v-else-if="nodes.length === 0" class="d-flex flex-column align-center justify-center text-center"
              style="height:70vh;">
              <VIcon icon="$plusCircleOutline" size="64" color="grey-lighten-1" class="mb-4" />
              <p class="text-body-1 text-medium-emphasis mb-6">No nodes yet</p>
              <VBtn color="primary" prepend-icon="$plus" @click="openAddMenu(-1, $event)">
                Add First Node
              </VBtn>
            </div>

            <div v-else class="mx-auto py-8 px-6" style="max-width:720px;">
              <template v-for="(node, idx) in nodes" :key="node.id">
                <div v-if="idx > 0" class="d-flex flex-column align-center py-2">
                  <div class="connector-line" />
                  <VBtn icon="$plus" size="x-small" variant="outlined" rounded="circle" class="connector-add-btn my-1"
                    @click="openAddMenu(idx - 1, $event)" />
                  <div class="connector-line" />
                </div>

                <FlowNodeCard :node="node" :index="idx" :total-nodes="nodes.length"
                  :available-variables="availableVariables" :saved-responses="savedResponses"
                  :node-options="nodeOptions" :expanded="expandedNodes[node.id]" :disabled="readOnly"
                  :api-integrations="apiIntegrations" :custom-functions="customFunctions" :bot-id="botId"
                  @update:expanded="expandedNodes[node.id] = $event" @set-first-node="setFirstNode"
                  @delete-node="deleteNode" @move-node="moveNode" @open-action-editor="openActionEditor"
                  @toggle-handoff="toggleHandoff" />
              </template>

              <div class="d-flex flex-column align-center mt-6">
                <div class="connector-line" />
                <VBtn icon="$plus" variant="outlined" rounded="circle" @click="openAddMenu(nodes.length - 1, $event)" />
              </div>
            </div>
          </div>
        </div>
      </PerfectScrollbar>
    </VMain>
  </VLayout>
  
  <ValidationPanel
    :nodes="nodes"
    @validate-complete="hasErrors = $event.hasErrors"
    @focus-node="scrollToNode"
  />
  <!-- ── Overlays ─────────────────────────────────────────────────────────── -->
  <AddNodeMenu :show="addMenuState.show" :x="addMenuState.x" :y="addMenuState.y" :after-index="addMenuState.afterIndex"
    @add-node="addNode" @close="addMenuState.show = false" />

  <VDialog v-model="createFlowDialog" max-width="480">
    <VCard rounded="xl">
      <VCardTitle class="pa-6 pb-2 text-h6">New Flow</VCardTitle>
      <VCardSubtitle class="px-6 pb-0">Add a flow to {{ bot?.name }}</VCardSubtitle>
      <VCardText class="pa-6 pt-4">
        <VTextField v-model="newFlow.name" label="Flow Name *" variant="outlined" rounded="lg" class="mb-3" autofocus />
        <VTextarea v-model="newFlow.description" label="Description" variant="outlined" rounded="lg" rows="2" />
      </VCardText>
      <VCardActions class="pa-6 pt-0">
        <VSpacer />
        <VBtn variant="text" @click="createFlowDialog = false">Cancel</VBtn>
        <VBtn color="primary" rounded="lg" :disabled="!newFlow.name" :loading="isCreatingFlow" @click="createFlow">
          Create
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>

  <VSnackbar v-model="snack.show" :color="snack.color" :timeout="3000" location="top right" rounded="lg">
    {{ snack.msg }}
  </VSnackbar>
</template>

<style scoped>
.connector-line {
  width: 2px;
  height: 20px;
  background: rgba(var(--v-border-color), 0.38);
  margin: 4px 0;
}

.connector-add-btn {
  opacity: 0.5;
  transition: opacity 0.2s;
}

.connector-add-btn:hover {
  opacity: 1;
}
</style>