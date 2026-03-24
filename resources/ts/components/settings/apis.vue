<script setup lang="ts">
import { ref, computed, onMounted, nextTick } from 'vue'
import axios from 'axios'

import RichTextField from '@/components/RichTextField.vue'
import TagVariableInput from '@/components/TagVariableInput.vue'
import { Codemirror } from 'vue-codemirror'
import { json } from '@codemirror/lang-json'
import { oneDark } from '@codemirror/theme-one-dark'

const props = defineProps<{ botId: string | number }>()
const emit = defineEmits<{ (e: 'apisUpdated', apis: any[]): void }>()

const cmExtensions = [json(), oneDark]

const snackbar = ref({
  show: false,
  message: '',
  color: 'info',
  timeout: 4000
})

const showSnackbar = (message: string, color = 'error', timeout = 4000) => {
  snackbar.value = { show: true, message, color, timeout }
}

// ── View ──────────────────────────────────────────────────────────────────────
const view = ref<'list' | 'form'>('list')
const editingApi = ref<any>(null)

function openCreateForm() { editingApi.value = null; resetForm(); view.value = 'form' }
function openEditForm(api: any) { editingApi.value = api; populateForm(api); view.value = 'form' }
function backToList() { view.value = 'list'; editingApi.value = null; resetForm(); testResult.value = null }

// ── List state ────────────────────────────────────────────────────────────────
// KEY FIX: never reassign allApis.value to a new array — always mutate in-place.
// Reassigning breaks the computed filteredApis dependency and makes the table vanish.
const allApis = ref<any[]>([])
const availableVariables = ref<string[]>([])
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const search = ref('')
const testResult = ref<any>(null)

// ── Pagination ─────────────────────────────────────────────────────────────────
const page = ref(1)
const perPage = ref(10)
const total = ref(0)
const lastPage = ref(1)

const paginatedApis = computed(() => {
  const q = search.value.toLowerCase().trim()
  const filtered = q
    ? allApis.value.filter(a =>
      a.name.toLowerCase().includes(q) || a.url.toLowerCase().includes(q)
    )
    : allApis.value
  const start = (page.value - 1) * perPage.value
  return filtered.slice(start, start + perPage.value)
})

const filteredTotal = computed(() => {
  const q = search.value.toLowerCase().trim()
  if (!q) return allApis.value.length
  return allApis.value.filter(a =>
    a.name.toLowerCase().includes(q) || a.url.toLowerCase().includes(q)
  ).length
})

const pageCount = computed(() => Math.max(1, Math.ceil(filteredTotal.value / perPage.value)))

// Reset to page 1 when searching
function onSearch() { page.value = 1 }

// ── Constants ─────────────────────────────────────────────────────────────────
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const
const CONTENT_TYPES = [
  { value: 'application/json', title: 'JSON' },
  { value: 'multipart/form-data', title: 'Multipart Form Data' },
  { value: 'application/x-www-form-urlencoded', title: 'URL Encoded Form' },
]
const METHOD_COLOR: Record<string, string> = {
  GET: 'success', POST: 'primary', PUT: 'warning', PATCH: 'secondary', DELETE: 'error',
}

// ── Form ──────────────────────────────────────────────────────────────────────
function blankForm() {
  return {
    name: '', method: 'GET' as typeof METHODS[number], url: '',
    content_type: 'application/json', request_body: '',
    form_data: [{ key: '', value: '', type: 'Text' }] as { key: string; value: string; type: 'Text' | 'File' }[],
    url_encoded_fields: [{ key: '', value: '' }] as { key: string; value: string }[],
    headers: [] as { key: string; value: string }[],
    body_parameters: [] as string[],
    header_parameters: [] as string[],
    showHeaders: false,
  }
}

