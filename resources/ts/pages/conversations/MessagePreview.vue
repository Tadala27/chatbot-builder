<script setup lang="ts">
import { computed } from "vue";
import { waMarkdownToHtml } from "@/chat/waFormat";

const props = defineProps<{
  type: string;
  text: string;
}>();

const ICONS: Record<string, string> = {
  image: "$imageOutline",
  video: "$videoVintage",
  audio: "$microphone",
  document: "$fileDocumentOutline",
  sticker: "$stickerOutline",
  location: "$mapMarkerOutline",
  contact: "$accountOutline",
  list: "$formatListBulleted",
  buttons: "$gestureTapButton",
  reply: "$replyOutline",
};

const ICON_TYPES = new Set(Object.keys(ICONS));

const iconName = computed(() => ICONS[props.type] ?? null);
const showIcon = computed(() => ICON_TYPES.has(props.type));

const TEXT_TYPES = new Set(["text", "reply", "list", "buttons", ""]);

function flattenToSingleLine(html: string): string {
  return html
    .replace(/<br\s*\/?>/gi, " ")
    .replace(/<\/p>\s*<p>/gi, " ")
    .replace(/<\/?p>/gi, "")
    .replace(/\s+/g, " ")
    .trim();
}

const renderedHtml = computed(() => {
  if (!TEXT_TYPES.has(props.type)) return null;
  return flattenToSingleLine(waMarkdownToHtml(props.text));
});
</script>

<template>
  <span class="msg-preview">
    <VIcon
      v-if="showIcon && iconName"
      :icon="iconName"
      size="14"
      class="msg-preview__icon"
    />

    <span v-if="renderedHtml" class="msg-preview__text" v-html="renderedHtml" />
    <span v-else class="msg-preview__text">{{ text }}</span>
  </span>
</template>

<style scoped>
.msg-preview {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  max-width: 100%;
  height: 18px;
  line-height: 18px;
  overflow: hidden;
}
.msg-preview__icon {
  flex-shrink: 0;
  opacity: 0.75;
}
.msg-preview__text {
  display: inline-block;
  height: 18px;
  line-height: 18px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.msg-preview__text :deep(p) {
  display: inline;
  margin: 0;
}
.msg-preview__text :deep(strong) {
  font-weight: 600;
}
.msg-preview__text :deep(em) {
  font-style: italic;
}
.msg-preview__text :deep(s) {
  text-decoration: line-through;
}
.msg-preview__text :deep(code) {
  font-family: "SFMono-Regular", Consolas, monospace;
  font-size: 0.9em;
}
</style>
