/**
 * resources/ts/chat/useStickyDate.ts
 */

import { ref, watch, onUnmounted, type Ref } from "vue";
import type { ChatMessage } from "./chat";

export function useStickyDate(
  containerRef: Ref<HTMLElement | null>,
  messages: Ref<ChatMessage[]>,
) {
  const stickyDate = ref<string | null>(null);
  const isAtBottom = ref(true);
  const showChip = ref(false); // only show after first scroll away from top

  let rafId: number | null = null;
  let hideTimer: ReturnType<typeof setTimeout> | null = null;

  // ── Date label helpers ──────────────────────────────────────────
  function labelFor(dateStr: string | null | undefined): string | null {
    if (!dateStr) return null;
    const d = new Date(dateStr);
    const now = new Date();

    const startOf = (dt: Date) =>
      new Date(dt.getFullYear(), dt.getMonth(), dt.getDate()).getTime();

    const today = startOf(now);
    const yesterday = today - 86_400_000;
    const day = startOf(d);

    if (day === today) return "Today";
    if (day === yesterday) return "Yesterday";

    // Same year → omit year to save space
    if (d.getFullYear() === now.getFullYear()) {
      return d.toLocaleDateString(undefined, {
        day: "numeric",
        month: "short",
      });
    }

    return d.toLocaleDateString(undefined, {
      day: "numeric",
      month: "short",
      year: "numeric",
    });
  }

  // ── Core scroll handler ─────────────────────────────────────────
  function onScroll() {
    if (rafId !== null) return;
    rafId = requestAnimationFrame(() => {
      rafId = null;
      const container = containerRef.value;
      if (!container) return;

      // ── Is-at-bottom detection ──────────────────────────────────
      const threshold = 120; // px from bottom
      const distFromBottom =
        container.scrollHeight - container.scrollTop - container.clientHeight;
      isAtBottom.value = distFromBottom <= threshold;

      // ── Sticky date: find the separator closest to top ──────────
      const separators = Array.from(
        container.querySelectorAll<HTMLElement>("[data-date-separator]"),
      );

      if (!separators.length) {
        stickyDate.value = null;
        return;
      }

      const containerTop = container.getBoundingClientRect().top;
      let best: HTMLElement | null = null;
      let bestDelta = Infinity;

      for (const el of separators) {
        const rect = el.getBoundingClientRect();
        const delta = rect.top - containerTop; // positive = below top edge
        if (delta <= 8 && Math.abs(delta) < bestDelta) {
          best = el;
          bestDelta = Math.abs(delta);
        }
      }

      // If nothing is above the fold yet, use the first separator
      if (!best) best = separators[0];

      const label = best?.dataset.dateSeparator ?? null;
      if (label !== stickyDate.value) {
        stickyDate.value = label;
        showChip.value = true;
        // Auto-hide chip after 2s of no scrolling
        if (hideTimer) clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
          showChip.value = false;
        }, 2000);
      }
    });
  }

  // ── Scroll-to-bottom helper ─────────────────────────────────────
  function scrollToBottom(smooth = true) {
    const container = containerRef.value;
    if (!container) return;
    container.scrollTo({
      top: container.scrollHeight,
      behavior: smooth ? "smooth" : "instant",
    });
  }

  // ── Wire up / tear down ─────────────────────────────────────────
  let attached: HTMLElement | null = null;

  watch(
    containerRef,
    (el, prev) => {
      if (prev) prev.removeEventListener("scroll", onScroll);
      if (el) el.addEventListener("scroll", onScroll, { passive: true });
      attached = el;
    },
    { immediate: true },
  );

  // Recompute when message list changes (e.g. older messages loaded)
  watch(
    messages,
    () => {
      onScroll();
    },
    { flush: "post" },
  );

  onUnmounted(() => {
    if (attached) attached.removeEventListener("scroll", onScroll);
    if (rafId !== null) cancelAnimationFrame(rafId);
    if (hideTimer) clearTimeout(hideTimer);
  });

  return { stickyDate, showChip, isAtBottom, scrollToBottom };
}
