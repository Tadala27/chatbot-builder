<script setup lang="ts">
import { v4 as uuidv4 } from "uuid";

interface Condition {
  id: string;
  type: "variable" | "saved_response" | "api_response";
  source: string;
  responsePath: string;
  fieldPath: string;
  operator: string;
  value: string;
}

interface Branch {
  id: string;
  conditionLogic: "AND" | "OR";
  conditions: Condition[];
  actions: any[];
}

const props = defineProps<{
  branches: Branch[];
  defaultBranch: { id: string; actions: any[] } | null;
  availableVariables: string[];
  savedResponses: any[];
  nodeOptions: any[];
  apiIntegrations?: any[];
  customFunctions?: any[];
}>();

const emit = defineEmits<{
  (e: "update:branches", v: Branch[]): void;
  (e: "update:defaultBranch", v: any): void;
}>();

// ── Constants ─────────────────────────────────────────────────────────────────
const CONDITION_TYPES = [
  { value: "variable", title: "Variable", icon: "$variable", hint: "Check a conversation variable" },
  { value: "saved_response", title: "User Selected", icon: "$cursorDefaultClick", hint: "What the user tapped" },
  { value: "api_response", title: "API Response", icon: "$api", hint: "Status or body of last API call" },
];

const OPERATORS = [
  { value: "equals", title: "=" },
  { value: "not_equals", title: "≠" },
  { value: "greater_than", title: ">" },
  { value: "less_than", title: "<" },
  { value: "greater_than_or_equal", title: "≥" },
  { value: "less_than_or_equal", title: "≤" },
  { value: "contains", title: "contains" },
  { value: "starts_with", title: "starts with" },
  { value: "ends_with", title: "ends with" },
  { value: "is_empty", title: "is empty" },
  { value: "is_not_empty", title: "is not empty" },
];

const RESPONSE_FIELDS = [
  { value: "status", title: "Status Code" },
  { value: "body", title: "Body Field" },
  { value: "header", title: "Header" },
];

// Nested action types available inside branch THEN blocks (no nested conditions)
const NESTED_TYPES = [
  { value: "navigation", title: "Navigate", icon: "$arrowRightCircleOutline", color: "#3b82f6" },
  { value: "variable", title: "Set Variable", icon: "$variable", color: "#f59e0b" },
  { value: "api", title: "API Call", icon: "$api", color: "#10b981" },
  { value: "function", title: "Run Function", icon: "$functionVariant", color: "#06b6d4" },
  { value: "delay", title: "Delay", icon: "mdi-timer-outline", color: "#6b7280" },
  { value: "handoff", title: "Handoff", icon: "mdi-headset", color: "#ef4444" },
];

// ── Factories ─────────────────────────────────────────────────────────────────
function makeCondition(): Condition {
  return { id: uuidv4(), type: "variable", source: "", responsePath: "status", fieldPath: "", operator: "equals", value: "" };
}
function makeBranch(): Branch {
  return { id: uuidv4(), conditionLogic: "AND", conditions: [makeCondition()], actions: [makeNestedAction("navigation")] };
}
function makeNestedAction(kind: string): any {
  const id = uuidv4();
  const shapes: Record<string, any> = {
    navigation: { id, kind, goTo: "" },
    variable: { id, kind, varName: "", varValue: "", dataType: "string" },
    api: { id, kind, apiConfigId: "", apiResultVar: "" },
    function: { id, kind, fnId: "", paramsRaw: "{}", resultVar: "" },
    delay: { id, kind, seconds: 3 },
    handoff: { id, kind, resumeAt: "" },
  };
  return shapes[kind] ?? { id, kind };
}
function needsValue(op: string) {
  return !["is_empty", "is_not_empty"].includes(op);
}

// ── Nested action list helpers ────────────────────────────────────────────────
function addNested(list: any[]): any[] {
  return [...list, makeNestedAction("navigation")];
}
function removeNested(list: any[], idx: number): any[] {
  return list.filter((_, i) => i !== idx);
}
function updateNested(list: any[], idx: number, field: string, val: any): any[] {
  return list.map((a, i) => i === idx ? { ...a, [field]: val } : a);
}
function changeNestedKind(list: any[], idx: number, kind: string): any[] {
  const id = list[idx]?.id ?? uuidv4();
  return list.map((a, i) => i === idx ? { ...makeNestedAction(kind), id } : a);
}

