<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue'
import RichTextField from '@/components/RichTextField.vue'
import axios from 'axios'
import Swal from 'sweetalert2'

// ── Props ─────────────────────────────────────────────────────────────────────
const props = defineProps<{
  botId: string | number
  availableVariables?: string[]
}>()

const emit = defineEmits<{
  (e: 'apisUpdated', apis: any[]): void
}>()

// ── View state ────────────────────────────────────────────────────────────────
// 'list' | 'form'  — drives the slider
const view = ref<'list' | 'form'>('list')
const editingApi = ref<any>(null)

function openCreateForm() {
  editingApi.value = null
  resetForm()
  view.value = 'form'
}
function openEditForm(api: any) {
  editingApi.value = api
  populateForm(api)
  view.value = 'form'
}
function backToList() {
  view.value = 'list'
  editingApi.value = null
  resetForm()
  testResult.value = null
}

// ── Data ──────────────────────────────────────────────────────────────────────
const allApis = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const search = ref('')
const testResult = ref<any>(null)

const filteredApis = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return allApis.value
  return allApis.value.filter(a =>
    a.name.toLowerCase().includes(q) ||
    (a.description ?? '').toLowerCase().includes(q) ||
    a.url.toLowerCase().includes(q)
  )
})

// ── Form model ────────────────────────────────────────────────────────────────
const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'] as const
const AUTH_TYPES = [
  { value: 'none', label: 'None' },
  { value: 'basic', label: 'Basic Auth' },
  { value: 'bearer', label: 'Bearer Token' },
  { value: 'api_key', label: 'API Key' },
  { value: 'oauth2', label: 'OAuth 2.0' },
]
const CONTENT_TYPES = [
  { value: 'application/json', label: 'JSON' },
  { value: 'multipart/form-data', label: 'Form Data (multipart)' },
  { value: 'application/x-www-form-urlencoded', label: 'URL Encoded Form' },
]

const form = ref(blankForm())

function blankForm() {
  return {
    name: '',
    description: '',
    method: 'GET' as typeof METHODS[number],
    url: '',
    auth_type: 'none',
    auth_config: {} as Record<string, string>,
    content_type: 'application/json',
    headers: [] as { key: string; value: string }[],
    request_body: '',
    form_data: [] as { key: string; value: string; type: 'Text' | 'File' }[],
    url_encoded_fields: [] as { key: string; value: string }[],
    body_parameters: [] as string[],
    header_parameters: [] as string[],
    variable_mappings: [] as { response_path: string; variable: string }[],
    timeout_seconds: 30,
    retry_attempts: 0,
    is_active: true,
    // UI-only
    showHeaders: false,
    showAdvanced: false,
    showVariableMaps: false,
  }
}

function resetForm() { form.value = blankForm() }

function populateForm(api: any) {
  form.value = {
    name: api.name ?? '',
    description: api.description ?? '',
    method: api.method ?? 'GET',
    url: api.url ?? '',
    auth_type: api.auth_type ?? 'none',
    auth_config: {},                   // never pre-fill secrets
    content_type: api.content_type ?? 'application/json',
    headers: api.headers ?? [],
    request_body: api.request_body ?? '',
    form_data: api.form_data ?? [],
    url_encoded_fields: api.url_encoded_fields ?? [],
    body_parameters: api.body_parameters ?? [],
    header_parameters: api.header_parameters ?? [],
    variable_mappings: api.variable_mappings ?? [],
    timeout_seconds: api.timeout_seconds ?? 30,
    retry_attempts: api.retry_attempts ?? 0,
    is_active: api.is_active ?? true,
    showHeaders: (api.headers ?? []).length > 0,
    showAdvanced: false,
    showVariableMaps: (api.variable_mappings ?? []).length > 0,
  }
}

const needsBody = computed(() => ['POST', 'PUT', 'PATCH'].includes(form.value.method))

// ── Sections helpers ──────────────────────────────────────────────────────────
function addHeader() { form.value.headers.push({ key: '', value: '' }) }
function removeHeader(i: number) { form.value.headers.splice(i, 1) }

function addFormField() { form.value.form_data.push({ key: '', value: '', type: 'Text' }) }
function removeFormField(i: number) { form.value.form_data.splice(i, 1) }

