<!-- ApiCallWithHandlers.vue -->
<template>
  <div class="api-call-with-handlers">
    <!-- Basic API Configuration -->
    <VSelect
      :model-value="config.apiConfigId"
      label="API Configuration"
      :items="apiIntegrations"
      item-title="name"
      item-value="id"
      variant="outlined"
      density="compact"
      class="mb-3"
      clearable
      @update:model-value="updateConfig('apiConfigId', $event)"
    >
      <template #prepend-inner>
        <VIcon icon="$api" size="small" />
      </template>
    </VSelect>

    <VTextField
      :model-value="config.endpoint"
      label="Endpoint URL"
      variant="outlined"
      density="compact"
      class="mb-3"
      placeholder="https://api.example.com/endpoint"
      hint="Use {{variable}} for dynamic values"
      persistent-hint
      @update:model-value="updateConfig('endpoint', $event)"
    />

    <VSelect
      :model-value="config.method || 'GET'"
      label="HTTP Method"
      :items="httpMethods"
      variant="outlined"
      density="compact"
      class="mb-3"
      @update:model-value="updateConfig('method', $event)"
    />

    <VTextarea
      :model-value="config.bodyRaw"
      label="Request Body (JSON)"
      variant="outlined"
      density="compact"
      rows="4"
      class="mb-3"
      hint='{"key": "value", "user": "{{user_name}}"}'
      persistent-hint
      @update:model-value="updateConfig('bodyRaw', $event)"
    />

    <VCombobox
      :model-value="config.apiResultVar"
      label="Save Response To Variable"
      :items="availableVariables"
      variant="outlined"
      density="compact"
      class="mb-4"
      clearable
      @update:model-value="updateConfig('apiResultVar', $event)"
    />

    <!-- Response Handlers -->
    <div class="response-handlers">
      <div class="text-subtitle-2 mb-3">
        Handle Response Based on Conditions
      </div>

      <div
        v-for="(handler, handlerIdx) in config.responseHandlers || []"
        :key="handler.id"
        class="handler-card mb-4 p-3 border rounded"
      >
        <div class="d-flex justify-space-between align-center mb-3">
          <VChip color="success" size="small"
            >Handler {{ handlerIdx + 1 }}</VChip
          >
          <VBtn
            icon="$trashCan"
            size="x-small"
            variant="text"
            color="error"
            @click="removeHandler(handlerIdx)"
          />
        </div>

        <!-- Conditions for this handler -->
        <div class="conditions-container mb-3">
          <div class="text-caption font-weight-bold mb-2">
            When response matches:
          </div>

          <div
            v-for="(condition, condIdx) in handler.conditions || []"
            :key="condition.id"
            class="condition-item mb-2 p-2 bg-grey-lighten-4 rounded"
          >
            <VRow dense align="center">
              <VCol cols="12" md="3">
                <VSelect
                  :model-value="condition.responseField"
                  :items="responseFields"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateHandlerCondition(
                      handlerIdx,
                      condIdx,
                      'responseField',
                      $event,
                    )
                  "
                />
              </VCol>

              <VCol cols="12" md="3">
                <VTextField
                  :model-value="condition.responsePath"
                  variant="outlined"
                  density="compact"
                  hide-details
                  placeholder="Path (e.g., user.status)"
                  hint="Optional JSON path"
                  persistent-hint
                  @update:model-value="
                    updateHandlerCondition(
                      handlerIdx,
                      condIdx,
                      'responsePath',
                      $event,
                    )
                  "
                />
              </VCol>

              <VCol cols="12" md="2">
                <VSelect
                  :model-value="condition.operator"
                  :items="operators"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateHandlerCondition(
                      handlerIdx,
                      condIdx,
                      'operator',
                      $event,
                    )
                  "
                />
              </VCol>

              <VCol cols="12" md="3">
                <VTextField
                  :model-value="condition.value"
                  variant="outlined"
                  density="compact"
                  hide-details
                  placeholder="Expected value"
                  @update:model-value="
                    updateHandlerCondition(handlerIdx, condIdx, 'value', $event)
                  "
                />
              </VCol>

              <VCol cols="12" md="1">
                <VBtn
                  icon="$close"
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="removeHandlerCondition(handlerIdx, condIdx)"
                />
              </VCol>
            </VRow>
          </div>

          <VBtn
            variant="outlined"
            color="primary"
            size="x-small"
            prepend-icon="$plus"
            @click="addHandlerCondition(handlerIdx)"
          >
            Add Condition
          </VBtn>
        </div>

        <!-- Actions for this handler -->
        <div class="handler-actions mt-3">
          <div class="text-caption font-weight-bold mb-2">
            Execute these actions:
          </div>

          <NestedActionList
            :actions="handler.actions || []"
            :available-variables="availableVariables"
            :node-options="nodeOptions"
            :api-integrations="apiIntegrations"
            @update:actions="updateHandlerActions(handlerIdx, $event)"
          />
        </div>
      </div>

      <!-- Default handler (optional) -->
      <div
        v-if="config.defaultActions"
        class="default-handler mt-3 p-3 border rounded bg-grey-lighten-5"
      >
        <div class="d-flex justify-space-between align-center mb-2">
          <VChip color="grey" size="small"
            >Default (if no handler matches)</VChip
          >
          <VBtn
            icon="$close"
            size="x-small"
            variant="text"
            color="error"
            @click="removeDefaultHandler"
          />
        </div>

        <NestedActionList
          :actions="config.defaultActions"
          :available-variables="availableVariables"
          :node-options="nodeOptions"
          :api-integrations="apiIntegrations"
          @update:actions="updateDefaultActions"
        />
      </div>

      <div class="d-flex gap-2 mt-3">
        <VBtn
          variant="outlined"
          color="primary"
          prepend-icon="$plus"
          @click="addResponseHandler"
        >
          Add Response Handler
        </VBtn>

        <VBtn
          v-if="!config.defaultActions"
          variant="outlined"
          color="grey"
          prepend-icon="$plus"
          @click="addDefaultHandler"
        >
          Add Default Handler
        </VBtn>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import NestedActionList from "./NestedActionList.vue";