// ── Branch mutations ──────────────────────────────────────────────────────────
const emitB = (b: Branch[]) => emit("update:branches", b);
const patchBranch = (bIdx: number, partial: Partial<Branch>) =>
  emitB(props.branches.map((b, i) => (i === bIdx ? { ...b, ...partial } : b)));

function addBranch() { emitB([...props.branches, makeBranch()]); }
function removeBranch(i: number) { emitB(props.branches.filter((_, j) => j !== i)); }

function addCondition(bIdx: number) {
  patchBranch(bIdx, { conditions: [...props.branches[bIdx].conditions, makeCondition()] });
}
function removeCondition(bIdx: number, cIdx: number) {
  patchBranch(bIdx, { conditions: props.branches[bIdx].conditions.filter((_, j) => j !== cIdx) });
}
function updateCondition(bIdx: number, cIdx: number, field: string, value: any) {
  if (field === "type") {
    // Full reset when type changes, preserve id
    const id = props.branches[bIdx].conditions[cIdx].id;
    const conds = props.branches[bIdx].conditions.map((c, j) =>
      j === cIdx ? { ...makeCondition(), id, type: value } : c
    );
    patchBranch(bIdx, { conditions: conds });
    return;
  }
  patchBranch(bIdx, {
    conditions: props.branches[bIdx].conditions.map((c, j) =>
      j === cIdx ? { ...c, [field]: value } : c
    ),
  });
}

// Nested actions for branch THEN
function setBranchActions(bIdx: number, actions: any[]) {
  patchBranch(bIdx, { actions });
}

// ── Default branch ────────────────────────────────────────────────────────────
function addDefaultBranch() {
  emit("update:defaultBranch", { id: uuidv4(), actions: [makeNestedAction("navigation")] });
}
function removeDefaultBranch() { emit("update:defaultBranch", null); }
function setDefaultActions(actions: any[]) {
  emit("update:defaultBranch", { ...props.defaultBranch, actions });
}
</script>

