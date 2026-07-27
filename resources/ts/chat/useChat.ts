/**
 * resources/ts/chat/useChat.ts
 */
import { computed, nextTick, onUnmounted, ref, watch } from "vue";
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
  who: "agent" | "contact" | null;
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
const TYPING_DEBOUNCE_MS = 3000;

function buildPreviewFromMessage(message: ChatMessage): {
  text: string;
  type: string;
} {
  const text = previewText(message.content, message.message_type);
  const type =
    message.message_type === "interactive"
      ? (() => {
          const c = message.content as { type?: string; response?: unknown };
          if (c.response) return "reply";
          if (c.type === "button") return "buttons";
          return "list";
        })()
      : message.message_type === "button"
        ? "reply"
        : message.message_type;

  return { text: text, type: type };
}

function compareChronological(a: ChatMessage, b: ChatMessage): number {
  const aTime = new Date(a.sent_at ?? a.created_at).getTime();
  const bTime = new Date(b.sent_at ?? b.created_at).getTime();
  if (aTime !== bTime) return aTime - bTime;
  return String(a.id).localeCompare(String(b.id));
}

export function useChat() {
  /* ---------------------------------------------------------------- */
  /* Conversation list (sidebar)                                       */
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

  function applyInboxActivity(payload: MessageReceivedPayload): void {
    const { message, conversation: summary } = payload;
    const preview = buildPreviewFromMessage(message);
    const isActive = summary.id === activeId.value;
    const idx = conversations.value.findIndex((c) => c.id === summary.id);

    const updated: ConversationSummary = {
      ...(idx !== -1 ? conversations.value[idx] : {}),
      ...summary,
      last_message_preview: preview.text,
      last_message_preview_type: preview.type,
      last_message_at: message.created_at,
      unread_count: isActive
        ? 0
        : ((idx !== -1 ? conversations.value[idx].unread_count : 0) ?? 0) + 1,
    };

    if (idx !== -1) conversations.value.splice(idx, 1);
    conversations.value.unshift(updated);
  }

  /* ---------------------------------------------------------------- */
  /* Active thread messages                                            */
  /* ---------------------------------------------------------------- */

  const messages = ref<ChatMessage[]>([]);
  const isLoadingMessages = ref(false);
  const isLoadingOlder = ref(false);
  const hasMoreMessages = ref(false);
  const oldestMessageId = ref<string | null>(null);
  const replyTarget = ref<ChatMessage | null>(null);
  const chatBodyRef = ref<HTMLElement | null>(null);
  const highlightedMessageId = ref<string | null>(null);
  let highlightTimer: ReturnType<typeof setTimeout> | null = null;

  const sortedMessages = computed(() => messages.value);

  const replyPreview = computed(() =>
    replyTarget.value
      ? previewText(replyTarget.value.content, replyTarget.value.message_type)
      : null,
  );

  function byWamid(wamid: string | null | undefined): ChatMessage | undefined {
    if (!wamid) return undefined;
    return messages.value.find((m) => m.whatsapp_message_id === wamid);
  }

  function scrollToBottom(smooth = false): void {
    nextTick(() => {
      if (!chatBodyRef.value) return;
      chatBodyRef.value.scrollTo({
        top: chatBodyRef.value.scrollHeight,
        behavior: smooth ? "smooth" : "instant",
      });
    });
  }

  async function loadMessages(id: string): Promise<void> {
    isLoadingMessages.value = true;
    hasMoreMessages.value = false;
    oldestMessageId.value = null;
    try {
      const { data } = await axios.get(`/tenant/conversations/${id}/messages`, {
        params: { per_page: 100 },
      });
      const page: ChatMessage[] = data.data ?? data;
      messages.value = [...page].sort(compareChronological);
      hasMoreMessages.value = (data.last_page ?? 1) > 1 || page.length === 100;
      oldestMessageId.value = messages.value[0]?.id ?? null;
      scrollToBottom();
    } finally {
      isLoadingMessages.value = false;
    }
  }

  async function loadOlderMessages(): Promise<void> {
    if (
      !activeId.value ||
      isLoadingOlder.value ||
      !hasMoreMessages.value ||
      !oldestMessageId.value
    )
      return;
    isLoadingOlder.value = true;
    try {
      const { data } = await axios.get(
        `/tenant/conversations/${activeId.value}/messages`,
        { params: { per_page: 100, before: oldestMessageId.value } },
      );
      const page: ChatMessage[] = data.data ?? data;
      if (!page.length) {
        hasMoreMessages.value = false;
        return;
      }
      const older = [...page].sort(compareChronological);
      const body = chatBodyRef.value;
      const prevHeight = body?.scrollHeight ?? 0;
      const prevTop = body?.scrollTop ?? 0;

      messages.value = [...older, ...messages.value];
      oldestMessageId.value = messages.value[0]?.id ?? null;
      hasMoreMessages.value = page.length === 30;

      nextTick(() => {
        if (body) body.scrollTop = prevTop + (body.scrollHeight - prevHeight);
      });
    } finally {
      isLoadingOlder.value = false;
    }
  }

  function appendIncoming(message: ChatMessage): void {
    if (message.conversation_id !== activeId.value) return;

    if (message.direction === "inbound" && contactTyping) {
      contactTyping = { ...contactTyping, is_typing: false };
      refreshTypingState();
    }
    if (message.direction === "outbound" && agentTyping) {
      agentTyping = { ...agentTyping, is_typing: false };
      refreshTypingState();
    }

    // 1. Exact ID match — update in place (duplicate broadcast / status resend)
    const exactIdx = messages.value.findIndex((m) => m.id === message.id);
    if (exactIdx !== -1) {
      messages.value[exactIdx] = message;
      return;
    }
    if (message.direction === "outbound" && message.whatsapp_message_id) {
      const optimisticIdx = messages.value.findIndex(
        (m) => Number(m.id) < 0 && m.direction === "outbound",
      );
      if (optimisticIdx !== -1) {
        messages.value[optimisticIdx] = message;
        scrollToBottom(true);
        return;
      }
    }

    // 3. Genuinely new message — insert in chronological order
    const insertAt = messages.value.findIndex(
      (m) => compareChronological(m, message) > 0,
    );
    if (insertAt === -1) {
      messages.value.push(message);
    } else {
      messages.value.splice(insertAt, 0, message);
    }
    scrollToBottom(true);

    const conv = conversations.value.find((c) => c.id === activeId.value);
    if (conv) conv.unread_count = 0;
  }

  async function scrollToMessage(id: string, attempt = 0): Promise<void> {
    await nextTick();

    const el = chatBodyRef.value?.querySelector<HTMLElement>(
      `[data-message-id="${CSS.escape(String(id))}"]`,
    );

    if (el) {
      el.scrollIntoView({ behavior: "smooth", block: "center" });
      highlightedMessageId.value = id;
      if (highlightTimer) clearTimeout(highlightTimer);
      highlightTimer = setTimeout(() => {
        if (highlightedMessageId.value === id)
          highlightedMessageId.value = null;
      }, 1600);
      return;
    }

    const MAX_ATTEMPTS = 5;
    if (
      attempt < MAX_ATTEMPTS &&
      hasMoreMessages.value &&
      !isLoadingOlder.value
    ) {
      await loadOlderMessages();
      return scrollToMessage(id, attempt + 1);
    }
    // Target message isn't reachable (deleted, different conversation, or
    // history exhausted) — silently give up rather than jarring the user.
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

    stopTyping();

    const optimistic: ChatMessage = {
      id: String(-Date.now()),
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

    const sidebarEntry = conversations.value.find(
      (c) => c.id === conversationId,
    );
    if (sidebarEntry) {
      const preview = buildPreviewFromMessage(optimistic);
      sidebarEntry.last_message_preview = preview.text;
      sidebarEntry.last_message_preview_type = preview.type;
      sidebarEntry.last_message_at = optimistic.created_at;
    }

    setReplyTarget(null);

    try {
      const { data } = await axios.post(
        `/tenant/conversations/${conversationId}/messages`,
        { text: body, reply_to_wamid: replyToWamid },
      );
      const real: ChatMessage | undefined = data.data ?? data;
      if (real) {
        // Replace the optimistic row with the API-confirmed message.
        // If appendIncoming already swapped it via the broadcast, the
        // negative id won't be found and this is a safe no-op.
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
  /* Agent typing → contact (debounced, auto-stops)                   */
  /* ---------------------------------------------------------------- */

  let typingDebounceTimer: ReturnType<typeof setTimeout> | null = null;
  let agentIsTypingSent = false;

  function notifyTyping(): void {
    if (!activeId.value) return;
    if (!agentIsTypingSent) {
      agentIsTypingSent = true;
      axios
        .post(`/tenant/inbox/${activeId.value}/typing`, {
          typing: true,
        })
        .catch(() => {
          agentIsTypingSent = false;
        });
    }
    if (typingDebounceTimer) clearTimeout(typingDebounceTimer);
    typingDebounceTimer = setTimeout(stopTyping, TYPING_DEBOUNCE_MS);
  }

  function stopTyping(): void {
    if (typingDebounceTimer) clearTimeout(typingDebounceTimer);
    typingDebounceTimer = null;
    if (agentIsTypingSent && activeId.value) {
      const id = activeId.value;
      agentIsTypingSent = false;
      axios
        .post(`/tenant/inbox/${id}/typing`, { typing: false })
        .catch(() => {});
    }
  }

  /* ---------------------------------------------------------------- */
  /* Echo: conversation.{id} + tenant.inbox                           */
  /* ---------------------------------------------------------------- */

  const typing = ref<TypingState>({ isTyping: false, label: null, who: null });

  let conversationChannel: Channel | null = null;
  let inboxChannel: Channel | null = null;
  let agentTyping: AgentTypingPayload | null = null;
  let contactTyping: ContactTypingPayload | null = null;
  let typingResetTimer: ReturnType<typeof setTimeout> | null = null;

  function refreshTypingState(): void {
    if (contactTyping?.is_typing) {
      typing.value = { isTyping: true, label: "Typing…", who: "contact" };
    } else if (agentTyping?.is_typing) {
      typing.value = {
        isTyping: true,
        label: `${agentTyping.agent_name} is typing…`,
        who: "agent",
      };
    } else {
      typing.value = { isTyping: false, label: null, who: null };
    }
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
      .listen(".message.received", (payload: MessageReceivedPayload) => {
        appendIncoming(payload.message);
        applyInboxActivity(payload);
      })
      .listen(".message.status", (payload: MessageStatusPayload) =>
        applyStatus(payload),
      )
      .listen(".agent.typing", (payload: AgentTypingPayload) => {
        agentTyping = payload;
        refreshTypingState();
        resetTypingAfterTimeout();
      })
      .listen(".contact.typing", (payload: ContactTypingPayload) => {
        contactTyping = payload;
        refreshTypingState();
        resetTypingAfterTimeout();
      });
  }

  function joinInboxChannel(): void {
    if (!window.Echo || inboxChannel) return;
    inboxChannel = window.Echo.private("tenant.inbox").listen(
      ".message.received",
      (payload: MessageReceivedPayload) => applyInboxActivity(payload),
    );
  }

  function leaveInboxChannel(): void {
    if (!window.Echo) return;
    window.Echo.leave("tenant.inbox");
    inboxChannel = null;
  }

  watch(activeId, (id, previousId) => {
    if (previousId) leaveConversationChannel(previousId);
    stopTyping();
    messages.value = [];
    replyTarget.value = null;
    if (id) {
      joinConversationChannel(id);
      loadMessages(id);
    }
  });

  /* ---------------------------------------------------------------- */
  /* Interactive (list/button) dialog                                  */
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
    stopTyping();
    if (activeId.value) leaveConversationChannel(activeId.value);
    leaveInboxChannel();
    if (typingResetTimer) clearTimeout(typingResetTimer);
    if (highlightTimer) clearTimeout(highlightTimer); // ← add this line
  });

  async function sendMessage(payload: {
    text: string;
    attachments: Array<{
      file: File;
      kind: string;
      caption: string;
      previewUrl: string | null;
    }>;
  }): Promise<void> {
    if (!activeId.value) return;

    const { text, attachments } = payload;
    const conversationId = activeId.value;

    if (attachments.length > 0) {
      let firstAttachment = true;

      for (const attachment of attachments) {
        const formData = new FormData();
        formData.append("file", attachment.file);

        if (attachment.caption.trim()) {
          formData.append("caption", attachment.caption.trim());
        }

        // Apply replyTarget only to the first item
        if (firstAttachment) {
          const wamid = replyTarget.value?.whatsapp_message_id ?? null;
          if (wamid) formData.append("reply_to_wamid", wamid);
          setReplyTarget(null);
          firstAttachment = false;
        }

        await axios.post(`/tenant/inbox/${conversationId}/send`, formData, {
          headers: { "Content-Type": "multipart/form-data" },
        });
      }

      if (text.trim()) {
        const formData = new FormData();
        formData.append("text", text.trim());
        await axios.post(`/tenant/inbox/${conversationId}/send`, formData, {
          headers: { "Content-Type": "multipart/form-data" },
        });
      }
    } else {
      await sendText(text);
    }
  }

  return {
    conversations,
    activeId,
    activeConversation,
    selectConversation,
    messages: sortedMessages,
    isLoadingOlder,
    hasMoreMessages,
    chatBodyRef,
    loadOlderMessages,
    isLoadingMessages,
    replyTarget,
    replyPreview,
    setReplyTarget,
    sendText,
    sendMessage,
    typing,
    notifyTyping,
    stopTyping,
    dialog,
    openInteractive,
    closeInteractive,
    start,
    highlightedMessageId, // ← add
    scrollToMessage, // ← add
  };
}