const form = ref(blankForm())
function resetForm() { form.value = blankForm() }
function populateForm(api: any) {
  form.value = {
    name: api.name ?? '',
    method: api.method ?? 'GET',
    url: api.url ?? '',
    content_type: api.content_type ?? 'application/json',
    request_body: api.request_body ?? '',
    form_data: api.form_data?.length ? api.form_data : [{ key: '', value: '', type: 'Text' }],
    url_encoded_fields: api.url_encoded_fields?.length ? api.url_encoded_fields : [{ key: '', value: '' }],
    headers: api.headers ?? [],
    body_parameters: api.body_parameters ?? [],
    header_parameters: api.header_parameters ?? [],
    showHeaders: (api.headers ?? []).length > 0,
  }
}

const needsBody = computed(() => ['POST', 'PUT', 'PATCH'].includes(form.value.method))

const responseBodyStr = computed(() => prettyJson(testResult.value?.body) || '')
const responseHeadersStr = computed(() => prettyJson(testResult.value?.headers) || '')

// ── Field helpers ─────────────────────────────────────────────────────────────
function addFormField() { form.value.form_data.push({ key: '', value: '', type: 'Text' }) }
function removeFormField(i: number) { form.value.form_data.splice(i, 1) }
function addUrlField() { form.value.url_encoded_fields.push({ key: '', value: '' }) }
function removeUrlField(i: number) { form.value.url_encoded_fields.splice(i, 1) }
function addHeader() { form.value.showHeaders = true; form.value.headers.push({ key: '', value: '' }) }
function removeHeader(i: number) { form.value.headers.splice(i, 1) }

// ── API calls ─────────────────────────────────────────────────────────────────
async function loadApis() {
  loading.value = true
  try {
    const { data } = await axios.get(`/api/bots/${props.botId}/apis`)
    // Mutate in-place: splice the whole array, then push all new items
    allApis.value.splice(0, allApis.value.length, ...(data.data ?? []))
    total.value = data.meta?.total ?? allApis.value.length
    lastPage.value = data.meta?.last_page ?? 1
    emit('apisUpdated', allApis.value)
    await loadVariables()
  } catch {
    showSnackbar('Failed to load APIs', 'error', 2000)
  } finally { loading.value = false }
}

async function loadVariables() {
  try {
    const { data } = await axios.get(
      `/api/bots/${props.botId}/variables?all=true`
    )

    availableVariables.value = data.data?.map((v: any) => v.key) ?? []
  } catch {
    /* silent */
  }
}
async function saveApi() {
  saving.value = true
  // Capture isEditing BEFORE backToList clears editingApi
  const isEditing = !!editingApi.value
  try {
    const payload = buildPayload()
    if (isEditing) {
      const { data } = await axios.put(`/api/bots/${props.botId}/apis/${editingApi.value.id}`, payload)
      const saved = data.data
      // Mutate in-place so filteredApis / paginatedApis stay reactive
      const idx = allApis.value.findIndex(a => a.id === saved.id)
      if (idx !== -1) allApis.value.splice(idx, 1, saved)
      else allApis.value.push(saved)
    } else {
      const { data } = await axios.post(`/api/bots/${props.botId}/apis`, payload)
      const saved = data.data
      // Push to existing array — never reassign the ref
      allApis.value.push(saved)
      // Jump to the page that contains the new item
      page.value = pageCount.value
    }

    emit('apisUpdated', allApis.value.slice())

    // Navigate back FIRST, then show the toast (avoids race with reactive resets)
    backToList()
    showSnackbar(isEditing ? 'API updated' : 'API created', 'success', 2000)


  } catch (err: any) {
    showSnackbar(err.response?.data?.message ?? err.message, 'error', 2000)

  } finally { saving.value = false }
}

