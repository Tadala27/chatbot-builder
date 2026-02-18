<script setup lang="ts">
import { ref, computed, watch } from "vue";

// Props
const props = defineProps<{
  show: boolean;
  targetNode: any;
  targetButton?: any;
  targetRow?: any;
  availableVariables: string[];
  customFunctions: any[];
  apiIntegrations: any[];
  nodeOptions: any[];
}>();

const emit = defineEmits(["close", "save"]);

// Get the actions array we're editing
const actions = computed(() => {
  if (props.targetButton) return props.targetButton.actions;
  if (props.targetRow) return props.targetRow.actions;
  return props.targetNode?.actions || [];
});

const contextName = computed(() => {
  if (props.targetButton) return `Button: ${props.targetButton.label}`;
  if (props.targetRow) return `List Item: ${props.targetRow.title}`;
  return `Node: ${props.targetNode?.kind}`;
});

// Expanded action panels
const expandedActions = ref<Record<number, boolean>>({});

// Action types
const ACTION_TYPES = [
  { value: "navigation", title: "Navigate to Node", icon: "$navigationVariant", color: "#3b82f6" },
  { value: "variable", title: "Save Variable", icon: "$variable", color: "#06b6d4" },
  { value: "condition", title: "Conditional Branch", icon: "$xml", color: "#f59e0b" },
  { value: "api", title: "API Call", icon: "$codeTags", color: "#ef4444" },
  { value: "function", title: "Run Function", icon: "$function", color: "#8b5cf6" },
  { value: "delay", title: "Delay", icon: "$timerSand", color: "#10b981" },
];

// Operators for conditions
const OPERATORS = [
  { value: "equals", title: "= Equals" },
  { value: "not_equals", title: "≠ Not equals" },
  { value: "contains", title: "∋ Contains" },
  { value: "not_contains", title: "∌ Doesn't contain" },
  { value: "greater_than", title: "> Greater than" },
  { value: "less_than", title: "< Less than" },
  { value: "greater_equal", title: "≥ Greater or equal" },
  { value: "less_equal", title: "≤ Less or equal" },
  { value: "is_empty", title: "∅ Is empty" },
  { value: "not_empty", title: "✓ Is not empty" },
  { value: "starts_with", title: "Starts with" },
  { value: "ends_with", title: "Ends with" },
  { value: "regex", title: "Matches regex" },
];

const DATA_TYPES = [
  { value: "string", title: "String (text)" },
  { value: "number", title: "Number" },
  { value: "boolean", title: "Boolean (true/false)" },
  { value: "array", title: "Array (list)" },
  { value: "object", title: "Object (JSON)" },
];

const HTTP_METHODS = ["GET", "POST", "PUT", "PATCH", "DELETE"];

// Add new action
function addAction() {
  actions.value.push({
    kind: "navigation",
    goTo: "",
  });
  expandedActions.value[actions.value.length - 1] = true;
}

// Remove action
function removeAction(index: number) {
  actions.value.splice(index, 1);
}

// Move action
function moveAction(index: number, direction: number) {
  const newIndex = index + direction;
  if (newIndex < 0 || newIndex >= actions.value.length) return;
  
  const temp = actions.value[index];
  actions.value[index] = actions.value[newIndex];
  actions.value[newIndex] = temp;
}

// Get action type info
function getActionTypeInfo(type: string) {
  return ACTION_TYPES.find((t) => t.value === type) || ACTION_TYPES[0];
}

// Change action type
function changeActionType(action: any, newType: string) {
  const oldType = action.kind;
  action.kind = newType;
  
  // Reset fields when changing type
  if (newType === "navigation") {
    Object.assign(action, { kind: newType, goTo: "" });
  } else if (newType === "variable") {
    Object.assign(action, {
      kind: newType,
      varName: "",
      varValue: "",
      dataType: "string",
    });
  } else if (newType === "condition") {
    Object.assign(action, {
      kind: newType,
      variable: "",
      operator: "equals",
      condValue: "",
      trueGoTo: "",
      falseGoTo: "",
    });
  } else if (newType === "api") {
    Object.assign(action, {
      kind: newType,
      apiConfigId: "",
      endpoint: "",
      method: "GET",
      bodyRaw: "",
      apiResultVar: "",
    });
  } else if (newType === "function") {
    Object.assign(action, {
      kind: newType,
      fnId: "",
      paramsRaw: "{}",
      resultVar: "",
    });
  } else if (newType === "delay") {
    Object.assign(action, {
      kind: newType,
      seconds: 3,
    });
  }
}

