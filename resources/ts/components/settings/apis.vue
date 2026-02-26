<template>
  <div class="api-library">
    <h3>API Integrations</h3>
    <p>
      Integrate your bot with external services through APIs to push and fetch
      data dynamically.
    </p>

    <v-row class="mb-4">
      <v-col cols="12" md="6">
        <v-text-field
          v-model="searchQuery"
          placeholder="Search APIs..."
          prepend-inner-icon="$magnify"
          density="compact"
          variant="outlined"
          hide-details
        />
      </v-col>
      <v-col cols="12" md="6" class="d-flex justify-end">
        <v-btn color="primary" prepend-icon="$plus" @click="openApiForm">
          Add API
        </v-btn>
      </v-col>
    </v-row>

    <!-- API Form -->
    <v-expand-transition>
      <v-card v-if="showForm" class="mb-4 pa-4" variant="outlined">
        <v-card-title
          class="d-flex justify-space-between align-center pa-0 mb-4"
        >
          <span class="text-h6">{{ editingApi ? "Edit API" : "New API" }}</span>
          <v-btn
            icon="$close"
            size="small"
            variant="text"
            @click="closeApiForm"
          />
        </v-card-title>

        <v-form @submit.prevent="submitApi">
          <v-row>
            <v-col cols="12" md="6">
              <v-text-field
                v-model="apiForm.name"
                label="API Name *"
                variant="outlined"
                density="compact"
                required
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-select
                v-model="apiForm.type"
                label="API Type *"
                :items="[
                  { value: 'rest', title: 'REST' },
                  { value: 'graphql', title: 'GraphQL' },
                  { value: 'soap', title: 'SOAP' },
                  { value: 'webhook', title: 'Webhook' },
                ]"
                variant="outlined"
                density="compact"
                required
              />
            </v-col>

            <v-col cols="12">
              <v-text-field
                v-model="apiForm.base_url"
                label="Base URL *"
                variant="outlined"
                density="compact"
                placeholder="https://api.example.com"
                hint="The base URL for all API requests"
                persistent-hint
                required
              />
            </v-col>

            <!-- Authentication Section -->
            <v-col cols="12">
              <v-divider class="my-2" />
              <h6 class="text-h6 mb-3">Authentication</h6>
            </v-col>

            <v-col cols="12" md="6">
              <v-select
                v-model="apiForm.auth_type"
                label="Auth Type *"
                :items="[
                  { value: 'none', title: 'None' },
                  { value: 'basic', title: 'Basic Auth' },
                  { value: 'bearer', title: 'Bearer Token' },
                  { value: 'api_key', title: 'API Key' },
                  { value: 'oauth2', title: 'OAuth 2.0' },
                ]"
                variant="outlined"
                density="compact"
                required
              />
            </v-col>

            <!-- Basic Auth Fields -->
            <template v-if="apiForm.auth_type === 'basic'">
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="apiForm.auth_config.username"
                  label="Username"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="apiForm.auth_config.password"
                  label="Password"
                  type="password"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
            </template>

            <!-- Bearer Token Fields -->
            <template v-if="apiForm.auth_type === 'bearer'">
              <v-col cols="12">
                <v-text-field
                  v-model="apiForm.auth_config.token"
                  label="Bearer Token"
                  variant="outlined"
                  density="compact"
                  type="password"
                />
              </v-col>
            </template>

            <!-- API Key Fields -->
            <template v-if="apiForm.auth_type === 'api_key'">
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="apiForm.auth_config.key"
                  label="Header Name"
                  placeholder="X-API-Key"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
              <v-col cols="12" md="6">
                <v-text-field
                  v-model="apiForm.auth_config.value"
                  label="API Key Value"
                  type="password"
                  variant="outlined"
                  density="compact"
                />
              </v-col>
            </template>

            <!-- OAuth 2.0 Fields -->
            <template v-if="apiForm.auth_type === 'oauth2'">
              <v-col cols="12">
                <v-text-field
                  v-model="apiForm.auth_config.access_token"
                  label="Access Token"
                  variant="outlined"
                  density="compact"
                  type="password"
                />
              </v-col>
            </template>

            <!-- Headers Section -->
            <v-col cols="12">
              <v-divider class="my-2" />
              <v-checkbox
                v-model="showHeaders"
                label="Add Custom Headers"
                density="compact"
              />
            </v-col>

            <v-col cols="12" v-if="showHeaders">
              <div
                v-for="(header, idx) in apiForm.headers"
                :key="idx"
                class="d-flex gap-2 mb-2 align-center"
              >
                <v-text-field
                  v-model="header.key"
                  placeholder="Header Key"
                  variant="outlined"
                  density="compact"
                  hide-details
                  style="width: 200px"
                />
                <v-text-field
                  v-model="header.value"
                  placeholder="Header Value"
                  variant="outlined"
                  density="compact"
                  hide-details
                  style="flex: 1"
                />
                <v-btn
                  icon="$close"
                  size="x-small"
                  variant="text"
                  color="error"
                  @click="removeHeader(idx)"
                />
              </div>
              <v-btn
                size="x-small"
                variant="outlined"
                prepend-icon="$plus"
                @click="addHeader"
              >
                Add Header
              </v-btn>
            </v-col>

            <!-- Advanced Settings -->
            <v-col cols="12">
              <v-divider class="my-2" />
              <h6 class="text-h6 mb-3">Advanced Settings</h6>
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field
                v-model.number="apiForm.timeout_seconds"
                label="Timeout (seconds)"
                type="number"
                variant="outlined"
                density="compact"
                min="1"
                max="300"
              />
            </v-col>

            <v-col cols="12" md="6">
              <v-text-field
                v-model.number="apiForm.retry_attempts"
                label="Retry Attempts"
                type="number"
                variant="outlined"
                density="compact"
                min="0"
                max="10"
              />
            </v-col>

            <v-col cols="12">
              <v-checkbox
                v-model="apiForm.is_active"
                label="Active (enable this integration)"
                density="compact"
              />
            </v-col>
          </v-row>

          <v-divider class="my-4" />

          <div class="d-flex justify-end gap-2">
            <v-btn variant="outlined" color="error" @click="closeApiForm">
              Cancel
            </v-btn>
            <v-btn color="primary" type="submit" :loading="isSubmitting">
              {{ editingApi ? "Update" : "Create" }} API
            </v-btn>
          </div>
        </v-form>
      </v-card>
    </v-expand-transition>

    <!-- API List -->
    <v-data-table
      :headers="tableHeaders"
      :items="filteredApis"
      :loading="isLoading"
      class="elevation-1"
    >
      <template #item.type="{ item }">
        <v-chip size="small" :color="getTypeColor(item.type)" variant="flat">
          {{ item.type.toUpperCase() }}
        </v-chip>
      </template>

      <template #item.auth_type="{ item }">
        <v-chip size="small" color="info" variant="tonal">
          {{ item.auth_type.replace("_", " ").toUpperCase() }}
        </v-chip>
      </template>

      <template #item.is_active="{ item }">
        <v-icon
          :icon="item.is_active ? '$checkCircle' : '$closeCircle'"
          :color="item.is_active ? 'success' : 'grey'"
          size="small"
        />
      </template>

      <template #item.created_at="{ item }">
        {{ formatDate(item.created_at) }}
      </template>

      <template #item.actions="{ item }">
        <v-btn
          icon="$pencil"
          size="x-small"
          variant="text"
          @click="editApi(item)"
        />
        <v-btn
          icon="$delete"
          size="x-small"
          variant="text"
          color="error"
          @click="deleteApi(item)"
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
  (e: "apisUpdated", apis: any[]): void;
}>();

