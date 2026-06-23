<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";

const emit = defineEmits(["close", "refresh"]);

// State
const integrations = ref<any[]>([]);
const isLoading = ref(false);
const editDialog = ref(false);
const editingIntegration = ref<any>(null);

// Form
const form = ref({
  name: "",
  type: "rest",
  base_url: "",
  auth_type: "none",
  auth_config: {
    username: "",
    password: "",
    token: "",
    api_key: "",
    api_key_header: "X-API-Key",
  },
  headers: [] as { key: string; value: string }[],
  timeout_seconds: 30,
  retry_attempts: 3,
  is_active: true,
});

const AUTH_TYPES = [
  { value: "none", title: "No Authentication" },
  { value: "basic", title: "Basic Auth" },
  { value: "bearer", title: "Bearer Token" },
  { value: "api_key", title: "API Key" },
];

// Load integrations
async function loadIntegrations() {
  isLoading.value = true;
  try {
    const response = await axios.get("/api/api-integrations");
    integrations.value = response.data.integrations || [];
  } catch (error) {
    console.error("Failed to load integrations:", error);
  } finally {
    isLoading.value = false;
  }
}

// Create new
function createNew() {
  editingIntegration.value = null;
  form.value = {
    name: "",
    type: "rest",
    base_url: "",
    auth_type: "none",
    auth_config: {
      username: "",
      password: "",
      token: "",
      api_key: "",
      api_key_header: "X-API-Key",
    },
    headers: [],
    timeout_seconds: 30,
    retry_attempts: 3,
    is_active: true,
  };
  editDialog.value = true;
}

// Edit existing
function edit(integration: any) {
  editingIntegration.value = integration;
  form.value = {
    ...integration,
    auth_config: integration.auth_config || {
      username: "",
      password: "",
      token: "",
      api_key: "",
      api_key_header: "X-API-Key",
    },
    headers: integration.headers || [],
  };
  editDialog.value = true;
}

// Delete
async function deleteIntegration(id: number) {
  if (!confirm("Are you sure you want to delete this integration?")) return;
  
  try {
    await axios.delete(`/api/api-integrations/${id}`);
    await loadIntegrations();
    emit("refresh");
  } catch (error) {
    console.error("Failed to delete integration:", error);
  }
}

// Save
async function save() {
  try {
    const payload = {
      ...form.value,
      headers: form.value.headers.filter((h) => h.key && h.value),
    };

    if (editingIntegration.value) {
      await axios.put(
        `/api/api-integrations/${editingIntegration.value.id}`,
        payload
      );
    } else {
      await axios.post("/api/api-integrations", payload);
    }

    editDialog.value = false;
    await loadIntegrations();
    emit("refresh");
  } catch (error) {
    console.error("Failed to save integration:", error);
  }
}

// Test integration
async function testIntegration(integration: any) {
  try {
    const response = await axios.post(
      `/api/api-integrations/${integration.id}/test`
    );
    alert(`Test successful!\n${JSON.stringify(response.data, null, 2)}`);
  } catch (error: any) {
    alert(`Test failed: ${error.response?.data?.message || error.message}`);
  }
}

// Header management
function addHeader() {
  form.value.headers.push({ key: "", value: "" });
}

function removeHeader(index: number) {
  form.value.headers.splice(index, 1);
}

onMounted(() => {
  loadIntegrations();
});
</script>

