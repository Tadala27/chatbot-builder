<script setup lang="ts">
import { ref, computed, onMounted, watch, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { onBeforeRouteLeave } from "vue-router";
import axios from "axios";
import FlowBuilderToolbar from "@/components/flowbuilder/FlowBuilderToolbar.vue";
import FlowNodeCard from "@/components/flowbuilder/FlowNodeCard.vue";
import AddNodeMenu from "@/components/flowbuilder/AddNodeMenu.vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import type {
  FlowNode,
  NodeKind,
  SavedResponse,
} from "@/components/flowbuilder/types";
import { useNodeDefaults } from "@/composables/useNodeDefaults";
import { useEditGuard } from "@/composables/useEditGuard";
import { useNodeHelpers } from "@/composables/useNodeHelpers";
const { cleanDanglingReferences } = useNodeHelpers();
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const flowId = computed(() => route.params.id as string);

// Composables & Stores
const { getNodeDefaults } = useNodeDefaults();
const actionEditorStore = useActionEditorStore();
const { canEdit, isReadOnly } = useEditGuard();

// ─── State ──────────────────────────────────────────────────────────────────
const nodes = ref<FlowNode[]>([]);
const isLoading = ref(true);
const saveStatus = ref<"saved" | "saving" | "unsaved">("saved");
const flow = ref<any>(null);
const flowVersion = ref<any>(null);
const flowVersions = ref<any[]>([]);
const selectedVersionId = ref<number | null>(null);

const availableVariables = ref<string[]>([]);
const customFunctions = ref<any[]>([]);
const apiIntegrations = ref<any[]>([]);
const expandedNodes = ref<Record<string, boolean>>({});

// Auto-save timer
const BACKGROUND_SAVE_INTERVAL = 5 * 60 * 1000; // 5 minutes
const EDIT_DEBOUNCE_DELAY = 30000; // 15 seconds after last edit
let editDebounceTimer: NodeJS.Timeout | null = null;
let backgroundSaveTimer: NodeJS.Timeout | null = null;
let isSaving = false;
const hasUnsavedChanges = ref(false);

// ─── Menu state ─────────────────────────────────────────────────────────────
const addMenuState = ref({
  show: false,
  afterIndex: -1,
  x: 0,
  y: 0,
});

// ─── Snackbar ────────────────────────────────────────────────────────────────
const snack = ref({ show: false, msg: "", color: "success" });
const toast = (msg: string, color = "success") => {
  snack.value = { show: true, msg, color };
};

const nodeOptions = computed(() => [
  { value: "", title: "— none —" },
  ...nodes.value.map((n: any) => ({
    value: n.id,
    title: `${n.kind} (#${n.id.slice(-5)})`,
  })),
]);

// ─── Auto-Save Logic ──────────────────────────────────────────────────────────
function scheduleEditSave() {
  if (flowVersion.value?.status === "published") return;

  if (editDebounceTimer) {
    clearTimeout(editDebounceTimer);
  }

  saveStatus.value = "unsaved";
  hasUnsavedChanges.value = true;

  editDebounceTimer = setTimeout(() => {
    performSave();
  }, EDIT_DEBOUNCE_DELAY);
}

async function performSave(force = false) {
  if (isSaving) return;
  if (!hasUnsavedChanges.value && !force) return;
  if (flowVersion.value?.status === "published") return;

  isSaving = true;
  saveStatus.value = "saving";

  try {
    await axios.post(`/api/flows/${flowId.value}/auto-save`, {
      nodes: nodes.value.map((n, i) => ({
        node_id: n.id,
        node_type: n.kind,
        position_x: 0,
        position_y: i * 200,
        config: { ...n },
      })),
    });

    saveStatus.value = "saved";
    hasUnsavedChanges.value = false;
  } catch (error: any) {
    console.error("Save failed:", error);
    saveStatus.value = "unsaved";
  } finally {
    isSaving = false;
  }
}
// Watch nodes for changes
watch(
  nodes,
  () => {
    if (!isLoading.value) {
      scheduleEditSave();
    }
  },
  { deep: true },
);

