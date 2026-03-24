<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { watchDebounced } from '@vueuse/core'
import axios from 'axios'
import Swal from 'sweetalert2'

const props = defineProps<{
  botId: string | number
}>()

const emit = defineEmits<{
  (e: 'functionsUpdated', functions: any[]): void
}>()

// ── Pagination state ─────────────────────────────────────────────────────────
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
const editingFunction = ref<any>(null)

function openCreateForm() {
  editingFunction.value = null
  resetForm()
  view.value = 'form'
}
function openEditForm(fn: any) {
  editingFunction.value = fn
  populateForm(fn)
  view.value = 'form'
}
function backToList() {
  view.value = 'list'
  editingFunction.value = null
  resetForm()
  testResult.value = null
}

// ── Data ──────────────────────────────────────────────────────────────────────
const allFunctions = ref<any[]>([])
const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const search = ref('')
const testResult = ref<any>(null)
const testParamsJson = ref('{}')

const filteredFunctions = computed(() => {
  const q = search.value.toLowerCase()
  if (!q) return allFunctions.value
  return allFunctions.value.filter(fn =>
    fn.name.toLowerCase().includes(q) ||
    fn.slug.toLowerCase().includes(q) ||
    (fn.description ?? '').toLowerCase().includes(q)
  )
})

// ── Function types ────────────────────────────────────────────────────────────
const FUNCTION_TYPES = [
  { value: 'javascript', title: 'JavaScript', icon: '$codeJson', color: 'primary' },
  { value: 'webhook', title: 'Webhook', icon: '$webhook', color: 'info' },
  { value: 'built_in', title: 'Built-in', icon: '$puzzle', color: 'warning' },
]

const RETURN_TYPES = ['string', 'number', 'boolean', 'object', 'array', 'void']

// ── Form model ────────────────────────────────────────────────────────────────
interface Param { name: string; type: string; required: boolean; description: string }

const form = ref(blankForm())

function blankForm() {
  return {
    name: '',
    slug: '',
    description: '',
    function_type: 'javascript' as 'javascript' | 'webhook' | 'built_in',
    code: defaultCode('javascript'),
    parameters: [] as Param[],
    return_type: 'string',
    timeout_seconds: 30,
    is_active: true,
    // UI-only
    slugTouched: false,
    showAdvanced: false,
    paramsJson: '[]',
    paramsJsonError: '',
  }
}

function resetForm() { form.value = blankForm() }

function populateForm(fn: any) {
  const params: Param[] = Array.isArray(fn.parameters) ? fn.parameters : []
  form.value = {
    name: fn.name ?? '',
    slug: fn.slug ?? '',
    description: fn.description ?? '',
    function_type: fn.function_type ?? 'javascript',
    code: fn.code ?? defaultCode(fn.function_type ?? 'javascript'),
    parameters: params,
    return_type: fn.return_type ?? 'string',
    timeout_seconds: fn.timeout_seconds ?? 30,
    is_active: fn.is_active ?? true,
    slugTouched: true,
    showAdvanced: false,
    paramsJson: JSON.stringify(params, null, 2),
    paramsJsonError: '',
  }
}

function defaultCode(type: string): string {
  if (type === 'javascript') {
    return `// Available variables are passed in as named parameters\n// Return a value to store in the result variable\n\nreturn "hello world";`
  }
  if (type === 'webhook') {
    return JSON.stringify({
      url: 'https://webhook.example.com/endpoint',
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
    }, null, 2)
  }
  return ''
}

// Auto-slug from name
function onNameInput() {
  if (form.value.slugTouched || editingFunction.value) return
  form.value.slug = form.value.name
    .toLowerCase()
    .replace(/\s+/g, '_')
    .replace(/[^a-z0-9_]/g, '')
    .replace(/^[^a-z_]+/, '')
    .slice(0, 100)
}

// When function_type changes, reset code to sensible default
watch(() => form.value.function_type, (newType) => {
  if (!editingFunction.value) {
    form.value.code = defaultCode(newType)
  }
})

// ── Parameter rows (JS functions) ─────────────────────────────────────────────
const PARAM_TYPES = ['string', 'number', 'boolean', 'array', 'object', 'any']

