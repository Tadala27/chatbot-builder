<script setup lang="ts">
import { computed, ref } from "vue";
import MessageMeta from "./MessageMeta.vue";
import AudioPlayer from "./AudioPlayer.vue";
import VideoPlayer from "./VideoPlayer.vue";
import VueEasyLightbox from "vue-easy-lightbox";
import { waMarkdownToHtml } from "@/chat/waFormat";
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
  highlighted?: boolean;
}>();

const emit = defineEmits<{
  reply: [message: ChatMessage];
  "open-interactive": [message: ChatMessage];
  "jump-to-message": [messageId: string];
}>();

const isOutbound = computed(() => props.message.direction === "outbound");

const isInteractiveOutbound = computed(() => {
  if (props.message.message_type !== "interactive") return false;
  return (
    interactiveOptions(props.message.content, props.message.message_type) !==
    null
  );
});

const interactiveKindValue = computed(() =>
  interactiveKind(props.message.content, props.message.message_type),
);

const listCardCopy = computed(() =>
  interactiveCopy(props.message.content, props.message.message_type),
);

const listCardButtonLabel = computed(() =>
  listButtonLabel(props.message.content, props.message.message_type),
);

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
  const isQuotedOutbound = quoted.direction === "outbound";
  const sender =
    (quoted as any).sender_name ?? (isQuotedOutbound ? "Bot" : "Contact");
  return {
    sender,
    icon: previewIconLabel(quoted.message_type),
    text: previewText(quoted.content, quoted.message_type),
  };
});

const textContent = computed(() =>
  previewText(props.message.content, props.message.message_type),
);

const formattedTextHtml = computed(() => waMarkdownToHtml(textContent.value));

function mediaUrl(message: unknown): string | null {
  const m = message as ChatMessage;
  // media_url is set by MessageResource and is already a clean signed URL.
  // Fall back to content.url for messages that arrived via broadcast
  // before the resource was in place.
  if (m.media_url) return m.media_url;
  const c = m.content as Record<string, unknown>;
  return (c?.url as string | null) ?? null;
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
  const url = mediaUrl(props.message);
  const filename = content?.filename ?? filenameFromUrl(url);
  const ext = filename.includes(".")
    ? (filename.split(".").pop()?.toLowerCase() ?? "")
    : (mimeExtensionMap[content?.mime_type ?? ""] ?? "");
  const style = docExtensionStyle[ext] ?? { bg: "#6b7280", label: "FILE" };
  const size = formatBytes(content?.file_size);
  return {
    filename,
    url,
    ext,
    caption: mediaCaption(content),
    style,
    subtitle: [style.label, size].filter(Boolean).join(" · "),
  };
});

