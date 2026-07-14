<script setup lang="ts">
import { ref, computed } from "vue";
import axios from "axios";
import Swal from "sweetalert2";

interface WhatsappAccount {
  id: number;
  phone_number: string;
  display_phone_number: string | null;
  verified_name: string | null;
  quality_rating: "GREEN" | "YELLOW" | "RED" | "UNKNOWN";
  onboarding_status:
    | "pending"
    | "code_requested"
    | "verified"
    | "active"
    | "failed"
    | "suspended";
  is_active: boolean;
  registered_at: string | null;
}

interface Health {
  quality_rating: { value: string; color: string; label: string };
  name_status: { value: string; color: string; label: string };
  status: { value: string; color: string };
  messaging_limit_tier: string | null;
  throughput: Record<string, unknown> | null;
}

const emit = defineEmits<{ registered: [WhatsappAccount] }>();

const props = defineProps<{
  /** Existing registered accounts to show in the health panel. */
  accounts?: WhatsappAccount[];
}>();

// ── Step tracking ──────────────────────────────────────────────────────────

type Step = "details" | "code" | "pin" | "done";
const step = ref<Step>("details");
const saving = ref(false);
const errors = ref<Record<string, string>>({});

// Shared across steps — populated progressively as steps succeed.
const accountId = ref<number | null>(null);

// ── Step 1: number details ─────────────────────────────────────────────────

const details = ref({
  country_code: "265",
  local_number: "",
  display_name: "",
  migrate: false,
});

