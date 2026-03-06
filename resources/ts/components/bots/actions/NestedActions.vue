<script setup lang="ts">
import { v4 as uuidv4 } from "uuid";

const props = defineProps<{
  actions: any[];
  availableVariables: string[];
  nodeOptions: any[];
  apiIntegrations: any[];
  customFunctions?: any[];
  savedResponses?: any[];
}>();

const emit = defineEmits<{
  (e: "update:actions", v: any[]): void;
}>();

const NESTED_TYPES = [
  { value: "navigation", title: "Navigate", icon: "$arrowRightCircleOutline", color: "#3b82f6" },
  { value: "variable", title: "Set Variable", icon: "$variable", color: "#f59e0b" },
  { value: "api", title: "API Call", icon: "$api", color: "#10b981" },
  { value: "function", title: "Run Function", icon: "$functionVariant", color: "#06b6d4" },
  { value: "delay", title: "Delay", icon: "mdi-timer-outline", color: "#6b7280" },
  { value: "handoff", title: "Handoff", icon: "mdi-headset", color: "#ef4444" },
];

const DATA_TYPES = [
  { value: "string", title: "Text" }, { value: "number", title: "Number" },
  { value: "boolean", title: "Boolean" }, { value: "json", title: "JSON" }, { value: "date", title: "Date" },
];

function getMeta(kind: string) {
  return NESTED_TYPES.find(t => t.value === kind) ?? NESTED_TYPES[0];
}

function makeShape(kind: string): any {
  const id = uuidv4();
  const shapes: Record<string, any> = {
    navigation: { id, kind, goTo: "" },
    variable: { id, kind, varName: "", varValue: "", dataType: "string" },
    api: { id, kind, apiConfigId: "", apiResultVar: "", responseHandlers: [] },
    function: { id, kind, fnId: "", paramsRaw: "{}", resultVar: "" },
    delay: { id, kind, seconds: 3 },
    handoff: { id, kind, resumeAt: "" },
  };
  return shapes[kind] ?? { id, kind };
}

function add() { emit("update:actions", [...props.actions, makeShape("navigation")]); }
function remove(idx: number) { emit("update:actions", props.actions.filter((_, i) => i !== idx)); }
function update(idx: number, field: string, val: any) {
  const a = [...props.actions];
  a[idx] = { ...a[idx], [field]: val };
  emit("update:actions", a);
}
function changeKind(idx: number, kind: string) {
  const id = props.actions[idx]?.id ?? uuidv4();
  const a = [...props.actions];
  a[idx] = { ...makeShape(kind), id };
  emit("update:actions", a);
}
</script>

<template>
  <div class="nested-actions">
    <div v-for="(action, idx) in actions" :key="action.id || idx" class="na-row">
      <!-- Row header: type pill + delete -->
      <div class="na-row__header">
        <div class="na-type-pills">
          <button v-for="t in NESTED_TYPES" :key="t.value" class="na-pill"
            :class="{ 'na-pill--active': action.kind === t.value }"
            :style="action.kind === t.value ? { borderColor: t.color, background: t.color + '15', color: t.color } : {}"
            @click="changeKind(idx, t.value)">
            <VIcon :icon="t.icon" size="12" />
            {{ t.title }}
          </button>
        </div>
        <VBtn icon="$close" size="x-small" variant="text" color="error" @click="remove(idx)" />
      </div>

      <!-- Fields -->
      <div class="na-row__fields">
        <!-- Navigate -->
        <template v-if="action.kind === 'navigation'">
          <VSelect :model-value="action.goTo" :items="nodeOptions" variant="outlined" density="compact" rounded="lg"
            hide-details placeholder="Navigate to..." prepend-inner-icon="mdi-arrow-right" class="flex-1"
            @update:model-value="update(idx, 'goTo', $event)" />
        </template>

        <!-- Variable -->
        <template v-else-if="action.kind === 'variable'">
          <VCombobox :model-value="action.varName" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details placeholder="Variable name..." class="flex-1"
            @update:model-value="update(idx, 'varName', $event)" />
          <VSelect :model-value="action.dataType" :items="DATA_TYPES" variant="outlined" density="compact" rounded="lg"
            hide-details style="max-width: 110px;" @update:model-value="update(idx, 'dataType', $event)" />
          <VTextField :model-value="action.varValue" variant="outlined" density="compact" rounded="lg" hide-details
            placeholder="Value or {{variable}}" class="flex-1" @update:model-value="update(idx, 'varValue', $event)" />
        </template>

        <!-- API -->
        <template v-else-if="action.kind === 'api'">
          <VSelect :model-value="action.apiConfigId" :items="apiIntegrations" item-title="name" item-value="id"
            variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select API integration..."
            clearable class="flex-1" @update:model-value="update(idx, 'apiConfigId', $event)" />
          <VCombobox :model-value="action.apiResultVar" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details placeholder="Save response to..." clearable style="max-width: 200px;"
            @update:model-value="update(idx, 'apiResultVar', $event)" />
        </template>

        <!-- Function -->
        <template v-else-if="action.kind === 'function'">
          <VSelect :model-value="action.fnId" :items="customFunctions" item-title="name" item-value="id"
            variant="outlined" density="compact" rounded="lg" hide-details placeholder="Select function..."
            class="flex-1" @update:model-value="update(idx, 'fnId', $event)" />
          <VCombobox :model-value="action.resultVar" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details clearable placeholder="Save result to..." style="max-width: 180px;"
            @update:model-value="update(idx, 'resultVar', $event)" />
        </template>

        <!-- Delay -->
        <template v-else-if="action.kind === 'delay'">
          <VTextField :model-value="action.seconds" type="number" variant="outlined" density="compact" rounded="lg"
            hide-details min="1" max="3600" prepend-inner-icon="mdi-timer-outline" suffix="seconds"
            style="max-width: 200px;" @update:model-value="update(idx, 'seconds', $event)" />
        </template>

        <!-- Handoff -->
        <template v-else-if="action.kind === 'handoff'">
          <div class="d-flex align-center gap-2 flex-1">
            <VIcon icon="mdi-headset" color="error" size="16" />
            <span class="text-body-2 text-medium-emphasis">Handoff to agent</span>
          </div>
          <VSelect :model-value="action.resumeAt" :items="nodeOptions" variant="outlined" density="compact" rounded="lg"
            hide-details clearable placeholder="Resume at... (optional)" style="max-width: 240px;"
            @update:model-value="update(idx, 'resumeAt', $event)" />
        </template>
      </div>
    </div>

    <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" @click="add">
      Add action
    </VBtn>
  </div>
</template>

<style scoped>
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
