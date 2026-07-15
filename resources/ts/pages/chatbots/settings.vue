<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from "vue";
import OperatingHoursEditor from "@/components/OperatingHoursEditor.vue";
import BotDialogSlotEditor from "@/components/BotDialogSlotEditor.vue";
import {
  useBotConfiguration,
  VALIDATION_BOUNDS,
  PURPOSE_SLOTS,
  type BotConfiguration,
  type BotDialogFormInput,
  type ReservedPurpose,
} from "@/composables/botSettings";

const props = defineProps<{ botId: string }>();

const {
  configuration,
  dialogsByPurpose,
  isLoading,
  isSaving,
  errors,
  load,
  save,
  saveBotDialog,
  deleteBotDialog,
} = useBotConfiguration(props.botId);

type KeywordField =
  | "home_keywords"
  | "back_keywords"
  | "handover_keywords"
  | "opt_out_keywords"
  | "opt_in_keywords";

const savedNotice = ref(false);
const keywordDraft = ref<Record<KeywordField, string>>({
  home_keywords: "",
  back_keywords: "",
  handover_keywords: "",
  opt_out_keywords: "",
  opt_in_keywords: "",
});

onMounted(async () => {
  await load();
  snapshot();
});

const savedSnapshot = ref<string | null>(null);
function snapshot() {
  savedSnapshot.value = configuration.value
    ? JSON.stringify(configuration.value)
    : null;
}
const isDirty = computed(() => {
  if (!configuration.value || savedSnapshot.value === null) return false;
  return JSON.stringify(configuration.value) !== savedSnapshot.value;
});

/** Purpose slots visible given current feature toggles. */
const visibleSlots = computed(() => {
  if (!configuration.value) return [];
  return PURPOSE_SLOTS.filter((slot) => {
    if (slot.alwaysShow || !slot.gatedBy) return true;
    return (configuration.value as any)[slot.gatedBy] === true;
  });
});

function fieldError(field: string): string | null {
  return errors.value[field]?.[0] ?? null;
}

function addKeyword(field: KeywordField) {
  if (!configuration.value) return;
  const parts = keywordDraft.value[field]
    .split(",")
    .map((s) => s.trim())
    .filter(Boolean);
  for (const part of parts) {
    if (!configuration.value[field].includes(part)) {
      configuration.value[field].push(part);
    }
  }
  keywordDraft.value[field] = "";
}

function removeKeyword(field: KeywordField, index: number) {
  configuration.value?.[field].splice(index, 1);
}

async function handleSave() {
  savedNotice.value = false;
  const ok = await save();
  if (ok) {
    savedNotice.value = true;
    snapshot();
    setTimeout(() => (savedNotice.value = false), 3000);
  }
}

// ── BotDialog slot editing ─────────────────────────────────────────────────

const editingPurpose = ref<ReservedPurpose | null>(null);

function openSlotEditor(purpose: ReservedPurpose) {
  editingPurpose.value = purpose;
}

function closeSlotEditor() {
  editingPurpose.value = null;
  errors.value = {};
}

async function handleSlotSave(input: BotDialogFormInput) {
  const saved = await saveBotDialog(input);
  if (saved) closeSlotEditor();
}

async function handleSlotDelete(purpose: ReservedPurpose) {
  await deleteBotDialog(purpose);
}

// ── Jump nav ───────────────────────────────────────────────────────────────

const sections = [
  { id: "dialogs", label: "Dialogs" },
  { id: "invalid", label: "Invalid input" },
  { id: "retry", label: "Retry" },
  { id: "handover", label: "Handover" },
  { id: "navigation", label: "Keywords" },
  { id: "subscription", label: "Subscription" },
  { id: "session", label: "Session" },
];

const activeSection = ref(sections[0].id);
let observer: IntersectionObserver | null = null;

onMounted(() => {
  observer = new IntersectionObserver(
    (entries) => {
      const visible = entries
        .filter((e) => e.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible[0]) activeSection.value = visible[0].target.id;
    },
    { rootMargin: "-96px 0px -70% 0px", threshold: 0 },
  );
  sections.forEach((s) => {
    const el = document.getElementById(s.id);
    if (el) observer?.observe(el);
  });
});