async function confirmDelete(api: any) {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "${api.name}"?`, text: 'This cannot be undone.',
    icon: 'warning', showCancelButton: true, confirmButtonColor: '#ef4444', confirmButtonText: 'Delete',
  })
  if (!isConfirmed) return
  try {
    await axios.delete(`/api/bots/${props.botId}/apis/${api.id}`)
    // Mutate in-place
    const idx = allApis.value.findIndex(a => a.id === api.id)
    if (idx !== -1) allApis.value.splice(idx, 1)
    // Clamp page if we deleted the last item on this page
    if (page.value > pageCount.value) page.value = Math.max(1, pageCount.value)
    emit('apisUpdated', allApis.value.slice())
  } catch (err: any) {

    showSnackbar(err.response?.data?.message ?? err.message, 'error', 2000)
  }
}

async function runTest() {
  testing.value = true; testResult.value = null
  try {
    const cfg: any = { method: form.value.method, url: form.value.url, headers: {} }
    if (form.value.showHeaders)
      form.value.headers.filter(h => h.key).forEach(h => { cfg.headers[h.key] = h.value })
    if (needsBody.value) {
      const ct = form.value.content_type
      if (ct === 'application/json') {
        cfg.data = JSON.parse(form.value.request_body || '{}')
        cfg.headers['Content-Type'] = 'application/json'
      } else if (ct === 'multipart/form-data') {
        const fd = new FormData()
        form.value.form_data.filter(f => f.key).forEach(f => fd.append(f.key, f.value))
        cfg.data = fd
      } else {
        const p = new URLSearchParams()
        form.value.url_encoded_fields.filter(f => f.key).forEach(f => p.append(f.key, f.value))
        cfg.data = p; cfg.headers['Content-Type'] = 'application/x-www-form-urlencoded'
      }
    }
    const res = await axios(cfg)
    testResult.value = { success: true, status: res.status, statusText: res.statusText, body: res.data, headers: res.headers, error: null }
  } catch (err: any) {
    testResult.value = { success: false, status: err.response?.status ?? 0, statusText: err.response?.statusText ?? 'Request failed', body: err.response?.data ?? null, headers: err.response?.headers ?? {}, error: err.message }
  } finally {
    testing.value = false
    await nextTick()
    document.getElementById('sandbox-panel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }
}

function buildPayload() {
  return {
    name: form.value.name, method: form.value.method, url: form.value.url,
    content_type: form.value.content_type,
    headers: form.value.headers.filter(h => h.key),
    request_body: form.value.request_body || undefined,
    form_data: form.value.form_data.filter(f => f.key),
    url_encoded_fields: form.value.url_encoded_fields.filter(f => f.key),
    body_parameters: form.value.body_parameters,
    header_parameters: form.value.header_parameters,
    is_active: true,
  }
}

function importApi() { /* TODO */ }

function statusColor(s: number) {
  if (s >= 200 && s < 300) return 'success'
  if (s >= 400) return 'error'
  return 'warning'
}
function prettyJson(val: any): string {
  if (!val) return ''
  try { return JSON.stringify(typeof val === 'string' ? JSON.parse(val) : val, null, 2) }
  catch { return String(val) }
}

onMounted(loadApis)
defineExpose({ loadApis })
</script>

<template>
  <div class="api-library">
    <div class="slider-track">

      <!-- ════════════════════════ LIST PANEL ════════════════════════════ -->
      <div class="panel" :class="view === 'list' ? 'panel--visible' : 'panel--hidden-left'">

        <v-row align="center" class="mb-4">
          <v-col cols="12" sm="8">
            <h4 class="text-h6 font-weight-bold">API Library</h4>
            <p class="text-subtitle-2 text-medium-emphasis mt-1 mb-0">
              Integrate your bot with external services to push and fetch data dynamically.
            </p>
          </v-col>
          <v-col cols="12" sm="4" class="d-flex justify-end ga-2">
            <v-btn variant="outlined" prepend-icon="$trayArrowUp" @click="importApi">Import API</v-btn>
            <v-btn color="primary" prepend-icon="$plus" @click="openCreateForm">Add API</v-btn>
          </v-col>
        </v-row>

        <v-row class="mb-3" align="center">
          <v-col cols="12" sm="5">
            <v-text-field v-model="search" label="Search APIs…" prepend-inner-icon="$magnify" variant="outlined"
              clearable hide-details density="comfortable" @update:model-value="onSearch" />
          </v-col>
          <v-col cols="12" sm="7" class="d-flex justify-end align-center ga-3">
            <span class="text-caption text-medium-emphasis">
              {{ filteredTotal }} {{ filteredTotal === 1 ? 'API' : 'APIs' }}
            </span>
            <v-select v-model="perPage" :items="[5, 10, 25, 50]" label="Per page" variant="outlined" density="compact"
              hide-details style="max-width:100px" @update:model-value="page = 1" />
          </v-col>
        </v-row>

        <!-- Table -->
        <v-table class="bordered-table" density="comfortable">
          <thead class="table-head">
            <tr>
              <th class="text-left pa-4">Name</th>
              <th class="text-left pa-4">Method</th>
              <th class="text-left pa-4">URL</th>
              <th class="text-left pa-4" style="width:90px">Actions</th>
            </tr>
          </thead>

          <tbody v-if="loading">
            <tr>
              <td colspan="4" class="py-12 text-center">
                <v-progress-circular indeterminate color="primary" size="48" />
              </td>
            </tr>
          </tbody>

          <tbody v-else>
            <tr v-if="!filteredTotal">
              <td colspan="4" class="text-center py-10">
                <SvgSprite name="custom-code" class="text-grey" style="width: 52px; height:52px" />

                <p class="text-body-2 text-medium-emphasis mb-3 mt-2">
                  {{ search ? 'No APIs match your search.' : 'No API integrations yet.' }}
                </p>
                <v-btn v-if="!search" color="primary" size="small" prepend-icon="$plus" @click="openCreateForm">
                  Add API
                </v-btn>
              </td>
            </tr>

            <tr v-for="api in paginatedApis" :key="api.id" class="table-row">
              <td class="pa-4 font-weight-medium">{{ api.name }}</td>
              <td class="pa-4">
                <v-chip size="small" variant="tonal" :color="METHOD_COLOR[api.method] ?? 'grey'"
                  class="font-weight-bold">{{ api.method }}</v-chip>
              </td>
              <td class="pa-4">
                <span class="url-cell text-caption text-medium-emphasis">{{ api.url }}</span>
              </td>
              <td class="pa-4">
                <div class="d-flex align-center ga-1">
                  <SvgSprite name="custom-edit-outline" class="action-icon text-primary" @click="openEditForm(api)" />
                  <SvgSprite name="custom-trash-fill" class="action-icon text-error" @click="confirmDelete(api)" />
                </div>
              </td>
            </tr>
          </tbody>
        </v-table>

        <!-- Pagination -->
        <div v-if="pageCount > 1" class="d-flex justify-center mt-4">
          <v-pagination v-model="page" :length="pageCount" :total-visible="6" density="comfortable" rounded="circle" />
        </div>

      </div>

      <!-- ════════════════════════ FORM PANEL ════════════════════════════ -->
      <div class="panel" :class="view === 'form' ? 'panel--visible' : 'panel--hidden-right'">

        <v-row align="center" class="mb-4">
          <v-col cols="12" sm="8" class="d-flex align-center ga-3">
            <v-btn icon="$arrowLeft" size="small" variant="text" @click="backToList" />
            <div>
              <h4 class="text-h6 font-weight-bold mb-0">{{ editingApi ? 'Edit API' : 'New API' }}</h4>
              <p class="text-subtitle-2 text-medium-emphasis mb-0">
                {{ editingApi ? `Editing "${editingApi.name}"` : 'Configure a new API integration' }}
              </p>
            </div>
          </v-col>
          <v-col cols="12" sm="4" class="d-flex justify-end">
            <v-btn variant="outlined" size="small" prepend-icon="$formatListBulleted" @click="backToList">
              View APIs
            </v-btn>
          </v-col>
        </v-row>

        <!-- 1. Name + Method -->
        <v-card variant="outlined" rounded="lg" class="mb-2 pa-4">
          <v-row>
            <v-col cols="12" md="8">
              <v-text-field v-model="form.name" label="API Name *" variant="outlined" density="compact" required />
            </v-col>
            <v-col cols="12" md="4">
              <v-select v-model="form.method" label="Method *" :items="METHODS" variant="outlined" density="compact" />
            </v-col>
          </v-row>
        </v-card>

        <!-- 2. Payload Type -->
        <v-card v-if="needsBody" variant="outlined" rounded="lg" class="mb-2 pa-4">
          <p class="section-label">Payload Type</p>
          <v-select v-model="form.content_type" :items="CONTENT_TYPES" item-title="title" item-value="value"
            variant="outlined" density="compact" hide-details />
        </v-card>

        <!-- 3. URL -->
        <v-card variant="outlined" rounded="lg" class="mb-2 pa-4">
          <p class="section-label">URL</p>
          <RichTextField v-model="form.url" placeholder="https://api.example.com/endpoint"
            :available-variables="availableVariables" field-type="body" hide-details />
        </v-card>

        <!-- 4. Payload -->
        <v-card v-if="needsBody" variant="outlined" rounded="lg" class="mb-2 pa-4">
          <p class="section-label">Payload</p>

          <template v-if="form.content_type === 'application/json'">
            <Codemirror v-model="form.request_body" placeholder='{ "key": "{{variable_name}}" }'
              :style="{ height: '200px', borderRadius: '8px', overflow: 'hidden' }" :extensions="cmExtensions" />
          </template>

          <template v-else-if="form.content_type === 'multipart/form-data'">
            <div v-for="(f, i) in form.form_data" :key="i" class="d-flex ga-2 mb-2 align-center">
              <v-text-field v-model="f.key" placeholder="Key" variant="outlined" density="compact" hide-details
                style="width:150px;flex-shrink:0" />
              <v-select v-model="f.type" :items="['Text', 'File']" variant="outlined" density="compact" hide-details
                style="width:90px;flex-shrink:0" />
              <div style="flex:1;min-width:0">
                <RichTextField v-model="f.value" placeholder="Value or {{variable}}"
                  :available-variables="availableVariables" field-type="body" hide-details />
              </div>
              <v-btn v-if="form.form_data.length > 1" icon="$close" size="x-small" variant="text" color="error"
                @click="removeFormField(i)" />
            </div>
            <v-btn size="x-small" variant="outlined" prepend-icon="$plus" @click="addFormField">Add Field</v-btn>
          </template>

          <template v-else-if="form.content_type === 'application/x-www-form-urlencoded'">
            <div v-for="(f, i) in form.url_encoded_fields" :key="i" class="d-flex ga-2 mb-2 align-center">
              <v-text-field v-model="f.key" placeholder="Key" variant="outlined" density="compact" hide-details
                style="width:200px;flex-shrink:0" />
              <div style="flex:1;min-width:0">
                <RichTextField v-model="f.value" placeholder="Value or {{variable}}"
                  :available-variables="availableVariables" field-type="body" hide-details />
              </div>
              <v-btn v-if="form.url_encoded_fields.length > 1" icon="$close" size="x-small" variant="text" color="error"
                @click="removeUrlField(i)" />
            </div>
            <v-btn size="x-small" variant="outlined" prepend-icon="$plus" @click="addUrlField">Add Field</v-btn>
          </template>
        </v-card>

        <!-- 5. Headers -->
        <v-card variant="outlined" rounded="lg" class="mb-2 pa-4">
          <v-checkbox v-model="form.showHeaders" label="Add Headers" density="compact" hide-details color="primary"
            class="mb-0" />
          <Transition name="expand">
            <div v-if="form.showHeaders" class="mt-3">
              <div v-for="(h, i) in form.headers" :key="i" class="d-flex ga-2 mb-2 align-center">
                <v-text-field v-model="h.key" placeholder="Header Key" variant="outlined" density="compact" hide-details
                  style="width:200px;flex-shrink:0" />
                <v-text-field v-model="h.value" placeholder="Header Value" variant="outlined" density="compact"
                  hide-details style="flex:1" />
                <v-btn icon="$close" size="x-small" variant="text" color="error" @click="removeHeader(i)" />
              </div>
              <v-btn size="x-small" variant="outlined" prepend-icon="$plus" @click="addHeader">Add Header</v-btn>
            </div>
          </Transition>
        </v-card>

        <!-- 6. Sandbox -->
        <v-card variant="outlined" rounded="lg" class="mb-2 pa-4" id="sandbox-panel">
          <div class="d-flex align-center justify-space-between mb-3">
            <p class="section-label mb-0">Sandbox</p>
            <v-btn color="secondary" size="small" prepend-icon="$play" :loading="testing" @click="runTest">Run</v-btn>
          </div>

          <div v-if="testResult" class="d-flex align-center flex-wrap ga-2 mb-3">
            <v-chip :color="statusColor(testResult.status)" variant="tonal" size="small" class="font-weight-bold">
              {{ testResult.status }} {{ testResult.statusText }}
            </v-chip>
            <v-chip :color="testResult.success ? 'success' : 'error'" variant="flat" size="small">
              {{ testResult.success ? 'Success' : 'Failed' }}
            </v-chip>
            <span v-if="testResult.error" class="text-error text-caption">{{ testResult.error }}</span>
          </div>

          <v-row>
            <v-col cols="12" md="6">
              <p class="section-label">Body</p>
              <Codemirror :model-value="responseBodyStr || '// Response body will appear here…'"
                :style="{ height: '260px', borderRadius: '8px', overflow: 'hidden' }" :extensions="cmExtensions"
                :disabled="true" />
            </v-col>
            <v-col cols="12" md="6">
              <p class="section-label">Headers</p>
              <Codemirror :model-value="responseHeadersStr || '// Response headers will appear here…'"
                :style="{ height: '260px', borderRadius: '8px', overflow: 'hidden' }" :extensions="cmExtensions"
                :disabled="true" />
            </v-col>
          </v-row>
        </v-card>

        <!-- 7. Bot Flow Values -->
        <v-card variant="outlined" rounded="lg" class="mb-2 pa-4">
          <p class="section-label">Values to Use in Bot Flows</p>
          <v-row>
            <v-col cols="12" md="6">
              <TagVariableInput v-model="form.body_parameters" :available-variables="availableVariables"
                label="Body Parameters" placeholder="e.g. user_id, token…" />
            </v-col>
            <v-col cols="12" md="6">
              <TagVariableInput v-model="form.header_parameters" :available-variables="availableVariables"
                label="Header Parameters" placeholder="e.g. x-request-id…" />
            </v-col>
          </v-row>
        </v-card>

        <!-- Actions -->
        <div class="d-flex justify-end ga-3 mt-2 mb-4">
          <v-btn variant="outlined" @click="backToList">Cancel</v-btn>
          <v-btn color="primary" :loading="saving" @click="saveApi">
            {{ editingApi ? 'Update API' : 'Create API' }}
          </v-btn>
        </div>

      </div>
    </div>
  </div>

  <!-- Snackbar -->
  <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
    {{ snackbar.message }}
    <template #actions>
      <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
    </template>
  </VSnackbar>
</template>

<style scoped lang="scss">
// ── Slider ────────────────────────────────────────────────────────────────────
.api-library {
  overflow: hidden;
  position: relative;
}

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
    inset: 0;
  }

  &--hidden-right {
    transform: translateX(100%);
    opacity: 0;
    pointer-events: none;
    position: absolute;
    inset: 0;
  }
}

// ── Table ─────────────────────────────────────────────────────────────────────
.bordered-table {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.table-head th {
  font-size: 0.7rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5) !important;
  background-color: rgba(var(--v-theme-on-surface), 0.03);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)) !important;
}

.table-row {
  transition: background 0.12s;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.04);
  }

  &:not(:last-child) td {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  }
}

.url-cell {
  display: block;
  max-width: 360px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.action-icon {
  width: 18px;
  height: 18px;
  cursor: pointer;
  opacity: 0.7;
  transition: opacity 0.15s;

  &:hover {
    opacity: 1;
  }
}

// ── Section labels inside form cards ──────────────────────────────────────────
.section-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  margin-bottom: 12px;
}

// ── Expand transition ─────────────────────────────────────────────────────────
.expand-enter-active,
.expand-leave-active {
  transition: all 0.2s ease;
  overflow: hidden;
}

.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}

.expand-enter-to,
.expand-leave-from {
  max-height: 600px;
  opacity: 1;
}
</style>