function handleBeforeUnload(event: BeforeUnloadEvent) {
  if (hasUnsavedChanges.value) {
    event.preventDefault();
    event.returnValue = "";
  }
}
// Warn before leaving with unsaved changes
onBeforeRouteLeave((to, from, next) => {
  if (hasUnsavedChanges.value && saveStatus.value !== "saving") {
    Swal.fire({
      title: "Unsaved Changes",
      text: "You have unsaved changes. Are you sure you want to leave?",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Leave",
      cancelButtonText: "Stay",
      confirmButtonColor: "#d33",
      cancelButtonColor: "#3085d6",
    }).then((result) => {
      if (result.isConfirmed) {
        next();
      } else {
        next(false);
      }
    });
  } else {
    next();
  }
});

onBeforeUnmount(() => {
  if (editDebounceTimer) clearTimeout(editDebounceTimer);
  if (backgroundSaveTimer) clearInterval(backgroundSaveTimer);

  window.removeEventListener("beforeunload", handleBeforeUnload);
});
// ─── First/Last Node Management ──────────────────────────────────────────────
async function setFirstNode(nodeId: string) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  nodes.value.forEach((n) => {
    n.isFirstNode = n.id === nodeId;
  });
  toast("Entry point updated");
}

async function setLastNode(nodeId: string) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  nodes.value.forEach((n) => {
    n.isLastNode = n.id === nodeId;
  });
  toast("Exit point updated");
}

async function toggleHandoff(node: FlowNode) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  node.triggersHandoff = !node.triggersHandoff;
  if (!node.triggersHandoff) {
    node.postHandoffNode = undefined;
  }
  toast(
    node.triggersHandoff
      ? "Handoff enabled for this node"
      : "Handoff disabled for this node",
  );
}

// ─── Version Management ──────────────────────────────────────────────────────
const versionOptions = computed(() => {
  return flowVersions.value.map((v) => ({
    value: v.id,
    title: `v${v.version_number} - ${v.status} (${new Date(v.created_at).toLocaleDateString()})`,
    status: v.status,
    version_number: v.version_number,
  }));
});

const currentVersionLabel = computed(() => {
  if (!flowVersion.value) return "";
  return `v${flowVersion.value.version_number} - ${flowVersion.value.status}`;
});

async function loadVersions() {
  try {
    const response = await axios.get(`/api/flows/${flowId.value}/versions`);
    flowVersions.value = response.data.versions || [];
  } catch (error) {
    console.error("Failed to load versions:", error);
  }
}

async function switchVersion(versionId: number) {
  if (!versionId) return;

  // Check for unsaved changes
  if (hasUnsavedChanges.value) {
    const result = await Swal.fire({
      title: "Unsaved Changes",
      text: "You have unsaved changes. They will be lost if you switch versions.",
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Switch Anyway",
      cancelButtonText: "Cancel",
      confirmButtonColor: "#d33",
    });

    if (!result.isConfirmed) {
      // Revert selection
      selectedVersionId.value = flowVersion.value?.id;
      return;
    }
  }

  isLoading.value = true;
  try {
    const response = await axios.get(
      `/api/flows/${flowId.value}/versions/${versionId}`,
    );

    flowVersion.value = response.data.version;
    loadNodesFromVersion(response.data);

    toast(`Switched to version ${flowVersion.value.version_number}`);
    hasUnsavedChanges.value = false;
    saveStatus.value = "saved";
  } catch (error) {
    console.error("Failed to switch version:", error);
    toast("Failed to switch version", "error");
  } finally {
    isLoading.value = false;
  }
}