function addUrlField() { form.value.url_encoded_fields.push({ key: '', value: '' }) }
function removeUrlField(i: number) { form.value.url_encoded_fields.splice(i, 1) }

function addVarMap() { form.value.variable_mappings.push({ response_path: '', variable: '' }) }
function removeVarMap(i: number) { form.value.variable_mappings.splice(i, 1) }

// ── Load / Save / Delete ──────────────────────────────────────────────────────
async function loadApis() {
  loading.value = true
  try {
    const { data } = await axios.get(`/api/bots/${props.botId}/apis`)
    allApis.value = data.data ?? []
    emit('apisUpdated', allApis.value)
  } catch {
    Swal.fire({ icon: 'error', title: 'Failed to load APIs', timer: 2000, showConfirmButton: false })
  } finally {
    loading.value = false
  }
}

async function saveApi() {
  saving.value = true
  try {
    const payload = buildPayload()
    let response: any
    if (editingApi.value) {
      response = await axios.put(`/api/bots/${props.botId}/apis/${editingApi.value.id}`, payload)
      const idx = allApis.value.findIndex(a => a.id === editingApi.value.id)
      if (idx !== -1) allApis.value.splice(idx, 1, response.data.data)
    } else {
      response = await axios.post(`/api/bots/${props.botId}/apis`, payload)
      allApis.value.push(response.data.data)
    }
    emit('apisUpdated', allApis.value)
    Swal.fire({ icon: 'success', title: editingApi.value ? 'API updated' : 'API created', timer: 1800, showConfirmButton: false })
    backToList()
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Save failed', text: err.response?.data?.message ?? err.message })
  } finally {
    saving.value = false
  }
}

