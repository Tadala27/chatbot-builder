<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import ActionEditor from "@/components/ActionEditor.vue";

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
  desc?: string;
  actions: ActionDef[];
  saveVariable?: string;
}

interface Section {
  title: string;
  rows: Row[];
}

interface FlowNode {
  id: string;
  kind: NodeKind;
  text?: string;
  btnText?: string;
  buttons?: Btn[];
  listHeader?: string;
  listBody?: string;
  sections?: Section[];
  mediaType?: "image" | "video" | "audio" | "document";
  mediaUrl?: string;
  mediaCaption?: string;
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
    desc: "Image/video",
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
      sections: [
        {
          title: "Section 1",
          rows: [
            {
              id: uid(),
              title: "Item 1",
              actions: [{ kind: "navigation", goTo: "" }],
              saveVariable: "",
            },
          ],
        },
      ],
    },
    media: { mediaType: "image", mediaUrl: "", mediaCaption: "" },
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

    n.sections?.forEach((s) =>
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
    actions: [{ kind: "navigation", goTo: "" }],
    saveVariable: "",
  });
}

function addSection(n: FlowNode) {
  if (!n.sections) n.sections = [];
  n.sections.push({
    title: `Section ${n.sections.length + 1}`,
    rows: [
      {
        id: uid(),
        title: "Item 1",
        actions: [{ kind: "navigation", goTo: "" }],
        saveVariable: "",
      },
    ],
  });
}

