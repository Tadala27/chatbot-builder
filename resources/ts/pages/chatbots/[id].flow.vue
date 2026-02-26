<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import ActionEditor from "@/components/ActionEditor.vue";
import RichTextEditor from "../../components/RichTextEditor.vue";
import RichTextField from "../../components/RichTextField.vue";

const route = useRoute();
const router = useRouter();
const flowId = computed(() => route.params.id as string);

// ─── Types ─────────────────────────────────────────────────────────────────
type NodeKind = "trigger" | "message" | "buttons" | "list" | "media" | "end";
type ActionKind =
  | "navigation"
  | "condition"
  | "function"
  | "variable"
  | "delay"
  | "api";

interface ActionDef {
  kind: ActionKind;
  goTo?: string;
  variable?: string;
  operator?: string;
  condValue?: string;
  trueGoTo?: string;
  falseGoTo?: string;
  fnId?: string;
  paramsRaw?: string;
  resultVar?: string;
  varName?: string;
  varValue?: string;
  dataType?: string;
  seconds?: number;
  endpoint?: string;
  method?: string;
  bodyRaw?: string;
  apiResultVar?: string;
  apiConfigId?: string;
}

interface Btn {
  id: string;
  label: string;
  actions: ActionDef[];
  saveVariable?: string;
}

interface Row {
  id: string;
  title: string;
  description?: string;
  actions: ActionDef[];
  saveVariable?: string;
}

interface Section {
  title: string;
  rows: Row[];
}

interface ListAction {
  button: string;
  sections: Section[];
}

// ============================================================================
// ENHANCED MEDIA NODE - Add to FlowBuilder.vue
// ============================================================================

// 1. UPDATE TYPES
interface FlowNode {
  id: string;
  kind: NodeKind;
  text?: string;
  btnText?: string;
  buttons?: Btn[];
  listHeader?: string;
  listBody?: string;
  action?: ListAction;
  actionButton?: string;
  sections?: Section[];

  // ✅ ENHANCED MEDIA FIELDS
  mediaType?: "image" | "video" | "audio" | "document";
  mediaUrl?: string;
  mediaCaption?: string;
  mediaFilename?: string; // For documents

  // ✅ LOCATION FIELDS
  locationLatitude?: number;
  locationLongitude?: number;
  locationName?: string;
  locationAddress?: string;

  // ✅ CONTACT FIELDS
  contactData?: {
    name: {
      formatted_name: string;
      first_name?: string;
      last_name?: string;
    };
    phones?: Array<{
      phone: string;
      type?: string;
      wa_id?: string;
    }>;
    emails?: Array<{
      email: string;
      type?: string;
    }>;
    addresses?: Array<{
      street?: string;
      city?: string;
      state?: string;
      zip?: string;
      country?: string;
      country_code?: string;
      type?: string;
    }>;
    urls?: Array<{
      url: string;
      type?: string;
    }>;
    org?: {
      company?: string;
      department?: string;
      title?: string;
    };
    birthday?: string;
  };

  goTo?: string;
  actions?: ActionDef[];
  isFirstNode?: boolean;
  isLastNode?: boolean;
  triggersHandoff?: boolean;
  postHandoffNode?: string;
  inputVariable?: string;
}

// ─── State ──────────────────────────────────────────────────────────────────
const nodes = ref<FlowNode[]>([]);
const isLoading = ref(true);
const isSaving = ref(false);
const flow = ref<any>(null);
const flowVersion = ref<any>(null);

const availableVariables = ref<string[]>([]);
const customFunctions = ref<any[]>([]);
const apiIntegrations = ref<any[]>([]);

// ─── Expanded states ────────────────────────────────────────────────────────
const expandedNodes = ref<Record<string, boolean>>({});

// ─── Off-canvas state ───────────────────────────────────────────────────────
const offCanvas = ref({
  show: false,
  targetNode: null as FlowNode | null,
  targetButton: null as Btn | null,
  targetRow: null as Row | null,
});

// ─── Menu state ─────────────────────────────────────────────────────────────
const addMenuState = ref({
  show: false,
  afterIndex: -1,
  x: 0,
  y: 0,
});
const menuStep = ref<"root" | "message" | "interactive">("root");

// ─── Snackbar ────────────────────────────────────────────────────────────────
const snack = ref({ show: false, msg: "", color: "success" });
const toast = (msg: string, color = "success") => {
  snack.value = { show: true, msg, color };
};

// ─── Helpers ──────────────────────────────────────────────────────────────────
let seq = 0;
const uid = () => `n${Date.now()}${seq++}`;

const nodeById = (id: string) => nodes.value.find((n) => n.id === id);
const nodeOptions = computed(() => [
  { value: "", title: "— none —" },
  ...nodes.value.map((n) => ({
    value: n.id,
    title: `${KINDS[n.kind].label} (#${n.id.slice(-4)})`,
  })),
]);

const firstNode = computed(() => nodes.value.find((n) => n.isFirstNode));
const lastNode = computed(() => nodes.value.find((n) => n.isLastNode));

// ─── Visual config ───────────────────────────────────────────────────────────
// 2. UPDATE VISUAL CONFIG
const KINDS: Record<
  NodeKind,
  { label: string; color: string; icon: string; desc: string }
> = {
  trigger: {
    label: "Trigger",
    color: "#10b981",
    icon: "$lightningBoltOutline",
    desc: "Entry point",
  },
  message: {
    label: "Message",
    color: "#3b82f6",
    icon: "$messageText",
    desc: "Send a text",
  },
  buttons: {
    label: "Buttons",
    color: "#8b5cf6",
    icon: "$radioboxMarked",
    desc: "Quick reply",
  },
  list: {
    label: "List",
    color: "#06b6d4",
    icon: "$formatListBulleted",
    desc: "Scrollable menu",
  },
  media: {
    label: "Media",
    color: "#f59e0b",
    icon: "$imageOutline",
    desc: "Image/video/doc",
  },
  location: {
    label: "Location",
    color: "#ec4899",
    icon: "$mapMarker",
    desc: "Send location",
  },
  contact: {
    label: "Contact",
    color: "#14b8a6",
    icon: "$accountCard",
    desc: "Share contact",
  },
  end: {
    label: "End",
    color: "#ef4444",
    icon: "$flagCheckered",
    desc: "Close flow",
  },
};

// ─── First/Last Node Management ──────────────────────────────────────────────
function setFirstNode(nodeId: string) {
  nodes.value.forEach((n) => {
    n.isFirstNode = n.id === nodeId;
  });
  toast("Entry point updated");
}

function setLastNode(nodeId: string) {
  nodes.value.forEach((n) => {
    n.isLastNode = n.id === nodeId;
  });
  toast("Exit point updated");
}

function toggleHandoff(node: FlowNode) {
  node.triggersHandoff = !node.triggersHandoff;
  if (!node.triggersHandoff) {
    node.postHandoffNode = undefined;
  }
}

