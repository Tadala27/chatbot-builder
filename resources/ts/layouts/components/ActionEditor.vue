<script setup lang="ts">
import { computed } from "vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import { v4 as uuidv4 } from "uuid";
import ConditionBranchBuilder from "@/components/bots/actions/ConditionBranchBuilder.vue";

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

const NESTED_TYPES = ACTION_TYPES.filter(t => t.value !== "condition"); // no nested conditions

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
  { value: "contains", title: "contains" },
  { value: "starts_with", title: "starts with" },
  { value: "is_empty", title: "is empty" },
  { value: "is_not_empty", title: "is not empty" },
];

// ── Shape factories ───────────────────────────────────────────────────────────
function makeAction(kind: string, id?: string): any {
  const _id = id ?? uuidv4();
  const shapes: Record<string, any> = {
    navigation: { id: _id, kind, goTo: "" },
    condition: { id: _id, kind, branches: [], defaultBranch: null },
    variable: { id: _id, kind, varName: "", varValue: "", dataType: "string" },
    api: { id: _id, kind, apiConfigId: "", apiResultVar: "", responseHandlers: [], defaultActions: undefined },
    function: { id: _id, kind, fnId: "", paramsRaw: "{}", resultVar: "" },
    delay: { id: _id, kind, seconds: 3 },
    handoff: { id: _id, kind, resumeAt: "" },
  };
  return shapes[kind] ?? { id: _id, kind };
}

function makeNestedAction(kind: string): any {
  const id = uuidv4();
  return makeAction(kind, id);
}

function makeApiHandler() {
  return {
    id: uuidv4(),
    conditions: [{ id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "200" }],
    actions: [],
  };
}

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

// ── Root action mutations ─────────────────────────────────────────────────────
function addAction() {
  actionsArray.value = [...actionsArray.value, makeAction("navigation")];
}
function removeAction(idx: number) {
  const a = [...actionsArray.value]; a.splice(idx, 1); actionsArray.value = a;
}
function moveAction(idx: number, dir: -1 | 1) {
  const target = idx + dir;
  if (target < 0 || target >= actionsArray.value.length) return;
  const a = [...actionsArray.value];
  [a[idx], a[target]] = [a[target], a[idx]];
  actionsArray.value = a;
}
function changeKind(idx: number, kind: string) {
  const id = actionsArray.value[idx]?.id ?? uuidv4();
  const a = [...actionsArray.value];
  a[idx] = makeAction(kind, id);
  actionsArray.value = a;
}
function updateField(idx: number, field: string, value: any) {
  const a = [...actionsArray.value];
  a[idx] = { ...a[idx], [field]: value };
  actionsArray.value = a;
}
function replaceAction(idx: number, action: any) {
  const a = [...actionsArray.value]; a[idx] = action; actionsArray.value = a;
}

// ── Nested action helpers (used inside API handlers & condition branches) ─────
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

