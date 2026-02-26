<!-- ActionEditor.vue -->
<template>
  <VNavigationDrawer
    :model-value="store.show"
    app
    temporary
    elevation="24"
    location="end"
    border="0"
    width="900"
    class="action-editor-drawer"
    style="z-index: 2000 !important"
    @update:model-value="close"
  >
    <!-- Header -->
    <VRow class="ma-0">
      <VCol cols="12" class="pa-0">
        <div class="pa-5 d-flex justify-space-between align-center">
          <div>
            <div class="text-h5">Configure Actions</div>
            <div class="text-caption text-medium-emphasis">{{ title }}</div>
          </div>
          <VBtn
            variant="text"
            icon
            aria-label="close"
            color="error"
            rounded="md"
            density="compact"
            @click="close"
          >
            <VIcon icon="$close" />
          </VBtn>
        </div>
      </VCol>
    </VRow>
    <VDivider />

    <!-- Scrollable Content -->
    <PerfectScrollbar style="height: calc(100vh - 150px)">
      <div class="pa-5">
        <!-- Empty state -->
        <div v-if="actionsArray.length === 0">
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            rounded="md"
          >
            <template #prepend>
              <SvgSprite
                name="custom-warning-fill"
                style="width: 20px; height: 20px"
              />
            </template>
            <div class="text-caption">
              No actions configured. Click "Add Action" to create your first
              action.
            </div>
          </VAlert>
        </div>

        <!-- Action cards -->
        <div
          v-for="(action, idx) in actionsArray"
          :key="action.id || idx"
          class="mb-4"
        >
          <VCard variant="outlined" elevation="0">
            <VCardTitle class="d-flex align-center bg-grey-lighten-5 pa-3">
              <VIcon icon="$drag" size="small" class="mr-2 cursor-move" />
              <span class="text-subtitle-2">Action {{ idx + 1 }}</span>
              <VSpacer />
              <VBtn
                icon="$arrowUp"
                size="x-small"
                variant="text"
                :disabled="idx === 0"
                @click="moveAction(idx, -1)"
              />
              <VBtn
                icon="$arrowDown"
                size="x-small"
                variant="text"
                :disabled="idx === actionsArray.length - 1"
                @click="moveAction(idx, 1)"
              />
              <VBtn
                icon="$trashCan"
                size="x-small"
                variant="text"
                color="error"
                @click="removeAction(idx)"
              />
            </VCardTitle>

            <VDivider />

            <VCardText class="pa-3">
              <!-- Kind selector -->
              <VSelect
                :model-value="action.kind"
                label="Action Type"
                :items="actionTypes"
                variant="outlined"
                density="compact"
                hide-details
                class="mb-3"
                @update:model-value="updateActionKind(idx, $event)"
              >
                <template #prepend-inner>
                  <VIcon icon="$cog" size="small" />
                </template>
              </VSelect>

              <!-- ── Navigate ─────────────────────────────────────────────── -->
              <template v-if="action.kind === 'navigation'">
                <VSelect
                  :model-value="action.goTo"
                  label="Go to Node"
                  :items="store.nodeOptions"
                  variant="outlined"
                  density="compact"
                  hint="Navigate to this node"
                  persistent-hint
                  @update:model-value="updateActionField(idx, 'goTo', $event)"
                >
                  <template #prepend-inner>
                    <VIcon icon="$navigationVariant" size="small" />
                  </template>
                </VSelect>
              </template>

              <!-- ── Multi-Branch Condition ──────────────────────────────── -->
              <template v-else-if="action.kind === 'condition'">
                <ConditionBranchesBuilder
                  :branches="action.branches || []"
                  :available-variables="store.availableVariables"
                  :saved-responses="store.savedResponses"
                  :node-options="store.nodeOptions"
                  :api-integrations="store.apiIntegrations"
                  @update:branches="updateActionField(idx, 'branches', $event)"
                />
              </template>

              <!-- ── API Call ─────────────────────────────────────────────── -->
              <template v-else-if="action.kind === 'api'">
                <ApiCallWithHandlers
                  :config="action"
                  :available-variables="store.availableVariables"
                  :api-integrations="store.apiIntegrations"
                  :node-options="store.nodeOptions"
                  @update:config="updateActionConfig(idx, $event)"
                />
              </template>

              <!-- ── Handoff ──────────────────────────────────────────────── -->
              <template v-else-if="action.kind === 'handoff'">
                <VAlert type="info" variant="tonal" class="mb-3">
                  <div class="d-flex align-center">
                    <VIcon
                      icon="$humanGreetingProximity"
                      size="small"
                      class="mr-2"
                    />
                    <span>Conversation will be handed off to an agent</span>
                  </div>
                </VAlert>
                <VSelect
                  :model-value="action.resumeAt"
                  label="Resume at node (after handoff)"
                  :items="store.nodeOptions"
                  variant="outlined"
                  density="compact"
                  clearable
                  hint="Optional: Where to continue after agent closes conversation"
                  persistent-hint
                  @update:model-value="
                    updateActionField(idx, 'resumeAt', $event)
                  "
                />
              </template>

              <!-- ── Set Variable ─────────────────────────────────────────── -->
              <template v-else-if="action.kind === 'variable'">
                <!-- Variable name: combobox so existing variables are suggested -->
                <VCombobox
                  :model-value="action.varName"
                  label="Variable Name"
                  :items="store.availableVariables"
                  variant="outlined"
                  density="compact"
                  class="mb-3"
                  @update:model-value="
                    updateActionField(idx, 'varName', $event)
                  "
                >
                  <template #prepend-inner>
                    <VIcon icon="$variable" size="small" />
                  </template>
                </VCombobox>

                <!--
                  Value: RichTextField gives the user a {{}} picker button so
                  they can insert any conversation variable without typing it.
                -->
                <RichTextField
                  :model-value="action.varValue"
                  label="Value"
                  :available-variables="store.availableVariables"
                  field-type="body"
                  show-variable-picker
                  density="compact"
                  hint="Use {{variable}} to insert a variable"
                  persistent-hint
                  class="mb-3"
                  @update:model-value="
                    updateActionField(idx, 'varValue', $event)
                  "
                />

                <VSelect
                  :model-value="action.dataType"
                  label="Data Type"
                  :items="dataTypes"
                  variant="outlined"
                  density="compact"
                  @update:model-value="
                    updateActionField(idx, 'dataType', $event)
                  "
                />
              </template>

              <!-- ── Execute Function ─────────────────────────────────────── -->
              <template v-else-if="action.kind === 'function'">
                <VSelect
                  :model-value="action.fnId"
                  label="Function"
                  :items="store.customFunctions"
                  item-title="name"
                  item-value="id"
                  variant="outlined"
                  density="compact"
                  class="mb-3"
                  @update:model-value="updateActionField(idx, 'fnId', $event)"
                >
                  <template #prepend-inner>
                    <VIcon icon="$function" size="small" />
                  </template>
                </VSelect>

                <!--
                  Params JSON: RichTextField so variables can be inserted into
                  the JSON string with the {{}} picker.
                -->
                <RichTextField
                  :model-value="action.paramsRaw"
                  label="Parameters (JSON)"
                  :available-variables="store.availableVariables"
                  field-type="body"
                  show-variable-picker
                  :multiline="true"
                  density="compact"
                  class="mb-3"
                  hint='{"key": "{{variable}}"}'
                  persistent-hint
                  @update:model-value="
                    updateActionField(idx, 'paramsRaw', $event)
                  "
                />

                <VCombobox
                  :model-value="action.resultVar"
                  label="Save Result To Variable"
                  :items="store.availableVariables"
                  variant="outlined"
                  density="compact"
                  clearable
                  @update:model-value="
                    updateActionField(idx, 'resultVar', $event)
                  "
                />
              </template>

              <!-- ── Delay ────────────────────────────────────────────────── -->
              <template v-else-if="action.kind === 'delay'">
                <VTextField
                  :model-value="action.seconds"
                  label="Delay (seconds)"
                  type="number"
                  variant="outlined"
                  density="compact"
                  min="1"
                  max="3600"
                  hint="Wait this many seconds before continuing"
                  persistent-hint
                  @update:model-value="
                    updateActionField(idx, 'seconds', $event)
                  "
                >
                  <template #prepend-inner>
                    <VIcon icon="$clockOutline" size="small" />
                  </template>
                </VTextField>
              </template>
            </VCardText>
          </VCard>
        </div>

        <!-- Add Action -->
        <VBtn
          variant="outlined"
          color="primary"
          prepend-icon="$plus"
          block
          @click="addAction"
        >
          Add Action
        </VBtn>
      </div>
    </PerfectScrollbar>

    <!-- Footer -->
    <VDivider />
    <div class="pa-4 d-flex gap-2">
      <VBtn variant="outlined" color="error" class="flex-grow-1" @click="close"
        >Cancel</VBtn
      >
      <VBtn color="primary" class="flex-grow-1" @click="save"
        >Save Actions</VBtn
      >
    </div>
  </VNavigationDrawer>
