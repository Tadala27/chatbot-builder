/**
 * resources/ts/utils/messagePreview.ts
 *
 * One-line, type-aware summary of a message's content.
 * Mirrors ConversationController::previewText() on the backend (PHP) —
 * keep these two in sync if the WhatsApp content shapes change.
 */
import type {
  ContactsContent,
  InteractiveButton,
  InteractiveContent,
  InteractiveInboundContent,
  InteractiveListRow,
  InteractiveListSection,
  InteractiveOutboundContent,
  LocationContent,
  MediaContent,
  MessageContent,
  MessageType,
  TextContent,
} from "../chat/chat";

const MEDIA_TYPES = ["image", "video", "audio", "document", "sticker"] as const;

function isInteractiveOutbound(
  content: InteractiveContent,
): content is InteractiveOutboundContent {
  return (content as InteractiveOutboundContent).type !== undefined;
}

function isInteractiveInbound(
  content: InteractiveContent,
): content is InteractiveInboundContent {
  return (content as InteractiveInboundContent).response !== undefined;
}

export function previewText(
  content: MessageContent | null | undefined,
  type: MessageType,
): string {
  const c = content ?? {};

  if (type === "text") {
    return (c as TextContent).text ?? "";
  }

  if (type === "interactive") {
    const ic = c as InteractiveContent;

    if (isInteractiveInbound(ic)) {
      // user tapped a button/list row — inbound shape
      return ic.response.title ?? "Selected an option";
    }

    if (isInteractiveOutbound(ic)) {
      if (ic.type === "list") {
        return ic.body?.text ?? "List message";
      }
      if (ic.type === "button") {
        return ic.body?.text ?? "Button message";
      }
    }

    return "Message";
  }

  if (type === "button") {
    return (c as { text?: string }).text ?? "Quick reply";
  }

  if ((MEDIA_TYPES as readonly string[]).includes(type)) {
    const media = c as MediaContent;
    return media.caption ?? type.charAt(0).toUpperCase() + type.slice(1);
  }

  if (type === "location") {
    return (c as LocationContent).name ?? "Location";
  }

  if (type === "contacts") {
    const contacts = c as ContactsContent;
    return contacts.contacts?.[0]?.name?.formatted_name ?? "Contact card";
  }

  return "Message";
}

/** Small icon-ish label used in the quoted-reply strip ("Photo", "List message", etc). */
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
 * Normalises an outbound interactive message's rows/buttons into a flat
 * list the dialog can render, regardless of whether it's a list or a
 * button message. Returns null for anything else (e.g. the inbound
 * "user tapped X" shape, which has nothing left to pick).
 */
export interface InteractiveOption {
  id: string;
  title: string;
  description?: string;
}

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
      section.rows.map((row: InteractiveListRow) => ({
        id: row.id,
        title: row.title,
        description: row.description,
      })),
    );
  }

  if (ic.type === "button") {
    const buttons: InteractiveButton[] = ic.action?.buttons ?? [];
    return buttons.map((b) => ({
      id: b.reply.id,
      title: b.reply.title,
    }));
  }

  return null;
}

/** Header/body/footer text for the interactive dialog's title + intro copy. */
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
