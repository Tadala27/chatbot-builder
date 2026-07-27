<script setup lang="ts">
import { computed, defineAsyncComponent } from "vue";
import { v4 as uuidv4 } from "uuid";
import { useActionEditorStore } from "@/stores/actionEditor";

const ACTION_TYPES = [
  {
    value: "navigation",
    title: "Navigate",
    icon: "$arrowRightCircleOutline",
    color: "#3b82f6",
  },
  {
    value: "condition",
    title: "Condition",
    icon: "$sourceBranch",
    color: "#8b5cf6",
  },
  {
    value: "variable",
    title: "Set Variable",
    icon: "$variable",
    color: "#f59e0b",
  },
  { value: "api", title: "API Call", icon: "$api", color: "#10b981" },
  {
    value: "function",
    title: "Run Function",
    icon: "$functionVariant",
    color: "#06b6d4",
  },
  { value: "delay", title: "Delay", icon: "$timerOutline", color: "#6b7280" },
  {
    value: "handoff",
    title: "Handoff to Agent",
    icon: "$headset",
    color: "#ef4444",
  },
];

const DATA_TYPES = [
  { value: "string", title: "Text" },
  { value: "number", title: "Number" },
  { value: "boolean", title: "Boolean" },
  { value: "json", title: "JSON" },
  { value: "date", title: "Date" },
];

const RESPONSE_FIELDS = [
  { value: "status", title: "Status Code" },
  { value: "body", title: "Body Field" },
  { value: "header", title: "Header" },
];

const API_OPERATORS = [
  { value: "equals", title: "=" },
  { value: "not_equals", title: "≠" },
  { value: "greater_than", title: ">" },
  { value: "less_than", title: "<" },
  { value: "greater_than_or_equal", title: "≥" },
  { value: "less_than_or_equal", title: "≤" },
  { value: "contains", title: "contains" },
  { value: "starts_with", title: "starts with" },
  { value: "is_empty", title: "is empty" },
  { value: "is_not_empty", title: "is not empty" },
];

const COND_TYPES = [
  { value: "variable", title: "Variable", icon: "$variable" },
  {
    value: "saved_response",
    title: "User Selected",
    icon: "$cursorDefaultClick",
  },
  {
    value: "user_input",
    title: "User Input",
    icon: "$chatProcessingOutline",
  },
  { value: "api_response", title: "API Response", icon: "$api" },
];

const COND_OPERATORS = [
  { value: "equals", title: "=" },
  { value: "not_equals", title: "≠" },
  { value: "greater_than", title: ">" },
  { value: "less_than", title: "<" },
  { value: "greater_than_or_equal", title: "≥" },
  { value: "less_than_or_equal", title: "≤" },
  { value: "contains", title: "contains" },
  { value: "starts_with", title: "starts with" },
  { value: "ends_with", title: "ends with" },
  { value: "is_empty", title: "has no value / no input" },
  { value: "is_not_empty", title: "has any value / any input" },
];

// ── Props: rootLevel=true reads from the store + shows drawer chrome.    ──
// ── rootLevel=false (recursive calls) reads from `actions` prop instead. ──
const props = withDefaults(
  defineProps<{
    rootLevel?: boolean;
    actions?: any[];
    nodeOptions?: any[];
    availableVariables?: string[];
    apiIntegrations?: any[];
    customFunctions?: any[];
    savedResponses?: any[];
  }>(),
  { rootLevel: true },
);

const emit = defineEmits<{ (e: "update:actions", v: any[]): void }>();

const store = useActionEditorStore();

// ── Unified accessors — root level reads/writes the store's target;     ──
// ── nested levels read/write the actions prop via emit.                  ──
const actionsArray = computed<any[]>({
  get: () => {
    if (!props.rootLevel) return props.actions ?? [];
    if (store.targetButton) return store.targetButton.actions ?? [];
    if (store.targetRow) return store.targetRow.actions ?? [];
    return store.targetNode?.actions ?? [];
  },
  set: (val) => {
    if (!props.rootLevel) {
      emit("update:actions", val);
      return;
    }
    if (store.targetButton) store.targetButton.actions = val;
    else if (store.targetRow) store.targetRow.actions = val;
    else if (store.targetNode) store.targetNode.actions = val;
  },
});

