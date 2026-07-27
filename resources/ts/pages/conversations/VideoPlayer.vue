<script setup lang="ts">
import { ref, onBeforeUnmount } from "vue";

const props = defineProps<{
  src: string | null | undefined;
  caption?: string | null;
  isOutbound?: boolean;
}>();

// State machine: thumbnail → downloading → viewer
type State = "thumbnail" | "downloading" | "viewer";
const state = ref<State>("thumbnail");
const progress = ref(0);
const videoRef = ref<HTMLVideoElement | null>(null);
const blobUrl = ref<string | null>(null);
const hasError = ref(false);
let abortCtrl: AbortController | null = null;

async function startDownload() {
  if (!props.src || state.value !== "thumbnail") return;

  state.value = "downloading";
  progress.value = 0;
  hasError.value = false;
  abortCtrl = new AbortController();

  try {
    const response = await fetch(props.src, { signal: abortCtrl.signal });

    if (!response.ok) throw new Error(`HTTP ${response.status}`);

    const contentLength = response.headers.get("content-length");
    const total = contentLength ? parseInt(contentLength, 10) : 0;
    const reader = response.body!.getReader();
    const chunks: Uint8Array[] = [];
    let received = 0;

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(value);
      received += value.length;
      if (total) progress.value = Math.round((received / total) * 100);
    }

    const blob = new Blob(chunks, { type: "video/mp4" });
    blobUrl.value = URL.createObjectURL(blob);
    state.value = "viewer";
  } catch (e: any) {
    if (e?.name === "AbortError") {
      state.value = "thumbnail";
    } else {
      hasError.value = true;
      state.value = "thumbnail";
    }
  }
}

function cancelDownload() {
  abortCtrl?.abort();
  state.value = "thumbnail";
  progress.value = 0;
}

function closeViewer() {
  videoRef.value?.pause();
  state.value = "thumbnail";
  // Keep blobUrl cached — next open is instant
}

onBeforeUnmount(() => {
  abortCtrl?.abort();
  if (blobUrl.value) URL.revokeObjectURL(blobUrl.value);
});
</script>

<template>
  <div class="vp">
    <!-- ── Thumbnail state ─────────────────────────────────────── -->
    <div
      v-if="state === 'thumbnail' || state === 'downloading'"
      class="vp__thumb-wrap"
    >
      <!-- Dark overlay gradient -->
      <div class="vp__thumb-overlay" />

      <!-- Play / cancel button -->
      <button
        v-if="state === 'thumbnail'"
        type="button"
        class="vp__play-btn"
        :disabled="!src"
        aria-label="Play video"
        @click="startDownload"
      >
        <svg width="22" height="22" viewBox="0 0 24 24" fill="white">
          <path d="M6 4.5v15l13-7.5-13-7.5z" />
        </svg>
      </button>

      <!-- Download progress ring -->
      <div v-else class="vp__progress-wrap">
        <svg class="vp__ring" width="52" height="52" viewBox="0 0 52 52">
          <circle class="vp__ring-bg" cx="26" cy="26" r="22" />
          <circle
            class="vp__ring-fill"
            cx="26"
            cy="26"
            r="22"
            :stroke-dashoffset="138.2 - (138.2 * progress) / 100"
          />
        </svg>
        <span class="vp__progress-pct">{{ progress }}%</span>
        <button
          type="button"
          class="vp__cancel-btn"
          aria-label="Cancel"
          @click="cancelDownload"
        >
          <svg width="12" height="12" viewBox="0 0 24 24" fill="white">
            <path
              d="M18 6L6 18M6 6l12 12"
              stroke="white"
              stroke-width="2.5"
              stroke-linecap="round"
            />
          </svg>
        </button>
      </div>

      <!-- Error badge -->
      <div v-if="hasError" class="vp__error-badge">
        <VIcon icon="$alertCircleOutline" size="14" />
        Failed to load
      </div>

      <!-- Duration chip placeholder -->
      <div class="vp__chip">
        <svg
          width="11"
          height="11"
          viewBox="0 0 24 24"
          fill="white"
          style="flex-shrink: 0"
        >
          <path d="M15 10l-9 5.196V4.804L15 10z" />
          <rect
            x="1"
            y="3"
            width="22"
            height="18"
            rx="3"
            stroke="white"
            stroke-width="2"
            fill="none"
          />
        </svg>
        Video
      </div>
    </div>

    <!-- ── Viewer (fullscreen overlay) ──────────────────────────── -->
    <Teleport to="body">
      <Transition name="vp-viewer">
        <div
          v-if="state === 'viewer'"
          class="vp__viewer"
          @click.self="closeViewer"
        >
          <button
            type="button"
            class="vp__close-btn"
            aria-label="Close"
            @click="closeViewer"
          >
            <VIcon icon="$close" size="22" color="white" />
          </button>
          <video
            ref="videoRef"
            :src="blobUrl ?? undefined"
            class="vp__video"
            controls
            autoplay
            playsinline
            @click.stop
          />
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
/* ── Thumbnail wrapper ───────────────────────────────────────── */
.vp {
  width: 100%;
  position: relative;
}
.vp__thumb-wrap {
  position: relative;
  width: 100%;
  aspect-ratio: 4 / 3;
  background: #1a1a1a;
  overflow: hidden;
  cursor: pointer;
  border-radius: inherit;
}
.vp__thumb-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to bottom,
    rgba(0, 0, 0, 0.05) 0%,
    rgba(0, 0, 0, 0.35) 100%
  );
  z-index: 1;
}

