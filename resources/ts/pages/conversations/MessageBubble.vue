<script setup lang="ts">
import { computed } from "vue";
import {
  interactiveCopy,
  interactiveKind,
  interactiveOptions,
  listButtonLabel,
  previewIconLabel,
  previewText,
  type ChatMessage,
  type MediaContent,
  type LocationContent,
  type ContactsContent,
} from "@/chat/chat";

const props = defineProps<{
  message: ChatMessage;
}>();

const emit = defineEmits<{
  /** Agent clicked "reply" on this bubble — composer should quote it. */
  reply: [message: ChatMessage];
  /** Agent clicked an interactive (list/button) bubble — open the read-only dialog. */
  "open-interactive": [message: ChatMessage];
}>();

const isOutbound = computed(() => props.message.direction === "outbound");

const isInteractiveOutbound = computed(() => {
  if (props.message.message_type !== "interactive") return false;
  return (
    interactiveOptions(props.message.content, props.message.message_type) !==
    null
  );
});

// "list" → rich card (header/body/footer + divider + single centered row
// that opens the picker). "button" → copy block + each real button as its
// own full-width row. null → not an outbound interactive message at all
// (e.g. the inbound "user tapped X" shape).
const interactiveKindValue = computed(() =>
  interactiveKind(props.message.content, props.message.message_type),
);

const listCardCopy = computed(() =>
  interactiveCopy(props.message.content, props.message.message_type),
);

const listCardButtonLabel = computed(() =>
  listButtonLabel(props.message.content, props.message.message_type),
);

// For button-type interactive messages: the header/body copy shown above
// the buttons, and the flat list of buttons themselves (each rendered as
// its own full-width row, exactly like WhatsApp's own button-message UI).
const buttonCardCopy = computed(() =>
  interactiveCopy(props.message.content, props.message.message_type),
);
const buttonOptions = computed(() => {
  if (
    props.message.message_type !== "interactive" ||
    interactiveKindValue.value !== "button"
  )
    return [];
  return (
    interactiveOptions(props.message.content, props.message.message_type) ?? []
  );
});

const quotedPreview = computed(() => {
  const quoted = props.message.quoted_message;
  if (!quoted) return null;
  return {
    icon: previewIconLabel(quoted.message_type),
    text: previewText(quoted.content, quoted.message_type),
  };
});

const statusGlyph = computed(() => {
  // Single check = sent/delivered, matches the reference. Read state
  // could later swap this to a filled/colored variant if desired.
  if (!isOutbound.value) return null;
  return props.message.status === "failed" ? "!" : "check";
});

// ─────────────────────────────────────────────────────────────────────────
// Media field extraction — matches the FLAT MediaContent shape from
// chat.ts: { caption?, url?, mime_type?, sha256?, filename?, file_size? }.
// There is no nested content.image / content.document / content.sticker —
// every image/video/audio/document/sticker message uses this same shape.
// ─────────────────────────────────────────────────────────────────────────

const textContent = computed(() =>
  previewText(props.message.content, props.message.message_type),
);

function mediaUrl(content: unknown): string | null {
  return (content as MediaContent)?.url ?? null;
}

function mediaCaption(content: unknown): string | null {
  return (content as MediaContent)?.caption ?? null;
}