const nodeOptionsRef = computed(() =>
  props.rootLevel ? store.nodeOptions : (props.nodeOptions ?? []),
);
const availableVariablesRef = computed(() =>
  props.rootLevel ? store.availableVariables : (props.availableVariables ?? []),
);
const apiIntegrationsRef = computed(() =>
  props.rootLevel ? store.apiIntegrations : (props.apiIntegrations ?? []),
);
const customFunctionsRef = computed(() =>
  props.rootLevel ? store.customFunctions : (props.customFunctions ?? []),
);
const savedResponsesRef = computed(() =>
  props.rootLevel ? store.savedResponses : (props.savedResponses ?? []),
);

// ── Drawer-only computed (title/subtitle) ────────────────────────────────────
const title = computed(() => {
  if (store.targetButton) return store.targetButton.label || "Button Actions";
  if (store.targetRow) return store.targetRow.title || "List Item Actions";
  return store.targetNode?.kind
    ? `${store.targetNode.kind} Node Actions`
    : "Actions";
});
const subtitle = computed(() => {
  if (store.targetButton)
    return "Configure what happens when user taps this button";
  if (store.targetRow)
    return "Configure what happens when user selects this item";
  return "Configure what this node does after the user interacts";
});

function getActionMeta(kind: string) {
  return ACTION_TYPES.find((t) => t.value === kind) ?? ACTION_TYPES[0];
}
function needsValue(op: string) {
  return !["is_empty", "is_not_empty"].includes(op);
}

// ── Shape factories ───────────────────────────────────────────────────────────
function makeCondition() {
  return {
    id: uuidv4(),
    type: "variable",
    source: "",
    responsePath: "status",
    fieldPath: "",
    operator: "equals",
    value: "",
  };
}
function makeBranch() {
  return {
    id: uuidv4(),
    conditionLogic: "AND",
    conditions: [makeCondition()],
    actions: [] as any[],
  };
}
function makeApiHandler() {
  return {
    id: uuidv4(),
    conditions: [
      {
        id: uuidv4(),
        responseField: "status",
        responsePath: "",
        operator: "equals",
        value: "200",
      },
    ],
    actions: [] as any[],
  };
}
function makeAction(kind: string, id?: string): any {
  const _id = id ?? uuidv4();
  const base = { id: _id, kind, then: null };
  if (kind === "condition")
    return {
      ...base,
      branches: [makeBranch()],
      defaultBranch: { id: uuidv4(), actions: [] },
    };
  if (kind === "api")
    return {
      ...base,
      apiConfigId: "",
      apiResultVar: "",
      responseHandlers: [makeApiHandler()],
      defaultActions: [],
    };
  const map: Record<string, any> = {
    navigation: { ...base, goTo: "" },
    variable: { ...base, varName: "" },
    function: { ...base, fnId: "", paramsRaw: "{}", resultVar: "" },
    delay: { ...base, seconds: 3 },
    handoff: { ...base, resumeAt: "" },
  };
  return map[kind] ?? base;
}

// ── Root action list mutations — all go through actionsArray (works for ──
// ── both root-level [store] and nested [emit] cases identically). ────────
const e = (v: any[]) => (actionsArray.value = v);

function addAction() {
  e([...actionsArray.value, makeAction("navigation")]);
}
function removeAction(i: number) {
  e(actionsArray.value.filter((_, j) => j !== i));
}
function moveAction(i: number, dir: -1 | 1) {
  const t = i + dir;
  if (t < 0 || t >= actionsArray.value.length) return;
  const a = [...actionsArray.value];
  [a[i], a[t]] = [a[t], a[i]];
  e(a);
}
function changeKind(i: number, kind: string) {
  e(
    actionsArray.value.map((a, j) =>
      j === i ? { ...makeAction(kind), id: a.id } : a,
    ),
  );
}
function patchAction(i: number, partial: any) {
  e(actionsArray.value.map((a, j) => (j === i ? { ...a, ...partial } : a)));
}