// ─── Node CRUD ────────────────────────────────────────────────────────────────
// 3. UPDATE spawnNode DEFAULTS
function spawnNode(kind: NodeKind, afterIndex: number) {
  const defaults: Partial<Record<NodeKind, Partial<FlowNode>>> = {
    trigger: {
      text: "keyword",
      mediaCaption: "",
      inputVariable: "",
      goTo: "",
    },
    message: { text: "", inputVariable: "" },
    buttons: {
      btnText: "",
      buttons: [
        {
          id: uid(),
          label: "Option 1",
          actions: [{ kind: "navigation", goTo: "" }],
          saveVariable: "",
        },
        {
          id: uid(),
          label: "Option 2",
          actions: [{ kind: "navigation", goTo: "" }],
          saveVariable: "",
        },
      ],
    },
    list: {
      listHeader: "",
      listBody: "",
      action: {
        button: "View Options",
        sections: [
          {
            title: "Section 1",
            rows: [
              {
                id: uid(),
                title: "Item 1",
                description: "",
                actions: [{ kind: "navigation", goTo: "" }],
                saveVariable: "",
              },
              {
                id: uid(),
                title: "Item 2",
                description: "",
                actions: [{ kind: "navigation", goTo: "" }],
                saveVariable: "",
              },
            ],
          },
        ],
      },
    },
    media: {
      mediaType: "image",
      mediaUrl: "",
      mediaCaption: "",
      mediaFilename: "",
      goTo: "",
    },
    location: {
      locationLatitude: 0,
      locationLongitude: 0,
      locationName: "",
      locationAddress: "",
      goTo: "",
    },
    contact: {
      contactData: {
        name: {
          formatted_name: "",
          first_name: "",
          last_name: "",
        },
        phones: [{ phone: "", type: "Mobile", wa_id: "" }],
        emails: [],
        addresses: [],
        urls: [],
      },
      goTo: "",
    },
    end: { text: "Thanks! Goodbye 👋" },
  };

  const node: FlowNode = {
    id: uid(),
    kind,
    goTo: "",
    actions: [],
    isFirstNode: nodes.value.length === 0,
    isLastNode: false,
    triggersHandoff: false,
    ...defaults[kind],
  };

  const insertAt = afterIndex + 1;
  nodes.value.splice(insertAt, 0, node);
  expandedNodes.value[node.id] = true;
  closeAddMenu();
}

function deleteNode(id: string) {
  const node = nodeById(id);
  if (node?.isFirstNode && nodes.value.length > 1) {
    toast("Please assign a new entry point first", "error");
    return;
  }

  nodes.value = nodes.value.filter((n) => n.id !== id);

  // Clean dangling references
  nodes.value.forEach((n) => {
    if (n.goTo === id) n.goTo = "";
    if (n.postHandoffNode === id) n.postHandoffNode = "";

    n.buttons?.forEach((b) => {
      b.actions.forEach((a) => {
        if (a.goTo === id) a.goTo = "";
        if (a.trueGoTo === id) a.trueGoTo = "";
        if (a.falseGoTo === id) a.falseGoTo = "";
      });
    });

    n.action?.sections?.forEach((s) =>
      s.rows.forEach((r) => {
        r.actions.forEach((a) => {
          if (a.goTo === id) a.goTo = "";
          if (a.trueGoTo === id) a.trueGoTo = "";
          if (a.falseGoTo === id) a.falseGoTo = "";
        });
      }),
    );

    n.actions?.forEach((a) => {
      if (a.goTo === id) a.goTo = "";
      if (a.trueGoTo === id) a.trueGoTo = "";
      if (a.falseGoTo === id) a.falseGoTo = "";
    });
  });
}

function moveNode(index: number, dir: -1 | 1) {
  const target = index + dir;
  if (target < 0 || target >= nodes.value.length) return;
  const tmp = nodes.value[index];
  nodes.value[index] = nodes.value[target];
  nodes.value[target] = tmp;
}

// ─── Button helpers ──────────────────────────────────────────────────────────
function addBtn(n: FlowNode) {
  if (!n.buttons) n.buttons = [];
  if (n.buttons.length < 3)
    n.buttons.push({
      id: uid(),
      label: `Option ${n.buttons.length + 1}`,
      actions: [{ kind: "navigation", goTo: "" }],
      saveVariable: "",
    });
}

function removeBtn(n: FlowNode, i: number) {
  n.buttons!.splice(i, 1);
}

// ─── List helpers ─────────────────────────────────────────────────────────────
function addRow(sec: Section) {
  sec.rows.push({
    id: uid(),
    title: `Item ${sec.rows.length + 1}`,
    description: "",
    actions: [{ kind: "navigation", goTo: "" }],
    saveVariable: "",
  });
}

function addSection(n: FlowNode) {
  // Initialize action if it doesn't exist
  if (!n.action) {
    n.action = {
      button: n.actionButton || "View Options",
      sections: [],
    };
  }

  // Initialize sections array if it doesn't exist
  if (!n.action.sections) {
    n.action.sections = [];
  }

  // Add new section
  n.action.sections.push({
    title: `Section ${n.action.sections.length + 1}`,
    rows: [
      {
        id: uid(),
        title: "Item 1",
        description: "",
        actions: [{ kind: "navigation", goTo: "" }],
        saveVariable: "",
      },
    ],
  });
}

function removeSection(n: FlowNode, idx: number) {
  if (n.action?.sections) {
    n.action.sections.splice(idx, 1);
    if (n.action.sections.length === 0) {
      n.action.sections = [];
    }
  }
}

function removeRow(sec: Section, idx: number) {
  sec.rows.splice(idx, 1);
}

// ─── Action helpers ──────────────────────────────────────────────────────────
function addNodeAction(n: FlowNode) {
  if (!n.actions) n.actions = [];
  n.actions.push({ kind: "navigation", goTo: "" });
}

function removeAction(actions: ActionDef[], idx: number) {
  actions.splice(idx, idx);
}

// ─── Off-canvas management ───────────────────────────────────────────────────
function openActionOffCanvas(node: FlowNode, button?: Btn, row?: Row) {
  offCanvas.value = {
    show: true,
    targetNode: node,
    targetButton: button || null,
    targetRow: row || null,
  };
}

function closeOffCanvas() {
  offCanvas.value.show = false;
  setTimeout(() => {
    offCanvas.value.targetNode = null;
    offCanvas.value.targetButton = null;
    offCanvas.value.targetRow = null;
  }, 300);
}

// ─── Add-node menu ────────────────────────────────────────────────────────────
function openAddMenu(afterIndex: number, event: MouseEvent) {
  event.stopPropagation();
  const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();

  addMenuState.value = {
    show: true,
    afterIndex,
    x: rect.left,
    y: rect.bottom + 8,
  };
  menuStep.value = "root";
}

