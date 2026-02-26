<script setup lang="ts">
import type { FlowNode } from "../types";
import RichTextField from "@/components/RichTextField.vue";

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
}>();

// Initialize mediaType if not set
if (!props.node.mediaType) {
  props.node.mediaType = "image";
}
</script>

<template>
  <div>
    <v-select
      v-model="node.mediaType"
      label="Media type"
      :items="[
        { value: 'image', title: '🖼️ Image' },
        { value: 'video', title: '🎥 Video' },
        { value: 'audio', title: '🎵 Audio' },
        { value: 'document', title: '📄 Document' },
      ]"
      variant="outlined"
      density="compact"
      hint="Select the type of media to send"
      persistent-hint
    />

    <v-text-field
      v-model="node.mediaUrl"
      label="Media URL"
      placeholder="https://example.com/file.jpg"
      variant="outlined"
      density="compact"
      class="mt-4"
      hint="Direct link to your media file (must be publicly accessible)"
      persistent-hint
    >
      <template #prepend-inner>
        <v-icon icon="$linkVariant" size="small" />
      </template>
    </v-text-field>

    <!-- Document filename (only for documents) -->
    <v-text-field
      v-if="node.mediaType === 'document'"
      v-model="node.mediaFilename"
      label="Document Filename"
      placeholder="report.pdf"
      variant="outlined"
      density="compact"
      class="mt-3"
      hint="The filename that will be displayed (optional)"
      persistent-hint
    >
      <template #prepend-inner>
        <v-icon icon="$fileDocument" size="small" />
      </template>
    </v-text-field>

    <!-- Caption (for image, video, document) -->
    <RichTextField
      v-if="node.mediaType !== 'audio'"
      v-model="node.mediaCaption"
      label="Caption (optional)"
      placeholder="Check out this..."
      :available-variables="availableVariables"
      :show-formatting="true"
      class="mt-3"
      hint="Optional text caption to accompany the media"
    />

    <v-divider class="my-4" />

    <!-- Preview card -->
    <v-card variant="tonal" class="mb-3">
      <v-card-text class="text-caption">
        <div class="d-flex align-center">
          <v-icon
            :icon="
              node.mediaType === 'image'
                ? '$image'
                : node.mediaType === 'video'
                  ? '$videoVintage'
                  : node.mediaType === 'audio'
                    ? '$microphone'
                    : '$fileDocument'
            "
            size="small"
            class="mr-2"
          />
          <span class="font-weight-medium">
            {{ node.mediaType?.toUpperCase() }} message
          </span>
        </div>
        <div class="mt-2">
          WhatsApp will send this {{ node.mediaType }} from the URL you provided
          <span v-if="node.mediaCaption"> with caption.</span>
        </div>
      </v-card-text>
    </v-card>

    <v-select
      v-model="node.goTo"
      label="Then go to"
      :items="nodeOptions.filter((o) => o.value !== node.id)"
      variant="outlined"
      density="compact"
    >
      <template #prepend-inner>
        <v-icon icon="$navigationVariant" size="small" />
      </template>
    </v-select>
  </div>
</template>