import { v4 as uuidv4 } from "uuid";

const props = defineProps<{
  config: any;
  availableVariables: string[];
  apiIntegrations: any[];
  nodeOptions: any[];
}>();

const emit = defineEmits<{
  (e: "update:config", value: any): void;
}>();

const httpMethods = [
  { value: "GET", title: "GET" },
  { value: "POST", title: "POST" },
  { value: "PUT", title: "PUT" },
  { value: "PATCH", title: "PATCH" },
  { value: "DELETE", title: "DELETE" },
];

const responseFields = [
  { value: "status", title: "Status Code" },
  { value: "body", title: "Response Body" },
  { value: "header", title: "Response Header" },
];

const operators = [
  { value: "equals", title: "Equals (=)" },
  { value: "not_equals", title: "Not Equals (≠)" },
  { value: "contains", title: "Contains" },
  { value: "starts_with", title: "Starts With" },
  { value: "ends_with", title: "Ends With" },
  { value: "greater_than", title: "Greater Than (>)" },
  { value: "less_than", title: "Less Than (<)" },
  { value: "greater_than_or_equal", title: "Greater or Equal (≥)" },
  { value: "less_than_or_equal", title: "Less or Equal (≤)" },
  { value: "is_empty", title: "Is Empty" },
  { value: "is_not_empty", title: "Is Not Empty" },
];

function updateConfig(field: string, value: any) {
  emit("update:config", {
    ...props.config,
    [field]: value,
  });
}

function addResponseHandler() {
  const handlers = [...(props.config.responseHandlers || [])];
  handlers.push({
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
    actions: [],
  });
  updateConfig("responseHandlers", handlers);
}

function removeHandler(index: number) {
  const handlers = [...(props.config.responseHandlers || [])];
  handlers.splice(index, 1);
  updateConfig("responseHandlers", handlers);
}

function addHandlerCondition(handlerIndex: number) {
  const handlers = [...(props.config.responseHandlers || [])];
  const handler = { ...handlers[handlerIndex] };
  handler.conditions = [...(handler.conditions || [])];
  handler.conditions.push({
    id: uuidv4(),
    responseField: "status",
    responsePath: "",
    operator: "equals",
    value: "",
  });
  handlers[handlerIndex] = handler;
  updateConfig("responseHandlers", handlers);
}

function removeHandlerCondition(handlerIndex: number, conditionIndex: number) {
  const handlers = [...(props.config.responseHandlers || [])];
  const handler = { ...handlers[handlerIndex] };
  handler.conditions = [...(handler.conditions || [])];
  handler.conditions.splice(conditionIndex, 1);
  handlers[handlerIndex] = handler;
  updateConfig("responseHandlers", handlers);
}

function updateHandlerCondition(
  handlerIndex: number,
  conditionIndex: number,
  field: string,
  value: any,
) {
  const handlers = [...(props.config.responseHandlers || [])];
  const handler = { ...handlers[handlerIndex] };
  const conditions = [...(handler.conditions || [])];
  conditions[conditionIndex] = {
    ...conditions[conditionIndex],
    [field]: value,
  };
  handler.conditions = conditions;
  handlers[handlerIndex] = handler;
  updateConfig("responseHandlers", handlers);
}

function updateHandlerActions(handlerIndex: number, actions: any[]) {
  const handlers = [...(props.config.responseHandlers || [])];
  const handler = { ...handlers[handlerIndex] };
  handler.actions = actions;
  handlers[handlerIndex] = handler;
  updateConfig("responseHandlers", handlers);
}

function addDefaultHandler() {
  updateConfig("defaultActions", []);
}

function removeDefaultHandler() {
  updateConfig("defaultActions", undefined);
}

function updateDefaultActions(actions: any[]) {
  updateConfig("defaultActions", actions);
}
</script>

<style scoped>
.handler-card {
  background-color: #f8f9fa;
  border-left: 4px solid #10b981;
}

.default-handler {
  border-left: 4px solid #6c757d;
}

.condition-item {
  background-color: white;
}

.gap-2 {
  gap: 8px;
}
</style>
