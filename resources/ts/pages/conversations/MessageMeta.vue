<script setup lang="ts">
import { computed } from "vue";
import type { MessageStatus } from "@/chat/chat";

const props = defineProps<{
  time: string;
  isOutbound: boolean;
  status?: MessageStatus;
  overlay?: boolean; // translucent pill variant, for use over photos/videos/stickers
}>();

function formatTime(iso: string): string {
  return new Date(iso)
    .toLocaleTimeString([], { hour: "numeric", minute: "2-digit" })
    .toLowerCase();
}

const tickState = computed<"sent" | "delivered" | "read" | "failed" | null>(() => {
  if (!props.isOutbound) return null;
  if (props.status === "failed") return "failed";
  if (props.status === "read") return "read";
  if (props.status === "delivered") return "delivered";
  return "sent"; // covers 'sent' and any unknown outbound status
});
</script>

<template>
  <span class="msg-meta" :class="{ 'msg-meta--overlay': overlay }">
    <span class="msg-meta__time">{{ formatTime(time) }}</span>
    <span v-if="tickState" class="ticks" :class="`ticks--${tickState}`">
      <span v-if="tickState === 'failed'" class="ticks__fail">!</span>
      <svg v-else width="15" height="10" viewBox="0 0 15 10" fill="none">
        <path
          d="M1 5.2L4 8.2L8.6 2"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
        <path
          v-if="tickState === 'delivered' || tickState === 'read'"
          d="M5.6 5.2L8.6 8.2L14 1.6"
          stroke="currentColor"
          stroke-width="1.4"
          stroke-linecap="round"
          stroke-linejoin="round"
        />
      </svg>
    </span>
  </span>
</template>

<style scoped>
.msg-meta {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 11px;
  white-space: nowrap;
  line-height: 1;
}
.msg-meta__time {
  opacity: 0.6;
  font-variant-numeric: tabular-nums;
}
.msg-meta--overlay {
  color: #fff;
  background: rgba(0, 0, 0, 0.45);
  padding: 2px 7px;
  border-radius: 10px;
}
.msg-meta--overlay .msg-meta__time {
  opacity: 1;
}
.ticks {
  display: inline-flex;
  align-items: center;
  color: currentColor;
  opacity: 0.55;
}
.ticks--read {
  color: #53bdeb;
  opacity: 1;
}
.ticks__fail {
  color: #e0524d;
  font-weight: 700;
  font-size: 11px;
  opacity: 1;
}
</style>