function formatBytes(bytes?: number | null): string | null {
  if (bytes === null || bytes === undefined || Number.isNaN(bytes)) return null;
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(bytes < 10 * 1024 * 1024 ? 1 : 0)} MB`;
}

// filename isn't guaranteed on MediaContent — fall back to the last path
// segment of the URL when the backend didn't send one.
function filenameFromUrl(url: string | null): string {
  if (!url) return "Document";
  try {
    const clean = url.split("?")[0].split("#")[0];
    const last = clean.substring(clean.lastIndexOf("/") + 1);
    return decodeURIComponent(last) || "Document";
  } catch {
    return "Document";
  }
}

const mimeExtensionMap: Record<string, string> = {
  "application/pdf": "pdf",
  "application/msword": "doc",
  "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
    "docx",
  "application/vnd.ms-excel": "xls",
  "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet": "xlsx",
  "application/vnd.ms-powerpoint": "ppt",
  "application/vnd.openxmlformats-officedocument.presentationml.presentation":
    "pptx",
  "application/zip": "zip",
  "text/plain": "txt",
};

const docExtensionStyle: Record<string, { bg: string; label: string }> = {
  pdf: { bg: "#e0524d", label: "PDF" },
  doc: { bg: "#3b82f6", label: "DOC" },
  docx: { bg: "#3b82f6", label: "DOC" },
  xls: { bg: "#1fa855", label: "XLS" },
  xlsx: { bg: "#1fa855", label: "XLS" },
  ppt: { bg: "#e2762b", label: "PPT" },
  pptx: { bg: "#e2762b", label: "PPT" },
  zip: { bg: "#6b7280", label: "ZIP" },
  txt: { bg: "#6b7280", label: "TXT" },
};

const documentMeta = computed(() => {
  const content = props.message.content as MediaContent;
  const url = mediaUrl(content);
  const filename = content?.filename ?? filenameFromUrl(url);
  const ext = filename.includes(".")
    ? (filename.split(".").pop()?.toLowerCase() ?? "")
    : (mimeExtensionMap[content?.mime_type ?? ""] ?? "");
  const style = docExtensionStyle[ext] ?? { bg: "#6b7280", label: "FILE" };
  const size = formatBytes(content?.file_size);
  return {
    filename,
    url,
    caption: mediaCaption(content),
    style,
    subtitle: [style.label, size].filter(Boolean).join(" · "),
  };
});

const locationMeta = computed(() => {
  const loc = props.message.content as LocationContent;
  const lat = loc?.latitude;
  const lng = loc?.longitude;
  return {
    lat,
    lng,
    name: loc?.name ?? null,
    address: loc?.address ?? null,
    mapUrl:
      lat && lng
        ? `https://staticmap.openstreetmap.de/staticmap.php?center=${lat},${lng}&zoom=15&size=290x140&maptype=mapnik&markers=${lat},${lng},red-pushpin`
        : null,
  };
});

const contactMeta = computed(() => {
  const content = props.message.content as ContactsContent;
  const c = content?.contacts?.[0];
  const name: string = c?.name?.formatted_name ?? "Contact";
  const phone: string | null = c?.phones?.[0]?.phone ?? null;
  return { name, phone, initials: getInitials(name) };
});

function getInitials(name: string | null | undefined): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
  return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}

// ─────────────────────────────────────────────────────────────────────────
// Emoji-only detection — a text message made up only of 1-3 emoji (no other
// characters) renders jumbo-sized with no bubble background, matching
// WhatsApp's own behavior. More than 3, or emoji mixed with other text,
// falls back to a normal text bubble.
// ─────────────────────────────────────────────────────────────────────────

function isEmojiOnly(text: string): boolean {
  const trimmed = text.trim();
  if (!trimmed) return false;
  const onlyEmojiAndModifiers =
    /^(?:\p{Extended_Pictographic}|\u200d|\ufe0f|\s)+$/u.test(trimmed);
  if (!onlyEmojiAndModifiers) return false;
  const count = (trimmed.match(/\p{Extended_Pictographic}/gu) ?? []).length;
  return count > 0 && count <= 3;
}

const isEmojiOnlyMessage = computed(
  () => props.message.message_type === "text" && isEmojiOnly(textContent.value),
);

function handleBubbleClick() {
  if (isInteractiveOutbound.value) emit("open-interactive", props.message);
}

function handleReplyClick(event: MouseEvent) {
  event.stopPropagation();
  emit("reply", props.message);
}

function handleMapError(event: Event) {
  (event.target as HTMLImageElement).style.display = "none";
}

function formatTime(iso: string): string {
  return new Date(iso)
    .toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })
    .toLowerCase();
}
</script>

