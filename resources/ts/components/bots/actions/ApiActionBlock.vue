<script setup lang="ts">
import { v4 as uuidv4 } from "uuid";
import NestedActions from "./NestedActions.vue";

const props = defineProps<{
  action: any;
  availableVariables: string[];
  apiIntegrations: any[];
  nodeOptions: any[];
  customFunctions?: any[];
}>();

const emit = defineEmits<{
  (e: "replace", v: any): void;
}>();

const RESPONSE_FIELDS = [
  { value: "status", title: "Status Code" },
  { value: "body", title: "Body Field" },
  { value: "header", title: "Header" },
];

const OPERATORS = [
  { value: "equals", title: "=" },
  { value: "not_equals", title: "≠" },
  { value: "greater_than", title: ">" },
  { value: "less_than", title: "<" },
  { value: "contains", title: "contains" },
  { value: "starts_with", title: "starts with" },
  { value: "is_empty", title: "is empty" },
  { value: "is_not_empty", title: "is not empty" },
];

function patch(partial: any) {
  emit("replace", { ...props.action, ...partial });
}

function updateTop(field: string, val: any) {
  patch({ [field]: val });
}

// Handlers
function makeHandler() {
  return { id: uuidv4(), conditions: [makeHandlerCond()], actions: [] };
}
function makeHandlerCond() {
  return { id: uuidv4(), responseField: "status", responsePath: "", operator: "equals", value: "200" };
}

function addHandler() {
  patch({ responseHandlers: [...(props.action.responseHandlers ?? []), makeHandler()] });
}
function removeHandler(hIdx: number) {
  patch({ responseHandlers: props.action.responseHandlers.filter((_: any, i: number) => i !== hIdx) });
}
function patchHandler(hIdx: number, partial: any) {
  patch({
    responseHandlers: props.action.responseHandlers.map((h: any, i: number) =>
      i === hIdx ? { ...h, ...partial } : h
    ),
  });
}

function addHandlerCond(hIdx: number) {
  const h = props.action.responseHandlers[hIdx];
  patchHandler(hIdx, { conditions: [...(h.conditions ?? []), makeHandlerCond()] });
}
function removeHandlerCond(hIdx: number, cIdx: number) {
  const h = props.action.responseHandlers[hIdx];
  patchHandler(hIdx, { conditions: h.conditions.filter((_: any, i: number) => i !== cIdx) });
}
function updateHandlerCond(hIdx: number, cIdx: number, field: string, val: any) {
  const h = props.action.responseHandlers[hIdx];
  patchHandler(hIdx, {
    conditions: h.conditions.map((c: any, i: number) => i === cIdx ? { ...c, [field]: val } : c),
  });
}
function updateHandlerActions(hIdx: number, actions: any[]) {
  patchHandler(hIdx, { actions });
}

function setDefault(actions: any[] | undefined) {
  patch({ defaultActions: actions });
}
</script>