function openDocument() {
  const { url, filename, ext } = documentMeta.value;
  if (!url) return;
  if (ext === "pdf") {
    window.open(url, "_blank", "noopener,noreferrer");
    return;
  }
  const link = document.createElement("a");
  link.href = url;
  link.download = filename;
  link.rel = "noopener";
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

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

// ── Audio ─────────────────────────────────────────────────────────
const audioSrc = computed<string | null>(() => {
  if (props.message.message_type !== "audio") return null;
  return mediaUrl(props.message);
});

const audioDuration = computed<number | null>(() => {
  const c = props.message.content as Record<string, unknown>;
  const d = c?.duration ?? c?.duration_seconds;
  if (typeof d === "number" && d > 0 && d < 3600) return d;
  return null;
});

// ── Image lightbox ────────────────────────────────────────────────
const lightboxVisible = ref(false);
const lightboxImgs = computed(() => {
  const url = mediaUrl(props.message);
  return url ? [url] : [];
});
function openLightbox() {
  if (props.message.message_type !== "image") return;
  if (!lightboxImgs.value.length) return;
  lightboxVisible.value = true;
}

// ── Inline video playback ─────────────────────────────────────────
// Video playback handled by VideoPlayer component

function handleBubbleClick() {
  if (isInteractiveOutbound.value) emit("open-interactive", props.message);
}

function handleReplyClick(event: MouseEvent) {
  event.stopPropagation();
  emit("reply", props.message);
}

function handleQuoteClick() {
  const targetId = props.message.quoted_message?.id;
  if (targetId) emit("jump-to-message", targetId);
}

function handleMapError(event: Event) {
  (event.target as HTMLImageElement).style.display = "none";
}
</script>

<template>
  <div
    class="row"
    :class="[
      isOutbound ? 'row--out' : 'row--in',
      { 'row--highlighted': highlighted },
    ]"
    :data-message-id="message.id"
  >
    <div class="stack" :class="isOutbound ? 'stack--out' : 'stack--in'">
      <div
        class="bubble-row"
        :class="isOutbound ? 'bubble-row--out' : 'bubble-row--in'"
      >
        <div class="bubble-wrap">
          <!-- Jumbo emoji -->
          <div
            v-if="isEmojiOnlyMessage"
            class="bubble bubble--emoji"
            :class="isOutbound ? 'bubble--out-emoji' : 'bubble--in-emoji'"
          >
            <div>{{ textContent }}</div>
            <MessageMeta
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
          </div>

          <!-- Plain text -->
          <div
            v-else-if="message.message_type === 'text'"
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <div
              v-if="quotedPreview"
              class="quote"
              :class="isOutbound ? 'quote--out' : 'quote--in'"
              @click="handleQuoteClick"
            >
              <span class="quote__bar" />
              <div class="quote__body">
                <p class="quote__sender">{{ quotedPreview.sender }}</p>
                <p class="quote__text">
                  <span v-if="quotedPreview.icon"
                    >{{ quotedPreview.icon }} </span
                  >{{ quotedPreview.text }}
                </p>
              </div>
            </div>
            <span class="bubble__text" v-html="formattedTextHtml" />
            <MessageMeta
              class="meta-float"
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
            <span class="meta-clear" />
          </div>

          <!-- Sticker -->
          <div
            v-else-if="message.message_type === 'sticker'"
            class="bubble bubble--sticker"
            @click="handleBubbleClick"
          >
            <img
              :src="mediaUrl(message) ?? ''"
              alt="Sticker"
              class="sticker__img"
            />
            <MessageMeta
              class="sticker__meta"
              overlay
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
          </div>

          <!-- Image / video -->
          <div
            v-else-if="
              message.message_type === 'image' ||
              message.message_type === 'video'
            "
            class="bubble bubble--media"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <div
              v-if="quotedPreview"
              class="quote quote--media"
              :class="isOutbound ? 'quote--out' : 'quote--in'"
              @click="handleQuoteClick"
            >
              <span class="quote__bar" />
              <div class="quote__body">
                <p class="quote__sender">{{ quotedPreview.sender }}</p>
                <p class="quote__text">
                  <span v-if="quotedPreview.icon"
                    >{{ quotedPreview.icon }} </span
                  >{{ quotedPreview.text }}
                </p>
              </div>
            </div>
            <div class="media__frame">
              <!-- Image -->
              <template v-if="message.message_type === 'image'">
                <img
                  :src="mediaUrl(message) ?? ''"
                  alt="Attachment"
                  class="media__img media__img--clickable"
                  @click="openLightbox"
                />
                <MessageMeta
                  v-if="!mediaCaption(message.content)"
                  class="media__time-overlay"
                  overlay
                  :time="message.created_at"
                  :is-outbound="isOutbound"
                  :status="message.status"
                />
              </template>
              <!-- Video — thumbnail → download progress → fullscreen viewer -->
              <VideoPlayer
                v-else
                :src="mediaUrl(message)"
                :is-outbound="isOutbound"
              />
            </div>
            <div
              v-if="mediaCaption(message.content)"
              class="media__caption-row"
            >
              <span
                class="media__caption-text"
                v-html="waMarkdownToHtml(mediaCaption(message.content) ?? '')"
              />
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
            </div>
          </div>

          <!-- Document -->
          <div
            v-else-if="message.message_type === 'document'"
            class="bubble bubble--document"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
            @click="openDocument"
          >
            <div
              v-if="quotedPreview"
              class="quote quote--media"
              :class="isOutbound ? 'quote--out' : 'quote--in'"
              @click.stop="handleQuoteClick"
            >
              <span class="quote__bar" />
              <div class="quote__body">
                <p class="quote__sender">{{ quotedPreview.sender }}</p>
                <p class="quote__text">
                  <span v-if="quotedPreview.icon"
                    >{{ quotedPreview.icon }} </span
                  >{{ quotedPreview.text }}
                </p>
              </div>
            </div>
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
              <span
                class="media__caption-text"
                v-html="waMarkdownToHtml(documentMeta.caption)"
              />
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
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
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
            </div>
          </div>

          <!-- Contact card -->
          <div
            v-else-if="message.message_type === 'contacts'"
            class="bubble bubble--contact"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
            @click="handleBubbleClick"
          >
            <div class="contact__top">
              <span class="contact__avatar">{{ contactMeta.initials }}</span>
              <div class="contact__meta">
                <p class="contact__name">{{ contactMeta.name }}</p>
                <p v-if="contactMeta.phone" class="contact__phone">
                  {{ contactMeta.phone }}
                </p>
              </div>
            </div>
            <div class="contact__meta-row">
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
            </div>
          </div>

          <!-- Interactive LIST -->
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
            <div class="list-card__meta-row">
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
            </div>
          </button>

          <!-- Interactive BUTTON -->
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
            </button>
            <button
              v-for="option in buttonOptions"
              :key="option.id"
              type="button"
              class="buttons-card__row text-info"
            >
              {{ option.title }}
            </button>
            <MessageMeta
              class="buttons-card__time"
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
          </div>

          <!-- Inbound tap / button reply -->
          <div
            v-else-if="
              message.message_type === 'interactive' ||
              message.message_type === 'button'
            "
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <div
              v-if="quotedPreview"
              class="quote"
              :class="isOutbound ? 'quote--out' : 'quote--in'"
              @click="handleQuoteClick"
            >
              <span class="quote__bar" />
              <div class="quote__body">
                <p class="quote__sender">{{ quotedPreview.sender }}</p>
                <p class="quote__text">
                  <span v-if="quotedPreview.icon"
                    >{{ quotedPreview.icon }} </span
                  >{{ quotedPreview.text }}
                </p>
              </div>
            </div>
            {{ previewText(message.content, message.message_type) }}
            <MessageMeta
              class="meta-float"
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
            <span class="meta-clear" />
          </div>

          <!-- Voice note — AudioPlayer with scrubber + real playback -->
          <div
            v-else-if="message.message_type === 'audio'"
            class="bubble bubble--audio-wrap"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            <AudioPlayer
              :src="audioSrc"
              :is-outbound="isOutbound"
              :duration-seconds="audioDuration"
            />
            <div class="audio-meta">
              <MessageMeta
                :time="message.created_at"
                :is-outbound="isOutbound"
                :status="message.status"
              />
            </div>
          </div>

          <!-- Fallback -->
          <div
            v-else
            class="bubble bubble--text"
            :class="isOutbound ? 'bubble--out' : 'bubble--in'"
          >
            {{ previewText(message.content, message.message_type) }}
            <MessageMeta
              class="meta-float"
              :time="message.created_at"
              :is-outbound="isOutbound"
              :status="message.status"
            />
            <span class="meta-clear" />
          </div>
        </div>
      </div>

      <div
        class="row__meta"
        :class="isOutbound ? 'row__meta--out' : 'row__meta--in'"
      >
        <button class="row__reply" type="button" @click="handleReplyClick">
          <VIcon size="small">$replyOutline</VIcon>
          Reply
        </button>
      </div>
    </div>
  </div>

  <Teleport to="body">
    <VueEasyLightbox
      :visible="lightboxVisible"
      :imgs="lightboxImgs"
      :index="0"
      @hide="lightboxVisible = false"
    />
  </Teleport>
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
  min-width: 0;
}

