<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import Swal from 'sweetalert2'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  botId: string | number
}>()

// ── State ─────────────────────────────────────────────────────────────────────
const loading   = ref(true)
const saving    = ref(false)
const dialogs   = ref<any[]>([])
const grouped   = ref<any[]>([])
const configExists = ref(false)

// ── Form ──────────────────────────────────────────────────────────────────────
const form = ref(blankForm())

function blankForm() {
  return {
    // Entry
    starting_dialog_id:           null as number | null,

    // Fallback
    invalid_input_dialog_id:      null as number | null,

    // Retry
    retry_enabled:                false,
    retry_dialog_id:              null as number | null,
    retry_after_minutes:          60,
    max_retry_attempts:           1,

    // Keywords
    home_keywords:                ['menu', 'home', 'start'] as string[],
    back_keywords:                ['back', '0'] as string[],
    handover_keywords:            ['agent', 'human', 'help'] as string[],

    // Handover
    handover_enabled:             false,
    handover_dialog_id_in_hours:  null as number | null,
    handover_dialog_id_off_hours: null as number | null,

    // Session
    session_timeout_minutes:      1440,

    // Operating hours — 0=Sun … 6=Sat
    operating_hours: defaultOperatingHours(),
  }
}

function defaultOperatingHours() {
  const days: Record<string, any> = {}
  for (let d = 0; d <= 6; d++) {
    days[String(d)] = {
      enabled:  d >= 1 && d <= 5,   // Mon–Fri on by default
      open:     '08:00',
      close:    '17:00',
      timezone: 'UTC',
    }
  }
  return days
}

// ── Timezone list (common ones) ───────────────────────────────────────────────
const TIMEZONES = [
  'UTC', 'Africa/Blantyre', 'Africa/Nairobi', 'Africa/Johannesburg',
  'Africa/Lagos', 'Africa/Accra', 'Africa/Cairo', 'Africa/Abidjan',
  'Europe/London', 'Europe/Paris', 'Europe/Berlin', 'Europe/Moscow',
  'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
  'America/Sao_Paulo', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Singapore',
  'Asia/Tokyo', 'Asia/Shanghai', 'Australia/Sydney',
]

const DAY_NAMES = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']

// ── Session timeout presets ───────────────────────────────────────────────────
const TIMEOUT_PRESETS = [
  { label: '15 minutes',  value: 15    },
  { label: '30 minutes',  value: 30    },
  { label: '1 hour',      value: 60    },
  { label: '2 hours',     value: 120   },
  { label: '4 hours',     value: 240   },
  { label: '8 hours',     value: 480   },
  { label: '12 hours',    value: 720   },
  { label: '24 hours',    value: 1440  },
  { label: '48 hours',    value: 2880  },
  { label: '7 days',      value: 10080 },
  { label: 'Never',       value: 43200 },
]

// ── Dialog select items (flat + grouped) ──────────────────────────────────────
const dialogItems = computed(() =>
  dialogs.value.map(d => ({
    value: d.id,
    title: d.display,
    subtitle: d.kind,
  }))
)

function dialogLabel(id: number | null): string {
  if (!id) return '— Not set —'
  const d = dialogs.value.find(d => d.id === id)
  return d ? d.display : `Dialog #${id}`
}

// ── Keyword tag helpers ───────────────────────────────────────────────────────
const newKeyword: Record<string, string> = {
  home: '', back: '', handover: ''
}

function addKeyword(field: 'home_keywords' | 'back_keywords' | 'handover_keywords', key: string) {
  const word = newKeyword[key.replace('_keywords', '')].trim().toLowerCase()
  if (!word) return
  if (!form.value[field].includes(word)) {
    form.value[field].push(word)
  }
  newKeyword[key.replace('_keywords', '')] = ''
}

function removeKeyword(field: 'home_keywords' | 'back_keywords' | 'handover_keywords', i: number) {
  form.value[field].splice(i, 1)
}