<template>
  <!-- Outer scroll wrapper so a deeply-nested condition builder doesn't bust layouts -->
  <div class="cb-root">

    <!-- ── IF / ELSE IF branches ──────────────────────────────────────────── -->
    <div v-for="(branch, bIdx) in branches" :key="branch.id" class="cb-branch">

      <!-- Branch header bar -->
      <div class="cb-branch__bar">
        <div class="d-flex align-center gap-2">
          <span class="cb-label" :class="bIdx === 0 ? 'cb-label--if' : 'cb-label--elseif'">
            {{ bIdx === 0 ? 'IF' : 'ELSE IF' }}
          </span>
          <!-- AND / OR toggle — only when >1 condition -->
          <div v-if="branch.conditions.length > 1" class="logic-toggle">
            <button :class="{ active: branch.conditionLogic === 'AND' }"
              @click="patchBranch(bIdx, { conditionLogic: 'AND' })">AND</button>
            <button :class="{ active: branch.conditionLogic === 'OR' }"
              @click="patchBranch(bIdx, { conditionLogic: 'OR' })">OR</button>
          </div>
        </div>
        <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="removeBranch(bIdx)" />
      </div>

      <!-- Branch body — scrollable so long ELSE IF chains don't overflow -->
      <div class="cb-branch__body">

        <!-- CONDITIONS ───────────────────────────────────────────────────── -->
        <div v-for="(cond, cIdx) in branch.conditions" :key="cond.id" class="cb-condition">
          <!-- Logic pill between rows -->
          <div v-if="cIdx > 0" class="cb-logic-pill">
            <span>{{ branch.conditionLogic }}</span>
          </div>

          <div class="cb-condition__row">
            <!-- Type tabs -->
            <div class="ctype-tabs">
              <button v-for="ct in CONDITION_TYPES" :key="ct.value" class="ctype-tab"
                :class="{ 'ctype-tab--active': cond.type === ct.value }"
                @click="updateCondition(bIdx, cIdx, 'type', ct.value)">
                <VIcon :icon="ct.icon" size="13" />
                {{ ct.title }}
              </button>
            </div>

            <!-- Condition fields -->
            <div class="cb-condition__fields">
              <!-- Variable -->
              <template v-if="cond.type === 'variable'">
                <VCombobox :model-value="cond.source" :items="availableVariables" variant="outlined" density="compact"
                  rounded="lg" hide-details placeholder="variable name..." style="flex:2;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'source', $event)" />
                <VSelect :model-value="cond.operator" :items="OPERATORS" item-title="title" item-value="value"
                  variant="outlined" density="compact" rounded="lg" hide-details style="flex:1.2;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'operator', $event)" />
                <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                  density="compact" rounded="lg" hide-details placeholder="value..." style="flex:2;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'value', $event)" />
              </template>

              <!-- Saved response -->
              <template v-else-if="cond.type === 'saved_response'">
                <VSelect :model-value="cond.source"
                  :items="savedResponses.map(r => ({ title: r.label, value: r.optionId }))" variant="outlined"
                  density="compact" rounded="lg" hide-details placeholder="Which option the user tapped..."
                  :no-data-text="savedResponses.length === 0 ? 'No options have Save Response enabled yet' : 'No matches'"
                  style="flex:1;" @update:model-value="updateCondition(bIdx, cIdx, 'source', $event)" />
              </template>

              <!-- API response -->
              <template v-else-if="cond.type === 'api_response'">
                <VSelect :model-value="cond.responsePath" :items="RESPONSE_FIELDS" item-title="title" item-value="value"
                  variant="outlined" density="compact" rounded="lg" hide-details style="flex:1.2;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'responsePath', $event)" />
                <VTextField v-if="cond.responsePath === 'body'" :model-value="cond.fieldPath" variant="outlined"
                  density="compact" rounded="lg" hide-details placeholder="data.user.status" style="flex:1.5;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'fieldPath', $event)" />
                <VSelect :model-value="cond.operator" :items="OPERATORS" item-title="title" item-value="value"
                  variant="outlined" density="compact" rounded="lg" hide-details style="flex:1;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'operator', $event)" />
                <VTextField v-if="needsValue(cond.operator)" :model-value="cond.value" variant="outlined"
                  density="compact" rounded="lg" hide-details placeholder="200" style="flex:1.2;"
                  @update:model-value="updateCondition(bIdx, cIdx, 'value', $event)" />
              </template>
            </div>

            <VBtn icon="$close" size="x-small" variant="text" :disabled="branch.conditions.length === 1"
              @click="removeCondition(bIdx, cIdx)" />
          </div>
        </div>

        <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mb-1"
          @click="addCondition(bIdx)">
          Add condition
        </VBtn>

        <!-- THEN ─────────────────────────────────────────────────────────── -->
        <div class="cb-section-label cb-section-label--then mt-3">THEN</div>

        <!-- Inlined nested actions for this branch -->
        <div class="nested-actions">
          <div v-for="(na, nIdx) in branch.actions ?? []" :key="na.id ?? nIdx" class="na-row">
            <div class="na-row__header">
              <div class="na-type-pills">
                <button v-for="t in NESTED_TYPES" :key="t.value" class="na-pill"
                  :class="{ 'na-pill--active': na.kind === t.value }"
                  :style="na.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
                  @click="setBranchActions(bIdx, changeNestedKind(branch.actions, nIdx, t.value))">
                  <VIcon :icon="t.icon" size="12" />{{ t.title }}
                </button>
              </div>
              <VBtn icon="$close" size="x-small" variant="text" color="error"
                @click="setBranchActions(bIdx, removeNested(branch.actions, nIdx))" />
            </div>

            <div class="na-row__fields">
              <template v-if="na.kind === 'navigation'">
                <VSelect :model-value="na.goTo" :items="nodeOptions" variant="outlined" density="compact" rounded="lg"
                  hide-details placeholder="Navigate to..." class="flex-1"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'goTo', $event))" />
              </template>

              <template v-else-if="na.kind === 'variable'">
                <VCombobox :model-value="na.varName" :items="availableVariables" variant="outlined" density="compact"
                  rounded="lg" hide-details placeholder="Variable name..." class="flex-1"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'varName', $event))" />
                <VTextField :model-value="na.varValue" variant="outlined" density="compact" rounded="lg" hide-details
                  placeholder="Value or {{var}}" class="flex-1"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'varValue', $event))" />
              </template>

              <template v-else-if="na.kind === 'api'">
                <VSelect :model-value="na.apiConfigId" :items="apiIntegrations ?? []" item-title="name" item-value="id"
                  variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select API integration..."
                  class="flex-1"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'apiConfigId', $event))" />
                <VCombobox :model-value="na.apiResultVar" :items="availableVariables" variant="outlined"
                  density="compact" rounded="lg" hide-details clearable placeholder="Save response to..."
                  style="max-width:200px;"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'apiResultVar', $event))" />
              </template>

              <template v-else-if="na.kind === 'function'">
                <VSelect :model-value="na.fnId" :items="customFunctions ?? []" item-title="name" item-value="id"
                  variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select function..."
                  class="flex-1"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'fnId', $event))" />
                <VCombobox :model-value="na.resultVar" :items="availableVariables" variant="outlined" density="compact"
                  rounded="lg" hide-details clearable placeholder="Save result to..." style="max-width:180px;"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'resultVar', $event))" />
              </template>

              <template v-else-if="na.kind === 'delay'">
                <VTextField :model-value="na.seconds" type="number" variant="outlined" density="compact" rounded="lg"
                  hide-details min="1" max="3600" suffix="seconds" style="max-width:200px;"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'seconds', $event))" />
              </template>

              <template v-else-if="na.kind === 'handoff'">
                <div class="d-flex align-center gap-2 flex-1">
                  <VIcon icon="mdi-headset" color="error" size="16" />
                  <span class="text-body-2 text-medium-emphasis">Handoff to agent</span>
                </div>
                <VSelect :model-value="na.resumeAt" :items="nodeOptions" variant="outlined" density="compact"
                  rounded="lg" hide-details clearable placeholder="Resume at... (optional)" style="max-width:240px;"
                  @update:model-value="setBranchActions(bIdx, updateNested(branch.actions, nIdx, 'resumeAt', $event))" />
              </template>
            </div>
          </div>

          <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary"
            @click="setBranchActions(bIdx, addNested(branch.actions ?? []))">
            Add action
          </VBtn>
        </div>

      </div>
    </div>

    <!-- ── Add IF / ELSE IF ───────────────────────────────────────────────── -->
    <VBtn variant="outlined" rounded="lg" size="small" :color="branches.length === 0 ? 'primary' : 'orange'"
      prepend-icon="$plus" block @click="addBranch">
      {{ branches.length === 0 ? 'Add Branch' : 'Add Else If' }}
    </VBtn>

    <!-- ── ELSE (default) branch ──────────────────────────────────────────── -->
    <div v-if="defaultBranch" class="cb-branch cb-branch--else mt-2">
      <div class="cb-branch__bar">
        <span class="cb-label cb-label--else">ELSE</span>
        <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="removeDefaultBranch" />
      </div>
      <div class="cb-branch__body">
        <div class="cb-section-label cb-section-label--then">THEN</div>

        <div class="nested-actions">
          <div v-for="(na, nIdx) in defaultBranch.actions ?? []" :key="na.id ?? nIdx" class="na-row">
            <div class="na-row__header">
              <div class="na-type-pills">
                <button v-for="t in NESTED_TYPES" :key="t.value" class="na-pill"
                  :class="{ 'na-pill--active': na.kind === t.value }"
                  :style="na.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
                  @click="setDefaultActions(changeNestedKind(defaultBranch.actions, nIdx, t.value))">
                  <VIcon :icon="t.icon" size="12" />{{ t.title }}
                </button>
              </div>
              <VBtn icon="$close" size="x-small" variant="text" color="error"
                @click="setDefaultActions(removeNested(defaultBranch.actions, nIdx))" />
            </div>

            <div class="na-row__fields">
              <template v-if="na.kind === 'navigation'">
                <VSelect :model-value="na.goTo" :items="nodeOptions" variant="outlined" density="compact" rounded="lg"
                  hide-details placeholder="Navigate to..." class="flex-1"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'goTo', $event))" />
              </template>
              <template v-else-if="na.kind === 'variable'">
                <VCombobox :model-value="na.varName" :items="availableVariables" variant="outlined" density="compact"
                  rounded="lg" hide-details placeholder="Variable name..." class="flex-1"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'varName', $event))" />
                <VTextField :model-value="na.varValue" variant="outlined" density="compact" rounded="lg" hide-details
                  placeholder="Value or {{var}}" class="flex-1"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'varValue', $event))" />
              </template>
              <template v-else-if="na.kind === 'api'">
                <VSelect :model-value="na.apiConfigId" :items="apiIntegrations ?? []" item-title="name" item-value="id"
                  variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select API..."
                  class="flex-1"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'apiConfigId', $event))" />
              </template>
              <template v-else-if="na.kind === 'function'">
                <VSelect :model-value="na.fnId" :items="customFunctions ?? []" item-title="name" item-value="id"
                  variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select function..."
                  class="flex-1"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'fnId', $event))" />
              </template>
              <template v-else-if="na.kind === 'delay'">
                <VTextField :model-value="na.seconds" type="number" variant="outlined" density="compact" rounded="lg"
                  hide-details suffix="seconds" style="max-width:200px;"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'seconds', $event))" />
              </template>
              <template v-else-if="na.kind === 'handoff'">
                <div class="d-flex align-center gap-2 flex-1">
                  <VIcon icon="mdi-headset" color="error" size="16" />
                  <span class="text-body-2 text-medium-emphasis">Handoff to agent</span>
                </div>
                <VSelect :model-value="na.resumeAt" :items="nodeOptions" variant="outlined" density="compact"
                  rounded="lg" hide-details clearable placeholder="Resume at... (optional)" style="max-width:240px;"
                  @update:model-value="setDefaultActions(updateNested(defaultBranch.actions, nIdx, 'resumeAt', $event))" />
              </template>
            </div>
          </div>

          <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary"
            @click="setDefaultActions(addNested(defaultBranch.actions ?? []))">
            Add action
          </VBtn>
        </div>
      </div>
    </div>

    <VBtn v-else variant="outlined" rounded="lg" size="small" color="grey-darken-1" prepend-icon="$plus" block
      class="mt-2" @click="addDefaultBranch">
      Add Else (Default)
    </VBtn>
  </div>
