<script setup lang="ts">
import type { FlowNode } from "../types";
import RichTextEditor from "@/components/RichTextEditor.vue";
import { useActionEditorStore } from "@/stores/actionEditor";

const actionEditor = useActionEditorStore();
const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  savedResponses: any[];
  nodeOptions: any[];
}>();

function configureNodeActions() {
  actionEditor.openActionEditor({
    targetNode: props.node,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
  });
}
</script>

<template>
  <div>
    <RichTextEditor
      v-model="node.text"
      label="Message text"
      placeholder="Type your message…"
      :available-variables="availableVariables"
      :show-formatting="true"
    />

    <v-divider class="my-4" />

    <!-- Store user input option -->
    <v-checkbox
      v-model="node.inputVariable"
      label="Wait for and store user's reply"
      density="compact"
      hide-details
      class="mb-3"
      true-value="user_reply"
      false-value=""
    />

    <v-combobox
      v-if="node.inputVariable"
      v-model="node.inputVariable"
      label="Save reply to variable"
      :items="availableVariables"
      placeholder="user_reply"
      variant="outlined"
      density="compact"
      class="mb-4"
    >
      <template #prepend-inner>
        <v-icon icon="$variable" size="small" />
      </template>
    </v-combobox>
    <!-- Message Node Actions -->
    <v-divider class="my-4" />
    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-2 font-weight-bold">Node Actions</div>
      <v-btn
        size="x-small"
        variant="outlined"
        prepend-icon="$cog"
        @click="configureNodeActions"
      >
        Configure {{ node.actions?.length || 0 }} action{{
          node.actions?.length !== 1 ? "s" : ""
        }}
      </v-btn>
    </div>
  </div>
</template>
