<script setup lang="ts">
import RichTextField from "@/components/RichTextField.vue";
import { v4 as uuidv4 } from "uuid";

interface SavedResponse {
  nodeId: string;
  optionId: string;
  label: string;
}

const props = defineProps<{
  branches: any[];
  availableVariables: string[];
  savedResponses: SavedResponse[];
  nodeOptions: any[];
  apiIntegrations?: any[];
}>();

const emit = defineEmits<{
  (e: "update:branches", branches: any[]): void;
}>();

// ── Constants ─────────────────────────────────────────────────────────────────
const CONDITION_TYPES = [
  {
    value: "variable",
    title: "Variable / Property",
    hint: "e.g. district, user_age",
  },
  {
    value: "saved_response",
    title: "Saved Selection",
    hint: "What user picked at a node",
  },
  {
    value: "api_response",
    title: "API Response",
    hint: "Status/body of last API call",
  },
];

const RESPONSE_PATHS = [
  { value: "status", title: "Status Code" },
  { value: "body", title: "Response Body (whole)" },
  { value: "field", title: "Field Path (dot)" },
];

const OPERATORS = [
  { value: "equals", title: "Equals" },
  { value: "not_equals", title: "Not Equals" },
  { value: "greater_than", title: "Greater Than" },
  { value: "less_than", title: "Less Than" },
  { value: "greater_than_or_equal", title: "≥ Great/Equal" },
  { value: "less_than_or_equal", title: "≤ Less/Equal" },
  { value: "contains", title: "Contains" },
  { value: "starts_with", title: "Starts With" },
  { value: "ends_with", title: "Ends With" },
  { value: "is_empty", title: "Is Empty" },
  { value: "is_not_empty", title: "Is Not Empty" },
];

const BRANCH_ACTION_TYPES = [
  { value: "navigation", title: "Go to Node" },
  { value: "variable", title: "Set Variable" },
  { value: "handoff", title: "🤝 Handoff Agent" },
];

function needsValue(op: string) {
  return !["is_empty", "is_not_empty"].includes(op);
}

function makeCondition(type = "variable") {
  return {
    id: uuidv4(),
    type,
    source: "",
    sourceNodeUuid: "",
    responsePath: "status",
    fieldPath: "",
    operator: "equals",
    value: "",
  };
}

// ── Mutation helpers ──────────────────────────────────────────────────────────
function emit_(updated: any[]) {
  emit("update:branches", updated);
}

function patchBranch(bIdx: number, partial: Record<string, any>) {
  emit_(props.branches.map((b, i) => (i === bIdx ? { ...b, ...partial } : b)));
}

function addBranch() {
  emit_([
    ...props.branches,
    {
      id: uuidv4(),
      conditionLogic: "AND",
      conditions: [makeCondition("variable")],
      actions: [{ id: uuidv4(), kind: "navigation", goTo: "" }],
    },
  ]);
}

function removeBranch(bIdx: number) {
  emit_(props.branches.filter((_, i) => i !== bIdx));
}

function addCondition(bIdx: number) {
  patchBranch(bIdx, {
    conditions: [
      ...(props.branches[bIdx].conditions ?? []),
      makeCondition("variable"),
    ],
  });
}

function removeCondition(bIdx: number, cIdx: number) {
  patchBranch(bIdx, {
    conditions: props.branches[bIdx].conditions.filter(
      (_: any, i: number) => i !== cIdx,
    ),
  });
}

function updateConditionField(
  bIdx: number,
  cIdx: number,
  field: string,
  value: any,
) {
  const conds = props.branches[bIdx].conditions.map((c: any, i: number) =>
    i === cIdx ? { ...c, [field]: value } : c,
  );
  patchBranch(bIdx, { conditions: conds });
}

// Changing type resets all source/path fields so stale data never bleeds over.
function changeConditionType(bIdx: number, cIdx: number, type: string) {
  const conds = props.branches[bIdx].conditions.map((c: any, i: number) =>
    i === cIdx ? { ...makeCondition(type), id: c.id } : c,
  );
  patchBranch(bIdx, { conditions: conds });
}
// Store the optionId as the condition value so the executor knows exactly what was picked
function pickSavedResponse(bIdx: number, cIdx: number, optionId: string) {
  const conds = props.branches[bIdx].conditions.map((c: any, i: number) =>
    i === cIdx ? { ...c, source: optionId } : c,
  );
  patchBranch(bIdx, { conditions: conds });
}

function savedResponseDisplay(cond: any): string {
  return (
    props.savedResponses.find((r) => r.optionId === cond.source)?.label ??
    cond.source ??
    ""
  );
}

function addAction(bIdx: number) {
  patchBranch(bIdx, {
    actions: [
      ...(props.branches[bIdx].actions ?? []),
      { id: uuidv4(), kind: "navigation", goTo: "" },
    ],
  });
}