function onKeywordEnter(field: 'home_keywords' | 'back_keywords' | 'handover_keywords', key: string) {
  addKeyword(field, key)
}

// ── Load ──────────────────────────────────────────────────────────────────────
async function loadAll() {
  loading.value = true
  try {
    const [configRes, dialogsRes] = await Promise.all([
      axios.get(`/api/bots/${props.botId}/configuration`),
      axios.get(`/api/bots/${props.botId}/configuration/dialogs`),
    ])

    const cfg = configRes.data.configuration
    configExists.value = configRes.data.exists

    dialogs.value = dialogsRes.data.dialogs ?? []
    grouped.value = dialogsRes.data.grouped ?? []

    if (configExists.value && cfg) {
      populateForm(cfg)
    }
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Failed to load configuration', text: err.message, timer: 3000 })
  } finally {
    loading.value = false
  }
}

function populateForm(cfg: any) {
  form.value = {
    starting_dialog_id:           cfg.starting_dialog_id           ?? null,
    invalid_input_dialog_id:      cfg.invalid_input_dialog_id      ?? null,
    retry_enabled:                cfg.retry_enabled                 ?? false,
    retry_dialog_id:              cfg.retry_dialog_id               ?? null,
    retry_after_minutes:          cfg.retry_after_minutes           ?? 60,
    max_retry_attempts:           cfg.max_retry_attempts            ?? 1,
    home_keywords:                cfg.home_keywords                 ?? ['menu', 'home', 'start'],
    back_keywords:                cfg.back_keywords                 ?? ['back', '0'],
    handover_keywords:            cfg.handover_keywords             ?? ['agent', 'human', 'help'],
    handover_enabled:             cfg.handover_enabled              ?? false,
    handover_dialog_id_in_hours:  cfg.handover_dialog_id_in_hours   ?? null,
    handover_dialog_id_off_hours: cfg.handover_dialog_id_off_hours  ?? null,
    session_timeout_minutes:      cfg.session_timeout_minutes       ?? 1440,
    operating_hours:              cfg.operating_hours               ?? defaultOperatingHours(),
  }
}

// ── Save ──────────────────────────────────────────────────────────────────────
async function save() {
  saving.value = true
  try {
    const { data } = await axios.put(`/api/bots/${props.botId}/configuration`, form.value)
    configExists.value = true
    Swal.fire({ icon: 'success', title: 'Configuration saved', timer: 1600, showConfirmButton: false })
  } catch (err: any) {
    const msg = err.response?.data?.message ?? err.message
    const errors = err.response?.data?.errors
    if (errors) {
      const list = Object.values(errors).flat().join('<br/>')
      Swal.fire({ icon: 'error', title: 'Validation failed', html: list })
    } else {
      Swal.fire({ icon: 'error', title: 'Save failed', text: msg })
    }
  } finally {
    saving.value = false
  }
}

// ── Session timeout display ───────────────────────────────────────────────────
const timeoutLabel = computed(() => {
  const p = TIMEOUT_PRESETS.find(p => p.value === form.value.session_timeout_minutes)
  return p ? p.label : `${form.value.session_timeout_minutes} min`
})

// ── Operating hours: apply same timezone to all days ─────────────────────────
function applyTimezoneToAll(tz: string) {
  for (let d = 0; d <= 6; d++) {
    form.value.operating_hours[String(d)].timezone = tz
  }
}

onMounted(loadAll)
</script>