</template>

<script setup lang="ts">
import { computed } from "vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import RichTextField from "@/components/RichTextField.vue";
import ConditionBranchesBuilder from "@/components/flowbuilder/actions/ConditionBranchesBuilder.vue";
import ApiCallWithHandlers from "@/components/flowbuilder/actions/ApiCallWithHandlers.vue";
import { v4 as uuidv4 } from "uuid";

const store = useActionEditorStore();

// ── Data types ────────────────────────────────────────────────────────────────
const dataTypes = [
  { value: "string", title: "String" },
  { value: "number", title: "Number" },
  { value: "boolean", title: "Boolean" },
  { value: "json", title: "JSON" },
  { value: "date", title: "Date" },
];

// ── Action type list ──────────────────────────────────────────────────────────
const actionTypes = [
  { value: "navigation", title: "Navigate to Node" },
  { value: "condition", title: "Multi-Branch Condition" },
  { value: "variable", title: "Set Variable" },
  { value: "function", title: "Execute Function" },
  { value: "api", title: "API Call" },
  { value: "delay", title: "Delay" },
  { value: "handoff", title: "Handoff to Agent" },
];

// ── Reactive actions array (writable computed) ────────────────────────────────
// Writing back to this propagates into the store target.
const actionsArray = computed({
  get: () => {
    if (store.targetButton) return store.targetButton.actions || [];
    if (store.targetRow) return store.targetRow.actions || [];
    return store.targetNode?.actions || [];
  },
  set: (value) => {
    if (store.targetButton) store.targetButton.actions = value;
    else if (store.targetRow) store.targetRow.actions = value;
    else if (store.targetNode) store.targetNode.actions = value;
  },
});