async function createNewVersion() {
  const result = await Swal.fire({
    title: "Create New Version",
    text: "This will create a new draft version from the current published version.",
    input: "textarea",
    inputLabel: "Version Notes (optional)",
    inputPlaceholder: "What changes are you planning?",
    showCancelButton: true,
    confirmButtonText: "Create Version",
    cancelButtonText: "Cancel",
    confirmButtonColor: "rgba(var(--v-theme-success))",
  });

  if (!result.isConfirmed) return;

  try {
    const response = await axios.post(
      `/api/flows/${flowId.value}/versions/new`,
      {
        notes: result.value || undefined,
        source_version_id: flowVersion.value?.id,
      },
    );

    toast("New version created!");

    // Reload to show new version
    await load();

    // Switch to new version
    const newVersion = response.data.version;
    selectedVersionId.value = newVersion.id;
    await switchVersion(newVersion.id);
  } catch (error: any) {
    console.error("Failed to create version:", error);

    if (error.response?.status === 422) {
      toast(error.response.data.message || "Cannot create version", "error");
    } else {
      toast("Failed to create version", "error");
    }
  }
}

watch(selectedVersionId, (newVersionId) => {
  if (newVersionId && newVersionId !== flowVersion.value?.id) {
    switchVersion(newVersionId);
  }
});

// ─── Node CRUD ────────────────────────────────────────────────────────────────
function openAddMenu(afterIndex: number, event: MouseEvent) {
  event.stopPropagation();
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

  addMenuState.value = {
    show: true,
    afterIndex,
    x: rect.left,
    y: rect.bottom + 8,
  };
}

function closeAddMenu() {
  addMenuState.value.show = false;
}

async function addNode(kind: NodeKind, afterIndex: number) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) {
    closeAddMenu();
    return;
  }

  const newNode = getNodeDefaults(kind, nodes.value.length === 0);

  const insertAt = afterIndex + 1;
  nodes.value.splice(insertAt, 0, newNode);
  expandedNodes.value[newNode.id] = true;
  closeAddMenu();
  toast(`${kind} node added`);
}

async function deleteNode(id: string) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  const node = nodes.value.find((n) => n.id === id);
  if (node?.isFirstNode && nodes.value.length > 1) {
    toast("Please assign a new entry point first", "error");
    return;
  }

  nodes.value = nodes.value.filter((n) => n.id !== id);
  cleanDanglingReferences(nodes.value, id);
  toast("Node deleted");
}

async function moveNode(index: number, direction: -1 | 1) {
  // Check if editing is allowed
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  const target = index + direction;
  if (target < 0 || target >= nodes.value.length) return;

  const temp = nodes.value[index];
  nodes.value[index] = nodes.value[target];
  nodes.value[target] = temp;
}

const savedResponses = computed<SavedResponse[]>(() => {
  const responses: SavedResponse[] = [];

  for (const node of nodes.value) {
    const shortId = String(node.id).slice(-5);

    // Buttons
    if (node.kind === "buttons") {
      const buttons: Btn[] = (node as any).buttons ?? [];
      for (const btn of buttons) {
        if (btn.saveResponse) {
          responses.push({
            nodeId: node.id,
            optionId: btn.id,
            label: `${btn.label} - buttons(${shortId})`,
          });
        }
      }
    }

    // List
    if (node.kind === "list") {
      const sections = node.action?.sections ?? [];
      const rows: Row[] = sections.flatMap((s) => s.rows ?? []);
      for (const row of rows) {
        if (row.saveResponse) {
          responses.push({
            nodeId: node.id,
            optionId: row.id,
            label: `${row.title} - list(${shortId})`,
          });
        }
      }
    }
  }

  console.log("Saved Responses collected:", responses);
  return responses;
});

// ─── PATCH 2: Update openActionOffCanvas to pass savedResponses ──────────────