.quote {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  width: 100%;
  max-width: 260px;
  min-width: 0;
  box-sizing: border-box;
  padding: 6px 8px;
  margin-bottom: 6px;
  border-radius: 8px;
  cursor: pointer;
}
.quote--media {
  margin: 10px 12px 0;
  max-width: none;
}
.quote--out {
  background: rgba(3, 112, 180, 0.1);
}
.quote--in {
  background: rgba(0, 0, 0, 0.06);
}
.quote__bar {
  width: 3px;
  align-self: stretch;
  background: #20304f;
  border-radius: 2px;
  flex-shrink: 0;
}
.quote__body {
  min-width: 0;
  flex: 1;
}
.quote__sender {
  margin: 0 0 2px;
  font-size: 12.5px;
  font-weight: 600;
  color: #263457;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.quote__text {
  margin: 0;
  font-size: 12.5px;
  line-height: 1.35;
  color: #455e70;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  word-break: break-word;
}

.bubble--text {
  position: relative;
  padding: 5px 10px 6px;
  border-radius: 12px;
  max-width: 400px;
  font-size: 14px;
  line-height: 1.45;
  word-wrap: break-word;
}
.bubble__text :deep(.wa-mono) {
  font-family: "SFMono-Regular", Consolas, monospace;
  background: rgba(0, 0, 0, 0.06);
  padding: 1px 4px;
  border-radius: 4px;
  font-size: 0.92em;
}
.meta-float {
  float: right;
  margin: 4px 0 0 8px;
}
.meta-clear {
  display: block;
  clear: both;
}

