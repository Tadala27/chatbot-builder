<script setup lang="ts">
import { ref, onBeforeUnmount } from "vue";

export interface VoiceRecordingPayload {
  file: File;
  durationSeconds: number;
}

const emit = defineEmits<{
  send: [payload: VoiceRecordingPayload];
  error: [message: string];
}>();

// ── State ──────────────────────────────────────────────────────────
type RecordState = "idle" | "recording" | "preview";

const state = ref<RecordState>("idle");
const elapsedSecs = ref(0);
const previewUrl = ref<string | null>(null);
const durationSecs = ref(0);

let mediaRecorder: MediaRecorder | null = null;
let recordedChunks: Blob[] = [];
let stream: MediaStream | null = null;
let timerInterval: ReturnType<typeof setInterval> | null = null;

// ── Preferred MIME — OGG/OPUS is what WhatsApp expects for voice notes
// Fall back to whatever the browser supports.
function preferredMime(): string {
  const candidates = [
    "audio/ogg;codecs=opus",
    "audio/ogg",
    "audio/webm;codecs=opus",
    "audio/webm",
    "audio/mp4",
  ];
  return candidates.find((m) => MediaRecorder.isTypeSupported(m)) ?? "";
}

function formatTime(s: number): string {
  const m = Math.floor(s / 60);
  return `${m}:${(s % 60).toString().padStart(2, "0")}`;
}

// ── Start ──────────────────────────────────────────────────────────
async function startRecording() {
  if (!navigator.mediaDevices?.getUserMedia) {
    emit("error", "Your browser doesn't support voice recording.");
    return;
  }

  try {
    stream = await navigator.mediaDevices.getUserMedia({
      audio: true,
      video: false,
    });
  } catch (e: any) {
    const msg =
      e?.name === "NotAllowedError"
        ? "Microphone access was denied. Please allow it in your browser settings."
        : "Could not access your microphone.";
    emit("error", msg);
    return;
  }

  recordedChunks = [];
  const mime = preferredMime();
  mediaRecorder = new MediaRecorder(
    stream,
    mime ? { mimeType: mime } : undefined,
  );

  mediaRecorder.ondataavailable = (e) => {
    if (e.data.size > 0) recordedChunks.push(e.data);
  };

  mediaRecorder.onstop = () => {
    const blob = new Blob(recordedChunks, {
      type: mediaRecorder?.mimeType ?? "audio/ogg",
    });
    const ext = (mediaRecorder?.mimeType ?? "").includes("ogg")
      ? "ogg"
      : (mediaRecorder?.mimeType ?? "").includes("mp4")
        ? "m4a"
        : "webm";
    durationSecs.value = elapsedSecs.value;
    previewUrl.value = URL.createObjectURL(blob);
    state.value = "preview";
    stopStream();
  };

  mediaRecorder.start(200); // collect data every 200ms
  elapsedSecs.value = 0;
  state.value = "recording";

  timerInterval = setInterval(() => {
    elapsedSecs.value++;
    // Auto-stop at WhatsApp's 16MB / ~16min limit; 10 min is a safe cap
    if (elapsedSecs.value >= 600) stopRecording();
  }, 1000);
}

// ── Stop recording → move to preview ──────────────────────────────
function stopRecording() {
  clearInterval(timerInterval!);
  timerInterval = null;
  mediaRecorder?.stop();
}

// ── Cancel — from recording or preview ────────────────────────────
function cancel() {
  clearInterval(timerInterval!);
  timerInterval = null;
  mediaRecorder?.stop();
  mediaRecorder = null;
  recordedChunks = [];
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value);
    previewUrl.value = null;
  }
  stopStream();
  elapsedSecs.value = 0;
  durationSecs.value = 0;
  state.value = "idle";
}

// ── Send from preview ──────────────────────────────────────────────
function sendRecording() {
  if (!previewUrl.value || !recordedChunks.length) return;

  const mimeType = mediaRecorder?.mimeType ?? "audio/ogg";
  const ext = mimeType.includes("ogg")
    ? "ogg"
    : mimeType.includes("mp4")
      ? "m4a"
      : "webm";
  const blob = new Blob(recordedChunks, { type: mimeType });
  const file = new File([blob], `voice-note-${Date.now()}.${ext}`, {
    type: mimeType,
  });

  emit("send", { file, durationSeconds: durationSecs.value });

  URL.revokeObjectURL(previewUrl.value);
  previewUrl.value = null;
  recordedChunks = [];
  durationSecs.value = 0;
  elapsedSecs.value = 0;
  state.value = "idle";
}