async function submitDetails() {
  errors.value = {};
  if (!details.value.local_number) errors.value.local_number = "Required.";
  if (!details.value.display_name) errors.value.display_name = "Required.";
  if (Object.keys(errors.value).length) return;

  saving.value = true;
  try {
    const { data } = await axios.post(
      "/tenant/whatsapp/register/add-number",
      details.value,
    );
    accountId.value = data.account.id;
    step.value = "code";
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

// ── Step 2: request + submit OTP ──────────────────────────────────────────

const codeMethod = ref<"sms" | "voice">("sms");
const language = ref("en_US");
const otp = ref("");
const codeSent = ref(false);
const resending = ref(false);

async function requestCode() {
  if (!accountId.value) return;
  resending.value = true;
  errors.value = {};
  try {
    await axios.post("/tenant/whatsapp/register/request-code", {
      whatsapp_account_id: accountId.value,
      method: codeMethod.value,
      language: language.value,
    });
    codeSent.value = true;
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    resending.value = false;
  }
}

async function submitCode() {
  errors.value = {};
  if (otp.value.length !== 6) {
    errors.value.code = "Enter the full 6-digit code.";
    return;
  }
  saving.value = true;
  try {
    await axios.post("/tenant/whatsapp/register/verify-code", {
      whatsapp_account_id: accountId.value,
      code: otp.value,
    });
    step.value = "pin";
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

// ── Step 3: set 2FA PIN + complete ─────────────────────────────────────────

const pin = ref("");
const pinConfirm = ref("");

const pinMismatch = computed(
  () => pin.value && pinConfirm.value && pin.value !== pinConfirm.value,
);

async function completeRegistration() {
  errors.value = {};
  if (pin.value.length !== 6) {
    errors.value.pin = "PIN must be exactly 6 digits.";
    return;
  }
  if (pin.value !== pinConfirm.value) {
    errors.value.pin = "PINs don't match.";
    return;
  }

  saving.value = true;
  try {
    const { data } = await axios.post("/tenant/whatsapp/register/complete", {
      whatsapp_account_id: accountId.value,
      pin: pin.value,
    });
    step.value = "done";
    emit("registered", data.account);
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

// ── Account health panel ───────────────────────────────────────────────────

const healthData = ref<Record<number, { health: Health; loading: boolean }>>(
  {},
);

async function loadHealth(account: WhatsappAccount) {
  healthData.value[account.id] = { health: null as any, loading: true };
  try {
    const { data } = await axios.get(
      `/tenant/whatsapp/accounts/${account.id}/health`,
    );
    healthData.value[account.id] = { health: data.health, loading: false };
  } catch {
    healthData.value[account.id].loading = false;
  }
}

async function syncAccount(account: WhatsappAccount) {
  healthData.value[account.id] = {
    ...(healthData.value[account.id] ?? {}),
    loading: true,
  };
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp/accounts/${account.id}/sync`,
    );
    healthData.value[account.id] = { health: data.health, loading: false };
    Swal.fire({
      title: "Synced",
      icon: "success",
      timer: 1200,
      showConfirmButton: false,
    });
  } catch (e: any) {
    healthData.value[account.id].loading = false;
    Swal.fire({
      title: "Sync failed",
      text: e.response?.data?.message,
      icon: "error",
    });
  }
}

// ── Reset ──────────────────────────────────────────────────────────────────

function startOver() {
  step.value = "details";
  accountId.value = null;
  codeSent.value = false;
  otp.value = "";
  pin.value = "";
  pinConfirm.value = "";
  errors.value = {};
  details.value = {
    country_code: "265",
    local_number: "",
    display_name: "",
    migrate: false,
  };
}

// ── Error helper ───────────────────────────────────────────────────────────

function handleAxiosError(e: any) {
  const serverErrors = e.response?.data?.errors;
  if (serverErrors) {
    errors.value = Object.fromEntries(
      Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
    );
  }
  Swal.fire({
    title: "Error",
    text:
      e.response?.data?.message ?? "Something went wrong. Please try again.",
    icon: "error",
  });
}

const qualityColor: Record<string, string> = {
  GREEN: "success",
  YELLOW: "warning",
  RED: "error",
  UNKNOWN: "grey",
};
const statusColors: Record<string, string> = {
  active: "success",
  pending: "warning",
  code_requested: "info",
  verified: "info",
  failed: "error",
  suspended: "error",
};
</script>

<template>
  <div>
    <!-- ── Account health list (existing numbers) ─────────────────────── -->
    <template v-if="accounts?.length">
      <p class="text-subtitle-1 font-weight-bold mb-3">Registered Numbers</p>
      <VCard
        v-for="account in accounts"
        :key="account.id"
        variant="outlined"
        class="mb-3"
      >
        <VCardText>
          <div
            class="d-flex align-center justify-space-between flex-wrap gap-2"
          >
            <div>
              <p class="text-body-1 font-weight-medium mb-0">
                {{ account.display_phone_number ?? account.phone_number }}
              </p>
              <p class="text-caption text-medium-emphasis mb-0">
                {{ account.verified_name }}
              </p>
            </div>
            <div class="d-flex align-center gap-2">
              <VChip
                size="small"
                :color="statusColors[account.onboarding_status] ?? 'default'"
                variant="tonal"
              >
                {{ account.onboarding_status.replace("_", " ") }}
              </VChip>
              <VChip
                v-if="account.quality_rating !== 'UNKNOWN'"
                size="small"
                :color="qualityColor[account.quality_rating]"
                variant="tonal"
              >
                {{ account.quality_rating }}
              </VChip>
              <VBtn
                size="x-small"
                variant="text"
                prepend-icon="$refresh"
                :loading="healthData[account.id]?.loading"
                @click="
                  !healthData[account.id]
                    ? loadHealth(account)
                    : syncAccount(account)
                "
              >
                {{ healthData[account.id] ? "Sync" : "Load health" }}
              </VBtn>
            </div>
          </div>

          <!-- Health detail panel — shown after first load -->
          <template v-if="healthData[account.id]?.health">
            <VDivider class="my-3" />
            <div class="d-flex flex-wrap gap-4">
              <div>
                <p class="text-caption text-medium-emphasis mb-1">Quality</p>
                <VChip
                  size="small"
                  :color="healthData[account.id].health.quality_rating.color"
                  variant="tonal"
                >
                  {{ healthData[account.id].health.quality_rating.label }}
                </VChip>
              </div>
              <div>
                <p class="text-caption text-medium-emphasis mb-1">
                  Display name
                </p>
                <VChip
                  size="small"
                  :color="healthData[account.id].health.name_status.color"
                  variant="tonal"
                >
                  {{ healthData[account.id].health.name_status.label }}
                </VChip>
              </div>
              <div>
                <p class="text-caption text-medium-emphasis mb-1">Status</p>
                <VChip
                  size="small"
                  :color="healthData[account.id].health.status.color"
                  variant="tonal"
                >
                  {{ healthData[account.id].health.status.value }}
                </VChip>
              </div>
              <div v-if="healthData[account.id].health.messaging_limit_tier">
                <p class="text-caption text-medium-emphasis mb-1">
                  Messaging tier
                </p>
                <p class="text-body-2">
                  {{ healthData[account.id].health.messaging_limit_tier }}
                </p>
              </div>
            </div>
          </template>
        </VCardText>
      </VCard>
      <VDivider class="my-5" />
    </template>

    <!-- ── Registration form ──────────────────────────────────────────── -->
    <p class="text-subtitle-1 font-weight-bold mb-1">Register a new number</p>
    <p class="text-body-2 text-medium-emphasis mb-4">
      The number must be reachable by SMS or voice call to receive the
      verification code. It cannot already be on the regular WhatsApp or
      WhatsApp Business consumer app.
    </p>

    <!-- Step indicator -->
    <div class="d-flex align-center gap-1 mb-5">
      <template
        v-for="(label, i) in ['Number details', 'Verify number', 'Activate']"
        :key="i"
      >
        <div class="d-flex align-center gap-1">
          <div
            class="step-dot"
            :class="{
              'step-dot--done':
                (['details', 'code', 'pin', 'done'] as Step[]).indexOf(step) >
                i,
              'step-dot--active':
                (['details', 'code', 'pin', 'done'] as Step[]).indexOf(step) ===
                i,
            }"
          >
            <VIcon
              v-if="
                (['details', 'code', 'pin', 'done'] as Step[]).indexOf(step) > i
              "
              size="12"
              icon="$check"
            />
            <span v-else>{{ i + 1 }}</span>
          </div>
          <span
            class="text-caption"
            :class="
              (['details', 'code', 'pin', 'done'] as Step[]).indexOf(step) >= i
                ? 'text-primary font-weight-medium'
                : 'text-medium-emphasis'
            "
            >{{ label }}</span
          >
        </div>
        <div v-if="i < 2" class="step-connector" />
      </template>
    </div>

    <!-- ── STEP 1: Number details ─────────────────────────────────────── -->
    <template v-if="step === 'details'">
      <VAlert type="info" variant="tonal" density="compact" class="mb-4">
        Enter the customer's WhatsApp Business number. We'll send a verification
        code to the phone before activating it.
      </VAlert>

      <div class="d-flex gap-3 mb-3">
        <VTextField
          v-model="details.country_code"
          label="Country code"
          prefix="+"
          hint="e.g. 265"
          persistent-hint
          variant="outlined"
          density="comfortable"
          style="max-width: 120px"
          :error-messages="errors.country_code"
        />
        <VTextField
          v-model="details.local_number"
          label="Phone number"
          placeholder="884 123 456"
          hint="Local number without the country code"
          persistent-hint
          variant="outlined"
          density="comfortable"
          class="flex-grow-1"
          :error-messages="errors.local_number"
        />
      </div>

      <VTextField
        v-model="details.display_name"
        label="Display / business name"
        placeholder="NICO Group"
        hint="Shown to customers in WhatsApp. Goes through Meta's name review."
        persistent-hint
        variant="outlined"
        density="comfortable"
        class="mb-3"
        :error-messages="errors.display_name"
      />

      <VCheckbox
        v-model="details.migrate"
        label="Migrate number from WhatsApp consumer app"
        density="compact"
        hint="Only check if the number has an active personal WhatsApp account the customer wants to move over."
        persistent-hint
        class="mb-4"
      />

      <VBtn
        color="primary"
        :loading="saving"
        prepend-icon="$arrowRight"
        @click="submitDetails"
      >
        Continue
      </VBtn>
    </template>

    <!-- ── STEP 2: Verify OTP ─────────────────────────────────────────── -->
    <template v-else-if="step === 'code'">
      <VAlert type="info" variant="tonal" density="compact" class="mb-4">
        Choose how to receive the code, then send it to the phone. Enter the
        6-digit code once it arrives.
      </VAlert>

      <VRadioGroup v-model="codeMethod" inline class="mb-3">
        <VRadio value="sms" label="SMS" />
        <VRadio value="voice" label="Voice call" />
      </VRadioGroup>

      <VSelect
        v-model="language"
        label="Code language"
        :items="[
          { title: 'English (US)', value: 'en_US' },
          { title: 'English (UK)', value: 'en_GB' },
          { title: 'French', value: 'fr' },
          { title: 'Portuguese', value: 'pt_BR' },
          { title: 'Spanish', value: 'es' },
        ]"
        variant="outlined"
        density="comfortable"
        class="mb-4"
        style="max-width: 280px"
      />

      <VBtn
        :color="codeSent ? 'secondary' : 'primary'"
        :loading="resending"
        :variant="codeSent ? 'outlined' : 'flat'"
        prepend-icon="$send"
        class="mb-4"
        @click="requestCode"
      >
        {{ codeSent ? "Resend code" : "Send code" }}
      </VBtn>

      <template v-if="codeSent">
        <VTextField
          v-model="otp"
          label="Verification code"
          placeholder="123456"
          maxlength="6"
          hint="The 6-digit code sent to the phone"
          persistent-hint
          variant="outlined"
          density="comfortable"
          class="mb-4"
          style="max-width: 220px"
          :error-messages="errors.code"
        />
        <div class="d-flex gap-2">
          <VBtn variant="text" @click="step = 'details'">Back</VBtn>
          <VBtn
            color="primary"
            :loading="saving"
            prepend-icon="$check"
            @click="submitCode"
          >
            Verify code
          </VBtn>
        </div>
      </template>
    </template>

    <!-- ── STEP 3: Set 2FA PIN ────────────────────────────────────────── -->
    <template v-else-if="step === 'pin'">
      <VAlert type="success" variant="tonal" density="compact" class="mb-4">
        Number verified! Set a 6-digit 2FA PIN to activate it on the WhatsApp
        Cloud API. Store this securely — it's needed for future number
        management.
      </VAlert>

      <VTextField
        v-model="pin"
        label="2FA PIN"
        placeholder="••••••"
        type="password"
        maxlength="6"
        hint="6 digits — used by Meta for number management operations"
        persistent-hint
        variant="outlined"
        density="comfortable"
        class="mb-3"
        style="max-width: 220px"
        :error-messages="errors.pin"
      />

      <VTextField
        v-model="pinConfirm"
        label="Confirm PIN"
        placeholder="••••••"
        type="password"
        maxlength="6"
        variant="outlined"
        density="comfortable"
        class="mb-4"
        style="max-width: 220px"
        :error-messages="pinMismatch ? ['PINs don\'t match'] : []"
      />

      <div class="d-flex gap-2">
        <VBtn variant="text" @click="step = 'code'">Back</VBtn>
        <VBtn
          color="primary"
          :loading="saving"
          :disabled="!!pinMismatch"
          prepend-icon="$check"
          @click="completeRegistration"
        >
          Activate number
        </VBtn>
      </div>
    </template>

    <!-- ── DONE ───────────────────────────────────────────────────────── -->
    <template v-else-if="step === 'done'">
      <VAlert type="success" variant="tonal" class="mb-4">
        <p class="font-weight-medium mb-1">Number registered successfully</p>
        <p class="text-body-2 mb-0">
          The number is now live on the WhatsApp Cloud API. Display name
          approval may take a few hours — messages can still be sent in the
          meantime.
        </p>
      </VAlert>
      <VBtn variant="outlined" prepend-icon="$plus" @click="startOver">
        Register another number
      </VBtn>
    </template>
  </div>
</template>

<style scoped>
.step-dot {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  border: 2px solid rgba(var(--v-theme-on-surface), 0.3);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
  transition:
    background 0.2s,
    border-color 0.2s;
}
.step-dot--active {
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
}
.step-dot--done {
  background: rgb(var(--v-theme-primary));
  border-color: rgb(var(--v-theme-primary));
  color: #fff;
}
.step-connector {
  flex: 1;
  height: 1px;
  background: rgba(var(--v-theme-on-surface), 0.15);
  min-width: 20px;
}
</style>