<template>
  <v-card>
    <v-card-title class="d-flex align-center">
      <v-icon icon="$api" class="mr-2" />
      API Integrations
      <v-spacer />
      <v-btn
        color="primary"
        variant="flat"
        prepend-icon="$plus"
        @click="createNew"
        size="small"
      >
        New Integration
      </v-btn>
    </v-card-title>

    <v-divider />

    <v-card-text style="max-height: 500px; overflow-y: auto">
      <v-progress-linear v-if="isLoading" indeterminate color="primary" />

      <template v-else>
        <v-alert
          v-if="integrations.length === 0"
          type="info"
          variant="tonal"
          class="mb-4"
        >
          No API integrations configured yet. Create one to connect external
          services.
        </v-alert>

        <v-list v-else>
          <v-list-item
            v-for="integration in integrations"
            :key="integration.id"
            :class="{ 'opacity-50': !integration.is_active }"
          >
            <template #prepend>
              <v-avatar :color="integration.is_active ? 'success' : 'grey'">
                <v-icon icon="$api" />
              </v-avatar>
            </template>

            <v-list-item-title>{{ integration.name }}</v-list-item-title>
            <v-list-item-subtitle>
              {{ integration.type.toUpperCase() }} • {{ integration.base_url }}
            </v-list-item-subtitle>

            <template #append>
              <v-btn
                icon="$playCircle"
                size="small"
                variant="text"
                @click="testIntegration(integration)"
                :disabled="!integration.is_active"
              />
              <v-btn
                icon="$pencil"
                size="small"
                variant="text"
                @click="edit(integration)"
              />
              <v-btn
                icon="$trashCan"
                size="small"
                variant="text"
                color="error"
                @click="deleteIntegration(integration.id)"
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
    <v-dialog v-model="editDialog" max-width="700" scrollable>
      <v-card>
        <v-card-title>
          {{ editingIntegration ? "Edit" : "New" }} API Integration
        </v-card-title>

        <v-divider />

        <v-card-text class="pa-4" style="max-height: 600px">
          <v-text-field
            v-model="form.name"
            label="Integration Name"
            placeholder="My API"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <v-select
            v-model="form.type"
            label="API Type"
            :items="[
              { value: 'rest', title: 'REST API' },
              { value: 'graphql', title: 'GraphQL' },
              { value: 'soap', title: 'SOAP' },
            ]"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <v-text-field
            v-model="form.base_url"
            label="Base URL"
            placeholder="https://api.example.com"
            variant="outlined"
            density="compact"
            class="mb-4"
          />

          <v-divider class="my-4" />

          <div class="text-subtitle-2 mb-3">Authentication</div>

          <v-select
            v-model="form.auth_type"
            label="Auth Type"
            :items="AUTH_TYPES"
            variant="outlined"
            density="compact"
            class="mb-3"
          />

          <!-- Basic Auth -->
          <template v-if="form.auth_type === 'basic'">
            <v-text-field
              v-model="form.auth_config.username"
              label="Username"
              variant="outlined"
              density="compact"
              class="mb-2"
            />
            <v-text-field
              v-model="form.auth_config.password"
              label="Password"
              type="password"
              variant="outlined"
              density="compact"
            />
          </template>

          <!-- Bearer Token -->
          <template v-if="form.auth_type === 'bearer'">
            <v-text-field
              v-model="form.auth_config.token"
              label="Bearer Token"
              type="password"
              variant="outlined"
              density="compact"
            />
          </template>

          <!-- API Key -->
          <template v-if="form.auth_type === 'api_key'">
            <v-text-field
              v-model="form.auth_config.api_key_header"
              label="Header Name"
              placeholder="X-API-Key"
              variant="outlined"
              density="compact"
              class="mb-2"
            />
            <v-text-field
              v-model="form.auth_config.api_key"
              label="API Key"
              type="password"
              variant="outlined"
              density="compact"
            />
          </template>

          <v-divider class="my-4" />

          <div class="text-subtitle-2 mb-3">Custom Headers</div>

          <v-card
            v-for="(header, index) in form.headers"
            :key="index"
            variant="outlined"
            class="pa-2 mb-2"
          >
            <div class="d-flex gap-2">
              <v-text-field
                v-model="header.key"
                label="Header Name"
                placeholder="Content-Type"
                variant="outlined"
                density="compact"
                hide-details
              />
              <v-text-field
                v-model="header.value"
                label="Header Value"
                placeholder="application/json"
                variant="outlined"
                density="compact"
                hide-details
              />
              <v-btn
                icon="$trashCan"
                size="small"
                variant="text"
                color="error"
                @click="removeHeader(index)"
              />
            </div>
          </v-card>

          <v-btn
            variant="outlined"
            size="small"
            prepend-icon="$plus"
            @click="addHeader"
            block
            class="mb-4"
          >
            Add Header
          </v-btn>

          <v-divider class="my-4" />

          <div class="text-subtitle-2 mb-3">Advanced Settings</div>

          <v-slider
            v-model="form.timeout_seconds"
            label="Timeout (seconds)"
            :min="5"
            :max="120"
            :step="5"
            thumb-label
            class="mb-3"
          />

          <v-slider
            v-model="form.retry_attempts"
            label="Retry Attempts"
            :min="0"
            :max="5"
            :step="1"
            thumb-label
            class="mb-3"
          />

          <v-switch
            v-model="form.is_active"
            label="Active"
            color="success"
            hide-details
          />
        </v-card-text>

        <v-divider />

        <v-card-actions>
          <v-spacer />
          <v-btn variant="text" @click="editDialog = false">Cancel</v-btn>
          <v-btn color="primary" variant="flat" @click="save">Save</v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>
  </v-card>
</template>

<style scoped>
.gap-2 {
  gap: 8px;
}
</style>