<template>
  <div class="row" :class="isOutbound ? 'row--out' : 'row--in'">
    <div class="stack" :class="isOutbound ? 'stack--out' : 'stack--in'">
      <!-- Quoted reply strip -->
      <div
        v-if="quotedPreview"
        class="quote"
        :class="isOutbound ? 'quote--out' : 'quote--in'"
      >
        <span class="quote__bar" />
        <span class="quote__text">{{
          quotedPreview.icon ?? quotedPreview.text
        }}</span>
      </div>

      <div
        class="bubble-row"
        :class="isOutbound ? 'bubble-row--out' : 'bubble-row--in'"
      >
        <div class="bubble-wrap">
          <!-- Jumbo emoji — no bubble background at all -->
          <div
            v-if="isEmojiOnlyMessage"
            class="bubble bubble--emoji"
            :class="isOutbound ? 'bubble--out-emoji' : 'bubble--in-emoji'"
          >
            {{ textContent }}
          </div>

          <!-- Plain text bubble -->
          <div
            v-else-if="message.message_type === 'text'"
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            {{ textContent }}
            <span
              v-if="statusGlyph"
              class="bubble__check"
              :class="{ 'bubble__check--failed': statusGlyph === '!' }"
            >
              <svg
                v-if="statusGlyph === 'check'"
                width="11"
                height="11"
                viewBox="0 0 24 24"
                fill="none"
              >
                <path
                  d="M5 13l4 4L19 7"
                  stroke="currentColor"
                  stroke-width="2.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
              <span v-else>!</span>
            </span>
          </div>

          <!-- Sticker — transparent, no bubble, no caption/time overlay on
               the image itself (time still shows in the meta row below). -->
          <div
            v-else-if="message.message_type === 'sticker'"
            class="bubble bubble--sticker"
            @click="handleBubbleClick"
          >
            <img
              :src="mediaUrl(message.content) ?? ''"
              alt="Sticker"
              class="sticker__img"
            />
          </div>

          <!-- Image (and video, sharing the same card shape) -->
          <div
            v-else-if="
              message.message_type === 'image' ||
              message.message_type === 'video'
            "
            class="bubble bubble--media"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <div class="media__frame">
              <img
                :src="mediaUrl(message.content) ?? ''"
                alt="Attachment"
                class="media__img"
              />
              <button
                v-if="message.message_type === 'video'"
                type="button"
                class="media__play"
                aria-label="Play video"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                  <path d="M6 4.5v15l13-7.5-13-7.5z" fill="currentColor" />
                </svg>
              </button>
              <span
                v-if="!mediaCaption(message.content) && statusGlyph"
                class="media__time-overlay"
              >
                {{ formatTime(message.created_at) }}
                <svg
                  v-if="statusGlyph === 'check'"
                  width="11"
                  height="11"
                  viewBox="0 0 24 24"
                  fill="none"
                >
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </div>
            <div
              v-if="mediaCaption(message.content)"
              class="media__caption-row"
            >
              <span class="media__caption-text">{{
                mediaCaption(message.content)
              }}</span>
              <span class="media__caption-time">
                {{ formatTime(message.created_at) }}
                <svg
                  v-if="statusGlyph === 'check'"
                  width="10"
                  height="10"
                  viewBox="0 0 24 24"
                  fill="none"
                >
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </div>
          </div>

          <!-- Document -->
          <div
            v-else-if="message.message_type === 'document'"
            class="bubble bubble--document"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
            @click="handleBubbleClick"
          >
            <div class="document__row">
              <span
                class="document__icon"
                :style="{ background: documentMeta.style.bg }"
              >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                  <path
                    d="M6 2h9l5 5v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2z"
                    stroke="white"
                    stroke-width="1.6"
                    fill="none"
                  />
                  <path
                    d="M15 2v5h5"
                    stroke="white"
                    stroke-width="1.6"
                    fill="none"
                  />
                </svg>
              </span>
              <div class="document__meta">
                <p class="document__filename">{{ documentMeta.filename }}</p>
                <p class="document__subtitle">{{ documentMeta.subtitle }}</p>
              </div>
            </div>
            <div v-if="documentMeta.caption" class="media__caption-row">
              <span class="media__caption-text">{{
                documentMeta.caption
              }}</span>
              <span class="media__caption-time">
                {{ formatTime(message.created_at) }}
                <svg
                  v-if="statusGlyph === 'check'"
                  width="10"
                  height="10"
                  viewBox="0 0 24 24"
                  fill="none"
                >
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </div>
          </div>

          <!-- Location -->
          <div
            v-else-if="message.message_type === 'location'"
            class="bubble bubble--media"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <div class="media__frame media__frame--map">
              <img
                v-if="locationMeta.mapUrl"
                :src="locationMeta.mapUrl"
                alt="Map preview"
                class="media__img"
                @error="handleMapError"
              />
              <div class="location__pin">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                  <path
                    d="M12 2C7.6 2 4 5.6 4 10c0 6 8 12 8 12s8-6 8-12c0-4.4-3.6-8-8-8z"
                    fill="#e0524d"
                  />
                  <circle cx="12" cy="10" r="3" fill="white" />
                </svg>
              </div>
            </div>
            <div class="media__caption-row">
              <span class="location__label">{{
                locationMeta.name ?? locationMeta.address ?? "Location"
              }}</span>
              <span class="media__caption-time">
                {{ formatTime(message.created_at) }}
                <svg
                  v-if="statusGlyph === 'check'"
                  width="10"
                  height="10"
                  viewBox="0 0 24 24"
                  fill="none"
                >
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </div>
          </div>

          <!-- Contact card -->
          <div
            v-else-if="message.message_type === 'contacts'"
            class="bubble bubble--contact"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
            @click="handleBubbleClick"
          >
            <span class="contact__avatar">{{ contactMeta.initials }}</span>
            <div class="contact__meta">
              <p class="contact__name">{{ contactMeta.name }}</p>
              <p v-if="contactMeta.phone" class="contact__phone">
                {{ contactMeta.phone }}
              </p>
            </div>
          </div>

          <!-- Interactive LIST message — rich card: header/body/footer text,
               a divider, then the single centered row showing the list's
               action.button label. Tapping opens the read-only options dialog;
               this never shows the individual rows directly in the bubble. -->
          <button
            v-else-if="
              message.message_type === 'interactive' &&
              interactiveKindValue === 'list'
            "
            type="button"
            class="bubble bubble--list"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
            @click="handleBubbleClick"
          >
            <div class="list-card__copy">
              <p v-if="listCardCopy.header" class="list-card__header">
                {{ listCardCopy.header }}
              </p>
              <p v-if="listCardCopy.body" class="list-card__body">
                {{ listCardCopy.body }}
              </p>
              <p v-if="listCardCopy.footer" class="list-card__footer">
                {{ listCardCopy.footer }}
              </p>
            </div>
            <div class="list-card__divider" />
            <div class="list-card__action">
              <svg
                width="16"
                height="16"
                viewBox="0 0 24 24"
                fill="none"
                class="list-card__action-icon text-info"
              >
                <path
                  d="M8 6h13M8 12h13M8 18h13"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
                <circle cx="3.5" cy="6" r="1.4" fill="currentColor" />
                <circle cx="3.5" cy="12" r="1.4" fill="currentColor" />
                <circle cx="3.5" cy="18" r="1.4" fill="currentColor" />
              </svg>
              <span class="list-card__action-label text-info">{{
                listCardButtonLabel
              }}</span>
            </div>
          </button>

          <!-- Interactive BUTTON message — copy block, then each actual
               button rendered as its own full-width centered row with a
               divider above it, matching WhatsApp's own button-message UI.
               Clicking any row (or the bubble) only opens the read-only
               options dialog — it never simulates tapping that button. -->
          <div
            v-else-if="
              message.message_type === 'interactive' &&
              interactiveKindValue === 'button'
            "
            class="bubble bubble--buttons"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <button
              type="button"
              class="buttons-card__copy"
              @click="handleBubbleClick"
            >
              <p v-if="buttonCardCopy.header" class="buttons-card__header">
                {{ buttonCardCopy.header }}
              </p>
              <p v-if="buttonCardCopy.body" class="buttons-card__body">
                {{ buttonCardCopy.body }}
              </p>
              <span class="buttons-card__time">{{
                formatTime(message.created_at)
              }}</span>
            </button>

            <button
              v-for="option in buttonOptions"
              :key="option.id"
              type="button"
              class="buttons-card__row text-info"
            >
              {{ option.title }}
            </button>
          </div>

          <!-- Inbound "user tapped a row/button" bubble -->
          <div
            v-else-if="
              message.message_type === 'interactive' ||
              message.message_type === 'button'
            "
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            {{ previewText(message.content, message.message_type) }}
          </div>

          <!-- Voice note bubble -->
          <div
            v-else-if="message.message_type === 'audio'"
            class="bubble bubble--audio"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <button class="audio__play" aria-label="Play voice message">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                <path d="M6 4.5v15l13-7.5-13-7.5z" fill="currentColor" />
              </svg>
            </button>
            <span class="audio__duration">{{
              previewText(message.content, message.message_type)
            }}</span>
          </div>

          <!-- Fallback for any other/unknown message type -->
          <div
            v-else
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            {{ previewText(message.content, message.message_type) }}
          </div>
        </div>
      </div>

      <!-- Meta row: time pinned to the outer edge (right for outbound, left
           for inbound), reply button always centered between the two edges. -->
      <div
        class="row__meta"
        :class="isOutbound ? 'row__meta--out' : 'row__meta--in'"
      >
        <span class="row__meta-edge row__meta-edge--left">
          <span v-if="!isOutbound" class="row__time">{{
            formatTime(message.created_at)
          }}</span>
        </span>

        <button class="row__reply" type="button" @click="handleReplyClick">
          <VIcon size="small">$replyOutline</VIcon>
          Reply
        </button>

        <span class="row__meta-edge row__meta-edge--right">
          <span v-if="isOutbound" class="row__time">{{
            formatTime(message.created_at)
          }}</span>
        </span>
      </div>
    </div>
  </div>