function stopStream() {
  stream?.getTracks().forEach((t) => t.stop());
  stream = null;
}

onBeforeUnmount(() => {
  clearInterval(timerInterval!);
  mediaRecorder?.stop();
  stopStream();
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value);
});
</script>

<template>
  <!-- ── IDLE: just the mic button ── -->
  <button
    v-if="state === 'idle'"
    type="button"
    class="vr-mic-btn"
    title="Record voice note"
    aria-label="Record voice note"
    @click="startRecording"
  >
    <VIcon icon="$microphone" size="18" />
  </button>

  <!-- ── RECORDING ── -->
  <div v-else-if="state === 'recording'" class="vr-bar vr-bar--recording">
    <button
      type="button"
      class="vr-icon-btn vr-icon-btn--cancel"
      title="Cancel"
      @click="cancel"
    >
      <VIcon icon="$trashCan" size="16" />
    </button>

    <span class="vr-dot" />
    <span class="vr-timer">{{ formatTime(elapsedSecs) }}</span>

    <button
      type="button"
      class="vr-icon-btn vr-icon-btn--stop"
      title="Stop recording"
      @click="stopRecording"
    >
      <VIcon icon="$stop" size="16" />
    </button>
  </div>

  <!-- ── PREVIEW ── -->
  <div v-else-if="state === 'preview'" class="vr-bar vr-bar--preview">
    <button
      type="button"
      class="vr-icon-btn vr-icon-btn--cancel"
      title="Discard"
      @click="cancel"
    >
      <VIcon icon="$trashCan" size="16" />
    </button>

    <audio
      v-if="previewUrl"
      :src="previewUrl"
      controls
      class="vr-preview-audio"
    />

    <button
      type="button"
      class="vr-icon-btn vr-icon-btn--send"
      title="Send voice note"
      @click="sendRecording"
    >
      <VIcon icon="$sendVariant" size="16" />
    </button>
  </div>
</template>

<style scoped>
/* ── Mic button (idle) ─────────────────────────────────────────── */
.vr-mic-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: var(--color-text-secondary, #abb1ae);
  cursor: pointer;
  padding: 0;
  transition:
    background 0.12s,
    color 0.12s;
  font-family: inherit;
}
.vr-mic-btn:hover {
  background: rgba(0, 0, 0, 0.05);
  color: var(--color-text-primary, #16191c);
}

/* ── Shared bar ────────────────────────────────────────────────── */
.vr-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
}

/* ── Recording bar ─────────────────────────────────────────────── */
.vr-bar--recording {
  background: rgba(239, 68, 68, 0.06);
  border-radius: 12px;
  padding: 4px 8px;
}
.vr-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #ef4444;
  flex-shrink: 0;
  animation: vr-pulse 1s ease-in-out infinite;
}
@keyframes vr-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.3;
  }
}
.vr-timer {
  flex: 1;
  font-size: 13px;
  font-variant-numeric: tabular-nums;
  color: #ef4444;
  font-weight: 500;
}

/* ── Preview bar ───────────────────────────────────────────────── */
.vr-bar--preview {
  padding: 2px 4px;
}
.vr-preview-audio {
  flex: 1;
  min-width: 0;
  height: 28px;
}

/* ── Shared icon buttons ───────────────────────────────────────── */
.vr-icon-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border: none;
  border-radius: 50%;
  cursor: pointer;
  padding: 0;
  flex-shrink: 0;
  transition: opacity 0.12s;
  font-family: inherit;
}
.vr-icon-btn--cancel {
  background: rgba(239, 68, 68, 0.1);
  color: #ef4444;
}
.vr-icon-btn--stop {
  background: #ef4444;
  color: #fff;
}
.vr-icon-btn--send {
  background: #16191c;
  color: #fff;
}
.vr-icon-btn:hover {
  opacity: 0.8;
}
</style>
