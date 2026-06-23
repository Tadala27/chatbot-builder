<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface WhatsappAccount {
  id: number;
  display_phone_number: string | null;
  phone_number: string;
  mode: "managed_bot" | "connector";
  webhook_url: string | null;
  is_active: boolean;
}

const emit = defineEmits<{ connected: [WhatsappAccount] }>();

// ── State ─────────────────────────────────────────────────────────────────────

const loading = ref(true);
const connecting = ref(false);
const rotating = ref(false);

const existingAccount = ref<WhatsappAccount | null>(null);
const endpointUrl = ref<string | null>(null);

// Only ever non-null right after connect/rotate — the key is shown once and
// never retrievable again, by design (it's not stored in plaintext).
const revealedKey = ref<string | null>(null);

const form = ref({
  phone_number: "",
  webhook_url: "",
});

const errors = ref<Record<string, string>>({});

// ── Fetch existing connector account on mount ──────────────────────────────
// Since a tenant can only ever have ONE connector account, this either
// finds it (and shows the instructions panel) or finds nothing (and shows
// the connect form). No list view needed.

const fetchExisting = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get("/tenant/whatsapp-accounts/connector");
    existingAccount.value = data.account;
    endpointUrl.value = data.endpoint_url ?? null;
  } finally {
    loading.value = false;
  }
};

// ── Connect ───────────────────────────────────────────────────────────────────

const connect = async () => {
  errors.value = {};

  if (!form.value.phone_number)
    errors.value.phone_number = "Phone number is required.";
  if (!form.value.webhook_url)
    errors.value.webhook_url = "Webhook URL is required.";
  if (Object.keys(errors.value).length) return;

  connecting.value = true;
  try {
    const { data } = await axios.post(
      "/tenant/whatsapp-accounts/connect-connector",
      form.value,
    );
    existingAccount.value = data.account;
    endpointUrl.value = data.endpoint_url;
    revealedKey.value = data.connector_api_key;
    emit("connected", data.account);
  } catch (e: any) {
    const serverErrors = e.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
      );
    }
    Swal.fire({
      title: "Couldn't connect",
      text: e.response?.data?.message ?? "Failed to connect this number.",
      icon: "error",
    });
  } finally {
    connecting.value = false;
  }
};

// ── Rotate key (only place a key can be re-revealed) ───────────────────────