onUnmounted(() => observer?.disconnect());

function scrollToSection(id: string) {
  document
    .getElementById(id)
    ?.scrollIntoView({ behavior: "smooth", block: "start" });
}
</script>

<template>
  <div class="settings-page">
    <header class="settings-page__header">
      <div>
        <h1 class="settings-page__title">Bot settings</h1>
        <p class="settings-page__subtitle">
          Configure how this bot greets contacts, handles confusion, and hands
          off to humans.
        </p>
      </div>
      <div class="settings-page__actions">
        <Transition name="fade">
          <span v-if="savedNotice" class="settings-page__saved">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
              <path
                d="M20 6L9 17l-5-5"
                stroke="currentColor"
                stroke-width="3"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
            Saved
          </span>
          <span v-else-if="isDirty" class="settings-page__unsaved"
            >Unsaved changes</span
          >
        </Transition>
        <button
          type="button"
          class="settings-page__save"
          :disabled="isSaving || !configuration || !isDirty"
          @click="handleSave"
        >
          {{ isSaving ? "Saving…" : "Save changes" }}
        </button>
      </div>
    </header>

    <p v-if="isLoading" class="settings-page__loading">
      Loading configuration…
    </p>

    <div v-else-if="configuration" class="settings-page__layout">
      <!-- Jump nav -->
      <nav class="settings-nav">
        <button
          v-for="s in sections"
          :key="s.id"
          type="button"
          class="settings-nav__item"
          :class="{ 'settings-nav__item--active': activeSection === s.id }"
          @click="scrollToSection(s.id)"
        >
          {{ s.label }}
        </button>
      </nav>

      <div class="settings-page__body">
        <!-- ── Dialogs ─────────────────────────────────────────────────── -->
        <section id="dialogs" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--indigo">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M4 6h16M4 12h10M4 18h16"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Dialogs</h2>
              <p class="card__hint">
                Each slot defines what the bot says at a specific moment. The
                purpose controls <em>when</em> each dialog fires — you set the
                content. Slots marked optional do nothing if left empty.
              </p>
            </div>
          </div>

          <div class="slot-list">
            <div v-for="slot in visibleSlots" :key="slot.purpose" class="slot">
              <div class="slot__info">
                <div class="slot__label">{{ slot.label }}</div>
                <div class="slot__hint">{{ slot.hint }}</div>
              </div>
              <div class="slot__status">
                <span
                  v-if="dialogsByPurpose[slot.purpose]"
                  class="slot__badge slot__badge--set"
                >
                  {{
                    dialogsByPurpose[slot.purpose]!.kind === "message"
                      ? "Message"
                      : dialogsByPurpose[slot.purpose]!.kind === "buttons"
                        ? "Buttons"
                        : "List"
                  }}
                </span>
                <span v-else class="slot__badge slot__badge--empty"
                  >Not set</span
                >
              </div>
              <div class="slot__actions">
                <button
                  type="button"
                  class="slot__btn"
                  @click="openSlotEditor(slot.purpose)"
                >
                  {{ dialogsByPurpose[slot.purpose] ? "Edit" : "Set up" }}
                </button>
                <button
                  v-if="dialogsByPurpose[slot.purpose]"
                  type="button"
                  class="slot__btn slot__btn--danger"
                  @click="handleSlotDelete(slot.purpose)"
                >
                  Remove
                </button>
              </div>
            </div>
          </div>
        </section>

        <!-- ── Invalid input ──────────────────────────────────────────── -->
        <section id="invalid" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Invalid input</h2>
              <p class="card__hint">
                Controls what happens when the bot doesn't understand a
                contact's message. Set the <em>Invalid input</em> and
                <em>Flow invalid input</em> dialogs above.
              </p>
            </div>
          </div>

          <div class="field">
            <label class="field__label">Prefix message (optional)</label>
            <p class="field__hint">
              Plain text sent immediately before the invalid input dialog fires.
              Leave blank to show the dialog directly without a prefix.
            </p>
            <textarea
              v-model="configuration.invalid_input_message"
              class="field__input field__textarea"
              maxlength="1000"
              rows="2"
            />
            <span
              v-if="fieldError('invalid_input_message')"
              class="field__error"
            >
              {{ fieldError("invalid_input_message") }}
            </span>
          </div>

          <div class="field">
            <label class="field__label">Max invalid attempts</label>
            <p class="field__hint">
              After this many consecutive failures, exit to the main menu
              instead of repeating the invalid input dialog.
            </p>
            <input
              type="number"
              v-model.number="configuration.max_invalid_attempts"
              :min="VALIDATION_BOUNDS.maxInvalidAttempts.min"
              :max="VALIDATION_BOUNDS.maxInvalidAttempts.max"
              class="field__input field__input--narrow"
            />
            <span
              v-if="fieldError('max_invalid_attempts')"
              class="field__error"
            >
              {{ fieldError("max_invalid_attempts") }}
            </span>
          </div>
        </section>

        <!-- ── Retry ──────────────────────────────────────────────────── -->
        <section id="retry" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--teal">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M21 12a9 9 0 11-3.51-7.14M21 4v5h-5"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Retry</h2>
              <p class="card__hint">
                Nudge a contact who's gone quiet mid-conversation.
              </p>
            </div>
          </div>

          <label class="toggle">
            <input type="checkbox" v-model="configuration.retry_enabled" />
            <span>Enable retry</span>
          </label>

          <Transition name="expand">
            <div v-if="configuration.retry_enabled" class="toggle-content">
              <p class="field__hint mb-3">
                Set the <em>Retry</em> dialog in the Dialogs section above to
                customise what the bot sends as the nudge.
              </p>
              <div class="field-row">
                <div class="field">
                  <label class="field__label">Retry after (minutes)</label>
                  <input
                    type="number"
                    v-model.number="configuration.retry_after_minutes"
                    :min="VALIDATION_BOUNDS.retryAfterMinutes.min"
                    :max="VALIDATION_BOUNDS.retryAfterMinutes.max"
                    class="field__input field__input--narrow"
                  />
                  <span
                    v-if="fieldError('retry_after_minutes')"
                    class="field__error"
                  >
                    {{ fieldError("retry_after_minutes") }}
                  </span>
                </div>
                <div class="field">
                  <label class="field__label">Max retry attempts</label>
                  <input
                    type="number"
                    v-model.number="configuration.max_retry_attempts"
                    :min="VALIDATION_BOUNDS.maxRetryAttempts.min"
                    :max="VALIDATION_BOUNDS.maxRetryAttempts.max"
                    class="field__input field__input--narrow"
                  />
                  <span
                    v-if="fieldError('max_retry_attempts')"
                    class="field__error"
                  >
                    {{ fieldError("max_retry_attempts") }}
                  </span>
                </div>
              </div>
            </div>
          </Transition>
        </section>

        <!-- ── Handover ───────────────────────────────────────────────── -->
        <section id="handover" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--rose">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m5-3.13a4 4 0 100-8 4 4 0 000 8zm6 0a4 4 0 100-8"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Handover</h2>
              <p class="card__hint">
                Route conversations to a human agent. Set
                <em>Handover — in hours</em> and
                <em>Handover — off hours</em> dialogs above to customise the
                messages.
              </p>
            </div>
          </div>

          <label class="toggle">
            <input type="checkbox" v-model="configuration.handover_enabled" />
            <span>Enable handover</span>
          </label>

          <Transition name="expand">
            <div v-if="configuration.handover_enabled" class="toggle-content">
              <div class="field">
                <label class="field__label">Unavailable fallback message</label>
                <p class="field__hint">
                  Plain text fallback shown if handover is triggered but no
                  matching dialog is configured for that time window.
                </p>
                <textarea
                  v-model="configuration.handover_unavailable_message"
                  class="field__input field__textarea"
                  maxlength="1000"
                  rows="2"
                />
                <span
                  v-if="fieldError('handover_unavailable_message')"
                  class="field__error"
                >
                  {{ fieldError("handover_unavailable_message") }}
                </span>
              </div>
              <div class="field">
                <label class="field__label">Auto-resolve after (minutes)</label>
                <p class="field__hint">
                  If no agent responds within this window, the conversation is
                  automatically resolved.
                </p>
                <input
                  type="number"
                  v-model.number="configuration.auto_resolve_after_minutes"
                  :min="VALIDATION_BOUNDS.autoResolveAfterMinutes.min"
                  :max="VALIDATION_BOUNDS.autoResolveAfterMinutes.max"
                  class="field__input field__input--narrow"
                />
                <span
                  v-if="fieldError('auto_resolve_after_minutes')"
                  class="field__error"
                >
                  {{ fieldError("auto_resolve_after_minutes") }}
                </span>
              </div>
              <div class="field">
                <label class="field__label">Handover keywords</label>
                <p class="field__hint">
                  A contact typing one of these triggers handover directly, any
                  time.
                </p>
                <div class="tags">
                  <span
                    v-for="(kw, i) in configuration.handover_keywords"
                    :key="kw"
                    class="tag"
                  >
                    {{ kw }}
                    <button
                      type="button"
                      class="tag__remove"
                      @click="removeKeyword('handover_keywords', i)"
                    >
                      ×
                    </button>
                  </span>
                  <input
                    v-model="keywordDraft.handover_keywords"
                    type="text"
                    placeholder="Add keyword, press Enter"
                    class="tags__input"
                    @keydown.enter.prevent="addKeyword('handover_keywords')"
                  />
                </div>
              </div>
              <div class="field">
                <label class="field__label">Operating hours</label>
                <p class="field__hint">
                  Determines whether a handover counts as in-hours or off-hours.
                </p>
                <OperatingHoursEditor v-model="configuration.operating_hours" />
              </div>
            </div>
          </Transition>
        </section>

        <!-- ── Navigation keywords ────────────────────────────────────── -->
        <section id="navigation" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--indigo">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Navigation keywords</h2>
              <p class="card__hint">
                Words a contact can type at any point to navigate.
              </p>
            </div>
          </div>

          <div
            v-for="field in ['home_keywords', 'back_keywords'] as const"
            :key="field"
            class="field"
          >
            <label class="field__label">{{
              field === "home_keywords" ? "Home keywords" : "Back keywords"
            }}</label>
            <div class="tags">
              <span
                v-for="(kw, i) in configuration[field]"
                :key="kw"
                class="tag"
              >
                {{ kw }}
                <button
                  type="button"
                  class="tag__remove"
                  @click="removeKeyword(field, i)"
                >
                  ×
                </button>
              </span>
              <input
                v-model="keywordDraft[field]"
                type="text"
                placeholder="Add keyword, press Enter"
                class="tags__input"
                @keydown.enter.prevent="addKeyword(field)"
              />
            </div>
          </div>
        </section>

        <!-- ── Subscription keywords ──────────────────────────────────── -->
        <section id="subscription" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--teal">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <path
                  d="M22 6l-10 7L2 6m0 0v12h20V6"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div>
              <h2 class="card__title">Subscription keywords</h2>
              <p class="card__hint">
                Words a contact can send to opt out of or back into messaging.
              </p>
            </div>
          </div>

          <div
            v-for="field in ['opt_out_keywords', 'opt_in_keywords'] as const"
            :key="field"
            class="field"
          >
            <label class="field__label">{{
              field === "opt_out_keywords"
                ? "Opt-out keywords"
                : "Opt-in keywords"
            }}</label>
            <div class="tags">
              <span
                v-for="(kw, i) in configuration[field]"
                :key="kw"
                class="tag"
              >
                {{ kw }}
                <button
                  type="button"
                  class="tag__remove"
                  @click="removeKeyword(field, i)"
                >
                  ×
                </button>
              </span>
              <input
                v-model="keywordDraft[field]"
                type="text"
                placeholder="Add keyword, press Enter"
                class="tags__input"
                @keydown.enter.prevent="addKeyword(field)"
              />
            </div>
          </div>
        </section>

        <!-- ── Session ────────────────────────────────────────────────── -->
        <section id="session" class="card">
          <div class="card__header">
            <span class="card__icon card__icon--amber">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                <circle
                  cx="12"
                  cy="12"
                  r="9"
                  stroke="currentColor"
                  stroke-width="2"
                />
                <path
                  d="M12 7v5l3 3"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>
            </span>
            <div><h2 class="card__title">Session</h2></div>
          </div>
          <div class="field">
            <label class="field__label">Session timeout (minutes)</label>
            <p class="field__hint">
              How long a conversation can sit idle before it's considered
              abandoned.
            </p>
            <input
              type="number"
              v-model.number="configuration.session_timeout_minutes"
              :min="VALIDATION_BOUNDS.sessionTimeoutMinutes.min"
              :max="VALIDATION_BOUNDS.sessionTimeoutMinutes.max"
              class="field__input field__input--narrow"
            />
            <span
              v-if="fieldError('session_timeout_minutes')"
              class="field__error"
            >
              {{ fieldError("session_timeout_minutes") }}
            </span>
          </div>
        </section>
      </div>
    </div>

    <!-- BotDialog slot editor (shared modal) -->
    <BotDialogSlotEditor
      v-if="editingPurpose"
      :purpose="editingPurpose"
      :existing="dialogsByPurpose[editingPurpose] ?? null"
      :errors="errors"
      :is-saving="isSaving"
      @save="handleSlotSave"
      @close="closeSlotEditor"
    />
  </div>
