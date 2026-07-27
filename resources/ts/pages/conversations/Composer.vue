<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from "vue";
import { useEditor, EditorContent } from "@tiptap/vue-3";
import StarterKit from "@tiptap/starter-kit";
import Placeholder from "@tiptap/extension-placeholder";
import { tiptapToWaMarkdown, isEditorEmpty } from "@/chat/waFormat";
import VoiceRecorder, { type VoiceRecordingPayload } from "./VoiceRecorder.vue";

export type AttachmentKind =
  | "image"
  | "video"
  | "document"
  | "audio"
  | "sticker";

export interface PendingAttachment {
  file: File;
  kind: AttachmentKind;
  caption: string;
  previewUrl: string | null;
}

export interface ComposerSendPayload {
  text: string;
  attachments: PendingAttachment[];
}

const emit = defineEmits<{
  send: [payload: ComposerSendPayload];
  typing: [];
  stopTyping: [];
  error: [message: string];
}>();

const isSending = ref(false);

const CAPTION_SUPPORTED: AttachmentKind[] = ["image", "video", "document"];

interface MediaTypeEntry {
  kind: AttachmentKind;
  mime: string;
  extensions: string[];
  maxBytes: number;
  label: string;
}

const MEDIA_TYPES: MediaTypeEntry[] = [
  {
    kind: "image",
    mime: "image/jpeg",
    extensions: ["jpg", "jpeg"],
    maxBytes: 5 * 1024 * 1024,
    label: "JPEG",
  },
  {
    kind: "image",
    mime: "image/png",
    extensions: ["png"],
    maxBytes: 5 * 1024 * 1024,
    label: "PNG",
  },
  {
    kind: "video",
    mime: "video/mp4",
    extensions: ["mp4"],
    maxBytes: 16 * 1024 * 1024,
    label: "MP4",
  },
  {
    kind: "video",
    mime: "video/3gpp",
    extensions: ["3gp"],
    maxBytes: 16 * 1024 * 1024,
    label: "3GP",
  },
  {
    kind: "document",
    mime: "text/plain",
    extensions: ["txt"],
    maxBytes: 100 * 1024 * 1024,
    label: "TEXT",
  },
  {
    kind: "document",
    mime: "application/vnd.ms-excel",
    extensions: ["xls"],
    maxBytes: 100 * 1024 * 1024,
    label: "EXCEL",
  },
  {
    kind: "document",
    mime: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    extensions: ["xlsx"],
    maxBytes: 100 * 1024 * 1024,
    label: "EXCEL",
  },
  {
    kind: "document",
    mime: "application/msword",
    extensions: ["doc"],
    maxBytes: 100 * 1024 * 1024,
    label: "WORD",
  },
  {
    kind: "document",
    mime: "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
    extensions: ["docx"],
    maxBytes: 100 * 1024 * 1024,
    label: "WORD",
  },
  {
    kind: "document",
    mime: "application/vnd.ms-powerpoint",
    extensions: ["ppt"],
    maxBytes: 100 * 1024 * 1024,
    label: "PPT",
  },
  {
    kind: "document",
    mime: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    extensions: ["pptx"],
    maxBytes: 100 * 1024 * 1024,
    label: "PPT",
  },
  {
    kind: "document",
    mime: "application/pdf",
    extensions: ["pdf"],
    maxBytes: 100 * 1024 * 1024,
    label: "PDF",
  },
  {
    kind: "audio",
    mime: "audio/aac",
    extensions: ["aac"],
    maxBytes: 16 * 1024 * 1024,
    label: "AAC",
  },
  {
    kind: "audio",
    mime: "audio/amr",
    extensions: ["amr"],
    maxBytes: 16 * 1024 * 1024,
    label: "AMR",
  },
  {
    kind: "audio",
    mime: "audio/mpeg",
    extensions: ["mp3"],
    maxBytes: 16 * 1024 * 1024,
    label: "MP3",
  },
  {
    kind: "audio",
    mime: "audio/mp4",
    extensions: ["m4a"],
    maxBytes: 16 * 1024 * 1024,
    label: "M4A",
  },
  {
    kind: "audio",
    mime: "audio/ogg",
    extensions: ["ogg"],
    maxBytes: 16 * 1024 * 1024,
    label: "OGG",
  },
  {
    kind: "sticker",
    mime: "image/webp",
    extensions: ["webp"],
    maxBytes: 500 * 1024,
    label: "STICKER",
  },
];

