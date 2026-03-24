<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import axios from 'axios'
import Swal from 'sweetalert2'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  botId: string | number
}>()

const emit = defineEmits<{
  (e: 'variablesUpdated', variables: any[]): void
}>()
const pagination = ref({
  total: 0,
  per_page: 20,
  current_page: 1,
  last_page: 1,
  from: 0,
  to: 0,
})
// ── View state (slider) ───────────────────────────────────────────────────────
const view = ref<'list' | 'form'>('list')
const editingVariable = ref<any>(null)

function openCreateForm() {
  editingVariable.value = null
  resetForm()
  view.value = 'form'
}
function openEditForm(v: any) {
  editingVariable.value = v
  populateForm(v)
  view.value = 'form'
}
function backToList() {
  view.value = 'list'
  editingVariable.value = null
  resetForm()
}

// ── Data ──────────────────────────────────────────────────────────────────────
const allVariables = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const search = ref('')
const hovered = ref('')

const systemVars = computed(() => allVariables.value.filter(v => v.is_system))
const customVars = computed(() => allVariables.value.filter(v => !v.is_system))

const filteredCustomVars = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return customVars.value
  return customVars.value.filter(v =>
    v.name.toLowerCase().includes(q) ||
    v.key.toLowerCase().includes(q) ||
    (v.description ?? '').toLowerCase().includes(q)
  )
})

// ── Form ──────────────────────────────────────────────────────────────────────
const DATA_TYPES = [
  { value: 'string', title: 'Text (string)' },
  { value: 'number', title: 'Number' },
  { value: 'boolean', title: 'Boolean (true/false)' },
  { value: 'json', title: 'JSON Object' },
  { value: 'date', title: 'Date' },
]

const SAVE_IN_OPTIONS = [
  {
    value: 'conversation',
    title: 'Conversation',
    subtitle: 'Stored per session — cleared when conversation ends',
    icon: '$messageText',
  },
  {
    value: 'user_property',
    title: 'User Property',
    subtitle: 'Stored permanently on the WhatsApp contact',
    icon: '$account',
  },
  {
    value: 'global',
    title: 'Global Variable',
    subtitle: 'Read-only reference to a tenant-level constant',
    icon: '$earth',
  },
]

const form = ref(blankForm())

function blankForm() {
  return {
    name: '',
    key: '',
    data_type: 'string',
    save_in: 'conversation',
    use_in_js: false,
    is_sensitive: false,
    default_value: '',
    description: '',
    keyTouched: false,
  }
}

function resetForm() { form.value = blankForm() }

function populateForm(v: any) {
  form.value = {
    name: v.name ?? '',
    key: v.key ?? '',
    data_type: v.data_type ?? 'string',
    save_in: v.save_in ?? 'conversation',
    use_in_js: v.use_in_js ?? false,
    is_sensitive: v.is_sensitive ?? false,
    default_value: v.default_value ?? '',
    description: v.description ?? '',
    keyTouched: true,
  }
}

function onNameInput() {
  if (form.value.keyTouched || editingVariable.value) return
  form.value.key = form.value.name
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_]/g, '')
    .replace(/^[^a-z]+/, '')
    .slice(0, 100)
}

// ── Load / Save / Delete ──────────────────────────────────────────────────────
async function loadVariables() {
  loading.value = true

  const params = {
    page: pagination.value.current_page,
    per_page: pagination.value.per_page,
    search: search.value || undefined,

  }
  try {
    const { data } = await axios.get(`/api/bots/${props.botId}/variables`, { params })
    allVariables.value = data.data ?? []

    pagination.value = {
      total: data.pagination.total,
      per_page: data.pagination.per_page,
      current_page: data.pagination.current_page,
      last_page: data.pagination.last_page,
      from: data.pagination.from ?? 0,
      to: data.pagination.to ?? 0,
    }
    emit('variablesUpdated', customVars.value)
  } catch (err: any) {
    console.error(err)
    Swal.fire({ icon: 'error', title: 'Failed to load variables', timer: 2000, showConfirmButton: false })
  } finally {
    loading.value = false
  }
}

