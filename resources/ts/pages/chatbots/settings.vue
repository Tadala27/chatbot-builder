<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import OperatingHoursEditor from "@/components/OperatingHoursEditor.vue";
import {
  useBotConfiguration,
  VALIDATION_BOUNDS,
  type DialogOption,
} from "@/composables/botSettings";

const props = defineProps<{
  botId: string;
}>();

const { configuration, dialogs, isLoading, isSaving, errors, load, save } = useBotConfiguration(props.botId);

type KeywordField = "home_keywords" | "back_keywords" | "handover_keywords" | "opt_out_keywords" | "opt_in_keywords";

const savedNotice = ref(false);
const keywordDraft = ref<Record<KeywordField, string>>({
  home_keywords: "",
  back_keywords: "",
  handover_keywords: "",
  opt_out_keywords: "",
  opt_in_keywords: "",
});

onMounted(load);

// Filter dialog pickers to relevant kinds where it matters — e.g. a
// starting dialog should be an entry point. Falls back to showing
// everything if nothing matches, so an unusual bot setup never ends up
// with an empty picker.
const entryPointDialogs = computed(() => {
  const entryPoints = dialogs.value.filter((d) => d.is_entry_point);
  return entryPoints.length > 0 ? entryPoints : dialogs.value;
});

function dialogOptions(): DialogOption[] {
  return dialogs.value;
}

function fieldError(field: string): string | null {
  return errors.value[field]?.[0] ?? null;
}

function addKeyword(field: KeywordField) {
  if (!configuration.value) return;
  const raw = keywordDraft.value[field].trim();
  if (!raw) return;
  // Allow pasting a comma-separated batch in one go, same normalisation
  // the backend itself tolerates (normaliseKeywords accepts array or
  // comma-string) — split client-side too so a paste of "menu, home"
  // becomes two tags instead of one literal string.
  const parts = raw.split(",").map((s) => s.trim()).filter(Boolean);
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
    setTimeout(() => (savedNotice.value = false), 3000);
  }
}
</script>

