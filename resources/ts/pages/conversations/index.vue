<script setup lang="ts">
import { onMounted, ref } from "vue";
import MessageBubble from "./MessageBubble.vue";
import InteractiveOptionsDialog from "./ListDialog.vue";
import MessagePreview from "./MessagePreview.vue";
import Composer, { type ComposerSendPayload } from "./Composer.vue";
import { useChat } from "@/chat/useChat";
import DateSeparator from "./DateSeparator.vue";
import { useStickyDate } from "@/chat/useStickyDate";

import axios from "axios";

const {
  conversations,
  activeId,
  activeConversation,
  selectConversation,
  messages,
  isLoadingMessages,
  isLoadingOlder,
  hasMoreMessages,
  chatBodyRef,
  loadOlderMessages,
  replyPreview,
  setReplyTarget,
  sendMessage,
  typing,
  notifyTyping,
  stopTyping,
  dialog,
  openInteractive,
  closeInteractive,
  start,
  highlightedMessageId,
  scrollToMessage,
} = useChat();

const {
  stickyDate,
  showChip,
  isAtBottom,
  scrollToBottom: jumpToBottom,
} = useStickyDate(chatBodyRef, messages);

const snackbar = ref({
  show: false,
  text: "",
  color: "success" as "success" | "error",
});

function showSnackbar(text: string, color: "success" | "error" = "success") {
  snackbar.value = { show: true, text, color };
}

onMounted(start);

async function handleComposerSend(payload: ComposerSendPayload) {
  try {
    await sendMessage(payload);
  } catch (e: any) {
    console.error(e);
    showSnackbar(
      e.response?.data?.message ?? "Failed to send message.",
      "error",
    );
  }
}

function getInitials(name: string | null | undefined): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
  return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}

const groupedItems = computed(() => {
  type Item =
    | { kind: "separator"; label: string; key: string }
    | { kind: "message"; message: (typeof messages.value)[0]; key: string };

  const result: Item[] = [];
  let lastLabel: string | null = null;

  for (const msg of messages.value) {
    const raw = msg.sent_at ?? msg.created_at;
    const label = dateLabelFor(raw);

    if (label !== lastLabel) {
      result.push({ kind: "separator", label, key: `sep-${label}` });
      lastLabel = label;
    }

    result.push({ kind: "message", message: msg, key: msg.id });
  }

  return result;
});