// State
const searchQuery = ref("");
const showForm = ref(false);
const editingApi = ref<any>(null);
const isLoading = ref(false);
const isSubmitting = ref(false);
const showHeaders = ref(false);
const allApis = ref<any[]>([]);

const apiForm = ref({
  name: "",
  type: "rest",
  base_url: "",
  auth_type: "none",
  auth_config: {} as any,
  headers: [] as any[],
  timeout_seconds: 30,
  retry_attempts: 3,
  is_active: true,
});

const tableHeaders = [
  { title: "Name", key: "name" },
  { title: "Type", key: "type" },
  { title: "Base URL", key: "base_url" },
  { title: "Auth", key: "auth_type" },
  { title: "Active", key: "is_active" },
  { title: "Created", key: "created_at" },
  { title: "Actions", key: "actions", sortable: false },
];

// Computed
const filteredApis = computed(() => {
  if (!searchQuery.value) return allApis.value;

  return allApis.value.filter(
    (api: any) =>
      api.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
      api.base_url.toLowerCase().includes(searchQuery.value.toLowerCase()),
  );
});

// Methods
async function loadApis() {
  isLoading.value = true;
  try {
    const response = await axios.get(`/api/api-integrations`);
    allApis.value = response.data.data || [];
    emit("apisUpdated", allApis.value);
  } catch (error) {
    console.error("Failed to load APIs:", error);
    Swal.fire("Error", "Failed to load API integrations", "error");
  } finally {
    isLoading.value = false;
  }
}