// Format JSON
function formatJSON(action: any, field: string) {
  try {
    const parsed = JSON.parse(action[field]);
    action[field] = JSON.stringify(parsed, null, 2);
  } catch (e) {
    // Invalid JSON, do nothing
  }
}

// Validate JSON
function isValidJSON(str: string): boolean {
  try {
    JSON.parse(str);
    return true;
  } catch {
    return false;
  }
}

// Close
function close() {
  emit("close");
}

// Save
function save() {
  emit("save");
  close();
}
</script>

<template>
  <v-navigation-drawer
    :model-value="show"
    location="right"
    temporary
    width="600"
    class="bg-grey-darken-4"
    @update:model-value="(val) => !val && close()"
  >
    <v-card flat class="fill-height d-flex flex-column">
      <!-- Header -->
      <v-card-title class="d-flex align-center pa-4">
        <div>
          <div class="text-h6">Configure Actions</div>
          <div class="text-caption text-grey">{{ contextName }}</div>
        </div>
        <v-spacer />
        <v-btn icon="$close" size="small" variant="text" @click="close" />
      </v-card-title>
      
      <v-divider />

      <!-- Content -->
      <v-card-text class="flex-grow-1 overflow-y-auto pa-4">
        <!-- Empty state -->
        <v-alert
          v-if="actions.length === 0"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          <div class="text-subtitle-2 mb-2">No actions configured</div>
          <div class="text-body-2">
            Actions execute in sequence when this element is triggered. Add your
            first action below.
          </div>
        </v-alert>

        <!-- Action List -->
        <v-expansion-panels
          v-model="expandedActions"
          multiple
          variant="accordion"
          class="mb-4"
        >
          <v-expansion-panel
            v-for="(action, index) in actions"
            :key="index"
            :value="index"
          >
            <v-expansion-panel-title>
              <div class="d-flex align-center w-100">
                <!-- Order badge -->
                <v-chip size="small" variant="outlined" class="mr-3">
                  {{ index + 1 }}
                </v-chip>

                <!-- Icon and title -->
                <v-icon
                  :icon="getActionTypeInfo(action.kind).icon"
                  :color="getActionTypeInfo(action.kind).color"
                  size="small"
                  class="mr-2"
                />
                <span :style="{ color: getActionTypeInfo(action.kind).color }">
                  {{ getActionTypeInfo(action.kind).title }}
                </span>

                <v-spacer />

                <!-- Action buttons -->
                <div @click.stop>
                  <v-btn
                    icon="$arrowUp"
                    size="x-small"
                    variant="text"
                    :disabled="index === 0"
                    @click="moveAction(index, -1)"
                    class="mr-1"
                  />
                  <v-btn
                    icon="$arrowDown"
                    size="x-small"
                    variant="text"
                    :disabled="index === actions.length - 1"
                    @click="moveAction(index, 1)"
                    class="mr-1"
                  />
                  <v-btn
                    icon="$trashCan"
                    size="x-small"
                    variant="text"
                    color="error"
                    @click="removeAction(index)"
                  />
                </div>
              </div>
            </v-expansion-panel-title>

            <v-expansion-panel-text>
              <div class="pt-2">
                <!-- Action Type Selector -->
                <v-select
                  :model-value="action.kind"
                  @update:model-value="(val) => changeActionType(action, val)"
                  label="Action Type"
                  :items="ACTION_TYPES"
                  variant="outlined"
                  density="compact"
                  class="mb-4"
                >
                  <template #item="{ props, item }">
                    <v-list-item v-bind="props">
                      <template #prepend>
                        <v-icon :icon="item.raw.icon" :color="item.raw.color" />
                      </template>
                    </v-list-item>
                  </template>
                </v-select>

                <!-- NAVIGATION -->
                <template v-if="action.kind === 'navigation'">
                  <v-select
                    v-model="action.goTo"
                    label="Navigate to Node"
                    :items="nodeOptions"
                    variant="outlined"
                    density="compact"
                    hint="The conversation will jump to this node"
                    persistent-hint
                  >
                    <template #prepend-inner>
                      <v-icon icon="$navigationVariant" size="small" />
                    </template>
                  </v-select>
                </template>

                <!-- VARIABLE -->
                <template v-else-if="action.kind === 'variable'">
                  <v-combobox
                    v-model="action.varName"
                    label="Variable Name"
                    :items="availableVariables"
                    placeholder="my_variable"
                    variant="outlined"
                    density="compact"
                    hint="Create or update this variable"
                    persistent-hint
                    class="mb-3"
                  >
                    <template #prepend-inner>
                      <v-icon icon="$variable" size="small" />
                    </template>
                  </v-combobox>

                  <v-text-field
                    v-model="action.varValue"
                    label="Value"
                    placeholder="Value or {{another_variable}}"
                    variant="outlined"
                    density="compact"
                    hint="Use {{variable}} syntax to reference other variables"
                    persistent-hint
                    class="mb-3"
                  />

                  <v-select
                    v-model="action.dataType"
                    label="Data Type"
                    :items="DATA_TYPES"
                    variant="outlined"
                    density="compact"
                  />

                  <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                    <strong>Examples:</strong><br />
                    • String: "Hello {{user_name}}"<br />
                    • Number: 42 or {{age}}<br />
                    • Boolean: true or {{is_active}}<br />
                    • Array: ["item1", "{{dynamic}}"]
                  </v-alert>
                </template>

                <!-- CONDITION -->
                <template v-else-if="action.kind === 'condition'">
                  <v-combobox
                    v-model="action.variable"
                    label="Variable to Check"
                    :items="availableVariables"
                    placeholder="{{user_answer}}"
                    variant="outlined"
                    density="compact"
                    hint="The variable to evaluate"
                    persistent-hint
                    class="mb-3"
                  >
                    <template #prepend-inner>
                      <v-icon icon="$variable" size="small" />
                    </template>
                  </v-combobox>

                  <v-select
                    v-model="action.operator"
                    label="Operator"
                    :items="OPERATORS"
                    variant="outlined"
                    density="compact"
                    class="mb-3"
                  />

                  <v-text-field
                    v-if="!['is_empty', 'not_empty'].includes(action.operator)"
                    v-model="action.condValue"
                    label="Compare Value"
                    placeholder="Value to compare against"
                    variant="outlined"
                    density="compact"
                    hint="Can be static value or {{variable}}"
                    persistent-hint
                    class="mb-4"
                  />

                  <!-- True branch -->
                  <v-card variant="outlined" color="success" class="pa-3 mb-3">
                    <div class="text-caption text-success mb-2 font-weight-bold">
                      <v-icon icon="$check" size="small" />
                      IF TRUE → Navigate to
                    </div>
                    <v-select
                      v-model="action.trueGoTo"
                      :items="nodeOptions"
                      variant="outlined"
                      density="compact"
                      hide-details
                    />
                  </v-card>

                  <!-- False branch -->
                  <v-card variant="outlined" color="error" class="pa-3">
                    <div class="text-caption text-error mb-2 font-weight-bold">
                      <v-icon icon="$close" size="small" />
                      IF FALSE → Navigate to
                    </div>
                    <v-select
                      v-model="action.falseGoTo"
                      :items="nodeOptions"
                      variant="outlined"
                      density="compact"
                      hide-details
                    />
                  </v-card>

                  <v-alert type="info" variant="tonal" density="compact" class="mt-3">
                    <strong>Tip:</strong> Conditions split the flow into two paths
                    based on the evaluation result.
                  </v-alert>
                </template>

                <!-- API CALL -->
                <template v-else-if="action.kind === 'api'">
                  <!-- API Integration selector -->
                  <v-select
                    v-model="action.apiConfigId"
                    label="API Integration (Optional)"
                    :items="[
                      { value: '', title: '— Custom API —' },
                      ...apiIntegrations.map((api) => ({
                        value: api.id,
                        title: api.name,
                      })),
                    ]"
                    variant="outlined"
                    density="compact"
                    hint="Select a pre-configured API or enter custom details"
                    persistent-hint
                    class="mb-3"
                  >
                    <template #prepend-inner>
                      <v-icon icon="$api" size="small" />
                    </template>
                  </v-select>

                  <!-- Custom API config -->
                  <template v-if="!action.apiConfigId">
                    <div class="d-flex gap-2 mb-3">
                      <v-select
                        v-model="action.method"
                        label="Method"
                        :items="HTTP_METHODS"
                        variant="outlined"
                        density="compact"
                        style="max-width: 120px"
                      />
                      <v-text-field
                        v-model="action.endpoint"
                        label="Endpoint URL"
                        placeholder="https://api.example.com/endpoint"
                        variant="outlined"
                        density="compact"
                      />
                    </div>

                    <!-- Request body for POST/PUT/PATCH -->
                    <template
                      v-if="['POST', 'PUT', 'PATCH'].includes(action.method)"
                    >
                      <v-textarea
                        v-model="action.bodyRaw"
                        label="Request Body (JSON)"
                        placeholder='{"key": "value", "data": "{{variable}}"}'
                        variant="outlined"
                        density="compact"
                        rows="6"
                        class="font-monospace mb-2"
                        :error="action.bodyRaw && !isValidJSON(action.bodyRaw)"
                        :error-messages="
                          action.bodyRaw && !isValidJSON(action.bodyRaw)
                            ? ['Invalid JSON format']
                            : []
                        "
                      />
                      <v-btn
                        size="small"
                        variant="outlined"
                        prepend-icon="$formatAlignLeft"
                        @click="formatJSON(action, 'bodyRaw')"
                        class="mb-3"
                      >
                        Format JSON
                      </v-btn>
                    </template>
                  </template>

                  <!-- Store response -->
                  <v-combobox
                    v-model="action.apiResultVar"
                    label="Store Response in Variable"
                    :items="availableVariables"
                    placeholder="api_response"
                    variant="outlined"
                    density="compact"
                    hint="Variable to store the API response"
                    persistent-hint
                  >
                    <template #prepend-inner>
                      <v-icon icon="$variable" size="small" />
                    </template>
                  </v-combobox>

                  <v-alert type="warning" variant="tonal" density="compact" class="mt-3">
                    <strong>Note:</strong> Use {{variable}} syntax to inject
                    variables into the endpoint URL or request body.
                  </v-alert>
                </template>

                <!-- FUNCTION -->
                <template v-else-if="action.kind === 'function'">
                  <v-select
                    v-model="action.fnId"
                    label="Function"
                    :items="[
                      { value: '', title: '— Select Function —' },
                      ...customFunctions.map((fn) => ({
                        value: fn.id,
                        title: fn.name,
                      })),
                    ]"
                    variant="outlined"
                    density="compact"
                    hint="Select a custom function to execute"
                    persistent-hint
                    class="mb-3"
                  >
                    <template #prepend-inner>
                      <v-icon icon="$function" size="small" />
                    </template>
                  </v-select>

                  <!-- Function parameters -->
                  <v-textarea
                    v-model="action.paramsRaw"
                    label="Parameters (JSON)"
                    placeholder='{"param1": "value", "param2": "{{variable}}"}'
                    variant="outlined"
                    density="compact"
                    rows="6"
                    class="font-monospace mb-2"
                    :error="action.paramsRaw && !isValidJSON(action.paramsRaw)"
                    :error-messages="
                      action.paramsRaw && !isValidJSON(action.paramsRaw)
                        ? ['Invalid JSON format']
                        : []
                    "
                  />
                  <v-btn
                    size="small"
                    variant="outlined"
                    prepend-icon="$formatAlignLeft"
                    @click="formatJSON(action, 'paramsRaw')"
                    class="mb-3"
                  >
                    Format JSON
                  </v-btn>

                  <!-- Store result -->
                  <v-combobox
                    v-model="action.resultVar"
                    label="Store Result in Variable"
                    :items="availableVariables"
                    placeholder="function_result"
                    variant="outlined"
                    density="compact"
                    hint="Variable to store the function's return value"
                    persistent-hint
                  >
                    <template #prepend-inner>
                      <v-icon icon="$variable" size="small" />
                    </template>
                  </v-combobox>

                  <!-- Function info -->
                  <v-alert
                    v-if="action.fnId"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mt-3"
                  >
                    <div class="text-subtitle-2 mb-1">
                      {{
                        customFunctions.find((f) => f.id === action.fnId)?.name
                      }}
                    </div>
                    <div class="text-caption">
                      {{
                        customFunctions.find((f) => f.id === action.fnId)
                          ?.description || "No description available"
                      }}
                    </div>
                  </v-alert>
                </template>

                <!-- DELAY -->
                <template v-else-if="action.kind === 'delay'">
                  <v-slider
                    v-model="action.seconds"
                    label="Delay Duration (seconds)"
                    :min="1"
                    :max="60"
                    :step="1"
                    thumb-label="always"
                    color="primary"
                    class="mb-4"
                  >
                    <template #prepend>
                      <v-icon icon="$timerSand" />
                    </template>
                  </v-slider>

                  <v-alert type="info" variant="tonal" density="compact">
                    The conversation will pause for
                    <strong>{{ action.seconds }} second{{
                      action.seconds !== 1 ? "s" : ""
                    }}</strong>
                    before continuing to the next action.
                  </v-alert>

                  <!-- Common delay presets -->
                  <div class="mt-3">
                    <div class="text-caption text-grey mb-2">Quick Presets:</div>
                    <v-chip-group>
                      <v-chip
                        size="small"
                        variant="outlined"
                        @click="action.seconds = 2"
                      >
                        2s
                      </v-chip>
                      <v-chip
                        size="small"
                        variant="outlined"
                        @click="action.seconds = 5"
                      >
                        5s
                      </v-chip>
                      <v-chip
                        size="small"
                        variant="outlined"
                        @click="action.seconds = 10"
                      >
                        10s
                      </v-chip>
                      <v-chip
                        size="small"
                        variant="outlined"
                        @click="action.seconds = 30"
                      >
                        30s
                      </v-chip>
                    </v-chip-group>
                  </div>
                </template>
              </div>
            </v-expansion-panel-text>
          </v-expansion-panel>
        </v-expansion-panels>

        <!-- Add action button -->
        <v-btn
          variant="outlined"
          color="primary"
          prepend-icon="$plus"
          @click="addAction"
          block
        >
          Add Action
        </v-btn>

        <!-- Execution order info -->
        <v-alert type="info" variant="tonal" density="compact" class="mt-4">
          <div class="text-subtitle-2 mb-1">
            <v-icon icon="$information" size="small" />
            Execution Order
          </div>
          <div class="text-caption">
            Actions execute sequentially from top to bottom. Use the arrow buttons
            to reorder them.
          </div>
        </v-alert>
      </v-card-text>

      <v-divider />

      <!-- Footer -->
      <v-card-actions class="pa-4">
        <v-spacer />
        <v-btn variant="text" @click="close">Cancel</v-btn>
        <v-btn color="primary" variant="flat" @click="save" prepend-icon="$check">
          Save Actions
        </v-btn>
      </v-card-actions>
    </v-card>
  </v-navigation-drawer>
</template>

<style scoped>
.font-monospace {
  font-family: "JetBrains Mono", "Courier New", monospace;
}

.gap-2 {
  gap: 8px;
}
</style>