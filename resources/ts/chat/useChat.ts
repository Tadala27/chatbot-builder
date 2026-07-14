/**
 * resources/ts/chat/useChat.ts
 *
 * One composable for the whole inbox screen:
 *  - conversation list (sidebar), kept live via the `tenant.inbox` channel
 *  - the active thread's messages, kept live via `conversation.{id}`
 *  - typing indicator (agent + contact)
 *  - optimistic send with reply-to-quote support
 *  - the interactive list/button dialog's open/close state
 *
 * Backed by:
 *   GET  /tenant/conversations              (sidebar list)
 *   GET  /tenant/conversations/{id}/messages (thread history)
 *   POST /tenant/conversations/{id}/messages (send — endpoint name
 *        is my best guess from the existing route style; point this at
 *        your real outbound-send route if it differs)
 *
 * Broadcasts consumed (from App\Events\*):
 *   message.received   → conversation.{id} AND tenant.inbox
 *   message.status     → conversation.{id}
 *   agent.typing        → conversation.{id}
 *   contact.typing       → conversation.{id}
 */
import { computed, onUnmounted, ref, watch, type Ref } from "vue";
import axios from "axios";
import type { Channel } from "laravel-echo";
import {
  interactiveCopy,
  interactiveOptions,
  previewText,
  type ChatMessage,
  type ConversationSummary,
  type AgentTypingPayload,
  type ContactTypingPayload,
  type InteractiveOption,
  type MessageReceivedPayload,
  type MessageStatusPayload,
} from "./chat";

export interface TypingState {
  isTyping: boolean;
  label: string | null;
}

export interface InteractiveDialogState {
  open: boolean;
  kind: "list" | "button";
  header?: string;
  body?: string;
  footer?: string;
  options: InteractiveOption[];
}

const TYPING_TIMEOUT_MS = 8000;

