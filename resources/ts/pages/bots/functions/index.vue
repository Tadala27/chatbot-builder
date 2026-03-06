<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";

const emit = defineEmits(["close", "refresh"]);

// State
const functions = ref<any[]>([]);
const isLoading = ref(false);
const editDialog = ref(false);
const editingFunction = ref<any>(null);

// Form
const form = ref({
  name: "",
  slug: "",
  description: "",
  function_type: "javascript",
  code: `// Your function code here
function execute(params) {
  // Access parameters: params.paramName
  // Return value will be stored in result variable
  
  return {
    success: true,
    data: "Hello World"
  };
}`,
  parameters: [] as { name: string; type: string; required: boolean; default_value: string }[],
  return_type: "object",
  is_async: false,
  timeout_seconds: 30,
  is_active: true,
  test_cases: [] as { input: string; expected_output: string }[],
});

const FUNCTION_TYPES = [
  { value: "javascript", title: "JavaScript", icon: "$language-javascript" },
  { value: "api_call", title: "API Call", icon: "$api" },
  { value: "webhook", title: "Webhook", icon: "$webhook" },
  { value: "built_in", title: "Built-in", icon: "$package" },
];

const PARAM_TYPES = [
  "string",
  "number",
  "boolean",
  "array",
  "object",
  "date",
  "any",
];

const RETURN_TYPES = [
  "string",
  "number",
  "boolean",
  "array",
  "object",
  "void",
  "any",
];

// Load functions
async function loadFunctions() {
  isLoading.value = true;
  try {
    const response = await axios.get("/api/custom-functions");
    functions.value = response.data.functions || [];
  } catch (error) {
    console.error("Failed to load functions:", error);
  } finally {
    isLoading.value = false;
  }
}

// Create new
function createNew() {
  editingFunction.value = null;
  form.value = {
    name: "",
    slug: "",
    description: "",
    function_type: "javascript",
    code: `// Your function code here
function execute(params) {
  // Access parameters: params.paramName
  // Return value will be stored in result variable
  
  return {
    success: true,
    data: "Hello World"
  };
}`,
    parameters: [],
    return_type: "object",
    is_async: false,
    timeout_seconds: 30,
    is_active: true,
    test_cases: [],
  };
  editDialog.value = true;
}

// Edit existing
function edit(fn: any) {
  editingFunction.value = fn;
  form.value = {
    ...fn,
    parameters: fn.parameters || [],
    test_cases: fn.test_cases || [],
  };
  editDialog.value = true;
}

// Delete
async function deleteFunction(id: number) {
  if (!confirm("Are you sure you want to delete this function?")) return;
  
  try {
    await axios.delete(`/api/custom-functions/${id}`);
    await loadFunctions();
    emit("refresh");
  } catch (error) {
    console.error("Failed to delete function:", error);
  }
}

// Save
async function save() {
  try {
    // Auto-generate slug from name if empty
    if (!form.value.slug) {
      form.value.slug = form.value.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, "_")
        .replace(/^_|_$/g, "");
    }

    const payload = {
      ...form.value,
      parameters: form.value.parameters.filter((p) => p.name),
      test_cases: form.value.test_cases.filter((t) => t.input),
    };

    if (editingFunction.value) {
      await axios.put(
        `/api/custom-functions/${editingFunction.value.id}`,
        payload
      );
    } else {
      await axios.post("/api/custom-functions", payload);
    }

    editDialog.value = false;
    await loadFunctions();
    emit("refresh");
  } catch (error) {
    console.error("Failed to save function:", error);
  }
}

// Test function
async function testFunction(fn: any) {
  const testInput = prompt("Enter test parameters (JSON):", "{}");
  if (!testInput) return;

  try {
    const response = await axios.post(`/api/custom-functions/${fn.id}/test`, {
      params: JSON.parse(testInput),
    });
    alert(
      `Test successful!\nResult: ${JSON.stringify(response.data.result, null, 2)}`
    );
  } catch (error: any) {
    alert(`Test failed: ${error.response?.data?.message || error.message}`);
  }
}

// Parameter management
function addParameter() {
  form.value.parameters.push({
    name: "",
    type: "string",
    required: false,
    default_value: "",
  });
}

function removeParameter(index: number) {
  form.value.parameters.splice(index, 1);
}