function closeAddMenu() {
  addMenuState.value.show = false;
  menuStep.value = "root";
}

// ─── Save / Load ──────────────────────────────────────────────────────────────
async function save() {
  isSaving.value = true;
  try {
    await axios.post(`/api/flows/${flowId.value}/save`, {
      nodes: nodes.value.map((n, i) => ({
        node_id: n.id,
        node_type: n.kind,
        position_x: 0,
        position_y: i * 200,
        config: { ...n },
      })),
      edges: buildEdges(),
    });
    toast("Flow saved!");
  } catch (error) {
    console.error("Save failed:", error);
    toast("Failed to save", "error");
  } finally {
    isSaving.value = false;
  }
}

function buildEdges() {
  const edges: any[] = [];
  nodes.value.forEach((n) => {
    if (n.goTo) {
      edges.push({
        edge_id: uid(),
        source_node_id: n.id,
        target_node_id: n.goTo,
        label: "default",
      });
    }

    if (n.postHandoffNode) {
      edges.push({
        edge_id: uid(),
        source_node_id: n.id,
        target_node_id: n.postHandoffNode,
        label: "post_handoff",
      });
    }

    n.buttons?.forEach((b) => {
      b.actions.forEach((a, ai) => {
        if (a.kind === "navigation" && a.goTo) {
          edges.push({
            edge_id: uid(),
            source_node_id: n.id,
            target_node_id: a.goTo,
            label: `btn:${b.label}:action${ai}`,
          });
        } else if (a.kind === "condition") {
          if (a.trueGoTo)
            edges.push({
              edge_id: uid(),
              source_node_id: n.id,
              target_node_id: a.trueGoTo,
              label: `btn:${b.label}:cond${ai}:true`,
            });
          if (a.falseGoTo)
            edges.push({
              edge_id: uid(),
              source_node_id: n.id,
              target_node_id: a.falseGoTo,
              label: `btn:${b.label}:cond${ai}:false`,
            });
        }
      });
    });

    n.action?.sections?.forEach((s) =>
      s.rows.forEach((r) => {
        r.actions.forEach((a, ai) => {
          if (a.kind === "navigation" && a.goTo) {
            edges.push({
              edge_id: uid(),
              source_node_id: n.id,
              target_node_id: a.goTo,
              label: `list:${r.title}:action${ai}`,
            });
          } else if (a.kind === "condition") {
            if (a.trueGoTo)
              edges.push({
                edge_id: uid(),
                source_node_id: n.id,
                target_node_id: a.trueGoTo,
                label: `list:${r.title}:cond${ai}:true`,
              });
            if (a.falseGoTo)
              edges.push({
                edge_id: uid(),
                source_node_id: n.id,
                target_node_id: a.falseGoTo,
                label: `list:${r.title}:cond${ai}:false`,
              });
          }
        });
      }),
    );

    n.actions?.forEach((a, ai) => {
      if (a.kind === "navigation" && a.goTo) {
        edges.push({
          edge_id: uid(),
          source_node_id: n.id,
          target_node_id: a.goTo,
          label: `action${ai}`,
        });
      } else if (a.kind === "condition") {
        if (a.trueGoTo)
          edges.push({
            edge_id: uid(),
            source_node_id: n.id,
            target_node_id: a.trueGoTo,
            label: `action${ai}:true`,
          });
        if (a.falseGoTo)
          edges.push({
            edge_id: uid(),
            source_node_id: n.id,
            target_node_id: a.falseGoTo,
            label: `action${ai}:false`,
          });
      }
    });
  });
  return edges;
}

async function load() {
  isLoading.value = true;
  try {
    const [flowRes, varsRes] = await Promise.all([
      axios.get(`/api/flows/${flowId.value}`),
      axios.get(`/api/flows/${flowId.value}/variables`),
    ]);

    flow.value = flowRes.data.flow;
    flowVersion.value = flowRes.data.version;
    availableVariables.value = varsRes.data.variables.map((v: any) => v.key);

    const rawNodes = flowRes.data.nodes || [];

    nodes.value = rawNodes
      .sort((a: any, b: any) => a.position_y - b.position_y)
      .map((n: any) => {
        const config = n.config || {};

        return {
          id: n.uuid || n.id?.toString() || uid(),
          kind: mapBackendTypeToFrontend(n.type),
          ...config,
          actions: config.actions || [],
          isFirstNode: n.is_entry_point || config.isFirstNode || false,
          isLastNode: n.is_terminal || config.isLastNode || false,
          triggersHandoff: config.triggersHandoff || false,
          postHandoffNode: config.postHandoffNode || "",
          inputVariable: config.inputVariable || "",
          buttons:
            config.buttons?.map((b: any) => ({
              ...b,
              actions: b.actions || [],
              saveVariable: b.saveVariable || "",
            })) || [],
          action: config.action || undefined,
        };
      });

    if (!nodes.value.find((n) => n.isFirstNode) && nodes.value.length > 0) {
      nodes.value[0].isFirstNode = true;
    }
  } catch (error) {
    console.error("Load failed:", error);
    toast("Failed to load flow", "error");
  } finally {
    isLoading.value = false;
  }
}

// 4. UPDATE mapBackendTypeToFrontend
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
async function publish() {
  if (!confirm("Are you sure you want to publish this flow?")) return;

  try {
    await save();
    await axios.post(`/api/flows/${flowId.value}/publish`);
    toast("Flow published successfully!");
    await load();
  } catch (error) {
    console.error("Publish failed:", error);
    toast("Failed to publish", "error");
  }
}

onMounted(() => {
  load();
});
</script>