function findMediaType(file: File): MediaTypeEntry | undefined {
  const mime = file.type.toLowerCase();
  const ext = file.name.split(".").pop()?.toLowerCase() ?? "";
  return (
    MEDIA_TYPES.find((t) => t.mime === mime) ??
    MEDIA_TYPES.find((t) => t.extensions.includes(ext))
  );
}

function getFileTypeLabel(file: File): string {
  return findMediaType(file)?.label ?? "FILE";
}

function formatSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(0)}MB`;
  return `${Math.ceil(bytes / 1024)}KB`;
}

function acceptFor(kind: AttachmentKind | null): string {
  const entries = kind
    ? MEDIA_TYPES.filter((t) => t.kind === kind)
    : MEDIA_TYPES;
  const mimes = [...new Set(entries.map((t) => t.mime))];
  const exts = [
    ...new Set(entries.flatMap((t) => t.extensions.map((e) => `.${e}`))),
  ];
  return [...mimes, ...exts].join(",");
}

const attachments = ref<PendingAttachment[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);

const batchKind = computed<AttachmentKind | null>(
  () => attachments.value[0]?.kind ?? null,
);
const acceptAttr = computed(() => acceptFor(batchKind.value));
const hasCaption = computed(
  () => batchKind.value !== null && CAPTION_SUPPORTED.includes(batchKind.value),
);
const hasAttachments = computed(() => attachments.value.length > 0);

function openFilePicker() {
  fileInput.value?.click();
}

function onFilesSelected(e: Event) {
  const files = Array.from((e.target as HTMLInputElement).files ?? []);
  let lockedKind = batchKind.value;
  let mismatchCount = 0;
  let unsupportedCount = 0;
  const oversizeNames: string[] = [];

  for (const file of files) {
    const entry = findMediaType(file);

    if (!entry) {
      unsupportedCount++;
      continue;
    }
    if (lockedKind !== null && entry.kind !== lockedKind) {
      mismatchCount++;
      continue;
    }
    if (file.size > entry.maxBytes) {
      oversizeNames.push(`${file.name} (max ${formatSize(entry.maxBytes)})`);
      continue;
    }

    lockedKind = entry.kind;
    attachments.value.push({
      file,
      kind: entry.kind,
      caption: "",
      previewUrl: ["image", "video", "sticker"].includes(entry.kind)
        ? URL.createObjectURL(file)
        : null,
    });
  }

  if (mismatchCount > 0)
    emit("error", "You can only select one attachment type at a time.");
  else if (unsupportedCount > 0)
    emit(
      "error",
      unsupportedCount === 1
        ? "That file type isn't supported by WhatsApp."
        : `${unsupportedCount} files aren't supported.`,
    );
  else if (oversizeNames.length)
    emit("error", `File too large: ${oversizeNames.join(", ")}`);

  (e.target as HTMLInputElement).value = "";
}

function removeAttachment(index: number) {
  const [removed] = attachments.value.splice(index, 1);
  if (removed?.previewUrl) URL.revokeObjectURL(removed.previewUrl);
}

function revokeAllPreviews() {
  attachments.value.forEach(
    (a) => a.previewUrl && URL.revokeObjectURL(a.previewUrl),
  );
}

async function handleVoiceNote(payload: VoiceRecordingPayload): Promise<void> {
  isSending.value = true;
  try {
    // Reuse the same ComposerSendPayload shape so useChat.sendMessage handles it
    const attachment = {
      file: payload.file,
      kind: "audio" as const,
      caption: "",
      previewUrl: null,
    };

    emit("send", {
      text: "",
      attachments: [attachment],
    });
  } finally {
    isSending.value = false;
  }
}