</template>

<style scoped>
.cb-root {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

/* Branch card */
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

/* Labels */
.cb-label {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.cb-label--if {
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
}

.cb-label--elseif {
  background: rgba(255, 152, 0, 0.12);
  color: #f59e0b;
}

.cb-label--else {
  background: rgba(var(--v-theme-on-surface), 0.08);
  color: rgba(var(--v-theme-on-surface), 0.6);
}

/* AND/OR toggle */
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
  transition: all 0.15s;
}

.logic-toggle button.active {
  background: rgb(var(--v-theme-primary));
  color: white;
}

/* Section labels */
.cb-section-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  color: rgba(var(--v-theme-on-surface), 0.4);
  margin-bottom: 8px;
}

.cb-section-label--then {
  color: rgb(var(--v-theme-primary));
}

/* Condition */
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
  letter-spacing: 0.06em;
  padding: 1px 8px;
  border-radius: 10px;
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
}

.cb-condition__row {
  display: flex;
  flex-direction: column;
  gap: 6px;
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

/* Condition type tabs */
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
  transition: all 0.15s;
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

/* ── Nested actions ──────────────────────────────────────────────────────── */
.nested-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.na-row {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-left: 3px solid rgba(var(--v-theme-primary), 0.4);
  border-radius: 8px;
  background: rgba(var(--v-theme-on-surface), 0.01);
  overflow: hidden;
}

.na-row__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 8px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.na-type-pills {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
}

.na-pill {
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

.na-pill:hover {
  background: rgba(var(--v-theme-on-surface), 0.05);
}

.na-pill--active {
  font-weight: 600;
}

.na-row__fields {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px;
  flex-wrap: wrap;
}

.flex-1 {
  flex: 1;
  min-width: 120px;
}

.gap-2 {
  gap: 8px;
}
</style>