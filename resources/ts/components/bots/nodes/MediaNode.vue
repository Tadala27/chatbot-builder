<script setup lang="ts">
import { ref, computed } from "vue";
import type { FlowNode } from "../types";
import RichTextField from "@/components/RichTextField.vue";
import axios from "axios";

const props = defineProps<{
  node: FlowNode;
  availableVariables: string[];
  nodeOptions: any[];
  botId: string;
}>();

if (!props.node.mediaType) props.node.mediaType = "image";

// ── Upload state ──────────────────────────────────────────────────────────────
const inputMode = ref<"url" | "upload">("url");
const uploading = ref(false);
const uploadError = ref("");
const uploadProgress = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const isDragOver = ref(false);
const imagePreview = ref<string | null>(null);

// ── Accept map ────────────────────────────────────────────────────────────────
const acceptMap: Record<string, string> = {
  image: "image/jpeg,image/png,image/webp,image/gif",
  video: "video/mp4,video/3gpp",
  audio: "audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg",
  document: "application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,application/zip",
};
const acceptAttr = computed(() => acceptMap[props.node.mediaType ?? "image"] ?? "*/*");

const mediaTypeItems = [
  { value: "image", title: "🖼️ Image" },
  { value: "video", title: "🎥 Video" },
  { value: "audio", title: "🎵 Audio" },
  { value: "document", title: "📄 Document" },
];

const mediaIconMap: Record<string, string> = {
  image: "$imageOutline",
  video: "$videoVintage",
  audio: "$microphone",
  document: "$fileDocumentOutline",
};
const mediaIcon = computed(() => mediaIconMap[props.node.mediaType ?? "image"]);
const isImage = computed(() => props.node.mediaType === "image");
const hasUrl = computed(() => !!props.node.mediaUrl?.trim());

// ── Core upload ───────────────────────────────────────────────────────────────
async function processFile(file: File) {
  uploadError.value = "";
  uploading.value = true;
  uploadProgress.value = 0;
  imagePreview.value = null;

  // Instant local blob preview for images (before the upload finishes)
  if (file.type.startsWith("image/")) {
    imagePreview.value = URL.createObjectURL(file);
  }

  // Optimistically populate the filename field
  props.node.mediaFilename = file.name;

  try {
    const form = new FormData();
    form.append("file", file);
    form.append("type", props.node.mediaType ?? "image");
    // No bot_id body param — it's in the route: /api/bots/{botId}/media/upload

    const res = await axios.post(`/api/bots/${props.botId}/media`, form, {
      headers: { "Content-Type": "multipart/form-data" },
      onUploadProgress: (evt) => {
        uploadProgress.value = Math.round((evt.loaded / (evt.total ?? 1)) * 100);
      },
    });

    props.node.mediaUrl = res.data.url;

    // Only overwrite filename if user hasn't manually changed it since we set it above
    if (props.node.mediaFilename === file.name) {
      props.node.mediaFilename = res.data.filename ?? file.name;
    }
  } catch (err: any) {
    uploadError.value = err.response?.data?.message ?? "Upload failed. Please try again.";
    imagePreview.value = null;
    props.node.mediaFilename = "";
  } finally {
    uploading.value = false;
    if (fileInput.value) fileInput.value.value = "";
  }
}

function handleFileChange(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (file) processFile(file);
}

function handleDrop(e: DragEvent) {
  isDragOver.value = false;
  const file = e.dataTransfer?.files?.[0];
  if (file) processFile(file);
}

function handleDragOver(e: DragEvent) {
  e.preventDefault();
  isDragOver.value = true;
}

function handleDragLeave() {
  isDragOver.value = false;
}

function openFilePicker() {
  if (!uploading.value) fileInput.value?.click();
}

function clearMedia() {
  props.node.mediaUrl = "";
  props.node.mediaFilename = "";
  props.node.mediaFileId = null;
  uploadError.value = "";
  imagePreview.value = null;
}

function onMediaTypeChange() {
  clearMedia();
}
</script>