async function deleteApi(api: any) {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "${api.name}"?`,
    text: 'This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    confirmButtonText: 'Delete',
  })
  if (!isConfirmed) return
  try {
    await axios.delete(`/api/bots/${props.botId}/apis/${api.id}`)
    allApis.value = allApis.value.filter(a => a.id !== api.id)
    emit('apisUpdated', allApis.value)
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Delete failed', text: err.response?.data?.message ?? err.message })
  }
}

// ── Test request ──────────────────────────────────────────────────────────────
async function runTest() {
  testing.value = true
  testResult.value = null
  try {
    const endpoint = editingApi.value
      ? `/api/bots/${props.botId}/apis/${editingApi.value.id}/test`
      : `/api/bots/${props.botId}/apis/test-draft`

    const payload = editingApi.value
      ? { variables: {} }
      : { ...buildPayload(), variables: {} }

    const { data } = await axios.post(endpoint, payload)
    testResult.value = data
  } catch (err: any) {
    testResult.value = {
      success: false,
      status: err.response?.status ?? 0,
      statusText: 'Request failed',
      error: err.response?.data?.error ?? err.message,
      body: null,
      headers: {},
    }
  } finally {
    testing.value = false
    await nextTick()
    document.getElementById('test-result-panel')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }
}

function buildPayload() {
  return {
    name: form.value.name,
    description: form.value.description,
    method: form.value.method,
    url: form.value.url,
    auth_type: form.value.auth_type,
    auth_config: Object.keys(form.value.auth_config).length ? form.value.auth_config : undefined,
    content_type: form.value.content_type,
    headers: form.value.headers.filter(h => h.key),
    request_body: form.value.request_body || undefined,
    form_data: form.value.form_data.filter(f => f.key),
    url_encoded_fields: form.value.url_encoded_fields.filter(f => f.key),
    body_parameters: form.value.body_parameters,
    header_parameters: form.value.header_parameters,
    variable_mappings: form.value.variable_mappings.filter(m => m.response_path && m.variable),
    timeout_seconds: form.value.timeout_seconds,
    retry_attempts: form.value.retry_attempts,
    is_active: form.value.is_active,
  }
}

// ── Formatting helpers ────────────────────────────────────────────────────────
const METHOD_COLORS: Record<string, string> = {
  GET: 'text-emerald-600 bg-emerald-50',
  POST: 'text-blue-600 bg-blue-50',
  PUT: 'text-amber-600 bg-amber-50',
  PATCH: 'text-purple-600 bg-purple-50',
  DELETE: 'text-red-600 bg-red-50',
}
function methodStyle(m: string) { return METHOD_COLORS[m] ?? 'text-gray-600 bg-gray-100' }

function statusColor(status: number) {
  if (status >= 200 && status < 300) return 'text-green-600'
  if (status >= 400) return 'text-red-600'
  return 'text-amber-600'
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

    <!-- ── Slider wrapper ──────────────────────────────────────────────────── -->
    <div class="slider-track">

      <!-- ════════════════════════════════ LIST PANEL ════════════════════════ -->
      <div class="panel" :class="view === 'list' ? 'panel--visible' : 'panel--hidden-left'">

        <!-- Header -->
        <div class="panel-header">
          <div>
            <h4 class="panel-title">API Integrations</h4>
            <p class="panel-subtitle">Connect your bot to external services — fetch data, submit forms, trigger
              webhooks.</p>
          </div>
          <VBtn color="primary" prepend-icon="$plus" @click="openCreateForm">
            Create API
          </VBtn>
        </div>

        <!-- Search -->
        <VTextField v-model="search" placeholder="Search by name, URL or description…" prepend-inner-icon="$magnify"
          variant="outlined" density="compact" hide-details class="mb-4" />

        <!-- Table -->
        <div class="api-table-wrap">
          <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-2" />

          <div v-if="!loading && filteredApis.length === 0" class="empty-state">
            <SvgSprite name="custom-code" class="mb-3 text-medium-emphasis" style="width:48px;height:48px" />
            <p class="text-body-1 font-weight-medium">No API integrations yet</p>
            <p class="text-caption text-medium-emphasis mb-4">Create one to start fetching data dynamically in your
              flows.</p>
            <VBtn color="primary" size="small" prepend-icon="$plus" @click="openCreateForm">Create API</VBtn>
          </div>

          <VTable v-else density="compact" class="api-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Method</th>
                <th>URL</th>
                <th>Auth</th>
                <th>Status</th>
                <th width="80">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="api in filteredApis" :key="api.id" class="api-row">
                <td>
                  <div class="font-weight-medium">{{ api.name }}</div>
                  <div v-if="api.description" class="text-caption text-medium-emphasis text-truncate"
                    style="max-width:200px">{{ api.description }}</div>
                </td>
                <td>
                  <span class="method-badge" :class="methodStyle(api.method)">{{ api.method }}</span>
                </td>
                <td>
                  <span class="url-cell text-caption">{{ api.url }}</span>
                </td>
                <td>
                  <VChip size="x-small" variant="tonal" color="info">
                    {{ api.auth_type?.replace('_', ' ') ?? 'none' }}
                  </VChip>
                </td>
                <td>
                  <VIcon :icon="api.is_active ? '$checkCircle' : '$closeCircle'"
                    :color="api.is_active ? 'success' : 'grey'" size="18" />
                </td>
                <td>
                  <div class="d-flex align-center ga-1">
                    <VBtn icon="$pencil" size="x-small" variant="text" @click="openEditForm(api)" />
                    <VBtn icon="$delete" size="x-small" variant="text" color="error" @click="deleteApi(api)" />
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>
        </div>
      </div>

      <!-- ════════════════════════════════ FORM PANEL ════════════════════════ -->
      <div class="panel" :class="view === 'form' ? 'panel--visible' : 'panel--hidden-right'">

        <!-- Header -->
        <div class="panel-header">
          <div class="d-flex align-center ga-3">
            <VBtn icon="$arrowLeft" size="small" variant="text" @click="backToList" />
            <div>
              <h4 class="panel-title">{{ editingApi ? 'Edit API' : 'Create API' }}</h4>
              <p class="panel-subtitle mb-0">{{ editingApi ? `Editing "${editingApi.name}"` :
                'Configure a new external API integration' }}</p>
            </div>
          </div>
          <VBtn variant="outlined" size="small" prepend-icon="$formatListBulleted" @click="backToList">
            View APIs
          </VBtn>
        </div>

        <div class="form-body">

          <!-- ── Basic info ───────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Basic</div>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField v-model="form.name" label="API Name *" variant="outlined" density="compact" required />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.description" label="Description" variant="outlined" density="compact" />
              </VCol>
            </VRow>
          </section>

          <!-- ── Request ─────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Request</div>
            <VRow>
              <VCol cols="12" md="3">
                <VSelect v-model="form.method" label="Method" :items="METHODS" variant="outlined" density="compact" />
              </VCol>
              <VCol cols="12" md="9">
                <RichTextField v-model="form.url" placeholder="https://api.example.com/endpoint" variant="outlined"
                  density="compact" :available-variables="availableVariables" field-type="button"
                  show-variable-picker />
                <!-- <VTextField v-model="form.url" label="URL *" placeholder="https://api.example.com/endpoint"
                  variant="outlined" density="compact" required
                  hint="Use {{variable_name}} to interpolate conversation variables" persistent-hint /> -->
              </VCol>
            </VRow>
          </section>

          <!-- ── Authentication ──────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Authentication</div>
            <VRow>
              <VCol cols="12" md="4">
                <VSelect v-model="form.auth_type" label="Auth Type"
                  :items="AUTH_TYPES.map(a => ({ value: a.value, title: a.label }))" variant="outlined"
                  density="compact" />
              </VCol>

              <!-- Basic -->
              <template v-if="form.auth_type === 'basic'">
                <VCol cols="12" md="4">
                  <VTextField v-model="form.auth_config.username" label="Username" variant="outlined"
                    density="compact" />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField v-model="form.auth_config.password" label="Password" type="password" variant="outlined"
                    density="compact" />
                </VCol>
              </template>

              <!-- Bearer -->
              <template v-else-if="form.auth_type === 'bearer'">
                <VCol cols="12" md="8">
                  <VTextField v-model="form.auth_config.token" label="Bearer Token" type="password" variant="outlined"
                    density="compact"
                    :placeholder="editingApi?.has_auth ? '(leave blank to keep existing token)' : ''" />
                </VCol>
              </template>

              <!-- API Key -->
              <template v-else-if="form.auth_type === 'api_key'">
                <VCol cols="12" md="4">
                  <VTextField v-model="form.auth_config.key" label="Header Name" placeholder="X-API-Key"
                    variant="outlined" density="compact" />
                </VCol>
                <VCol cols="12" md="4">
                  <VTextField v-model="form.auth_config.value" label="Key Value" type="password" variant="outlined"
                    density="compact" />
                </VCol>
              </template>

              <!-- OAuth2 -->
              <template v-else-if="form.auth_type === 'oauth2'">
                <VCol cols="12" md="8">
                  <VTextField v-model="form.auth_config.access_token" label="Access Token" type="password"
                    variant="outlined" density="compact" />
                </VCol>
              </template>
            </VRow>
          </section>

          <!-- ── Headers ────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-toggle" @click="form.showHeaders = !form.showHeaders">
              <VIcon :icon="form.showHeaders ? '$chevronDown' : '$chevronRight'" size="16" class="mr-1" />
              <span class="section-label mb-0">Custom Headers</span>
              <VChip v-if="form.headers.length" size="x-small" class="ml-2">{{ form.headers.length }}</VChip>
            </div>
            <Transition name="expand">
              <div v-if="form.showHeaders" class="mt-3">
                <div v-for="(h, i) in form.headers" :key="i" class="d-flex ga-2 mb-2 align-center">
                  <VTextField v-model="h.key" placeholder="Key" variant="outlined" density="compact" hide-details
                    style="width:200px" />
                  <VTextField v-model="h.value" placeholder="Value" variant="outlined" density="compact" hide-details
                    style="flex:1" />
                  <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeHeader(i)" />
                </div>
                <VBtn size="x-small" variant="outlined" prepend-icon="$plus" @click="addHeader">Add Header</VBtn>
              </div>
            </Transition>
          </section>

          <!-- ── Body (POST/PUT/PATCH) ───────────────────────────────────── -->
          <section v-if="needsBody" class="form-section">
            <div class="section-label">Request Body</div>
            <VSelect v-model="form.content_type" label="Content Type"
              :items="CONTENT_TYPES.map(c => ({ value: c.value, title: c.label }))" variant="outlined" density="compact"
              class="mb-3" />

            <!-- JSON -->
            <template v-if="form.content_type === 'application/json'">
              <VTextarea v-model="form.request_body" label="JSON Body" variant="outlined" rows="6"
                font-family="monospace" placeholder='{"key": "{{variable_name}}"}'
                hint="Use {{variable_name}} to inject conversation variables" persistent-hint />
            </template>

            <!-- Form data -->
            <template v-else-if="form.content_type === 'multipart/form-data'">
              <div v-for="(f, i) in form.form_data" :key="i" class="d-flex ga-2 mb-2 align-center">
                <VTextField v-model="f.key" placeholder="Key" variant="outlined" density="compact" hide-details
                  style="width:160px" />
                <VSelect v-model="f.type" :items="['Text', 'File']" variant="outlined" density="compact" hide-details
                  style="width:90px" />
                <VTextField v-model="f.value" placeholder="Value or {{variable}}" variant="outlined" density="compact"
                  hide-details style="flex:1" />
                <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeFormField(i)" />
              </div>
              <VBtn size="x-small" variant="outlined" prepend-icon="$plus" @click="addFormField">Add Field</VBtn>
            </template>

            <!-- URL encoded -->
            <template v-else-if="form.content_type === 'application/x-www-form-urlencoded'">
              <div v-for="(f, i) in form.url_encoded_fields" :key="i" class="d-flex ga-2 mb-2 align-center">
                <VTextField v-model="f.key" placeholder="Key" variant="outlined" density="compact" hide-details
                  style="width:200px" />
                <VTextField v-model="f.value" placeholder="Value or {{variable}}" variant="outlined" density="compact"
                  hide-details style="flex:1" />
                <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeUrlField(i)" />
              </div>
              <VBtn size="x-small" variant="outlined" prepend-icon="$plus" @click="addUrlField">Add Field</VBtn>
            </template>
          </section>

          <!-- ── Variable mappings ──────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-toggle" @click="form.showVariableMaps = !form.showVariableMaps">
              <VIcon :icon="form.showVariableMaps ? '$chevronDown' : '$chevronRight'" size="16" class="mr-1" />
              <span class="section-label mb-0">Save Response Values to Variables</span>
              <VChip v-if="form.variable_mappings.length" size="x-small" class="ml-2">{{ form.variable_mappings.length
                }}</VChip>
            </div>
            <Transition name="expand">
              <div v-if="form.showVariableMaps" class="mt-3">
                <p class="text-caption text-medium-emphasis mb-3">
                  Map response fields to conversation variables so downstream dialogs can use the data.
                  Use dot notation for nested fields: <code>data.user.id</code>
                </p>
                <div v-for="(m, i) in form.variable_mappings" :key="i" class="d-flex ga-2 mb-2 align-center">
                  <VTextField v-model="m.response_path" placeholder="data.user.name" label="Response path"
                    variant="outlined" density="compact" hide-details style="flex:1" />
                  <VIcon icon="$arrowRight" size="16" class="flex-shrink-0" />
                  <VCombobox v-model="m.variable" :items="availableVariables ?? []" placeholder="variable_name"
                    label="Save to variable" variant="outlined" density="compact" hide-details style="flex:1" />
                  <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeVarMap(i)" />
                </div>
                <VBtn size="x-small" variant="outlined" prepend-icon="$plus" @click="addVarMap">Add Mapping</VBtn>
              </div>
            </Transition>
          </section>

          <!-- ── Advanced ───────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-toggle" @click="form.showAdvanced = !form.showAdvanced">
              <VIcon :icon="form.showAdvanced ? '$chevronDown' : '$chevronRight'" size="16" class="mr-1" />
              <span class="section-label mb-0">Advanced Settings</span>
            </div>
            <Transition name="expand">
              <div v-if="form.showAdvanced" class="mt-3">
                <VRow>
                  <VCol cols="12" md="4">
                    <VTextField v-model.number="form.timeout_seconds" label="Timeout (seconds)" type="number" min="1"
                      max="300" variant="outlined" density="compact" />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model.number="form.retry_attempts" label="Retry Attempts" type="number" min="0"
                      max="10" variant="outlined" density="compact" />
                  </VCol>
                  <VCol cols="12" md="4" class="d-flex align-center">
                    <VSwitch v-model="form.is_active" label="Active" color="primary" hide-details density="compact"
                      inset />
                  </VCol>
                </VRow>
              </div>
            </Transition>
          </section>

          <!-- ── Test ───────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="section-label mb-0">Test Request</div>
              <VBtn color="secondary" size="small" prepend-icon="$play" :loading="testing" @click="runTest">
                Run Test
              </VBtn>
            </div>

            <div v-if="testResult" id="test-result-panel" class="test-result-panel">
              <div class="test-result-header">
                <span :class="statusColor(testResult.status)" class="font-weight-bold">
                  {{ testResult.status }} {{ testResult.statusText }}
                </span>
                <VChip size="x-small" variant="flat" :color="testResult.success ? 'success' : 'error'">
                  {{ testResult.success ? 'Success' : 'Failed' }}
                </VChip>
              </div>

              <div v-if="testResult.error" class="test-error">{{ testResult.error }}</div>

              <VTabs v-if="testResult.body !== null || testResult.headers" density="compact" color="primary"
                class="mt-2">
                <VTab value="body">Body</VTab>
                <VTab value="headers">Headers</VTab>
              </VTabs>
              <VWindow>
                <VWindowItem value="body">
                  <pre class="code-block">{{ prettyJson(testResult.body) }}</pre>
                </VWindowItem>
                <VWindowItem value="headers">
                  <pre class="code-block">{{ prettyJson(testResult.headers) }}</pre>
                </VWindowItem>
              </VWindow>
            </div>
          </section>

          <!-- ── Actions ────────────────────────────────────────────────── -->
          <div class="form-actions">
            <VBtn variant="outlined" @click="backToList">Cancel</VBtn>
            <VBtn color="primary" :loading="saving" @click="saveApi">
              {{ editingApi ? 'Update API' : 'Create API' }}
            </VBtn>
          </div>

        </div><!-- /form-body -->
      </div><!-- /form panel -->

    </div><!-- /slider-track -->
  </div>
</template>

<style scoped lang="scss">
.api-library {
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
  transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
    opacity 0.25s ease;
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

// ── Panel headers ─────────────────────────────────────────────────────────────
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
.api-table-wrap {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.api-table {
  th {
    font-size: 0.7rem !important;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: rgba(var(--v-theme-on-surface), 0.5) !important;
  }
}

.api-row {
  transition: background 0.12s;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.03);
  }
}

.method-badge {
  font-size: 0.68rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
  letter-spacing: 0.04em;
}

.url-cell {
  display: block;
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: rgba(var(--v-theme-on-surface), 0.6);
}

.empty-state {
  padding: 48px 24px;
  text-align: center;
  color: rgba(var(--v-theme-on-surface), 0.5);
}

// ── Form ──────────────────────────────────────────────────────────────────────
.form-body {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.form-section {
  padding: 16px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  background: rgba(var(--v-theme-surface), 1);
  margin-bottom: 8px;
}

.section-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: rgba(var(--v-theme-on-surface), 0.45);
  margin-bottom: 12px;
}

.section-toggle {
  display: flex;
  align-items: center;
  cursor: pointer;
  user-select: none;
  color: rgba(var(--v-theme-on-surface), 0.7);
  font-size: 0.8rem;
  font-weight: 600;

  &:hover {
    color: rgba(var(--v-theme-on-surface), 1);
  }
}

// ── Test panel ────────────────────────────────────────────────────────────────
.test-result-panel {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 8px;
  overflow: hidden;
  background: rgba(var(--v-theme-surface-variant), 0.5);
}

.test-result-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  font-size: 0.85rem;
}

.test-error {
  padding: 10px 14px;
  font-size: 0.8rem;
  color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.06);
}

.code-block {
  margin: 0;
  padding: 14px;
  font-size: 0.75rem;
  font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
  overflow-x: auto;
  max-height: 300px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.5;
}

// ── Form actions ──────────────────────────────────────────────────────────────
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 4px;
  margin-bottom: 8px;
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

// ── Method colors (Tailwind-style utilities for the badge) ────────────────────
.text-emerald-600 {
  color: #059669;
}

.bg-emerald-50 {
  background-color: #ecfdf5;
}

.text-blue-600 {
  color: #2563eb;
}

.bg-blue-50 {
  background-color: #eff6ff;
}

.text-amber-600 {
  color: #d97706;
}

.bg-amber-50 {
  background-color: #fffbeb;
}

.text-purple-600 {
  color: #9333ea;
}

.bg-purple-50 {
  background-color: #faf5ff;
}

.text-red-600 {
  color: #dc2626;
}

.bg-red-50 {
  background-color: #fef2f2;
}

.text-green-600 {
  color: #16a34a;
}
</style>