function getTypeColor(type: string): string {
  const colors: Record<string, string> = {
    rest: "success",
    graphql: "primary",
    soap: "warning",
    webhook: "info",
  };
  return colors[type] || "default";
}

function formatDate(date: string): string {
  return new Date(date).toLocaleDateString();
}

function openApiForm() {
  showForm.value = true;
  editingApi.value = null;
  resetForm();
}

function closeApiForm() {
  showForm.value = false;
  editingApi.value = null;
  resetForm();
}

function resetForm() {
  apiForm.value = {
    name: "",
    type: "rest",
    base_url: "",
    auth_type: "none",
    auth_config: {},
    headers: [],
    timeout_seconds: 30,
    retry_attempts: 3,
    is_active: true,
  };
  showHeaders.value = false;
}

function editApi(api: any) {
  editingApi.value = api;
  apiForm.value = {
    name: api.name,
    type: api.type,
    base_url: api.base_url,
    auth_type: api.auth_type,
    auth_config: api.auth_config || {},
    headers: api.headers || [],
    timeout_seconds: api.timeout_seconds || 30,
    retry_attempts: api.retry_attempts || 3,
    is_active: api.is_active ?? true,
  };
  showHeaders.value = (api.headers || []).length > 0;
  showForm.value = true;
}

async function deleteApi(api: any) {
  const result = await Swal.fire({
    title: "Delete API?",
    text: `Are you sure you want to delete "${api.name}"?`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#d33",
    cancelButtonColor: "#3085d6",
    confirmButtonText: "Delete",
  });

  if (!result.isConfirmed) return;

  try {
    await axios.delete(`/api/api-integrations/${api.id}`);
    allApis.value = allApis.value.filter((a: any) => a.id !== api.id);
    emit("apisUpdated", allApis.value);
    Swal.fire("Deleted!", "API has been deleted.", "success");
  } catch (error: any) {
    Swal.fire(
      "Error",
      error.response?.data?.message || "Failed to delete API",
      "error",
    );
  }
}

function addHeader() {
  apiForm.value.headers.push({ key: "", value: "" });
}

function removeHeader(index: number) {
  apiForm.value.headers.splice(index, 1);
}

async function submitApi() {
  isSubmitting.value = true;

  try {
    const payload = {
      name: apiForm.value.name,
      type: apiForm.value.type,
      base_url: apiForm.value.base_url,
      auth_type: apiForm.value.auth_type,
      auth_config: apiForm.value.auth_config,
      headers: apiForm.value.headers,
      timeout_seconds: apiForm.value.timeout_seconds,
      retry_attempts: apiForm.value.retry_attempts,
      is_active: apiForm.value.is_active,
    };

    let response;
    if (editingApi.value) {
      response = await axios.put(
        `/api/api-integrations/${editingApi.value.id}`,
        payload,
      );
    } else {
      response = await axios.post(`/api/api-integrations`, payload);
    }

    if (editingApi.value) {
      allApis.value = allApis.value.map((a: any) =>
        a.id === editingApi.value.id ? response.data : a,
      );
    } else {
      allApis.value.push(response.data);
    }

    emit("apisUpdated", allApis.value);

    Swal.fire({
      title: "Success!",
      text: `API ${editingApi.value ? "updated" : "created"} successfully`,
      icon: "success",
      timer: 2000,
    });

    closeApiForm();
  } catch (error: any) {
    Swal.fire({
      title: "Error",
      text: error.response?.data?.message || "Failed to save API",
      icon: "error",
    });
  } finally {
    isSubmitting.value = false;
  }
}

onMounted(() => {
  loadApis();
});

// Expose load method
defineExpose({
  loadApis,
});
</script>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>
