<script setup lang="ts">
import { onMounted, ref } from "vue";
import MessageBubble from "./MessageBubble.vue";
import InteractiveOptionsDialog from "./ListDialog.vue";
import { useChat } from "@/chat/useChat";

const {
  conversations,
  activeId,
  activeConversation,
  selectConversation,
  messages,
  isLoadingMessages,
  replyPreview,
  setReplyTarget,
  sendText,
  typing,
  dialog,
  openInteractive,
  closeInteractive,
  start,
} = useChat();

const draft = ref("");

onMounted(start);

async function sendDraft() {
  const text = draft.value;
  if (!text.trim()) return;
  draft.value = "";
  await sendText(text).catch(() => {
    // sendText already marks the optimistic row as 'failed'; hook your
    // toast/snackbar system in here if you want a visible error too.
  });
}
function getInitials(name: string | null | undefined): string {
  if (!name) return "?";
  const parts = name.trim().split(/\s+/);
  if (parts.length === 1) {
    return parts[0].charAt(0).toUpperCase();
  }
  return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
}
</script>

<template>
  <div class="messages-page">
    <div class="layout">
      <!-- Sidebar: conversation list -->
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
                <span class="item__name">
                  <svg
                    v-if="conversation.pinned"
                    width="12"
                    height="12"
                    viewBox="0 0 24 24"
                    fill="none"
                    class="item__pin"
                  >
                    <path
                      d="M14 4l6 6-4 1-5 5-1-1-5 5v0l5-5-1-1 5-5 1-4z"
                      stroke="currentColor"
                      stroke-width="1.8"
                      stroke-linejoin="round"
                    />
                  </svg>
                  {{
                    conversation.whatsapp_user_name ??
                    conversation.whatsapp_user_phone
                  }}
                </span>
                <span class="item__time">{{
                  conversation.last_message_at
                    ? new Date(conversation.last_message_at)
                        .toLocaleTimeString([], {
                          hour: "numeric",
                          minute: "2-digit",
                        })
                        .toLowerCase()
                    : ""
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
                  {{
                    conversation.id === activeId && typing.isTyping
                      ? typing.label
                      : (conversation.last_message_preview ?? "")
                  }}
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
            <img
              :src="activeConversation.avatar ?? ''"
              :alt="activeConversation.whatsapp_user_name ?? ''"
              class="chat__avatar"
            />
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
            <button class="chat__icon-btn" aria-label="More options">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                <rect
                  x="3"
                  y="4"
                  width="14"
                  height="17"
                  rx="2"
                  stroke="currentColor"
                  stroke-width="1.5"
                />
                <path
                  d="M7 9h6M7 13h6"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                />
              </svg>
            </button>
          </div>
        </header>

        <div class="chat__body">
          <p v-if="isLoadingMessages" class="chat__loading">
            Loading messages…
          </p>
          <MessageBubble
            v-for="message in messages"
            :key="message.id"
            :message="message"
            :avatar="activeConversation?.avatar ?? ''"
            @reply="setReplyTarget"
            @open-interactive="openInteractive"
          />
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

        <footer class="chat__composer">
          <input
            v-model="draft"
            type="text"
            placeholder="Write a Message"
            @keyup.enter="sendDraft"
          />
          <button
            class="chat__send"
            aria-label="Send message"
            @click="sendDraft"
          >
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
              <path d="M3 11l18-7-7 18-3-8-8-3z" fill="currentColor" />
            </svg>
          </button>
        </footer>
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
</template>

<style scoped>
/* Design tokens scoped to this page, lifted from the reference screenshots. */
.messages-page {
  --color-sidebar-bg: #fdfcfc;
  --color-chat-bg: #ececec;
  --color-border: #eeeeee;
  --color-text-primary: #16191c;
  --color-text-secondary: #abb1ae;
  --color-text-tertiary: #b8c0bb;
  --color-online: #1fc06b;
  --color-unread: #f5a623;
  --radius-lg: 24px;
  --radius-md: 18px;
  --radius-sm: 10px;
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

/* Sidebar */
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

/* Conversation list item */
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

.item__avatar {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  object-fit: cover;
  display: block;
  background: #ececec;
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
  transform: rotate(0deg);
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
  font-size: 13px;
  color: var(--color-text-secondary);
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

/* Chat panel */
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

.chat__body {
  flex: 1;
  overflow-y: auto;
  padding: 3px 6px;
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

.chat__composer {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 24px;
  background: var(--color-sidebar-bg);
  border-top: 1px solid var(--color-border);
}

.chat__composer input {
  flex: 1;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 13px 18px;
  font-size: 14px;
  outline: none;
  background: #ffffff;
  color: var(--color-text-primary);
}

.chat__composer input:focus {
  border-color: var(--color-text-tertiary);
}

.chat__send {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  border: none;
  background: var(--color-text-primary);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
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