function dateLabelFor(dateStr: string | null | undefined): string {
  if (!dateStr) return "Unknown";
  const d = new Date(dateStr);
  const now = new Date();

  const startOf = (dt: Date) =>
    new Date(dt.getFullYear(), dt.getMonth(), dt.getDate()).getTime();

  const today = startOf(now);
  const yesterday = today - 86_400_000;
  const day = startOf(d);

  if (day === today) return "Today";
  if (day === yesterday) return "Yesterday";

  if (d.getFullYear() === now.getFullYear()) {
    return d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
  }

  return d.toLocaleDateString(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

function formatConversationTime(dateStr: string | null | undefined): string {
  if (!dateStr) return "";

  const date = new Date(dateStr);
  const now = new Date();

  const startOfDay = (d: Date) =>
    new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

  const startOfToday = startOfDay(now);
  const startOfYesterday = startOfToday - 86_400_000;
  const startOfDate = startOfDay(date);

  if (startOfDate === startOfToday) {
    return date.toLocaleTimeString([], {
      hour: "2-digit",
      minute: "2-digit",
      hour12: false,
    });
  }

  if (startOfDate === startOfYesterday) {
    return "Yesterday";
  }

  return date.toLocaleDateString([], {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  });
}

// Scroll handler: load older messages when user reaches the top
const SCROLL_THRESHOLD = 80; // px from top to trigger load

function onChatScroll(e: Event) {
  const el = e.target as HTMLElement;
  if (
    el.scrollTop <= SCROLL_THRESHOLD &&
    hasMoreMessages.value &&
    !isLoadingOlder.value
  ) {
    loadOlderMessages();
  }
}

async function resolveChat(conversationId: string): Promise<void> {
  try {
    await axios.post(`/tenant/conversations/${conversationId}/resolve`);
    showSnackbar("Conversation resolved.");
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? "Failed to resolve conversation.",
      "error",
    );
  }
}
</script>

<template>
  <div class="messages-page">
    <div class="layout">
      <!-- Sidebar -->
      <aside class="sidebar">
        <div class="sidebar__search">
          <svg
            width="16"
            height="16"
            viewBox="0 0 24 24"
            fill="none"
            class="sidebar__search-icon"
          >
            <circle
              cx="11"
              cy="11"
              r="7"
              stroke="currentColor"
              stroke-width="1.8"
            />
            <path
              d="M20 20l-3.5-3.5"
              stroke="currentColor"
              stroke-width="1.8"
              stroke-linecap="round"
            />
          </svg>
          <input type="text" placeholder="Search" />
        </div>

        <h1 class="sidebar__heading">Messages</h1>

        <div class="sidebar__list">
          <button
            v-for="conversation in conversations"
            :key="conversation.id"
            class="item"
            :class="{ 'item--active': conversation.id === activeId }"
            @click="selectConversation(conversation.id)"
          >
            <span class="item__avatar-wrap">
              <span class="item__avatar-initials">
                {{
                  getInitials(
                    conversation.whatsapp_user_name ??
                      conversation.whatsapp_user_phone,
                  )
                }}
              </span>
              <span class="item__status" />
            </span>

            <span class="item__body">
              <span class="item__top">
                <span class="item__name">{{
                  conversation.whatsapp_user_name ??
                  conversation.whatsapp_user_phone
                }}</span>
                <span class="item__time">{{
                  formatConversationTime(conversation.last_message_at)
                }}</span>
              </span>
              <span class="item__bottom">
                <span
                  class="item__preview"
                  :class="{
                    'item__preview--typing':
                      conversation.id === activeId && typing.isTyping,
                  }"
                >
                  <template
                    v-if="conversation.id === activeId && typing.isTyping"
                  >
                    {{ typing.label }}
                  </template>
                  <MessagePreview
                    v-else
                    :type="conversation.last_message_preview_type ?? 'text'"
                    :text="conversation.last_message_preview ?? ''"
                  />
                </span>
                <span v-if="conversation.unread_count" class="item__badge">{{
                  conversation.unread_count
                }}</span>
                <svg
                  v-else
                  class="item__check"
                  width="13"
                  height="13"
                  viewBox="0 0 24 24"
                  fill="none"
                >
                  <path
                    d="M5 13l4 4L19 7"
                    stroke="currentColor"
                    stroke-width="2.3"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </span>
            </span>
          </button>
        </div>
      </aside>

      <!-- Main chat panel -->
      <section class="chat">
        <header v-if="activeConversation" class="chat__header">
          <div class="chat__who">
            <!-- <img
              :src="activeConversation.avatar ?? ''"
              :alt="activeConversation.whatsapp_user_name ?? ''"
              class="chat__avatar"
            /> -->
            <div class="chat__who-text">
              <span class="chat__name">{{
                activeConversation.whatsapp_user_name ??
                activeConversation.whatsapp_user_phone
              }}</span>
              <span class="chat__status">
                <span class="chat__status-dot" />
                {{ typing.label ?? "Online" }}
              </span>
            </div>
          </div>

          <div class="chat__actions">
            <button class="chat__icon-btn" aria-label="Pin conversation">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <path
                  d="M14 4l6 6-4 1-5 5-1-1-5 5v0l5-5-1-1 5-5 1-4z"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linejoin="round"
                />
              </svg>
            </button>
            <button class="chat__icon-btn" aria-label="Media">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <rect
                  x="3"
                  y="5"
                  width="18"
                  height="14"
                  rx="2"
                  stroke="currentColor"
                  stroke-width="1.5"
                />
                <circle
                  cx="8.5"
                  cy="10"
                  r="1.5"
                  stroke="currentColor"
                  stroke-width="1.5"
                />
                <path
                  d="M3 16l5-4 4 3 4-4 5 5"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linejoin="round"
                />
              </svg>
            </button>
            <VMenu location="bottom end">
              <template #activator="{ props }">
                <VBtn
                  class="chat__icon-btn"
                  icon
                  size="x-small"
                  variant="text"
                  v-bind="props"
                  @click.stop
                >
                  <VIcon icon="$dotsVertical" size="18" />
                </VBtn>
              </template>
              <VList density="compact" min-width="160">
                <VListItem @click.stop="resolveChat(activeConversation.id)">
                  <VListItemTitle>
                    <VIcon icon="$chatProcessingOutline" class="mr-1" />
                    Resolve Chat
                  </VListItemTitle>
                </VListItem>
              </VList>
            </VMenu>
          </div>
        </header>

        <div class="chat__body-wrap">
          <div ref="chatBodyRef" class="chat__body" @scroll="onChatScroll">
            <!-- Load-older spinner -->
            <div v-if="isLoadingOlder" class="chat__load-older">
              <VProgressCircular
                indeterminate
                size="20"
                width="2"
                color="primary"
              />
            </div>

            <div
              v-else-if="!hasMoreMessages && messages.length > 0"
              class="chat__all-loaded"
            >
              All messages loaded
            </div>

            <p v-if="isLoadingMessages" class="chat__loading">
              Loading messages…
            </p>

            <!-- Combined message + separator loop -->
            <template v-for="item in groupedItems" :key="item.key">
              <DateSeparator
                v-if="item.kind === 'separator'"
                :label="item.label"
              />
              <MessageBubble
                v-else
                :message="item.message"
                :highlighted="item.message.id === highlightedMessageId"
                @reply="setReplyTarget"
                @open-interactive="openInteractive"
                @jump-to-message="scrollToMessage"
              />
            </template>

            <!-- Typing bubble -->
            <div
              v-if="typing.isTyping"
              class="row"
              :class="typing.who === 'agent' ? 'row--out' : 'row--in'"
            >
              <div
                class="stack"
                :class="typing.who === 'agent' ? 'stack--out' : 'stack--in'"
              >
                <div
                  class="typing-bubble"
                  :class="
                    typing.who === 'agent'
                      ? 'typing-bubble--out'
                      : 'typing-bubble--in'
                  "
                >
                  <span class="typing-dots"><span /><span /><span /></span>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Sticky date chip — floats above the chat body ─────────── -->
          <Transition name="date-chip">
            <div v-if="stickyDate && showChip" class="chat__sticky-date">
              {{ stickyDate }}
            </div>
          </Transition>

          <!-- ── Scroll-to-bottom FAB ───────────────────────────────────── -->
          <Transition name="scroll-btn">
            <button
              v-if="!isAtBottom"
              type="button"
              class="chat__scroll-btn"
              aria-label="Scroll to latest message"
              @click="jumpToBottom()"
            >
              <VIcon icon="$chevronDown" size="20" />
            </button>
          </Transition>
        </div>

        <div v-if="replyPreview" class="chat__reply-preview">
          <span class="chat__reply-text">Replying to: {{ replyPreview }}</span>
          <button
            type="button"
            class="chat__reply-cancel"
            @click="setReplyTarget(null)"
          >
            ×
          </button>
        </div>

        <Composer
          @send="handleComposerSend"
          @typing="notifyTyping"
          @stop-typing="stopTyping"
          @error="(msg) => showSnackbar(msg, 'error')"
        />
      </section>
    </div>

    <InteractiveOptionsDialog
      :open="dialog.open"
      :kind="dialog.kind"
      :header="dialog.header"
      :body="dialog.body"
      :footer="dialog.footer"
      :options="dialog.options"
      @close="closeInteractive"
    />
  </div>

  <VSnackbar
    v-model="snackbar.show"
    :color="snackbar.color"
    timeout="4000"
    location="top right"
    closable
  >
    {{ snackbar.text }}
    <template #actions>
      <VBtn
        size="small"
        variant="text"
        icon="$close"
        @click="snackbar.show = false"
      />
    </template>
  </VSnackbar>