</template>

<style scoped>
.row {
  display: flex;
  margin-bottom: 22px;
}
.row--out {
  justify-content: flex-end;
}
.row--in {
  justify-content: flex-start;
}

.stack {
  display: flex;
  flex-direction: column;
  max-width: 72%;
  width: 100%;
}
.stack--out {
  align-items: flex-end;
}
.stack--in {
  align-items: flex-start;
}

.bubble-row {
  display: flex;
  align-items: flex-end;
  gap: 10px;
  width: 100%;
}
.bubble-row--out {
  justify-content: flex-end;
}

.bubble-wrap {
  position: relative;
  max-width: 100%;
}

.quote {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #6b7a73;
  margin-bottom: 4px;
  padding: 0 4px;
  max-width: 100%;
}
.quote__bar {
  width: 2px;
  align-self: stretch;
  background: #9fb0a8;
  border-radius: 2px;
  flex-shrink: 0;
}
.quote__text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Text bubble */
.bubble--text {
  position: relative;
  padding: 5px 10px;
  border-radius: 12px;
  max-width: 400px;
  font-size: 14px;
  line-height: 1.45;
}

.bubble--out {
  background: #ffffff;
  color: #2a2d30;
  padding-right: 30px;
}

.bubble--in {
  background: #cdd1d8;
  border-bottom-left-radius: 6px;
}