<template>
  <div class="api-block">
    <!-- Config row -->
    <VRow dense class="mb-3">
      <VCol cols="12" sm="7">
        <div class="field-group">
          <label class="field-label">API Integration</label>
          <VSelect :model-value="action.apiConfigId" :items="apiIntegrations" item-title="name" item-value="id"
            variant="outlined" density="compact" rounded="lg" hide-details clearable
            placeholder="Select configured API..." no-data-text="No APIs configured yet" prepend-inner-icon="$api"
            @update:model-value="updateTop('apiConfigId', $event)" />
        </div>
      </VCol>
      <VCol cols="12" sm="5">
        <div class="field-group">
          <label class="field-label">Save Response To</label>
          <VCombobox :model-value="action.apiResultVar" :items="availableVariables" variant="outlined" density="compact"
            rounded="lg" hide-details clearable placeholder="variable name..."
            @update:model-value="updateTop('apiResultVar', $event)" />
        </div>
      </VCol>
    </VRow>

    <!-- Response handlers -->
    <div class="handlers-section">
      <div class="handlers-section__header">
        <span class="text-caption font-weight-semibold text-medium-emphasis">RESPONSE HANDLERS</span>
        <div class="d-flex gap-2">
          <VBtn variant="outlined" size="x-small" rounded="lg" prepend-icon="$plus" @click="addHandler">
            Add Handler
          </VBtn>
          <VBtn v-if="action.defaultActions === undefined" variant="outlined" size="x-small" rounded="lg" color="grey"
            prepend-icon="$plus" @click="setDefault([])">
            Default
          </VBtn>
        </div>
      </div>

      <!-- Handler cards -->
      <div v-for="(handler, hIdx) in action.responseHandlers ?? []" :key="handler.id" class="handler-card">
        <div class="handler-card__header">
          <VChip size="x-small" color="success" variant="tonal">Handler {{ hIdx + 1 }}</VChip>
          <VBtn icon="$trashCan" size="x-small" variant="text" color="error" @click="removeHandler(hIdx)" />
        </div>

        <!-- Handler conditions -->
        <div class="handler-card__body">
          <div class="text-caption font-weight-semibold text-medium-emphasis mb-2">WHEN RESPONSE MATCHES</div>

          <div v-for="(hc, cIdx) in handler.conditions ?? []" :key="hc.id" class="handler-cond">
            <VSelect :model-value="hc.responseField" :items="RESPONSE_FIELDS" item-title="title" item-value="value"
              variant="outlined" density="compact" rounded="lg" hide-details style="flex: 1.2;"
              @update:model-value="updateHandlerCond(hIdx, cIdx, 'responseField', $event)" />
            <VTextField v-if="hc.responseField === 'body'" :model-value="hc.responsePath" variant="outlined"
              density="compact" rounded="lg" hide-details placeholder="data.status" style="flex: 1.5;"
              @update:model-value="updateHandlerCond(hIdx, cIdx, 'responsePath', $event)" />
            <VSelect :model-value="hc.operator" :items="OPERATORS" item-title="title" item-value="value"
              variant="outlined" density="compact" rounded="lg" hide-details style="flex: 0.9;"
              @update:model-value="updateHandlerCond(hIdx, cIdx, 'operator', $event)" />
            <VTextField v-if="!['is_empty', 'is_not_empty'].includes(hc.operator)" :model-value="hc.value"
              variant="outlined" density="compact" rounded="lg" hide-details placeholder="200" style="flex: 1.2;"
              @update:model-value="updateHandlerCond(hIdx, cIdx, 'value', $event)" />
            <VBtn icon="$close" size="x-small" variant="text" :disabled="handler.conditions.length === 1"
              @click="removeHandlerCond(hIdx, cIdx)" />
          </div>

          <VBtn variant="text" size="x-small" prepend-icon="$plus" color="primary" class="mt-1"
            @click="addHandlerCond(hIdx)">
            Add condition
          </VBtn>

          <div class="text-caption font-weight-semibold text-medium-emphasis mt-3 mb-2">THEN RUN</div>

          <NestedActions :actions="handler.actions ?? []" :available-variables="availableVariables"
            :node-options="nodeOptions" :api-integrations="apiIntegrations" :custom-functions="customFunctions"
            @update:actions="updateHandlerActions(hIdx, $event)" />
        </div>
      </div>

      <!-- Default handler -->
      <div v-if="action.defaultActions !== undefined" class="handler-card handler-card--default">
        <div class="handler-card__header">
          <VChip size="x-small" color="grey" variant="tonal">Default (no match)</VChip>
          <VBtn icon="$close" size="x-small" variant="text" color="error" @click="setDefault(undefined)" />
        </div>
        <div class="handler-card__body">
          <NestedActions :actions="action.defaultActions" :available-variables="availableVariables"
            :node-options="nodeOptions" :api-integrations="apiIntegrations" :custom-functions="customFunctions"
            @update:actions="setDefault($event)" />
        </div>
      </div>

      <div v-if="(action.responseHandlers?.length ?? 0) === 0 && action.defaultActions === undefined"
        class="handlers-empty">
        <VIcon icon="mdi-transit-connection-variant" size="24" color="medium-emphasis" class="mb-2" />
        <p class="text-caption text-medium-emphasis mb-0">Add handlers to run actions based on the API response</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.api-block {}

.field-group {
  margin-bottom: 4px;
}

.field-label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.45);
  margin-bottom: 5px;
}

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

.gap-2 {
  gap: 8px;
}
</style>