</template>

<style scoped>
.chat__load-older {
  display: flex;
  justify-content: center;
  padding: 12px 0 4px;
}

.chat__all-loaded {
  text-align: center;
  font-size: 12px;
  color: #b8c0bb;
  padding: 10px 0 4px;
  user-select: none;
}

/* ── Copy in all existing styles below this line unchanged ── */
.messages-page {
  --color-sidebar-bg: #fdfcfc;
  --color-chat-bg: #ececec;
  --color-border: #eeeeee;
  --color-text-primary: #16191c;
  --color-text-secondary: #abb1ae;
  --color-text-tertiary: #b8c0bb;
  --color-online: #1fc06b;
  --color-unread: #2d6ea4;
  --font-sans: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI",
    sans-serif;
  font-family: var(--font-sans);
  min-height: 90vh;
  display: flex;
  align-items: center;
  justify-content: center;
}
.messages-page * {
  box-sizing: border-box;
}
.messages-page button {
  font-family: inherit;
  cursor: pointer;
}
.messages-page input {
  font-family: inherit;
}
.layout {
  display: grid;
  grid-template-columns: 320px 1fr;
  height: 90vh;
  width: 100%;
  margin: 0 auto;
  background: var(--color-sidebar-bg);
  overflow: hidden;
}
.sidebar {
  background: var(--color-sidebar-bg);
  display: flex;
  flex-direction: column;
  padding: 20px 18px 12px;
  overflow: hidden;
}
.sidebar__search {
  display: flex;
  align-items: center;
  gap: 10px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  padding: 12px 14px;
  margin-bottom: 18px;
}
.sidebar__search-icon {
  color: var(--color-text-tertiary);
  flex-shrink: 0;
}
.sidebar__search input {
  border: none;
  background: transparent;
  outline: none;
  font-size: 14px;
  width: 100%;
  color: var(--color-text-primary);
}
.sidebar__heading {
  font-size: 26px;
  font-weight: 800;
  margin: 0 0 14px;
  padding: 0 6px;
  color: var(--color-text-primary);
}
.sidebar__list {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 0;
}
.item {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 11px 10px;
  border: none;
  background: transparent;
  border-radius: 16px;
  text-align: left;
}
.item--active {
  background: #c8cbd8;
}
.item__avatar-wrap {
  position: relative;
  flex-shrink: 0;
}
.item__avatar-initials {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: rgba(var(--v-theme-primary));
  color: rgba(var(--v-theme-white));
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 16px;
  font-weight: 600;
  text-transform: uppercase;
}
.item__status {
  position: absolute;
  bottom: -1px;
  right: -1px;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: var(--color-online);
  border: 2px solid var(--color-sidebar-bg);
}
.item__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.item__top {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}
.item__name {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 14.5px;
  font-weight: 600;
  color: var(--color-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.item__pin {
  color: var(--color-text-secondary);
  flex-shrink: 0;
}
.item__time {
  font-size: 12px;
  color: var(--color-text-primary);
  flex-shrink: 0;
}
.item__bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-height: 16px;
}
.item__preview {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.item__preview--typing {
  color: var(--color-online);
  font-style: italic;
}
.item__check {
  color: var(--color-online);
  flex-shrink: 0;
}
.item__badge {
  min-width: 19px;
  height: 19px;
  padding: 0 5px;
  border-radius: 999px;
  background: var(--color-unread);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.chat {
  display: flex;
  flex-direction: column;
  height: 90vh;
  background: var(--color-chat-bg);
}
.chat__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 24px;
  background: var(--color-sidebar-bg);
  border-bottom: 1px solid var(--color-border);
}
.chat__who {
  display: flex;
  align-items: center;
  gap: 12px;
}
.chat__avatar {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  display: block;
  background: #ececec;
}
.chat__who-text {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.chat__name {
  font-size: 15px;
  font-weight: 600;
  color: var(--color-text-primary);
}
.chat__status {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  color: var(--color-text-secondary);
}
.chat__status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--color-online);
}
.chat__actions {
  display: flex;
  gap: 8px;
}
.chat__icon-btn {
  width: 34px;
  height: 34px;
  border-radius: 10px;
  border: 1px solid var(--color-border);
  background: #fff;
  color: var(--color-text-secondary);
  display: flex;
  align-items: center;
  justify-content: center;
}

.chat__body-wrap {
  position: relative;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

/* Move overflow from .chat__body up — it still needs to scroll */
.chat__body {
  flex: 1;
  overflow-y: auto;
  padding: 3px 6px;
}

/* ── Sticky date chip ───────────────────────────────────────────── */
.chat__sticky-date {
  position: absolute;
  top: 10px;
  left: 50%;
  transform: translateX(-50%);
  z-index: 10;
  font-size: 11.5px;
  font-weight: 500;
  color: #6b7a87;
  background: rgba(255, 255, 255, 0.85);
  backdrop-filter: blur(6px);
  border: 1px solid rgba(0, 0, 0, 0.07);
  border-radius: 999px;
  padding: 3px 12px;
  box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
  pointer-events: none;
  white-space: nowrap;
  user-select: none;
}

/* Fade in/out */
.date-chip-enter-active,
.date-chip-leave-active {
  transition: opacity 0.2s ease;
}
.date-chip-enter-from,
.date-chip-leave-to {
  opacity: 0;
}

/* ── Scroll-to-bottom FAB ───────────────────────────────────────── */
.chat__scroll-btn {
  position: absolute;
  bottom: 16px;
  right: 16px;
  z-index: 10;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background: #fff;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
  color: #4a5568;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition:
    box-shadow 0.15s,
    opacity 0.15s;
  padding: 0;
}
.chat__scroll-btn:hover {
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.22);
  color: #16191c;
}

/* Slide up / slide down */
.scroll-btn-enter-active,
.scroll-btn-leave-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.scroll-btn-enter-from,
.scroll-btn-leave-to {
  opacity: 0;
  transform: translateY(8px);
}

.chat__loading {
  font-size: 13px;
  color: var(--color-text-secondary);
}
.chat__reply-preview {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 24px;
  background: var(--color-sidebar-bg);
  font-size: 12.5px;
  color: var(--color-text-primary);
  border-top: 1px solid var(--color-border);
}
.chat__reply-cancel {
  border: none;
  background: transparent;
  font-size: 16px;
  color: var(--color-text-secondary);
}
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
}
.stack--out {
  align-items: flex-end;
}
.stack--in {
  align-items: flex-start;
}
.typing-bubble {
  padding: 10px 14px;
  border-radius: 12px;
}
.typing-bubble--in {
  background: #cdd1d8;
  border-bottom-left-radius: 6px;
}
.typing-bubble--out {
  background: #ffffff;
  border-bottom-right-radius: 6px;
}
.typing-dots {
  display: inline-flex;
  gap: 3px;
}
.typing-dots span {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #97a39d;
  display: inline-block;
  animation: typing-bounce 1.2s infinite ease-in-out;
}
.typing-dots span:nth-child(2) {
  animation-delay: 0.15s;
}
.typing-dots span:nth-child(3) {
  animation-delay: 0.3s;
}
@keyframes typing-bounce {
  0%,
  60%,
  100% {
    transform: translateY(0);
    opacity: 0.5;
  }
  30% {
    transform: translateY(-4px);
    opacity: 1;
  }
}
@media (max-width: 860px) {
  .layout {
    grid-template-columns: 1fr;
  }
  .sidebar {
    display: none;
  }
}
</style>
