/**
 * resources/ts/chat.ts
 *
 * Everything that doesn't hold reactive state lives here:
 *  - Types mirroring the Laravel broadcast payloads and API shapes
 *  - The shared `window.Echo` / `window.Pusher` global augmentation
 *  - Pure helpers for summarising message content (mirrors
 *    ConversationController::previewText() on the PHP side — keep the
 *    two in sync if the WhatsApp content shapes change)
 *
 * Reactive/stateful logic (Echo subscriptions, message list, sending)
 * lives in useChat.ts.
 */
import type Echo from "laravel-echo";
import type Pusher from "pusher-js";

/* ------------------------------------------------------------------ */
/* Global Window augmentation                                         */
/* ------------------------------------------------------------------ */

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: InstanceType<typeof Echo>;
  }
}

/* ------------------------------------------------------------------ */
/* Core types — mirror the Laravel models / broadcast payloads        */
/* ------------------------------------------------------------------ */

export type MessageDirection = "inbound" | "outbound";
export type MessageStatus = "sent" | "delivered" | "read" | "failed";

export type MessageType =
  | "text"
  | "interactive"
  | "button"
  | "image"
  | "video"
  | "audio"
  | "document"
  | "sticker"
  | "location"
  | "contacts";

export type SenderType = "bot" | "agent" | "contact" | null;

export interface InteractiveListRow {
  id: string;
  title: string;
  description?: string;
}

export interface InteractiveListSection {
  title?: string;
  rows: InteractiveListRow[];
}

export interface InteractiveButton {
  type: "reply";
  reply: { id: string; title: string };
}

/** content shape for message_type === 'interactive', outbound (bot-sent) variant. */
export interface InteractiveOutboundContent {
  type: "list" | "button";
  header?: { type: "text"; text: string };
  body?: { text: string };
  footer?: { text: string };
  action?: {
    button?: string;
    sections?: InteractiveListSection[];
    buttons?: InteractiveButton[];
  };
}

/** content shape for message_type === 'interactive', inbound (contact tapped a row/button). */
export interface InteractiveInboundContent {
  response: { title: string; id?: string; description?: string };
}

export type InteractiveContent =
  | InteractiveOutboundContent
  | InteractiveInboundContent;

export interface TextContent {
  text: string;
}

/**
 * Shared content shape for image/video/audio/document/sticker.
 * `filename` and `file_size` are only ever populated for `document` (and
 * only when the upstream webhook/upload actually provided them) — treat
 * both as optional everywhere else. When `filename` is absent, derive a
 * display name from `url` instead of assuming it exists.
 */
export interface MediaContent {
  caption?: string;
  url?: string;
  link?: string;
  mime_type?: string;
  sha256?: string;
  filename?: string;
  file_size?: number;
}

export interface LocationContent {
  name?: string;
  address?: string;
  latitude?: number;
  longitude?: number;
}

export interface ContactsContent {
  contacts: Array<{
    name?: { formatted_name?: string };
    phones?: Array<{ phone: string; type?: string }>;
  }>;
}

/** `content` is a jsonb column, so the shape varies by `message_type`. */
export type MessageContent =
  | TextContent
  | InteractiveContent
  | MediaContent
  | LocationContent
  | ContactsContent
  | { text?: string }
  | Record<string, unknown>;

export interface QuotedMessage {
  id: string;
  direction: MessageDirection;
  message_type: MessageType;
  content: MessageContent;
  sender_name?: string | null; // ← add this
}

export interface ChatMessage {
  id: string;
  conversation_id: string;
  whatsapp_message_id: string | null;
  reply_to_wamid?: string | null;
  direction: MessageDirection;
  message_type: MessageType;
  content: MessageContent;
  status: MessageStatus;
  sender_type: SenderType;
  sender_name: string | null;
  sent_at: string | null;
  delivered_at: string | null;
  read_at: string | null;
  created_at: string;
  quoted_message?: QuotedMessage | null;
  media_url?: string | null;
}

export interface ConversationSummary {
  id: string;
  whatsapp_user_phone: string;
  whatsapp_user_name: string | null;
  status: string;
  last_message_at: string | null;
  last_message_preview: string | null;
  last_message_preview_type: string | null; // ← add this
  unread_count: number;
  assigned_agent_id: string | null;
  assigned_agent_name: string | null;
}

/** Payload of `message.received` (App\Events\MessageSent). */
export interface MessageReceivedPayload {
  message: ChatMessage;
  conversation: ConversationSummary;
}

/** Payload of `message.status` (App\Events\MessageStatusUpdated). */
export interface MessageStatusPayload {
  message_id: string;
  whatsapp_message_id: string | null;
  status: MessageStatus;
  delivered_at: string | null;
  read_at: string | null;
}

/** Payload of `agent.typing` (App\Events\AgentTyping). */
export interface AgentTypingPayload {
  agent_id: string;
  agent_name: string;
  is_typing: boolean;
}

/** Payload of `contact.typing` (App\Events\ContactTyping). */
export interface ContactTypingPayload {
  conversation_id: string;
  contact_phone: string;
  is_typing: boolean;
}

/* ------------------------------------------------------------------ */
/* Pure preview / interactive-content helpers                         */
/* ------------------------------------------------------------------ */

const MEDIA_TYPES = ["image", "video", "audio", "document", "sticker"] as const;

function isInteractiveOutbound(
  c: InteractiveContent,
): c is InteractiveOutboundContent {
  const t = (c as InteractiveOutboundContent).type;
  return t === "list" || t === "button";
}