// ── Context title ─────────────────────────────────────────────────────────────
const title = computed(() => {
  if (store.targetButton) return `Button: ${store.targetButton.label}`;
  if (store.targetRow) return `List Item: ${store.targetRow.title}`;
  return "Node Actions";
});

// ── Mutation helpers ──────────────────────────────────────────────────────────

function addAction() {
  actionsArray.value = [
    ...actionsArray.value,
    { id: uuidv4(), kind: "navigation", goTo: "" },
  ];
}

function removeAction(index: number) {
  const a = [...actionsArray.value];
  a.splice(index, 1);
  actionsArray.value = a;
}

function moveAction(index: number, direction: -1 | 1) {
  const target = index + direction;
  if (target < 0 || target >= actionsArray.value.length) return;
  const a = [...actionsArray.value];
  [a[index], a[target]] = [a[target], a[index]];
  actionsArray.value = a;
}

// Reset the action shape completely when its type changes, so no stale fields
// carry over (e.g. old goTo doesn't linger on a variable action).
function updateActionKind(index: number, kind: string) {
  const id = actionsArray.value[index]?.id || uuidv4();
  const base = { id, kind };

  const shapes: Record<string, object> = {
    navigation: { ...base, goTo: "" },
    condition: {
      ...base,
      branches: [
        {
          id: uuidv4(),
          conditions: [
            {
              id: uuidv4(),
              type: "variable",
              source: "",
              operator: "equals",
              value: "",
            },
          ],
          conditionLogic: "AND",
          actions: [],
        },
      ],
    },
    api: {
      ...base,
      endpoint: "",
      method: "GET",
      responseHandlers: [],
      apiResultVar: "",
    },
    variable: { ...base, varName: "", varValue: "", dataType: "string" },
    function: { ...base, fnId: "", paramsRaw: "{}", resultVar: "" },
    delay: { ...base, seconds: 3 },
    handoff: { ...base, resumeAt: "" },
  };

  const a = [...actionsArray.value];
  a[index] = shapes[kind] ?? base;
  actionsArray.value = a;
}

function updateActionField(index: number, field: string, value: any) {
  const a = [...actionsArray.value];
  a[index] = { ...a[index], [field]: value };
  actionsArray.value = a;
}

function updateActionConfig(index: number, config: any) {
  const a = [...actionsArray.value];
  a[index] = config;
  actionsArray.value = a;
}

// ── Save / close ──────────────────────────────────────────────────────────────
function save() {
  store.closeActionEditor();
}
function close() {
  store.closeActionEditor();
}
</script>

<style scoped>
.action-editor-drawer {
  z-index: 2000 !important;
}
.cursor-move {
  cursor: move;
}
.gap-2 {
  gap: 8px;
}
</style>