function addParam() {
  form.value.parameters.push({ name: '', type: 'string', required: true, description: '' })
}
function removeParam(i: number) {
  form.value.parameters.splice(i, 1)
}

// ── Load / Save / Delete ──────────────────────────────────────────────────────
async function loadFunctions() {
  loading.value = true

  const params = {
    page: pagination.value.current_page,
    per_page: pagination.value.per_page,
    search: search.value || undefined,
  }

  try {
    const { data } = await axios.get(`/api/bots/${props.botId}/functions`, { params })

    // Handle the response structure
    allFunctions.value = data.data ?? []

    pagination.value = {
      total: data.pagination?.total ?? 0,
      per_page: data.pagination?.per_page ?? 20,
      current_page: data.pagination?.current_page ?? 1,
      last_page: data.pagination?.last_page ?? 1,
      from: data.pagination?.from ?? 0,
      to: data.pagination?.to ?? 0,
    }

    emit('functionsUpdated', allFunctions.value)
  } catch (err: any) {
    console.error(err)
    Swal.fire({ icon: 'error', title: 'Failed to load functions', timer: 2000, showConfirmButton: false })
  } finally {
    loading.value = false
  }
}

async function saveFunction() {
  // Validate slug
  if (!/^[a-z_][a-z0-9_]*$/.test(form.value.slug)) {
    Swal.fire({ icon: 'error', title: 'Invalid slug', text: 'Must start with a letter or underscore, lowercase only.' })
    return
  }

  saving.value = true
  try {
    const payload = buildPayload()
    let response: any

    if (editingFunction.value) {
      response = await axios.put(`/api/bots/${props.botId}/functions/${editingFunction.value.id}`, payload)
      const idx = allFunctions.value.findIndex(f => f.id === editingFunction.value.id)
      if (idx !== -1) allFunctions.value.splice(idx, 1, response.data.function)
    } else {
      response = await axios.post(`/api/bots/${props.botId}/functions`, payload)
      // Refresh the list to get paginated results
      await loadFunctions()
    }

    emit('functionsUpdated', allFunctions.value)
    Swal.fire({
      icon: 'success',
      title: editingFunction.value ? 'Function updated' : 'Function created',
      timer: 1600,
      showConfirmButton: false,
    })
    backToList()
  } catch (err: any) {
    const errors = err.response?.data?.errors?.code
    if (errors) {
      Swal.fire({ icon: 'error', title: 'Syntax error', html: errors.join('<br/>') })
    } else {
      Swal.fire({ icon: 'error', title: 'Save failed', text: err.response?.data?.message ?? err.message })
    }
  } finally {
    saving.value = false
  }
}

