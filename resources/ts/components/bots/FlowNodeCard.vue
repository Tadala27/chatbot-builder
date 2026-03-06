<script setup lang="ts">
import { computed } from "vue";
import type { FlowNode } from "./types";
import { NODE_CONFIGS } from "./types";
import TriggerNode from "./nodes/TriggerNode.vue";
import MessageNode from "./nodes/MessageNode.vue";
import ButtonsNode from "./nodes/ButtonsNode.vue";
import ListNode from "./nodes/ListNode.vue";
import MediaNode from "./nodes/MediaNode.vue";
import LocationNode from "./nodes/LocationNode.vue";
import ContactNode from "./nodes/ContactNode.vue";
import EndNode from "./nodes/EndNode.vue";

const props = defineProps<{
  node: FlowNode;
  index: number;
  totalNodes: number;
  availableVariables: string[];
  savedResponses: any[];
  nodeOptions: any[];
  expanded: boolean;
  disabled?: boolean;
  apiIntegrations?: any[];
  customFunctions?: any[];
}>();

const emit = defineEmits<{
  (e: "update:expanded", value: boolean): void;
  (e: "setFirstNode", id: string): void;
  (e: "deleteNode", id: string): void;
  (e: "moveNode", index: number, direction: -1 | 1): void;
  (e: "toggleHandoff", node: FlowNode): void;
  (e: "openActionEditor", node: FlowNode): void;
}>();

const config = computed(() => NODE_CONFIGS[props.node.kind]);

// Sub-title shown in the collapsed card header
const nodeSubtitle = computed(() => {
  switch (props.node.kind) {
    case "trigger": return props.node.triggerType ?? "any";
    case "message": return truncate(props.node.text ?? "");
    case "buttons": return `${props.node.buttons?.length ?? 0} buttons`;
    case "list": return `${(props.node.action?.sections ?? []).reduce((s, sec) => s + sec.rows.length, 0)} items`;
    case "media": return props.node.mediaType ?? "image";
    case "location": return props.node.locationName ?? "Location";
    case "contact": return props.node.contactData?.name?.formatted_name ?? "Contact";
    case "end": return "End";
    default: return "";
  }
});

function truncate(s: string, max = 50) {
  // Strip simple HTML tags for preview
  const plain = s.replace(/<[^>]*>/g, "").trim();
  return plain.length > max ? plain.slice(0, max) + "…" : plain;
}

const nodeComponent = computed(() => {
  const map: Record<string, any> = {
    trigger: TriggerNode, message: MessageNode, buttons: ButtonsNode,
    list: ListNode, media: MediaNode, location: LocationNode,
    contact: ContactNode, end: EndNode,
  };
  return map[props.node.kind] ?? MessageNode;
});
</script>

