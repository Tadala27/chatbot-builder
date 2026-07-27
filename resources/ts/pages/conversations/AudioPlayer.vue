<script setup lang="ts">
import { ref, computed, onBeforeUnmount, watch } from "vue";

const props = defineProps<{
  src: string | null | undefined;
  isOutbound?: boolean;
  durationSeconds?: number | null;
}>();

const audioRef = ref<HTMLAudioElement | null>(null);
const isPlaying = ref(false);
const isLoading = ref(false);
const hasError = ref(false);
const currentTime = ref(0);
const duration = ref(props.durationSeconds ?? 0);

// Waveform — 30 bars, animated when playing
const BAR_COUNT = 30;
const waveHeights = ref<number[]>(generateWave());

function generateWave(): number[] {
  const bars: number[] = [];
  for (let i = 0; i < BAR_COUNT; i++) {
    const center = (i / BAR_COUNT - 0.5) * Math.PI * 2;
    const base = 0.35 + 0.55 * Math.abs(Math.sin(center * 1.3 + i * 0.4));
    bars.push(base);
  }
  return bars;
}

// Which bars are "played" (left of playhead)
const playedBars = computed(() => {
  const d = duration.value || 1;
  return Math.round((currentTime.value / d) * BAR_COUNT);
});

// Animate bars while playing
let animFrame: number | null = null;
let animPhase = 0;
const animatedHeights = ref<number[]>([...waveHeights.value]);

function animateBars() {
  animPhase += 0.08;
  animatedHeights.value = waveHeights.value.map((h, i) => {
    if (i < playedBars.value) return h;
    const wave = 0.85 + 0.15 * Math.sin(animPhase + i * 0.45);
    return h * wave;
  });
  animFrame = requestAnimationFrame(animateBars);
}

function startAnim() {
  if (animFrame !== null) return;
  animateBars();
}
function stopAnim() {
  if (animFrame !== null) {
    cancelAnimationFrame(animFrame);
    animFrame = null;
  }
  animatedHeights.value = [...waveHeights.value];
}

function formatTime(s: number): string {
  if (!s || !isFinite(s)) return "0:00";
  return `${Math.floor(s / 60)}:${String(Math.floor(s % 60)).padStart(2, "0")}`;
}

const progress = computed(() => {
  const d = duration.value || 1;
  return Math.min((currentTime.value / d) * 100, 100);
});

const displayTime = computed(() =>
  isPlaying.value || currentTime.value > 0
    ? formatTime(currentTime.value)
    : formatTime(duration.value),
);

async function togglePlay() {
  if (!audioRef.value || !props.src || hasError.value) return;
  if (isPlaying.value) {
    audioRef.value.pause();
  } else {
    isLoading.value = true;
    try {
      await audioRef.value.play();
    } catch {
      hasError.value = true;
    } finally {
      isLoading.value = false;
    }
  }
}

function onTimeUpdate() {
  if (audioRef.value) currentTime.value = audioRef.value.currentTime;
}
function onDurationChange() {
  if (audioRef.value && isFinite(audioRef.value.duration)) {
    duration.value = audioRef.value.duration;
  }
}
function onPlay() {
  isPlaying.value = true;
  startAnim();
}
function onPause() {
  isPlaying.value = false;
  stopAnim();
}
function onEnded() {
  isPlaying.value = false;
  currentTime.value = 0;
  stopAnim();
  if (audioRef.value) audioRef.value.currentTime = 0;
}
function onError() {
  isPlaying.value = false;
  isLoading.value = false;
  hasError.value = true;
  stopAnim();
}

function onScrub(e: MouseEvent | TouchEvent) {
  if (!audioRef.value || !duration.value) return;
  const bar = (e.currentTarget as HTMLElement).getBoundingClientRect();
  const x = "touches" in e ? e.touches[0].clientX : e.clientX;
  const frac = Math.max(0, Math.min(1, (x - bar.left) / bar.width));
  audioRef.value.currentTime = frac * duration.value;
}

watch(
  () => props.src,
  () => {
    stopAnim();
    isPlaying.value = false;
    currentTime.value = 0;
    hasError.value = false;
    duration.value = props.durationSeconds ?? 0;
    audioRef.value?.pause();
    audioRef.value?.load();
  },
);