async function deleteFunction(fn: any) {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "${fn.name}"?`,
    text: 'Any flow actions referencing this function will stop working.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    confirmButtonText: 'Delete',
  })
  if (!isConfirmed) return

  try {
    await axios.delete(`/api/bots/${props.botId}/functions/${fn.id}`)
    await loadFunctions() // Refresh the list
    emit('functionsUpdated', allFunctions.value)
  } catch (err: any) {
    Swal.fire({ icon: 'error', title: 'Delete failed', text: err.response?.data?.message ?? err.message })
  }
}

// ── Test ──────────────────────────────────────────────────────────────────────
async function runTest() {
  let params: any = {}
  try {
    params = JSON.parse(testParamsJson.value || '{}')
  } catch {
    Swal.fire({ icon: 'error', title: 'Invalid JSON', text: 'Test parameters must be valid JSON.' })
    return
  }

  testing.value = true
  testResult.value = null

  try {
    let response: any
    if (editingFunction.value) {
      response = await axios.post(`/api/bots/${props.botId}/functions/${editingFunction.value.id}/test`, { parameters: params })
    } else {
      response = await axios.post(`/api/bots/${props.botId}/functions/test-draft`, {
        ...buildPayload(),
        test_parameters: params,
      })
    }
    testResult.value = response.data
  } catch (err: any) {
    testResult.value = {
      success: false,
      error: err.response?.data?.message ?? err.message,
      result: null,
      execution_time_ms: null,
    }
  } finally {
    testing.value = false
  }
}

function buildPayload() {
  return {
    name: form.value.name,
    slug: form.value.slug,
    description: form.value.description || undefined,
    function_type: form.value.function_type,
    code: form.value.code,
    parameters: form.value.parameters.filter(p => p.name),
    return_type: form.value.return_type,
    timeout_seconds: form.value.timeout_seconds,
    is_active: form.value.is_active,
  }
}

function prettyJson(val: any): string {
  if (val === null || val === undefined) return ''
  try { return JSON.stringify(typeof val === 'string' ? JSON.parse(val) : val, null, 2) }
  catch { return String(val) }
}

// ── Display helpers ───────────────────────────────────────────────────────────
const TYPE_COLORS: Record<string, string> = { javascript: 'primary', webhook: 'info', built_in: 'warning' }
const TYPE_LABELS: Record<string, string> = { javascript: 'JavaScript', webhook: 'Webhook', built_in: 'Built-in' }

// ────────────────────────────────────────
// Watchers
// ────────────────────────────────────────
watchDebounced(
  [search],
  () => {
    pagination.value.current_page = 1
    loadFunctions()
  },
  { debounce: 500, maxWait: 1000 }
)

watch(() => pagination.value.current_page, () => loadFunctions())

onMounted(loadFunctions)
defineExpose({ loadFunctions })
</script>

<template>
  <div class="function-library">

    <!-- ── Slider track ────────────────────────────────────────────────────── -->
    <div class="slider-track">

      <!-- ══════════════════════════════════ LIST ════════════════════════════ -->
      <div class="panel" :class="view === 'list' ? 'panel--visible' : 'panel--hidden-left'">

        <!-- Header -->
        <div class="panel-header">
          <div>
            <h4 class="panel-title">Functions</h4>
            <p class="panel-subtitle">
              Custom JavaScript, webhooks, and built-in helpers that your flow actions can call
            </p>
          </div>
          <VBtn color="primary" prepend-icon="$plus" @click="openCreateForm">
            Create Function
          </VBtn>
        </div>

        <!-- Search -->
        <VTextField v-model="search" placeholder="Search by name, slug or description…" prepend-inner-icon="$magnify"
          variant="outlined" density="compact" hide-details class="mb-4" />

        <VProgressLinear v-if="loading" indeterminate color="primary" class="mb-2" />

        <!-- Functions table -->
        <div class="fn-table-wrap">

          <!-- Empty state -->
          <div v-if="!loading && filteredFunctions.length === 0" class="empty-state">
            <VIcon icon="$codeJson" size="44" class="mb-3 text-medium-emphasis" />
            <p class="text-body-2 font-weight-medium">No functions found</p>
            <p class="text-caption text-medium-emphasis mb-4">
              Create a function to run logic, call webhooks, or use built-in helpers inside your flow actions.
            </p>
            <VBtn color="primary" size="small" prepend-icon="$plus" @click="openCreateForm">
              Create Function
            </VBtn>
          </div>

          <VTable v-else class="bordered-table" density="comfortable">
            <thead class="bg-gray text-uppercase">
              <tr class="text-secondary">
                <th class="text-left pa-4">Name</th>
                <th class="text-left pa-4">Slug</th>
                <th class="text-center pa-4">Type</th>
                <th class="text-center pa-4">Return</th>
                <th class="text-center pa-4">Timeout</th>
                <th class="text-center pa-4">Active</th>
                <th class="text-center pa-4">Actions</th>
              </tr>
            </thead>

            <!-- Loading -->
            <tbody v-if="loading">
              <tr>
                <td colspan="7" class="py-12">
                  <div class="d-flex justify-center">
                    <VProgressCircular indeterminate color="primary" size="48" />
                  </div>
                </td>
              </tr>
            </tbody>

            <tbody v-else class="bg-containerBg">
              <tr v-for="fn in filteredFunctions" :key="fn.id" class="fn-row">
                <td class="pa-4">
                  <div class="d-flex align-center gap-3">
                    <div>
                      <div class="text-subtitle-2 font-weight-medium">{{ fn.name }}</div>
                      <div v-if="fn.description" class="text-caption text-medium-emphasis" style="max-width:300px">
                        {{ fn.description }}
                      </div>
                    </div>
                  </div>
                </td>
                <td class="pa-4"><code class="slug-code">{{ fn.slug }}</code></td>
                <td class="pa-4 text-center">
                  <VChip size="small" :color="TYPE_COLORS[fn.function_type] ?? 'grey'" variant="tonal">
                    <SvgSprite
                      :name="fn.function_type === 'javascript' ? 'custom-code' : fn.function_type === 'webhook' ? 'custom-webhook' : 'custom-puzzle'"
                      class="me-2" style="width: 15px; height: 15px" />
                    {{ TYPE_LABELS[fn.function_type] ?? fn.function_type }}
                  </VChip>
                </td>
                <td class="pa-4 text-center"><span class="text-body-2">{{ fn.return_type ?? '—' }}</span></td>
                <td class="pa-4 text-center"><span class="text-body-2">{{ fn.timeout_seconds }}s</span></td>
                <td class="pa-4 text-center">
                  <VIcon :icon="fn.is_active ? '$checkCircle' : '$closeCircle'"
                    :color="fn.is_active ? 'success' : 'grey'" size="20" />
                </td>
                <td class="pa-4 text-center">
                  <div class="d-flex align-center justify-center ga-2">
                    <SvgSprite name="custom-edit-outline" class="text-primary" @click="openEditForm(fn)"
                      style="width: 18px; height: 18px; cursor: pointer" />
                    <SvgSprite name="custom-trash-fill" class="text-error" @click="deleteFunction(fn)"
                      style="width: 18px; height: 18px; cursor: pointer" />
                  </div>
                </td>
              </tr>
            </tbody>
          </VTable>

          <!-- ── Pagination ──────────────────────────────────────────── -->
          <v-card-text v-if="pagination.total > 0" class="pt-4">
            <VRow class="align-center text-center text-sm-start" justify="space-between">
              <VCol cols="12" sm="6" class="d-flex justify-center justify-sm-start">
                <span class="text-medium-emphasis">
                  Showing {{ pagination.from }}–{{ pagination.to }} of {{ pagination.total }} functions
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
              <h4 class="panel-title">{{ editingFunction ? 'Edit Function' : 'Create Function' }}</h4>
              <p class="panel-subtitle mb-0">
                {{ editingFunction ? `Editing "${editingFunction.name}"` : 'Define a new reusable function for this bot'
                }}
              </p>
            </div>
          </div>
          <VBtn variant="outlined" size="small" prepend-icon="$formatListBulleted" @click="backToList">
            View Functions
          </VBtn>
        </div>

        <div class="form-body">

          <!-- ── Identity ──────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Identity</div>
            <VRow>
              <VCol cols="12" md="6">
                <VTextField v-model="form.name" label="Function Name *" variant="outlined" density="compact"
                  hint="Display name shown in the builder" persistent-hint @input="onNameInput" />
              </VCol>
              <VCol cols="12" md="6">
                <VTextField v-model="form.slug" label="Slug *" variant="outlined" density="compact"
                  :readonly="!!editingFunction" :hint="editingFunction
                    ? 'Slug is locked — flow actions reference it by this slug'
                    : 'Lowercase, underscores only. Used internally by flow actions.'" persistent-hint
                  @input="form.slugTouched = true">
                  <template #append-inner>
                    <VIcon v-if="editingFunction" icon="$lock" size="14" color="grey" />
                  </template>
                </VTextField>
              </VCol>
              <VCol cols="12">
                <VTextField v-model="form.description" label="Description" variant="outlined" density="compact"
                  hint="What does this function do?" persistent-hint />
              </VCol>
            </VRow>
          </section>

          <!-- ── Type selector ─────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-label">Function Type</div>
            <VRow>
              <VCol v-for="t in FUNCTION_TYPES" :key="t.value" cols="12" md="4">
                <div class="type-card" :class="{ 'type-card--active': form.function_type === t.value }"
                  @click="form.function_type = t.value as any">
                  <VIcon :icon="t.icon" size="22" :color="form.function_type === t.value ? t.color : undefined"
                    class="mb-1" />
                  <div class="font-weight-semibold text-body-2">{{ t.title }}</div>
                  <div class="text-caption text-medium-emphasis mt-1">
                    <template v-if="t.value === 'javascript'">Write JS logic, access variables</template>
                    <template v-else-if="t.value === 'webhook'">Fire HTTP requests on execution</template>
                    <template v-else>Use pre-built platform helpers</template>
                  </div>
                </div>
              </VCol>
            </VRow>
          </section>

          <!-- ── Code / Config ─────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="section-label mb-0">
                <template v-if="form.function_type === 'javascript'">JavaScript Code</template>
                <template v-else-if="form.function_type === 'webhook'">Webhook Configuration (JSON)</template>
                <template v-else>Configuration</template>
              </div>
              <VChip v-if="form.function_type === 'javascript'" size="x-small" variant="tonal" color="primary">
                {{form.parameters.filter(p => p.name).length}} param{{form.parameters.filter(p => p.name).length !== 1 ?
                  's' :
                  ''}} available
              </VChip>
            </div>

            <!-- JS editor hint -->
            <VAlert v-if="form.function_type === 'javascript'" type="info" variant="tonal" density="compact"
              rounded="lg" class="mb-3" :icon="false">
              <div class="text-caption">
                Parameters defined below are injected as named variables.
                Use <code>return</code> to output a value.
                Available globals: <code>JSON</code>, <code>Math</code>, <code>Date</code>, <code>parseInt</code>,
                <code>parseFloat</code>, <code>String</code>, <code>Number</code>.
              </div>
            </VAlert>

            <VTextarea v-model="form.code"
              :label="form.function_type === 'javascript' ? 'JavaScript Code *' : 'Webhook Configuration JSON *'"
              variant="outlined" :rows="form.function_type === 'javascript' ? 12 : 8" class="code-textarea"
              placeholder="Write your logic here" />
          </section>

          <!-- ── Parameters (JS only) ──────────────────────────────────────── -->
          <section v-if="form.function_type === 'javascript'" class="form-section">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="section-label mb-0">Parameters</div>
              <VBtn size="x-small" variant="outlined" prepend-icon="$plus" @click="addParam">
                Add Parameter
              </VBtn>
            </div>
            <p class="text-caption text-medium-emphasis mb-3">
              Each parameter becomes a variable in your JS code. Flow actions provide values when calling this function.
            </p>

            <div v-if="form.parameters.length === 0" class="text-caption text-medium-emphasis py-2">
              No parameters — function takes no inputs.
            </div>

            <div v-for="(p, i) in form.parameters" :key="i" class="param-row">
              <VTextField v-model="p.name" placeholder="param_name" label="Name" variant="outlined" density="compact"
                hide-details style="flex:1.2" />
              <VSelect v-model="p.type" :items="PARAM_TYPES" label="Type" variant="outlined" density="compact"
                hide-details style="width:110px" />
              <VTextField v-model="p.description" placeholder="Optional description" label="Description"
                variant="outlined" density="compact" hide-details style="flex:2" />
              <div class="d-flex align-center ga-1">
                <VTooltip text="Required">
                  <template #activator="{ props: tp }">
                    <VIcon v-bind="tp" :icon="p.required ? '$asterisk' : '$asteriskOff'"
                      :color="p.required ? 'error' : 'grey'" size="18" class="cursor-pointer"
                      @click="p.required = !p.required" />
                  </template>
                </VTooltip>
                <VBtn icon="$close" size="x-small" variant="text" color="error" @click="removeParam(i)" />
              </div>
            </div>
          </section>

          <!-- ── Advanced ───────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="section-toggle" @click="form.showAdvanced = !form.showAdvanced">
              <VIcon :icon="form.showAdvanced ? '$chevronDown' : '$chevronRight'" size="16" class="mr-1" />
              <span class="section-label mb-0">Advanced Settings</span>
            </div>
            <Transition name="expand">
              <div v-if="form.showAdvanced" class="mt-3">
                <VRow>
                  <VCol cols="12" md="4">
                    <VSelect v-model="form.return_type" label="Return Type" :items="RETURN_TYPES" variant="outlined"
                      density="compact" hint="Helps the flow builder know what to expect back" persistent-hint />
                  </VCol>
                  <VCol cols="12" md="4">
                    <VTextField v-model.number="form.timeout_seconds" label="Timeout (seconds)" type="number" min="1"
                      max="300" variant="outlined" density="compact" hint="Max execution time before failing"
                      persistent-hint />
                  </VCol>
                  <VCol cols="12" md="4" class="d-flex align-center">
                    <VSwitch v-model="form.is_active" label="Active" color="primary" hide-details density="compact"
                      inset />
                  </VCol>
                </VRow>
              </div>
            </Transition>
          </section>

          <!-- ── Test ───────────────────────────────────────────────────────── -->
          <section class="form-section">
            <div class="d-flex align-center justify-space-between mb-3">
              <div class="section-label mb-0">Test Function</div>
              <VBtn color="secondary" size="small" prepend-icon="$play" :loading="testing" @click="runTest">
                Run Test
              </VBtn>
            </div>

            <VTextarea v-model="testParamsJson" label="Test Parameters (JSON)" variant="outlined" density="compact"
              rows="3" class="code-textarea mb-3" placeholder='{"param_name": "value"}'
              hint="Key-value pairs matching your parameter names" persistent-hint />

            <div v-if="testResult" id="fn-test-result" class="test-result-panel">
              <div class="test-result-header">
                <div class="d-flex align-center ga-2">
                  <VChip size="x-small" variant="flat" :color="testResult.success ? 'success' : 'error'">
                    {{ testResult.success ? 'Success' : 'Failed' }}
                  </VChip>
                  <span v-if="testResult.execution_time_ms" class="text-caption text-medium-emphasis">
                    {{ testResult.execution_time_ms }}ms
                  </span>
                </div>
              </div>
              <div v-if="testResult.error" class="test-error">{{ testResult.error }}</div>
              <pre v-if="testResult.result !== null && testResult.result !== undefined" class="code-block">{{
                prettyJson(testResult.result) }}</pre>
            </div>
          </section>

          <!-- ── Actions ───────────────────────────────────────────────────── -->
          <div class="form-actions">
            <VBtn variant="outlined" @click="backToList">Cancel</VBtn>
            <VBtn color="primary" :loading="saving" @click="saveFunction">
              {{ editingFunction ? 'Update Function' : 'Create Function' }}
            </VBtn>
          </div>

        </div>
      </div>

    </div>
  </div>
</template>

<style scoped lang="scss">
.function-library {
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
.fn-table-wrap {
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

.fn-row {
  transition: background 0.12s;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.03);
  }
}

.slug-code {
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.78rem;
  font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace;
  white-space: nowrap;
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

.type-card {
  padding: 14px 12px;
  text-align: center;
  border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  cursor: pointer;
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

.param-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}

.cursor-pointer {
  cursor: pointer;
}

.code-textarea :deep(textarea) {
  font-family: 'JetBrains Mono', 'Fira Code', ui-monospace, monospace !important;
  font-size: 0.8rem !important;
  line-height: 1.55 !important;
}

.section-toggle {
  display: flex;
  align-items: center;
  cursor: pointer;
  user-select: none;
  color: rgba(var(--v-theme-on-surface), 0.65);
  font-size: 0.8rem;
  font-weight: 600;

  &:hover {
    color: rgba(var(--v-theme-on-surface), 1);
  }
}

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
  padding: 8px 12px;
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

.test-error {
  padding: 10px 14px;
  font-size: 0.8rem;
  color: rgb(var(--v-theme-error));
  background: rgba(var(--v-theme-error), 0.06);
}

.code-block {
  margin: 0;
  padding: 12px;
  font-size: 0.76rem;
  font-family: 'JetBrains Mono', ui-monospace, monospace;
  overflow-x: auto;
  max-height: 280px;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
  line-height: 1.5;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  padding-top: 4px;
  margin-bottom: 8px;
}

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
  max-height: 400px;
  opacity: 1;
}
</style>