.bubble--out {
  background: #ffffff;
  color: #2a2d30;
}
.bubble--in {
  background: #cdd1d8;
  border-bottom-left-radius: 6px;
}

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

.bubble--sticker {
  position: relative;
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
.sticker__meta {
  position: absolute;
  bottom: 4px;
  right: 4px;
}

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
  aspect-ratio: 4/3;
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
.media__img--clickable {
  cursor: zoom-in;
}
.media__video {
  width: 100%;
  height: 100%;
  object-fit: contain;
  display: block;
  background: #000;
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
.location__label {
  font-size: 14px;
  font-weight: 600;
  color: #1fa855;
}

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

.bubble--contact {
  width: 240px;
  max-width: 100%;
  padding: 12px;
  border-radius: 14px;
  cursor: pointer;
}
.bubble--contact.bubble--in {
  border-bottom-left-radius: 6px;
}
.contact__top {
  display: flex;
  align-items: center;
  gap: 10px;
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
.contact__meta-row {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
}

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
.list-card__meta-row {
  display: flex;
  justify-content: flex-end;
  padding: 0 14px 10px;
}

/* ── Audio bubble ──────────────────────────────────────────────── */
.bubble--audio-wrap {
  padding: 0;
  border-radius: 20px;
  display: inline-flex;
  flex-direction: column;
  overflow: hidden;
  min-width: 200px;
  max-width: 300px;
}
.bubble--audio-wrap.bubble--in {
  border-bottom-left-radius: 6px;
}
.bubble--audio-wrap.bubble--out {
  border-bottom-right-radius: 6px;
}
.audio-meta {
  display: flex;
  justify-content: flex-end;
  padding: 0 12px 6px;
}

/* ── Highlight flash ───────────────────────────────────────────── */
.row--highlighted .bubble--text,
.row--highlighted .bubble--media,
.row--highlighted .bubble--document,
.row--highlighted .bubble--contact,
.row--highlighted .bubble--audio-wrap,
.row--highlighted .bubble--buttons,
.row--highlighted .bubble--list {
  animation: bubble-flash 1.5s ease-out;
}
@keyframes bubble-flash {
  0% {
    box-shadow: 0 0 0 4px rgba(139, 183, 209, 0.65);
  }
  100% {
    box-shadow: 0 0 0 4px rgba(255, 213, 79, 0);
  }
}

.row__meta {
  display: flex;
  width: 100%;
  margin-top: 4px;
  padding: 0 4px;
}
.row__meta--out {
  justify-content: flex-end;
}
.row__meta--in {
  justify-content: flex-start;
}
.row__reply {
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