// ── Condition branch mutations ────────────────────────────────────────────────
function addCondBranch(i: number) {
  patchAction(i, {
    branches: [...(actionsArray.value[i].branches ?? []), makeBranch()],
  });
}
function removeCondBranch(i: number, bi: number) {
  const a = actionsArray.value[i];
  if ((a.branches ?? []).length <= 1) return;
  patchAction(i, {
    branches: a.branches.filter((_: any, j: number) => j !== bi),
  });
}
function patchCondBranch(i: number, bi: number, partial: any) {
  const a = actionsArray.value[i];
  patchAction(i, {
    branches: a.branches.map((b: any, j: number) =>
      j === bi ? { ...b, ...partial } : b,
    ),
  });
}
function addCondCond(i: number, bi: number) {
  const b = actionsArray.value[i].branches[bi];
  patchCondBranch(i, bi, { conditions: [...b.conditions, makeCondition()] });
}
function removeCondCond(i: number, bi: number, ci: number) {
  const b = actionsArray.value[i].branches[bi];
  patchCondBranch(i, bi, {
    conditions: b.conditions.filter((_: any, j: number) => j !== ci),
  });
}
function updateCondCond(
  i: number,
  bi: number,
  ci: number,
  field: string,
  val: any,
) {
  const b = actionsArray.value[i].branches[bi];
  if (field === "type") {
    const id = b.conditions[ci].id;
    patchCondBranch(i, bi, {
      conditions: b.conditions.map((c: any, j: number) =>
        j === ci ? { ...makeCondition(), id, type: val } : c,
      ),
    });
    return;
  }
  patchCondBranch(i, bi, {
    conditions: b.conditions.map((c: any, j: number) =>
      j === ci ? { ...c, [field]: val } : c,
    ),
  });
}
function setCondBranchActions(i: number, bi: number, actions: any[]) {
  patchCondBranch(i, bi, { actions });
}
function setCondDefaultActions(i: number, actions: any[]) {
  const a = actionsArray.value[i];
  patchAction(i, {
    defaultBranch: { ...(a.defaultBranch ?? { id: uuidv4() }), actions },
  });
}
function toggleCondElse(i: number) {
  const a = actionsArray.value[i];
  patchAction(i, {
    defaultBranch: a.defaultBranch ? null : { id: uuidv4(), actions: [] },
  });
}

// ── API handler mutations ─────────────────────────────────────────────────────
function addApiHandler(i: number) {
  patchAction(i, {
    responseHandlers: [
      ...(actionsArray.value[i].responseHandlers ?? []),
      makeApiHandler(),
    ],
  });
}
function removeApiHandler(i: number, hi: number) {
  const a = actionsArray.value[i];
  patchAction(i, {
    responseHandlers: a.responseHandlers.filter(
      (_: any, j: number) => j !== hi,
    ),
  });
}
function patchApiHandler(i: number, hi: number, partial: any) {
  const a = actionsArray.value[i];
  patchAction(i, {
    responseHandlers: a.responseHandlers.map((h: any, j: number) =>
      j === hi ? { ...h, ...partial } : h,
    ),
  });
}
function addApiCond(i: number, hi: number) {
  const h = actionsArray.value[i].responseHandlers[hi];
  patchApiHandler(i, hi, {
    conditions: [
      ...h.conditions,
      {
        id: uuidv4(),
        responseField: "status",
        responsePath: "",
        operator: "equals",
        value: "",
      },
    ],
  });
}
function removeApiCond(i: number, hi: number, ci: number) {
  const h = actionsArray.value[i].responseHandlers[hi];
  patchApiHandler(i, hi, {
    conditions: h.conditions.filter((_: any, j: number) => j !== ci),
  });
}
function updateApiCond(
  i: number,
  hi: number,
  ci: number,
  field: string,
  val: any,
) {
  const h = actionsArray.value[i].responseHandlers[hi];
  patchApiHandler(i, hi, {
    conditions: h.conditions.map((c: any, j: number) =>
      j === ci ? { ...c, [field]: val } : c,
    ),
  });
}
function setApiHandlerActions(i: number, hi: number, actions: any[]) {
  patchApiHandler(i, hi, { actions });
}
function setApiDefaultActions(i: number, actions: any[]) {
  patchAction(i, { defaultActions: actions });
}

// ── Drawer-only ───────────────────────────────────────────────────────────────
function save() {
  store.closeActionEditor();
}
function close() {
  store.closeActionEditor();
}
</script>