export function useChat() {
  /* ---------------------------------------------------------------- */
  /* Conversation list (sidebar)                                      */
  /* ---------------------------------------------------------------- */

  const conversations = ref<ConversationSummary[]>([]);
  const activeId = ref<string | null>(null);

  const activeConversation = computed(
    () => conversations.value.find((c) => c.id === activeId.value) ?? null,
  );

  async function loadConversations(): Promise<void> {
    const { data } = await axios.get("/tenant/conversations");
    const list: ConversationSummary[] = data.data ?? data;
    conversations.value = list;
    if (!activeId.value && list.length > 0) {
      activeId.value = list[0].id;
    }
  }

  function selectConversation(id: string): void {
    activeId.value = id;
    const target = conversations.value.find((c) => c.id === id);
    if (target) target.unread_count = 0;
  }

  /** Bumps/creates a sidebar row from a live `message.received` broadcast on the inbox channel. */
  function applyInboxActivity(summary: ConversationSummary): void {
    const idx = conversations.value.findIndex((c) => c.id === summary.id);
    if (idx === -1) {
      conversations.value.unshift(summary);
      return;
    }
    const isActiveThread = summary.id === activeId.value;
    const merged: ConversationSummary = {
      ...conversations.value[idx],
      ...summary,
      // Don't let the broadcast's unread_count override "0" while the
      // agent is actively looking at this thread.
      unread_count: isActiveThread ? 0 : summary.unread_count,
    };
    conversations.value.splice(idx, 1);
    conversations.value.unshift(merged);
  }

  /* ---------------------------------------------------------------- */
  /* Active thread messages                                            */
  /* ---------------------------------------------------------------- */

  const messages = ref<ChatMessage[]>([]);
  const isLoadingMessages = ref(false);
  const replyTarget = ref<ChatMessage | null>(null);

  const sortedMessages = computed(() =>
    [...messages.value].sort(
      (a, b) =>
        new Date(a.created_at).getTime() - new Date(b.created_at).getTime(),
    ),
  );

  const replyPreview = computed(() =>
    replyTarget.value
      ? previewText(replyTarget.value.content, replyTarget.value.message_type)
      : null,
  );

  function byWamid(wamid: string | null | undefined): ChatMessage | undefined {
    if (!wamid) return undefined;
    return messages.value.find((m) => m.whatsapp_message_id === wamid);
  }

  async function loadMessages(id: string): Promise<void> {
    isLoadingMessages.value = true;
    try {
      const { data } = await axios.get(`/tenant/conversations/${id}/messages`);
      messages.value = data.data ?? data;
    } finally {
      isLoadingMessages.value = false;
    }
  }

  function appendIncoming(message: ChatMessage): void {
    if (message.conversation_id !== activeId.value) return;
    const existing = messages.value.findIndex((m) => m.id === message.id);
    if (existing !== -1) {
      messages.value[existing] = message;
      return;
    }
    messages.value.push(message);
  }

  function applyStatus(payload: MessageStatusPayload): void {
    const target =
      messages.value.find((m) => m.id === payload.message_id) ??
      byWamid(payload.whatsapp_message_id);
    if (!target) return;
    target.status = payload.status;
    target.delivered_at = payload.delivered_at;
    target.read_at = payload.read_at;
  }

  function setReplyTarget(message: ChatMessage | null): void {
    replyTarget.value = message;
  }

  async function sendText(body: string): Promise<void> {
    if (!activeId.value || !body.trim()) return;
    const conversationId = activeId.value;
    const replyToWamid = replyTarget.value?.whatsapp_message_id ?? null;

    const optimistic: ChatMessage = {
      id: -Date.now(), // negative temp id, replaced once the real broadcast/response arrives
      conversation_id: conversationId,
      whatsapp_message_id: null,
      reply_to_wamid: replyToWamid,
      direction: "outbound",
      message_type: "text",
      content: { text: body },
      status: "sent",
      sender_type: "agent",
      sender_name: null,
      sent_at: new Date().toISOString(),
      delivered_at: null,
      read_at: null,
      created_at: new Date().toISOString(),
      quoted_message: replyTarget.value
        ? {
            id: replyTarget.value.id,
            direction: replyTarget.value.direction,
            message_type: replyTarget.value.message_type,
            content: replyTarget.value.content,
          }
        : null,
    };

    messages.value.push(optimistic);
    setReplyTarget(null);

    try {
      const { data } = await axios.post(
        `/tenant/conversations/${conversationId}/messages`,
        {
          text: body,
          reply_to_wamid: replyToWamid,
        },
      );
      const real: ChatMessage | undefined = data.data ?? data;
      if (real) {
        const idx = messages.value.findIndex((m) => m.id === optimistic.id);
        if (idx !== -1) messages.value[idx] = real;
      }
    } catch (error) {
      const idx = messages.value.findIndex((m) => m.id === optimistic.id);
      if (idx !== -1) messages.value[idx] = { ...optimistic, status: "failed" };
      throw error;
    }
  }

  /* ---------------------------------------------------------------- */
  /* Echo: conversation.{id} + tenant.inbox                            */
  /* ---------------------------------------------------------------- */

  const typing = ref<TypingState>({ isTyping: false, label: null });

  let conversationChannel: Channel | null = null;
  let inboxChannel: Channel | null = null;
  let agentTyping: AgentTypingPayload | null = null;
  let contactTyping: ContactTypingPayload | null = null;
  let typingResetTimer: ReturnType<typeof setTimeout> | null = null;

  function refreshTypingState(): void {
    typing.value = {
      isTyping: Boolean(agentTyping?.is_typing || contactTyping?.is_typing),
      label: agentTyping?.is_typing
        ? `${agentTyping.agent_name} is typing…`
        : contactTyping?.is_typing
          ? "Typing…"
          : null,
    };
  }

  function resetTypingAfterTimeout(): void {
    if (typingResetTimer) clearTimeout(typingResetTimer);
    typingResetTimer = setTimeout(() => {
      if (agentTyping) agentTyping = { ...agentTyping, is_typing: false };
      if (contactTyping) contactTyping = { ...contactTyping, is_typing: false };
      refreshTypingState();
    }, TYPING_TIMEOUT_MS);
  }

  function leaveConversationChannel(id: string): void {
    if (!window.Echo) return;
    window.Echo.leave(`conversation.${id}`);
    conversationChannel = null;
    agentTyping = null;
    contactTyping = null;
    refreshTypingState();
  }

  function joinConversationChannel(id: string): void {
    if (!window.Echo) return;
    conversationChannel = window.Echo.private(`conversation.${id}`)
      .listen("message.received", (payload: MessageReceivedPayload) =>
        appendIncoming(payload.message),
      )
      .listen("message.status", (payload: MessageStatusPayload) =>
        applyStatus(payload),
      )
      .listen("agent.typing", (payload: AgentTypingPayload) => {
        agentTyping = payload;
        refreshTypingState();
        resetTypingAfterTimeout();
      })
      .listen("contact.typing", (payload: ContactTypingPayload) => {
        contactTyping = payload;
        refreshTypingState();
        resetTypingAfterTimeout();
      });
  }

  function joinInboxChannel(): void {
    if (!window.Echo || inboxChannel) return;
    inboxChannel = window.Echo.private("tenant.inbox").listen(
      "message.received",
      (payload: MessageReceivedPayload) =>
        applyInboxActivity(payload.conversation),
    );
  }

  function leaveInboxChannel(): void {
    if (!window.Echo) return;
    window.Echo.leave("tenant.inbox");
    inboxChannel = null;
  }

  watch(activeId, (id, previousId) => {
    if (previousId) leaveConversationChannel(previousId);
    messages.value = [];
    replyTarget.value = null;
    if (id) {
      joinConversationChannel(id);
      loadMessages(id);
    }
  });

  /* ---------------------------------------------------------------- */
  /* Interactive (list/button) dialog — view-only, never sends         */
  /* ---------------------------------------------------------------- */

  const dialog = ref<InteractiveDialogState>({
    open: false,
    kind: "list",
    options: [],
  });

  function openInteractive(message: ChatMessage): void {
    const options = interactiveOptions(message.content, message.message_type);
    if (!options) return;
    const copy = interactiveCopy(message.content, message.message_type);
    const kind =
      (message.content as { type?: "list" | "button" }).type === "button"
        ? "button"
        : "list";
    dialog.value = {
      open: true,
      kind,
      header: copy.header,
      body: copy.body,
      footer: copy.footer,
      options,
    };
  }

  function closeInteractive(): void {
    dialog.value.open = false;
  }

  /* ---------------------------------------------------------------- */
  /* Bootstrap / teardown                                              */
  /* ---------------------------------------------------------------- */

  async function start(): Promise<void> {
    joinInboxChannel();
    await loadConversations();
  }

  onUnmounted(() => {
    if (activeId.value) leaveConversationChannel(activeId.value);
    leaveInboxChannel();
    if (typingResetTimer) clearTimeout(typingResetTimer);
  });

  return {
    conversations,
    activeId,
    activeConversation,
    selectConversation,
    messages: sortedMessages,
    isLoadingMessages,
    replyTarget,
    replyPreview,
    setReplyTarget,
    sendText,
    typing,
    dialog,
    openInteractive,
    closeInteractive,
    start,
  };
}
