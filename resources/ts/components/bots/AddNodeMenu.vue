<script setup lang="ts">
import { ref, watch, computed } from "vue";
import type { NodeKind } from "../types";

const props = defineProps<{
  show: boolean;
  x: number;
  y: number;
  afterIndex: number;
}>();

const emit = defineEmits<{
  (e: "addNode", kind: NodeKind, afterIndex: number): void;
  (e: "close"): void;
}>();

const menuStep = ref<"root" | "message" | "interactive">("root");


// In AddNodeMenu.vue
const menuLocation = computed(() => {
  const viewportHeight = window.innerHeight;
  const spaceBelow = viewportHeight - props.y;
  const menuHeight = 400;

  // Automatically choose best position
  return spaceBelow < menuHeight ? "top center" : "bottom center";
});
watch(
  () => props.show,
  (newVal) => {
    if (!newVal) {
      // Reset to root when menu closes
      setTimeout(() => {
        menuStep.value = "root";
      }, 300);
    }
  },
);

function addNode(kind: NodeKind, mediaType?: string) {
  emit("addNode", kind, props.afterIndex);
  // If it's a media node with specific type, the parent will set the mediaType
  emit("close");
}
</script>

<template>
  <v-menu
    :model-value="show"
    :style="{
      position: 'fixed',
      left: `${x}px`,
      top: `${y}px`,
    }"
    :location="menuLocation"
    :close-on-content-click="false"
    @update:model-value="$emit('close')"
  >
    <v-card min-width="260">
      <v-card-text class="pa-3">
        <!-- Root Menu -->
        <template v-if="menuStep === 'root'">
          <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
            Add Node
          </div>
          <v-list density="compact">
            <v-list-item
              @click="addNode('trigger')"
              prepend-icon="$lightningBoltOutline"
            >
              <v-list-item-title>Trigger</v-list-item-title>
              <v-list-item-subtitle
                >Start the conversation</v-list-item-subtitle
              >
            </v-list-item>
            <v-list-item
              @click="menuStep = 'message'"
              prepend-icon="$messageText"
            >
              <v-list-item-title>Message</v-list-item-title>
              <v-list-item-subtitle>Text or media</v-list-item-subtitle>
              <template #append>
                <v-icon icon="$chevronRight" size="small" />
              </template>
            </v-list-item>
            <v-list-item
              @click="menuStep = 'interactive'"
              prepend-icon="$radioboxMarked"
            >
              <v-list-item-title>Interactive</v-list-item-title>
              <v-list-item-subtitle>Buttons or lists</v-list-item-subtitle>
              <template #append>
                <v-icon icon="$chevronRight" size="small" />
              </template>
            </v-list-item>
            <v-list-item @click="addNode('end')" prepend-icon="$flagCheckered">
              <v-list-item-title>End Flow</v-list-item-title>
              <v-list-item-subtitle>Close conversation</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>

        <!-- Message Submenu -->
        <template v-else-if="menuStep === 'message'">
          <v-btn
            size="x-small"
            variant="text"
            prepend-icon="$arrowLeft"
            @click="menuStep = 'root'"
            class="mb-2"
          >
            Back
          </v-btn>
          <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
            Message Type
          </div>
          <v-list density="compact">
            <v-list-item
              @click="addNode('message')"
              prepend-icon="$messageText"
            >
              <v-list-item-title>Text Message</v-list-item-title>
              <v-list-item-subtitle>Plain text message</v-list-item-subtitle>
            </v-list-item>

            <v-divider class="my-1" />

            <v-list-item @click="addNode('media')" prepend-icon="$imageOutline">
              <v-list-item-title>Image</v-list-item-title>
              <v-list-item-subtitle>Send an image</v-list-item-subtitle>
            </v-list-item>

            <v-list-item @click="addNode('media')" prepend-icon="$videoVintage">
              <v-list-item-title>Video</v-list-item-title>
              <v-list-item-subtitle>Send a video file</v-list-item-subtitle>
            </v-list-item>

            <v-list-item @click="addNode('media')" prepend-icon="$fileDocument">
              <v-list-item-title>Document</v-list-item-title>
              <v-list-item-subtitle>PDF, Excel, Word...</v-list-item-subtitle>
            </v-list-item>

            <v-list-item @click="addNode('media')" prepend-icon="$microphone">
              <v-list-item-title>Audio</v-list-item-title>
              <v-list-item-subtitle>Voice or music file</v-list-item-subtitle>
            </v-list-item>

            <v-divider class="my-1" />

            <v-list-item
              @click="addNode('location')"
              prepend-icon="$mapMarkerRadiusOutline"
            >
              <v-list-item-title>Location</v-list-item-title>
              <v-list-item-subtitle>Share a location pin</v-list-item-subtitle>
            </v-list-item>

            <v-list-item
              @click="addNode('contact')"
              prepend-icon="$cardAccountDetails"
            >
              <v-list-item-title>Contact</v-list-item-title>
              <v-list-item-subtitle>Share a vCard</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>

        <!-- Interactive Submenu -->
        <template v-else-if="menuStep === 'interactive'">
          <v-btn
            size="x-small"
            variant="text"
            prepend-icon="$arrowLeft"
            @click="menuStep = 'root'"
            class="mb-2"
          >
            Back
          </v-btn>
          <div class="text-caption text-grey-darken-2 text-uppercase mb-2">
            Interactive Type
          </div>
          <v-list density="compact">
            <v-list-item
              @click="addNode('buttons')"
              prepend-icon="$radioboxMarked"
            >
              <v-list-item-title>Quick Replies</v-list-item-title>
              <v-list-item-subtitle>Up to 3 buttons</v-list-item-subtitle>
            </v-list-item>
            <v-list-item
              @click="addNode('list')"
              prepend-icon="$formatListBulleted"
            >
              <v-list-item-title>List Message</v-list-item-title>
              <v-list-item-subtitle>Scrollable menu</v-list-item-subtitle>
            </v-list-item>
          </v-list>
        </template>
      </v-card-text>
    </v-card>
  </v-menu>
</template>