<template>
  <!-- ════════════════════════════════════ ROOT LEVEL: drawer chrome ════ -->
  <VNavigationDrawer
    v-if="rootLevel"
    :model-value="store.show"
    app
    temporary
    location="end"
    width="900"
    elevation="0"
    class="action-editor"
    style="z-index: 2000 !important"
    @update:model-value="close"
  >
    <div class="ae-header">
      <div class="d-flex align-center gap-3">
        <div class="ae-header__icon">
          <VIcon icon="$lightningBolt" size="18" color="primary" />
        </div>
        <div>
          <div class="ae-header__title">{{ title }}</div>
          <div class="ae-header__subtitle">{{ subtitle }}</div>
        </div>
      </div>
      <div class="d-flex align-center gap-1">
        <VBtn
          variant="text"
          size="small"
          prepend-icon="$plus"
          @click="addAction"
          >Add Action</VBtn
        >
        <VBtn icon="$close" variant="text" size="small" @click="close" />
      </div>
    </div>
    <VDivider />

    <PerfectScrollbar class="ae-body">
      <div v-if="actionsArray.length === 0" class="ae-empty">
        <div class="ae-empty__icon">
          <VIcon
            icon="$lightningBoltOutline"
            size="32"
            color="medium-emphasis"
          />
        </div>
        <p class="text-body-2 text-medium-emphasis mb-0">
          No actions yet. Add one below.
        </p>
      </div>

      <ActionTree
        v-else
        :root-level="false"
        :actions="actionsArray"
        :node-options="nodeOptionsRef"
        :available-variables="availableVariablesRef"
        :api-integrations="apiIntegrationsRef"
        :custom-functions="customFunctionsRef"
        :saved-responses="savedResponsesRef"
        @update:actions="actionsArray = $event"
      />

      <div class="ae-add-btn">
        <VBtn
          variant="outlined"
          rounded="lg"
          prepend-icon="$plus"
          color="primary"
          block
          @click="addAction"
          >Add Action</VBtn
        >
      </div>
    </PerfectScrollbar>

    <div class="ae-footer">
      <VBtn variant="outlined" rounded="sm" @click="close">Discard</VBtn>
      <VSpacer />
      <div class="text-caption text-medium-emphasis">
        Changes are saved to the flow automatically
      </div>
      <VBtn color="primary" rounded="sm" prepend-icon="$check" @click="save"
        >Done</VBtn
      >
    </div>
  </VNavigationDrawer>

  <!-- ════════════════════════════════════ NESTED: bare action rows ═════ -->
  <div v-else class="ab-root">
    <div
      v-for="(action, idx) in actionsArray"
      :key="action.id ?? idx"
      class="ab-row"
      :style="{ '--ab-accent': getActionMeta(action.kind).color }"
    >
      <div class="ab-row__hd">
        <div class="ab-pills">
          <button
            v-for="t in ACTION_TYPES"
            :key="t.value"
            class="ab-pill"
            :class="{ 'ab-pill--on': action.kind === t.value }"
            :style="
              action.kind === t.value
                ? {
                    borderColor: t.color,
                    background: t.color + '15',
                    color: t.color,
                  }
                : {}
            "
            @click="changeKind(idx, t.value)"
          >
            <VIcon :icon="t.icon" size="11" />{{ t.title }}
          </button>
        </div>
        <VBtn
          icon="$close"
          size="x-small"
          variant="text"
          color="error"
          @click="removeAction(idx)"
        />
      </div>

      <div class="ab-row__bd">
        <!-- NAVIGATE -->
        <template v-if="action.kind === 'navigation'">
          <VAutocomplete
            :model-value="action.goTo"
            :items="nodeOptionsRef"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            placeholder="Navigate to..."
            class="flex-1"
            @update:model-value="patchAction(idx, { goTo: $event })"
          />
        </template>

        <!-- VARIABLE -->
        <template v-else-if="action.kind === 'variable'">
          <VCombobox
            :model-value="action.varName"
            :items="availableVariablesRef"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            placeholder="Variable name..."
            class="flex-1"
            @update:model-value="patchAction(idx, { varName: $event })"
          />
          <VAutocomplete
            :model-value="action.dataType"
            :items="DATA_TYPES"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            style="max-width: 110px"
            @update:model-value="patchAction(idx, { dataType: $event })"
          />
          <VTextField
            :model-value="action.varValue"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            placeholder="Value or {{variable}}"
            class="flex-1"
            @update:model-value="patchAction(idx, { varValue: $event })"
          />
        </template>

        <!-- DELAY -->
        <template v-else-if="action.kind === 'delay'">
          <VTextField
            :model-value="action.seconds"
            type="number"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            min="1"
            max="3600"
            prepend-inner-icon="$timerOutline"
            suffix="seconds"
            style="max-width: 200px"
            @update:model-value="patchAction(idx, { seconds: Number($event) })"
          />
        </template>

        <!-- FUNCTION -->
        <template v-else-if="action.kind === 'function'">
          <VAutocomplete
            :model-value="action.fnId"
            :items="customFunctionsRef"
            item-title="name"
            item-value="id"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            placeholder="Select function..."
            class="flex-1"
            @update:model-value="patchAction(idx, { fnId: $event })"
          />
          <VCombobox
            :model-value="action.resultVar"
            :items="availableVariablesRef"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            clearable
            placeholder="Save result to..."
            style="max-width: 180px"
            @update:model-value="patchAction(idx, { resultVar: $event })"
          />
        </template>

        <!-- HANDOFF -->
        <template v-else-if="action.kind === 'handoff'">
          <div class="ab-handoff">
            <VIcon icon="$headset" color="error" size="15" />Handoff to agent
          </div>
          <VAutocomplete
            :model-value="action.resumeAt"
            :items="nodeOptionsRef"
            variant="outlined"
            density="compact"
            rounded="lg"
            hide-details
            clearable
            placeholder="Resume at... (optional)"
            class="flex-1"
            @update:model-value="patchAction(idx, { resumeAt: $event })"
          />
        </template>

        <!-- CONDITION -->
        <template v-else-if="action.kind === 'condition'">
          <div class="ab-block">
            <div
              v-for="(branch, bi) in action.branches ?? []"
              :key="branch.id"
              class="ab-branch"
            >
              <div class="ab-branch__bar">
                <div class="ab-bar-left">
                  <span class="ab-badge ab-badge--if">IF</span>
                  <div v-if="branch.conditions.length > 1" class="ab-logic">
                    <button
                      :class="{
                        'ab-logic--on': branch.conditionLogic === 'AND',
                      }"
                      @click="
                        patchCondBranch(idx, bi, { conditionLogic: 'AND' })
                      "
                    >
                      AND
                    </button>
                    <button
                      :class="{
                        'ab-logic--on': branch.conditionLogic === 'OR',
                      }"
                      @click="
                        patchCondBranch(idx, bi, { conditionLogic: 'OR' })
                      "
                    >
                      OR
                    </button>
                  </div>
                </div>
                <VBtn
                  v-if="(action.branches ?? []).length > 1"
                  icon="$trashCan"
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="removeCondBranch(idx, bi)"
                />
              </div>

              <div class="ab-branch__body">
                <div
                  v-for="(cond, ci) in branch.conditions"
                  :key="cond.id"
                  class="ab-cond-wrap"
                >
                  <div v-if="ci > 0" class="ab-logic-sep">
                    <span>{{ branch.conditionLogic }}</span>
                  </div>
                  <div class="ab-cond">
                    <div class="ab-ctype-tabs">
                      <button
                        v-for="ct in COND_TYPES"
                        :key="ct.value"
                        class="ab-ctype-tab"
                        :class="{ 'ab-ctype-tab--on': cond.type === ct.value }"
                        @click="updateCondCond(idx, bi, ci, 'type', ct.value)"
                      >
                        <VIcon :icon="ct.icon" size="12" />{{ ct.title }}
                      </button>
                    </div>
                    <div class="ab-cond-fields">
                      <template v-if="cond.type === 'variable'">
                        <VCombobox
                          :model-value="cond.source"
                          :items="availableVariablesRef"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="variable..."
                          style="flex: 1.4; min-width: 120px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'source', $event)
                          "
                        />
                        <VAutocomplete
                          :model-value="cond.operator"
                          :items="COND_OPERATORS"
                          item-title="title"
                          item-value="value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          style="flex: 1.1; min-width: 130px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'operator', $event)
                          "
                        />
                        <VTextField
                          v-if="needsValue(cond.operator)"
                          :model-value="cond.value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="value..."
                          style="flex: 1.2; min-width: 100px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'value', $event)
                          "
                        />
                      </template>
                      <template v-else-if="cond.type === 'saved_response'">
                        <VAutocomplete
                          :model-value="cond.source"
                          :items="
                            (savedResponsesRef ?? []).map((r: any) => ({
                              title: r.label,
                              value: r.optionId,
                            }))
                          "
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="Which option the user tapped..."
                          style="flex: 1"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'source', $event)
                          "
                        />
                      </template>
                      <template v-else-if="cond.type === 'user_input'">
                        <VAutocomplete
                          :model-value="cond.operator"
                          :items="COND_OPERATORS"
                          item-title="title"
                          item-value="value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          style="flex: 1.1; min-width: 130px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'operator', $event)
                          "
                        />
                        <VTextField
                          v-if="needsValue(cond.operator)"
                          :model-value="cond.value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="e.g. yes"
                          style="flex: 1.2; min-width: 100px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'value', $event)
                          "
                        />
                      </template>
                      <template v-else-if="cond.type === 'api_response'">
                        <VAutocomplete
                          :model-value="cond.responsePath"
                          :items="RESPONSE_FIELDS"
                          item-title="title"
                          item-value="value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          style="flex: 1.1; min-width: 110px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'responsePath', $event)
                          "
                        />
                        <VTextField
                          v-if="cond.responsePath === 'body'"
                          :model-value="cond.fieldPath"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="data.user.status"
                          style="flex: 1.3; min-width: 110px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'fieldPath', $event)
                          "
                        />
                        <VAutocomplete
                          :model-value="cond.operator"
                          :items="COND_OPERATORS"
                          item-title="title"
                          item-value="value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          style="flex: 1; min-width: 110px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'operator', $event)
                          "
                        />
                        <VTextField
                          v-if="needsValue(cond.operator)"
                          :model-value="cond.value"
                          variant="outlined"
                          density="compact"
                          rounded="lg"
                          hide-details
                          placeholder="200"
                          style="flex: 0.9; min-width: 80px"
                          @update:model-value="
                            updateCondCond(idx, bi, ci, 'value', $event)
                          "
                        />
                      </template>
                    </div>
                    <VBtn
                      icon="$close"
                      size="x-small"
                      variant="text"
                      :disabled="branch.conditions.length === 1"
                      @click="removeCondCond(idx, bi, ci)"
                    />
                  </div>
                </div>
                <VBtn
                  variant="text"
                  size="x-small"
                  prepend-icon="$plus"
                  color="primary"
                  class="mb-2"
                  @click="addCondCond(idx, bi)"
                  >Add condition</VBtn
                >

                <div class="ab-then-label">THEN</div>
                <ActionTree
                  :root-level="false"
                  :actions="branch.actions ?? []"
                  :node-options="nodeOptionsRef"
                  :available-variables="availableVariablesRef"
                  :api-integrations="apiIntegrationsRef"
                  :custom-functions="customFunctionsRef"
                  :saved-responses="savedResponsesRef"
                  @update:actions="setCondBranchActions(idx, bi, $event)"
                />
              </div>
            </div>

            <VBtn
              variant="outlined"
              rounded="lg"
              size="small"
              color="primary"
              prepend-icon="$plus"
              block
              class="mt-1"
              @click="addCondBranch(idx)"
              >Add IF Branch</VBtn
            >

            <div
              v-if="action.defaultBranch"
              class="ab-branch ab-branch--else mt-2"
            >
              <div class="ab-branch__bar">
                <div class="ab-bar-left">
                  <span class="ab-badge ab-badge--else">ELSE</span>
                  <span class="ab-bar-hint">— no IF matched</span>
                </div>
                <VBtn
                  icon="$trashCan"
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="toggleCondElse(idx)"
                />
              </div>
              <div class="ab-branch__body">
                <div class="ab-then-label">THEN</div>
                <ActionTree
                  :root-level="false"
                  :actions="action.defaultBranch.actions ?? []"
                  :node-options="nodeOptionsRef"
                  :available-variables="availableVariablesRef"
                  :api-integrations="apiIntegrationsRef"
                  :custom-functions="customFunctionsRef"
                  :saved-responses="savedResponsesRef"
                  @update:actions="setCondDefaultActions(idx, $event)"
                />
              </div>
            </div>
            <VBtn
              v-else
              variant="outlined"
              rounded="lg"
              size="small"
              color="grey-darken-1"
              prepend-icon="$plus"
              block
              class="mt-2"
              @click="toggleCondElse(idx)"
              >Add ELSE (Default)</VBtn
            >
          </div>
        </template>

        <!-- API CALL -->
        <template v-else-if="action.kind === 'api'">
          <div class="ab-block">
            <div class="ab-api-top">
              <div class="ab-field" style="flex: 1.4">
                <label class="ab-lbl">API Integration</label>
                <VAutocomplete
                  :model-value="action.apiConfigId"
                  :items="apiIntegrationsRef"
                  item-title="name"
                  item-value="id"
                  variant="outlined"
                  density="compact"
                  rounded="lg"
                  hide-details
                  clearable
                  placeholder="Select configured API..."
                  no-data-text="No APIs configured yet"
                  prepend-inner-icon="$api"
                  @update:model-value="
                    patchAction(idx, { apiConfigId: $event })
                  "
                />
              </div>
            </div>

            <div class="ab-handlers">
              <div class="ab-handlers-hd">
                <span class="ab-lbl">Response Handlers</span>
                <VBtn
                  variant="outlined"
                  size="x-small"
                  rounded="lg"
                  prepend-icon="$plus"
                  @click="addApiHandler(idx)"
                  >Add IF Handler</VBtn
                >
              </div>

              <div
                v-for="(handler, hi) in action.responseHandlers ?? []"
                :key="handler.id"
                class="ab-handler"
              >
                <div class="ab-handler__bar">
                  <div class="ab-bar-left">
                    <span class="ab-badge ab-badge--if">IF</span>
                    <span class="ab-bar-hint">response matches</span>
                  </div>
                  <VBtn
                    icon="$trashCan"
                    size="x-small"
                    variant="text"
                    color="error"
                    @click="removeApiHandler(idx, hi)"
                  />
                </div>
                <div class="ab-handler__body">
                  <div
                    v-for="(hc, ci) in handler.conditions"
                    :key="hc.id"
                    class="ab-api-cond"
                  >
                    <VAutocomplete
                      :model-value="hc.responseField"
                      :items="RESPONSE_FIELDS"
                      item-title="title"
                      item-value="value"
                      variant="outlined"
                      density="compact"
                      rounded="lg"
                      hide-details
                      style="flex: 1.2"
                      @update:model-value="
                        updateApiCond(idx, hi, ci, 'responseField', $event)
                      "
                    />
                    <VTextField
                      v-if="hc.responseField === 'body'"
                      :model-value="hc.responsePath"
                      variant="outlined"
                      density="compact"
                      rounded="lg"
                      hide-details
                      placeholder="data.status"
                      style="flex: 1.5"
                      @update:model-value="
                        updateApiCond(idx, hi, ci, 'responsePath', $event)
                      "
                    />
                    <VAutocomplete
                      :model-value="hc.operator"
                      :items="API_OPERATORS"
                      item-title="title"
                      item-value="value"
                      variant="outlined"
                      density="compact"
                      rounded="lg"
                      hide-details
                      style="flex: 0.9"
                      @update:model-value="
                        updateApiCond(idx, hi, ci, 'operator', $event)
                      "
                    />
                    <VTextField
                      v-if="needsValue(hc.operator)"
                      :model-value="hc.value"
                      variant="outlined"
                      density="compact"
                      rounded="lg"
                      hide-details
                      placeholder="200"
                      style="flex: 1.2"
                      @update:model-value="
                        updateApiCond(idx, hi, ci, 'value', $event)
                      "
                    />
                    <VBtn
                      icon="$close"
                      size="x-small"
                      variant="text"
                      :disabled="handler.conditions.length === 1"
                      @click="removeApiCond(idx, hi, ci)"
                    />
                  </div>
                  <VBtn
                    variant="text"
                    size="x-small"
                    prepend-icon="$plus"
                    color="primary"
                    class="mt-1 mb-2"
                    @click="addApiCond(idx, hi)"
                    >Add condition</VBtn
                  >

                  <div class="ab-then-label">THEN</div>
                  <ActionTree
                    :root-level="false"
                    :actions="handler.actions ?? []"
                    :node-options="nodeOptionsRef"
                    :available-variables="availableVariablesRef"
                    :api-integrations="apiIntegrationsRef"
                    :custom-functions="customFunctionsRef"
                    :saved-responses="savedResponsesRef"
                    @update:actions="setApiHandlerActions(idx, hi, $event)"
                  />
                </div>
              </div>

              <div class="ab-handler ab-handler--else">
                <div class="ab-handler__bar">
                  <div class="ab-bar-left">
                    <span class="ab-badge ab-badge--else">ELSE</span>
                    <span class="ab-bar-hint">— no IF handler matched</span>
                  </div>
                </div>
                <div class="ab-handler__body">
                  <div class="ab-then-label">THEN</div>
                  <ActionTree
                    :root-level="false"
                    :actions="action.defaultActions ?? []"
                    :node-options="nodeOptionsRef"
                    :available-variables="availableVariablesRef"
                    :api-integrations="apiIntegrationsRef"
                    :custom-functions="customFunctionsRef"
                    :saved-responses="savedResponsesRef"
                    @update:actions="setApiDefaultActions(idx, $event)"
                  />
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>

      <!-- THEN chain (next action after this one, any kind) -->
      <div class="then-chain mt-3 px-2 pb-2">
        <VDivider class="mb-2" />
        <div class="d-flex align-center justify-space-between mb-2">
          <span class="field-label" style="color: rgb(var(--v-theme-primary))"
            >THEN (next action)</span
          >
          <VBtn
            v-if="!action.then"
            variant="text"
            size="x-small"
            prepend-icon="$plus"
            color="primary"
            @click="patchAction(idx, { then: makeAction('navigation') })"
            >Add next action</VBtn
          >
          <VBtn
            v-else
            icon="$close"
            size="x-small"
            variant="text"
            color="error"
            @click="patchAction(idx, { then: null })"
          />
        </div>
        <div v-if="action.then" class="then-slot">
          <ActionTree
            :root-level="false"
            :actions="[action.then]"
            :node-options="nodeOptionsRef"
            :available-variables="availableVariablesRef"
            :api-integrations="apiIntegrationsRef"
            :custom-functions="customFunctionsRef"
            :saved-responses="savedResponsesRef"
            @update:actions="patchAction(idx, { then: $event[0] ?? null })"
          />
        </div>
      </div>
    </div>

    <VBtn
      variant="text"
      size="x-small"
      prepend-icon="$plus"
      color="primary"
      @click="addAction"
      >Add action</VBtn
    >
  </div>