async function openActionOffCanvas(node: FlowNode, button?: any, row?: any) {
  const allowed = await canEdit(flowVersion, createNewVersion);
  if (!allowed) return;

  console.log("Saved Responses before opening editor:", savedResponses.value); // Add this line
  console.log("openActionOffCanvas", savedResponses.value);
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
// ─── Load ──────────────────────────────────────────────────────────────────────
async function load() {
  isLoading.value = true;
  try {
    const [flowRes, varsRes] = await Promise.all([
      axios.get(`/api/flows/${flowId.value}`),
      axios.get(`/api/flows/${flowId.value}/variables`),
    ]);

    flow.value = flowRes.data.flow;
    flowVersion.value = flowRes.data.version;
    selectedVersionId.value = flowVersion.value?.id;
    availableVariables.value = varsRes.data.variables.map((v: any) => v.name);

    loadNodesFromVersion(flowRes.data);

    // Load all versions for dropdown
    await loadVersions();

    saveStatus.value = "saved";
    hasUnsavedChanges.value = false;
  } catch (error) {
    console.error("Load failed:", error);
    toast("Failed to load flow", "error");
  } finally {
    isLoading.value = false;
  }
}

function loadNodesFromVersion(data: any) {
  const rawNodes = data.nodes || [];

  nodes.value = rawNodes
    .sort((a: any, b: any) => a.position_y - b.position_y)
    .map((n: any) => {
      const config = n.config || {};
      return {
        id: n.uuid || n.id?.toString() || `node_${Date.now()}_${Math.random()}`,
        kind: mapBackendTypeToFrontend(n.type),
        ...config,
        actions: config.actions || [],
        isFirstNode: n.is_entry_point || config.isFirstNode || false,
        isLastNode: n.is_terminal || config.isLastNode || false,
        triggersHandoff: config.triggersHandoff || false,
        postHandoffNode: config.postHandoffNode || "",
        inputVariable: config.inputVariable || "",
      };
    });

  // Ensure first node is marked
  if (!nodes.value.find((n) => n.isFirstNode) && nodes.value.length > 0) {
    nodes.value[0].isFirstNode = true;
  }

  // Expand all nodes by default
  nodes.value.forEach((n) => {
    expandedNodes.value[n.id] = true;
  });
}

function mapBackendTypeToFrontend(backendType: string): NodeKind {
  const mapping: Record<string, NodeKind> = {
    message: "message",
    input: "message",
    buttons: "buttons",
    list: "list",
    media: "media",
    location: "location",
    contact: "contact",
    end: "end",
    trigger: "trigger",
  };
  return mapping[backendType] || "message";
}

// ─── Publish ──────────────────────────────────────────────────────────────────
async function publish() {
  const result = await Swal.fire({
    title: "Publish Flow",
    text: "This will make this version live. All users will see this version.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Publish",
    cancelButtonText: "Cancel",
    confirmButtonColor: "rgba(var(--v-theme-success))",
    cancelButtonColor: "rgba(var(--v-theme-error))",
  });

  if (!result.isConfirmed) return;

  try {
    // Wait for any pending auto-save to complete
    if (saveStatus.value === "saving") {
      toast("Waiting for auto-save to complete...", "info");
      await performSave(true);
    }

    await axios.post(`/api/flows/${flowId.value}/publish`);
    toast("Flow published successfully!");
    await load();
  } catch (error: any) {
    console.error("Publish failed:", error);

    if (error.response?.status === 422) {
      // Validation errors
      const errors = error.response.data.errors;
      if (errors && typeof errors === "object") {
        const errorList = Object.values(errors).flat().join("\n");
        Swal.fire({
          title: "Validation Failed",
          text: errorList,
          icon: "error",
        });
      } else {
        toast(error.response.data.message || "Validation failed", "error");
      }
    } else {
      toast("Failed to publish", "error");
    }
  }
}

onMounted(() => {
  load();

  backgroundSaveTimer = setInterval(() => {
    performSave();
  }, BACKGROUND_SAVE_INTERVAL);

  window.addEventListener("beforeunload", handleBeforeUnload);
});
</script>

<template>
  <!-- Toolbar Component -->
  <FlowBuilderToolbar
    :flow="flow"
    :flow-version="flowVersion"
    :flow-versions="flowVersions"
    :version-options="versionOptions"
    :current-version-label="currentVersionLabel"
    :selected-version-id="selectedVersionId"
    :nodes-count="nodes.length"
    :save-status="saveStatus"
    @update:selected-version-id="selectedVersionId = $event"
    @create-new-version="createNewVersion"
    @publish="publish"
    @back="router.push('/flows')"
  />

  <!-- Main Content -->
  <div>
    <!-- Loading -->
    <div v-if="isLoading" class="fill-height">
      <v-row align="center" justify="center">
        <v-col cols="auto">
          <v-progress-circular indeterminate color="primary" size="64" />
          <p class="text-center mt-4 text-grey-darken-1">Loading flow…</p>
        </v-col>
      </v-row>
    </div>

    <!-- Flow Canvas -->
    <div v-else fluid class="py-8">
      <PerfectScrollbar style="height: calc(100vh - 150px)">
        <v-row justify="center">
          <v-col cols="12" md="6" lg="6" xl="6">
            <!-- Warning for published version -->
            <VAlert
              v-if="flowVersion?.status === 'published'"
              type="info"
              variant="tonal"
              density="compact"
              class="mb-4 border-solid border-info border-thin border-opacity-25"
              icon="false"
              rounded="md"
            >
              <template #prepend>
                <SvgSprite
                  name="custom-warning-fill"
                  style="width: 20px; height: 20px"
                />
              </template>
              <div class="text-caption">
                This version is live. Create a new version to make changes.
              </div>
            </VAlert>

            <!-- Add first node button -->
            <div class="text-center" v-if="nodes.length === 0">
              <v-btn
                variant="outlined"
                color="primary"
                prepend-icon="$plus"
                @click="openAddMenu(-1, $event)"
                size="small"
              >
                Add First Node
              </v-btn>
            </div>

            <!-- Node List -->
            <template v-for="(node, idx) in nodes" :key="node.id">
              <!-- Connector line -->
              <v-divider
                v-if="idx > 0"
                class="my-2"
                :thickness="2"
                color="grey-lighten-2"
              />

              <!-- Node Card Component -->
              <FlowNodeCard
                :node="node"
                :index="idx"
                :total-nodes="nodes.length"
                :available-variables="availableVariables"
                :saved-responses="savedResponses"
                :node-options="nodeOptions"
                :expanded="expandedNodes[node.id]"
                :disabled="isReadOnly(flowVersion)"
                @update:expanded="expandedNodes[node.id] = $event"
                @set-first-node="setFirstNode"
                @set-last-node="setLastNode"
                @delete-node="deleteNode"
                @move-node="moveNode"
                @open-action-editor="openActionOffCanvas"
                @toggle-handoff="toggleHandoff"
              />

              <!-- Add node between -->
              <div class="text-center my-2">
                <v-btn
                  variant="outlined"
                  size="x-small"
                  icon="$plus"
                  @click="openAddMenu(idx, $event)"
                />
              </div>
            </template>
          </v-col>
        </v-row>
      </PerfectScrollbar>
    </div>
  </div>

  <!-- Add Node Menu Component -->
  <AddNodeMenu
    :show="addMenuState.show"
    :x="addMenuState.x"
    :y="addMenuState.y"
    :after-index="addMenuState.afterIndex"
    @add-node="addNode"
    @close="closeAddMenu"
  />

  <!-- Snackbar -->
  <v-snackbar
    v-model="snack.show"
    :color="snack.color"
    :timeout="3000"
    location="top right"
  >
    {{ snack.msg }}
    <template #actions>
      <v-btn variant="text" @click="snack.show = false">OK</v-btn>
    </template>
  </v-snackbar>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}
</style>