onBeforeUnmount(revokeAllPreviews);

// ── Editor 1: main message ──────────────────────────────────────────
const editorRevision = ref(0);

const messageEditor = useEditor({
  extensions: [
    StarterKit.configure({
      heading: false,
      bulletList: false,
      orderedList: false,
      blockquote: false,
      horizontalRule: false,
    }),
    Placeholder.configure({ placeholder: "Write a message…" }),
  ],
  content: "",
  editorProps: {
    attributes: { class: "composer__editor-inner" },
    handleKeyDown(_view, event) {
      if (event.key !== "Enter") return false;
      if (event.shiftKey) {
        event.preventDefault();
        messageEditor.value?.chain().focus().setHardBreak().run();
        return true;
      }
      return false;
    },
  },
  onTransaction: () => {
    editorRevision.value++;
  },
  onUpdate: () => {
    const doc = messageEditor.value?.getJSON();
    if (doc && !isEditorEmpty(doc)) emit("typing");
    else emit("stopTyping");
  },
});

// ── Editor 2: caption (only rendered when hasCaption) ──────────────
const captionEditor = useEditor({
  extensions: [
    StarterKit.configure({
      heading: false,
      bulletList: false,
      orderedList: false,
      blockquote: false,
      horizontalRule: false,
    }),
    Placeholder.configure({ placeholder: "Add a caption…" }),
  ],
  content: "",
  editorProps: {
    attributes: { class: "composer__editor-inner" },
    handleKeyDown(_view, event) {
      if (event.key !== "Enter") return false;
      if (event.shiftKey) {
        event.preventDefault();
        captionEditor.value?.chain().focus().setHardBreak().run();
        return true;
      }
      return false;
    },
  },
});

// Clear caption editor when attachments are cleared
watch(hasAttachments, (has) => {
  if (!has) captionEditor.value?.commands.clearContent();
});

onBeforeUnmount(() => {
  messageEditor.value?.destroy();
  captionEditor.value?.destroy();
});

// ── Toolbar (shared state, acts on whichever editor is focused) ─────
interface ToolbarAction {
  key: "bold" | "italic" | "strike" | "code";
  icon: string;
  label: string;
}

const toolbarActions: ToolbarAction[] = [
  { key: "bold", icon: "$formatBold", label: "Bold" },
  { key: "italic", icon: "$formatItalic", label: "Italic" },
  {
    key: "strike",
    icon: "$formatStrikethroughVariant",
    label: "Strikethrough",
  },
  { key: "code", icon: "$codeTags", label: "Monospace" },
];

// Which editor is currently focused (message = default)
const activeEditor = ref<"message" | "caption">("message");

function runToolbarAction(key: ToolbarAction["key"]) {
  const ed =
    activeEditor.value === "caption"
      ? captionEditor.value
      : messageEditor.value;
  ed
    ?.chain()
    .focus()
    [`toggle${key.charAt(0).toUpperCase()}${key.slice(1)}` as "toggleBold"]()
    .run();
}

function isActionActive(key: ToolbarAction["key"]): boolean {
  void editorRevision.value;
  const ed =
    activeEditor.value === "caption"
      ? captionEditor.value
      : messageEditor.value;
  return ed?.isActive(key) ?? false;
}

// ── Send ────────────────────────────────────────────────────────────
const messageText = computed(() => {
  const doc = messageEditor.value?.getJSON();
  return doc && !isEditorEmpty(doc) ? tiptapToWaMarkdown(doc) : "";
});

const captionText = computed(() => {
  if (!hasCaption.value) return "";
  const doc = captionEditor.value?.getJSON();
  return doc && !isEditorEmpty(doc) ? tiptapToWaMarkdown(doc) : "";
});

const canSend = computed(
  () =>
    !isSending.value &&
    (messageText.value.trim().length > 0 || attachments.value.length > 0),
);

