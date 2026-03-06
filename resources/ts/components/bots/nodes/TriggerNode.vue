<script setup lang="ts">
import type { FlowNode } from "../types";
import { useActionEditorStore } from "@/stores/actionEditor";

const actionEditor = useActionEditorStore();

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
  savedResponses: any[];
}>();

// Initialise defaults
if (!props.node.triggerType) props.node.triggerType = "any";

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
    <v-select v-model="node.triggerType" label="Trigger Type" :items="[
      { value: 'any', title: 'Any message' },
      { value: 'first', title: 'First message ever' },
      { value: 'keyword', title: 'Keyword match' },
      { value: 'opt_in', title: 'Opt-in' },
    ]" variant="outlined" density="compact" hint="When should this flow start?" persistent-hint />

    <v-text-field v-if="node.triggerType === 'keyword'" v-model="node.keywords" label="Keywords (comma-separated)"
      placeholder="hello, start, hi, menu" class="mt-4" variant="outlined" density="compact"
      hint="Users typing any of these words will trigger this flow" persistent-hint />

    <v-divider class="my-4" />

    <div class="d-flex align-center justify-space-between mb-3">
      <div class="text-subtitle-2 font-weight-bold">Node Actions</div>
      <v-btn size="x-small" variant="outlined" prepend-icon="$cog" @click="configureNodeActions">
        Configure {{ node.actions?.length || 0 }} action{{ node.actions?.length !== 1 ? "s" : "" }}
      </v-btn>
    </div>

    <v-alert v-if="!node.actions?.length" type="info" variant="tonal" density="compact">
      No actions configured. Actions run when this trigger fires.
    </v-alert>
  </div>
</template>