function isInteractiveInbound(
  c: InteractiveContent,
): c is InteractiveInboundContent {
  return (c as InteractiveInboundContent).response !== undefined;
}

/** One-line, type-aware summary of a message's content. */
export function previewText(
  content: MessageContent | null | undefined,
  type: MessageType,
): string {
  const c = content ?? {};

  if (type === "text") return (c as TextContent).text ?? "";

  if (type === "interactive") {
    const ic = c as InteractiveContent;
    if (isInteractiveInbound(ic))
      return ic.response.title ?? "Selected an option";
    if (isInteractiveOutbound(ic)) {
      if (ic.type === "list") return ic.body?.text ?? "List message";
      if (ic.type === "button") return ic.body?.text ?? "Button message";
    }
    return "Message";
  }

  if (type === "button") return (c as { text?: string }).text ?? "Quick reply";

  if ((MEDIA_TYPES as readonly string[]).includes(type)) {
    const media = c as MediaContent;
    return media.caption ?? type.charAt(0).toUpperCase() + type.slice(1);
  }

  if (type === "location") return (c as LocationContent).name ?? "Location";

  if (type === "contacts") {
    const contacts = c as ContactsContent;
    return contacts.contacts?.[0]?.name?.formatted_name ?? "Contact card";
  }

  return "Message";
}

/** Small icon-ish label used in the quoted-reply strip ("📷 Photo", "📍 Location", etc). */
export function previewIconLabel(type: MessageType): string | null {
  const labels: Partial<Record<MessageType, string>> = {
    image: "📷 Photo",
    video: "🎥 Video",
    audio: "🎤 Voice message",
    document: "📄 Document",
    sticker: "😀 Sticker",
    location: "📍 Location",
    contacts: "👤 Contact",
  };
  return labels[type] ?? null;
}

/**
 * One-line "last message" summary suitable for a conversation list row —
 * combines previewIconLabel + previewText so media/sticker/location/
 * contacts messages read the same way WhatsApp itself previews them
 * ("📷 Photo", "😀 Sticker") instead of showing blank or raw captions.
 * Plain text (including emoji-only messages) is returned as-is, since the
 * text itself is already the best possible preview.
 *
 * If `conversation.last_message_preview` is populated server-side by
 * something other than this same logic (e.g. a Message model observer),
 * that code path needs the equivalent icon-prefixed treatment for
 * media/sticker/location/contacts — otherwise the sidebar and the
 * bubble/quote-strip previews will disagree for those types.
 */
export function messagePreview(
  content: MessageContent | null | undefined,
  type: MessageType,
): string {
  if (type === "text") return previewText(content, type);

  const icon = previewIconLabel(type);
  const text = previewText(content, type);

  // For media/location/contacts, previewText already falls back to a
  // capitalized type name (e.g. "Sticker") when there's no caption — don't
  // double up with the icon label's own word in that case.
  if (icon && text && text.toLowerCase() !== type.toLowerCase()) {
    return `${icon.split(" ")[0]} ${text}`;
  }

  return icon ?? text;
}

export interface InteractiveOption {
  id: string;
  title: string;
  description?: string;
}

/**
 * Flattens an outbound interactive message's rows/buttons for the dialog.
 * Returns null for anything that isn't an outbound list/button message
 * (e.g. the inbound "user tapped X" shape has nothing left to pick).
 */
export function interactiveOptions(
  content: MessageContent | null | undefined,
  type: MessageType,
): InteractiveOption[] | null {
  if (type !== "interactive") return null;
  const ic = (content ?? {}) as InteractiveContent;
  if (!isInteractiveOutbound(ic)) return null;

  if (ic.type === "list") {
    const sections: InteractiveListSection[] = ic.action?.sections ?? [];
    return sections.flatMap((section) =>
      section.rows.map((row) => ({
        id: row.id,
        title: row.title,
        description: row.description,
      })),
    );
  }

  if (ic.type === "button") {
    const buttons: InteractiveButton[] = ic.action?.buttons ?? [];
    return buttons.map((b) => ({ id: b.reply.id, title: b.reply.title }));
  }

  return null;
}

/** Header/body/footer copy for the interactive dialog's title + intro text. */
export function interactiveCopy(
  content: MessageContent | null | undefined,
  type: MessageType,
): { header?: string; body?: string; footer?: string } {
  if (type !== "interactive") return {};
  const ic = (content ?? {}) as InteractiveContent;
  if (!isInteractiveOutbound(ic)) return {};
  return {
    header: ic.header?.text,
    body: ic.body?.text,
    footer: ic.footer?.text,
  };
}

/** "list" or "button", or null if this isn't an outbound interactive message at all. */
export function interactiveKind(
  content: MessageContent | null | undefined,
  type: MessageType,
): "list" | "button" | null {
  if (type !== "interactive") return null;
  const ic = (content ?? {}) as InteractiveContent;
  if (!isInteractiveOutbound(ic)) return null;
  return ic.type;
}

/**
 * The label on a list message's single tappable row (WhatsApp's
 * `action.button` field — e.g. "More", "Choose an option"). This is NOT
 * one of the list's actual rows; it's the one row visible in the bubble
 * itself before the picker sheet is opened. Falls back to a sensible
 * default when the payload didn't set one.
 */
export function listButtonLabel(
  content: MessageContent | null | undefined,
  type: MessageType,
): string {
  if (type !== "interactive") return "Choose an option";
  const ic = (content ?? {}) as InteractiveContent;
  if (!isInteractiveOutbound(ic) || ic.type !== "list")
    return "Choose an option";
  return ic.action?.button ?? "Choose an option";
}

export {};