<template>
  <v-app>
    <!-- LIGHT MODE APP BAR -->
    <v-app-bar color="white" elevation="1" density="compact">
      <v-btn
        icon="$arrowLeft"
        @click="router.push('/flows')"
        size="small"
        class="ml-2"
      />
      <v-toolbar-title>
        <span class="font-weight-bold">{{ flow?.name || "Flow Builder" }}</span>
        <v-chip
          :color="flow?.status === 'published' ? 'success' : 'default'"
          size="x-small"
          class="ml-2"
          variant="flat"
        >
          {{ flow?.status || "draft" }}
        </v-chip>
        <v-chip v-if="flowVersion" size="x-small" class="ml-1" variant="text">
          v{{ flowVersion.version_number }}
        </v-chip>
      </v-toolbar-title>
      <v-spacer />

      <span class="text-caption text-grey-darken-2 mr-4"
        >{{ nodes.length }} node{{ nodes.length !== 1 ? "s" : "" }}</span
      >

      <v-btn
        v-if="flow?.status === 'draft'"
        color="success"
        @click="publish"
        prepend-icon="$publish"
        variant="outlined"
        class="mr-2"
        size="small"
      >
        Publish
      </v-btn>

      <v-btn
        color="primary"
        :loading="isSaving"
        @click="save"
        prepend-icon="$contentSave"
        variant="flat"
        class="mr-2"
      >
        Save Flow
      </v-btn>
    </v-app-bar>

    <!-- LIGHT MODE MAIN -->
    <v-main class="bg-grey-lighten-4">
      <!-- Loading -->
      <v-container v-if="isLoading" class="fill-height">
        <v-row align="center" justify="center">
          <v-col cols="auto">
            <v-progress-circular indeterminate color="primary" size="64" />
            <p class="text-center mt-4 text-grey-darken-1">Loading flow…</p>
          </v-col>
        </v-row>
      </v-container>

      <!-- Flow Canvas -->
      <v-container v-else fluid class="py-8">
        <v-row justify="center">
          <v-col cols="12" md="8" lg="7" xl="6">
            <!-- Add first node button -->
            <div class="text-center mb-4" v-if="nodes.length === 0">
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
            <template v-for="(n, idx) in nodes" :key="n.id">
              <!-- Connector line -->
              <v-divider
                v-if="idx > 0"
                class="my-2"
                :thickness="2"
                color="grey-lighten-2"
              />

              <!-- Node Card -->
              <v-card
                :style="{ borderLeft: `4px solid ${KINDS[n.kind].color}` }"
                variant="outlined"
                class="mb-4"
                elevation="1"
              >
                <!-- Node Header -->
                <v-card-title
                  class="d-flex align-center cursor-pointer bg-grey-lighten-5"
                  @click="expandedNodes[n.id] = !expandedNodes[n.id]"
                >
                  <!-- First/Last badges -->
                  <v-chip
                    v-if="n.isFirstNode"
                    size="x-small"
                    color="primary"
                    variant="flat"
                    class="mr-2"
                  >
                    <v-icon icon="$play" size="x-small" class="mr-1" />
                    START
                  </v-chip>
                  <v-chip
                    v-if="n.isLastNode"
                    size="x-small"
                    color="success"
                    variant="flat"
                    class="mr-2"
                  >
                    <v-icon icon="$flagCheckered" size="x-small" class="mr-1" />
                    END
                  </v-chip>

                  <v-icon
                    :icon="KINDS[n.kind].icon"
                    :color="KINDS[n.kind].color"
                    class="mr-2"
                  />
                  <span
                    class="text-subtitle-1 font-weight-bold"
                    :style="{ color: KINDS[n.kind].color }"
                  >
                    {{ KINDS[n.kind].label }}
                  </span>
                  <v-chip size="x-small" variant="text" class="ml-2"
                    >#{{ n.id.slice(-5) }}</v-chip
                  >

                  <v-spacer />

                  <!-- Node actions menu -->
                  <v-menu>
                    <template v-slot:activator="{ props }">
                      <v-btn
                        icon="$dotsVertical"
                        size="x-small"
                        variant="text"
                        v-bind="props"
                        @click.stop
                      />
                    </template>
                    <v-list density="compact">
                      <v-list-item @click="setFirstNode(n.id)">
                        <template #prepend>
                          <v-icon icon="$play" size="small" />
                        </template>
                        <v-list-item-title
                          >Set as Entry Point</v-list-item-title
                        >
                      </v-list-item>
                      <v-list-item @click="setLastNode(n.id)">
                        <template #prepend>
                          <v-icon icon="$flagCheckered" size="small" />
                        </template>
                        <v-list-item-title>Set as Exit Point</v-list-item-title>
                      </v-list-item>
                      <v-list-item @click="toggleHandoff(n)">
                        <template #prepend>
                          <v-icon icon="$humanGreetingProximity" size="small" />
                        </template>
                        <v-list-item-title>
                          {{ n.triggersHandoff ? "Disable" : "Enable" }} Handoff
                        </v-list-item-title>
                      </v-list-item>
                      <v-divider />
                      <v-list-item @click="deleteNode(n.id)" class="text-error">
                        <template #prepend>
                          <v-icon icon="$trashCan" size="small" color="error" />
                        </template>
                        <v-list-item-title>Delete Node</v-list-item-title>
                      </v-list-item>
                    </v-list>
                  </v-menu>

                  <!-- Move buttons -->
                  <v-btn
                    icon="$arrowUp"
                    size="x-small"
                    variant="text"
                    :disabled="idx === 0"
                    @click.stop="moveNode(idx, -1)"
                  />
                  <v-btn
                    icon="$arrowDown"
                    size="x-small"
                    variant="text"
                    :disabled="idx === nodes.length - 1"
                    @click.stop="moveNode(idx, 1)"
                  />

                  <v-icon
                    :icon="expandedNodes[n.id] ? '$chevronUp' : '$chevronDown'"
                  />
                </v-card-title>

                <!-- Node Body -->
                <v-expand-transition>
                  <div v-show="expandedNodes[n.id]">
                    <v-divider />
                    <v-card-text>
                      <!-- TRIGGER -->
                      <template v-if="n.kind === 'trigger'">
                        <v-select
                          v-model="n.text"
                          label="Trigger Type"
                          :items="[
                            { value: 'keyword', title: 'Keyword match' },
                            { value: 'any', title: 'Any message' },
                            { value: 'first', title: 'First message ever' },
                            { value: 'opt_in', title: 'Opt-in' },
                          ]"
                          variant="outlined"
                          density="compact"
                          hint="When should this flow start?"
                          persistent-hint
                        />

                        <RichTextField
                          v-if="n.text === 'keyword'"
                          v-model="n.mediaCaption"
                          label="Keywords (comma-separated)"
                          placeholder="hello, start, hi, menu"
                          class="mt-4"
                          hint="Users typing these words will trigger this flow"
                          persistent-hint
                        />

                        <v-combobox
                          v-model="n.inputVariable"
                          label="Save trigger input to variable (optional)"
                          :items="availableVariables"
                          placeholder="trigger_message"
                          variant="outlined"
                          density="compact"
                          clearable
                          hint="Store what the user sent to trigger this flow"
                          persistent-hint
                          class="mt-4"
                        >
                          <template #prepend-inner>
                            <v-icon icon="$variable" size="small" />
                          </template>
                        </v-combobox>

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                          class="mt-4"
                          hint="The next node to execute after trigger"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$navigationVariant" size="small" />
                          </template>
                        </v-select>

                        <!-- Trigger Node Actions -->
                        <v-divider class="my-4" />
                        <div
                          class="d-flex align-center justify-space-between mb-3"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Node Actions
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$cog"
                            @click="openActionOffCanvas(n)"
                          >
                            Configure {{ n.actions?.length || 0 }} action{{
                              n.actions?.length !== 1 ? "s" : ""
                            }}
                          </v-btn>
                        </div>
                        <v-alert
                          v-if="!n.actions || n.actions.length === 0"
                          type="info"
                          variant="tonal"
                          density="compact"
                        >
                          No actions configured. Actions run when this trigger
                          fires.
                        </v-alert>
                      </template>

                      <!-- MESSAGE -->
                      <template v-else-if="n.kind === 'message'">
                        <RichTextEditor
                          v-model="n.text"
                          label="Message text"
                          placeholder="Type your message… {{variable}} for dynamic values"
                          :available-variables="availableVariables"
                          :show-store-checkbox="true"
                          :store-initial-value="!!n.inputVariable"
                          :store-initial-variable="n.inputVariable"
                          @update:store-input="
                            (val) => (n.inputVariable = val ? 'temp_var' : '')
                          "
                          @update:store-variable="
                            (val) => (n.inputVariable = val)
                          "
                          character-count-type="message"
                          :max-length="1024"
                        />

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                          class="mt-4"
                        />

                        <!-- Message Node Actions -->
                        <v-divider class="my-4" />
                        <div
                          class="d-flex align-center justify-space-between mb-3"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Node Actions
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$cog"
                            @click="openActionOffCanvas(n)"
                          >
                            Configure {{ n.actions?.length || 0 }} action{{
                              n.actions?.length !== 1 ? "s" : ""
                            }}
                          </v-btn>
                        </div>
                      </template>

                      <!-- BUTTONS -->
                      <template v-else-if="n.kind === 'buttons'">
                        <RichTextEditor
                          v-model="n.btnText"
                          label="Message text"
                          placeholder="Choose an option..."
                          :available-variables="availableVariables"
                          character-count-type="body"
                          :max-length="1024"
                        />

                        <v-divider class="my-4" />

                        <div
                          class="d-flex align-center justify-space-between mb-3"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Buttons ({{ n.buttons?.length || 0 }}/3)
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$plus"
                            @click="addBtn(n)"
                            :disabled="(n.buttons?.length || 0) >= 3"
                          >
                            Add Button
                          </v-btn>
                        </div>

                        <v-card
                          v-for="(btn, bIdx) in n.buttons"
                          :key="btn.id"
                          variant="outlined"
                          class="mb-3"
                        >
                          <v-card-text>
                            <div class="d-flex gap-2 align-center mb-3">
                              <RichTextField
                                v-model="btn.label"
                                label="Button text"
                                :available-variables="availableVariables"
                                field-type="button"
                                :max-length="20"
                                show-variable-picker
                              />
                              <v-btn
                                icon="$trashCan"
                                size="x-small"
                                variant="text"
                                color="error"
                                @click="removeBtn(n, bIdx)"
                              />
                            </div>

                            <v-btn
                              size="small"
                              variant="outlined"
                              prepend-icon="$cog"
                              @click="openActionOffCanvas(n, btn)"
                              block
                            >
                              Configure {{ btn.actions?.length || 0 }} action{{
                                btn.actions?.length !== 1 ? "s" : ""
                              }}
                            </v-btn>
                          </v-card-text>
                        </v-card>
                      </template>

                      <!-- LIST -->
                      <template v-else-if="n.kind === 'list'">
                        <RichTextField
                          v-model="n.listHeader"
                          label="List Header"
                          placeholder="Enter a header"
                          :available-variables="availableVariables"
                          field-type="header"
                          :max-length="60"
                          show-variable-picker
                        />

                        <RichTextEditor
                          class="mt-2"
                          v-model="n.listBody"
                          label="List Body"
                          placeholder="Please choose an option..."
                          :available-variables="availableVariables"
                          variable-tooltip="Insert variable in description"
                          character-count-type="body"
                          :max-length="1024"
                        />

                        <v-divider class="my-4" />

                        <!-- Initialize action if it doesn't exist -->
                        <RichTextField
                          v-if="n.action"
                          v-model="n.action.button"
                          label="Call to Action Button"
                          placeholder="View Options"
                          :available-variables="availableVariables"
                          field-type="button"
                          :max-length="20"
                          show-variable-picker
                        />
                        <RichTextField
                          v-else
                          v-model="n.actionButton"
                          label="Call to Action Button"
                          placeholder="View Options"
                          :available-variables="availableVariables"
                          field-type="button"
                          :max-length="25"
                          show-variable-picker
                          @update:model-value="
                            (val) => {
                              if (!n.action)
                                n.action = { button: val, sections: [] };
                              else n.action.button = val;
                            }
                          "
                        />

                        <v-divider class="my-4" />

                        <div
                          class="d-flex align-center justify-space-between mb-3"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Sections ({{ n.action?.sections?.length || 0 }})
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$plus"
                            @click="addSection(n)"
                          >
                            Add Section
                          </v-btn>
                        </div>

                        <template v-if="n.action?.sections?.length">
                          <v-card
                            v-for="(sec, sIdx) in n.action.sections"
                            :key="sIdx"
                            variant="outlined"
                            class="mb-3"
                          >
                            <div
                              class="text-h6 d-flex align-center mt-2 bg-grey-lighten-5"
                            >
                              <RichTextField
                                v-model="sec.title"
                                placeholder="Section title"
                                :available-variables="availableVariables"
                                field-type="title"
                                :max-length="45"
                                show-variable-picker
                                variant="plain"
                                density="compact"
                                hide-details
                                class="ml-2"
                              />
                              <v-btn
                                icon="$trashCan"
                                size="x-small"
                                variant="text"
                                color="error"
                                @click="removeSection(n, sIdx)"
                              />
                            </div>
                            <v-divider />
                            <v-card-text>
                              <v-card
                                v-for="(row, rIdx) in sec.rows"
                                :key="row.id"
                                variant="outlined"
                                class="mb-2"
                              >
                                <v-card-text>
                                  <div class="d-flex gap-2 align-center mb-2">
                                    <RichTextField
                                      v-model="row.title"
                                      placeholder="Title"
                                      :available-variables="availableVariables"
                                      field-type="title"
                                      :max-length="45"
                                      show-variable-picker
                                      density="compact"
                                      hide-details
                                    />
                                    <v-tooltip location="top">
                                      <template #activator="{ props }">
                                        <v-btn
                                          icon="$trashCan"
                                          size="x-small"
                                          variant="text"
                                          color="error"
                                          @click="removeRow(sec, rIdx)"
                                        />
                                      </template>
                                      Delete Item
                                    </v-tooltip>
                                  </div>
                                  <div class="d-flex align-center gap-2 mb-2">
                                    <RichTextField
                                      v-model="row.description"
                                      placeholder="Description (optional)"
                                      :available-variables="availableVariables"
                                      field-type="description"
                                      :max-length="72"
                                      show-variable-picker
                                      density="compact"
                                      hide-details
                                      class="flex-grow-1"
                                    />

                                    <v-tooltip location="top">
                                      <template #activator="{ props }">
                                        <v-btn
                                          v-bind="props"
                                          icon="$cog"
                                          size="x-small"
                                          variant="text"
                                          color="success"
                                          @click="
                                            openActionOffCanvas(
                                              n,
                                              undefined,
                                              row,
                                            )
                                          "
                                        />
                                      </template>
                                      Configure Action
                                    </v-tooltip>
                                  </div>
                                </v-card-text>
                              </v-card>

                              <v-btn
                                size="small"
                                variant="text"
                                prepend-icon="$plus"
                                @click="addRow(sec)"
                                block
                              >
                                Add Row
                              </v-btn>
                            </v-card-text>
                          </v-card>
                        </template>
                        <v-alert
                          v-else
                          type="info"
                          variant="tonal"
                          density="compact"
                        >
                          No sections added yet. Click "Add Section" to create
                          your first section.
                        </v-alert>
                      </template>
                      <!-- ============================================================================ -->
                      <!-- ENHANCED MEDIA NODE TEMPLATES - Add to FlowBuilder.vue <template> section -->
                      <!-- Replace the existing MEDIA template with this comprehensive version -->
                      <!-- ============================================================================ -->

                      <!-- MEDIA NODE (Images, Videos, Documents, Audio) -->
                      <template v-else-if="n.kind === 'media'">
                        <v-select
                          v-model="n.mediaType"
                          label="Media type"
                          :items="[
                            { value: 'image', title: '🖼️ Image' },
                            { value: 'video', title: '🎥 Video' },
                            { value: 'audio', title: '🎵 Audio' },
                            { value: 'document', title: '📄 Document' },
                          ]"
                          variant="outlined"
                          density="compact"
                          hint="Select the type of media to send"
                          persistent-hint
                        />

                        <v-text-field
                          v-model="n.mediaUrl"
                          label="Media URL"
                          placeholder="https://example.com/file.jpg"
                          variant="outlined"
                          density="compact"
                          class="mt-4"
                          hint="Direct link to your media file (must be publicly accessible)"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$linkVariant" size="small" />
                          </template>
                        </v-text-field>

                        <!-- Document filename (only for documents) -->
                        <v-text-field
                          v-if="n.mediaType === 'document'"
                          v-model="n.mediaFilename"
                          label="Document Filename"
                          placeholder="report.pdf"
                          variant="outlined"
                          density="compact"
                          class="mt-3"
                          hint="The filename that will be displayed (optional)"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$fileDocument" size="small" />
                          </template>
                        </v-text-field>

                        <!-- Caption (for image, video, document) -->
                        <RichTextField
                          v-if="n.mediaType !== 'audio'"
                          v-model="n.mediaCaption"
                          label="Caption (optional)"
                          placeholder="Check out this..."
                          :available-variables="availableVariables"
                          :show-formatting="true"
                          class="mt-3"
                          hint="Optional text caption to accompany the media"
                        />

                        <v-divider class="my-4" />

                        <!-- Preview card -->
                        <v-card variant="tonal" class="mb-3">
                          <v-card-text class="text-caption">
                            <div class="d-flex align-center">
                              <v-icon
                                :icon="
                                  n.mediaType === 'image'
                                    ? '$image'
                                    : n.mediaType === 'video'
                                      ? '$video'
                                      : n.mediaType === 'audio'
                                        ? '$microphone'
                                        : '$fileDocument'
                                "
                                size="small"
                                class="mr-2"
                              />
                              <span class="font-weight-medium"
                                >{{ n.mediaType?.toUpperCase() }} message</span
                              >
                            </div>
                            <div class="mt-2">
                              WhatsApp will send this {{ n.mediaType }} from the
                              URL you provided
                              <span v-if="n.mediaCaption"> with caption.</span>
                            </div>
                          </v-card-text>
                        </v-card>

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                        >
                          <template #prepend-inner>
                            <v-icon icon="$navigationVariant" size="small" />
                          </template>
                        </v-select>
                      </template>

                      <!-- LOCATION NODE -->
                      <template v-else-if="n.kind === 'location'">
                        <v-row>
                          <v-col cols="6">
                            <v-text-field
                              v-model.number="n.locationLatitude"
                              label="Latitude"
                              placeholder="-25.7479"
                              variant="outlined"
                              density="compact"
                              type="number"
                              step="any"
                              hint="Example: -25.7479"
                              persistent-hint
                            >
                              <template #prepend-inner>
                                <v-icon icon="$latitude" size="small" />
                              </template>
                            </v-text-field>
                          </v-col>
                          <v-col cols="6">
                            <v-text-field
                              v-model.number="n.locationLongitude"
                              label="Longitude"
                              placeholder="28.2293"
                              variant="outlined"
                              density="compact"
                              type="number"
                              step="any"
                              hint="Example: 28.2293"
                              persistent-hint
                            >
                              <template #prepend-inner>
                                <v-icon icon="$longitude" size="small" />
                              </template>
                            </v-text-field>
                          </v-col>
                        </v-row>

                        <v-text-field
                          v-model="n.locationName"
                          label="Location Name"
                          placeholder="OneNICO Office"
                          variant="outlined"
                          density="compact"
                          class="mt-2"
                          hint="Display name for the location"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$mapMarker" size="small" />
                          </template>
                        </v-text-field>

                        <v-textarea
                          v-model="n.locationAddress"
                          label="Address (optional)"
                          placeholder="123 Main Street, City"
                          variant="outlined"
                          density="compact"
                          rows="2"
                          class="mt-3"
                          hint="Full address of the location"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$home" size="small" />
                          </template>
                        </v-textarea>

                        <v-divider class="my-4" />

                        <!-- Preview -->
                        <v-card variant="tonal" class="mb-3">
                          <v-card-text class="text-caption">
                            <div class="d-flex align-center mb-2">
                              <v-icon
                                icon="$mapMarker"
                                size="small"
                                class="mr-2"
                                color="error"
                              />
                              <span class="font-weight-medium"
                                >LOCATION PIN</span
                              >
                            </div>
                            <div v-if="n.locationName">
                              <strong>{{ n.locationName }}</strong>
                            </div>
                            <div
                              v-if="n.locationLatitude && n.locationLongitude"
                              class="text-grey"
                            >
                              {{ n.locationLatitude }},
                              {{ n.locationLongitude }}
                            </div>
                            <div v-if="n.locationAddress" class="mt-1">
                              {{ n.locationAddress }}
                            </div>
                          </v-card-text>
                        </v-card>

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                        >
                          <template #prepend-inner>
                            <v-icon icon="$navigationVariant" size="small" />
                          </template>
                        </v-select>
                      </template>

                      <!-- CONTACT NODE (vCard) -->
                      <template v-else-if="n.kind === 'contact'">
                        <v-alert
                          type="info"
                          variant="tonal"
                          density="compact"
                          class="mb-3"
                        >
                          <div class="text-caption">
                            Send a contact card (vCard) that users can save to
                            their contacts
                          </div>
                        </v-alert>

                        <!-- Name Section -->
                        <div class="text-subtitle-2 font-weight-bold mb-2">
                          Contact Name
                        </div>
                        <v-text-field
                          v-model="n.contactData.name.formatted_name"
                          label="Full Name *"
                          placeholder="John Doe"
                          variant="outlined"
                          density="compact"
                          hint="How the name appears in WhatsApp"
                          persistent-hint
                        >
                          <template #prepend-inner>
                            <v-icon icon="$account" size="small" />
                          </template>
                        </v-text-field>

                        <v-row class="mt-2">
                          <v-col cols="6">
                            <v-text-field
                              v-model="n.contactData.name.first_name"
                              label="First Name"
                              placeholder="John"
                              variant="outlined"
                              density="compact"
                              hide-details
                            />
                          </v-col>
                          <v-col cols="6">
                            <v-text-field
                              v-model="n.contactData.name.last_name"
                              label="Last Name"
                              placeholder="Doe"
                              variant="outlined"
                              density="compact"
                              hide-details
                            />
                          </v-col>
                        </v-row>

                        <v-divider class="my-4" />

                        <!-- Phone Numbers -->
                        <div
                          class="d-flex align-center justify-space-between mb-2"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Phone Numbers
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$plus"
                            @click="
                              n.contactData.phones = n.contactData.phones || [];
                              n.contactData.phones.push({
                                phone: '',
                                type: 'Mobile',
                                wa_id: '',
                              });
                            "
                          >
                            Add Phone
                          </v-btn>
                        </div>

                        <v-card
                          v-for="(phone, pIdx) in n.contactData.phones"
                          :key="pIdx"
                          variant="outlined"
                          class="mb-2"
                        >
                          <v-card-text>
                            <div class="d-flex gap-2 align-center">
                              <v-text-field
                                v-model="phone.phone"
                                label="Phone Number"
                                placeholder="+1234567890"
                                variant="outlined"
                                density="compact"
                                hide-details
                                class="flex-grow-1"
                              >
                                <template #prepend-inner>
                                  <v-icon icon="$phone" size="small" />
                                </template>
                              </v-text-field>
                              <v-select
                                v-model="phone.type"
                                :items="['Mobile', 'Work', 'Home', 'Landline']"
                                variant="outlined"
                                density="compact"
                                hide-details
                                style="max-width: 120px"
                              />
                              <v-btn
                                icon="$trashCan"
                                size="x-small"
                                variant="text"
                                color="error"
                                @click="n.contactData.phones.splice(pIdx, 1)"
                              />
                            </div>
                            <v-text-field
                              v-if="phone.type === 'Mobile'"
                              v-model="phone.wa_id"
                              label="WhatsApp ID (optional)"
                              placeholder="1234567890"
                              variant="outlined"
                              density="compact"
                              class="mt-2"
                              hint="Phone number without + or country code"
                              persistent-hint
                            />
                          </v-card-text>
                        </v-card>

                        <v-divider class="my-4" />

                        <!-- Emails (Optional) -->
                        <div
                          class="d-flex align-center justify-space-between mb-2"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Emails (Optional)
                          </div>
                          <v-btn
                            size="x-small"
                            variant="outlined"
                            prepend-icon="$plus"
                            @click="
                              n.contactData.emails = n.contactData.emails || [];
                              n.contactData.emails.push({
                                email: '',
                                type: 'Work',
                              });
                            "
                          >
                            Add Email
                          </v-btn>
                        </div>

                        <v-card
                          v-for="(email, eIdx) in n.contactData.emails"
                          :key="eIdx"
                          variant="outlined"
                          class="mb-2"
                        >
                          <v-card-text>
                            <div class="d-flex gap-2 align-center">
                              <v-text-field
                                v-model="email.email"
                                label="Email Address"
                                placeholder="john@example.com"
                                variant="outlined"
                                density="compact"
                                hide-details
                                class="flex-grow-1"
                              >
                                <template #prepend-inner>
                                  <v-icon icon="$email" size="small" />
                                </template>
                              </v-text-field>
                              <v-select
                                v-model="email.type"
                                :items="['Work', 'Personal', 'Other']"
                                variant="outlined"
                                density="compact"
                                hide-details
                                style="max-width: 120px"
                              />
                              <v-btn
                                icon="$trashCan"
                                size="x-small"
                                variant="text"
                                color="error"
                                @click="n.contactData.emails.splice(eIdx, 1)"
                              />
                            </div>
                          </v-card-text>
                        </v-card>

                        <v-divider class="my-4" />

                        <!-- Organization (Optional) -->
                        <v-expansion-panels variant="accordion" class="mb-3">
                          <v-expansion-panel>
                            <v-expansion-panel-title>
                              <v-icon
                                icon="$domain"
                                size="small"
                                class="mr-2"
                              />
                              Organization Info (Optional)
                            </v-expansion-panel-title>
                            <v-expansion-panel-text>
                              <v-text-field
                                v-model="n.contactData.org.company"
                                label="Company"
                                placeholder="OneNICO"
                                variant="outlined"
                                density="compact"
                                class="mb-2"
                              />
                              <v-text-field
                                v-model="n.contactData.org.department"
                                label="Department"
                                placeholder="Customer Support"
                                variant="outlined"
                                density="compact"
                                class="mb-2"
                              />
                              <v-text-field
                                v-model="n.contactData.org.title"
                                label="Job Title"
                                placeholder="Support Manager"
                                variant="outlined"
                                density="compact"
                              />
                            </v-expansion-panel-text>
                          </v-expansion-panel>
                        </v-expansion-panels>

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                        >
                          <template #prepend-inner>
                            <v-icon icon="$navigationVariant" size="small" />
                          </template>
                        </v-select>
                      </template>

                      <!-- END -->
                      <template v-else-if="n.kind === 'end'">
                        <RichTextEditor
                          v-model="n.text"
                          label="Closing Message"
                          placeholder="Thank you! GoodBye"
                          :available-variables="availableVariables"
                          :show-store-checkbox="true"
                          :store-initial-value="!!n.inputVariable"
                          :store-initial-variable="n.inputVariable"
                          @update:store-input="
                            (val) => (n.inputVariable = val ? 'temp_var' : '')
                          "
                          @update:store-variable="
                            (val) => (n.inputVariable = val)
                          "
                          character-count-type="message"
                          :max-length="1024"
                        />

                        <v-alert
                          type="success"
                          variant="tonal"
                          density="compact"
                          class="mt-4"
                        >
                          This node ends the conversation flow.
                        </v-alert>
                      </template>

                      <!-- Handoff Settings (for all nodes) -->
                      <template v-if="n.triggersHandoff">
                        <v-divider class="my-4" />
                        <v-alert
                          type="warning"
                          variant="tonal"
                          density="compact"
                          class="mb-3"
                        >
                          <div class="text-subtitle-2 mb-2">
                            <v-icon
                              icon="$humanGreetingProximity"
                              size="small"
                            />
                            Agent Handoff Enabled
                          </div>
                          <div class="text-caption">
                            After this node, conversation will be handed off to
                            an agent.
                          </div>
                        </v-alert>
                        <v-select
                          v-model="n.postHandoffNode"
                          label="Resume at node (after handoff ends)"
                          :items="nodeOptions"
                          variant="outlined"
                          density="compact"
                          clearable
                          hint="Optional: Where to continue after agent closes conversation"
                          persistent-hint
                        />
                      </template>
                    </v-card-text>
                  </div>
                </v-expand-transition>
              </v-card>

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
      </v-container>
    </v-main>

    <!-- Add Node Menu -->
    <v-menu
      v-model="addMenuState.show"
      :style="{
        position: 'fixed',
        left: `${addMenuState.x}px`,
        top: `${addMenuState.y}px`,
      }"
      location="bottom center"
      :close-on-content-click="false"
    >
      <v-card min-width="260">
        <v-card-text class="pa-3">
          <!-- Root Menu -->
          <template v-if="menuStep === 'root'">
            <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
              Add Node
            </div>
            <v-list density="compact">
              <v-list-item
                @click="spawnNode('trigger', addMenuState.afterIndex)"
                prepend-icon="$lightningBoltOutline"
              >
                <v-list-item-title>Trigger</v-list-item-title>
                <v-list-item-subtitle
                  >Start the conversation</v-list-item-subtitle
                >
              </v-list-item>
              <v-list-item
                @click="menuStep = 'message'"
                prepend-icon="$messageText"
              >
                <v-list-item-title>Message</v-list-item-title>
                <v-list-item-subtitle>Text or media</v-list-item-subtitle>
                <template #append>
                  <v-icon icon="$chevronRight" size="small" />
                </template>
              </v-list-item>
              <v-list-item
                @click="menuStep = 'interactive'"
                prepend-icon="$radioboxMarked"
              >
                <v-list-item-title>Interactive</v-list-item-title>
                <v-list-item-subtitle>Buttons or lists</v-list-item-subtitle>
                <template #append>
                  <v-icon icon="$chevronRight" size="small" />
                </template>
              </v-list-item>
              <v-list-item
                @click="spawnNode('end', addMenuState.afterIndex)"
                prepend-icon="$flagCheckered"
              >
                <v-list-item-title>End Flow</v-list-item-title>
                <v-list-item-subtitle>Close conversation</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </template>

          <!-- Message Submenu -->
          <template v-else-if="menuStep === 'message'">
            <v-btn
              size="x-small"
              variant="text"
              prepend-icon="$arrowLeft"
              @click="menuStep = 'root'"
              class="mb-2"
            >
              Back
            </v-btn>
            <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
              Message Type
            </div>
            <v-list density="compact">
              <v-list-item
                @click="spawnNode('message', addMenuState.afterIndex)"
                prepend-icon="$messageText"
              >
                <v-list-item-title>📝 Text Message</v-list-item-title>
                <v-list-item-subtitle>Plain text message</v-list-item-subtitle>
              </v-list-item>

              <v-divider class="my-1" />

              <v-list-item
                @click="spawnNode('media', addMenuState.afterIndex)"
                prepend-icon="$imageOutline"
              >
                <v-list-item-title>🖼️ Image</v-list-item-title>
                <v-list-item-subtitle>Send an image</v-list-item-subtitle>
              </v-list-item>

              <v-list-item
                @click="
                  spawnNode('media', addMenuState.afterIndex);
                  const lastNode = nodes[nodes.length - 1];
                  if (lastNode) lastNode.mediaType = 'video';
                "
                prepend-icon="$video"
              >
                <v-list-item-title>🎥 Video</v-list-item-title>
                <v-list-item-subtitle>Send a video file</v-list-item-subtitle>
              </v-list-item>

              <v-list-item
                @click="
                  spawnNode('media', addMenuState.afterIndex);
                  const lastNode = nodes[nodes.length - 1];
                  if (lastNode) lastNode.mediaType = 'document';
                "
                prepend-icon="$fileDocument"
              >
                <v-list-item-title>📄 Document</v-list-item-title>
                <v-list-item-subtitle>PDF, Excel, Word...</v-list-item-subtitle>
              </v-list-item>

              <v-list-item
                @click="
                  spawnNode('media', addMenuState.afterIndex);
                  const lastNode = nodes[nodes.length - 1];
                  if (lastNode) lastNode.mediaType = 'audio';
                "
                prepend-icon="$microphone"
              >
                <v-list-item-title>🎵 Audio</v-list-item-title>
                <v-list-item-subtitle>Voice or music file</v-list-item-subtitle>
              </v-list-item>

              <v-divider class="my-1" />

              <v-list-item
                @click="spawnNode('location', addMenuState.afterIndex)"
                prepend-icon="$mapMarker"
              >
                <v-list-item-title>📍 Location</v-list-item-title>
                <v-list-item-subtitle
                  >Share a location pin</v-list-item-subtitle
                >
              </v-list-item>

              <v-list-item
                @click="spawnNode('contact', addMenuState.afterIndex)"
                prepend-icon="$accountCard"
              >
                <v-list-item-title>👤 Contact</v-list-item-title>
                <v-list-item-subtitle>Share a vCard</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </template>

          <!-- Interactive Submenu -->
          <template v-else-if="menuStep === 'interactive'">
            <v-btn
              size="x-small"
              variant="text"
              prepend-icon="$arrowLeft"
              @click="menuStep = 'root'"
              class="mb-2"
            >
              Back
            </v-btn>
            <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
              Interactive Type
            </div>
            <v-list density="compact">
              <v-list-item
                @click="spawnNode('buttons', addMenuState.afterIndex)"
                prepend-icon="$radioboxMarked"
              >
                <v-list-item-title>Quick Replies</v-list-item-title>
                <v-list-item-subtitle>Up to 3 buttons</v-list-item-subtitle>
              </v-list-item>
              <v-list-item
                @click="spawnNode('list', addMenuState.afterIndex)"
                prepend-icon="$formatListBulleted"
              >
                <v-list-item-title>List Message</v-list-item-title>
                <v-list-item-subtitle>Scrollable menu</v-list-item-subtitle>
              </v-list-item>
            </v-list>
          </template>
        </v-card-text>
      </v-card>
    </v-menu>

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
  </v-app>

  <!-- Action Editor Off-Canvas -->
  <ActionEditor
    v-if="offCanvas.show"
    :show="offCanvas.show"
    :targetNode="offCanvas.targetNode!"
    :targetButton="offCanvas.targetButton || undefined"
    :targetRow="offCanvas.targetRow || undefined"
    :availableVariables="availableVariables"
    :customFunctions="customFunctions"
    :apiIntegrations="apiIntegrations"
    :nodeOptions="nodeOptions"
    @close="closeOffCanvas"
    @save="closeOffCanvas"
  />
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.gap-2 {
  gap: 8px;
}

.gap-4 {
  gap: 16px;
}
</style>
