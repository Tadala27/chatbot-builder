<!-- ListNode.vue -->
<script setup lang="ts">
import { computed, ref } from "vue";
import type { FlowNode, Section, Row } from "../types";
import RichTextEditor from "@/components/RichTextEditor.vue";
import RichTextField from "@/components/RichTextField.vue";
import { useActionEditorStore } from "@/stores/actionEditor";

const actionEditor = useActionEditorStore();

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
  savedResponses: any[];
}>();

let secSeq = 0;
let rowSeq = 0;
const sectionUid = () => `sec${Date.now()}${secSeq++}`;
const rowUid = () => `row${Date.now()}${rowSeq++}`;

const showRowLimitAlert = ref(false);

function totalRows(): number {
  if (!props.node.action?.sections) return 0;
  return props.node.action.sections.reduce(
    (sum, section) => sum + section.rows.length,
    0,
  );
}

function addSection(): Section {
  return {
    id: sectionUid(),
    title: "Section " + (props.node.action?.sections?.length ?? 0) + 1,
    rows: [
      {
        id: rowUid(),
        title: "Item 1",
        description: "",
        actions: [{ kind: "navigation", goTo: "" }],
        saveResponse: false,
      },
    ],
  };
}

function addRow(section: Section) {
  section.rows.push({
    id: rowUid(),
    title: `Item ${section.rows.length + 1}`,
    description: "",
    actions: [{ kind: "navigation", goTo: "" }],
    saveResponse: false,
  });
}

function onSaveResponseChange(row: Row, value: boolean) {}
function removeSection(index: number) {
  props.node.action?.sections?.splice(index, 1);
}

function removeRow(section: Section, index: number) {
  section.rows.splice(index, 1);
}

function configureRow(row: Row) {
  actionEditor.openActionEditor({
    targetNode: props.node,
    targetRow: row,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
  });
}
</script>

<template>
  <div>
    <!-- List Header -->
    <RichTextField
      v-model="node.listHeader"
      label="List Header (optional)"
      placeholder="Menu"
      :available-variables="availableVariables"
      field-type="header"
      :max-length="60"
      show-variable-picker
      density="compact"
    />

    <!-- List Body -->
    <RichTextEditor
      class="mt-3"
      v-model="node.listBody"
      label="List Body"
      placeholder="Please choose an option..."
      :available-variables="availableVariables"
      field-type="body"
      :max-length="4096"
      :show-formatting="true"
    />

    <!-- List Footer -->
    <RichTextField
      v-model="node.listFooter"
      label="List Footer (optional)"
      placeholder="Footer"
      :available-variables="availableVariables"
      field-type="footer"
      :max-length="60"
      show-variable-picker
      density="compact"
    />

    <v-divider class="my-4" />

    <!-- Call to Action Button -->
    <RichTextField
      v-if="node.action"
      v-model="node.action.button"
      label="Call to Action Button"
      placeholder="View Options"
      :available-variables="availableVariables"
      field-type="button"
      :max-length="20"
      show-variable-picker
      density="compact"
      hint="Button text that opens the list"
      persistent-hint
    />
    <RichTextField
      v-else
      v-model="node.actionButton"
      label="Call to Action Button"
      placeholder="View Options"
      :available-variables="availableVariables"
      field-type="button"
      :max-length="20"
      show-variable-picker
      density="compact"
      hint="Button text that opens the list"
      persistent-hint
      @update:model-value="
        (val) => {
          if (!node.action) node.action = { button: val, sections: [] };
          else node.action.button = val;
        }
      "
    />

    <v-divider class="my-4" />

    <!-- Sections header -->
    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-2 font-weight-bold">
        Sections ({{ node.action?.sections?.length || 0 }})
      </div>
      <v-btn
        size="x-small"
        variant="outlined"
        prepend-icon="$plus"
        @click="addSection"
      >
        Add Section
      </v-btn>
    </div>

    <template v-if="node.action?.sections?.length">
      <v-card
        v-for="(section, sIdx) in node.action.sections"
        :key="sIdx"
        variant="outlined"
        class="mb-3"
      >
        <!-- Section Header -->
        <div class="d-flex align-center bg-grey-lighten-5 pa-2">
          <RichTextField
            v-model="section.title"
            placeholder="Section title"
            :available-variables="availableVariables"
            field-type="title"
            :max-length="24"
            show-variable-picker
            variant="plain"
            density="compact"
            class="flex-grow-1"
          />
          <v-btn
            icon="$trashCan"
            size="x-small"
            variant="text"
            color="error"
            @click="removeSection(sIdx)"
          />
        </div>

        <v-divider />

        <!-- Row limit alert -->
        <v-alert
          v-if="showRowLimitAlert"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-2"
        >
          Maximum of 10 items allowed across all sections.
        </v-alert>

        <!-- Rows -->
        <v-card-text>
          <v-card
            v-for="(row, rIdx) in section.rows"
            :key="row.id"
            variant="outlined"
            class="mb-2"
          >
            <v-card-text class="pa-2">
              <!-- Title + controls row -->
              <div class="d-flex gap-2 align-center mb-2">
                <RichTextField
                  v-model="row.title"
                  placeholder="Item title"
                  :available-variables="availableVariables"
                  field-type="title"
                  :max-length="24"
                  show-variable-picker
                  density="compact"
                  class="flex-grow-1"
                />
                <v-tooltip location="top">
                  <template #activator="{ props: tip }">
                    <v-btn
                      v-bind="tip"
                      class="mt-n5"
                      icon="$trashCan"
                      size="x-small"
                      variant="text"
                      color="error"
                      @click="removeRow(section, rIdx)"
                    />
                  </template>
                  Delete Item
                </v-tooltip>
              </div>

              <!-- Description + configure row -->
              <div class="d-flex align-center gap-2">
                <RichTextField
                  v-model="row.description"
                  placeholder="Description (optional)"
                  :available-variables="availableVariables"
                  field-type="description"
                  :max-length="72"
                  show-variable-picker
                  density="compact"
                  class="flex-grow-1"
                />
                <v-tooltip location="top">
                  <template #activator="{ props: tip }">
                    <v-btn
                      v-bind="tip"
                      class="mt-n5"
                      icon="$cog"
                      size="x-small"
                      variant="text"
                      color="success"
                      @click="configureRow(row)"
                    />
                  </template>
                  Configure {{ row.actions?.length || 0 }} action{{
                    row.actions?.length !== 1 ? "s" : ""
                  }}
                </v-tooltip>
              </div>

              <v-checkbox
                v-model="row.saveResponse"
                label="Save response"
                density="compact"
                hide-details
                @update:model-value="onSaveResponseChange(row, $event)"
              />
            </v-card-text>
          </v-card>

          <v-btn
            size="small"
            variant="text"
            prepend-icon="$plus"
            block
            :disabled="totalRows() >= 10"
            @click="addRow(section)"
          >
            Add Row
          </v-btn>
        </v-card-text>
      </v-card>
    </template>

    <v-alert v-else type="info" variant="tonal" density="compact">
      No sections added yet. Click "Add Section" to create your first section.
    </v-alert>
  </div>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>
