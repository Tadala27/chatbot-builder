<template>
  <div class="functions-library">
    <h3>Custom Functions</h3>
    <p>
      Create reusable JavaScript functions that can be called from your flow.
    </p>

    <v-row class="mb-4">
      <v-col cols="12" md="6">
        <v-text-field
          v-model="searchQuery"
          placeholder="Search functions..."
          prepend-inner-icon="$magnify"
          density="compact"
          variant="outlined"
          hide-details
        />
      </v-col>
      <v-col cols="12" md="6" class="d-flex justify-end">
        <v-btn color="primary" prepend-icon="$plus" @click="openFunctionForm">
          Add Function
        </v-btn>
      </v-col>
    </v-row>

    <!-- Function Form -->
    <v-expand-transition>
      <v-card v-if="showForm" class="mb-4 pa-4" variant="outlined">
        <v-card-title
          class="d-flex justify-space-between align-center pa-0 mb-4"
        >
          <span class="text-h6">{{
            editingFunction ? "Edit Function" : "New Function"
          }}</span>
          <v-btn
            icon="$close"
            size="small"
            variant="text"
            @click="closeFunctionForm"
          />
        </v-card-title>

        <v-form @submit.prevent="submitFunction">
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="functionForm.name"
                label="Function Name *"
                variant="outlined"
                density="compact"
                hint="Display name for the function"
                persistent-hint
                required
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field
                v-model="functionForm.slug"
                label="Function Slug *"
                variant="outlined"
                density="compact"
                hint="Unique identifier (lowercase, no spaces)"
                persistent-hint
                required
              />
            </v-col>

            <v-col cols="12">
              <v-textarea
                v-model="functionForm.description"
                label="Description"
                variant="outlined"
                density="compact"
                rows="2"
                hint="Brief description of what this function does"
                persistent-hint
              />
            </v-col>

            <v-col cols="12">
              <v-select
                v-model="functionForm.function_type"
                label="Function Type *"
                :items="[
                  { value: 'javascript', title: 'JavaScript' },
                  { value: 'api_call', title: 'API Call' },
                  { value: 'webhook', title: 'Webhook' },
                ]"
                variant="outlined"
                density="compact"
                required
              />
            </v-col>

            <!-- JavaScript Code Editor -->
            <template v-if="functionForm.function_type === 'javascript'">
              <v-col cols="12">
                <v-label class="mb-2">JavaScript Code *</v-label>
                <v-textarea
                  v-model="functionForm.code"
                  variant="outlined"
                  density="compact"
                  rows="10"
                  placeholder="function execute(params) {
  // Your code here
  return result;
}"
                  hint="Define a function named 'execute' that accepts 'params' and returns a result"
                  persistent-hint
                  class="code-editor"
                />
              </v-col>
            </template>

            <!-- API Call Configuration -->
            <template v-if="functionForm.function_type === 'api_call'">
              <v-col cols="12">
                <v-textarea
                  v-model="functionForm.code"
                  label="API Configuration (JSON)"
                  variant="outlined"
                  density="compact"
                  rows="6"
                  placeholder='{
  "url": "https://api.example.com/endpoint",
  "method": "GET",
  "headers": {},
  "body": {}
}'
                  hint="JSON configuration for the API call"
                  persistent-hint
                />
              </v-col>
            </template>

            <!-- Webhook Configuration -->
            <template v-if="functionForm.function_type === 'webhook'">
              <v-col cols="12">
                <v-textarea
                  v-model="functionForm.code"
                  label="Webhook Configuration (JSON)"
                  variant="outlined"
                  density="compact"
                  rows="6"
                  placeholder='{
  "url": "https://webhook.example.com/endpoint",
  "headers": {}
}'
                  hint="JSON configuration for the webhook"
                  persistent-hint
                />
              </v-col>
            </template>

            <!-- Parameters -->
            <v-col cols="12">
              <v-textarea
                v-model="functionForm.parameters"
                label="Parameters (JSON)"
                variant="outlined"
                density="compact"
                rows="4"
                placeholder='[
  {"name": "param1", "type": "string", "required": true},
  {"name": "param2", "type": "number", "required": false}
]'
                hint="Define the parameters this function accepts"
                persistent-hint
              />
            </v-col>

            <!-- Advanced Settings -->
            <v-col cols="12">
              <v-divider class="my-2" />
              <h6 class="text-h6 mb-3">Advanced Settings</h6>
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field
                v-model="functionForm.return_type"
                label="Return Type"
                variant="outlined"
                density="compact"
                placeholder="string, number, object, etc."
              />
            </v-col>

            <v-col cols="12" md="4">
              <v-text-field
                v-model.number="functionForm.timeout_seconds"
                label="Timeout (seconds)"
                type="number"
                variant="outlined"
                density="compact"
                min="1"
                max="300"
              />
            </v-col>

            <v-col cols="12" md="4">
              <v-checkbox
                v-model="functionForm.is_async"
                label="Async Function"
                density="compact"
              />
            </v-col>

            <v-col cols="12">
              <v-checkbox
                v-model="functionForm.is_active"
                label="Active (enable this function)"
                density="compact"
              />
            </v-col>

            <!-- Test Section -->
            <v-col cols="12" v-if="functionForm.function_type === 'javascript'">
              <v-divider class="my-2" />
              <h6 class="text-h6 mb-3">Test Function</h6>
            </v-col>

            <v-col cols="12" v-if="functionForm.function_type === 'javascript'">
              <v-textarea
                v-model="testParams"
                label="Test Parameters (JSON)"
                variant="outlined"
                density="compact"
                rows="3"
                placeholder='{"param1": "value1", "param2": 123}'
              />
            </v-col>

            <v-col
              cols="12"
              v-if="functionForm.function_type === 'javascript'"
              class="d-flex justify-space-between"
            >
              <v-btn
                color="primary"
                size="small"
                :loading="isTesting"
                @click="testFunction"
              >
                <v-icon icon="$play" size="small" class="mr-1" />
                Test Function
              </v-btn>

              <div v-if="testResult" class="test-result">
                <v-chip
                  :color="testResult.success ? 'success' : 'error'"
                  size="small"
                >
                  {{ testResult.success ? "Success" : "Error" }}
                </v-chip>
                <span class="ml-2 text-caption">
                  {{ testResult.execution_time_ms }}ms
                </span>
              </div>
            </v-col>

            <v-col cols="12" v-if="testResult">
              <v-card variant="outlined">
                <v-card-title class="text-subtitle-2 bg-grey-lighten-4">
                  Test Result
                </v-card-title>
                <v-card-text>
                  <pre class="test-output">{{
                    testResult.result || testResult.error
                  }}</pre>
                </v-card-text>
              </v-card>
            </v-col>
          </v-row>

          <v-divider class="my-4" />

          <div class="d-flex justify-end gap-2">
            <v-btn variant="outlined" color="error" @click="closeFunctionForm">
              Cancel
            </v-btn>
            <v-btn color="primary" type="submit" :loading="isSubmitting">
              {{ editingFunction ? "Update" : "Create" }} Function
            </v-btn>
          </div>
        </v-form>
      </v-card>
    </v-expand-transition>

    <!-- Functions List -->
    <v-data-table
      :headers="tableHeaders"
      :items="filteredFunctions"
      :loading="isLoading"
      class="elevation-1"
    >
      <template #item.function_type="{ item }">
        <v-chip
          size="small"
          :color="getTypeColor(item.function_type)"
          variant="flat"
        >
          {{ item.function_type.replace("_", " ").toUpperCase() }}
        </v-chip>
      </template>

      <template #item.is_active="{ item }">
        <v-icon
          :icon="item.is_active ? '$checkCircle' : '$closeCircle'"
          :color="item.is_active ? 'success' : 'grey'"
          size="small"
        />
      </template>

      <template #item.is_async="{ item }">
        <v-icon
          :icon="item.is_async ? '$checkCircle' : '$closeCircle'"
          :color="item.is_async ? 'info' : 'grey'"
          size="small"
        />
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="$pencil"
          size="x-small"
          variant="text"
          @click="editFunction(item)"
        />
        <v-btn
          icon="$delete"
          size="x-small"
          variant="text"
          color="error"
          @click="deleteFunction(item)"
        />
      </template>
    </v-data-table>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

