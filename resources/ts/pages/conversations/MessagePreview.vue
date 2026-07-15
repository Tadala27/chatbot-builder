<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{
  /** conversation.latest_message_type from the API */
  type: string;
  /** conversation.latest_message from the API */
  text: string;
}>();

// Outline paths, 24x24 viewBox — same visual language as the icons already
// used elsewhere (stroke=currentColor, width 2, round caps).
const ICON_PATHS: Record<string, string> = {
  image:
    "M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M4 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z",
  video:
    "M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z",
  audio:
    "M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3zM19 10v2a7 7 0 01-14 0v-2M12 19v4m-4 0h8",
  document:
    "M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zM14 2v6h6",
  sticker: "M15 3H5a2 2 0 00-2 2v14l4-4h10a2 2 0 002-2V8l-4-5zM15 3v5h5",
  location:
    "M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0zM12 13a3 3 0 100-6 3 3 0 000 6z",
  contact:
    "M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2M12 11a4 4 0 100-8 4 4 0 000 8z",
  list: "M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01",
  buttons: "M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01",
  reply: "M9 17l-5-5 5-5M4 12h12a4 4 0 014 4v1",
};

const ICON_TYPES = new Set(Object.keys(ICON_PATHS));

const iconPath = computed(() => ICON_PATHS[props.type] ?? null);
const showIcon = computed(() => ICON_TYPES.has(props.type));
</script>

<template>
  <span class="msg-preview">
    <svg
      v-if="showIcon && iconPath"
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="none"
      class="msg-preview__icon"
    >
      <path
        :d="iconPath"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>
    <span class="msg-preview__text">{{ text }}</span>
  </span>
</template>

<style scoped>
.msg-preview {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  min-width: 0;
  max-width: 100%;
}
.msg-preview__icon {
  flex-shrink: 0;
  opacity: 0.75;
}
.msg-preview__text {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
