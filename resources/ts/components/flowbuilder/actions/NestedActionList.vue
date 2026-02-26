<!-- NestedActionList.vue -->
<template>
  <div class="nested-action-list">
    <div
      v-for="(action, idx) in actions"
      :key="action.id || idx"
      class="nested-action-item mb-2 p-2 border rounded bg-white"
    >
      <div class="d-flex align-center gap-2">
        <VSelect
          :model-value="action.kind"
          :items="nestedActionTypes"
          variant="outlined"
          density="compact"
          hide-details
          class="flex-grow-1"
          @update:model-value="updateActionField(idx, 'kind', $event)"
        />

        <!-- Navigation -->
        <template v-if="action.kind === 'navigation'">
          <VSelect
            :model-value="action.goTo"
            :items="nodeOptions"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Go to..."
            @update:model-value="updateActionField(idx, 'goTo', $event)"
          />
        </template>

        <!-- Variable -->
        <template v-else-if="action.kind === 'variable'">
          <VCombobox
            :model-value="action.varName"
            :items="availableVariables"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Variable"
            @update:model-value="updateActionField(idx, 'varName', $event)"
          />
          <VTextField
            :model-value="action.varValue"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Value"
            @update:model-value="updateActionField(idx, 'varValue', $event)"
          />
        </template>

        <!-- API Call -->
        <template v-else-if="action.kind === 'api'">
          <VSelect
            :model-value="action.apiConfigId"
            :items="apiIntegrations"
            item-title="name"
            item-value="id"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Select API"
            clearable
            @update:model-value="updateActionField(idx, 'apiConfigId', $event)"
          />
          <VTextField
            :model-value="action.endpoint"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Endpoint"
            @update:model-value="updateActionField(idx, 'endpoint', $event)"
          />
        </template>

        <!-- Handoff -->
        <template v-else-if="action.kind === 'handoff'">
          <VSelect
            :model-value="action.resumeAt"
            :items="nodeOptions"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Resume at..."
            clearable
            @update:model-value="updateActionField(idx, 'resumeAt', $event)"
          />
        </template>

        <!-- Delay -->
        <template v-else-if="action.kind === 'delay'">
          <VTextField
            :model-value="action.seconds"
            type="number"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Seconds"
            min="1"
            @update:model-value="updateActionField(idx, 'seconds', $event)"
          />
        </template>

        <!-- Function -->
        <template v-else-if="action.kind === 'function'">
          <VSelect
            :model-value="action.fnId"
            :items="customFunctions"
            item-title="name"
            item-value="id"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Select function"
            @update:model-value="updateActionField(idx, 'fnId', $event)"
          />
          <VCombobox
            :model-value="action.resultVar"
            :items="availableVariables"
            variant="outlined"
            density="compact"
            hide-details
            class="flex-grow-1"
            placeholder="Save to"
            @update:model-value="updateActionField(idx, 'resultVar', $event)"
          />
        </template>

        <VBtn
          icon="$trashCan"
          size="x-small"
          variant="text"
          color="error"
          @click="removeAction(idx)"
        />
      </div>

      <!-- Advanced options toggle -->
      <div v-if="hasAdvancedOptions(action)" class="mt-2">
        <VBtn
          size="x-small"
          variant="text"
          :prepend-icon="
            showAdvanced[action.id] ? '$chevronUp' : '$chevronDown'
          "
          @click="toggleAdvanced(action.id)"
        >
          Advanced
        </VBtn>

        <div
          v-if="showAdvanced[action.id]"
          class="mt-2 p-2 bg-grey-lighten-4 rounded"
        >
          <!-- Advanced options based on action type -->
          <template v-if="action.kind === 'api'">
            <VSelect
              :model-value="action.method"
              :items="httpMethods"
              variant="outlined"
              density="compact"
              label="HTTP Method"
              class="mb-2"
              @update:model-value="updateActionField(idx, 'method', $event)"
            />
            <VTextarea
              :model-value="action.bodyRaw"
              label="Request Body"
              variant="outlined"
              density="compact"
              rows="2"
              @update:model-value="updateActionField(idx, 'bodyRaw', $event)"
            />
            <VCombobox
              :model-value="action.apiResultVar"
              :items="availableVariables"
              variant="outlined"
              density="compact"
              label="Save Result To"
              clearable
              @update:model-value="
                updateActionField(idx, 'apiResultVar', $event)
              "
            />
          </template>

          <template v-else-if="action.kind === 'function'">
            <VTextarea
              :model-value="action.paramsRaw"
              label="Parameters (JSON)"
              variant="outlined"
              density="compact"
              rows="2"
              @update:model-value="updateActionField(idx, 'paramsRaw', $event)"
            />
          </template>
        </div>
      </div>
    </div>

    <VBtn
      variant="outlined"
      color="primary"
      size="x-small"
      prepend-icon="$plus"
      @click="addAction"
    >
      Add Action
    </VBtn>
  </div>
</template>

<script setup lang="ts">
import { ref } from "vue";
import { v4 as uuidv4 } from "uuid";

const props = defineProps<{
  actions: any[];
  availableVariables: string[];
  nodeOptions: any[];
  apiIntegrations: any[];
  customFunctions?: any[];
}>();

const emit = defineEmits<{
  (e: "update:actions", value: any[]): void;
}>();

const httpMethods = ["GET", "POST", "PUT", "PATCH", "DELETE"];
const showAdvanced = ref<Record<string, boolean>>({});

const nestedActionTypes = [
  { value: "navigation", title: "Navigate" },
  { value: "variable", title: "Set Variable" },
  { value: "api", title: "Call API" },
  { value: "function", title: "Execute Function" },
  { value: "delay", title: "Delay" },
  { value: "handoff", title: "Handoff" },
];

function addAction() {
  const newActions = [...props.actions];
  newActions.push({
    id: uuidv4(),
    kind: "navigation",
    goTo: "",
  });
  emit("update:actions", newActions);
}

function removeAction(index: number) {
  const newActions = [...props.actions];
  newActions.splice(index, 1);
  emit("update:actions", newActions);
}

function updateActionField(index: number, field: string, value: any) {
  const newActions = [...props.actions];
  newActions[index] = {
    ...newActions[index],
    [field]: value,
  };
  emit("update:actions", newActions);
}

function hasAdvancedOptions(action: any): boolean {
  return ["api", "function"].includes(action.kind);
}

function toggleAdvanced(id: string) {
  showAdvanced.value[id] = !showAdvanced.value[id];
}
</script>

<style scoped>
.nested-action-item {
  border-left: 3px solid #3b82f6;
}

.gap-2 {
  gap: 8px;
}
</style>