const props = defineProps<{
  flowId: string | number;
}>();

const emit = defineEmits<{
  (e: "functionsUpdated", functions: any[]): void;
}>();

// State
const searchQuery = ref("");
const showForm = ref(false);
const editingFunction = ref<any>(null);
const isLoading = ref(false);
const isSubmitting = ref(false);
const isTesting = ref(false);
const allFunctions = ref<any[]>([]);
const testParams = ref("{}");
const testResult = ref<any>(null);

const functionForm = ref({
  name: "",
  slug: "",
  description: "",
  function_type: "javascript",
  code: "",
  parameters: "[]",
  return_type: "string",
  is_async: false,
  timeout_seconds: 30,
  is_active: true,
});

const tableHeaders = [
  { title: "Name", key: "name" },
  { title: "Slug", key: "slug" },
  { title: "Type", key: "function_type" },
  { title: "Async", key: "is_async" },
  { title: "Active", key: "is_active" },
  { title: "Actions", key: "actions", sortable: false },
];

// Computed
const filteredFunctions = computed(() => {
  if (!searchQuery.value) return allFunctions.value;

  return allFunctions.value.filter(
    (fn: any) =>
      fn.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      fn.slug.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      fn.description?.toLowerCase().includes(searchQuery.value.toLowerCase()),
  );
});

// Methods
async function loadFunctions() {
  isLoading.value = true;
  try {
    const response = await axios.get(`/api/custom-functions`);
    allFunctions.value = response.data.data || [];
    emit("functionsUpdated", allFunctions.value);
  } catch (error) {
    console.error("Failed to load functions:", error);
    Swal.fire("Error", "Failed to load functions", "error");
  } finally {
    isLoading.value = false;
  }
}