// Test case management
function addTestCase() {
  form.value.test_cases.push({
    input: "{}",
    expected_output: "{}",
  });
}

function removeTestCase(index: number) {
  form.value.test_cases.splice(index, 1);
}

onMounted(() => {
  loadFunctions();
});
</script>

<template>
  <v-card>
    <v-card-title class="d-flex align-center">
      <v-icon icon="$function" class="mr-2" />
      Custom Functions
      <v-spacer />
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="$plus"
        @click="createNew"
        size="small"
      >
        New Function
      </v-btn>
    </v-card-title>

    <v-divider />

    <v-card-text style="max-height: 500px; overflow-y: auto">
      <v-progress-linear v-if="isLoading" indeterminate color="primary" />

      <template v-else>
        <v-alert
          v-if="functions.length === 0"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          No custom functions configured yet. Create one to extend your chatbot's
          capabilities.
        </v-alert>

        <v-list v-else>
          <v-list-item
            v-for="fn in functions"
            :key="fn.id"
            :class="{ 'opacity-50': !fn.is_active }"
          >
            <template #prepend>
              <v-avatar
                :color="fn.is_active ? 'primary' : 'grey'"
                variant="tonal"
              >
                <v-icon icon="$function" />
              </v-avatar>
            </template>

            <v-list-item-title>{{ fn.name }}</v-list-item-title>
            <v-list-item-subtitle>
              <v-chip size="x-small" variant="outlined" class="mr-2">
                {{ fn.function_type }}
              </v-chip>
              {{ fn.description || "No description" }}
            </v-list-item-subtitle>

            <template #append>
              <v-btn
                icon="$playCircle"
                size="small"
                variant="text"
                @click="testFunction(fn)"
                :disabled="!fn.is_active"
              />
              <v-btn
                icon="$pencil"
                size="small"
                variant="text"
                @click="edit(fn)"
              />
              <v-btn
                icon="$trashCan"
                size="small"
                variant="text"
                color="error"
                @click="deleteFunction(fn.id)"
              />
            </template>
          </v-list-item>
        </v-list>
      </template>
    </v-card-text>

    <v-divider />

    <v-card-actions>
      <v-spacer />
      <v-btn variant="text" @click="$emit('close')">Close</v-btn>
    </v-card-actions>

    <!-- Edit Dialog -->
    <v-dialog v-model="editDialog" max-width="1000" scrollable persistent>
      <v-card>
        <v-card-title>
          {{ editingFunction ? "Edit" : "New" }} Custom Function
        </v-card-title>

        <v-divider />

        <v-card-text class="pa-4" style="max-height: 70vh">
          <v-tabs v-model="tab" class="mb-4">
            <v-tab value="general">General</v-tab>
            <v-tab value="code">Code</v-tab>
            <v-tab value="parameters">Parameters</v-tab>
            <v-tab value="tests">Tests</v-tab>
          </v-tabs>

          <v-window v-model="tab">
            <!-- General Tab -->
            <v-window-item value="general">
              <v-text-field
                v-model="form.name"
                label="Function Name"
                placeholder="My Function"
                variant="outlined"
                density="compact"
                class="mb-3"
              />

              <v-text-field
                v-model="form.slug"
                label="Slug"
                placeholder="my_function (auto-generated)"
                variant="outlined"
                density="compact"
                hint="Unique identifier for this function"
                persistent-hint
                class="mb-3"
              />

              <v-textarea
                v-model="form.description"
                label="Description"
                placeholder="What does this function do?"
                variant="outlined"
                density="compact"
                rows="3"
                class="mb-3"
              />

              <v-select
                v-model="form.function_type"
                label="Function Type"
                :items="FUNCTION_TYPES"
                variant="outlined"
                density="compact"
                class="mb-3"
              />

              <v-select
                v-model="form.return_type"
                label="Return Type"
                :items="RETURN_TYPES"
                variant="outlined"
                density="compact"
                class="mb-3"
              />

              <v-slider
                v-model="form.timeout_seconds"
                label="Timeout (seconds)"
                :min="5"
                :max="120"
                :step="5"
                thumb-label
                class="mb-3"
              />

              <v-switch
                v-model="form.is_async"
                label="Asynchronous"
                color="primary"
                hint="Check if function performs async operations"
                persistent-hint
                class="mb-2"
              />

              <v-switch
                v-model="form.is_active"
                label="Active"
                color="success"
              />
            </v-window-item>

            <!-- Code Tab -->
            <v-window-item value="code">
              <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                Write your function code below. The function receives a
                <code>params</code> object and should return a value.
              </v-alert>

              <v-textarea
                v-model="form.code"
                label="Function Code"
                variant="outlined"
                rows="20"
                class="font-monospace"
                placeholder="Write your code here..."
              />

              <v-alert type="warning" variant="tonal" density="compact" class="mt-3">
                <strong>Available globals:</strong>
                <ul>
                  <li><code>params</code> - Input parameters object</li>
                  <li><code>console.log()</code> - Logging</li>
                  <li><code>fetch()</code> - HTTP requests (if async)</li>
                </ul>
              </v-alert>
            </v-window-item>

            <!-- Parameters Tab -->
            <v-window-item value="parameters">
              <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                Define the parameters this function accepts. These will be
                available in the <code>params</code> object.
              </v-alert>

              <v-card
                v-for="(param, index) in form.parameters"
                :key="index"
                variant="outlined"
                class="pa-3 mb-3"
              >
                <div class="d-flex align-center mb-2">
                  <v-chip size="small" variant="outlined">
                    Param {{ index + 1 }}
                  </v-chip>
                  <v-spacer />
                  <v-btn
                    icon="$trashCan"
                    size="x-small"
                    variant="text"
                    color="error"
                    @click="removeParameter(index)"
                  />
                </div>

                <v-text-field
                  v-model="param.name"
                  label="Parameter Name"
                  placeholder="paramName"
                  variant="outlined"
                  density="compact"
                  class="mb-2"
                />

                <v-select
                  v-model="param.type"
                  label="Type"
                  :items="PARAM_TYPES"
                  variant="outlined"
                  density="compact"
                  class="mb-2"
                />

                <v-text-field
                  v-model="param.default_value"
                  label="Default Value (optional)"
                  variant="outlined"
                  density="compact"
                  class="mb-2"
                />

                <v-switch
                  v-model="param.required"
                  label="Required"
                  color="error"
                  density="compact"
                  hide-details
                />
              </v-card>

              <v-btn
                variant="outlined"
                prepend-icon="$plus"
                @click="addParameter"
                block
              >
                Add Parameter
              </v-btn>
            </v-window-item>

            <!-- Tests Tab -->
            <v-window-item value="tests">
              <v-alert type="info" variant="tonal" density="compact" class="mb-3">
                Create test cases to validate your function works correctly.
              </v-alert>

              <v-card
                v-for="(test, index) in form.test_cases"
                :key="index"
                variant="outlined"
                class="pa-3 mb-3"
              >
                <div class="d-flex align-center mb-2">
                  <v-chip size="small" variant="outlined">
                    Test {{ index + 1 }}
                  </v-chip>
                  <v-spacer />
                  <v-btn
                    icon="$trashCan"
                    size="x-small"
                    variant="text"
                    color="error"
                    @click="removeTestCase(index)"
                  />
                </div>

                <v-textarea
                  v-model="test.input"
                  label="Input (JSON)"
                  placeholder='{"param1": "value"}'
                  variant="outlined"
                  density="compact"
                  rows="4"
                  class="font-monospace mb-2"
                />

                <v-textarea
                  v-model="test.expected_output"
                  label="Expected Output (JSON)"
                  placeholder='{"result": "expected"}'
                  variant="outlined"
                  density="compact"
                  rows="4"
                  class="font-monospace"
                />
              </v-card>

              <v-btn
                variant="outlined"
                prepend-icon="$plus"
                @click="addTestCase"
                block
              >
                Add Test Case
              </v-btn>
            </v-window-item>
          </v-window>
        </v-card-text>

        <v-divider />

        <v-card-actions>
          <v-btn variant="text" @click="editDialog = false">Cancel</v-btn>
          <v-spacer />
          <v-btn color="primary" variant="flat" @click="save">
            Save Function
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-card>
</template>

<script>
export default {
  data() {
    return {
      tab: "general",
    };
  },
};
</script>

<style scoped>
.font-monospace {
  font-family: "JetBrains Mono", "Courier New", monospace;
  font-size: 12px;
}

code {
  background: rgba(255, 255, 255, 0.1);
  padding: 2px 6px;
  border-radius: 3px;
  font-family: "JetBrains Mono", monospace;
}
</style>