const rotateKey = async () => {
  if (!existingAccount.value) return;

  const { isConfirmed } = await Swal.fire({
    title: "Rotate API Key",
    text: "The current key stops working immediately. Update your integration with the new key right away.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonText: "Rotate Key",
  });
  if (!isConfirmed) return;

  rotating.value = true;
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${existingAccount.value.id}/rotate-connector-key`,
    );
    revealedKey.value = data.connector_api_key;
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to rotate key.",
      icon: "error",
    });
  } finally {
    rotating.value = false;
  }
};

const disconnect = async () => {
  if (!existingAccount.value) return;

  const { isConfirmed } = await Swal.fire({
    title: "Disconnect Number",
    text: "Your external chatbot will stop receiving messages through this number. You can reconnect a number afterwards.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
  });
  if (!isConfirmed) return;

  try {
    await axios.post(
      `/tenant/whatsapp-accounts/${existingAccount.value.id}/disconnect`,
    );
    existingAccount.value = null;
    endpointUrl.value = null;
    revealedKey.value = null;
  } catch (e: any) {
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to disconnect.",
      icon: "error",
    });
  }
};

const copy = (text: string | null) => {
  if (!text) return;
  navigator.clipboard.writeText(text);
  Swal.fire({
    title: "Copied",
    icon: "success",
    timer: 1000,
    showConfirmButton: false,
  });
};

onMounted(fetchExisting);
</script>

<template>
  <VCard variant="outlined" elevation="0">
    <VCardTitle class="d-flex align-center gap-2">
      <VIcon icon="$linkVariant" />
      Connect Your Own Chatbot
    </VCardTitle>
    <VDivider />

    <VCardText>
      <div v-if="loading" class="d-flex justify-center py-8">
        <VProgressCircular indeterminate color="primary" size="32" />
      </div>

      <!-- ── Already connected — instructions panel ───────────────────── -->
      <template v-else-if="existingAccount">
        <VAlert
          :type="existingAccount.is_active ? 'success' : 'warning'"
          variant="tonal"
          class="mb-4"
        >
          <p class="font-weight-medium mb-1">
            {{
              existingAccount.display_phone_number ??
              existingAccount.phone_number
            }}
            {{ existingAccount.is_active ? "connected" : "disconnected" }}
          </p>
          <p class="text-caption mb-0">
            Incoming messages on this number are forwarded to:
            <code>{{ existingAccount.webhook_url }}</code>
          </p>
        </VAlert>

        <VAlert
          v-if="revealedKey"
          type="info"
          variant="tonal"
          class="mb-4"
          closable
          @click:close="revealedKey = null"
        >
          <p class="font-weight-medium mb-1">
            Your connector API key (shown once):
          </p>
          <div class="d-flex align-center gap-2">
            <code class="text-body-2" style="word-break: break-all">{{
              revealedKey
            }}</code>
            <VBtn icon size="x-small" variant="text" @click="copy(revealedKey)"
              ><VIcon size="16">$contentCopy</VIcon></VBtn
            >
          </div>
          <p class="text-caption mt-2 mb-0">
            Save this now — it won't be shown again.
          </p>
        </VAlert>

        <!-- ── Setup instructions — framework/language agnostic ────────── -->
        <p class="text-subtitle-2 font-weight-medium mb-2">
          How to send replies
        </p>
        <p class="text-body-2 text-medium-emphasis mb-3">
          From your own system (any language — this is a plain HTTP API, not
          tied to any particular framework), send a <code>POST</code> request to
          this exact URL (it's the same URL for everyone — your API key is what
          identifies you, not the URL):
        </p>

        <div class="d-flex align-center gap-2 mb-3">
          <VTextField
            :model-value="endpointUrl"
            readonly
            variant="outlined"
            density="compact"
            hide-details
          />
          <VBtn icon variant="text" @click="copy(endpointUrl)"
            ><VIcon size="18">$contentCopy</VIcon></VBtn
          >
        </div>

        <p class="text-body-2 text-medium-emphasis mb-2">
          Include your API key in the <code>X-Connector-Key</code> header, and a
          JSON body like:
        </p>

        <VCard variant="tonal" color="grey" class="pa-3 mb-4">
          <pre class="text-caption" style="white-space: pre-wrap; margin: 0">
            curl -X POST "{{ endpointUrl }}" \
              -H "X-Connector-Key: &lt;your key&gt;" \
              -H "Content-Type: application/json" \
              -d '{
                "to": "265997123456",
                "type": "text",
                "text": "Hello from my own chatbot!"
              }'
          </pre>
        </VCard>

        <p class="text-caption text-medium-emphasis mb-4">
          We forward every incoming WhatsApp message on your number to your
          webhook URL as Meta's raw JSON payload — no setup needed on our side
          beyond what's configured here.
        </p>

        <VAlert type="info" variant="tonal" density="compact" class="mb-4">
          <p class="text-body-2 mb-0">
            Works with any language or framework — Python, Node, Laravel,
            anything that can receive a webhook and make an HTTP request.
            <a
              href="/docs/connector-integration"
              target="_blank"
              class="text-decoration-underline"
            >
              Full integration guide →
            </a>
          </p>
        </VAlert>

        <div class="d-flex gap-2">
          <VBtn
            variant="outlined"
            class="mx-2"
            color="warning"
            size="small"
            :loading="rotating"
            prepend-icon="$reload"
            @click="rotateKey"
          >
            Rotate API Key
          </VBtn>
          <VBtn
            variant="outlined"
            color="error"
            size="small"
            prepend-icon="$linkOff"
            @click="disconnect"
          >
            Disconnect
          </VBtn>
        </div>
      </template>

      <!-- ── Not connected — form ─────────────────────────────────────── -->
      <template v-else>
        <p class="text-body-2 text-medium-emphasis mb-4">
          Already have a chatbot running elsewhere? Connect your WhatsApp number
          here and we'll forward incoming messages to your webhook — you reply
          using your own system, through your number, no flows needed in this
          dashboard.
        </p>

        <VTextField
          v-model="form.phone_number"
          label="WhatsApp Number"
          placeholder="265997123456"
          hint="Country code + number, digits only"
          persistent-hint
          variant="outlined"
          density="comfortable"
          class="mb-4"
          :error-messages="errors.phone_number"
        />

        <VTextField
          v-model="form.webhook_url"
          label="Your Webhook URL"
          placeholder="https://your-system.com/webhooks/whatsapp"
          hint="We'll POST every incoming message here as JSON"
          persistent-hint
          variant="outlined"
          density="comfortable"
          class="mb-4"
          :error-messages="errors.webhook_url"
        />

        <VBtn
          color="primary"
          :loading="connecting"
          prepend-icon="$linkVariant"
          @click="connect"
        >
          Connect Number
        </VBtn>
      </template>
    </VCardText>
  </VCard>
</template>