<template>
  <div class="bot-config-page">

    <!-- ── Page header ────────────────────────────────────────────────────── -->
    <div class="page-header">
      <div>
        <h4 class="page-title">Bot Configuration</h4>
        <p class="page-subtitle">
          Control how your bot starts, handles unexpected input, retries, manages sessions,
          and hands off to human agents.
        </p>
      </div>
      <VBtn color="primary" :loading="saving" prepend-icon="$contentSave" @click="save">
        Save Configuration
      </VBtn>
    </div>

    <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-6" rounded />

    <template v-if="!loading">

      <!-- ══════════════════════════════════════════════════════════════════════
           SECTION 1 — ENTRY & FALLBACK
      ══════════════════════════════════════════════════════════════════════ -->
      <div class="config-section">
        <div class="config-section__head">
          <div class="config-section__icon-wrap bg-primary-subtle">
            <VIcon icon="$playCircle" color="primary" size="22" />
          </div>
          <div>
            <div class="config-section__title">Entry &amp; Fallback</div>
            <div class="config-section__subtitle">
              Where the bot starts and what happens when it doesn't understand input
            </div>
          </div>
        </div>

        <VRow class="mt-4">
          <VCol cols="12" md="6">
            <div class="field-card">
              <div class="field-card__label">
                <VIcon icon="$flagCheckered" size="16" class="mr-1" />
                Starting Dialog
              </div>
              <div class="field-card__desc">
                The first dialog sent when a new conversation begins.
                If not set, the executor uses the flow's entry-point dialog.
              </div>
              <VSelect
                v-model="form.starting_dialog_id"
                :items="[{ value: null, title: '— Use flow entry point (default) —' }, ...dialogItems]"
                variant="outlined" density="compact" class="mt-3"
                hide-details
              >
                <template #selection="{ item }">
                  <span class="text-body-2">{{ item.title }}</span>
                </template>
              </VSelect>
              <div v-if="form.starting_dialog_id" class="field-card__selected-hint">
                <VIcon icon="$information" size="13" class="mr-1" />
                {{ dialogLabel(form.starting_dialog_id) }}
              </div>
            </div>
          </VCol>

          <VCol cols="12" md="6">
            <div class="field-card">
              <div class="field-card__label">
                <VIcon icon="$alertCircle" size="16" class="mr-1" color="warning" />
                Invalid Input Dialog
              </div>
              <div class="field-card__desc">
                Sent when the user's free-text reply doesn't match what the current dialog expects.
                Tip: add a message like "Sorry, I didn't understand that. Please choose an option."
              </div>
              <VSelect
                v-model="form.invalid_input_dialog_id"
                :items="[{ value: null, title: '— None (ignore invalid input) —' }, ...dialogItems]"
                variant="outlined" density="compact" class="mt-3"
                hide-details
              />
            </div>
          </VCol>
        </VRow>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════
           SECTION 2 — SESSION TIMEOUT
      ══════════════════════════════════════════════════════════════════════ -->
      <div class="config-section">
        <div class="config-section__head">
          <div class="config-section__icon-wrap bg-info-subtle">
            <VIcon icon="$clockOutline" color="info" size="22" />
          </div>
          <div>
            <div class="config-section__title">Session Timeout</div>
            <div class="config-section__subtitle">
              How long the bot waits before treating the conversation as stale
            </div>
          </div>
        </div>

        <VRow class="mt-4">
          <VCol cols="12" md="6">
            <div class="field-card">
              <div class="field-card__label">Timeout Duration</div>
              <div class="field-card__desc">
                If the user is silent for this long, the conversation context is reset.
                The next message they send will restart from the starting dialog.
              </div>
              <div class="timeout-presets mt-3">
                <div
                  v-for="p in TIMEOUT_PRESETS"
                  :key="p.value"
                  class="timeout-chip"
                  :class="{ 'timeout-chip--active': form.session_timeout_minutes === p.value }"
                  @click="form.session_timeout_minutes = p.value"
                >
                  {{ p.label }}
                </div>
              </div>
              <div class="timeout-custom mt-3">
                <VTextField
                  v-model.number="form.session_timeout_minutes"
                  label="Custom (minutes)"
                  type="number" min="1" max="43200"
                  variant="outlined" density="compact" hide-details
                  style="max-width:180px"
                />
                <span class="text-caption text-medium-emphasis ml-2 align-self-center">
                  = {{ timeoutLabel }}
                </span>
              </div>
            </div>
          </VCol>

          <!-- Retry re-engagement -->
          <VCol cols="12" md="6">
            <div class="field-card h-full">
              <div class="d-flex align-center justify-space-between mb-2">
                <div class="field-card__label mb-0">
                  <VIcon icon="$refresh" size="16" class="mr-1" color="success" />
                  Re-engagement (Retry)
                </div>
                <VSwitch
                  v-model="form.retry_enabled"
                  color="success" hide-details density="compact" inset
                />
              </div>
              <div class="field-card__desc mb-3">
                When enabled, if a conversation goes silent before timing out, the bot
                automatically re-sends a dialog to nudge the user.
              </div>

              <Transition name="expand">
                <div v-if="form.retry_enabled">
                  <VSelect
                    v-model="form.retry_dialog_id"
                    :items="[{ value: null, title: '— Select a dialog —' }, ...dialogItems]"
                    label="Re-engagement Dialog"
                    variant="outlined" density="compact" class="mb-3"
                    hide-details
                  />
                  <VRow>
                    <VCol cols="6">
                      <VTextField
                        v-model.number="form.retry_after_minutes"
                        label="Wait (minutes)"
                        type="number" min="1" max="10080"
                        variant="outlined" density="compact" hide-details
                      />
                    </VCol>
                    <VCol cols="6">
                      <VTextField
                        v-model.number="form.max_retry_attempts"
                        label="Max attempts"
                        type="number" min="1" max="20"
                        variant="outlined" density="compact" hide-details
                      />
                    </VCol>
                  </VRow>
                </div>
              </Transition>
            </div>
          </VCol>
        </VRow>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════
           SECTION 3 — KEYWORD TRIGGERS
      ══════════════════════════════════════════════════════════════════════ -->
      <div class="config-section">
        <div class="config-section__head">
          <div class="config-section__icon-wrap bg-success-subtle">
            <VIcon icon="$keyboardOutline" color="success" size="22" />
          </div>
          <div>
            <div class="config-section__title">Global Keyword Triggers</div>
            <div class="config-section__subtitle">
              Keywords that work at any point in the flow, no matter which dialog the user is on
            </div>
          </div>
        </div>

        <VRow class="mt-4">
          <!-- Home keywords -->
          <VCol cols="12" md="4">
            <div class="field-card">
              <div class="field-card__label">
                <VIcon icon="$home" size="15" class="mr-1" color="primary" />
                Home Keywords
              </div>
              <div class="field-card__desc">
                Restart the flow from the starting dialog.
                e.g. "menu", "home", "start"
              </div>
              <div class="keyword-chips mt-3">
                <VChip
                  v-for="(kw, i) in form.home_keywords"
                  :key="kw"
                  size="small" closable color="primary" variant="tonal" class="mr-1 mb-1"
                  @click:close="removeKeyword('home_keywords', i)"
                >
                  {{ kw }}
                </VChip>
              </div>
              <div class="d-flex ga-2 mt-2">
                <VTextField
                  v-model="newKeyword.home"
                  placeholder="Add keyword"
                  variant="outlined" density="compact" hide-details
                  @keydown.enter.prevent="onKeywordEnter('home_keywords', 'home')"
                />
                <VBtn icon="$plus" size="small" variant="outlined" @click="addKeyword('home_keywords', 'home')" />
              </div>
            </div>
          </VCol>

          <!-- Back keywords -->
          <VCol cols="12" md="4">
            <div class="field-card">
              <div class="field-card__label">
                <VIcon icon="$arrowLeft" size="15" class="mr-1" color="info" />
                Back Keywords
              </div>
              <div class="field-card__desc">
                Navigate back to the previous dialog.
                e.g. "back", "0", "previous"
              </div>
              <div class="keyword-chips mt-3">
                <VChip
                  v-for="(kw, i) in form.back_keywords"
                  :key="kw"
                  size="small" closable color="info" variant="tonal" class="mr-1 mb-1"
                  @click:close="removeKeyword('back_keywords', i)"
                >
                  {{ kw }}
                </VChip>
              </div>
              <div class="d-flex ga-2 mt-2">
                <VTextField
                  v-model="newKeyword.back"
                  placeholder="Add keyword"
                  variant="outlined" density="compact" hide-details
                  @keydown.enter.prevent="onKeywordEnter('back_keywords', 'back')"
                />
                <VBtn icon="$plus" size="small" variant="outlined" @click="addKeyword('back_keywords', 'back')" />
              </div>
            </div>
          </VCol>

          <!-- Handover keywords -->
          <VCol cols="12" md="4">
            <div class="field-card">
              <div class="field-card__label">
                <VIcon icon="$accountSwitch" size="15" class="mr-1" color="warning" />
                Handover Keywords
              </div>
              <div class="field-card__desc">
                Trigger agent handover when typed at any point.
                e.g. "agent", "human", "help"
              </div>
              <div class="keyword-chips mt-3">
                <VChip
                  v-for="(kw, i) in form.handover_keywords"
                  :key="kw"
                  size="small" closable color="warning" variant="tonal" class="mr-1 mb-1"
                  @click:close="removeKeyword('handover_keywords', i)"
                >
                  {{ kw }}
                </VChip>
              </div>
              <div class="d-flex ga-2 mt-2">
                <VTextField
                  v-model="newKeyword.handover"
                  placeholder="Add keyword"
                  variant="outlined" density="compact" hide-details
                  @keydown.enter.prevent="onKeywordEnter('handover_keywords', 'handover')"
                />
                <VBtn icon="$plus" size="small" variant="outlined" @click="addKeyword('handover_keywords', 'handover')" />
              </div>
            </div>
          </VCol>
        </VRow>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════
           SECTION 4 — HUMAN HANDOVER
      ══════════════════════════════════════════════════════════════════════ -->
      <div class="config-section">
        <div class="config-section__head">
          <div class="config-section__icon-wrap bg-warning-subtle">
            <VIcon icon="$headset" color="warning" size="22" />
          </div>
          <div class="flex-grow-1">
            <div class="config-section__title">Human Handover</div>
            <div class="config-section__subtitle">
              Route conversations to a live agent — send different dialogs based on operating hours
            </div>
          </div>
          <VSwitch
            v-model="form.handover_enabled"
            color="warning" hide-details density="compact" inset
            label="Enable handover"
          />
        </div>

        <Transition name="expand">
          <VRow v-if="form.handover_enabled" class="mt-4">
            <VCol cols="12" md="6">
              <div class="field-card">
                <div class="field-card__label">
                  <VIcon icon="$weatherSunny" size="15" class="mr-1" color="success" />
                  During Operating Hours
                </div>
                <div class="field-card__desc">
                  Dialog sent when a handover is triggered and agents are available.
                  e.g. "Connecting you to an agent, please wait…"
                </div>
                <VSelect
                  v-model="form.handover_dialog_id_in_hours"
                  :items="[{ value: null, title: '— None —' }, ...dialogItems]"
                  variant="outlined" density="compact" class="mt-3"
                  hide-details
                />
              </div>
            </VCol>
            <VCol cols="12" md="6">
              <div class="field-card">
                <div class="field-card__label">
                  <VIcon icon="$weatherNight" size="15" class="mr-1" color="info" />
                  Outside Operating Hours
                </div>
                <div class="field-card__desc">
                  Dialog sent when agents are unavailable.
                  e.g. "We're currently offline. Our hours are Mon–Fri 8am–5pm."
                </div>
                <VSelect
                  v-model="form.handover_dialog_id_off_hours"
                  :items="[{ value: null, title: '— None —' }, ...dialogItems]"
                  variant="outlined" density="compact" class="mt-3"
                  hide-details
                />
              </div>
            </VCol>
          </VRow>
        </Transition>
      </div>

      <!-- ══════════════════════════════════════════════════════════════════════
           SECTION 5 — OPERATING HOURS
      ══════════════════════════════════════════════════════════════════════ -->
      <div class="config-section">
        <div class="config-section__head">
          <div class="config-section__icon-wrap bg-secondary-subtle">
            <VIcon icon="$calendarClock" color="secondary" size="22" />
          </div>
          <div class="flex-grow-1">
            <div class="config-section__title">Operating Hours</div>
            <div class="config-section__subtitle">
              Define when agents are available — used by human handover to route correctly
            </div>
          </div>
          <!-- Apply timezone to all -->
          <div class="d-flex align-center ga-2">
            <span class="text-caption text-medium-emphasis">Apply timezone to all:</span>
            <VSelect
              :items="TIMEZONES"
              variant="outlined" density="compact" hide-details
              style="width:200px"
              placeholder="Select timezone"
              @update:model-value="applyTimezoneToAll"
            />
          </div>
        </div>

        <div class="operating-hours-grid mt-4">
          <div
            v-for="d in 7"
            :key="d - 1"
            class="oh-row"
            :class="{ 'oh-row--disabled': !form.operating_hours[String(d - 1)]?.enabled }"
          >
            <!-- Day toggle + name -->
            <div class="oh-day">
              <VSwitch
                v-model="form.operating_hours[String(d - 1)].enabled"
                color="primary" hide-details density="compact" inset
              />
              <span class="oh-day-name" :class="{ 'text-medium-emphasis': !form.operating_hours[String(d - 1)]?.enabled }">
                {{ DAY_NAMES[d - 1] }}
              </span>
            </div>

            <!-- Hours -->
            <Transition name="fade">
              <div v-if="form.operating_hours[String(d - 1)]?.enabled" class="oh-times">
                <VTextField
                  v-model="form.operating_hours[String(d - 1)].open"
                  label="Opens"
                  type="time"
                  variant="outlined" density="compact" hide-details
                  style="width:130px"
                />
                <span class="text-caption text-medium-emphasis px-2">to</span>
                <VTextField
                  v-model="form.operating_hours[String(d - 1)].close"
                  label="Closes"
                  type="time"
                  variant="outlined" density="compact" hide-details
                  style="width:130px"
                />
                <VSelect
                  v-model="form.operating_hours[String(d - 1)].timezone"
                  :items="TIMEZONES"
                  label="Timezone"
                  variant="outlined" density="compact" hide-details
                  style="width:190px"
                />
              </div>
              <div v-else class="oh-closed-label">
                Closed
              </div>
            </Transition>
          </div>
        </div>
      </div>

      <!-- ── Bottom save bar ──────────────────────────────────────────────── -->
      <div class="save-bar">
        <div class="text-caption text-medium-emphasis">
          <VIcon icon="$informationOutline" size="14" class="mr-1" />
          Changes take effect on the next incoming message.
        </div>
        <VBtn color="primary" :loading="saving" prepend-icon="$contentSave" @click="save">
          Save Configuration
        </VBtn>
      </div>

    </template>
  </div>