</template>

<style scoped>
.settings-page {
  --color-border: #ececec;
  --color-text-primary: #16191c;
  --color-text-secondary: #8d9a93;
  --color-online: #1fc06b;
  --color-warn: #d99a2b;
  font-family:
    "Inter",
    -apple-system,
    BlinkMacSystemFont,
    "Segoe UI",
    sans-serif;
  margin: 0 auto;
}
.settings-page * {
  box-sizing: border-box;
}

.settings-page__header {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  background: #fff;
  padding: 12px 0 20px;
  margin-bottom: 8px;
  border-bottom: 1px solid var(--color-border);
}
.settings-page__title {
  font-size: 24px;
  font-weight: 800;
  color: var(--color-text-primary);
  margin: 0 0 4px;
}
.settings-page__subtitle {
  font-size: 13px;
  color: var(--color-text-secondary);
  margin: 0;
  max-width: 480px;
}
.settings-page__actions {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}
.settings-page__saved {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 13px;
  color: var(--color-online);
  font-weight: 600;
}
.settings-page__unsaved {
  font-size: 12.5px;
  color: var(--color-warn);
  font-weight: 600;
}
.settings-page__save {
  background: var(--color-text-primary);
  color: #fff;
  border: none;
  border-radius: 10px;
  padding: 10px 18px;
  font-size: 13.5px;
  font-weight: 600;
  cursor: pointer;
}
.settings-page__save:disabled {
  opacity: 0.4;
  cursor: default;
}
.settings-page__loading {
  color: var(--color-text-secondary);
  font-size: 14px;
}
.settings-page__layout {
  display: grid;
  grid-template-columns: 168px 1fr;
  gap: 32px;
  align-items: start;
}