</template>

<style scoped>
/* Drawer chrome (root level only) */
.action-editor :deep(.v-navigation-drawer__content) {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.ae-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  flex-shrink: 0;
}
.ae-header__icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}
.ae-header__title {
  font-size: 15px;
  font-weight: 600;
}
.ae-header__subtitle {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin-top: 1px;
}
.ae-body {
  flex: 1;
  min-height: 0;
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ae-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 40px 20px;
  border: 2px dashed rgba(var(--v-border-color), 0.4);
  border-radius: 12px;
}
.ae-empty__icon {
  width: 56px;
  height: 56px;
  border-radius: 16px;
  background: rgba(var(--v-theme-on-surface), 0.04);
  display: flex;
  align-items: center;
  justify-content: center;
}
.ae-add-btn {
  padding: 4px 0 8px;
  flex-shrink: 0;
}
.ae-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  flex-shrink: 0;
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
}

/* Recursive rows (any level) */
.ab-root {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ab-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-left: 3px solid var(--ab-accent, rgba(var(--v-theme-primary), 0.5));
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.01);
  overflow: hidden;
}
.ab-row__hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 8px;
  flex-wrap: wrap;
  gap: 4px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ab-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  flex: 1;
}
.ab-pill {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 12px;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  background: none;
  color: rgba(var(--v-theme-on-surface), 0.55);
  transition: all 0.15s;
}
.ab-pill:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.ab-pill--on {
  font-weight: 600;
}
.ab-row__bd {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: 6px;
  padding: 8px;
}
.ab-row__bd .ab-block {
  width: 100%;
}
.ab-handoff {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: #ef4444;
  background: rgba(239, 68, 68, 0.07);
  border: 1px solid rgba(239, 68, 68, 0.2);
  border-radius: 6px;
  padding: 5px 9px;
  flex-shrink: 0;
}
.ab-block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.ab-branch {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 9px;
  overflow: hidden;
}
.ab-branch--else {
  border-style: dashed;
  border-color: rgba(var(--v-theme-on-surface), 0.2);
}
.ab-branch__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 11px;
  gap: 8px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ab-bar-left {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ab-bar-hint {
  font-size: 11px;
  color: rgba(var(--v-theme-on-surface), 0.4);
}
.ab-branch__body {
  padding: 10px 11px;
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.ab-badge {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  padding: 3px 9px;
  border-radius: 5px;
  flex-shrink: 0;
}
.ab-badge--if {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}
.ab-badge--else {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.55);
}
.ab-logic {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  overflow: hidden;
}
.ab-logic button {
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 700;
  background: none;
  border: none;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.45);
  transition: all 0.12s;
}
.ab-logic--on {
  background: rgb(var(--v-theme-primary)) !important;
  color: #fff !important;
}
.ab-logic-sep {
  display: flex;
  padding: 2px 0;
}
.ab-logic-sep span {
  font-size: 9px;
  font-weight: 800;
  padding: 1px 7px;
  border-radius: 9px;
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
}
.ab-cond-wrap {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.ab-cond {
  display: flex;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px 9px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 7px;
}
.ab-ctype-tabs {
  display: flex;
  gap: 3px;
  flex-wrap: wrap;
  width: 100%;
}
.ab-ctype-tab {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 8px;
  border-radius: 14px;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  background: none;
  color: rgba(var(--v-theme-on-surface), 0.55);
  transition: all 0.12s;
}
.ab-ctype-tab:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}
.ab-ctype-tab--on {
  background: rgba(var(--v-theme-primary), 0.1);
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
  font-weight: 700;
}
.ab-cond-fields {
  display: flex;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}
.ab-then-label {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 4px;
}
.ab-api-top {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}
.ab-field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.ab-lbl {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.45);
}
.ab-handlers {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 9px;
  overflow: hidden;
}
.ab-handlers-hd {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 11px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ab-handler {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ab-handler:last-child {
  border-bottom: none;
}
.ab-handler--else {
  background: rgba(var(--v-theme-on-surface), 0.012);
}
.ab-handler__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 11px;
  gap: 8px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
.ab-handler__body {
  padding: 9px 11px;
}
.ab-api-cond {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 5px;
}
.field-label {
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}
</style>