<template>
  <div class="settings-page">
    <header class="settings-page__header">
      <h1 class="settings-page__title">Bot settings</h1>
      <div class="settings-page__actions">
        <span v-if="savedNotice" class="settings-page__saved">Saved</span>
        <button type="button" class="settings-page__save" :disabled="isSaving || !configuration" @click="handleSave">
          {{ isSaving ? "Saving…" : "Save changes" }}
        </button>
      </div>
    </header>

    <p v-if="isLoading" class="settings-page__loading">Loading configuration…</p>

    <div v-else-if="configuration" class="settings-page__body">
      <!-- Flow -->
      <section class="card">
        <h2 class="card__title">Flow</h2>
        <p class="card__hint">Which dialog the bot opens with for a brand-new contact versus a returning one.</p>

        <div class="field">
          <label class="field__label">Starting dialog</label>
          <select v-model.number="configuration.starting_dialog_id" class="field__input">
            <option :value="null">No dialog selected</option>
            <option v-for="d in entryPointDialogs" :key="d.id" :value="d.id">{{ d.display }}</option>
          </select>
          <span v-if="fieldError('starting_dialog_id')" class="field__error">{{ fieldError("starting_dialog_id") }}</span>
        </div>

        <div class="field">
          <label class="field__label">Welcome dialog</label>
          <select v-model.number="configuration.welcome_dialog_id" class="field__input">
            <option :value="null">No dialog selected</option>
            <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
          </select>
          <span v-if="fieldError('welcome_dialog_id')" class="field__error">{{ fieldError("welcome_dialog_id") }}</span>
        </div>
      </section>

      <!-- Invalid input -->
      <section class="card">
        <h2 class="card__title">Invalid input</h2>
        <p class="card__hint">What happens when the bot doesn't understand a reply, and what to do if it keeps happening.</p>

        <div class="field">
          <label class="field__label">Invalid input message</label>
          <p class="field__hint">Sent as plain text before the invalid input dialog, if set. Leave blank to skip straight to the dialog.</p>
          <textarea v-model="configuration.invalid_input_message" class="field__input field__textarea" maxlength="1000" rows="2" />
          <span v-if="fieldError('invalid_input_message')" class="field__error">{{ fieldError("invalid_input_message") }}</span>
        </div>

        <div class="field">
          <label class="field__label">Invalid input dialog</label>
          <select v-model.number="configuration.invalid_input_dialog_id" class="field__input">
            <option :value="null">No dialog selected</option>
            <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
          </select>
          <span v-if="fieldError('invalid_input_dialog_id')" class="field__error">{{ fieldError("invalid_input_dialog_id") }}</span>
        </div>

        <div class="field-row">
          <div class="field">
            <label class="field__label">Max invalid attempts</label>
            <p class="field__hint">After this many invalid replies in a row, escalate instead of repeating the dialog.</p>
            <input
              type="number"
              v-model.number="configuration.max_invalid_attempts"
              :min="VALIDATION_BOUNDS.maxInvalidAttempts.min"
              :max="VALIDATION_BOUNDS.maxInvalidAttempts.max"
              class="field__input field__input--narrow"
            />
            <span v-if="fieldError('max_invalid_attempts')" class="field__error">{{ fieldError("max_invalid_attempts") }}</span>
          </div>

          <div class="field">
            <label class="field__label">Escalation dialog</label>
            <select v-model.number="configuration.invalid_attempts_dialog_id" class="field__input">
              <option :value="null">No dialog selected</option>
              <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
            </select>
            <span v-if="fieldError('invalid_attempts_dialog_id')" class="field__error">{{ fieldError("invalid_attempts_dialog_id") }}</span>
          </div>
        </div>
      </section>

      <!-- Retry -->
      <section class="card">
        <h2 class="card__title">Retry</h2>
        <p class="card__hint">Nudge a contact who's gone quiet mid-conversation.</p>

        <label class="toggle">
          <input type="checkbox" v-model="configuration.retry_enabled" />
          <span>Enable retry</span>
        </label>

        <template v-if="configuration.retry_enabled">
          <div class="field">
            <label class="field__label">Retry dialog</label>
            <select v-model.number="configuration.retry_dialog_id" class="field__input">
              <option :value="null">No dialog selected</option>
              <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
            </select>
          </div>

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
              <span v-if="fieldError('retry_after_minutes')" class="field__error">{{ fieldError("retry_after_minutes") }}</span>
            </div>

            <div class="field">
              <label class="field__label">Max attempts</label>
              <input
                type="number"
                v-model.number="configuration.max_retry_attempts"
                :min="VALIDATION_BOUNDS.maxRetryAttempts.min"
                :max="VALIDATION_BOUNDS.maxRetryAttempts.max"
                class="field__input field__input--narrow"
              />
              <span v-if="fieldError('max_retry_attempts')" class="field__error">{{ fieldError("max_retry_attempts") }}</span>
            </div>
          </div>
        </template>
      </section>

      <!-- Handover -->
      <section class="card">
        <h2 class="card__title">Handover</h2>
        <p class="card__hint">Route the conversation to a human agent, with different dialogs for in-hours and off-hours.</p>

        <label class="toggle">
          <input type="checkbox" v-model="configuration.handover_enabled" />
          <span>Enable handover</span>
        </label>

        <template v-if="configuration.handover_enabled">
          <div class="field-row">
            <div class="field">
              <label class="field__label">In-hours dialog</label>
              <select v-model.number="configuration.handover_dialog_id_in_hours" class="field__input">
                <option :value="null">No dialog selected</option>
                <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
              </select>
            </div>

            <div class="field">
              <label class="field__label">Off-hours dialog</label>
              <select v-model.number="configuration.handover_dialog_id_off_hours" class="field__input">
                <option :value="null">No dialog selected</option>
                <option v-for="d in dialogOptions()" :key="d.id" :value="d.id">{{ d.display }}</option>
              </select>
            </div>
          </div>

          <div class="field">
            <label class="field__label">Unavailable message</label>
            <p class="field__hint">Shown if handover is triggered off-hours and no off-hours dialog is set, or no agent is available.</p>
            <textarea v-model="configuration.handover_unavailable_message" class="field__input field__textarea" maxlength="1000" rows="2" />
            <span v-if="fieldError('handover_unavailable_message')" class="field__error">{{ fieldError("handover_unavailable_message") }}</span>
          </div>

          <div class="field">
            <label class="field__label">Auto-resolve after (minutes)</label>
            <p class="field__hint">If no agent responds within this window after handover, the conversation is automatically resolved.</p>
            <input
              type="number"
              v-model.number="configuration.auto_resolve_after_minutes"
              :min="VALIDATION_BOUNDS.autoResolveAfterMinutes.min"
              :max="VALIDATION_BOUNDS.autoResolveAfterMinutes.max"
              class="field__input field__input--narrow"
            />
            <span v-if="fieldError('auto_resolve_after_minutes')" class="field__error">{{ fieldError("auto_resolve_after_minutes") }}</span>
          </div>

          <div class="field">
            <label class="field__label">Handover keywords</label>
            <p class="field__hint">A customer typing one of these triggers handover directly, any time.</p>
            <div class="tags">
              <span v-for="(kw, i) in configuration.handover_keywords" :key="kw" class="tag">
                {{ kw }}
                <button type="button" class="tag__remove" @click="removeKeyword('handover_keywords', i)">×</button>
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
            <p class="field__hint">Determines whether a handover during this window counts as in-hours or off-hours.</p>
            <OperatingHoursEditor v-model="configuration.operating_hours" />
          </div>
        </template>
      </section>

      <!-- Navigation keywords -->
      <section class="card">
        <h2 class="card__title">Navigation keywords</h2>
        <p class="card__hint">Words a contact can type at any point to jump to the main menu or go back a step.</p>

        <div class="field">
          <label class="field__label">Home keywords</label>
          <div class="tags">
            <span v-for="(kw, i) in configuration.home_keywords" :key="kw" class="tag">
              {{ kw }}
              <button type="button" class="tag__remove" @click="removeKeyword('home_keywords', i)">×</button>
            </span>
            <input
              v-model="keywordDraft.home_keywords"
              type="text"
              placeholder="Add keyword, press Enter"
              class="tags__input"
              @keydown.enter.prevent="addKeyword('home_keywords')"
            />
          </div>
        </div>

        <div class="field">
          <label class="field__label">Back keywords</label>
          <div class="tags">
            <span v-for="(kw, i) in configuration.back_keywords" :key="kw" class="tag">
              {{ kw }}
              <button type="button" class="tag__remove" @click="removeKeyword('back_keywords', i)">×</button>
            </span>
            <input
              v-model="keywordDraft.back_keywords"
              type="text"
              placeholder="Add keyword, press Enter"
              class="tags__input"
              @keydown.enter.prevent="addKeyword('back_keywords')"
            />
          </div>
        </div>
      </section>

      <!-- Subscription keywords -->
      <section class="card">
        <h2 class="card__title">Subscription keywords</h2>
        <p class="card__hint">Words a contact can send to opt out of or back into messaging from this bot.</p>

        <div class="field">
          <label class="field__label">Opt-out keywords</label>
          <div class="tags">
            <span v-for="(kw, i) in configuration.opt_out_keywords" :key="kw" class="tag">
              {{ kw }}
              <button type="button" class="tag__remove" @click="removeKeyword('opt_out_keywords', i)">×</button>
            </span>
            <input
              v-model="keywordDraft.opt_out_keywords"
              type="text"
              placeholder="Add keyword, press Enter"
              class="tags__input"
              @keydown.enter.prevent="addKeyword('opt_out_keywords')"
            />
          </div>
        </div>

        <div class="field">
          <label class="field__label">Opt-in keywords</label>
          <div class="tags">
            <span v-for="(kw, i) in configuration.opt_in_keywords" :key="kw" class="tag">
              {{ kw }}
              <button type="button" class="tag__remove" @click="removeKeyword('opt_in_keywords', i)">×</button>
            </span>
            <input
              v-model="keywordDraft.opt_in_keywords"
              type="text"
              placeholder="Add keyword, press Enter"
              class="tags__input"
              @keydown.enter.prevent="addKeyword('opt_in_keywords')"
            />
          </div>
        </div>
      </section>

      <!-- Session -->
      <section class="card">
        <h2 class="card__title">Session</h2>
        <div class="field">
          <label class="field__label">Session timeout (minutes)</label>
          <p class="field__hint">How long a conversation can sit idle before it's considered abandoned.</p>
          <input
            type="number"
            v-model.number="configuration.session_timeout_minutes"
            :min="VALIDATION_BOUNDS.sessionTimeoutMinutes.min"
            :max="VALIDATION_BOUNDS.sessionTimeoutMinutes.max"
            class="field__input field__input--narrow"
          />
          <span v-if="fieldError('session_timeout_minutes')" class="field__error">{{ fieldError("session_timeout_minutes") }}</span>
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.settings-page {
  --color-border: #ececec;
  --color-text-primary: #16191c;
  --color-text-secondary: #8d9a93;
  --color-text-tertiary: #b8c0bb;
  --color-online: #1fc06b;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  max-width: 720px;
  margin: 0 auto;
  padding: 32px 24px 64px;
}
.settings-page * {
  box-sizing: border-box;
}

.settings-page__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 24px;
}
.settings-page__title {
  font-size: 24px;
  font-weight: 800;
  color: var(--color-text-primary);
  margin: 0;
}
.settings-page__actions {
  display: flex;
  align-items: center;
  gap: 12px;
}
.settings-page__saved {
  font-size: 13px;
  color: var(--color-online);
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
  opacity: 0.5;
  cursor: default;
}
.settings-page__loading {
  color: var(--color-text-secondary);
  font-size: 14px;
}

.settings-page__body {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.card {
  background: #fff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  padding: 20px 22px;
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
  margin: 0 0 16px;
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

.toggle {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13.5px;
  font-weight: 600;
  color: var(--color-text-primary);
  cursor: pointer;
  margin-bottom: 16px;
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
</style>