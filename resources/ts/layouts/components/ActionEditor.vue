<script setup lang="ts">
import { computed } from "vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import { v4 as uuidv4 } from "uuid";
import ActionBranch from "@/components/bots/actions/ActionBranch.vue";

const store = useActionEditorStore();

// ── Constants ─────────────────────────────────────────────────────────────────
const ACTION_TYPES = [
  { value: "navigation", title: "Navigate", icon: "$arrowRight", color: "#3b82f6" },
  { value: "condition", title: "Condition", icon: "$sourceBranch", color: "#8b5cf6" },
  { value: "variable", title: "Set Variable", icon: "$variable", color: "#f59e0b" },
  { value: "api", title: "API Call", icon: "$api", color: "#10b981" },
  { value: "function", title: "Run Function", icon: "$functionVariant", color: "#06b6d4" },
  { value: "delay", title: "Delay", icon: "$timerOutline", color: "#6b7280" },
  { value: "handoff", title: "Handoff to Agent", icon: "$headset", color: "#ef4444" },
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
  { value: "saved_response", title: "User Selected", icon: "$cursorDefaultClick" },
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

// ── Shape factories ───────────────────────────────────────────────────────────
function makeCondition() {
  return {
    id: uuidv4(), type: "variable", source: "",
    responsePath: "status", fieldPath: "", operator: "equals", value: "",
  };
}

function makeBranch() {
  return {
    id: uuidv4(), conditionLogic: "AND",
    conditions: [makeCondition()],
    actions: [] as any[],
  };
}

function makeApiHandler() {
  return {
    id: uuidv4(),
    conditions: [{ id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "200" }],
    actions: [] as any[],
  };
}

function makeAction(kind: string, id?: string): any {
  const _id = id ?? uuidv4();
  const base = { id: _id, kind, then: null };  // ← always include then: null
  if (kind === "condition") return {
    ...base,
    branches: [makeBranch()],
    defaultBranch: { id: uuidv4(), actions: [] },
  };
  if (kind === "api") return {
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

function needsValue(op: string) { return !["is_empty", "is_not_empty"].includes(op); }

// ── Reactive root actions ─────────────────────────────────────────────────────
const actionsArray = computed({
  get: () => {
    if (store.targetButton) return store.targetButton.actions ?? [];
    if (store.targetRow) return store.targetRow.actions ?? [];
    return store.targetNode?.actions ?? [];
  },
  set: (val) => {
    if (store.targetButton) store.targetButton.actions = val;
    else if (store.targetRow) store.targetRow.actions = val;
    else if (store.targetNode) store.targetNode.actions = val;
  },
});

const title = computed(() => {
  if (store.targetButton) return store.targetButton.label || "Button Actions";
  if (store.targetRow) return store.targetRow.title || "List Item Actions";
  return store.targetNode?.kind ? `${store.targetNode.kind} Node Actions` : "Actions";
});

const subtitle = computed(() => {
  if (store.targetButton) return "Configure what happens when user taps this button";
  if (store.targetRow) return "Configure what happens when user selects this item";
  return "Configure what this node does after the user interacts";
});

function getActionMeta(kind: string) {
  return ACTION_TYPES.find(t => t.value === kind) ?? ACTION_TYPES[0];
}

// ── Root action list mutations ────────────────────────────────────────────────
function addAction() {
  actionsArray.value = [...actionsArray.value, makeAction("navigation")];
}
function removeAction(idx: number) {
  const a = [...actionsArray.value]; a.splice(idx, 1); actionsArray.value = a;
}
function moveAction(idx: number, dir: -1 | 1) {
  const t = idx + dir;
  if (t < 0 || t >= actionsArray.value.length) return;
  const a = [...actionsArray.value];[a[idx], a[t]] = [a[t], a[idx]]; actionsArray.value = a;
}
function changeKind(idx: number, kind: string) {
  const id = actionsArray.value[idx]?.id ?? uuidv4();
  const a = [...actionsArray.value]; a[idx] = makeAction(kind, id); actionsArray.value = a;
}
function patchAction(idx: number, partial: any) {
  const a = [...actionsArray.value];
  a[idx] = { ...a[idx], ...partial };
  actionsArray.value = a;
}

// ── Condition branch mutations ────────────────────────────────────────────────
function addCondBranch(idx: number) {
  patchAction(idx, { branches: [...(actionsArray.value[idx].branches ?? []), makeBranch()] });
}
function removeCondBranch(idx: number, bi: number) {
  const a = actionsArray.value[idx];
  if ((a.branches ?? []).length <= 1) return;
  patchAction(idx, { branches: a.branches.filter((_: any, j: number) => j !== bi) });
}
function patchCondBranch(idx: number, bi: number, partial: any) {
  const a = actionsArray.value[idx];
  patchAction(idx, {
    branches: a.branches.map((b: any, j: number) => j === bi ? { ...b, ...partial } : b),
  });
}
function addCondCond(idx: number, bi: number) {
  const b = actionsArray.value[idx].branches[bi];
  patchCondBranch(idx, bi, { conditions: [...b.conditions, makeCondition()] });
}
function removeCondCond(idx: number, bi: number, ci: number) {
  const b = actionsArray.value[idx].branches[bi];
  patchCondBranch(idx, bi, { conditions: b.conditions.filter((_: any, j: number) => j !== ci) });
}
function updateCondCond(idx: number, bi: number, ci: number, field: string, val: any) {
  const b = actionsArray.value[idx].branches[bi];
  if (field === "type") {
    const id = b.conditions[ci].id;
    patchCondBranch(idx, bi, {
      conditions: b.conditions.map((c: any, j: number) => j === ci ? { ...makeCondition(), id, type: val } : c),
    });
    return;
  }
  patchCondBranch(idx, bi, {
    conditions: b.conditions.map((c: any, j: number) => j === ci ? { ...c, [field]: val } : c),
  });
}
function setCondBranchActions(idx: number, bi: number, actions: any[]) {
  patchCondBranch(idx, bi, { actions });
}
function setCondDefaultActions(idx: number, actions: any[]) {
  const a = actionsArray.value[idx];
  patchAction(idx, { defaultBranch: { ...(a.defaultBranch ?? { id: uuidv4() }), actions } });
}
function toggleCondElse(idx: number) {
  const a = actionsArray.value[idx];
  patchAction(idx, { defaultBranch: a.defaultBranch ? null : { id: uuidv4(), actions: [] } });
}

// ── API handler mutations ─────────────────────────────────────────────────────
function addApiHandler(idx: number) {
  patchAction(idx, { responseHandlers: [...(actionsArray.value[idx].responseHandlers ?? []), makeApiHandler()] });
}
function removeApiHandler(idx: number, hi: number) {
  const a = actionsArray.value[idx];
  patchAction(idx, { responseHandlers: a.responseHandlers.filter((_: any, j: number) => j !== hi) });
}
function patchApiHandler(idx: number, hi: number, partial: any) {
  const a = actionsArray.value[idx];
  patchAction(idx, {
    responseHandlers: a.responseHandlers.map((h: any, j: number) => j === hi ? { ...h, ...partial } : h),
  });
}
function addApiCond(idx: number, hi: number) {
  const h = actionsArray.value[idx].responseHandlers[hi];
  patchApiHandler(idx, hi, { conditions: [...h.conditions, { id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "" }] });
}
function removeApiCond(idx: number, hi: number, ci: number) {
  const h = actionsArray.value[idx].responseHandlers[hi];
  patchApiHandler(idx, hi, { conditions: h.conditions.filter((_: any, j: number) => j !== ci) });
}
function updateApiCond(idx: number, hi: number, ci: number, field: string, val: any) {
  const h = actionsArray.value[idx].responseHandlers[hi];
  patchApiHandler(idx, hi, {
    conditions: h.conditions.map((c: any, j: number) => j === ci ? { ...c, [field]: val } : c),
  });
}
function setApiHandlerActions(idx: number, hi: number, actions: any[]) {
  patchApiHandler(idx, hi, { actions });
}
function setApiDefaultActions(idx: number, actions: any[]) {
  patchAction(idx, { defaultActions: actions });
}

function save() { store.closeActionEditor(); }
function close() { store.closeActionEditor(); }
</script>

<template>
  <VNavigationDrawer :model-value="store.show" app temporary location="end" width="900" elevation="0"
    class="action-editor" style="z-index:2000!important;" @update:model-value="close">
    <!-- ── Header ────────────────────────────────────────────────────────── -->
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
        <VBtn variant="text" size="small" prepend-icon="$plus" @click="addAction">Add Action</VBtn>
        <VBtn icon="$close" variant="text" size="small" @click="close" />
      </div>
    </div>
    <VDivider />

    <!-- ── Scrollable body ───────────────────────────────────────────────── -->
    <PerfectScrollbar class="ae-body">

      <div v-if="actionsArray.length === 0" class="ae-empty">
        <div class="ae-empty__icon">
          <VIcon icon="$lightningBoltOutline" size="32" color="medium-emphasis" />
        </div>
        <p class="text-body-2 text-medium-emphasis mb-0">No actions yet. Add one below.</p>
      </div>

      <!-- ── Action cards ──────────────────────────────────────────────── -->
      <div v-for="(action, idx) in actionsArray" :key="action.id ?? idx" class="action-card">

        <!-- Card header -->
        <div class="action-card__header">
          <div class="d-flex align-center gap-2">
            <div class="action-card__type-icon"
              :style="{ background: getActionMeta(action.kind).color + '20', color: getActionMeta(action.kind).color }">
              <VIcon :icon="getActionMeta(action.kind).icon" size="15" />
            </div>
            <span class="text-caption font-weight-semibold text-medium-emphasis">ACTION {{ idx + 1 }}</span>
          </div>
          <div class="d-flex align-center gap-1">
            <VBtn icon="$arrowUp" size="x-small" variant="text" :disabled="idx === 0" @click="moveAction(idx, -1)" />
            <VBtn icon="$arrowDown" size="x-small" variant="text" :disabled="idx === actionsArray.length - 1"
              @click="moveAction(idx, 1)" />
            <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="removeAction(idx)" />
          </div>
        </div>

        <!-- Type selector -->
        <div class="action-card__type-row">
          <div class="type-selector">
            <div v-for="t in ACTION_TYPES" :key="t.value" class="type-option"
              :class="{ 'type-option--active': action.kind === t.value }"
              :style="action.kind === t.value ? { borderColor: t.color, background: t.color + '12' } : {}"
              @click="changeKind(idx, t.value)">
              <VIcon :icon="t.icon" size="16" :color="action.kind === t.value ? t.color : undefined" />
              <span>{{ t.title }}</span>
            </div>
          </div>
        </div>

        <VDivider class="mx-4" />

        <!-- ── Action body ─────────────────────────────────────────────── -->
        <div class="action-card__body">

          <!-- NAVIGATE -->
          <template v-if="action.kind === 'navigation'">
            <div class="field-group">
              <label class="field-label">Destination Node</label>
              <VAutocomplete :model-value="action.goTo" :items="store.nodeOptions" variant="outlined" density="compact"
                rounded="lg" hide-details placeholder="Select node to navigate to…" prepend-inner-icon="$arrowRight"
                @update:model-value="patchAction(idx, { goTo: $event })" />
            </div>
          </template>

          <!-- SET VARIABLE -->
          <template v-else-if="action.kind === 'variable'">
            <div class="field-group">
              <label class="field-label">Variable to Set</label>
              <VCombobox :model-value="action.varName" :items="store.availableVariables" variant="outlined"
                density="compact" rounded="lg" hide-details placeholder="e.g. district, user_age, city…"
                prepend-inner-icon="$variable" @update:model-value="patchAction(idx, { varName: $event })" />
            </div>
          </template>

          <!-- RUN FUNCTION -->
          <template v-else-if="action.kind === 'function'">
            <VRow dense>
              <VCol cols="12" sm="5">
                <div class="field-group">
                  <label class="field-label">Function</label>
                  <VAutocomplete :model-value="action.fnId" :items="store.customFunctions" item-title="name"
                    item-value="id" variant="outlined" density="compact" rounded="lg" hide-details
                    placeholder="Select function…" prepend-inner-icon="$functionVariant"
                    @update:model-value="patchAction(idx, { fnId: $event })" />
                </div>
              </VCol>
              <VCol cols="12" sm="3">
                <div class="field-group">
                  <label class="field-label">Save Result To</label>
                  <VCombobox :model-value="action.resultVar" :items="store.availableVariables" variant="outlined"
                    density="compact" rounded="lg" hide-details clearable placeholder="variable name…"
                    @update:model-value="patchAction(idx, { resultVar: $event })" />
                </div>
              </VCol>
              <VCol cols="12">
                <div class="field-group">
                  <label class="field-label">Parameters (JSON) <span class="text-caption text-medium-emphasis">—
                      &#123;&#123;variable&#125;&#125; supported</span></label>
                  <VTextarea :model-value="action.paramsRaw" variant="outlined" density="compact" rounded="lg"
                    hide-details rows="2" placeholder='{"key": "{{variable}}"}'
                    @update:model-value="patchAction(idx, { paramsRaw: $event })" />
                </div>
              </VCol>
            </VRow>
          </template>

          <!-- DELAY -->
          <template v-else-if="action.kind === 'delay'">
            <div class="field-group" style="max-width:240px">
              <label class="field-label">Wait Duration (seconds)</label>
              <VTextField :model-value="action.seconds" type="number" variant="outlined" density="compact" rounded="lg"
                hide-details min="1" max="3600" prepend-inner-icon="$timerOutline"
                @update:model-value="patchAction(idx, { seconds: $event })" />
            </div>
          </template>

          <!-- HANDOFF -->
          <template v-else-if="action.kind === 'handoff'">
            <VAlert type="warning" variant="tonal" rounded="lg" density="compact" class="mb-4">
              <div class="d-flex align-center gap-2">
                <VIcon icon="$headset" size="18" />
                <span class="text-body-2">Conversation will be handed off to a live agent</span>
              </div>
            </VAlert>
            <div class="field-group" style="max-width:360px">
              <label class="field-label">Resume at Node (after agent closes)</label>
              <VAutocomplete :model-value="action.resumeAt" :items="store.nodeOptions" variant="outlined"
                density="compact" rounded="lg" hide-details clearable placeholder="Optional — where to continue…"
                @update:model-value="patchAction(idx, { resumeAt: $event })" />
            </div>
          </template>

          <!-- ════════════════════════════════════════════════════════════════
               CONDITION — multiple IF branches + ELSE
               Each THEN uses <ActionBranch> for full recursion
          ════════════════════════════════════════════════════════════════ -->
          <template v-else-if="action.kind === 'condition'">
            <div class="cond-root">

              <!-- IF branches -->
              <div v-for="(branch, bi) in (action.branches ?? [])" :key="branch.id" class="cb-branch">
                <div class="cb-branch__bar">
                  <div class="d-flex align-center gap-2">
                    <span class="cb-label cb-label--if">IF</span>
                    <div v-if="branch.conditions.length > 1" class="logic-toggle">
                      <button :class="{ active: branch.conditionLogic === 'AND' }"
                        @click="patchCondBranch(idx, bi, { conditionLogic: 'AND' })">AND</button>
                      <button :class="{ active: branch.conditionLogic === 'OR' }"
                        @click="patchCondBranch(idx, bi, { conditionLogic: 'OR' })">OR</button>
                    </div>
                  </div>
                  <VBtn v-if="(action.branches ?? []).length > 1" icon="$trashCan" size="x-small" variant="text"
                    color="error" @click="removeCondBranch(idx, bi)" />
                </div>

                <div class="cb-branch__body">
                  <!-- conditions -->
                  <div v-for="(cond, ci) in branch.conditions" :key="cond.id" class="cb-condition">
                    <div v-if="ci > 0" class="cb-logic-pill"><span>{{ branch.conditionLogic }}</span></div>
                    <div class="cb-condition__row">
                      <div class="ctype-tabs">
                        <button v-for="ct in COND_TYPES" :key="ct.value" class="ctype-tab"
                          :class="{ 'ctype-tab--active': cond.type === ct.value }"
                          @click="updateCondCond(idx, bi, ci, 'type', ct.value)">
                          <VIcon :icon="ct.icon" size="13" />{{ ct.title }}
                        </button>
                      </div>
                      <!-- VARIABLE -->
                      <template v-if="cond.type === 'variable'">
                        <div class="cb-condition__fields">
                          <VCombobox :model-value="cond.source" :items="store.availableVariables" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="e.g. district, user_age…"
                            prepend-inner-icon="$variable" style="flex:1.5;min-width:140px"
                            @update:model-value="updateCondCond(idx, bi, ci, 'source', $event)" />
                          <VAutocomplete :model-value="cond.operator" :items="COND_OPERATORS" item-title="title"
                            item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                            style="min-width:160px;flex:1.2"
                            @update:model-value="updateCondCond(idx, bi, ci, 'operator', $event)" />
                          <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="e.g. Blantyre, 42…"
                            style="flex:1.5;min-width:120px"
                            @update:model-value="updateCondCond(idx, bi, ci, 'value', $event)" />
                        </div>
                      </template>
                      <!-- SAVED RESPONSE -->
                      <template v-else-if="cond.type === 'saved_response'">
                        <div class="cb-condition__fields">
                          <VAutocomplete :model-value="cond.source"
                            :items="(store.savedResponses ?? []).map((r: any) => ({ title: r.label, value: r.optionId }))"
                            variant="outlined" density="compact" rounded="lg" hide-details
                            placeholder="Which option the user tapped…" style="flex:1"
                            @update:model-value="updateCondCond(idx, bi, ci, 'source', $event)" />
                        </div>
                      </template>
                      <!-- API RESPONSE -->
                      <template v-else-if="cond.type === 'api_response'">
                        <div class="cb-condition__fields">
                          <VAutocomplete :model-value="cond.responsePath" :items="RESPONSE_FIELDS" item-title="title"
                            item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                            style="flex:1.2"
                            @update:model-value="updateCondCond(idx, bi, ci, 'responsePath', $event)" />
                          <VTextField v-if="cond.responsePath === 'body'" :model-value="cond.fieldPath"
                            variant="outlined" density="compact" rounded="lg" hide-details
                            placeholder="data.user.status" style="flex:1.5"
                            @update:model-value="updateCondCond(idx, bi, ci, 'fieldPath', $event)" />
                          <VAutocomplete :model-value="cond.operator" :items="COND_OPERATORS" item-title="title"
                            item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                            style="flex:1" @update:model-value="updateCondCond(idx, bi, ci, 'operator', $event)" />
                          <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="200" style="flex:1.2"
                            @update:model-value="updateCondCond(idx, bi, ci, 'value', $event)" />
                        </div>
                      </template>
                      <VBtn icon="$close" size="x-small" variant="text" :disabled="branch.conditions.length === 1"
                        @click="removeCondCond(idx, bi, ci)" />
                    </div>
                  </div>
                  <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mb-1"
                    @click="addCondCond(idx, bi)">Add
                    condition</VBtn>

                  <!-- THEN ─────────────────────────────────────────────── -->
                  <div class="cb-section-label cb-section-label--then mt-3">THEN</div>
                  <ActionBranch :actions="branch.actions ?? []" :node-options="store.nodeOptions"
                    :available-variables="store.availableVariables" :api-integrations="store.apiIntegrations"
                    :custom-functions="store.customFunctions" :saved-responses="store.savedResponses"
                    @update:actions="setCondBranchActions(idx, bi, $event)" />
                </div>
              </div><!-- /branch loop -->

              <!-- Add IF branch -->
              <VBtn variant="outlined" rounded="lg" size="small" color="primary" prepend-icon="$plus" block class="mt-1"
                @click="addCondBranch(idx)">
                Add IF Branch
              </VBtn>

              <!-- ELSE -->
              <div v-if="action.defaultBranch" class="cb-branch cb-branch--else mt-2">
                <div class="cb-branch__bar">
                  <div class="d-flex align-center gap-2">
                    <span class="cb-label cb-label--else">ELSE</span>
                    <span class="text-caption text-medium-emphasis">— runs when no IF matched</span>
                  </div>
                  <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="toggleCondElse(idx)" />
                </div>
                <div class="cb-branch__body">
                  <div class="cb-section-label cb-section-label--then">THEN</div>
                  <ActionBranch :actions="action.defaultBranch.actions ?? []" :node-options="store.nodeOptions"
                    :available-variables="store.availableVariables" :api-integrations="store.apiIntegrations"
                    :custom-functions="store.customFunctions" :saved-responses="store.savedResponses"
                    @update:actions="setCondDefaultActions(idx, $event)" />
                </div>
              </div>
              <VBtn v-else variant="outlined" rounded="lg" size="small" color="grey-darken-1" prepend-icon="$plus" block
                class="mt-2" @click="toggleCondElse(idx)">
                Add Else (Default)
              </VBtn>

            </div>
          </template>

          <!-- ════════════════════════════════════════════════════════════════
               API CALL — multiple IF handlers + ELSE
               Each THEN uses <ActionBranch> for full recursion
          ════════════════════════════════════════════════════════════════ -->
          <template v-else-if="action.kind === 'api'">
            <VRow dense class="mb-4">
              <VCol cols="12" sm="7">
                <div class="field-group">
                  <label class="field-label">API Integration</label>
                  <VAutocomplete :model-value="action.apiConfigId" :items="store.apiIntegrations" item-title="name"
                    item-value="id" variant="outlined" density="compact" rounded="lg" hide-details clearable
                    placeholder="Select configured API…" no-data-text="No APIs configured yet" prepend-inner-icon="$api"
                    @update:model-value="patchAction(idx, { apiConfigId: $event })" />
                </div>
              </VCol>
              <VCol cols="12" sm="5">
                <div class="field-group">
                  <label class="field-label">Save Response To <span
                      class="text-medium-emphasis font-weight-regular">(optional)</span></label>
                  <VCombobox :model-value="action.apiResultVar" :items="store.availableVariables" variant="outlined"
                    density="compact" rounded="lg" hide-details clearable placeholder="variable name…"
                    @update:model-value="patchAction(idx, { apiResultVar: $event })" />
                </div>
              </VCol>
            </VRow>

            <div class="response-handlers">
              <div class="handlers-section__header">
                <span class="text-caption font-weight-semibold text-medium-emphasis">RESPONSE HANDLERS</span>
                <VBtn variant="outlined" size="x-small" rounded="lg" prepend-icon="$plus" @click="addApiHandler(idx)">
                  Add IF Handler</VBtn>
              </div>

              <!-- IF handlers -->
              <div v-for="(handler, hi) in (action.responseHandlers ?? [])" :key="handler.id" class="rh-card">
                <div class="rh-card__bar">
                  <div class="d-flex align-center gap-2">
                    <span class="cb-label cb-label--if">IF</span>
                    <span class="text-caption text-medium-emphasis">response matches</span>
                  </div>
                  <VBtn icon="$trashCan" size="x-small" variant="text" color="error"
                    @click="removeApiHandler(idx, hi)" />
                </div>
                <div class="rh-card__body">
                  <div v-for="(hc, ci) in handler.conditions" :key="hc.id" class="handler-cond">
                    <VAutocomplete :model-value="hc.responseField" :items="RESPONSE_FIELDS" item-title="title"
                      item-value="value" variant="outlined" density="compact" rounded="lg" hide-details style="flex:1.2"
                      @update:model-value="updateApiCond(idx, hi, ci, 'responseField', $event)" />
                    <VTextField v-if="hc.responseField === 'body'" :model-value="hc.responsePath" variant="outlined"
                      density="compact" rounded="lg" hide-details placeholder="data.status" style="flex:1.5"
                      @update:model-value="updateApiCond(idx, hi, ci, 'responsePath', $event)" />
                    <VAutocomplete :model-value="hc.operator" :items="API_OPERATORS" item-title="title"
                      item-value="value" variant="outlined" density="compact" rounded="lg" hide-details style="flex:0.9"
                      @update:model-value="updateApiCond(idx, hi, ci, 'operator', $event)" />
                    <VTextField v-if="needsValue(hc.operator)" :model-value="hc.value" variant="outlined"
                      density="compact" rounded="lg" hide-details placeholder="200" style="flex:1.2"
                      @update:model-value="updateApiCond(idx, hi, ci, 'value', $event)" />
                    <VBtn icon="$close" size="x-small" variant="text" :disabled="handler.conditions.length === 1"
                      @click="removeApiCond(idx, hi, ci)" />
                  </div>
                  <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mt-1 mb-3"
                    @click="addApiCond(idx, hi)">Add condition</VBtn>

                  <!-- THEN -->
                  <div class="cb-section-label cb-section-label--then mb-2">THEN</div>
                  <ActionBranch :actions="handler.actions ?? []" :node-options="store.nodeOptions"
                    :available-variables="store.availableVariables" :api-integrations="store.apiIntegrations"
                    :custom-functions="store.customFunctions" :saved-responses="store.savedResponses"
                    @update:actions="setApiHandlerActions(idx, hi, $event)" />
                </div>
              </div>

              <!-- ELSE — always visible -->
              <div class="rh-card rh-card--else mt-2">
                <div class="rh-card__bar">
                  <div class="d-flex align-center gap-2">
                    <span class="cb-label cb-label--else">ELSE</span>
                    <span class="text-caption text-medium-emphasis">— runs when no IF matched</span>
                  </div>
                </div>
                <div class="rh-card__body">
                  <div class="cb-section-label cb-section-label--then mb-2">THEN</div>
                  <ActionBranch :actions="action.defaultActions ?? []" :node-options="store.nodeOptions"
                    :available-variables="store.availableVariables" :api-integrations="store.apiIntegrations"
                    :custom-functions="store.customFunctions" :saved-responses="store.savedResponses"
                    @update:actions="setApiDefaultActions(idx, $event)" />
                </div>
              </div>
            </div>
          </template>

        </div><!-- /action-card__body -->
        <!-- At the bottom of action-card__body, inside the v-for loop -->
        <div class="then-chain mt-3">
          <VDivider class="mb-2" />
          <div class="d-flex align-center justify-space-between mb-2">
            <span class="field-label" style="color: rgb(var(--v-theme-primary))">THEN (next action)</span>
            <VBtn v-if="!action.then" variant="text" size="x-small" prepend-icon="$plus" color="primary"
              @click="patchAction(idx, { then: makeAction('navigation') })">
              Add next action
            </VBtn>
            <VBtn v-else icon="$close" size="x-small" variant="text" color="error"
              @click="patchAction(idx, { then: null })" />
          </div>
          <div v-if="action.then" class="then-slot">
            <!-- Recursively render a single action using ActionBranch with one item -->
            <ActionBranch :actions="[action.then]" :node-options="store.nodeOptions"
              :available-variables="store.availableVariables" :api-integrations="store.apiIntegrations"
              :custom-functions="store.customFunctions" :saved-responses="store.savedResponses"
              @update:actions="patchAction(idx, { then: $event[0] ?? null })" />
          </div>
        </div>
      </div><!-- /action-card loop -->

      <div class="ae-add-btn">
        <VBtn variant="outlined" rounded="lg" prepend-icon="$plus" color="primary" block @click="addAction">
          Add Action
        </VBtn>
      </div>

    </PerfectScrollbar>

    <!-- ── Footer ────────────────────────────────────────────────────────── -->
    <div class="ae-footer">
      <VBtn variant="outlined" rounded="sm" @click="close">Discard</VBtn>
      <VSpacer />
      <div class="text-caption text-medium-emphasis">Changes are saved to the flow automatically</div>
      <VBtn color="primary" rounded="sm" prepend-icon="$check" @click="save">Done</VBtn>
    </div>
  </VNavigationDrawer>
</template>

<style scoped>
.action-editor :deep(.v-navigation-drawer__content) {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* Header */
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

/* Body */
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

/* Footer */
.ae-footer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  flex-shrink: 0;
  border-top: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
}

/* Action card */
.action-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px;
  overflow: hidden;
  background: rgb(var(--v-theme-surface));
  flex-shrink: 0;
}

.action-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.action-card__type-icon {
  width: 26px;
  height: 26px;
  border-radius: 7px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.action-card__type-row {
  padding: 10px 14px;
}

.action-card__body {
  padding: 14px;
}

/* Type selector */
.type-selector {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.type-option {
  display: flex;
  align-items: center;
  gap: 5px;
  padding: 5px 10px;
  border-radius: 20px;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  cursor: pointer;
  font-size: 12px;
  font-weight: 500;
  transition: all .15s;
  color: rgba(var(--v-theme-on-surface), 0.7);
  user-select: none;
  background: rgba(var(--v-theme-on-surface), 0.02);
}

.type-option:hover {
  background: rgba(var(--v-theme-on-surface), 0.06);
}

.type-option--active {
  font-weight: 600;
}

/* Fields */
.field-group {
  margin-bottom: 4px;
}

.field-label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: .04em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin-bottom: 5px;
}

/* Condition builder */
.cond-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cb-branch {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.cb-branch--else {
  border-style: dashed;
  border-color: rgba(var(--v-theme-on-surface), 0.2);
}

.cb-branch__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.cb-branch__body {
  padding: 12px;
}

.cb-label {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: .06em;
}

.cb-label--if {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}

.cb-label--else {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.logic-toggle {
  display: flex;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;
  overflow: hidden;
}

.logic-toggle button {
  padding: 2px 8px;
  font-size: 11px;
  font-weight: 600;
  background: none;
  border: none;
  cursor: pointer;
  color: rgba(var(--v-theme-on-surface), 0.5);
  transition: all .15s;
}

.logic-toggle button.active {
  background: rgb(var(--v-theme-primary));
  color: white;
}

.cb-section-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .08em;
  color: rgba(var(--v-theme-on-surface), 0.4);
  margin-bottom: 8px;
}

.cb-section-label--then {
  color: rgb(var(--v-theme-primary));
}

.cb-condition {
  margin-bottom: 6px;
}

.cb-logic-pill {
  display: flex;
  justify-content: flex-start;
  padding: 4px 0;
}

.cb-logic-pill span {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .06em;
  padding: 1px 8px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
}

.cb-condition__row {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px;
  background: rgba(var(--v-theme-on-surface), 0.02);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
}

.cb-condition__fields {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.ctype-tabs {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.ctype-tab {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 9px;
  border-radius: 16px;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  background: none;
  color: rgba(var(--v-theme-on-surface), 0.6);
  transition: all .15s;
}

.ctype-tab:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.ctype-tab--active {
  background: rgba(var(--v-theme-primary), 0.1);
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
  font-weight: 600;
}

/* API handlers */
.response-handlers {
  display: flex;
  flex-direction: column;
  gap: 0;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.handlers-section__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.rh-card {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.rh-card:last-child {
  border-bottom: none;
}

.rh-card--else {
  border-style: dashed;
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.2);
  border-radius: 8px;
  margin: 0 8px 8px;
}

.rh-card__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.rh-card__body {
  padding: 10px 12px;
}

.handler-cond {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
</style>