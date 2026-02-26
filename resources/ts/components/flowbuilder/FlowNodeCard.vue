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
}>();

const emit = defineEmits<{
  (e: "update:expanded", value: boolean): void;
  (e: "setFirstNode", id: string): void;
  (e: "setLastNode", id: string): void;
  (e: "deleteNode", id: string): void;
  (e: "moveNode", index: number, direction: -1 | 1): void;
  (e: "toggleHandoff", node: FlowNode): void;
}>();

const config = computed(() => NODE_CONFIGS[props.node.kind]);

const nodeComponent = computed(() => {
  const components = {
    trigger: TriggerNode,
    message: MessageNode,
    buttons: ButtonsNode,
    list: ListNode,
    media: MediaNode,
    location: LocationNode,
    contact: ContactNode,
    end: EndNode,
  };
  return components[props.node.kind as keyof typeof components] || MessageNode;
});
</script>

<template>
  <v-card
    :style="{ borderLeft: `4px solid ${config.color}` }"
    variant="outlined"
    class="mb-4 node-card"
    :class="{ 'node-card--disabled': disabled }"
    elevation="1"
  >
    <!-- Disabled Overlay -->
    <div v-if="disabled" class="node-card__overlay">
      <v-icon icon="$lock" size="large" color="grey" />
    </div>
    <!-- Header -->
    <v-card-title
      class="d-flex align-center cursor-pointer bg-grey-lighten-5"
      @click="emit('update:expanded', !expanded)"
    >
      <!-- Badges -->
      <v-chip
        v-if="node.isFirstNode"
        size="x-small"
        color="primary"
        variant="flat"
        class="mr-2"
      >
        <v-icon icon="$play" size="x-small" class="mr-1" />
        START
      </v-chip>

      <v-chip
        v-if="node.isLastNode"
        size="x-small"
        color="success"
        variant="flat"
        class="mr-2"
      >
        <v-icon icon="$flagCheckered" size="x-small" class="mr-1" />
        END
      </v-chip>

      <!-- Icon & Title -->
      <v-icon :icon="config.icon" :color="config.color" class="mr-2" />
      <span
        class="text-subtitle-1 font-weight-bold"
        :style="{ color: config.color }"
      >
        {{ config.label }}
      </span>
      <v-chip size="x-small" variant="text" class="ml-2">
        #{{ node.id.slice(-5) }}
      </v-chip>

      <v-spacer />

      <!-- Actions Menu -->
      <v-menu>
        <template #activator="{ props }">
          <v-btn
            icon="$dotsVertical"
            size="x-small"
            variant="text"
            v-bind="props"
            @click.stop
          />
        </template>
        <v-list density="compact">
          <v-list-item @click="emit('setFirstNode', node.id)">
            <template #prepend>
              <v-icon icon="$play" size="small" />
            </template>
            <v-list-item-title>Set as Entry Point</v-list-item-title>
          </v-list-item>
          <v-list-item @click="emit('setLastNode', node.id)">
            <template #prepend>
              <v-icon icon="$flagCheckered" size="small" />
            </template>
            <v-list-item-title>Set as Exit Point</v-list-item-title>
          </v-list-item>
          <v-list-item @click="emit('toggleHandoff', node)">
            <template #prepend>
              <v-icon icon="$humanGreetingProximity" size="small" />
            </template>
            <v-list-item-title>
              {{ node.triggersHandoff ? "Disable" : "Enable" }} Handoff
            </v-list-item-title>
          </v-list-item>
          <v-divider />
          <v-list-item @click="emit('deleteNode', node.id)" class="text-error">
            <template #prepend>
              <v-icon icon="$trashCan" size="small" color="error" />
            </template>
            <v-list-item-title>Delete Node</v-list-item-title>
          </v-list-item>
        </v-list>
      </v-menu>

      <!-- Move Buttons -->
      <v-btn
        icon="$arrowUp"
        size="x-small"
        variant="text"
        :disabled="index === 0"
        @click.stop="emit('moveNode', index, -1)"
      />
      <v-btn
        icon="$arrowDown"
        size="x-small"
        variant="text"
        :disabled="index === totalNodes - 1"
        @click.stop="emit('moveNode', index, 1)"
      />

      <v-icon :icon="expanded ? '$chevronUp' : '$chevronDown'" />
    </v-card-title>

    <!-- Body with Dynamic Component -->
    <v-expand-transition>
      <div v-show="expanded">
        <v-divider />
        <v-card-text>
          <component
            :is="nodeComponent"
            :node="node"
            :available-variables="availableVariables"
            :node-options="nodeOptions"
            :saved-responses="savedResponses"
          />

          <!-- Handoff Settings (for all nodes) -->
          <template v-if="node.triggersHandoff">
            <v-divider class="my-4" />
            <v-alert
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-3"
            >
              <div class="text-subtitle-2 mb-2">
                <v-icon icon="$humanGreetingProximity" size="small" />
                Agent Handoff Enabled
              </div>
              <div class="text-caption">
                After this node, conversation will be handed off to an agent.
              </div>
            </v-alert>
            <v-select
              v-model="node.postHandoffNode"
              label="Resume at node (after handoff ends)"
              :items="nodeOptions"
              variant="outlined"
              density="compact"
              clearable
              hint="Optional: Where to continue after agent closes conversation"
              persistent-hint
            />
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
}

.node-card--disabled {
  opacity: 0.6;
  pointer-events: none;
  user-select: none;
}

.node-card__overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(255, 255, 255, 0.8);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  backdrop-filter: blur(2px);
}
</style>
