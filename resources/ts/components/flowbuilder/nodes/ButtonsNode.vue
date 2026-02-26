<!-- ButtonsNode.vue -->
<script setup lang="ts">
import type { FlowNode, Btn } from "../types";
import RichTextField from "@/components/RichTextField.vue";
import RichTextEditor from "@/components/RichTextEditor.vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import { computed } from "vue";

const actionEditor = useActionEditorStore();

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
  savedResponses: any[];
}>();

let btnSeq = 0;
const uid = () => `btn${Date.now()}${btnSeq++}`;

function addButton() {
  if (!props.node.buttons) props.node.buttons = [];
  if (props.node.buttons.length < 3) {
    props.node.buttons.push({
      id: uid(),
      label: `Option ${props.node.buttons.length + 1}`,
      actions: [{ kind: "navigation", goTo: "" }],
      saveResponse: false,
    });
  }
}

function onSaveResponseChange(btn: Btn, value: boolean) {}

function configureButton(btn: Btn) {
  actionEditor.openActionEditor({
    targetNode: props.node,
    targetButton: btn,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
  });
}
function removeButton(index: number) {
  props.node.buttons?.splice(index, 1);
}
</script>

<template>
  <div>
    <RichTextEditor
      v-model="node.btnText"
      label="Message text"
      placeholder="Choose an option..."
      :available-variables="availableVariables"
      :show-formatting="true"
      hint="Message shown above the buttons"
    />

    <v-divider class="my-4" />

    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-2 font-weight-bold">
        Buttons ({{ node.buttons?.length || 0 }}/3)
      </div>
      <v-btn
        size="x-small"
        variant="outlined"
        prepend-icon="$plus"
        :disabled="(node.buttons?.length || 0) >= 3"
        @click="addButton"
      >
        Add Button
      </v-btn>
    </div>

    <v-card
      v-for="(btn, bIdx) in node.buttons"
      :key="btn.id"
      variant="outlined"
      class="mb-3"
    >
      <v-card-text>
        <!-- Label row -->
        <div class="d-flex gap-2 align-center mb-3">
          <RichTextField
            v-model="btn.label"
            label="Button text"
            :available-variables="availableVariables"
            field-type="button"
            :max-length="20"
            show-variable-picker
            density="compact"
            class="flex-grow-1"
          />
          <v-tooltip location="top">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                class="mt-md-5"
                icon="$trashCan"
                size="x-small"
                variant="text"
                color="error"
                @click="removeButton(bIdx)"
              />
            </template>
            Delete Button
          </v-tooltip>
          <v-tooltip location="top">
            <template #activator="{ props: tip }">
              <v-btn
                v-bind="tip"
                class="mt-md-5"
                icon="$cog"
                size="x-small"
                variant="text"
                color="success"
                @click="configureButton(btn)"
              />
            </template>
            Configure {{ btn.actions?.length || 0 }} action{{
              btn.actions?.length !== 1 ? "s" : ""
            }}
          </v-tooltip>
        </div>

        <v-checkbox
          v-model="btn.saveResponse"
          label="Save response"
          density="compact"
          hide-details
          @update:model-value="onSaveResponseChange(btn, $event)"
        />
      </v-card-text>
    </v-card>

    <v-alert
      v-if="!node.buttons || node.buttons.length === 0"
      type="info"
      variant="tonal"
      density="compact"
    >
      Add buttons for users to tap. Each button can trigger different actions.
    </v-alert>
  </div>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>