function getTypeColor(type: string): string {
  const colors: Record<string, string> = {
    javascript: "primary",
    api_call: "success",
    webhook: "info",
    built_in: "warning",
  };
  return colors[type] || "default";
}

function openFunctionForm() {
  showForm.value = true;
  editingFunction.value = null;
  resetForm();
}

function closeFunctionForm() {
  showForm.value = false;
  editingFunction.value = null;
  testResult.value = null;
  resetForm();
}

function resetForm() {
  functionForm.value = {
    name: "",
    slug: "",
    description: "",
    function_type: "javascript",
    code: "",
    parameters: "[]",
    return_type: "string",
    is_async: false,
    timeout_seconds: 30,
    is_active: true,
  };
  testParams.value = "{}";
  testResult.value = null;
}

function editFunction(fn: any) {
  editingFunction.value = fn;
  functionForm.value = {
    name: fn.name,
    slug: fn.slug,
    description: fn.description || "",
    function_type: fn.function_type,
    code: fn.code || "",
    parameters: JSON.stringify(fn.parameters || [], null, 2),
    return_type: fn.return_type || "string",
    is_async: fn.is_async || false,
    timeout_seconds: fn.timeout_seconds || 30,
    is_active: fn.is_active ?? true,
  };
  showForm.value = true;
}

async function deleteFunction(fn: any) {
  const result = await Swal.fire({
    title: "Delete Function?",
    text: `Are you sure you want to delete "${fn.name}"?`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Delete",
  });

  if (!result.isConfirmed) return;

  try {
    await axios.delete(`/api/custom-functions/${fn.id}`);
    allFunctions.value = allFunctions.value.filter((f: any) => f.id !== fn.id);
    emit("functionsUpdated", allFunctions.value);
    Swal.fire("Deleted!", "Function has been deleted.", "success");
  } catch (error: any) {
    Swal.fire(
      "Error",
      error.response?.data?.message || "Failed to delete function",
      "error",
    );
  }
}

async function testFunction() {
  isTesting.value = true;
  testResult.value = null;

  try {
    // Parse test parameters
    const params = JSON.parse(testParams.value);

    // Create temporary function for testing
    const tempFunction = {
      ...functionForm.value,
      parameters: JSON.parse(functionForm.value.parameters),
    };

    const response = await axios.post(`/api/custom-functions/test`, {
      function: tempFunction,
      test_parameters: params,
    });

    testResult.value = response.data;
  } catch (error: any) {
    testResult.value = {
      success: false,
      error: error.response?.data?.message || error.message,
    };
  } finally {
    isTesting.value = false;
  }
}

async function submitFunction() {
  // Validate slug format
  const slugRegex = /^[a-z_][a-z0-9_]*$/;
  if (!slugRegex.test(functionForm.value.slug)) {
    Swal.fire({
      title: "Invalid Slug",
      text: "Slug must start with lowercase letter or underscore, and contain only lowercase letters, numbers, and underscores.",
      icon: "error",
    });
    return;
  }

  isSubmitting.value = true;

  try {
    const payload = {
      name: functionForm.value.name,
      slug: functionForm.value.slug,
      description: functionForm.value.description,
      function_type: functionForm.value.function_type,
      code: functionForm.value.code,
      parameters: JSON.parse(functionForm.value.parameters),
      return_type: functionForm.value.return_type,
      is_async: functionForm.value.is_async,
      timeout_seconds: functionForm.value.timeout_seconds,
      is_active: functionForm.value.is_active,
    };

    let response;
    if (editingFunction.value) {
      response = await axios.put(
        `/api/custom-functions/${editingFunction.value.id}`,
        payload,
      );
    } else {
      response = await axios.post(`/api/custom-functions`, payload);
    }

    if (editingFunction.value) {
      allFunctions.value = allFunctions.value.map((f: any) =>
        f.id === editingFunction.value.id ? response.data : f,
      );
    } else {
      allFunctions.value.push(response.data);
    }

    emit("functionsUpdated", allFunctions.value);

    Swal.fire({
      title: "Success!",
      text: `Function ${editingFunction.value ? "updated" : "created"} successfully`,
      icon: "success",
      timer: 2000,
    });

    closeFunctionForm();
  } catch (error: any) {
    Swal.fire({
      title: "Error",
      text: error.response?.data?.message || "Failed to save function",
      icon: "error",
    });
  } finally {
    isSubmitting.value = false;
  }
}

onMounted(() => {
  loadFunctions();
});

// Expose load method
defineExpose({
  loadFunctions,
});
</script>

<style scoped>
.gap-2 {
  gap: 8px;
}

.code-editor :deep(textarea) {
  font-family: "Courier New", monospace;
  font-size: 13px;
}

.test-output {
  background: #f5f5f5;
  padding: 12px;
  border-radius: 4px;
  font-size: 12px;
  font-family: "Courier New", monospace;
  max-height: 200px;
  overflow: auto;
  margin: 0;
}

.test-result {
  display: flex;
  align-items: center;
}
</style>
