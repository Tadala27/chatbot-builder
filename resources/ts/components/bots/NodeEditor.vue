<script setup lang="ts">
import { computed, ref } from "vue";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";
import type { FlowNode, Btn } from "./types";
import RichTextField from "@/components/RichTextField.vue";
import RichTextEditor from "@/components/RichTextEditor.vue";
import { useActionEditorStore } from "@/stores/actionEditor";

const props = defineProps<{
  node: FlowNode;
  botId: string;
  availableVariables: string[];
  nodeOptions: any[];
  savedResponses: any[];
  apiIntegrations?: any[];
  customFunctions?: any[];
}>();

const actionEditor = useActionEditorStore();

// ── Shared: open the action editor drawer for this node's root actions ──────
function configureNodeActions() {
  actionEditor.openActionEditor({
    targetNode: props.node,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
    apiIntegrations: props.apiIntegrations ?? [],
    customFunctions: props.customFunctions ?? [],
  });
}

function configureButtonActions(btn: Btn) {
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

// Kinds that show the generic "Node Actions" footer.
const showsNodeActionsFooter = computed(
  () => !["buttons", "list", "end"].includes(props.node.kind),
);

// ───────────────────────────────────────────────────────────────────────────
// TRIGGER
// ───────────────────────────────────────────────────────────────────────────
if (props.node.kind === "trigger" && !props.node.triggerType) {
  props.node.triggerType = "any";
}

// ───────────────────────────────────────────────────────────────────────────
// LOCATION
// ───────────────────────────────────────────────────────────────────────────
if (props.node.kind === "location") {
  if (props.node.locationLatitude === undefined)
    props.node.locationLatitude = 0;
  if (props.node.locationLongitude === undefined)
    props.node.locationLongitude = 0;
  if (props.node.waitForReply === undefined) props.node.waitForReply = false;
}

// ───────────────────────────────────────────────────────────────────────────
// CONTACT
// ───────────────────────────────────────────────────────────────────────────
if (props.node.kind === "contact" && !props.node.contactData) {
  props.node.contactData = {
    name: { formatted_name: "", first_name: "", last_name: "" },
    phones: [{ phone: "", type: "Mobile", wa_id: "" }],
    emails: [],
    addresses: [],
    urls: [],
    org: { company: "", department: "", title: "" },
  };
}
if (props.node.kind === "contact" && props.node.waitForReply === undefined) {
  props.node.waitForReply = false;
}

function addPhone() {
  if (!props.node.contactData!.phones) props.node.contactData!.phones = [];
  props.node.contactData!.phones.push({ phone: "", type: "Mobile", wa_id: "" });
}
function removePhone(index: number) {
  props.node.contactData!.phones?.splice(index, 1);
}
function addEmail() {
  if (!props.node.contactData!.emails) props.node.contactData!.emails = [];
  props.node.contactData!.emails.push({ email: "", type: "Work" });
}
function removeEmail(index: number) {
  props.node.contactData!.emails?.splice(index, 1);
}

// ───────────────────────────────────────────────────────────────────────────
// BUTTONS
// ───────────────────────────────────────────────────────────────────────────
function makeDefaultAction() {
  return { id: uuidv4(), kind: "navigation" as const, goTo: "" };
}

function addButton() {
  if (!props.node.buttons) props.node.buttons = [];
  if (props.node.buttons.length < 3) {
    props.node.buttons.push({
      id: uuidv4(),
      label: `Option ${props.node.buttons.length + 1}`,
      type: "reply",
      url: "",
      actions: [makeDefaultAction()],
      saveResponse: false,
    });
  }
}
function removeButton(index: number) {
  props.node.buttons?.splice(index, 1);
}

// ───────────────────────────────────────────────────────────────────────────
// LIST
// ───────────────────────────────────────────────────────────────────────────
function totalListRows(): number {
  return (props.node.action?.sections ?? []).reduce(
    (sum, section) => sum + section.rows.length,
    0,
  );
}
function addSection() {
  if (!props.node.action)
    props.node.action = { button: "View Options", sections: [] };
  const n = props.node.action.sections.length + 1;
  props.node.action.sections.push({
    id: uuidv4(),
    title: `Section ${n}`,
    rows: [
      {
        id: uuidv4(),
        title: "Item 1",
        description: "",
        actions: [makeDefaultAction()],
        saveResponse: false,
      },
    ],
  });
}
function addRow(section: any) {
  if (totalListRows() >= 10) return;
  section.rows.push({
    id: uuidv4(),
    title: `Item ${section.rows.length + 1}`,
    description: "",
    actions: [makeDefaultAction()],
    saveResponse: false,
  });
}
function removeSection(index: number) {
  props.node.action?.sections?.splice(index, 1);
}
function removeRow(section: any, index: number) {
  section.rows.splice(index, 1);
}
function configureRowActions(row: any) {
  actionEditor.openActionEditor({
    targetNode: props.node,
    targetRow: row,
    availableVariables: props.availableVariables,
    nodeOptions: props.nodeOptions,
    savedResponses: props.savedResponses,
    apiIntegrations: props.apiIntegrations ?? [],
    customFunctions: props.customFunctions ?? [],
  });
}

// ───────────────────────────────────────────────────────────────────────────
// MEDIA — upload state
// ───────────────────────────────────────────────────────────────────────────
if (props.node.kind === "media" && !props.node.mediaType) {
  props.node.mediaType = "image";
}
if (props.node.kind === "media" && props.node.waitForReply === undefined) {
  props.node.waitForReply = false;
}

const inputMode = ref<"url" | "upload">("url");
const uploading = ref(false);
const uploadError = ref("");
const uploadProgress = ref(0);
const fileInput = ref<HTMLInputElement | null>(null);
const isDragOver = ref(false);
const imagePreview = ref<string | null>(null);

const acceptMap: Record<string, string> = {
  image: "image/jpeg,image/png,image/webp,image/gif",
  video: "video/mp4,video/3gpp",
  audio: "audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg",
  document:
    "application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/plain,application/zip",
};
const acceptAttr = computed(
  () => acceptMap[props.node.mediaType ?? "image"] ?? "*/*",
);

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

async function processFile(file: File) {
  uploadError.value = "";
  uploading.value = true;
  uploadProgress.value = 0;
  imagePreview.value = null;

  if (file.type.startsWith("image/")) {
    imagePreview.value = URL.createObjectURL(file);
  }

  props.node.mediaFilename = file.name;

  try {
    const form = new FormData();
    form.append("file", file);
    form.append("type", props.node.mediaType ?? "image");

    const res = await axios.post(`/tenant/bots/${props.botId}/media`, form, {
      headers: { "Content-Type": "multipart/form-data" },
      onUploadProgress: (evt) => {
        uploadProgress.value = Math.round(
          (evt.loaded / (evt.total ?? 1)) * 100,
        );
      },
    });

    props.node.mediaUrl = res.data.url;
    props.node.mediaFileId = res.data.id;
    props.node.mediaFilename = res.data.filename ?? file.name;
  } catch (err: any) {
    uploadError.value =
      err.response?.data?.message ?? "Upload failed. Please try again.";
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
  props.node.mediaFileId = undefined;
  uploadError.value = "";
  imagePreview.value = null;
}

function onMediaTypeChange() {
  clearMedia();
}
</script>

<template>
  <div>
    <!-- ══════════════════════════════════════════════════════════════════
         MESSAGE
    ══════════════════════════════════════════════════════════════════ -->
    <template v-if="node.kind === 'message'">
      <RichTextEditor
        v-model="node.text"
        label="Message text"
        placeholder="Type your message..."
        :available-variables="availableVariables"
        :show-formatting="true"
        :maxLength="4096"
      />
      <v-divider class="my-4" />
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
        <template #prepend-inner
          ><v-icon icon="$variable" size="small"
        /></template>
      </v-combobox>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         BUTTONS
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'buttons'">
      <RichTextEditor
        v-model="node.btnText"
        label="Message text"
        placeholder="Choose an option..."
        :available-variables="availableVariables"
        :show-formatting="true"
        hint="Message shown above the buttons"
      />
      <v-divider class="my-4" />
      <div class="d-flex align-center justify-space-between mb-3">
        <div class="text-subtitle-2 font-weight-bold">
          Buttons ({{ node.buttons?.length || 0 }}/3)
        </div>
        <v-btn
          size="x-small"
          variant="outlined"
          prepend-icon="$plus"
          :disabled="(node.buttons?.length || 0) >= 3"
          @click="addButton"
        >
          Add Button
        </v-btn>
      </div>

      <v-card
        v-for="(btn, bIdx) in node.buttons"
        :key="btn.id"
        variant="outlined"
        class="mb-3"
      >
        <v-card-text>
          <!-- Button Type Selection -->
          <div class="d-flex gap-2 align-center mb-3">
            <v-select
              v-model="btn.type"
              label="Button Type"
              :items="[
                { value: 'reply', title: 'Reply' },
                { value: 'url', title: 'URL' },
              ]"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 170px; min-width: 100px"
            />

            <RichTextField
              v-model="btn.label"
              placeholder="Button text"
              :available-variables="availableVariables"
              field-type="button"
              :max-length="20"
              show-variable-picker
              density="compact"
              class="flex-grow-1 mt-5"
            />

            <v-tooltip location="top">
              <template #activator="{ props: tip }">
                <v-btn
                  v-bind="tip"
                  icon="$cog"
                  size="x-small"
                  variant="text"
                  color="success"
                  @click="configureButtonActions(btn)"
                />
              </template>
              Configure {{ btn.actions?.length || 0 }} action{{
                btn.actions?.length !== 1 ? "s" : ""
              }}
            </v-tooltip>
            <v-tooltip location="top">
              <template #activator="{ props: tip }">
                <v-btn
                  v-bind="tip"
                  icon="$trashCan"
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="removeButton(bIdx)"
                />
              </template>
              Delete Button
            </v-tooltip>
          </div>

          <!-- URL Field (only for URL buttons) -->
          <div v-if="btn.type === 'url'" class="mb-3">
            <RichTextField
              v-model="btn.url"
              label="Button URL *"
              placeholder="https://example.com/page"
              :available-variables="availableVariables"
              field-type="url"
              show-variable-picker
              density="compact"
              hint="The URL to open when the button is clicked"
              persistent-hint
            />
            <div class="text-caption text-medium-emphasis mt-1">
              URL buttons open in the user's browser. You can use variables in
              the URL.
            </div>
          </div>

          <!-- Reply Button specific options -->
          <div v-if="btn.type === 'reply'" class="mb-3">
            <v-checkbox
              v-model="btn.saveResponse"
              label="Save response"
              density="compact"
              hide-details
            />
            <div class="text-caption text-medium-emphasis mt-1">
              The user's selection will be stored when they click this button.
            </div>
          </div>

          <!-- Info alert for URL buttons -->
          <v-alert
            v-if="btn.type === 'url'"
            type="info"
            variant="tonal"
            density="compact"
            class="mt-2"
          >
            <div class="text-caption">
              <strong>Note:</strong> URL buttons are sent as interactive CTA
              messages. The user is redirected to the URL when they tap the
              button.
              <span v-if="!btn.url" class="text-error">
                Please enter a URL.
              </span>
            </div>
          </v-alert>
        </v-card-text>
      </v-card>

      <v-alert
        v-if="!node.buttons?.length"
        type="info"
        variant="tonal"
        density="compact"
      >
        Add up to 3 buttons. Each can be either a Reply button (triggers actions
        in the bot) or a URL button (opens a link).
      </v-alert>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         LIST
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'list'">
      <RichTextField
        v-model="node.listHeader"
        label="List Header (optional)"
        placeholder="Menu"
        :available-variables="availableVariables"
        field-type="header"
        :available-functions="customFunctions"
        :max-length="60"
        show-variable-picker
        density="compact"
      />
      <RichTextEditor
        class="mt-3"
        v-model="node.listBody"
        label="List Body"
        placeholder="Please choose an option..."
        :available-variables="availableVariables"
        field-type="body"
        :max-length="4096"
        :show-formatting="true"
      />
      <RichTextField
        v-model="node.listFooter"
        label="List Footer (optional)"
        placeholder="Footer"
        :available-variables="availableVariables"
        field-type="footer"
        :max-length="60"
        show-variable-picker
        density="compact"
      />
      <v-divider class="my-4" />
      <RichTextField
        v-if="node.action"
        v-model="node.action.button"
        label="Call to Action Button"
        placeholder="View Options"
        :available-variables="availableVariables"
        :available-functions="customFunctions"
        field-type="button"
        :max-length="20"
        show-variable-picker
        density="compact"
        hint="Button text that opens the list"
        persistent-hint
      />
      <v-divider class="my-4" />

      <div class="d-flex align-center justify-space-between mb-3">
        <div class="text-subtitle-2 font-weight-bold">
          Sections ({{ node.action?.sections?.length || 0 }})
          <span class="text-caption text-medium-emphasis ml-1"
            >— {{ totalListRows() }}/10 items used</span
          >
        </div>
        <v-btn
          size="x-small"
          variant="outlined"
          prepend-icon="$plus"
          @click="addSection"
          >Add Section</v-btn
        >
      </div>

      <template v-if="node.action?.sections?.length">
        <v-card
          v-for="(section, sIdx) in node.action.sections"
          :key="section.id"
          variant="outlined"
          class="mb-3"
        >
          <div class="d-flex align-center bg-grey-lighten-5 pa-2">
            <RichTextField
              v-model="section.title"
              placeholder="Section title"
              :available-variables="availableVariables"
              field-type="title"
              :max-length="24"
              show-variable-picker
              variant="plain"
              density="compact"
              class="flex-grow-1"
            />
            <v-btn
              icon="$trashCan"
              size="x-small"
              variant="text"
              color="error"
              @click="removeSection(sIdx)"
            />
          </div>
          <v-divider />
          <v-card-text>
            <v-alert
              v-if="totalListRows() >= 10"
              type="warning"
              variant="tonal"
              density="compact"
              class="mb-2"
            >
              Maximum of 10 items across all sections.
            </v-alert>
            <v-card
              v-for="(row, rIdx) in section.rows"
              :key="row.id"
              variant="outlined"
              class="mb-2"
            >
              <v-card-text class="pa-2">
                <div class="d-flex gap-2 align-center mb-2">
                  <RichTextField
                    v-model="row.title"
                    placeholder="Item title"
                    :available-variables="availableVariables"
                    field-type="title"
                    :max-length="24"
                    show-variable-picker
                    density="compact"
                    class="flex-grow-1"
                  />
                  <v-tooltip location="top">
                    <template #activator="{ props: tip }">
                      <v-btn
                        v-bind="tip"
                        icon="$cog"
                        size="x-small"
                        variant="text"
                        color="success"
                        @click="configureRowActions(row)"
                      />
                    </template>
                    Configure {{ row.actions?.length || 0 }} action{{
                      row.actions?.length !== 1 ? "s" : ""
                    }}
                  </v-tooltip>
                  <v-tooltip location="top">
                    <template #activator="{ props: tip }">
                      <v-btn
                        v-bind="tip"
                        icon="$trashCan"
                        size="x-small"
                        variant="text"
                        color="error"
                        @click="removeRow(section, rIdx)"
                      />
                    </template>
                    Delete Item
                  </v-tooltip>
                </div>
                <RichTextField
                  v-model="row.description"
                  placeholder="Description (optional)"
                  :available-variables="availableVariables"
                  field-type="description"
                  :max-length="72"
                  show-variable-picker
                  density="compact"
                  class="mb-2"
                />
                <v-checkbox
                  v-model="row.saveResponse"
                  label="Save response"
                  density="compact"
                  hide-details
                />
              </v-card-text>
            </v-card>
            <v-btn
              size="small"
              variant="text"
              prepend-icon="$plus"
              block
              :disabled="totalListRows() >= 10"
              @click="addRow(section)"
            >
              Add Row
            </v-btn>
          </v-card-text>
        </v-card>
      </template>
      <v-alert v-else type="info" variant="tonal" density="compact"
        >No sections yet. Click "Add Section" to begin.</v-alert
      >
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         MEDIA
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'media'">
      <VSelect
        v-model="node.mediaType"
        label="Media type"
        :items="mediaTypeItems"
        variant="outlined"
        density="compact"
        hide-details
        class="mb-4"
        @update:model-value="onMediaTypeChange"
      />

      <VTabs v-model="inputMode" density="compact" color="primary" class="mb-3">
        <VTab value="url" prepend-icon="$linkVariant">URL / Link</VTab>
        <VTab value="upload" prepend-icon="$cloudUploadOutline"
          >Upload File</VTab
        >
      </VTabs>

      <VWindow v-model="inputMode">
        <VWindowItem value="url">
          <VTextField
            v-model="node.mediaUrl"
            class="mt-2"
            label="Media URL"
            placeholder="https://example.com/file.jpg"
            variant="outlined"
            density="compact"
            hint="Direct public URL to the media file"
            persistent-hint
            :clearable="hasUrl"
            @click:clear="clearMedia"
          >
            <template #prepend-inner
              ><VIcon icon="$linkVariant" size="small"
            /></template>
          </VTextField>
        </VWindowItem>

        <VWindowItem value="upload">
          <input
            ref="fileInput"
            type="file"
            :accept="acceptAttr"
            style="
              position: absolute;
              width: 0;
              height: 0;
              pointer-events: none;
              opacity: 0;
            "
            @change="handleFileChange"
          />

          <div
            class="d-flex flex-column align-center justify-center border-dashed rounded-lg overflow-hidden bg-lightsecondary"
            :class="isDragOver ? 'border-primary' : 'border-thin'"
            style="
              width: 100%;
              min-height: 200px;
              cursor: pointer;
              position: relative;
            "
            @click="openFilePicker"
            @drop.prevent="handleDrop"
            @dragover.prevent="handleDragOver"
            @dragleave="handleDragLeave"
          >
            <img
              v-if="isImage && (imagePreview || hasUrl)"
              :src="imagePreview || node.mediaUrl"
              alt="media preview"
              style="
                width: 100%;
                height: 100%;
                object-fit: cover;
                position: absolute;
                inset: 0;
              "
            />

            <div
              v-if="isImage && (imagePreview || hasUrl) && !uploading"
              style="position: absolute; top: 8px; right: 8px; z-index: 2"
              @click.stop="clearMedia"
            >
              <VBtn
                icon="$close"
                size="x-small"
                color="error"
                variant="flat"
                rounded="circle"
              />
            </div>

            <div
              v-if="uploading"
              class="d-flex flex-column align-center justify-center text-center px-4"
              style="position: relative; z-index: 1"
            >
              <VProgressCircular
                indeterminate
                color="primary"
                size="40"
                class="mb-3"
              />
              <p class="text-body-2 font-weight-medium mb-2">
                Uploading… {{ uploadProgress }}%
              </p>
              <VProgressLinear
                :model-value="uploadProgress"
                color="primary"
                rounded
                height="4"
                style="width: 160px"
              />
            </div>

            <div
              v-else-if="hasUrl && !isImage"
              class="d-flex flex-column align-center justify-center text-center px-4"
              style="position: relative; z-index: 1"
            >
              <VIcon
                icon="$checkCircleOutline"
                color="success"
                size="40"
                class="mb-2"
              />
              <p class="text-body-2 font-weight-medium text-success">
                File uploaded
              </p>
              <p
                class="text-caption text-medium-emphasis text-truncate mt-1"
                style="max-width: 220px"
              >
                {{ node.mediaFilename || node.mediaUrl }}
              </p>
              <VBtn
                variant="text"
                size="x-small"
                color="error"
                prepend-icon="$close"
                class="mt-2"
                @click.stop="clearMedia"
                >Remove</VBtn
              >
            </div>

            <div
              v-else-if="!isImage || (!imagePreview && !hasUrl)"
              class="text-center px-4"
              style="position: relative; z-index: 1"
            >
              <VIcon
                icon="$cloudUploadOutline"
                size="55"
                class="text-secondary"
              />
              <p class="text-body-2 font-weight-medium mt-1">
                Browse file or drop here
              </p>
              <p class="text-caption mt-1 text-medium-emphasis">
                <template v-if="node.mediaType === 'image'"
                  >Max 5 MB · PNG, JPG, WebP, GIF</template
                >
                <template v-else-if="node.mediaType === 'video'"
                  >Max 16 MB · MP4, 3GPP</template
                >
                <template v-else-if="node.mediaType === 'audio'"
                  >Max 16 MB · AAC, MP3, AMR, OGG</template
                >
                <template v-else
                  >Max 100 MB · PDF, Word, Excel, TXT, ZIP</template
                >
              </p>
            </div>
          </div>

          <VAlert
            v-if="uploadError"
            type="error"
            variant="tonal"
            density="compact"
            rounded="lg"
            class="mt-2"
            :text="uploadError"
            closable
            @click:close="uploadError = ''"
          />

          <VTextField
            v-if="hasUrl && !uploading"
            :model-value="node.mediaUrl"
            label="Resolved URL"
            variant="outlined"
            density="compact"
            readonly
            class="mt-3"
            hint="This URL will be sent to WhatsApp"
            persistent-hint
          >
            <template #prepend-inner
              ><VIcon icon="$linkVariant" size="small"
            /></template>
          </VTextField>
        </VWindowItem>
      </VWindow>

      <VTextField
        v-if="node.mediaType === 'document'"
        v-model="node.mediaFilename"
        label="Document Filename"
        placeholder="report.pdf"
        variant="outlined"
        density="compact"
        class="mt-3"
        hint="Auto-filled from upload · you can edit this"
        persistent-hint
      >
        <template #prepend-inner
          ><VIcon icon="$fileDocumentOutline" size="small"
        /></template>
      </VTextField>

      <RichTextField
        v-if="node.mediaType !== 'audio'"
        v-model="node.mediaCaption"
        label="Caption (optional)"
        placeholder="Check out this…"
        :available-variables="availableVariables"
        :show-formatting="true"
        class="mt-3"
        hint="Optional text caption to accompany the media"
      />

      <v-divider class="my-4" />

      <VCard variant="tonal" rounded="lg" class="mb-4">
        <VCardText class="text-caption">
          <div v-if="isImage && hasUrl && inputMode === 'url'" class="mb-3">
            <VImg
              :src="node.mediaUrl"
              max-height="160"
              cover
              rounded="lg"
              class="bg-grey-lighten-3"
            >
              <template #error>
                <div
                  class="d-flex align-center justify-center fill-height text-medium-emphasis text-caption"
                >
                  <VIcon icon="$imageBrokenVariant" class="mr-1" /> Cannot
                  preview URL
                </div>
              </template>
            </VImg>
          </div>
          <div class="d-flex align-center">
            <VIcon :icon="mediaIcon" size="small" class="mr-2" />
            <span class="font-weight-medium"
              >{{ node.mediaType?.toUpperCase() }} message</span
            >
          </div>
          <div class="mt-1 text-medium-emphasis">
            <template v-if="hasUrl"
              >WhatsApp will send this {{ node.mediaType
              }}<span v-if="node.mediaCaption"> with caption.</span></template
            >
            <template v-else
              ><VIcon
                icon="$alertCircleOutline"
                size="x-small"
                color="warning"
                class="mr-1"
              />No media source set yet.</template
            >
          </div>
        </VCardText>
      </VCard>

      <VCard variant="outlined" rounded="lg" class="mb-3">
        <VCardText class="pa-3">
          <div class="d-flex align-center justify-space-between mb-1">
            <div>
              <div class="text-body-2 font-weight-medium">
                Wait for user reply
              </div>
              <div class="text-caption text-medium-emphasis">
                Pause the flow after sending — continue only when the user
                responds
              </div>
            </div>
            <VSwitch
              v-model="node.waitForReply"
              hide-details
              density="compact"
              color="primary"
              inset
            />
          </div>
          <VExpandTransition>
            <div v-if="node.waitForReply">
              <VDivider class="my-2" />
              <VCombobox
                v-model="node.replyVariable"
                :items="availableVariables"
                label="Save reply to variable (optional)"
                placeholder="e.g. user_confirmation"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                class="mt-2"
              >
                <template #prepend-inner
                  ><VIcon icon="$variable" size="small"
                /></template>
              </VCombobox>
              <div class="text-caption text-medium-emphasis mt-1 px-1">
                The user's next message will be stored in this variable before
                the flow continues.
              </div>
            </div>
          </VExpandTransition>
        </VCardText>
      </VCard>

      <VSelect
        v-model="node.goTo"
        :label="node.waitForReply ? 'After reply, go to' : 'Then go to'"
        :items="nodeOptions.filter((o) => o.value !== node.id)"
        variant="outlined"
        density="compact"
      >
        <template #prepend-inner
          ><VIcon icon="$navigationVariantOutline" size="small"
        /></template>
      </VSelect>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         LOCATION
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'location'">
      <v-row>
        <v-col cols="6">
          <v-text-field
            v-model.number="node.locationLatitude"
            label="Latitude"
            placeholder="-25.7479"
            variant="outlined"
            density="compact"
            type="number"
            step="any"
            hint="Example: -25.7479"
            persistent-hint
          >
            <template #prepend-inner
              ><v-icon icon="$latitude" size="small"
            /></template>
          </v-text-field>
        </v-col>
        <v-col cols="6">
          <v-text-field
            v-model.number="node.locationLongitude"
            label="Longitude"
            placeholder="28.2293"
            variant="outlined"
            density="compact"
            type="number"
            step="any"
            hint="Example: 28.2293"
            persistent-hint
          >
            <template #prepend-inner
              ><v-icon icon="$longitude" size="small"
            /></template>
          </v-text-field>
        </v-col>
      </v-row>
      <v-text-field
        v-model="node.locationName"
        label="Location Name"
        placeholder="OneNICO Office"
        variant="outlined"
        density="compact"
        class="mt-2"
        hint="Display name for the location"
        persistent-hint
      >
        <template #prepend-inner
          ><v-icon icon="$mapMarker" size="small"
        /></template>
      </v-text-field>
      <v-textarea
        v-model="node.locationAddress"
        label="Address (optional)"
        placeholder="123 Main Street, City"
        variant="outlined"
        density="compact"
        rows="2"
        class="mt-3"
        hint="Full address of the location"
        persistent-hint
      >
        <template #prepend-inner><v-icon icon="$home" size="small" /></template>
      </v-textarea>

      <v-divider class="my-4" />

      <VCard variant="outlined" rounded="lg" class="mb-3">
        <VCardText class="pa-3">
          <div class="d-flex align-center justify-space-between mb-1">
            <div>
              <div class="text-body-2 font-weight-medium">
                Wait for user reply
              </div>
              <div class="text-caption text-medium-emphasis">
                Pause the flow after sending location — continue only when the
                user responds
              </div>
            </div>
            <VSwitch
              v-model="node.waitForReply"
              hide-details
              density="compact"
              color="primary"
              inset
            />
          </div>
          <VExpandTransition>
            <div v-if="node.waitForReply">
              <VDivider class="my-2" />
              <VCombobox
                v-model="node.replyVariable"
                :items="availableVariables"
                label="Save reply to variable (optional)"
                placeholder="e.g. user_location_response"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                class="mt-2"
              >
                <template #prepend-inner
                  ><VIcon icon="$variable" size="small"
                /></template>
              </VCombobox>
              <div class="text-caption text-medium-emphasis mt-1 px-1">
                The user's next message will be stored in this variable before
                the flow continues.
              </div>
            </div>
          </VExpandTransition>
        </VCardText>
      </VCard>

      <v-card variant="tonal" class="mb-3">
        <v-card-text class="text-caption">
          <div class="d-flex align-center mb-2">
            <v-icon icon="$mapMarker" size="small" class="mr-2" color="error" />
            <span class="font-weight-medium">LOCATION PIN</span>
          </div>
          <div v-if="node.locationName">
            <strong>{{ node.locationName }}</strong>
          </div>
          <div
            v-if="node.locationLatitude && node.locationLongitude"
            class="text-grey"
          >
            {{ node.locationLatitude }}, {{ node.locationLongitude }}
          </div>
          <div v-if="node.locationAddress" class="mt-1">
            {{ node.locationAddress }}
          </div>
          <v-alert
            v-if="!node.locationLatitude || !node.locationLongitude"
            type="warning"
            variant="tonal"
            density="compact"
            class="mt-2"
          >
            Please enter valid coordinates
          </v-alert>
        </v-card-text>
      </v-card>

      <VSelect
        v-model="node.goTo"
        :label="node.waitForReply ? 'After reply, go to' : 'Then go to'"
        :items="nodeOptions.filter((o) => o.value !== node.id)"
        variant="outlined"
        density="compact"
      >
        <template #prepend-inner
          ><VIcon icon="$navigationVariant" size="small"
        /></template>
      </VSelect>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         CONTACT
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'contact'">
      <v-alert type="info" variant="tonal" density="compact" class="mb-3">
        <div class="text-caption">
          Send a contact card (vCard) that users can save to their contacts
        </div>
      </v-alert>

      <div class="text-subtitle-2 font-weight-bold mb-2">Contact Name</div>
      <v-text-field
        v-model="node.contactData!.name.formatted_name"
        label="Full Name *"
        placeholder="John Doe"
        variant="outlined"
        density="compact"
        hint="How the name appears in WhatsApp"
        persistent-hint
      >
        <template #prepend-inner>
          <v-icon icon="$account" size="small" />
        </template>
      </v-text-field>

      <v-row class="mt-2">
        <v-col cols="6">
          <v-text-field
            v-model="node.contactData!.name.first_name"
            label="First Name"
            placeholder="John"
            variant="outlined"
            density="compact"
            hide-details
          />
        </v-col>
        <v-col cols="6">
          <v-text-field
            v-model="node.contactData!.name.last_name"
            label="Last Name"
            placeholder="Doe"
            variant="outlined"
            density="compact"
            hide-details
          />
        </v-col>
      </v-row>

      <!-- Phone Numbers -->
      <v-divider class="my-4" />
      <div class="d-flex align-center justify-space-between mb-2">
        <div class="text-subtitle-2 font-weight-bold">Phone Numbers</div>
        <v-btn
          size="x-small"
          variant="outlined"
          prepend-icon="$plus"
          @click="addPhone"
        >
          Add Phone
        </v-btn>
      </div>

      <v-card
        v-for="(phone, pIdx) in node.contactData!.phones"
        :key="pIdx"
        variant="outlined"
        class="mb-2"
      >
        <v-card-text class="pa-2">
          <div class="d-flex gap-2 align-center">
            <v-text-field
              v-model="phone.phone"
              label="Phone Number"
              placeholder="+1234567890"
              variant="outlined"
              density="compact"
              hide-details
              class="flex-grow-1"
            >
              <template #prepend-inner>
                <v-icon icon="$phone" size="small" />
              </template>
            </v-text-field>
            <v-select
              v-model="phone.type"
              :items="['Mobile', 'Work', 'Home', 'Landline']"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 120px"
            />
            <v-btn
              icon="$trashCan"
              size="x-small"
              variant="text"
              color="error"
              @click="removePhone(pIdx)"
            />
          </div>
        </v-card-text>
      </v-card>

      <!-- Emails -->
      <v-divider class="my-4" />
      <div class="d-flex align-center justify-space-between mb-2">
        <div class="text-subtitle-2 font-weight-bold">Emails (Optional)</div>
        <v-btn
          size="x-small"
          variant="outlined"
          prepend-icon="$plus"
          @click="addEmail"
        >
          Add Email
        </v-btn>
      </div>

      <v-card
        v-for="(email, eIdx) in node.contactData!.emails"
        :key="eIdx"
        variant="outlined"
        class="mb-2"
      >
        <v-card-text class="pa-2">
          <div class="d-flex gap-2 align-center">
            <v-text-field
              v-model="email.email"
              label="Email Address"
              placeholder="john@example.com"
              variant="outlined"
              density="compact"
              hide-details
              class="flex-grow-1"
            >
              <template #prepend-inner>
                <v-icon icon="$email" size="small" />
              </template>
            </v-text-field>
            <v-select
              v-model="email.type"
              :items="['Work', 'Personal', 'Other']"
              variant="outlined"
              density="compact"
              hide-details
              style="max-width: 120px"
            />
            <v-btn
              icon="$trashCan"
              size="x-small"
              variant="text"
              color="error"
              @click="removeEmail(eIdx)"
            />
          </div>
        </v-card-text>
      </v-card>

      <!-- Organization Info -->
      <v-divider class="my-4" />
      <v-expansion-panels variant="accordion" class="mb-3">
        <v-expansion-panel>
          <v-expansion-panel-title>
            <v-icon icon="$domain" size="small" class="mr-2" />
            Organization Info (Optional)
          </v-expansion-panel-title>
          <v-expansion-panel-text>
            <v-text-field
              v-model="node.contactData!.org!.company"
              label="Company"
              placeholder="OneNICO"
              variant="outlined"
              density="compact"
              class="mb-2"
            />
            <v-text-field
              v-model="node.contactData!.org!.department"
              label="Department"
              placeholder="Customer Support"
              variant="outlined"
              density="compact"
              class="mb-2"
            />
            <v-text-field
              v-model="node.contactData!.org!.title"
              label="Job Title"
              placeholder="Support Manager"
              variant="outlined"
              density="compact"
            />
          </v-expansion-panel-text>
        </v-expansion-panel>
      </v-expansion-panels>

      <v-divider class="my-4" />

      <!-- Wait for Reply Toggle -->
      <VCard variant="outlined" rounded="lg" class="mb-3">
        <VCardText class="pa-3">
          <div class="d-flex align-center justify-space-between mb-1">
            <div>
              <div class="text-body-2 font-weight-medium">
                Wait for user reply
              </div>
              <div class="text-caption text-medium-emphasis">
                Pause the flow after sending contact — continue only when the
                user responds
              </div>
            </div>
            <VSwitch
              v-model="node.waitForReply"
              hide-details
              density="compact"
              color="primary"
              inset
            />
          </div>
          <VExpandTransition>
            <div v-if="node.waitForReply">
              <VDivider class="my-2" />
              <VCombobox
                v-model="node.replyVariable"
                :items="availableVariables"
                label="Save reply to variable (optional)"
                placeholder="e.g. user_contact_response"
                variant="outlined"
                density="compact"
                hide-details
                clearable
                class="mt-2"
              >
                <template #prepend-inner>
                  <VIcon icon="$variable" size="small" />
                </template>
              </VCombobox>
              <div class="text-caption text-medium-emphasis mt-1 px-1">
                The user's next message will be stored in this variable before
                the flow continues.
              </div>
            </div>
          </VExpandTransition>
        </VCardText>
      </VCard>

      <VSelect
        v-model="node.goTo"
        :label="node.waitForReply ? 'After reply, go to' : 'Then go to'"
        :items="nodeOptions.filter((o) => o.value !== node.id)"
        variant="outlined"
        density="compact"
      >
        <template #prepend-inner>
          <VIcon icon="$navigationVariant" size="small" />
        </template>
      </VSelect>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         END
    ══════════════════════════════════════════════════════════════════ -->
    <template v-else-if="node.kind === 'end'">
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
          <div class="text-caption">
            This node ends the conversation flow. The conversation will be
            marked as completed.
          </div>
        </div>
      </v-alert>
    </template>

    <!-- ══════════════════════════════════════════════════════════════════
         SHARED FOOTER — "Node Actions" configure button
    ══════════════════════════════════════════════════════════════════ -->
    <template v-if="showsNodeActionsFooter">
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
        v-if="node.kind === 'trigger' && !node.actions?.length"
        type="info"
        variant="tonal"
        density="compact"
      >
        No actions configured. Actions run when this trigger fires.
      </v-alert>
    </template>
  </div>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>