// ── API block helpers ─────────────────────────────────────────────────────────
function apiPatch(idx: number, partial: any) {
  replaceAction(idx, { ...actionsArray.value[idx], ...partial });
}
function addApiHandler(idx: number) {
  const action = actionsArray.value[idx];
  apiPatch(idx, { responseHandlers: [...(action.responseHandlers ?? []), makeApiHandler()] });
}
function removeApiHandler(idx: number, hIdx: number) {
  const action = actionsArray.value[idx];
  apiPatch(idx, { responseHandlers: action.responseHandlers.filter((_: any, i: number) => i !== hIdx) });
}
function patchApiHandler(idx: number, hIdx: number, partial: any) {
  const action = actionsArray.value[idx];
  apiPatch(idx, {
    responseHandlers: action.responseHandlers.map((h: any, i: number) =>
      i === hIdx ? { ...h, ...partial } : h
    ),
  });
}
function addHandlerCond(idx: number, hIdx: number) {
  const h = actionsArray.value[idx].responseHandlers[hIdx];
  patchApiHandler(idx, hIdx, {
    conditions: [...(h.conditions ?? []), { id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "" }],
  });
}
function removeHandlerCond(idx: number, hIdx: number, cIdx: number) {
  const h = actionsArray.value[idx].responseHandlers[hIdx];
  patchApiHandler(idx, hIdx, { conditions: h.conditions.filter((_: any, i: number) => i !== cIdx) });
}
function updateHandlerCond(idx: number, hIdx: number, cIdx: number, field: string, val: any) {
  const h = actionsArray.value[idx].responseHandlers[hIdx];
  patchApiHandler(idx, hIdx, {
    conditions: h.conditions.map((c: any, i: number) => i === cIdx ? { ...c, [field]: val } : c),
  });
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
      <VBtn icon="$plus" variant="text" size="small" @click="addAction">Add Action</VBtn>

      <VBtn icon="$close" variant="text" size="small" @click="close" />
    </div>
    <VDivider />

    <!-- ── Scrollable body ───────────────────────────────────────────────── -->
    <PerfectScrollbar class="ae-body">

      <!-- Empty state -->
      <div v-if="actionsArray.length === 0" class="ae-empty">
        <div class="ae-empty__icon">
          <VIcon icon="$lightningBoltOutline" size="32" color="medium-emphasis" />
        </div>
        <p class="text-body-2 text-medium-emphasis mb-0">No actions yet. Add one below.</p>
      </div>

      <!-- ── Action cards ────────────────────────────────────────────────── -->
      <div v-for="(action, idx) in actionsArray" :key="action.id ?? idx" class="action-card">

        <!-- Card header -->
        <div class="action-card__header">
          <div class="d-flex align-center gap-2">
            <div class="action-card__type-icon"
              :style="{ background: getActionMeta(action.kind).color + '20', color: getActionMeta(action.kind).color }">
              <VIcon :icon="getActionMeta(action.kind).icon" size="15" />
            </div>
            <span class="text-caption font-weight-semibold text-medium-emphasis">
              ACTION {{ idx + 1 }}
            </span>
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

          <!-- Navigate -->
          <template v-if="action.kind === 'navigation'">
            <div class="field-group">
              <label class="field-label">Destination Node</label>
              <VSelect :model-value="action.goTo" :items="store.nodeOptions" variant="outlined" density="compact"
                rounded="lg" hide-details placeholder="Select node to navigate to..." prepend-inner-icon="$arrowRight"
                @update:model-value="updateField(idx, 'goTo', $event)" />
            </div>
          </template>

          <!-- Condition — delegates to ConditionBranchBuilder -->
          <template v-else-if="action.kind === 'condition'">
            <ConditionBranchBuilder :branches="action.branches ?? []" :default-branch="action.defaultBranch ?? null"
              :available-variables="store.availableVariables" :saved-responses="store.savedResponses"
              :node-options="store.nodeOptions" :api-integrations="store.apiIntegrations"
              :custom-functions="store.customFunctions" @update:branches="updateField(idx, 'branches', $event)"
              @update:default-branch="updateField(idx, 'defaultBranch', $event)" />
          </template>

          <!-- Set Variable -->
          <template v-else-if="action.kind === 'variable'">
            <VRow dense>
              <VCol cols="12" sm="4">
                <div class="field-group">
                  <label class="field-label">Variable Name</label>
                  <VCombobox :model-value="action.varName" :items="store.availableVariables" variant="outlined"
                    density="compact" rounded="lg" hide-details placeholder="district, user_age..."
                    prepend-inner-icon="$variable" @update:model-value="updateField(idx, 'varName', $event)" />
                </div>
              </VCol>
              <VCol cols="12" sm="2">
                <div class="field-group">
                  <label class="field-label">Type</label>
                  <VSelect :model-value="action.dataType" :items="DATA_TYPES" variant="outlined" density="compact"
                    rounded="lg" hide-details @update:model-value="updateField(idx, 'dataType', $event)" />
                </div>
              </VCol>
              <VCol cols="12" sm="6">
                <div class="field-group">
                  <label class="field-label">
                    Value
                    <span class="text-caption text-medium-emphasis">— &#123;&#123;variable&#125;&#125; for
                      dynamic</span>
                  </label>
                  <VTextField :model-value="action.varValue" variant="outlined" density="compact" rounded="lg"
                    hide-details placeholder="e.g. Blantyre or {{city}}"
                    @update:model-value="updateField(idx, 'varValue', $event)" />
                </div>
              </VCol>
            </VRow>
          </template>

          <!-- ── API Call (inlined ApiActionBlock) ──────────────────────── -->
          <template v-else-if="action.kind === 'api'">
            <VRow dense class="mb-3">
              <VCol cols="12" sm="7">
                <div class="field-group">
                  <label class="field-label">API Integration</label>
                  <VSelect :model-value="action.apiConfigId" :items="store.apiIntegrations" item-title="name"
                    item-value="id" variant="outlined" density="compact" rounded="lg" hide-details clearable
                    placeholder="Select configured API..." no-data-text="No APIs configured yet"
                    prepend-inner-icon="$api" @update:model-value="updateField(idx, 'apiConfigId', $event)" />
                </div>
              </VCol>
              <VCol cols="12" sm="5">
                <div class="field-group">
                  <label class="field-label">Save Response To</label>
                  <VCombobox :model-value="action.apiResultVar" :items="store.availableVariables" variant="outlined"
                    density="compact" rounded="lg" hide-details clearable placeholder="variable name..."
                    @update:model-value="updateField(idx, 'apiResultVar', $event)" />
                </div>
              </VCol>
            </VRow>

            <!-- Response handlers -->
            <div class="handlers-section">
              <div class="handlers-section__header">
                <span class="text-caption font-weight-semibold text-medium-emphasis">RESPONSE HANDLERS</span>
                <div class="d-flex gap-2">
                  <VBtn variant="outlined" size="x-small" rounded="lg" prepend-icon="$plus" @click="addApiHandler(idx)">
                    Add Handler
                  </VBtn>
                  <VBtn v-if="action.defaultActions === undefined" variant="outlined" size="x-small" rounded="lg"
                    color="grey" prepend-icon="$plus" @click="updateField(idx, 'defaultActions', [])">
                    Add Default
                  </VBtn>
                </div>
              </div>

              <!-- Handler cards -->
              <div v-for="(handler, hIdx) in action.responseHandlers ?? []" :key="handler.id" class="handler-card">
                <div class="handler-card__header">
                  <VChip size="x-small" color="success" variant="tonal">Handler {{ hIdx + 1 }}</VChip>
                  <VBtn icon="$trashCan" size="x-small" variant="text" color="error"
                    @click="removeApiHandler(idx, hIdx)" />
                </div>
                <div class="handler-card__body">
                  <div class="text-caption font-weight-semibold text-medium-emphasis mb-2">WHEN RESPONSE MATCHES</div>

                  <div v-for="(hc, cIdx) in handler.conditions ?? []" :key="hc.id" class="handler-cond">
                    <VSelect :model-value="hc.responseField" :items="RESPONSE_FIELDS" item-title="title"
                      item-value="value" variant="outlined" density="compact" rounded="lg" hide-details
                      style="flex:1.2;"
                      @update:model-value="updateHandlerCond(idx, hIdx, cIdx, 'responseField', $event)" />
                    <VTextField v-if="hc.responseField === 'body'" :model-value="hc.responsePath" variant="outlined"
                      density="compact" rounded="lg" hide-details placeholder="data.status" style="flex:1.5;"
                      @update:model-value="updateHandlerCond(idx, hIdx, cIdx, 'responsePath', $event)" />
                    <VSelect :model-value="hc.operator" :items="API_OPERATORS" item-title="title" item-value="value"
                      variant="outlined" density="compact" rounded="lg" hide-details style="flex:0.9;"
                      @update:model-value="updateHandlerCond(idx, hIdx, cIdx, 'operator', $event)" />
                    <VTextField v-if="!['is_empty', 'is_not_empty'].includes(hc.operator)" :model-value="hc.value"
                      variant="outlined" density="compact" rounded="lg" hide-details placeholder="200" style="flex:1.2;"
                      @update:model-value="updateHandlerCond(idx, hIdx, cIdx, 'value', $event)" />
                    <VBtn icon="$close" size="x-small" variant="text" :disabled="handler.conditions.length === 1"
                      @click="removeHandlerCond(idx, hIdx, cIdx)" />
                  </div>

                  <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mt-1"
                    @click="addHandlerCond(idx, hIdx)">
                    Add condition
                  </VBtn>

                  <div class="text-caption font-weight-semibold text-medium-emphasis mt-3 mb-2">THEN RUN</div>

                  <!-- Inline nested actions for handler -->
                  <div class="nested-actions">
                    <div v-for="(na, nIdx) in handler.actions ?? []" :key="na.id ?? nIdx" class="na-row">
                      <div class="na-row__header">
                        <div class="na-type-pills">
                          <button v-for="t in NESTED_TYPES" :key="t.value" class="na-pill"
                            :class="{ 'na-pill--active': na.kind === t.value }"
                            :style="na.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
                            @click="patchApiHandler(idx, hIdx, { actions: changeNestedKind(handler.actions, nIdx, t.value) })">
                            <VIcon :icon="t.icon" size="12" />{{ t.title }}
                          </button>
                        </div>
                        <VBtn icon="$close" size="x-small" variant="text" color="error"
                          @click="patchApiHandler(idx, hIdx, { actions: removeNested(handler.actions, nIdx) })" />
                      </div>
                      <div class="na-row__fields">
                        <template v-if="na.kind === 'navigation'">
                          <VSelect :model-value="na.goTo" :items="store.nodeOptions" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="Navigate to..." class="flex-1"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'goTo', $event) })" />
                        </template>
                        <template v-else-if="na.kind === 'variable'">
                          <VCombobox :model-value="na.varName" :items="store.availableVariables" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="Variable..." class="flex-1"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'varName', $event) })" />
                          <VTextField :model-value="na.varValue" variant="outlined" density="compact" rounded="lg"
                            hide-details placeholder="Value or {{var}}" class="flex-1"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'varValue', $event) })" />
                        </template>
                        <template v-else-if="na.kind === 'delay'">
                          <VTextField :model-value="na.seconds" type="number" variant="outlined" density="compact"
                            rounded="lg" hide-details suffix="seconds" style="max-width:180px;"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'seconds', $event) })" />
                        </template>
                        <template v-else-if="na.kind === 'handoff'">
                          <span class="text-body-2 text-medium-emphasis flex-1">Handoff to agent</span>
                          <VSelect :model-value="na.resumeAt" :items="store.nodeOptions" variant="outlined"
                            density="compact" rounded="lg" hide-details clearable placeholder="Resume at... (optional)"
                            style="max-width:220px;"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'resumeAt', $event) })" />
                        </template>
                        <template v-else-if="na.kind === 'api'">
                          <VSelect :model-value="na.apiConfigId" :items="store.apiIntegrations" item-title="name"
                            item-value="id" variant="outlined" density="compact" rounded="lg" hide-details
                            placeholder="Select API..." class="flex-1"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'apiConfigId', $event) })" />
                        </template>
                        <template v-else-if="na.kind === 'function'">
                          <VSelect :model-value="na.fnId" :items="store.customFunctions" item-title="name"
                            item-value="id" variant="outlined" density="compact" rounded="lg" hide-details
                            placeholder="Select function..." class="flex-1"
                            @update:model-value="patchApiHandler(idx, hIdx, { actions: updateNested(handler.actions, nIdx, 'fnId', $event) })" />
                        </template>
                      </div>
                    </div>
                    <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary"
                      @click="patchApiHandler(idx, hIdx, { actions: addNested(handler.actions ?? []) })">
                      Add action
                    </VBtn>
                  </div>
                </div>
              </div>

              <!-- Default handler -->
              <div v-if="action.defaultActions !== undefined" class="handler-card handler-card--default">
                <div class="handler-card__header">
                  <VChip size="x-small" color="grey" variant="tonal">Default (no match)</VChip>
                  <VBtn icon="$close" size="x-small" variant="text" color="error"
                    @click="updateField(idx, 'defaultActions', undefined)" />
                </div>
                <div class="handler-card__body">
                  <div class="nested-actions">
                    <div v-for="(na, nIdx) in action.defaultActions ?? []" :key="na.id ?? nIdx" class="na-row">
                      <div class="na-row__header">
                        <div class="na-type-pills">
                          <button v-for="t in NESTED_TYPES" :key="t.value" class="na-pill"
                            :class="{ 'na-pill--active': na.kind === t.value }"
                            :style="na.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
                            @click="updateField(idx, 'defaultActions', changeNestedKind(action.defaultActions, nIdx, t.value))">
                            <VIcon :icon="t.icon" size="12" />{{ t.title }}
                          </button>
                        </div>
                        <VBtn icon="$close" size="x-small" variant="text" color="error"
                          @click="updateField(idx, 'defaultActions', removeNested(action.defaultActions, nIdx))" />
                      </div>
                      <div class="na-row__fields">
                        <template v-if="na.kind === 'navigation'">
                          <VSelect :model-value="na.goTo" :items="store.nodeOptions" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="Navigate to..." class="flex-1"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'goTo', $event))" />
                        </template>
                        <template v-else-if="na.kind === 'variable'">
                          <VCombobox :model-value="na.varName" :items="store.availableVariables" variant="outlined"
                            density="compact" rounded="lg" hide-details placeholder="Variable..." class="flex-1"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'varName', $event))" />
                          <VTextField :model-value="na.varValue" variant="outlined" density="compact" rounded="lg"
                            hide-details placeholder="Value or {{var}}" class="flex-1"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'varValue', $event))" />
                        </template>
                        <template v-else-if="na.kind === 'delay'">
                          <VTextField :model-value="na.seconds" type="number" variant="outlined" density="compact"
                            rounded="lg" hide-details suffix="seconds" style="max-width:180px;"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'seconds', $event))" />
                        </template>
                        <template v-else-if="na.kind === 'handoff'">
                          <span class="text-body-2 text-medium-emphasis flex-1">Handoff to agent</span>
                          <VSelect :model-value="na.resumeAt" :items="store.nodeOptions" variant="outlined"
                            density="compact" rounded="lg" hide-details clearable placeholder="Resume at... (optional)"
                            style="max-width:220px;"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'resumeAt', $event))" />
                        </template>
                        <template v-else-if="na.kind === 'function'">
                          <VSelect :model-value="na.fnId" :items="store.customFunctions" item-title="name"
                            item-value="id" variant="outlined" density="compact" rounded="lg" hide-details
                            placeholder="Select function..." class="flex-1"
                            @update:model-value="updateField(idx, 'defaultActions', updateNested(action.defaultActions, nIdx, 'fnId', $event))" />
                        </template>
                      </div>
                    </div>
                    <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary"
                      @click="updateField(idx, 'defaultActions', addNested(action.defaultActions ?? []))">
                      Add action
                    </VBtn>
                  </div>
                </div>
              </div>

              <div v-if="(action.responseHandlers?.length ?? 0) === 0 && action.defaultActions === undefined"
                class="handlers-empty">
                <VIcon icon="mdi-transit-connection-variant" size="24" color="medium-emphasis" class="mb-2" />
                <p class="text-caption text-medium-emphasis mb-0">Add handlers to run actions based on the API response
                </p>
              </div>
            </div>
          </template>

          <!-- Run Function -->
          <template v-else-if="action.kind === 'function'">
            <VRow dense>
              <VCol cols="12" sm="5">
                <div class="field-group">
                  <label class="field-label">Function</label>
                  <VSelect :model-value="action.fnId" :items="store.customFunctions" item-title="name" item-value="id"
                    variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select function..."
                    prepend-inner-icon="$functionVariant" @update:model-value="updateField(idx, 'fnId', $event)" />
                </div>
              </VCol>
              <VCol cols="12" sm="3">
                <div class="field-group">
                  <label class="field-label">Save Result To</label>
                  <VCombobox :model-value="action.resultVar" :items="store.availableVariables" variant="outlined"
                    density="compact" rounded="lg" hide-details clearable placeholder="variable name..."
                    @update:model-value="updateField(idx, 'resultVar', $event)" />
                </div>
              </VCol>
              <VCol cols="12">
                <div class="field-group">
                  <label class="field-label">
                    Parameters (JSON)
                    <span class="text-caption text-medium-emphasis">— &#123;&#123;variable&#125;&#125; supported</span>
                  </label>
                  <VTextarea :model-value="action.paramsRaw" variant="outlined" density="compact" rounded="lg"
                    hide-details rows="2" placeholder='{"key": "{{variable}}"}'
                    @update:model-value="updateField(idx, 'paramsRaw', $event)" />
                </div>
              </VCol>
            </VRow>
          </template>

          <!-- Delay -->
          <template v-else-if="action.kind === 'delay'">
            <div class="field-group" style="max-width:240px;">
              <label class="field-label">Wait Duration (seconds)</label>
              <VTextField :model-value="action.seconds" type="number" variant="outlined" density="compact" rounded="lg"
                hide-details min="1" max="3600" prepend-inner-icon="$timerOutline"
                @update:model-value="updateField(idx, 'seconds', $event)" />
            </div>
          </template>

          <!-- Handoff -->
          <template v-else-if="action.kind === 'handoff'">
            <VAlert type="warning" variant="tonal" rounded="lg" density="compact" class="mb-4">
              <div class="d-flex align-center gap-2">
                <VIcon icon="$headset" size="18" />
                <span class="text-body-2">Conversation will be handed off to a live agent</span>
              </div>
            </VAlert>
            <div class="field-group" style="max-width:360px;">
              <label class="field-label">Resume at Node (after agent closes)</label>
              <VSelect :model-value="action.resumeAt" :items="store.nodeOptions" variant="outlined" density="compact"
                rounded="lg" hide-details clearable placeholder="Optional — where to continue..."
                @update:model-value="updateField(idx, 'resumeAt', $event)" />
            </div>
          </template>

        </div>
        <!-- /action-card__body -->
      </div>
      <!-- /action card loop -->

      <!-- Add action -->
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

/* Scrollable body — PerfectScrollbar fills remaining height */
.ae-body {
  flex: 1;
  min-height: 0;
  padding: 16px 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Empty */
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
  transition: all 0.15s;
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
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin-bottom: 5px;
}

/* Add button */
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

/* ── API handlers ──────────────────────────────────────────────────────────── */
.handlers-section {
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

.handler-card {
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.handler-card:last-child {
  border-bottom: none;
}

.handler-card--default {
  background: rgba(var(--v-theme-on-surface), 0.015);
}

.handler-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 7px 12px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.handler-card__body {
  padding: 10px 12px;
}

.handler-cond {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}

.handlers-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 20px;
  text-align: center;
}

/* ── Nested actions (inline) ──────────────────────────────────────────────── */
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

.gap-3 {
  gap: 12px;
}
</style>