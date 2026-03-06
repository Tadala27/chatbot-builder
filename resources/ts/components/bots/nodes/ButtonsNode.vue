<script setup lang="ts">
import type { FlowNode, Btn, Action } from "../types";
import RichTextField from "@/components/RichTextField.vue";
import RichTextEditor from "@/components/RichTextEditor.vue";
import { useActionEditorStore } from "@/stores/actionEditor";
import { v4 as uuidv4 } from "uuid";

const actionEditor = useActionEditorStore();

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
  savedResponses: any[];
  apiIntegrations?: any[];
  customFunctions?: any[];
}>();

function makeDefaultAction(): Action {
  return { id: uuidv4(), kind: "navigation", goTo: "" };
}

function addButton() {
  if (!props.node.buttons) props.node.buttons = [];
  if (props.node.buttons.length < 3) {
    props.node.buttons.push({
      id: uuidv4(),
      label: `Option ${props.node.buttons.length + 1}`,
      actions: [makeDefaultAction()],
      saveResponse: false,
    });
  }
}

function removeButton(index: number) {
  props.node.buttons?.splice(index, 1);
}

function configureButton(btn: Btn) {
  actionEditor.openActionEditor({
    targetNode: props.node,
    targetButton: btn,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
    apiIntegrations: props.apiIntegrations ?? [],
    customFunctions: props.customFunctions ?? [],
  });
}
</script>

<template>
  <div>
    <RichTextEditor v-model="node.btnText" label="Message text" placeholder="Choose an option..."
      :available-variables="availableVariables" :show-formatting="true" hint="Message shown above the buttons" />

    <v-divider class="my-4" />

    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-2 font-weight-bold">
        Buttons ({{ node.buttons?.length || 0 }}/3)
      </div>
      <v-btn size="x-small" variant="outlined" prepend-icon="$plus" :disabled="(node.buttons?.length || 0) >= 3"
        @click="addButton">
        Add Button
      </v-btn>
    </div>

    <v-card v-for="(btn, bIdx) in node.buttons" :key="btn.id" variant="outlined" class="mb-3">
      <v-card-text>
        <div class="d-flex gap-2 align-center mb-3">
          <RichTextField v-model="btn.label" label="Button text" :available-variables="availableVariables"
            field-type="button" :max-length="20" show-variable-picker density="compact" class="flex-grow-1" />
          <v-tooltip location="top">
            <template #activator="{ props: tip }">
              <v-btn v-bind="tip" class="mt-md-5" icon="$cog" size="x-small" variant="text" color="success"
                @click="configureButton(btn)" />
            </template>
            Configure {{ btn.actions?.length || 0 }} action{{ btn.actions?.length !== 1 ? "s" : "" }}
          </v-tooltip>
          <v-tooltip location="top">
            <template #activator="{ props: tip }">
              <v-btn v-bind="tip" class="mt-md-5" icon="$trashCan" size="x-small" variant="text" color="error"
                @click="removeButton(bIdx)" />
            </template>
            Delete Button
          </v-tooltip>
        </div>

        <v-checkbox v-model="btn.saveResponse" label="Save response" density="compact" hide-details />
      </v-card-text>
    </v-card>

    <v-alert v-if="!node.buttons?.length" type="info" variant="tonal" density="compact">
      Add up to 3 buttons. Each can trigger different actions.
    </v-alert>
  </div>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>