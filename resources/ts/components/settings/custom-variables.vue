<template>
  <div class="custom-variables-library">
    <h3>Custom Variables</h3>
    <p>
      Define custom variables for this flow that can be saved to bot variables
      or user properties.
    </p>

    <VAlert
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
      icon="false"
      rounded="md"
    >
      <template #prepend>
        <SvgSprite
          name="custom-warning-fill"
          style="width: 20px; height: 20px"
        />
      </template>
      <div class="text-caption">
        Variables defined here will be available for use in all nodes within
        this flow.
      </div>
    </VAlert>

    <v-row class="mb-4">
      <v-col cols="12" md="6">
        <v-text-field
          v-model="searchQuery"
          placeholder="Search variables..."
          prepend-inner-icon="$magnify"
          density="compact"
          variant="outlined"
          hide-details
        />
      </v-col>
      <v-col cols="12" md="6" class="d-flex justify-end">
        <v-btn
          color="primary"
          prepend-icon="$plus"
          @click="openVariableForm"
          :disabled="isReadOnly"
        >
          Add Variable
        </v-btn>
      </v-col>
    </v-row>

    <!-- Variable Form -->
    <v-expand-transition>
      <v-card v-if="showForm" class="mb-4 pa-4" variant="outlined">
        <v-card-title
          class="d-flex justify-space-between align-center pa-0 mb-4"
        >
          <span class="text-h6">{{
            editingVariable ? "Edit Variable" : "New Variable"
          }}</span>
          <v-btn
            icon="$close"
            size="small"
            variant="text"
            @click="closeVariableForm"
          />
        </v-card-title>

        <v-form @submit.prevent="submitVariable">
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="variableForm.name"
                label="Variable Name *"
                variant="outlined"
                density="compact"
                hint="Use lowercase with underscores (e.g., user_age)"
                persistent-hint
                required
              >
                <template #prepend-inner>
                  <VIcon icon="$variable" size="small" />
                </template>
              </v-text-field>
            </v-col>

            <v-col cols="12" md="6">
              <v-select
                v-model="variableForm.save_in"
                label="Save In *"
                :items="saveInOptions"
                variant="outlined"
                density="compact"
                hint="Where to store this variable"
                persistent-hint
                required
              >
                <template #prepend-inner>
                  <VIcon icon="$database" size="small" />
                </template>
              </v-select>
            </v-col>

            <v-col cols="12" md="6">
              <v-checkbox
                v-model="variableForm.use_in_js"
                label="Use in JavaScript Functions"
                density="compact"
                hint="Make available to custom JavaScript functions"
                persistent-hint
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-checkbox
                v-model="variableForm.is_sensitive"
                label="Sensitive Data"
                density="compact"
                hint="Mark as sensitive (e.g., passwords, API keys)"
                persistent-hint
              />
            </v-col>

            <v-col cols="12">
              <v-alert type="info" variant="tonal" density="compact">
                <div class="text-caption">
                  <strong>Bot Variables:</strong> Stored per conversation
                  session<br />
                  <strong>User Properties:</strong> Stored permanently per user
                  across all conversations
                </div>
              </v-alert>
            </v-col>
          </v-row>

          <v-divider class="my-4" />

          <div class="d-flex justify-end gap-2">
            <v-btn variant="outlined" color="error" @click="closeVariableForm">
              Cancel
            </v-btn>
            <v-btn color="primary" type="submit" :loading="isSubmitting">
              {{ editingVariable ? "Update" : "Create" }} Variable
            </v-btn>
          </div>
        </v-form>
      </v-card>
    </v-expand-transition>

    <!-- Variables List -->
    <v-data-table
      :headers="tableHeaders"
      :items="filteredVariables"
      :loading="isLoading"
      class="elevation-1"
    >
      <template #item.name="{ item }">
        <div class="d-flex align-center">
          <VIcon icon="$variable" size="small" class="mr-2" />
          <code class="text-primary">{{ formatVariable(item.name) }}</code>
        </div>
      </template>

      <template #item.save_in="{ item }">
        <v-chip
          size="small"
          :color="item.save_in === 'bot_variables' ? 'primary' : 'success'"
          variant="flat"
        >
          {{
            item.save_in === "bot_variables" ? "Bot Variables" : "User Props"
          }}
        </v-chip>
      </template>

      <template #item.use_in_js="{ item }">
        <v-icon
          :icon="item.use_in_js ? '$checkCircle' : '$closeCircle'"
          :color="item.use_in_js ? 'success' : 'grey'"
          size="small"
        />
      </template>

      <template #item.is_sensitive="{ item }">
        <v-icon
          :icon="item.is_sensitive ? '$lock' : '$lockOpen'"
          :color="item.is_sensitive ? 'warning' : 'grey'"
          size="small"
        />
      </template>

      <template #item.actions="{ item }">
        <template v-if="item.id || item.flow_id">
          <v-btn
            icon="$pencil"
            size="x-small"
            variant="text"
            :disabled="isReadOnly"
            @click="editVariable(item)"
          />
          <v-btn
            icon="$delete"
            size="x-small"
            variant="text"
            color="error"
            :disabled="isReadOnly"
            @click="deleteVariable(item)"
          />
        </template>

        <span v-else class="text-caption text-grey"> System Variables </span>
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
  isReadOnly: boolean;
}>();

