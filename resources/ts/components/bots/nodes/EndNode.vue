<script setup lang="ts">
import type { FlowNode } from "../types";
import RichTextEditor from "@/components/RichTextEditor.vue";

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
}>();
</script>

<template>
  <div>
    <RichTextEditor
      v-model="node.text"
      label="Closing Message"
      placeholder="Thank you! Goodbye 👋"
      :available-variables="availableVariables"
      field-type="body"
      :max-length="4096"
      :show-formatting="true"
      hint="Final message sent before ending the conversation"
    />

    <v-alert color="success" variant="tonal" density="compact" class="mt-4">
      <div class="d-flex align-center">
        <v-icon icon="$flagCheckered" size="small" class="mr-2" />
        <div>
          <div class="text-caption">
            This node ends the conversation flow. The conversation will be
            marked as completed.
          </div>
        </div>
      </div>
    </v-alert>
  </div>
</template>