.settings-nav {
  position: sticky;
  top: 92px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.settings-nav__item {
  text-align: left;
  background: transparent;
  border: none;
  border-radius: 8px;
  padding: 7px 10px;
  font-size: 12.5px;
  font-weight: 500;
  color: var(--color-text-secondary);
  cursor: pointer;
}
.settings-nav__item:hover {
  background: #f7f7f7;
  color: var(--color-text-primary);
}
.settings-nav__item--active {
  background: #eef6f1;
  color: var(--color-online);
  font-weight: 700;
}

.settings-page__body {
  display: flex;
  flex-direction: column;
  gap: 20px;
  min-width: 0;
}

.card {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 20px 22px;
  scroll-margin-top: 96px;
}
.card__header {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 16px;
}
.card__icon {
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.card__icon--indigo {
  background: #eceafd;
  color: #5b4fe0;
}
.card__icon--amber {
  background: #fdf1e0;
  color: var(--color-warn);
}
.card__icon--teal {
  background: #e2f5f0;
  color: #1a9c82;
}
.card__icon--rose {
  background: #fbe9ec;
  color: #d24b6b;
}
.card__title {
  font-size: 15.5px;
  font-weight: 700;
  color: var(--color-text-primary);
  margin: 0 0 4px;
}
.card__hint {
  font-size: 12.5px;
  color: var(--color-text-secondary);
  margin: 0;
}

/* Dialog slots */
.slot-list {
  display: flex;
  flex-direction: column;
  gap: 0;
}
.slot {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--color-border);
}
.slot:last-child {
  border-bottom: none;
  padding-bottom: 0;
}
.slot__label {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-text-primary);
  margin-bottom: 2px;
}
.slot__hint {
  font-size: 12px;
  color: var(--color-text-secondary);
}
.slot__status {
  display: flex;
  align-items: center;
}
.slot__badge {
  font-size: 11.5px;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 6px;
  white-space: nowrap;
}
.slot__badge--set {
  background: #eef6f1;
  color: var(--color-online);
}
.slot__badge--empty {
  background: #f5f5f5;
  color: var(--color-text-secondary);
}
.slot__actions {
  display: flex;
  gap: 6px;
}
.slot__btn {
  border: 1px solid var(--color-border);
  background: #fff;
  border-radius: 8px;
  padding: 6px 12px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  color: var(--color-text-primary);
}
.slot__btn--danger {
  color: #d24b4b;
  border-color: #f7d5d5;
  background: #fff8f8;
}