const emit = defineEmits<{
  (e: "variablesUpdated", variables: any[]): void;
}>();

// State
const searchQuery = ref("");
const showForm = ref(false);
const editingVariable = ref<any>(null);
const isLoading = ref(false);
const isSubmitting = ref(false);
const allVariables = ref<any[]>([]);

const formatVariable = (variable: string) => `{{${variable}}}`;

const variableForm = ref({
  name: "",
  save_in: "bot_variables",
  use_in_js: false,
  is_sensitive: false,
});

const saveInOptions = [
  {
    value: "bot_variables",
    title: "Bot Variables (per conversation)",
  },
  {
    value: "user_properties",
    title: "User Properties (permanent)",
  },
];

const tableHeaders = [
  { title: "Variable Name", key: "name" },
  { title: "Storage", key: "save_in" },
  { title: "JS Access", key: "use_in_js" },
  { title: "Sensitive", key: "is_sensitive" },
  { title: "Actions", key: "actions", sortable: false },
];

// Computed
const filteredVariables = computed(() => {
  if (!searchQuery.value) return allVariables.value;
  return allVariables.value.filter((v) =>
    v.name.toLowerCase().includes(searchQuery.value.toLowerCase()),
  );
});

// Methods
async function loadVariables() {
  isLoading.value = true;
  try {
    const response = await axios.get(`/api/flows/${props.flowId}/variables`);
    allVariables.value = response.data.variables || [];
    emit("variablesUpdated", allVariables.value);
  } catch (error) {
    console.error("Failed to load variables:", error);
    Swal.fire("Error", "Failed to load variables", "error");
  } finally {
    isLoading.value = false;
  }
}

function openVariableForm() {
  showForm.value = true;
  editingVariable.value = null;
  resetForm();
}

function closeVariableForm() {
  showForm.value = false;
  editingVariable.value = null;
  resetForm();
}

function resetForm() {
  variableForm.value = {
    name: "",
    save_in: "bot_variables",
    use_in_js: false,
    is_sensitive: false,
  };
}

function editVariable(variable: any) {
  editingVariable.value = variable;
  variableForm.value = {
    name: variable.name,
    save_in: variable.save_in,
    use_in_js: variable.use_in_js || false,
    is_sensitive: variable.is_sensitive || false,
  };
  showForm.value = true;
}

async function deleteVariable(variable: any) {
  const result = await Swal.fire({
    title: "Delete Variable?",
    text: `Are you sure you want to delete "${variable.name}"?`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Delete",
  });

  if (!result.isConfirmed) return;

  try {
    await axios.delete(`/api/flows/${props.flowId}/variables/${variable.id}`);
    allVariables.value = allVariables.value.filter((v) => v.id !== variable.id);
    emit("variablesUpdated", allVariables.value);
    Swal.fire("Deleted!", "Variable has been deleted.", "success");
  } catch (error: any) {
    Swal.fire(
      "Error",
      error.response?.data?.message || "Failed to delete variable",
      "error",
    );
  }
}

async function submitVariable() {
  // Validate variable name format
  const nameRegex = /^[a-z_][a-z0-9_]*$/;
  if (!nameRegex.test(variableForm.value.name)) {
    Swal.fire({
      title: "Invalid Name",
      text: "Variable name must start with a lowercase letter or underscore, and contain only lowercase letters, numbers, and underscores.",
      icon: "error",
    });
    return;
  }

  isSubmitting.value = true;

  try {
    const payload = {
      name: variableForm.value.name,
      save_in: variableForm.value.save_in,
      use_in_js: variableForm.value.use_in_js,
      is_sensitive: variableForm.value.is_sensitive,
    };

    let response;
    if (editingVariable.value) {
      response = await axios.put(
        `/api/flows/${props.flowId}/variables/${editingVariable.value.id}`,
        payload,
      );
    } else {
      response = await axios.post(
        `/api/flows/${props.flowId}/variables`,
        payload,
      );
    }

    if (editingVariable.value) {
      allVariables.value = allVariables.value.map((v) =>
        v.id === editingVariable.value.id ? response.data.variable : v,
      );
    } else {
      allVariables.value.push(response.data.variable);
    }

    emit("variablesUpdated", allVariables.value);

    Swal.fire({
      title: "Success!",
      text: `Variable ${editingVariable.value ? "updated" : "created"} successfully`,
      icon: "success",
      timer: 2000,
    });

    closeVariableForm();
  } catch (error: any) {
    Swal.fire({
      title: "Error",
      text: error.response?.data?.message || "Failed to save variable",
      icon: "error",
    });
  } finally {
    isSubmitting.value = false;
  }
}

onMounted(() => {
  loadVariables();
});

// Expose load method for parent component
defineExpose({
  loadVariables,
});
</script>

<style scoped>
.gap-2 {
  gap: 8px;
}

code {
  background: rgba(var(--v-theme-primary), 0.1);
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 0.875rem;
}
</style>