</template>

<style scoped lang="scss">
.bot-config-page {
  max-width: 1100px;
  padding-bottom: 40px;
}

// ── Page header ───────────────────────────────────────────────────────────────
.page-header {
  display: flex; align-items: flex-start; justify-content: space-between;
  gap: 16px; margin-bottom: 28px;
}
.page-title   { font-size: 1.15rem; font-weight: 700; margin: 0 0 4px; }
.page-subtitle { font-size: 0.82rem; color: rgba(var(--v-theme-on-surface), 0.55); margin: 0; }

// ── Config sections ───────────────────────────────────────────────────────────
.config-section {
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 14px;
  padding: 22px 24px;
  margin-bottom: 16px;
}

.config-section__head {
  display: flex; align-items: center; gap: 14px;
  flex-wrap: wrap;
}

.config-section__icon-wrap {
  width: 44px; height: 44px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
}

.config-section__title {
  font-size: 0.95rem; font-weight: 700; margin-bottom: 2px;
}

.config-section__subtitle {
  font-size: 0.78rem; color: rgba(var(--v-theme-on-surface), 0.5);
}

// ── Field cards ───────────────────────────────────────────────────────────────
.field-card {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  padding: 16px;
  height: 100%;
}

.field-card__label {
  font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.08em; color: rgba(var(--v-theme-on-surface), 0.6);
  margin-bottom: 6px; display: flex; align-items: center;
}