.field {
  margin-bottom: 16px;
}
.field:last-child {
  margin-bottom: 0;
}
.field__label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: var(--color-text-primary);
  margin-bottom: 6px;
}
.field__hint {
  font-size: 12px;
  color: var(--color-text-secondary);
  margin: 0 0 8px;
}
.field__input {
  width: 100%;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 9px 12px;
  font-size: 13.5px;
  font-family: inherit;
  color: var(--color-text-primary);
  background: #fff;
}
.field__input--narrow {
  max-width: 160px;
}
.field__textarea {
  resize: vertical;
  min-height: 60px;
  font-family: inherit;
  line-height: 1.5;
}
.field__error {
  display: block;
  font-size: 12px;
  color: #d24b4b;
  margin-top: 4px;
}
.field-row {
  display: flex;
  gap: 16px;
}
.field-row .field {
  flex: 1;
}
.mb-3 {
  margin-bottom: 12px;
}

.toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-text-primary);
  cursor: pointer;
}
.toggle-content {
  margin-top: 16px;
  padding-top: 16px;
  border-top: 1px dashed var(--color-border);
  overflow: hidden;
}

.tags {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  border: 1px solid var(--color-border);
  border-radius: 10px;
  padding: 8px;
}
.tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #eef6f1;
  color: var(--color-text-primary);
  font-size: 12.5px;
  font-weight: 600;
  padding: 4px 8px;
  border-radius: 8px;
}
.tag__remove {
  border: none;
  background: transparent;
  color: var(--color-text-secondary);
  cursor: pointer;
  font-size: 13px;
  padding: 0;
  line-height: 1;
}
.tags__input {
  flex: 1;
  min-width: 140px;
  border: none;
  outline: none;
  font-size: 13px;
  font-family: inherit;
  padding: 4px;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.15s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
.expand-enter-active,
.expand-leave-active {
  transition:
    max-height 0.2s ease,
    opacity 0.2s ease;
  max-height: 800px;
}
.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}

@media (max-width: 720px) {
  .settings-page__layout {
    grid-template-columns: 1fr;
  }
  .settings-nav {
    position: static;
    flex-direction: row;
    flex-wrap: wrap;
    top: auto;
  }
  .slot {
    grid-template-columns: 1fr;
  }
}
</style>
