<script setup lang="ts">
import { computed } from "vue";
import type { FlowNode } from "../types";
import RichTextField from "@/components/RichTextField.vue";
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
    <v-select
      v-model="node.text"
      label="Trigger Type"
      :items="[
        { value: 'keyword', title: 'Keyword match' },
        { value: 'any', title: 'Any message' },
        { value: 'first', title: 'First message ever' },
        { value: 'opt_in', title: 'Opt-in' },
      ]"
      variant="outlined"
      density="compact"
      hint="When should this flow start?"
      persistent-hint
    />

    <VTextField
      v-if="node.text === 'keyword'"
      v-model="node.mediaCaption"
      label="Keywords (comma-separated)"
      placeholder="hello, start, hi, menu"
      class="mt-4"
      variant="outlined"
      hint="Users typing these words will trigger this flow"
      persistent-hint
    />

    
    <!-- Trigger Node Actions -->
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
    <v-alert
      v-if="!node.actions || node.actions.length === 0"
      type="info"
      variant="tonal"
      density="compact"
    >
      No actions configured. Actions run when this trigger fires.
    </v-alert>
  </div>
</template>