<template>
  <v-card :style="{ borderLeft: `4px solid ${config.color}` }" variant="outlined" class="node-card"
    :class="{ 'node-card--disabled': disabled }" elevation="1">
    <!-- Disabled overlay -->
    <div v-if="disabled" class="node-card__overlay">
      <v-icon icon="$lock" size="large" color="grey" />
    </div>

    <!-- ── Card header ───────────────────────────────────────────────── -->
    <v-card-title class="d-flex align-center cursor-pointer bg-grey-lighten-5"
      @click="emit('update:expanded', !expanded)">
      <!-- Entry / Exit badges -->
      <v-chip v-if="node.isFirstNode" size="x-small" color="primary" variant="flat" class="mr-2">
        <v-icon icon="$play" size="x-small" class="mr-1" /> START
      </v-chip>
      <v-chip v-if="node.isLastNode" size="x-small" color="success" variant="flat" class="mr-2">
        <v-icon icon="$flagCheckered" size="x-small" class="mr-1" /> END
      </v-chip>

      <!-- Kind icon + label -->
      <v-icon :icon="config.icon" :color="config.color" class="mr-2" />
      <span class="text-subtitle-1 font-weight-bold" :style="{ color: config.color }">
        {{ config.label }}
      </span>

      <!-- Subtitle preview -->
      <span v-if="!expanded && nodeSubtitle" class="text-caption text-medium-emphasis ml-2 text-truncate"
        style="max-width: 180px;">
        — {{ nodeSubtitle }}
      </span>

      <v-chip size="x-small" variant="text" class="ml-2 text-medium-emphasis">
        #{{ node.id.slice(-5) }}
      </v-chip>

      <v-spacer />

      <!-- Actions menu -->
      <v-menu>
        <template #activator="{ props: mProps }">
          <v-btn icon="$dotsVertical" size="x-small" variant="text" v-bind="mProps" @click.stop />
        </template>
        <v-list density="compact" rounded="lg" elevation="8">
          <v-list-item @click="emit('setFirstNode', node.id)">
            <template #prepend><v-icon icon="$play" size="small" /></template>
            <v-list-item-title>Set as Entry Point</v-list-item-title>
          </v-list-item>
          <v-list-item @click="emit('toggleHandoff', node)">
            <template #prepend><v-icon icon="$humanGreetingProximity" size="small" /></template>
            <v-list-item-title>{{ node.triggersHandoff ? "Disable" : "Enable" }} Handoff</v-list-item-title>
          </v-list-item>
          <v-list-item v-if="['message', 'trigger', 'location'].includes(node.kind)"
            @click="emit('openActionEditor', node)">
            <template #prepend><v-icon icon="$lightningBolt" size="small" /></template>
            <v-list-item-title>Configure Actions</v-list-item-title>
          </v-list-item>
          <v-divider />
          <v-list-item @click="emit('deleteNode', node.id)" class="text-error">
            <template #prepend><v-icon icon="$trashCan" size="small" color="error" /></template>
            <v-list-item-title>Delete Node</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>

      <!-- Move buttons -->
      <v-btn icon="$arrowUp" size="x-small" variant="text" :disabled="index === 0"
        @click.stop="emit('moveNode', index, -1)" />
      <v-btn icon="$arrowDown" size="x-small" variant="text" :disabled="index === totalNodes - 1"
        @click.stop="emit('moveNode', index, 1)" />

      <v-icon :icon="expanded ? '$chevronUp' : '$chevronDown'" class="ml-1" />
    </v-card-title>

    <!-- ── Card body ─────────────────────────────────────────────────── -->
    <v-expand-transition>
      <div v-show="expanded">
        <v-divider />
        <v-card-text>
          <component :is="nodeComponent" :node="node" :available-variables="availableVariables"
            :node-options="nodeOptions" :saved-responses="savedResponses" :api-integrations="apiIntegrations ?? []"
            :custom-functions="customFunctions ?? []" />

          <!-- Handoff settings -->
          <template v-if="node.triggersHandoff">
            <v-divider class="my-4" />
            <v-alert type="warning" variant="tonal" density="compact" class="mb-3">
              <div class="text-subtitle-2 mb-1">
                <v-icon icon="$humanGreetingProximity" size="small" class="mr-1" />
                Agent Handoff Enabled
              </div>
              <div class="text-caption">
                After this node, the conversation will be handed off to a live agent.
              </div>
            </v-alert>
            <v-select v-model="node.postHandoffNode" label="Resume at node (after agent closes)" :items="nodeOptions"
              variant="outlined" density="compact" clearable
              hint="Optional: where to continue after the agent closes the conversation" persistent-hint />
          </template>
        </v-card-text>
      </div>
    </v-expand-transition>
  </v-card>
</template>

<style scoped>
.cursor-pointer {
  cursor: pointer;
}

.node-card {
  position: relative;
  margin-bottom: 0;
  /* connector handles spacing */
}

.node-card--disabled {
  opacity: 0.6;
  pointer-events: none;
  user-select: none;
}

.node-card__overlay {
  position: absolute;
  inset: 0;
  background: rgba(255, 255, 255, 0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  backdrop-filter: blur(2px);
}
</style>