async function saveVariable() {
  saving.value = true
  try {
    const payload = {
      name: form.value.name,
      key: form.value.key,
      data_type: form.value.data_type,
      save_in: form.value.save_in,
      use_in_js: form.value.use_in_js,
      is_sensitive: form.value.is_sensitive,
      default_value: form.value.default_value || null,
      description: form.value.description || null,
    }

    let response: any
    if (editingVariable.value) {
      response = await axios.put(`/api/bots/${props.botId}/variables/${editingVariable.value.id}`, payload)
      const idx = allVariables.value.findIndex(v => v.id === editingVariable.value.id)
      if (idx !== -1) allVariables.value.splice(idx, 1, response.data.variable)
    } else {
      response = await axios.post(`/api/bots/${props.botId}/variables`, payload)
      allVariables.value.push(response.data.variable)
    }

    emit('variablesUpdated', customVars.value)
    Swal.fire({
      icon: 'success',
      title: editingVariable.value ? 'Variable updated' : 'Variable created',
      timer: 1600,
      showConfirmButton: false,
    })
    backToList()
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Save failed', text: err.response?.data?.message ?? err.message })
  } finally {
    saving.value = false
  }
}

async function deleteVariable(v: any) {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "$${v.key}"?`,
    html: `Any dialog text referencing <code>$${v.key}</code> will stop resolving.<br/>This cannot be undone.`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    confirmButtonText: 'Delete',
  })
  if (!isConfirmed) return

  try {
    const { data } = await axios.delete(`/api/bots/${props.botId}/variables/${v.id}`)
    allVariables.value = allVariables.value.filter(x => x.id !== v.id)
    emit('variablesUpdated', customVars.value)

    if (data.active_count > 0) {
      Swal.fire({
        icon: 'warning',
        title: 'Deleted',
        text: `Variable deleted. ${data.active_count} conversation(s) had runtime values for this variable — those values are now orphaned.`,
      })
    }
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Delete failed', text: err.response?.data?.message ?? err.message })
  }
}

// ── Display helpers ───────────────────────────────────────────────────────────
const SAVE_IN_COLORS: Record<string, string> = { conversation: 'primary', user_property: 'success', global: 'warning' }
const SAVE_IN_LABELS: Record<string, string> = { conversation: 'Session', user_property: 'Permanent', global: 'Global' }
const DATA_TYPE_COLORS: Record<string, string> = { string: 'info', number: 'purple', boolean: 'orange', json: 'teal', date: 'pink' }
const DATA_TYPE_ICONS: Record<string, string> = { string: 'custom-note-outline', number: 'custom-table', boolean: 'custom-check-circle-fill', json: 'custom-code', date: 'custom-calendar-2' }


// ────────────────────────────────────────
// Watchers
// ────────────────────────────────────────
watchDebounced(
  [search,],
  () => {
    pagination.value.current_page = 1
    loadVariables()
  },
  { debounce: 500, maxWait: 1000 }
)

watch(() => pagination.value.current_page, () => loadVariables())

onMounted(loadVariables)
defineExpose({ loadVariables })
</script>

<template>
  <div class="variable-library">

    <!-- ── Slider track ────────────────────────────────────────────────────── -->
    <div class="slider-track">

      <!-- ══════════════════════════════════ LIST ════════════════════════════ -->
      <div class="panel" :class="view === 'list' ? 'panel--visible' : 'panel--hidden-left'">

        <!-- Header -->
        <div class="panel-header">
          <div>
            <h4 class="panel-title">Custom Variables</h4>
            <p class="panel-subtitle">Define variables that dialogs can collect</p>
          </div>
          <VBtn color="primary" prepend-icon="$plus" @click="openCreateForm">
            Create Variable
          </VBtn>
        </div>

        <!-- Search -->
        <VTextField v-model="search" placeholder="Search by name or key…" prepend-inner-icon="$magnify"
          variant="outlined" density="compact" hide-details class="mb-4" />

        <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-2" />

        <!-- System variables -->
        <VExpansionPanels variant="accordion" class="mb-3">
          <VExpansionPanel>
            <VExpansionPanelTitle class="py-2">
              <div class="d-flex align-center ga-2">
                <VIcon icon="$cog" size="16" />
                <span class="text-caption font-weight-bold text-uppercase" style="letter-spacing:.08em">
                  System Variables
                </span>
                <VChip size="x-small" variant="tonal">{{ systemVars.length }}</VChip>
              </div>
              <template #actions="{ expanded }">
                <VIcon :icon="expanded ? '$chevronUp' : '$chevronDown'" size="16" />
              </template>
            </VExpansionPanelTitle>
            <VExpansionPanelText class="pa-0">
              <VTable density="compact" class="system-table">
                <tbody>
                  <tr v-for="v in systemVars" :key="v.key">
                    <td><code class="var-code">{{ v.key }}</code></td>
                    <td class="text-caption text-medium-emphasis">{{ v.name }}</td>
                    <td>
                      <VChip size="x-small" color="grey" variant="tonal">{{ v.data_type }}</VChip>
                    </td>
                    <td class="text-caption text-medium-emphasis">{{ v.description }}</td>
                  </tr>
                </tbody>
              </VTable>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>

        <!-- Custom variables table -->
        <div class="var-table-wrap">

          <!-- Empty state -->
          <div v-if="!loading && customVars.length === 0" class="empty-state">
            <VIcon icon="$variable" size="40" class="mb-3 text-medium-emphasis" />
            <p class="text-body-2 font-weight-medium">No custom variables yet</p>
            <p class="text-caption text-medium-emphasis mb-4">
              Create variables to collect user input and pass data between dialogs.
            </p>
            <VBtn color="primary" size="small" prepend-icon="$plus" @click="openCreateForm">
              Create Variable
            </VBtn>
          </div>

          <VTable v-else class="bordered-table" density="comfortable">
            <thead class="bg-gray text-uppercase">
              <tr class="text-secondary">
                <th class="text-left pa-4 cursor-pointer" @mouseenter="hovered = 'name'" @mouseleave="hovered = ''">
                  Variable
                  <SvgSprite v-if="hovered === 'name'" name="custom-chevron-up" class="me-2"
                    style="width: 15px; height: 15px" />
                </th>
                <th class="text-center pa-4">Type</th>
                <th class="text-center pa-4">Stored In</th>
                <th class="text-center pa-4">Default</th>
                <th class="text-center pa-4">Flags</th>
                <th class="text-center pa-4">Actions</th>
              </tr>
            </thead>

            <!-- Loading -->
            <tbody v-if="loading">
              <tr>
                <td colspan="6" class="py-12">
                  <div class="d-flex justify-center">
                    <VProgressCircular indeterminate color="primary" size="48" />
                  </div>
                </td>
              </tr>
            </tbody>

            <tbody v-else class="bg-containerBg">
              <tr v-for="v in filteredCustomVars" :key="v.id" class="var-row">

                <!-- Variable name + key -->
                <td class="pa-4">
                  <div class="d-flex align-center gap-3">
                    <div>
                      <div class="text-subtitle-2 font-weight-medium">{{ v.name }}</div>
                      <code class="var-code">$\{{ v.key }}</code>
                    </div>
                  </div>
                </td>

                <!-- Data type -->
                <td class="pa-4 text-center">
                  <VChip size="small" :color="DATA_TYPE_COLORS[v.data_type] ?? 'grey'" variant="tonal">
                    <SvgSprite :name="DATA_TYPE_ICONS[v.data_type]" class="me-2" style="width: 15px; height: 15px" />
                    {{ v.data_type }}
                  </VChip>
                </td>

                <!-- Stored in -->
                <td class="pa-4 text-center">
                  <VChip size="small" :color="SAVE_IN_COLORS[v.save_in] ?? 'grey'" variant="flat">
                    {{ SAVE_IN_LABELS[v.save_in] ?? v.save_in }}
                  </VChip>
                </td>

                <!-- Default value -->
                <td class="pa-4 text-center">
                  <span v-if="v.default_value" class="text-body-2">{{ v.default_value }}</span>
                  <span v-else class="text-caption text-medium-emphasis">—</span>
                </td>

                <!-- Flags: JS + Sensitive -->
                <td class="pa-4 text-center">
                  <VTooltip location="top" text="Used in JS functions">
                    <template #activator="{ props: tp }">
                      <VIcon v-bind="tp" :icon="v.use_in_js ? '$checkCircle' : '$minus'"
                        :color="v.use_in_js ? 'success' : 'grey-lighten-2'" size="18" class="me-2" />
                    </template>
                  </VTooltip>
                  <VTooltip location="top" :text="v.is_sensitive ? 'Sensitive — masked in logs' : 'Not sensitive'">
                    <template #activator="{ props: tp }">
                      <SvgSprite v-bind="tp" :name="v.is_sensitive ? 'custom-lock' : 'custom-unlock'"
                        style="width: 18px; height: 18px"
                        :class="v.is_sensitive ? 'text-warning' : 'text-grey-lighten-2'" />
                    </template>
                  </VTooltip>
                </td>

                <!-- Actions -->
                <td class="pa-4 text-center">
                  <SvgSprite name="custom-edit-outline" class="text-primary me-2" @click="openEditForm(v)"
                    style="width: 18px; height: 18px" />
                  <SvgSprite name="custom-trash-fill" class="text-error" @click="deleteVariable(v)"
                    style="width: 18px; height: 18px" />
                </td>
              </tr>
            </tbody>
          </VTable>
          <!-- ── Pagination ──────────────────────────────────────────── -->
          <v-card-text v-if="pagination.total > 0" class="pt-4">
            <VRow class="align-center text-center text-sm-start" justify="space-between">
              <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                <span class="text-medium-emphasis">
                  Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} bots
                </span>
              </VCol>
              <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-end">
                <v-pagination v-model="pagination.current_page" :length="pagination.last_page" :total-visible="5"
                  rounded="circle" density="comfortable" variant="outlined" color="primary" />
              </VCol>
            </VRow>
          </v-card-text>
        </div>
      </div><!-- /list panel -->

      <!-- ══════════════════════════════════ FORM ════════════════════════════ -->
      <div class="panel" :class="view === 'form' ? 'panel--visible' : 'panel--hidden-right'">

        <!-- Header -->
        <div class="panel-header">
          <div class="d-flex align-center ga-3">
            <VBtn icon="$arrowLeft" size="small" variant="text" @click="backToList" />
            <div>
              <h4 class="panel-title">{{ editingVariable ? `Edit Variable ${editingVariable.key}` : 'Create Variable' }}
              </h4>
              <p class="panel-subtitle mb-0">
                {{ editingVariable ? `Editing ${editingVariable.key}` : 'Define a new variable for this bot' }}
              </p>
            </div>
          </div>
          <VBtn variant="outlined" size="small" prepend-icon="$formatListBulleted" @click="backToList">
            View Variables
          </VBtn>
        </div>

        <div class="form-body">

          <!-- ── Identity ──────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Identity</div>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField v-model="form.name" label="Display Name *" variant="outlined" density="compact"
                  hint="Human-readable label shown in the builder" persistent-hint @input="onNameInput" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.key" label="Variable Key *" variant="outlined" density="compact"
                  :readonly="!!editingVariable"
                  :hint="editingVariable ? 'Key is locked after creation to prevent breaking existing dialogs' : 'Lowercase, underscores only. Used as $key in dialog text.'"
                  persistent-hint @input="form.keyTouched = true">
                  <template #prepend-inner>
                    <span class="text-caption text-medium-emphasis mr-n1">$</span>
                  </template>
                  <template #append-inner>
                    <VIcon v-if="editingVariable" icon="$lock" size="14" color="grey" />
                  </template>
                </VTextField>
              </VCol>
              <VCol cols="12">
                <VTextField v-model="form.description" label="Description" variant="outlined" density="compact"
                  hint="Optional note for other bot builders" persistent-hint />
              </VCol>
            </VRow>
          </section>

          <!-- ── Data type ─────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Data Type &amp; Default</div>
            <VRow>
              <VCol cols="12" md="6">
                <VSelect v-model="form.data_type" label="Data Type *" :items="DATA_TYPES" variant="outlined"
                  density="compact" hint="How the executor casts the collected value" persistent-hint />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.default_value" label="Default Value" variant="outlined" density="compact"
                  hint="Used when the variable has no collected value yet" persistent-hint />
              </VCol>
            </VRow>
          </section>

          <!-- ── Storage ───────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Storage</div>
            <p class="text-caption text-medium-emphasis mb-3">
              Where should the runtime value be persisted?
            </p>
            <VRow>
              <VCol v-for="opt in SAVE_IN_OPTIONS" :key="opt.value" cols="12" md="4">
                <div class="save-in-card" :class="{ 'save-in-card--active': form.save_in === opt.value }"
                  @click="form.save_in = opt.value">
                  <VIcon :icon="opt.icon" size="22" class="mb-2" />
                  <div class="font-weight-semibold text-body-2">{{ opt.title }}</div>
                  <div class="text-caption text-medium-emphasis mt-1">{{ opt.subtitle }}</div>
                </div>
              </VCol>
            </VRow>
            <VAlert v-if="form.save_in === 'global'" type="warning" variant="tonal" density="compact" rounded="lg"
              class="mt-3">
              <div class="text-caption">
                The variable key must match an existing <strong>Global Variable</strong> key defined in
                <strong>Settings → Global Variables</strong>. The bot can only read it — not write it.
              </div>
            </VAlert>
          </section>

          <!-- ── Flags ─────────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Flags</div>
            <VRow>
              <VCol cols="12" md="6">
                <div class="flag-card" :class="{ 'flag-card--active': form.use_in_js }"
                  @click="form.use_in_js = !form.use_in_js">
                  <div class="d-flex align-center justify-space-between">
                    <div class="d-flex align-center ga-2">
                      <VIcon icon="$codeJson" size="20" />
                      <div>
                        <div class="text-body-2 font-weight-medium">Use in JavaScript Functions</div>
                        <div class="text-caption text-medium-emphasis">Inject into CustomFunction JS sandbox</div>
                      </div>
                    </div>
                    <VSwitch :model-value="form.use_in_js" color="primary" hide-details density="compact" inset
                      @click.stop @update:model-value="form.use_in_js = $event" />
                  </div>
                </div>
              </VCol>
              <VCol cols="12" md="6">
                <div class="flag-card" :class="{ 'flag-card--sensitive': form.is_sensitive }"
                  @click="form.is_sensitive = !form.is_sensitive">
                  <div class="d-flex align-center justify-space-between">
                    <div class="d-flex align-center ga-2">
                      <VIcon icon="$lock" size="20" :color="form.is_sensitive ? 'warning' : undefined" />
                      <div>
                        <div class="text-body-2 font-weight-medium">Sensitive Data</div>
                        <div class="text-caption text-medium-emphasis">Mask value in logs and debug output</div>
                      </div>
                    </div>
                    <VSwitch :model-value="form.is_sensitive" color="warning" hide-details density="compact" inset
                      @click.stop @update:model-value="form.is_sensitive = $event" />
                  </div>
                </div>
              </VCol>
            </VRow>
          </section>

          <!-- ── Preview ───────────────────────────────────────────────────── -->
          <section v-if="form.key" class="form-section">
            <div class="section-label">Preview</div>
            <div class="preview-block">
              <div class="preview-row">
                <span class="preview-label">Placeholder</span>
                <code class="var-code var-code--lg">${{ form.key }}</code>
              </div>
              <div class="preview-row">
                <span class="preview-label">Example dialog text</span>
                <span class="text-body-2">
                  "Hello <code class="var-code">${{ form.key }}</code>, how can I help you today?"
                </span>
              </div>
              <div class="preview-row">
                <span class="preview-label">Executor stores as</span>
                <span class="text-caption">
                  <code>ConversationVariable.key = "{{ form.key }}"</code>
                  <span class="text-medium-emphasis ml-1">→ cast to {{ form.data_type }}</span>
                </span>
              </div>
            </div>
          </section>

          <!-- ── Actions ───────────────────────────────────────────────────── -->
          <div class="form-actions">
            <VBtn variant="outlined" @click="backToList">Cancel</VBtn>
            <VBtn color="primary" :loading="saving" @click="saveVariable">
              {{ editingVariable ? 'Update Variable' : 'Create Variable' }}
            </VBtn>
          </div>

        </div><!-- /form-body -->
      </div><!-- /form panel -->

    </div><!-- /slider-track -->
  </div>
</template>

<style scoped lang="scss">
.variable-library {
  overflow: hidden;
  position: relative;
}

// ── Slider ────────────────────────────────────────────────────────────────────
.slider-track {
  position: relative;
  width: 100%;
}

.panel {
  width: 100%;
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.25s ease;
  will-change: transform, opacity;

  &--visible {
    transform: translateX(0);
    opacity: 1;
    pointer-events: all;
    position: relative;
  }

  &--hidden-left {
    transform: translateX(-100%);
    opacity: 0;
    pointer-events: none;
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
  }

  &--hidden-right {
    transform: translateX(100%);
    opacity: 0;
    pointer-events: none;
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
  }
}

// ── Panel header ──────────────────────────────────────────────────────────────
.panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 20px;
}

.panel-title {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 2px;
}

.panel-subtitle {
  font-size: 0.8rem;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin: 0;
}

// ── Table ─────────────────────────────────────────────────────────────────────
.var-table-wrap {
  border-radius: 10px;
  overflow: hidden;
}

.bordered-table {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.bg-gray {
  background-color: rgba(var(--v-theme-on-surface), 0.04);
}

.bg-containerBg {
  background-color: rgb(var(--v-theme-surface));
}

.system-table {
  th {
    font-size: 0.68rem !important;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: rgba(var(--v-theme-on-surface), 0.45) !important;
  }
}

.var-row {
  transition: background 0.12s;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.03);
  }
}

.var-code {
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.78rem;
  font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
  white-space: nowrap;

  &--lg {
    font-size: 0.88rem;
    padding: 3px 8px;
  }
}

.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: rgba(var(--v-theme-on-surface), 0.5);
}

.gap-2 {
  gap: 8px;
}

.gap-3 {
  gap: 12px;
}

// ── Form ──────────────────────────────────────────────────────────────────────
.form-body {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-section {
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  background: rgba(var(--v-theme-surface), 1);
}

.section-label {
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.09em;
  color: rgba(var(--v-theme-on-surface), 0.4);
  margin-bottom: 12px;
}

.save-in-card {
  padding: 14px;
  border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  cursor: pointer;
  text-align: center;
  transition: border-color 0.18s, background 0.18s;
  height: 100%;

  &:hover {
    border-color: rgba(var(--v-theme-primary), 0.4);
  }

  &--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.05);
  }
}

.flag-card {
  padding: 12px 14px;
  border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  cursor: pointer;
  transition: border-color 0.18s, background 0.18s;

  &--active {
    border-color: rgb(var(--v-theme-primary));
    background: rgba(var(--v-theme-primary), 0.04);
  }

  &--sensitive {
    border-color: rgb(var(--v-theme-warning));
    background: rgba(var(--v-theme-warning), 0.04);
  }

  &:hover {
    border-color: rgba(var(--v-theme-primary), 0.35);
  }
}

.preview-block {
  background: rgba(var(--v-theme-surface-variant), 0.5);
  border-radius: 8px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.preview-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.preview-label {
  font-size: 0.68rem;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: rgba(var(--v-theme-on-surface), 0.4);
  min-width: 140px;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 4px;
  margin-bottom: 8px;
}
</style>