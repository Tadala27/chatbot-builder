// waFormat.ts
import type { JSONContent } from "@tiptap/core";

const HTML_ESCAPES: Record<string, string> = {
  "&": "&amp;",
  "<": "&lt;",
  ">": "&gt;",
};

function escapeHtml(text: string): string {
  return text.replace(/[&<>]/g, (c) => HTML_ESCAPES[c]);
}

/**
 * Render a stored WhatsApp-markdown string as safe HTML for display inside
 * a message bubble. Escapes first, then applies formatting rules, so this
 * is safe to use with v-html even on untrusted message content.
 */
export function waMarkdownToHtml(raw: string): string {
  if (!raw) return "";

  let out = escapeHtml(raw);

  // Monospace first, so its contents become immune to the other rules
  // (WhatsApp does not apply bold/italic/strike inside a code span).
  out = out.replace(/```([^`\n]+?)```/g, '<code class="wa-mono">$1</code>');

  // Bold: *text* — lookarounds avoid matching mid-word asterisks (e.g. `a*b*c`)
  out = out.replace(
    /(?<![*\w])\*([^*\n]+?)\*(?![*\w])/g,
    "<strong>$1</strong>",
  );

  // Italic: _text_
  out = out.replace(/(?<![_\w])_([^_\n]+?)_(?![_\w])/g, "<em>$1</em>");

  // Strikethrough: ~text~
  out = out.replace(/(?<![~\w])~([^~\n]+?)~(?![~\w])/g, "<s>$1</s>");

  return out.replace(/\n/g, "<br>");
}

/**
 * Convert the Tiptap editor's JSON document into the WhatsApp-markdown
 * string that actually gets sent to the API / stored as message content.
 */
export function tiptapToWaMarkdown(doc: JSONContent): string {
  function renderInline(node: JSONContent): string {
    if (node.type === "text") {
      let text = node.text ?? "";
      const marks = new Set((node.marks ?? []).map((m) => m.type));

      // Code wins outright — WhatsApp monospace spans aren't nested with
      // other formatting, so bail out before applying bold/italic/strike.
      if (marks.has("code")) return "```" + text + "```";

      if (marks.has("bold")) text = `*${text}*`;
      if (marks.has("italic")) text = `_${text}_`;
      if (marks.has("strike")) text = `~${text}~`;

      return text;
    }

    if (node.type === "hardBreak") return "\n";

    return (node.content ?? []).map(renderInline).join("");
  }

  return (doc.content ?? [])
    .map((block) => (block.content ?? []).map(renderInline).join(""))
    .join("\n")
    .trim();
}

/** True if the editor currently holds no text (attachments may still exist). */
export function isEditorEmpty(doc: JSONContent): boolean {
  return tiptapToWaMarkdown(doc).length === 0;
}