onBeforeUnmount(() => {
  stopAnim();
  audioRef.value?.pause();
});
</script>

<template>
  <div class="ap" :class="isOutbound ? 'ap--out' : 'ap--in'">
    <audio
      ref="audioRef"
      :src="src ?? undefined"
      preload="metadata"
      @timeupdate="onTimeUpdate"
      @durationchange="onDurationChange"
      @play="onPlay"
      @pause="onPause"
      @ended="onEnded"
      @error="onError"
    />

    <!-- Play / pause -->
    <button
      type="button"
      class="ap__btn"
      :disabled="!src || hasError"
      :aria-label="isPlaying ? 'Pause' : 'Play'"
      @click="togglePlay"
    >
      <Transition name="ap-icon" mode="out-in">
        <VProgressCircular
          v-if="isLoading"
          key="loading"
          indeterminate
          size="14"
          width="2"
        />
        <svg
          v-else-if="isPlaying"
          key="pause"
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="currentColor"
        >
          <rect x="5" y="4" width="4" height="16" rx="1.5" />
          <rect x="15" y="4" width="4" height="16" rx="1.5" />
        </svg>
        <svg
          v-else
          key="play"
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="currentColor"
        >
          <path d="M6 4.5v15l13-7.5-13-7.5z" />
        </svg>
      </Transition>
    </button>

    <!-- Waveform scrubber -->
    <div class="ap__body">
      <div
        class="ap__wave"
        role="slider"
        :aria-valuenow="Math.round(currentTime)"
        :aria-valuemax="Math.round(duration)"
        aria-valuemin="0"
        @click="onScrub"
        @touchstart.prevent="onScrub"
      >
        <span
          v-for="(h, i) in animatedHeights"
          :key="i"
          class="ap__bar"
          :class="{ 'ap__bar--played': i < playedBars }"
          :style="{ height: h * 100 + '%' }"
        />
      </div>
      <div class="ap__time">{{ displayTime }}</div>
    </div>

    <!-- Error -->
    <VIcon v-if="hasError" icon="$alertCircleOutline" size="14" color="error" />
  </div>
</template>

<style scoped>
.ap {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  min-width: 220px;
  max-width: 300px;
  border-radius: 20px;
  user-select: none;
}
.ap--out {
  background: #ffffff;
}
.ap--in {
  background: #cdd1d8;
}

/* Play button */
.ap__btn {
  flex-shrink: 0;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition:
    transform 0.15s,
    opacity 0.15s;
}
.ap--out .ap__btn {
  background: #16191c;
  color: #fff;
}
.ap--in .ap__btn {
  background: rgba(0, 0, 0, 0.22);
  color: #fff;
}
.ap__btn:disabled {
  opacity: 0.4;
  cursor: default;
}
.ap__btn:not(:disabled):hover {
  transform: scale(1.08);
  opacity: 0.85;
}
.ap__btn:not(:disabled):active {
  transform: scale(0.95);
}

/* Icon transition */
.ap-icon-enter-active,
.ap-icon-leave-active {
  transition:
    opacity 0.1s,
    transform 0.1s;
}
.ap-icon-enter-from {
  opacity: 0;
  transform: scale(0.7);
}
.ap-icon-leave-to {
  opacity: 0;
  transform: scale(0.7);
}

/* Body */
.ap__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

/* Waveform */
.ap__wave {
  display: flex;
  align-items: center;
  gap: 2px;
  height: 28px;
  cursor: pointer;
  padding: 2px 0;
}
.ap__bar {
  flex: 1;
  min-height: 3px;
  border-radius: 2px;
  background: rgba(0, 0, 0, 0.18);
  transition:
    height 0.08s ease-out,
    background 0.15s;
  will-change: height;
}
.ap--out .ap__bar {
  background: rgba(0, 0, 0, 0.18);
}
.ap--out .ap__bar--played {
  background: #16191c;
}
.ap--in .ap__bar {
  background: rgba(0, 0, 0, 0.2);
}
.ap--in .ap__bar--played {
  background: #4a5568;
}

/* Time */
.ap__time {
  font-size: 11px;
  font-variant-numeric: tabular-nums;
  opacity: 0.55;
  letter-spacing: 0.01em;
}
</style>