async function send() {
  if (!canSend.value) return;

  isSending.value = true;

  try {
    const caption = captionText.value.trim();
    const text = hasAttachments.value ? "" : messageText.value;

    emit("send", {
      // When attachments are present the message editor content is NOT sent
      // as a separate text message — only the caption goes on the media.
      text,
      attachments: attachments.value.map((a, i) => ({
        ...a,
        // Put the caption only on the last attachment (WhatsApp convention)
        caption:
          hasCaption.value && i === attachments.value.length - 1 ? caption : "",
      })),
    });

    messageEditor.value?.commands.clearContent();
    captionEditor.value?.commands.clearContent();
    revokeAllPreviews();
    attachments.value = [];
    emit("stopTyping");
  } finally {
    isSending.value = false;
  }
}

defineExpose({ focus: () => messageEditor.value?.commands.focus() });
</script>

<template>
  <div class="composer">
    <!-- Attachment strip -->
    <div v-if="attachments.length" class="composer__attachments">
      <div v-for="(a, i) in attachments" :key="i" class="attachment-chip">
        <img
          v-if="a.previewUrl"
          :src="a.previewUrl"
          class="attachment-chip__preview"
          alt="Attachment preview"
        />
        <div v-else class="attachment-chip__icon" :title="a.file.name">
          {{ getFileTypeLabel(a.file) }}
        </div>
        <VBtn
          icon
          size="x-small"
          variant="text"
          aria-label="Remove attachment"
          class="attachment-chip__remove"
          @click="removeAttachment(i)"
        >
          <VIcon icon="$close" size="16" />
        </VBtn>
      </div>
    </div>

    <!-- Main input card -->
    <div class="composer__card">
      <!-- Caption editor — only shown when attachments support captions -->
      <div
        v-if="hasAttachments && hasCaption"
        class="composer__section composer__section--caption"
      >
        <span class="composer__section-label">Caption</span>
        <EditorContent
          :editor="captionEditor"
          class="composer__editor"
          @focus="activeEditor = 'caption'"
        />
      </div>

      <!-- No-caption notice -->
      <div
        v-else-if="hasAttachments && !hasCaption"
        class="composer__no-caption"
      >
        Captions aren't supported for {{ batchKind }} messages
      </div>

      <!-- Message editor — hidden when caption editor is visible -->
      <div
        v-if="!hasAttachments || !hasCaption"
        class="composer__section"
        :class="{
          'composer__section--bordered': hasAttachments && !hasCaption,
        }"
      >
        <EditorContent
          :editor="messageEditor"
          class="composer__editor"
          @focus="activeEditor = 'message'"
        />
      </div>

      <!-- Toolbar -->
      <div class="composer__toolbar">
        <div class="composer__toolbar-left">
          <button
            v-for="action in toolbarActions"
            :key="action.key"
            type="button"
            class="composer__fmt-btn"
            :class="{ 'composer__fmt-btn--active': isActionActive(action.key) }"
            :title="action.label"
            @click="runToolbarAction(action.key)"
          >
            <VIcon :icon="action.icon" size="16" />
          </button>
        </div>

        <div class="composer__toolbar-right">
          <VoiceRecorder
            @send="handleVoiceNote"
            @error="emit('error', $event)"
          />

          <button
            type="button"
            class="composer__fmt-btn"
            title="Attach file"
            @click="openFilePicker"
          >
            <VIcon icon="$linkVariant" size="24" />
          </button>
          <input
            ref="fileInput"
            type="file"
            multiple
            hidden
            :accept="acceptAttr"
            @change="onFilesSelected"
          />

          <VBtn
            :loading="isSending"
            class="composer__send-btn"
            :disabled="!canSend"
            aria-label="Send message"
            @click="send"
            size="35"
            icon="$sendVariant"
          >
          </VBtn>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.composer {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px 0 10px;
  background: var(--color-sidebar-bg, #fdfcfc);
  border-top: 1px solid var(--color-border, #eee);
}

/* ── Attachment strip ──────────────────────────────────────────── */
.composer__attachments {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.attachment-chip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px;
  border: 1px solid var(--color-border, #eee);
  border-radius: 12px;
  background: #fff;
  flex-shrink: 0;
}
.attachment-chip__preview {
  width: 36px;
  height: 36px;
  object-fit: cover;
  border-radius: 6px;
  display: block;
}
.attachment-chip__icon {
  min-width: 56px;
  width: max-content;
  height: 36px;
  padding: 0 8px;
  border-radius: 6px;
  background: #eee;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: var(--color-text-secondary, #abb1ae);
  white-space: nowrap;
}
.attachment-chip__remove {
  color: var(--color-text-secondary, #abb1ae);
}

/* ── Card ──────────────────────────────────────────────────────── */
.composer__card {
  display: flex;
  flex-direction: column;
  background: #fff;
  border: 1px solid var(--color-border, #eee);
  border-radius: 18px;
  overflow: hidden;
  min-width: 0;
  width: 100%;
}

/* ── Editor sections ───────────────────────────────────────────── */
.composer__section {
  display: flex;
  flex-direction: column;
}
.composer__section--bordered {
  border-top: 1px solid var(--color-border, #eee);
}
.composer__section--caption {
  background: rgba(var(--v-theme-primary), 0.03);
}
.composer__section-label {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--color-text-tertiary, #b8c0bb);
  padding: 6px 14px 0;
}

.composer__no-caption {
  padding: 8px 14px;
  font-size: 12px;
  color: var(--color-text-tertiary, #b8c0bb);
  font-style: italic;
  border-bottom: 1px solid var(--color-border, #eee);
}

.composer__editor {
  padding: 8px 14px 6px;
  min-height: 36px;
  max-height: 120px;
  overflow-y: auto;
  overflow-x: hidden;
  min-width: 0;
  width: 100%;
}
:deep(.composer__editor-inner) {
  outline: none;
  font-size: 14px;
  line-height: 1.45;
  color: var(--color-text-primary, #16191c);
  word-break: break-word;
  overflow-wrap: break-word;
  white-space: pre-wrap;
  width: 100%;
  min-width: 0;
}
:deep(.composer__editor-inner p) {
  margin: 0;
  word-break: break-word;
  overflow-wrap: break-word;
  white-space: pre-wrap;
}
:deep(.composer__editor-inner p + p) {
  margin-top: 6px;
}
:deep(.composer__editor-inner p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  color: var(--color-text-tertiary, #b8c0bb);
  float: left;
  height: 0;
  pointer-events: none;
}
:deep(.composer__editor-inner code) {
  background: rgba(0, 0, 0, 0.06);
  padding: 1px 4px;
  border-radius: 4px;
  font-family: "SFMono-Regular", Consolas, monospace;
  font-size: 0.92em;
}

/* ── Toolbar ───────────────────────────────────────────────────── */
.composer__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 4px 8px 6px;
  border-top: 1px solid var(--color-border, #eee);
}
.composer__toolbar-left,
.composer__toolbar-right {
  display: flex;
  align-items: center;
  gap: 2px;
}
.composer__fmt-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--color-text-secondary, #abb1ae);
  cursor: pointer;
  transition:
    background 0.12s,
    color 0.12s;
  padding: 0;
  font-family: inherit;
}
.composer__fmt-btn:hover {
  background: rgba(0, 0, 0, 0.05);
  color: var(--color-text-primary, #16191c);
}
.composer__fmt-btn--active {
  color: rgba(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.1);
}
.composer__send-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: none;
  border-radius: 50%;
  background: var(--color-text-primary, #16191c);
  color: #fff;
  cursor: pointer;
  padding: 0;
  margin-left: 4px;
  transition: opacity 0.12s;
  font-family: inherit;
}
.composer__send-btn:disabled {
  opacity: 0.35;
  cursor: default;
}
.composer__send-btn:not(:disabled):hover {
  opacity: 0.85;
}
</style>