function removeSection(n: FlowNode, idx: number) {
  n.sections?.splice(idx, 1);
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

    n.sections?.forEach((s) =>
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
          sections:
            config.sections?.map((s: any) => ({
              ...s,
              rows:
                s.rows?.map((r: any) => ({
                  ...r,
                  actions: r.actions || [],
                  saveVariable: r.saveVariable || "",
                })) || [],
            })) || [],
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

function mapBackendTypeToFrontend(backendType: string): NodeKind {
  const mapping: Record<string, NodeKind> = {
    message: "message",
    input: "message",
    buttons: "buttons",
    list: "list",
    media: "media",
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
            <!-- Flow Info -->

            <!-- Add first node button - FIXED -->
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

              <!-- Node Card - LIGHT MODE -->
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
                      <!-- TRIGGER - COMPLETE CONFIGURATION -->
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

                        <v-text-field
                          v-if="n.text === 'keyword'"
                          v-model="n.mediaCaption"
                          label="Keywords (comma-separated)"
                          placeholder="hello, start, hi, menu"
                          variant="outlined"
                          density="compact"
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
                        <v-textarea
                          v-model="n.text"
                          label="Message text"
                          placeholder="Type your message… {{variable}} for dynamic values"
                          variant="outlined"
                          density="compact"
                          rows="3"
                        />

                        <v-combobox
                          v-model="n.inputVariable"
                          label="Save user reply to variable (optional)"
                          :items="availableVariables"
                          placeholder="user_response"
                          variant="outlined"
                          density="compact"
                          clearable
                          hint="Capture the user's response in this variable"
                          persistent-hint
                          class="mt-3"
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
                        <v-textarea
                          v-model="n.btnText"
                          label="Message text"
                          placeholder="Choose an option..."
                          variant="outlined"
                          density="compact"
                          rows="2"
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
                              <v-chip size="small" color="primary">{{
                                bIdx + 1
                              }}</v-chip>
                              <v-text-field
                                v-model="btn.label"
                                label="Button text"
                                variant="outlined"
                                density="compact"
                                hide-details
                              />
                              <v-btn
                                icon="$trashCan"
                                size="x-small"
                                variant="text"
                                color="error"
                                @click="removeBtn(n, bIdx)"
                              />
                            </div>

                            <v-combobox
                              v-model="btn.saveVariable"
                              label="Save to variable (optional)"
                              :items="availableVariables"
                              placeholder="button_choice"
                              variant="outlined"
                              density="compact"
                              clearable
                              hide-details
                              class="mb-3"
                            >
                              <template #prepend-inner>
                                <v-icon icon="$variable" size="small" />
                              </template>
                            </v-combobox>

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
                        <v-text-field
                          v-model="n.listHeader"
                          label="List header"
                          placeholder="Menu"
                          variant="outlined"
                          density="compact"
                        />

                        <v-textarea
                          v-model="n.listBody"
                          label="List body"
                          placeholder="Please choose an option"
                          variant="outlined"
                          density="compact"
                          rows="2"
                          class="mt-3"
                        />

                        <v-divider class="my-4" />

                        <div
                          class="d-flex align-center justify-space-between mb-3"
                        >
                          <div class="text-subtitle-2 font-weight-bold">
                            Sections ({{ n.sections?.length || 0 }})
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

                        <v-card
                          v-for="(sec, sIdx) in n.sections"
                          :key="sIdx"
                          variant="outlined"
                          class="mb-3"
                        >
                          <v-card-title
                            class="d-flex align-center bg-grey-lighten-5"
                          >
                            <v-chip size="small">{{ sIdx + 1 }}</v-chip>
                            <v-text-field
                              v-model="sec.title"
                              label="Section title"
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
                          </v-card-title>
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
                                  <v-chip size="x-small">{{ rIdx + 1 }}</v-chip>
                                  <v-text-field
                                    v-model="row.title"
                                    label="Title"
                                    variant="outlined"
                                    density="compact"
                                    hide-details
                                  />
                                  <v-btn
                                    icon="$trashCan"
                                    size="x-small"
                                    variant="text"
                                    color="error"
                                    @click="removeRow(sec, rIdx)"
                                  />
                                </div>

                                <v-text-field
                                  v-model="row.desc"
                                  label="Description (optional)"
                                  variant="outlined"
                                  density="compact"
                                  hide-details
                                  class="mb-2"
                                />

                                <v-combobox
                                  v-model="row.saveVariable"
                                  label="Save to variable (optional)"
                                  :items="availableVariables"
                                  placeholder="selected_item"
                                  variant="outlined"
                                  density="compact"
                                  clearable
                                  hide-details
                                  class="mb-2"
                                >
                                  <template #prepend-inner>
                                    <v-icon icon="$variable" size="small" />
                                  </template>
                                </v-combobox>

                                <v-btn
                                  size="small"
                                  variant="outlined"
                                  prepend-icon="$cog"
                                  @click="
                                    openActionOffCanvas(n, undefined, row)
                                  "
                                  block
                                >
                                  Configure
                                  {{ row.actions?.length || 0 }} action{{
                                    row.actions?.length !== 1 ? "s" : ""
                                  }}
                                </v-btn>
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

                      <!-- MEDIA -->
                      <template v-else-if="n.kind === 'media'">
                        <v-select
                          v-model="n.mediaType"
                          label="Media type"
                          :items="[
                            { value: 'image', title: 'Image' },
                            { value: 'video', title: 'Video' },
                            { value: 'audio', title: 'Audio' },
                            { value: 'document', title: 'Document' },
                          ]"
                          variant="outlined"
                          density="compact"
                        />

                        <v-text-field
                          v-model="n.mediaUrl"
                          label="Media URL"
                          placeholder="https://example.com/media.jpg"
                          variant="outlined"
                          density="compact"
                          class="mt-3"
                        />

                        <v-textarea
                          v-model="n.mediaCaption"
                          label="Caption (optional)"
                          placeholder="Check out this..."
                          variant="outlined"
                          density="compact"
                          rows="2"
                          class="mt-3"
                        />

                        <v-select
                          v-model="n.goTo"
                          label="Then go to"
                          :items="nodeOptions.filter((o) => o.value !== n.id)"
                          variant="outlined"
                          density="compact"
                          class="mt-4"
                        />
                      </template>

                      <!-- END -->
                      <template v-else-if="n.kind === 'end'">
                        <v-textarea
                          v-model="n.text"
                          label="Closing message"
                          placeholder="Thanks! Goodbye 👋"
                          variant="outlined"
                          density="compact"
                          rows="2"
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

              <!-- Add node between - FIXED BUTTON -->
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

    <!-- Add Node Menu - FIXED POSITIONING -->
    <v-menu
      v-model="addMenuState.show"
      :style="{
        position: 'fixed',
        left: `${addMenuState.x}px`,
        top: `${addMenuState.y}px`,
      }"
      location="right"
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
                <v-list-item-title>Text</v-list-item-title>
                <v-list-item-subtitle>Plain text message</v-list-item-subtitle>
              </v-list-item>
              <v-list-item
                @click="spawnNode('media', addMenuState.afterIndex)"
                prepend-icon="$imageOutline"
              >
                <v-list-item-title>Media</v-list-item-title>
                <v-list-item-subtitle
                  >Image, video, audio…</v-list-item-subtitle
                >
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