.field-card__desc {
  font-size: 0.78rem; color: rgba(var(--v-theme-on-surface), 0.5); line-height: 1.5;
}

.field-card__selected-hint {
  font-size: 0.72rem; color: rgba(var(--v-theme-primary), 0.8);
  margin-top: 6px; display: flex; align-items: center;
}

// ── Timeout presets ───────────────────────────────────────────────────────────
.timeout-presets {
  display: flex; flex-wrap: wrap; gap: 6px;
}

.timeout-chip {
  padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; cursor: pointer;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  transition: all 0.15s; user-select: none;

  &:hover { border-color: rgba(var(--v-theme-primary), 0.5); }
  &--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.1);
    color: rgb(var(--v-theme-primary));
    font-weight: 600;
  }
}

.timeout-custom { display: flex; align-items: center; }

// ── Keyword chips ─────────────────────────────────────────────────────────────
.keyword-chips { min-height: 32px; }

// ── Operating hours grid ──────────────────────────────────────────────────────
.operating-hours-grid {
  display: flex; flex-direction: column; gap: 10px;
}

.oh-row {
  display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
  padding: 10px 14px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  transition: background 0.15s, opacity 0.15s;

  &--disabled {
    opacity: 0.55;
    background: rgba(var(--v-theme-surface-variant), 0.3);
  }
}

