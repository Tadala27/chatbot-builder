<script setup lang="ts">
import { defineAsyncComponent } from "vue";
import { v4 as uuidv4 } from "uuid";

// Self-referential import via defineAsyncComponent avoids circular dep error
const ActionBranch = defineAsyncComponent(() => import("./ActionBranch.vue"));

const props = defineProps<{
  actions: any[];
  nodeOptions: any[];
  availableVariables: string[];
  apiIntegrations: any[];
  customFunctions?: any[];
  savedResponses?: any[];
}>();

const emit = defineEmits<{
  (e: "update:actions", v: any[]): void;
}>();

// ── All action types available in THEN blocks ─────────────────────────────────
const ACTION_TYPES = [
  { value: "navigation", title: "Navigate", icon: "$arrowRightCircleOutline", color: "#3b82f6" },
  { value: "condition", title: "Condition", icon: "$sourceBranch", color: "#8b5cf6" },
  { value: "variable", title: "Set Variable", icon: "$variable", color: "#f59e0b" },
  { value: "api", title: "API Call", icon: "$api", color: "#10b981" },
  { value: "function", title: "Run Function", icon: "$functionVariant", color: "#06b6d4" },
  { value: "delay", title: "Delay", icon: "mdi-timer-outline", color: "#6b7280" },
  { value: "handoff", title: "Handoff", icon: "mdi-headset", color: "#ef4444" },
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
function makeBranch() {
  return {
    id: uuidv4(), conditionLogic: "AND",
    conditions: [makeCondition()],
    actions: [] as any[],
  };
}

function makeCondition() {
  return {
    id: uuidv4(), type: "variable", source: "",
    responsePath: "status", fieldPath: "", operator: "equals", value: "",
  };
}

function makeApiHandler() {
  return {
    id: uuidv4(),
    conditions: [{ id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "200" }],
    actions: [] as any[],
  };
}

function needsValue(op: string) { return !["is_empty", "is_not_empty"].includes(op); }
function getMeta(kind: string) { return ACTION_TYPES.find(t => t.value === kind) ?? ACTION_TYPES[0]; }

// ── Immutable list mutations ──────────────────────────────────────────────────
const e = (v: any[]) => emit("update:actions", v);

function addAction() { e([...props.actions, makeAction("navigation")]); }
function removeAction(i: number) { e(props.actions.filter((_, j) => j !== i)); }

function changeKind(i: number, kind: string) {
  e(props.actions.map((a, j) => j === i ? { ...makeAction(kind), id: a.id } : a));
}
function patchAction(i: number, partial: any) {
  e(props.actions.map((a, j) => j === i ? { ...a, ...partial } : a));
}
function replaceAction(i: number, val: any) {
  e(props.actions.map((a, j) => j === i ? val : a));
}

// ── Condition (inside ActionBranch) mutations ────────────────────────────────
function addCondBranch(i: number) {
  const a = props.actions[i];
  patchAction(i, { branches: [...(a.branches ?? []), makeBranch()] });
}
function removeCondBranch(i: number, bi: number) {
  const a = props.actions[i];
  if ((a.branches ?? []).length <= 1) return;
  patchAction(i, { branches: a.branches.filter((_: any, j: number) => j !== bi) });
}
function patchCondBranch(i: number, bi: number, partial: any) {
  const a = props.actions[i];
  patchAction(i, {
    branches: a.branches.map((b: any, j: number) => j === bi ? { ...b, ...partial } : b),
  });
}
function addCondCond(i: number, bi: number) {
  const b = props.actions[i].branches[bi];
  patchCondBranch(i, bi, { conditions: [...b.conditions, makeCondition()] });
}
function removeCondCond(i: number, bi: number, ci: number) {
  const b = props.actions[i].branches[bi];
  patchCondBranch(i, bi, { conditions: b.conditions.filter((_: any, j: number) => j !== ci) });
}
function updateCondCond(i: number, bi: number, ci: number, field: string, val: any) {
  const b = props.actions[i].branches[bi];
  if (field === "type") {
    const id = b.conditions[ci].id;
    patchCondBranch(i, bi, {
      conditions: b.conditions.map((c: any, j: number) => j === ci ? { ...makeCondition(), id, type: val } : c),
    });
    return;
  }
  patchCondBranch(i, bi, {
    conditions: b.conditions.map((c: any, j: number) => j === ci ? { ...c, [field]: val } : c),
  });
}
function setCondBranchActions(i: number, bi: number, actions: any[]) {
  patchCondBranch(i, bi, { actions });
}
function setCondDefaultActions(i: number, actions: any[]) {
  const a = props.actions[i];
  patchAction(i, { defaultBranch: { ...(a.defaultBranch ?? { id: uuidv4() }), actions } });
}
function toggleCondElse(i: number) {
  const a = props.actions[i];
  patchAction(i, { defaultBranch: a.defaultBranch ? null : { id: uuidv4(), actions: [] } });
}

// ── API (inside ActionBranch) mutations ───────────────────────────────────────
function addApiHandler(i: number) {
  const a = props.actions[i];
  patchAction(i, { responseHandlers: [...(a.responseHandlers ?? []), makeApiHandler()] });
}
function removeApiHandler(i: number, hi: number) {
  const a = props.actions[i];
  patchAction(i, { responseHandlers: a.responseHandlers.filter((_: any, j: number) => j !== hi) });
}
function patchApiHandler(i: number, hi: number, partial: any) {
  const a = props.actions[i];
  patchAction(i, {
    responseHandlers: a.responseHandlers.map((h: any, j: number) => j === hi ? { ...h, ...partial } : h),
  });
}
function addApiCond(i: number, hi: number) {
  const h = props.actions[i].responseHandlers[hi];
  patchApiHandler(i, hi, { conditions: [...h.conditions, { id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "" }] });
}
function removeApiCond(i: number, hi: number, ci: number) {
  const h = props.actions[i].responseHandlers[hi];
  patchApiHandler(i, hi, { conditions: h.conditions.filter((_: any, j: number) => j !== ci) });
}
function updateApiCond(i: number, hi: number, ci: number, field: string, val: any) {
  const h = props.actions[i].responseHandlers[hi];
  patchApiHandler(i, hi, { conditions: h.conditions.map((c: any, j: number) => j === ci ? { ...c, [field]: val } : c) });
}
function setApiHandlerActions(i: number, hi: number, actions: any[]) {
  patchApiHandler(i, hi, { actions });
}
function setApiDefaultActions(i: number, actions: any[]) {
  patchAction(i, { defaultActions: actions });
}
</script>

<template>
  <div class="ab-root">

    <div v-for="(action, idx) in actions" :key="action.id || idx" class="ab-row"
      :style="{ '--ab-accent': getMeta(action.kind).color }">
      <!-- row header: type pills + delete -->
      <div class="ab-row__hd">
        <div class="ab-pills">
          <button v-for="t in ACTION_TYPES" :key="t.value" class="ab-pill"
            :class="{ 'ab-pill--on': action.kind === t.value }"
            :style="action.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
            @click="changeKind(idx, t.value)">
            <VIcon :icon="t.icon" size="11" />{{ t.title }}
          </button>
        </div>
        <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeAction(idx)" />
      </div>

      <!-- row body -->
      <div class="ab-row__bd">

        <!-- NAVIGATE -->
        <template v-if="action.kind === 'navigation'">
          <VAutocomplete :model-value="action.goTo" :items="nodeOptions" variant="outlined" density="compact"
            rounded="lg" hide-details placeholder="Navigate to..." class="flex-1"
            @update:model-value="patchAction(idx, { goTo: $event })" />
        </template>

        <!-- VARIABLE -->
        <template v-else-if="action.kind === 'variable'">
          <VCombobox :model-value="action.varName" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details placeholder="Variable name..." class="flex-1"
            @update:model-value="patchAction(idx, { varName: $event })" />
          <VAutocomplete :model-value="action.dataType" :items="DATA_TYPES" variant="outlined" density="compact"
            rounded="lg" hide-details style="max-width:110px"
            @update:model-value="patchAction(idx, { dataType: $event })" />
          <VTextField :model-value="action.varValue" variant="outlined" density="compact" rounded="lg" hide-details
            placeholder="Value or {{variable}}" class="flex-1"
            @update:model-value="patchAction(idx, { varValue: $event })" />
        </template>

        <!-- DELAY -->
        <template v-else-if="action.kind === 'delay'">
          <VTextField :model-value="action.seconds" type="number" variant="outlined" density="compact" rounded="lg"
            hide-details min="1" max="3600" prepend-inner-icon="mdi-timer-outline" suffix="seconds"
            style="max-width:200px" @update:model-value="patchAction(idx, { seconds: Number($event) })" />
        </template>

        <!-- FUNCTION -->
        <template v-else-if="action.kind === 'function'">
          <VAutocomplete :model-value="action.fnId" :items="customFunctions ?? []" item-title="name" item-value="id"
            variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select function..."
            class="flex-1" @update:model-value="patchAction(idx, { fnId: $event })" />
          <VCombobox :model-value="action.resultVar" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details clearable placeholder="Save result to..." style="max-width:180px"
            @update:model-value="patchAction(idx, { resultVar: $event })" />
        </template>

        <!-- HANDOFF -->
        <template v-else-if="action.kind === 'handoff'">
          <div class="ab-handoff">
            <VIcon icon="mdi-headset" color="error" size="15" />
            Handoff to agent
          </div>
          <VAutocomplete :model-value="action.resumeAt" :items="nodeOptions" variant="outlined" density="compact"
            rounded="lg" hide-details clearable placeholder="Resume at... (optional)" class="flex-1"
            @update:model-value="patchAction(idx, { resumeAt: $event })" />
        </template>

        <!-- ══════════════════════════════════════════════════════════════════
             CONDITION inline — full branch editor
        ══════════════════════════════════════════════════════════════════ -->
        <template v-else-if="action.kind === 'condition'">
          <div class="ab-block">

            <!-- IF branches -->
            <div v-for="(branch, bi) in (action.branches ?? [])" :key="branch.id" class="ab-branch">
              <div class="ab-branch__bar">
                <div class="ab-bar-left">
                  <span class="ab-badge ab-badge--if">IF</span>
                  <div v-if="branch.conditions.length > 1" class="ab-logic">
                    <button :class="{ 'ab-logic--on': branch.conditionLogic === 'AND' }"
                      @click="patchCondBranch(idx, bi, { conditionLogic: 'AND' })">AND</button>
                    <button :class="{ 'ab-logic--on': branch.conditionLogic === 'OR' }"
                      @click="patchCondBranch(idx, bi, { conditionLogic: 'OR' })">OR</button>
                  </div>
                </div>
                <VBtn v-if="(action.branches ?? []).length > 1" icon="$trashCan" size="x-small" variant="text"
                  color="error" @click="removeCondBranch(idx, bi)" />
              </div>

              <div class="ab-branch__body">
                <!-- conditions -->
                <div v-for="(cond, ci) in branch.conditions" :key="cond.id" class="ab-cond-wrap">
                  <div v-if="ci > 0" class="ab-logic-sep"><span>{{ branch.conditionLogic }}</span></div>
                  <div class="ab-cond">
                    <!-- type tabs -->
                    <div class="ab-ctype-tabs">
                      <button v-for="ct in COND_TYPES" :key="ct.value" class="ab-ctype-tab"
                        :class="{ 'ab-ctype-tab--on': cond.type === ct.value }"
                        @click="updateCondCond(idx, bi, ci, 'type', ct.value)">
                        <VIcon :icon="ct.icon" size="12" />{{ ct.title }}
                      </button>
                    </div>
                    <!-- fields -->
                    <div class="ab-cond-fields">
                      <template v-if="cond.type === 'variable'">
                        <VCombobox :model-value="cond.source" :items="availableVariables" variant="outlined"
                          density="compact" rounded="lg" hide-details placeholder="variable..."
                          style="flex:1.4;min-width:120px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'source', $event)" />
                        <VAutocomplete :model-value="cond.operator" :items="COND_OPERATORS" item-title="title"
                          item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                          style="flex:1.1;min-width:130px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'operator', $event)" />
                        <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                          density="compact" rounded="lg" hide-details placeholder="value..."
                          style="flex:1.2;min-width:100px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'value', $event)" />
                      </template>
                      <template v-else-if="cond.type === 'saved_response'">
                        <VAutocomplete :model-value="cond.source"
                          :items="(savedResponses ?? []).map((r: any) => ({ title: r.label, value: r.optionId }))"
                          variant="outlined" density="compact" rounded="lg" hide-details
                          placeholder="Which option the user tapped..." style="flex:1"
                          @update:model-value="updateCondCond(idx, bi, ci, 'source', $event)" />
                      </template>
                      <template v-else-if="cond.type === 'api_response'">
                        <VAutocomplete :model-value="cond.responsePath" :items="RESPONSE_FIELDS" item-title="title"
                          item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                          style="flex:1.1;min-width:110px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'responsePath', $event)" />
                        <VTextField v-if="cond.responsePath === 'body'" :model-value="cond.fieldPath" variant="outlined"
                          density="compact" rounded="lg" hide-details placeholder="data.user.status"
                          style="flex:1.3;min-width:110px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'fieldPath', $event)" />
                        <VAutocomplete :model-value="cond.operator" :items="COND_OPERATORS" item-title="title"
                          item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                          style="flex:1;min-width:110px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'operator', $event)" />
                        <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                          density="compact" rounded="lg" hide-details placeholder="200" style="flex:0.9;min-width:80px"
                          @update:model-value="updateCondCond(idx, bi, ci, 'value', $event)" />
                      </template>
                    </div>
                    <VBtn icon="$close" size="x-small" variant="text" :disabled="branch.conditions.length === 1"
                      @click="removeCondCond(idx, bi, ci)" />
                  </div>
                </div>
                <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mb-2"
                  @click="addCondCond(idx, bi)">Add
                  condition</VBtn>

                <!-- THEN -->
                <div class="ab-then-label">THEN</div>
                <ActionBranch :actions="branch.actions ?? []" :node-options="nodeOptions"
                  :available-variables="availableVariables" :api-integrations="apiIntegrations"
                  :custom-functions="customFunctions" :saved-responses="savedResponses"
                  @update:actions="setCondBranchActions(idx, bi, $event)" />
              </div>
            </div>

            <!-- Add IF branch -->
            <VBtn variant="outlined" rounded="lg" size="small" color="primary" prepend-icon="$plus" block class="mt-1"
              @click="addCondBranch(idx)">
              Add IF Branch
            </VBtn>

            <!-- ELSE branch -->
            <div v-if="action.defaultBranch" class="ab-branch ab-branch--else mt-2">
              <div class="ab-branch__bar">
                <div class="ab-bar-left">
                  <span class="ab-badge ab-badge--else">ELSE</span>
                  <span class="ab-bar-hint">— no IF matched</span>
                </div>
                <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="toggleCondElse(idx)" />
              </div>
              <div class="ab-branch__body">
                <div class="ab-then-label">THEN</div>
                <ActionBranch :actions="action.defaultBranch.actions ?? []" :node-options="nodeOptions"
                  :available-variables="availableVariables" :api-integrations="apiIntegrations"
                  :custom-functions="customFunctions" :saved-responses="savedResponses"
                  @update:actions="setCondDefaultActions(idx, $event)" />
              </div>
            </div>
            <VBtn v-else variant="outlined" rounded="lg" size="small" color="grey-darken-1" prepend-icon="$plus" block
              class="mt-2" @click="toggleCondElse(idx)">
              Add ELSE (Default)
            </VBtn>

          </div>
        </template>

        <!-- ══════════════════════════════════════════════════════════════════
             API CALL inline — full handler editor
        ══════════════════════════════════════════════════════════════════ -->
        <template v-else-if="action.kind === 'api'">
          <div class="ab-block">
            <!-- top row -->
            <div class="ab-api-top">
              <div class="ab-field" style="flex:1.4">
                <label class="ab-lbl">API Integration</label>
                <VAutocomplete :model-value="action.apiConfigId" :items="apiIntegrations" item-title="name"
                  item-value="id" variant="outlined" density="compact" rounded="lg" hide-details clearable
                  placeholder="Select configured API..." no-data-text="No APIs configured yet" prepend-inner-icon="$api"
                  @update:model-value="patchAction(idx, { apiConfigId: $event })" />
              </div>
            </div>

            <!-- handlers container -->
            <div class="ab-handlers">
              <div class="ab-handlers-hd">
                <span class="ab-lbl">Response Handlers</span>
                <VBtn variant="outlined" size="x-small" rounded="lg" prepend-icon="$plus" @click="addApiHandler(idx)">
                  Add IF Handler</VBtn>
              </div>

              <!-- IF handlers -->
              <div v-for="(handler, hi) in (action.responseHandlers ?? [])" :key="handler.id" class="ab-handler">
                <div class="ab-handler__bar">
                  <div class="ab-bar-left">
                    <span class="ab-badge ab-badge--if">IF</span>
                    <span class="ab-bar-hint">response matches</span>
                  </div>
                  <VBtn icon="$trashCan" size="x-small" variant="text" color="error"
                    @click="removeApiHandler(idx, hi)" />
                </div>
                <div class="ab-handler__body">
                  <!-- conditions -->
                  <div v-for="(hc, ci) in handler.conditions" :key="hc.id" class="ab-api-cond">
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
                  <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mt-1 mb-2"
                    @click="addApiCond(idx, hi)">Add condition</VBtn>

                  <!-- THEN -->
                  <div class="ab-then-label">THEN</div>
                  <ActionBranch :actions="handler.actions ?? []" :node-options="nodeOptions"
                    :available-variables="availableVariables" :api-integrations="apiIntegrations"
                    :custom-functions="customFunctions" :saved-responses="savedResponses"
                    @update:actions="setApiHandlerActions(idx, hi, $event)" />
                </div>
              </div>

              <!-- ELSE — always visible -->
              <div class="ab-handler ab-handler--else">
                <div class="ab-handler__bar">
                  <div class="ab-bar-left">
                    <span class="ab-badge ab-badge--else">ELSE</span>
                    <span class="ab-bar-hint">— no IF handler matched</span>
                  </div>
                </div>
                <div class="ab-handler__body">
                  <div class="ab-then-label">THEN</div>
                  <ActionBranch :actions="action.defaultActions ?? []" :node-options="nodeOptions"
                    :available-variables="availableVariables" :api-integrations="apiIntegrations"
                    :custom-functions="customFunctions" :saved-responses="savedResponses"
                    @update:actions="setApiDefaultActions(idx, $event)" />
                </div>
              </div>

            </div><!-- /ab-handlers -->
          </div>
        </template>

      </div><!-- /ab-row__bd -->
    </div><!-- /ab-row loop -->

    <!-- add action button -->
    <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" @click="addAction">
      Add action
    </VBtn>

  </div>
</template>

<style scoped>
.ab-root {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

/* action row */
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
  transition: all .15s;
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

/* api and condition expand full width */
.ab-row__bd .ab-block {
  width: 100%;
}

/* handoff */
.ab-handoff {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: #ef4444;
  background: rgba(239, 68, 68, .07);
  border: 1px solid rgba(239, 68, 68, .2);
  border-radius: 6px;
  padding: 5px 9px;
  flex-shrink: 0;
}

/* block (condition / api) */
.ab-block {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

/* branch */
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

/* badges */
.ab-badge {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .06em;
  text-transform: uppercase;
  padding: 3px 9px;
  border-radius: 5px;
  flex-shrink: 0;
}

.ab-badge--if {
  background: rgba(var(--v-theme-primary), .12);
  color: rgb(var(--v-theme-primary));
}

.ab-badge--else {
  background: rgba(var(--v-theme-on-surface), .08);
  color: rgba(var(--v-theme-on-surface), .55);
}

/* AND/OR logic toggle */
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
  color: rgba(var(--v-theme-on-surface), .45);
  transition: all .12s;
}

.ab-logic--on {
  background: rgb(var(--v-theme-primary)) !important;
  color: #fff !important;
}

/* logic separator pill */
.ab-logic-sep {
  display: flex;
  padding: 2px 0;
}

.ab-logic-sep span {
  font-size: 9px;
  font-weight: 800;
  padding: 1px 7px;
  border-radius: 9px;
  background: rgba(var(--v-theme-primary), .1);
  color: rgb(var(--v-theme-primary));
}

/* condition row */
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
  background: rgba(var(--v-theme-on-surface), .02);
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
  color: rgba(var(--v-theme-on-surface), .55);
  transition: all .12s;
}

.ab-ctype-tab:hover {
  background: rgba(var(--v-theme-on-surface), .05);
}

.ab-ctype-tab--on {
  background: rgba(var(--v-theme-primary), .1);
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

/* THEN label */
.ab-then-label {
  font-size: 10px;
  font-weight: 800;
  letter-spacing: .07em;
  text-transform: uppercase;
  color: rgb(var(--v-theme-primary));
  margin-bottom: 4px;
}

/* API top row */
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
  letter-spacing: .05em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), .45);
}

.ab-lbl-opt {
  font-weight: 400;
  text-transform: none;
  letter-spacing: 0;
  color: rgba(var(--v-theme-on-surface), .3);
}

/* handlers box */
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
  background: rgba(var(--v-theme-on-surface), .025);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ab-handler {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.ab-handler:last-child {
  border-bottom: none;
}

.ab-handler--else {
  background: rgba(var(--v-theme-on-surface), .012);
}

.ab-handler__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 11px;
  gap: 8px;
  background: rgba(var(--v-theme-on-surface), .02);
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

</style>