<template>
  <div>

    <!-- Media type -->
    <VSelect v-model="node.mediaType" label="Media type" :items="mediaTypeItems" variant="outlined" density="compact"
      hide-details class="mb-4" @update:model-value="onMediaTypeChange" />

    <!-- Input mode tabs -->
    <VTabs v-model="inputMode" density="compact" color="primary" class="mb-3">
      <VTab value="url" prepend-icon="$linkVariant">URL / Link</VTab>
      <VTab value="upload" prepend-icon="$cloudUploadOutline">Upload File</VTab>
    </VTabs>

    <VWindow v-model="inputMode">

      <!-- ── URL tab ─────────────────────────────────────────────────────── -->
      <VWindowItem value="url">
        <VTextField v-model="node.mediaUrl" class="mt-2" label="Media URL" placeholder="https://example.com/file.jpg"
          variant="outlined" density="compact" hint="Direct public URL to the media file" persistent-hint
          :clearable="hasUrl" @click:clear="clearMedia">
          <template #prepend-inner>
            <VIcon icon="$linkVariant" size="small" />
          </template>
        </VTextField>
      </VWindowItem>

      <!-- ── Upload tab ─────────────────────────────────────────────────── -->
      <VWindowItem value="upload">

        <!-- Hidden real file input -->
        <input ref="fileInput" type="file" :accept="acceptAttr"
          style="position:absolute;width:0;height:0;pointer-events:none;opacity:0;" @change="handleFileChange" />

        <!-- ── Drop zone ────────────────────────────────────────────────── -->
        <div
          class="d-flex flex-column align-center justify-center border-dashed rounded-lg overflow-hidden bg-lightsecondary"
          :class="isDragOver ? 'border-primary' : 'border-thin'"
          style="width:100%; min-height:200px; cursor:pointer; position:relative;" @click="openFilePicker"
          @drop.prevent="handleDrop" @dragover.prevent="handleDragOver" @dragleave="handleDragLeave">
          <!-- Image preview fills the box -->
          <img v-if="isImage && (imagePreview || hasUrl)" :src="imagePreview || node.mediaUrl" alt="media preview"
            style="width:100%; height:100%; object-fit:cover; position:absolute; inset:0;" />

          <!-- Remove button overlay for images -->
          <div v-if="isImage && (imagePreview || hasUrl) && !uploading"
            style="position:absolute; top:8px; right:8px; z-index:2;" @click.stop="clearMedia">
            <VBtn icon="$close" size="x-small" color="error" variant="flat" rounded="circle" />
          </div>

          <!-- Uploading overlay -->
          <div v-if="uploading" class="d-flex flex-column align-center justify-center text-center px-4"
            style="position:relative; z-index:1;">
            <VProgressCircular indeterminate color="primary" size="40" class="mb-3" />
            <p class="text-body-2 font-weight-medium mb-2">Uploading… {{ uploadProgress }}%</p>
            <VProgressLinear :model-value="uploadProgress" color="primary" rounded height="4" style="width:160px;" />
          </div>

          <!-- Non-image success state -->
          <div v-else-if="hasUrl && !isImage" class="d-flex flex-column align-center justify-center text-center px-4"
            style="position:relative; z-index:1;">
            <VIcon icon="$checkCircleOutline" color="success" size="40" class="mb-2" />
            <p class="text-body-2 font-weight-medium text-success">File uploaded</p>
            <p class="text-caption text-medium-emphasis text-truncate mt-1" style="max-width:220px;">
              {{ node.mediaFilename || node.mediaUrl }}
            </p>
            <VBtn variant="text" size="x-small" color="error" prepend-icon="$close" class="mt-2"
              @click.stop="clearMedia">
              Remove
            </VBtn>
          </div>

          <!-- Idle / empty prompt -->
          <div v-else-if="!isImage || (!imagePreview && !hasUrl)" class="text-center px-4"
            style="position:relative; z-index:1;">
            <VIcon icon="$cloudUploadOutline" size="55" class="text-secondary" />
            <p class="text-body-2 font-weight-medium mt-1">Browse file or drop here</p>
            <p class="text-caption mt-1 text-medium-emphasis">
              <template v-if="node.mediaType === 'image'">Max 5 MB · PNG, JPG, WebP, GIF</template>
              <template v-else-if="node.mediaType === 'video'">Max 16 MB · MP4, 3GPP</template>
              <template v-else-if="node.mediaType === 'audio'">Max 16 MB · AAC, MP3, AMR, OGG</template>
              <template v-else>Max 100 MB · PDF, Word, Excel, TXT, ZIP</template>
            </p>
          </div>
        </div>

        <!-- Upload error -->
        <VAlert v-if="uploadError" type="error" variant="tonal" density="compact" rounded="lg" class="mt-2"
          :text="uploadError" closable @click:close="uploadError = ''" />

        <!-- Resolved URL (read-only) shown after upload -->
        <VTextField v-if="hasUrl && !uploading" :model-value="node.mediaUrl" label="Resolved URL" variant="outlined"
          density="compact" readonly class="mt-3" hint="This URL will be sent to WhatsApp" persistent-hint>
          <template #prepend-inner>
            <VIcon icon="$linkVariant" size="small" />
          </template>
        </VTextField>

      </VWindowItem>
    </VWindow>

    <!-- ── Document filename — auto-populated from upload, fully editable ── -->
    <VTextField v-if="node.mediaType === 'document'" v-model="node.mediaFilename" label="Document Filename"
      placeholder="report.pdf" variant="outlined" density="compact" class="mt-3"
      hint="Auto-filled from upload · you can edit this" persistent-hint>
      <template #prepend-inner>
        <VIcon icon="$fileDocumentOutline" size="small" />
      </template>
    </VTextField>

    <!-- ── Caption (not for audio) ──────────────────────────────────────────── -->
    <RichTextField v-if="node.mediaType !== 'audio'" v-model="node.mediaCaption" label="Caption (optional)"
      placeholder="Check out this…" :available-variables="availableVariables" :show-formatting="true" class="mt-3"
      hint="Optional text caption to accompany the media" />

    <VDivider class="my-4" />

    <!-- ── Preview card ─────────────────────────────────────────────────────── -->
    <VCard variant="tonal" rounded="lg" class="mb-4">
      <VCardText class="text-caption">
        <!-- Image preview from URL tab -->
        <div v-if="isImage && hasUrl && inputMode === 'url'" class="mb-3">
          <VImg :src="node.mediaUrl" max-height="160" cover rounded="lg" class="bg-grey-lighten-3">
            <template #error>
              <div class="d-flex align-center justify-center fill-height text-medium-emphasis text-caption">
                <VIcon icon="$imageBrokenVariant" class="mr-1" /> Cannot preview URL
              </div>
            </template>
          </VImg>
        </div>

        <div class="d-flex align-center">
          <VIcon :icon="mediaIcon" size="small" class="mr-2" />
          <span class="font-weight-medium">{{ node.mediaType?.toUpperCase() }} message</span>
        </div>
        <div class="mt-1 text-medium-emphasis">
          <template v-if="hasUrl">
            WhatsApp will send this {{ node.mediaType
            }}<span v-if="node.mediaCaption"> with caption.</span>
          </template>
          <template v-else>
            <VIcon icon="$alertCircleOutline" size="x-small" color="warning" class="mr-1" />
            No media source set yet.
          </template>
        </div>
      </VCardText>
    </VCard>

    <!-- ── After sending ─────────────────────────────────────────────────────── -->
    <VCard variant="outlined" rounded="lg" class="mb-3">
      <VCardText class="pa-3">
        <div class="d-flex align-center justify-space-between mb-1">
          <div>
            <div class="text-body-2 font-weight-medium">Wait for user reply</div>
            <div class="text-caption text-medium-emphasis">
              Pause the flow after sending — continue only when the user responds
            </div>
          </div>
          <VSwitch v-model="node.waitForReply" hide-details density="compact" color="primary" inset />
        </div>

        <!-- Optional: save whatever the user replies into a variable -->
        <VExpandTransition>
          <div v-if="node.waitForReply">
            <VDivider class="my-2" />
            <VCombobox v-model="node.replyVariable" :items="availableVariables"
              label="Save reply to variable (optional)" placeholder="e.g. user_confirmation" variant="outlined"
              density="compact" hide-details clearable class="mt-2">
              <template #prepend-inner>
                <VIcon icon="$variable" size="small" />
              </template>
            </VCombobox>
            <div class="text-caption text-medium-emphasis mt-1 px-1">
              The user's next message will be stored in this variable before the flow continues.
            </div>
          </div>
        </VExpandTransition>
      </VCardText>
    </VCard>

    <!-- ── Then go to (shown when NOT waiting, or always for context) ─────── -->
    <VSelect v-model="node.goTo" :label="node.waitForReply ? 'After reply, go to' : 'Then go to'"
      :items="nodeOptions.filter(o => o.value !== node.id)" variant="outlined" density="compact">
      <template #prepend-inner>
        <VIcon icon="$navigationVariantOutline" size="small" />
      </template>
    </VSelect>

  </div>
</template>