.bubble__check {
  position: absolute;
  right: -6px;
  bottom: -6px;
  width: 20px;
  height: 20px;
  border-radius: 50%;
  background: #ffffff;
  color: #18c97a;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
}
.bubble__check--failed {
  color: #e0524d;
  font-size: 12px;
  font-weight: 700;
}

/* Jumbo emoji — no bubble at all */
.bubble--emoji {
  font-size: 44px;
  line-height: 1.15;
  padding: 2px 4px;
  background: transparent;
}
.bubble--out-emoji {
  text-align: right;
}
.bubble--in-emoji {
  text-align: left;
}

/* Sticker — transparent, image only */
.bubble--sticker {
  padding: 0;
  background: transparent;
  cursor: pointer;
}
.sticker__img {
  width: 130px;
  height: 130px;
  object-fit: contain;
  display: block;
}

/* Image / video / document / location — shared "media card" shell */
.bubble--media {
  width: 260px;
  max-width: 100%;
  padding: 0;
  border-radius: 14px;
  overflow: hidden;
}
.bubble--media.bubble--in {
  border-bottom-left-radius: 6px;
}

.media__frame {
  position: relative;
  width: 100%;
  aspect-ratio: 4 / 3;
  background: #d9dbde;
}
.media__frame--map {
  background: #cfd6d3;
}
.media__img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.media__play {
  position: absolute;
  inset: 0;
  margin: auto;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  border: none;
  background: rgba(0, 0, 0, 0.45);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.media__time-overlay {
  position: absolute;
  right: 8px;
  bottom: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  border-radius: 10px;
  background: rgba(0, 0, 0, 0.45);
  color: #fff;
  font-size: 11px;
}
.location__pin {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.media__caption-row {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 12px;
}
.media__caption-text {
  font-size: 14px;
  line-height: 1.4;
}
.media__caption-time {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  opacity: 0.6;
  flex-shrink: 0;
  white-space: nowrap;
}
.location__label {
  font-size: 14px;
  font-weight: 600;
  color: #1fa855;
}

/* Document card */
.bubble--document {
  width: 260px;
  max-width: 100%;
  padding: 0;
  border-radius: 14px;
  overflow: hidden;
  cursor: pointer;
}
.bubble--document.bubble--in {
  border-bottom-left-radius: 6px;
}
.document__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
}
.document__icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.document__meta {
  min-width: 0;
}
.document__filename {
  font-size: 13.5px;
  font-weight: 600;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.document__subtitle {
  font-size: 12px;
  opacity: 0.6;
  margin: 2px 0 0;
}

/* Contact card */
.bubble--contact {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 240px;
  max-width: 100%;
  padding: 12px;
  border-radius: 14px;
  cursor: pointer;
}
.bubble--contact.bubble--in {
  border-bottom-left-radius: 6px;
}
.contact__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: rgba(var(--v-theme-primary));
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  font-weight: 600;
  flex-shrink: 0;
}
.contact__meta {
  min-width: 0;
}
.contact__name {
  font-size: 14px;
  font-weight: 600;
  margin: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.contact__phone {
  font-size: 12px;
  opacity: 0.6;
  margin: 2px 0 0;
}

/* Interactive BUTTON message — copy block on top, then each button as its
   own full-width centered row with a divider above it, matching WhatsApp's
   own button-message layout (no individual rounded corners on the rows;
   only the outer bubble carries the radius). */
.bubble--buttons {
  display: flex;
  flex-direction: column;
  width: 290px;
  max-width: 100%;
  padding: 0;
  border-radius: 22px;
  overflow: hidden;
}
.bubble--buttons.bubble--in {
  border-bottom-left-radius: 6px;
}

.buttons-card__copy {
  position: relative;
  display: block;
  width: 100%;
  padding: 14px 18px 30px;
  border: none;
  background: transparent;
  color: inherit;
  text-align: left;
  font-family: inherit;
  cursor: pointer;
}
.buttons-card__header {
  font-size: 14.5px;
  line-height: 1.4;
  margin: 0 0 10px;
}
.buttons-card__body {
  font-size: 14.5px;
  line-height: 1.45;
  margin: 0;
  opacity: 0.92;
}
.buttons-card__time {
  position: absolute;
  right: 14px;
  bottom: 10px;
  font-size: 11.5px;
  opacity: 0.55;
}

.buttons-card__row {
  display: block;
  width: 100%;
  padding: 13px 16px;
  border: none;
  background: transparent;
  font-family: inherit;
  font-size: 14.5px;
  font-weight: 600;
  text-align: center;
  cursor: pointer;
}
.bubble--buttons.bubble--in .buttons-card__row {
  border-top: 1px solid rgba(255, 255, 255, 0.12);
}
.bubble--buttons.bubble--out .buttons-card__row {
  border-top: 1px solid rgba(22, 25, 28, 0.1);
}

/* Interactive LIST message — rich card, modeled on WhatsApp's own list-message
   layout: stacked header/body/footer copy, a divider, then a single centered
   row showing the list's action-button label (NOT the individual rows). */
.bubble--list {
  display: flex;
  flex-direction: column;
  width: 290px;
  max-width: 100%;
  padding: 0;
  border-radius: 22px;
  border: none;
  text-align: left;
  font-family: inherit;
  cursor: pointer;
  overflow: hidden;
}
.bubble--list.bubble--in {
  border-bottom-left-radius: 6px;
}

.list-card__copy {
  padding: 16px 18px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.list-card__header {
  font-size: 15.5px;
  font-weight: 700;
  line-height: 1.4;
  margin: 0;
}
.list-card__body {
  font-size: 14.5px;
  line-height: 1.45;
  margin: 0;
  opacity: 0.92;
}
.list-card__footer {
  font-size: 12.5px;
  line-height: 1.4;
  margin: 0;
  opacity: 0.6;
}

.list-card__divider {
  height: 1px;
  margin: 0 18px;
}
.bubble--list.bubble--in .list-card__divider {
  background: rgba(255, 255, 255, 0.12);
}
.bubble--list.bubble--out .list-card__divider {
  background: rgba(22, 25, 28, 0.1);
}

.list-card__action {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 13px 18px;
  font-size: 14.5px;
  font-weight: 600;
}

.list-card__action-icon {
  flex-shrink: 0;
}

/* Audio bubble */
.bubble--audio {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 18px;
  border-radius: 22px;
  min-width: 200px;
}
.bubble--audio.bubble--in {
  border-bottom-left-radius: 6px;
}
.audio__play {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.15);
  color: inherit;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  padding: 0;
  cursor: pointer;
}
.bubble--audio.bubble--out .audio__play {
  background: #16191c;
  color: #fff;
}
.audio__duration {
  font-size: 13px;
}

.row__meta {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  align-items: center;
  width: 100%;
  margin-top: 6px;
  padding: 0 4px;
}
.row__meta-edge {
  display: flex;
  align-items: center;
  min-width: 0;
}
.row__meta-edge--left {
  justify-content: flex-start;
}
.row__meta-edge--right {
  justify-content: flex-end;
}
.row__time {
  font-size: 12.5px;
  color: #97a39d;
  white-space: nowrap;
}
.row__reply {
  justify-self: center;
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  color: #97a39d;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  opacity: 0;
  transition: opacity 0.15s ease;
  white-space: nowrap;
}
.row:hover .row__reply {
  opacity: 1;
}

@media (max-width: 640px) {
  .stack {
    max-width: 86%;
  }
}
</style>