/* Play button */
.vp__play-btn {
  position: absolute;
  inset: 0;
  margin: auto;
  width: 52px;
  height: 52px;
  border-radius: 50%;
  border: none;
  background: rgba(0, 0, 0, 0.55);
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 2;
  transition:
    transform 0.15s,
    background 0.15s;
}
.vp__play-btn:hover {
  background: rgba(0, 0, 0, 0.72);
  transform: scale(1.08);
}
.vp__play-btn:active {
  transform: scale(0.95);
}
.vp__play-btn:disabled {
  opacity: 0.4;
  cursor: default;
}

/* Download ring */
.vp__progress-wrap {
  position: absolute;
  inset: 0;
  margin: auto;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 2;
}
.vp__ring {
  position: absolute;
  transform: rotate(-90deg);
}
.vp__ring-bg {
  fill: none;
  stroke: rgba(255, 255, 255, 0.25);
  stroke-width: 3;
}
.vp__ring-fill {
  fill: none;
  stroke: #fff;
  stroke-width: 3;
  stroke-linecap: round;
  stroke-dasharray: 138.2;
  transition: stroke-dashoffset 0.2s linear;
}
.vp__progress-pct {
  font-size: 10px;
  font-weight: 700;
  color: #fff;
  font-variant-numeric: tabular-nums;
  position: relative;
  z-index: 1;
  line-height: 1;
}
.vp__cancel-btn {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  border: none;
  background: rgba(0, 0, 0, 0.6);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  z-index: 3;
}

/* Error badge */
.vp__error-badge {
  position: absolute;
  bottom: 8px;
  left: 50%;
  transform: translateX(-50%);
  background: rgba(220, 38, 38, 0.85);
  color: #fff;
  font-size: 11px;
  padding: 3px 8px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
  z-index: 2;
  white-space: nowrap;
}

/* Duration chip */
.vp__chip {
  position: absolute;
  bottom: 8px;
  right: 8px;
  background: rgba(0, 0, 0, 0.55);
  color: #fff;
  font-size: 10.5px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
  z-index: 2;
}

/* ── Fullscreen viewer ───────────────────────────────────────── */
.vp__viewer {
  position: fixed;
  inset: 0;
  z-index: 9999;
  background: rgba(0, 0, 0, 0.94);
  display: flex;
  align-items: center;
  justify-content: center;
}
.vp__close-btn {
  position: absolute;
  top: 16px;
  right: 16px;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: none;
  background: rgba(255, 255, 255, 0.12);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: background 0.15s;
  z-index: 1;
}
.vp__close-btn:hover {
  background: rgba(255, 255, 255, 0.22);
}

.vp__video {
  max-width: min(92vw, 960px);
  max-height: 88vh;
  border-radius: 8px;
  outline: none;
}

/* Viewer transition */
.vp-viewer-enter-active {
  transition:
    opacity 0.2s ease,
    transform 0.2s ease;
}
.vp-viewer-leave-active {
  transition:
    opacity 0.18s ease,
    transform 0.18s ease;
}
.vp-viewer-enter-from {
  opacity: 0;
  transform: scale(0.96);
}
.vp-viewer-leave-to {
  opacity: 0;
  transform: scale(0.96);
}
</style>