function removeAction(bIdx: number, aIdx: number) {
  patchBranch(bIdx, {
    actions: props.branches[bIdx].actions.filter(
      (_: any, i: number) => i !== aIdx,
    ),
  });
}

function updateActionField(
  bIdx: number,
  aIdx: number,
  field: string,
  value: any,
) {
  const acts = props.branches[bIdx].actions.map((a: any, i: number) =>
    i === aIdx ? { ...a, [field]: value } : a,
  );
  patchBranch(bIdx, { actions: acts });
}

function changeActionKind(bIdx: number, aIdx: number, kind: string) {
  const id = props.branches[bIdx].actions[aIdx]?.id ?? uuidv4();
  const base = { id, kind };
  const shapes: Record<string, any> = {
    navigation: { ...base, goTo: "" },
    variable: { ...base, varName: "", varValue: "" },
    handoff: { ...base },
  };
  const acts = props.branches[bIdx].actions.map((a: any, i: number) =>
    i === aIdx ? (shapes[kind] ?? base) : a,
  );
  patchBranch(bIdx, { actions: acts });
}
</script>

<template>
  <div>
    <VAlert
      v-if="branches.length === 0"
      type="info"
      variant="tonal"
      density="compact"
      class="mb-3"
    >
      <span class="text-caption">
        No branches yet. Add a branch to check a condition and define what
        happens.
      </span>
    </VAlert>

    <div
      v-for="(branch, bIdx) in branches"
      :key="branch.id"
      class="branch-card mb-3"
    >
      <!-- Branch header -->
      <div
        class="branch-card__header d-flex align-center justify-space-between pa-2 pl-3"
      >
        <span class="text-caption font-weight-bold">Branch {{ bIdx + 1 }}</span>

        <VBtnToggle
          v-if="(branch.conditions?.length ?? 0) > 1"
          :model-value="branch.conditionLogic"
          density="compact"
          variant="outlined"
          divided
          mandatory
          color="primary"
          @update:model-value="patchBranch(bIdx, { conditionLogic: $event })"
        >
          <VBtn value="AND" size="x-small">AND</VBtn>
          <VBtn value="OR" size="x-small">OR</VBtn>
        </VBtnToggle>

        <VBtn
          icon="$trashCan"
          size="x-small"
          variant="text"
          color="error"
          @click="removeBranch(bIdx)"
        />
      </div>

      <div class="pa-3 pt-2">
        <!-- ── IF conditions ─────────────────────────────────────────────── -->
        <div class="bldr-label mb-2">IF:</div>

        <div
          v-for="(cond, cIdx) in branch.conditions"
          :key="cond.id"
          class="condition-row mb-2 pa-2 rounded"
        >
          <!-- AND/OR badge between rows -->
          <div v-if="cIdx > 0" class="text-center mb-2">
            <VChip
              size="x-small"
              :color="branch.conditionLogic === 'AND' ? 'primary' : 'orange'"
              variant="flat"
            >
              {{ branch.conditionLogic }}
            </VChip>
          </div>

          <VRow dense align="start">
            <!-- ── Type selector ─────────────────────────────────────────── -->
            <VCol cols="12" sm="6">
              <VSelect
                :model-value="cond.type"
                label="Check type"
                :items="CONDITION_TYPES"
                item-title="title"
                item-value="value"
                variant="outlined"
                density="compact"
                hide-details
                @update:model-value="changeConditionType(bIdx, cIdx, $event)"
              >
                <template #item="{ item, props: iProps }">
                  <VListItem v-bind="iProps" :subtitle="item.raw?.hint" />
                </template>
              </VSelect>
            </VCol>

            <!-- ── variable ──────────────────────────────────────────────── -->
            <template v-if="cond.type === 'variable'">
              <VCol cols="12" sm="6">
                <VCombobox
                  :model-value="cond.source"
                  label="Variable"
                  :items="availableVariables"
                  variant="outlined"
                  density="compact"
                  hide-details
                  placeholder="e.g. district"
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'source', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="2">
                <VSelect
                  :model-value="cond.operator"
                  :items="OPERATORS"
                  item-title="title"
                  item-value="value"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'operator', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="3" v-if="needsValue(cond.operator)">
                <RichTextField
                  :model-value="cond.value"
                  placeholder="Value"
                  :available-variables="availableVariables"
                  field-type="body"
                  show-variable-picker
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'value', $event)
                  "
                />
              </VCol>
            </template>

            <!-- ── saved_response ─────────────────────────────────────────────────── -->
            <template v-if="cond.type === 'saved_response'">
              <!-- Step 1: pick which node -->
              <VCol cols="12" sm="5">
                <VSelect
                  :model-value="cond.source"
                  label="Saved Selection"
                  :items="
                    savedResponses.map((r) => ({
                      title: r.label,
                      value: r.optionId,
                    }))
                  "
                  variant="outlined"
                  density="compact"
                  hide-details
                  :no-data-text="
                    savedResponses.length === 0
                      ? 'No saved responses available'
                      : 'No matches'
                  "
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'source', $event)
                  "
                />
              </VCol>
            </template>

            <!-- ── api_response ───────────────────────────────────────────── -->
            <template v-if="cond.type === 'api_response'">
              <VCol cols="12" sm="2">
                <VSelect
                  :model-value="cond.responsePath"
                  label="Field"
                  :items="RESPONSE_PATHS"
                  item-title="title"
                  item-value="value"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'responsePath', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="2" v-if="cond.responsePath === 'field'">
                <VTextField
                  :model-value="cond.fieldPath"
                  label="Path"
                  variant="outlined"
                  density="compact"
                  hide-details
                  placeholder="data.user.id"
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'fieldPath', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="2">
                <VSelect
                  :model-value="cond.operator"
                  :items="OPERATORS"
                  item-title="title"
                  item-value="value"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'operator', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="2" v-if="needsValue(cond.operator)">
                <RichTextField
                  :model-value="cond.value"
                  label="Value"
                  :available-variables="availableVariables"
                  field-type="body"
                  show-variable-picker
                  density="compact"
                  hide-details
                  placeholder="200"
                  @update:model-value="
                    updateConditionField(bIdx, cIdx, 'value', $event)
                  "
                />
              </VCol>
            </template>

            <!-- Remove condition -->
            <VCol cols="auto" class="d-flex align-center">
              <VBtn
                class="mt-md-2"
                icon="$close"
                size="x-small"
                variant="text"
                color="error"
                :disabled="branch.conditions.length === 1"
                @click="removeCondition(bIdx, cIdx)"
              />
            </VCol>
          </VRow>
        </div>

        <VBtn
          variant="text"
          size="x-small"
          prepend-icon="$plus"
          color="primary"
          class="mb-4"
          @click="addCondition(bIdx)"
        >
          Add Condition
        </VBtn>

        <!-- ── THEN actions ──────────────────────────────────────────────── -->
        <div class="bldr-label bldr-label--then mb-2">THEN:</div>

        <div
          v-for="(act, aIdx) in branch.actions"
          :key="act.id"
          class="action-row mb-2 pa-2 rounded"
        >
          <VRow dense align="center">
            <VCol cols="12" sm="4">
              <VSelect
                :model-value="act.kind"
                label="Action"
                :items="BRANCH_ACTION_TYPES"
                item-title="title"
                item-value="value"
                variant="outlined"
                density="compact"
                hide-details
                @update:model-value="changeActionKind(bIdx, aIdx, $event)"
              />
            </VCol>

            <VCol cols="12" sm="6" v-if="act.kind === 'navigation'">
              <VSelect
                :model-value="act.goTo"
                label="Go to Node"
                :items="nodeOptions"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                @update:model-value="
                  updateActionField(bIdx, aIdx, 'goTo', $event)
                "
              />
            </VCol>

            <template v-if="act.kind === 'variable'">
              <VCol cols="12" sm="3">
                <VCombobox
                  :model-value="act.varName"
                  label="Variable"
                  :items="availableVariables"
                  variant="outlined"
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateActionField(bIdx, aIdx, 'varName', $event)
                  "
                />
              </VCol>
              <VCol cols="12" sm="4">
                <RichTextField
                  :model-value="act.varValue"
                  label="Value"
                  :available-variables="availableVariables"
                  field-type="body"
                  show-variable-picker
                  density="compact"
                  hide-details
                  @update:model-value="
                    updateActionField(bIdx, aIdx, 'varValue', $event)
                  "
                />
              </VCol>
            </template>

            <VCol cols="12" v-if="act.kind === 'handoff'">
              <VAlert
                type="warning"
                variant="tonal"
                density="compact"
                rounded="lg"
              >
                <span class="text-caption">Handoff to agent.</span>
              </VAlert>
            </VCol>

            <VCol cols="auto">
              <VBtn
                icon="$close"
                size="x-small"
                variant="text"
                color="error"
                :disabled="branch.actions.length === 1"
                @click="removeAction(bIdx, aIdx)"
              />
            </VCol>
          </VRow>
        </div>

        <VBtn
          variant="text"
          size="x-small"
          prepend-icon="$plus"
          color="success"
          @click="addAction(bIdx)"
        >
          Add Action
        </VBtn>
      </div>
    </div>

    <VBtn
      variant="outlined"
      color="orange"
      size="small"
      prepend-icon="$plus"
      block
      @click="addBranch"
    >
      Add Branch
    </VBtn>
  </div>
</template>

<style scoped>
.branch-card {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
}
.branch-card__header {
  background: rgba(var(--v-theme-borderLight), 0.45);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