.oh-day {
  display: flex; align-items: center; gap: 10px;
  min-width: 140px;
}

.oh-day-name {
  font-size: 0.85rem; font-weight: 600; min-width: 90px;
}

.oh-times {
  display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

.oh-closed-label {
  font-size: 0.78rem; color: rgba(var(--v-theme-on-surface), 0.35);
  font-style: italic; padding-left: 4px;
}

// ── Save bar ──────────────────────────────────────────────────────────────────
.save-bar {
  display: flex; align-items: center; justify-content: space-between;
  background: rgb(var(--v-theme-surface));
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 12px; padding: 14px 20px; margin-top: 8px;
}

// ── Colour utilities ──────────────────────────────────────────────────────────
.bg-primary-subtle   { background: rgba(var(--v-theme-primary),   0.1) !important; }
.bg-info-subtle      { background: rgba(var(--v-theme-info),      0.1) !important; }
.bg-success-subtle   { background: rgba(var(--v-theme-success),   0.1) !important; }
.bg-warning-subtle   { background: rgba(var(--v-theme-warning),   0.1) !important; }
.bg-secondary-subtle { background: rgba(var(--v-theme-secondary), 0.1) !important; }

// ── Transitions ───────────────────────────────────────────────────────────────
.expand-enter-active, .expand-leave-active { transition: all 0.22s ease; overflow: hidden; }
.expand-enter-from, .expand-leave-to       { max-height: 0; opacity: 0; }
.expand-enter-to, .expand-leave-from       { max-height: 500px; opacity: 1; }

.fade-enter-active, .fade-leave-active { transition: opacity 0.18s; }
.fade-enter-from, .fade-leave-to       { opacity: 0; }
</style>
