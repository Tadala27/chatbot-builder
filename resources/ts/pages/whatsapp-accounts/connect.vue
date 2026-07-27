<script setup lang="ts">
import { computed, ref, watch } from "vue";
import axios from "axios";

declare global {
  interface Window {
    FB: any;
    fbAsyncInit: () => void;
  }
}

const APP_ID = import.meta.env.VITE_META_APP_ID;
const CONFIG_ID = import.meta.env.VITE_META_ES_CONFIG_ID;

const props = defineProps<{
  modelValue: boolean;
  sdkReady: boolean;
  /**
   * Pass this to skip the method picker and jump straight to the mode
   * selection step for an account that completed OAuth but hasn't had
   * managed_bot / connector chosen yet.
   */
  setupAccount?: { id: string; phone: string } | null;
}>();

const emit = defineEmits<{
  (e: "update:modelValue", v: boolean): void;
  (e: "connected"): void;
}>();

type Method = "facebook" | "manual" | null;
type ManualStep = "details" | "code" | "pin" | "done";

const method = ref<Method>(null);
const fbStep = ref<"idle" | "signing_up" | "choosing_mode">("idle");
const manualStep = ref<ManualStep>("details");
const saving = ref(false);
const errors = ref<Record<string, string>>({});

// ── Snackbar ──────────────────────────────────────────────────────────────────

const snackbar = ref({
  show: false,
  text: "",
  color: "success" as "success" | "error" | "info",
});

function showSnackbar(
  text: string,
  color: "success" | "error" | "info" = "success",
) {
  snackbar.value = { show: true, text, color };
}

// ── API key dialog ─────────────────────────────────────────────────────────────

const apiKeyDialog = ref({ show: false, key: "" });

// ── Facebook / setup path state ───────────────────────────────────────────────

const pendingAccountId = ref<string | null>(null);
const pendingPhone = ref<string | null>(null);
const selectedMode = ref<"managed_bot" | "connector" | null>(null);
const webhookUrl = ref("");

let sessionPayload: {
  waba_id?: string;
  phone_number_id?: string;
  business_id?: string;
} = {};

// Whether this dialog is completing an existing account rather than onboarding a new one
const isCompletingSetup = computed(() => !!props.setupAccount);

// Subtitle text for the dialog header
const headerSubtitle = computed(() => {
  if (isCompletingSetup.value) return "Complete account setup";
  if (method.value === null)
    return "Choose how you want to connect your number";
  if (method.value === "facebook") {
    if (fbStep.value === "choosing_mode")
      return "How will you use this number?";
    if (fbStep.value === "signing_up") return "Completing Meta signup…";
    return "Continue with Meta";
  }
  if (manualStep.value === "done") return "Number activated";
  return (
    (["Number details", "Verify number", "Set PIN"] as const)[
      manualStepIndex.value
    ] ?? ""
  );
});

function launchFacebook() {
  if (!props.sdkReady || !window.FB) {
    showSnackbar(
      "SDK still loading — please wait a moment and try again.",
      "info",
    );
    return;
  }
  fbStep.value = "signing_up";
  window.FB.login(
    (response: any) => {
      handleFbResponse(response);
    },
    {
      config_id: CONFIG_ID,
      response_type: "code",
      override_default_response_type: true,
      extras: { setup: {} },
    },
  );
}

const handleFbResponse = async (response: any) => {
  if (!response.authResponse?.code) {
    fbStep.value = "idle";
    return;
  }
  if (!sessionPayload.waba_id || !sessionPayload.phone_number_id) {
    fbStep.value = "idle";
    showSnackbar(
      "We didn't receive your WhatsApp account details from Meta. Please try again.",
      "error",
    );
    return;
  }
  try {
    const { data } = await axios.post(
      "/tenant/whatsapp-accounts/embedded-signup/callback",
      {
        code: response.authResponse.code,
        ...sessionPayload,
      },
    );
    pendingAccountId.value = data.account.id;
    pendingPhone.value =
      data.account.display_phone_number ?? data.account.phone_number;
    fbStep.value = "choosing_mode";
  } catch (e: any) {
    fbStep.value = "idle";
    showSnackbar(
      e.response?.data?.message ?? "Something went wrong. Please try again.",
      "error",
    );
  }
};

const confirmMode = async () => {
  if (!selectedMode.value || !pendingAccountId.value) return;
  if (selectedMode.value === "connector" && !webhookUrl.value.trim()) {
    errors.value.webhook_url = "A webhook URL is required for connector mode.";
    return;
  }
  saving.value = true;
  errors.value = {};
  try {
    const { data } = await axios.post(
      `/tenant/whatsapp-accounts/${pendingAccountId.value}/choose-mode`,
      { mode: selectedMode.value, webhook_url: webhookUrl.value || undefined },
    );
    if (data.connector_api_key) {
      apiKeyDialog.value = { show: true, key: data.connector_api_key };
    } else {
      showSnackbar("Account connected successfully.");
    }
    emit("connected");
    close();
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Failed to set mode.", "error");
  } finally {
    saving.value = false;
  }
};

// ── Manual path state ─────────────────────────────────────────────────────────

const details = ref({
  country_code: "265",
  local_number: "",
  display_name: "",
  migrate: false,
});
const codeMethod = ref<"sms" | "voice">("sms");
const language = ref("en_US");
const otp = ref("");
const pin = ref("");
const pinConfirm = ref("");
const codeSent = ref(false);
const resending = ref(false);
const manualAccountId = ref<number | null>(null);

const pinMismatch = computed(
  () => pin.value && pinConfirm.value && pin.value !== pinConfirm.value,
);
const manualStepIndex = computed(() =>
  (["details", "code", "pin", "done"] as ManualStep[]).indexOf(
    manualStep.value,
  ),
);

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
    manualAccountId.value = data.account.id;
    manualStep.value = "code";
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

async function requestCode() {
  if (!manualAccountId.value) return;
  resending.value = true;
  errors.value = {};
  try {
    await axios.post("/tenant/whatsapp/register/request-code", {
      whatsapp_account_id: manualAccountId.value,
      method: codeMethod.value,
      language: language.value,
    });
    codeSent.value = true;
    showSnackbar("Verification code sent.");
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
      whatsapp_account_id: manualAccountId.value,
      code: otp.value,
    });
    manualStep.value = "pin";
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

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
    await axios.post("/tenant/whatsapp/register/complete", {
      whatsapp_account_id: manualAccountId.value,
      pin: pin.value,
    });
    manualStep.value = "done";
    emit("connected");
  } catch (e: any) {
    handleAxiosError(e);
  } finally {
    saving.value = false;
  }
}

function handleAxiosError(e: any) {
  const serverErrors = e.response?.data?.errors;
  if (serverErrors) {
    errors.value = Object.fromEntries(
      Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
    );
  }
  showSnackbar(
    e.response?.data?.message ?? "Something went wrong. Please try again.",
    "error",
  );
}

// ── Exposed to parent ─────────────────────────────────────────────────────────

function registerSessionPayload(payload: typeof sessionPayload) {
  sessionPayload = payload;
}

defineExpose({ registerSessionPayload });

// ── Dialog open/close lifecycle ───────────────────────────────────────────────

function close() {
  emit("update:modelValue", false);
}

function closeApiKeyDialog() {
  apiKeyDialog.value = { show: false, key: "" };
  showSnackbar("Account connected successfully.");
}

async function copyToClipboard(text: string | null): Promise<void> {
  if (!text) return;

  if (navigator.clipboard?.writeText) {
    await navigator.clipboard.writeText(text);
  } else {
    // Fallback for HTTP / non-secure contexts
    const el = document.createElement("textarea");
    el.value = text;
    el.style.position = "fixed";
    el.style.opacity = "0";
    document.body.appendChild(el);
    el.focus();
    el.select();
    document.execCommand("copy");
    document.body.removeChild(el);
  }

  showSnackbar("Copied to clipboard.");
}

// When the dialog opens, check if we're completing an existing account's setup.
// If so, jump straight to the mode picker screen with the account pre-filled.
watch(
  () => props.modelValue,
  (open) => {
    if (open && props.setupAccount) {
      pendingAccountId.value = props.setupAccount.id;
      pendingPhone.value = props.setupAccount.phone;
      method.value = "facebook";
      fbStep.value = "choosing_mode";
    }

    if (!open) {
      setTimeout(resetState, 300);
    }
  },
);

// Also react if setupAccount changes while dialog is already open
watch(
  () => props.setupAccount,
  (account) => {
    if (props.modelValue && account) {
      pendingAccountId.value = account.id;
      pendingPhone.value = account.phone;
      method.value = "facebook";
      fbStep.value = "choosing_mode";
    }
  },
);

function resetState() {
  method.value = null;
  fbStep.value = "idle";
  manualStep.value = "details";
  saving.value = false;
  errors.value = {};
  pendingAccountId.value = null;
  pendingPhone.value = null;
  selectedMode.value = null;
  webhookUrl.value = "";
  otp.value = "";
  pin.value = "";
  pinConfirm.value = "";
  codeSent.value = false;
  manualAccountId.value = null;
  details.value = {
    country_code: "265",
    local_number: "",
    display_name: "",
    migrate: false,
  };
  sessionPayload = {};
}
</script>

<template>
  <VDialog
    :model-value="modelValue"
    max-width="600"
    :persistent="method !== null"
    @update:model-value="close"
  >
    <VCard class="connect-card">
      <!-- Header -->
      <div class="connect-header">
        <div class="connect-header__icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none">
            <path
              d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"
              fill="currentColor"
            />
            <path
              d="M12 2C6.477 2 2 6.477 2 12c0 1.89.522 3.66 1.438 5.168L2 22l4.832-1.438A9.955 9.955 0 0012 22c5.523 0 10-4.477 10-10S17.523 2 12 2z"
              stroke="currentColor"
              stroke-width="1.5"
              fill="none"
            />
          </svg>
        </div>
        <div>
          <h2 class="connect-header__title">
            {{
              isCompletingSetup ? "Complete setup" : "Connect a WhatsApp number"
            }}
          </h2>
          <p class="connect-header__sub">{{ headerSubtitle }}</p>
        </div>
        <button
          v-if="method === null || manualStep === 'done' || fbStep === 'idle'"
          class="connect-close"
          type="button"
          @click="close"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
            <path
              d="M18 6L6 18M6 6l12 12"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
            />
          </svg>
        </button>
      </div>

      <VDivider />

      <div class="connect-body">
        <!-- ══ STEP 0: Method picker (only shown for new connections) ══ -->
        <template v-if="method === null">
          <div class="method-grid">
            <button
              type="button"
              class="method-card"
              @click="method = 'facebook'"
            >
              <div class="method-card__badge">
                <span class="badge-dot" />
                Recommended
              </div>
              <div class="method-card__icon method-card__icon--blue">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                  <path
                    d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"
                    fill="currentColor"
                  />
                </svg>
              </div>
              <h3 class="method-card__title">Meta Business</h3>
              <p class="method-card__desc">
                Connect through Meta's OAuth flow. Faster setup, no OTP needed.
              </p>
              <div class="method-card__footer">Continue with Meta →</div>
            </button>

            <button
              type="button"
              class="method-card"
              @click="method = 'manual'"
            >
              <div class="method-card__icon method-card__icon--green">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none">
                  <rect
                    x="5"
                    y="2"
                    width="14"
                    height="20"
                    rx="2"
                    stroke="currentColor"
                    stroke-width="1.8"
                  />
                  <path
                    d="M12 18h.01"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                  />
                  <path
                    d="M9 6h6M9 10h4"
                    stroke="currentColor"
                    stroke-width="1.5"
                    stroke-linecap="round"
                  />
                </svg>
              </div>
              <h3 class="method-card__title">Manual setup</h3>
              <p class="method-card__desc">
                Register using SMS or voice verification. For numbers not yet on
                WhatsApp.
              </p>
              <div class="method-card__footer">Set up manually →</div>
            </button>
          </div>
          <p class="method-note">
            Both methods register your number on the WhatsApp Cloud API. A Meta
            Business account and payment method are required to send messages.
          </p>
        </template>

        <!-- ══ FACEBOOK PATH ══ -->
        <template v-else-if="method === 'facebook'">
          <!-- Idle -->
          <template v-if="fbStep === 'idle'">
            <div class="fb-intro__steps mb-4">
              <div
                v-for="(step, i) in [
                  'Meta opens a secure login window',
                  'Select your WhatsApp Business Account',
                  'Choose how you\'ll use the number here',
                ]"
                :key="i"
                class="fb-step"
              >
                <span class="fb-step__num">{{ i + 1 }}</span>
                <span>{{ step }}</span>
              </div>
            </div>
            <VAlert type="info" variant="tonal" density="compact" class="mb-4">
              Make sure pop-ups are allowed for this site.
            </VAlert>
            <div class="connect-actions">
              <VBtn variant="text" @click="method = null">Back</VBtn>
              <VBtn
                color="primary"
                variant="flat"
                prepend-icon="$openInNew"
                :disabled="!sdkReady"
                :loading="!sdkReady"
                @click="launchFacebook"
              >
                Open Meta signup
              </VBtn>
            </div>
          </template>

          <!-- Signing up -->
          <template v-else-if="fbStep === 'signing_up'">
            <div class="fb-waiting">
              <VProgressCircular
                indeterminate
                color="primary"
                size="40"
                class="mb-4"
              />
              <p class="fb-waiting__text">Waiting for Meta to complete…</p>
              <p class="fb-waiting__sub">
                Finish the steps in the Meta pop-up, then return here.
              </p>
            </div>
          </template>

          <!-- ══ MODE PICKER — shared by both new accounts and complete-setup ══ -->
          <template v-else-if="fbStep === 'choosing_mode'">
            <VAlert
              type="success"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              <strong>{{ pendingPhone }}</strong>
              {{
                isCompletingSetup
                  ? " — choose how to use this number."
                  : " registered. Now choose how to use it."
              }}
            </VAlert>

            <div class="mode-options mb-1">
              <!-- Managed bot option -->
              <button
                type="button"
                class="mode-option"
                :class="{
                  'mode-option--selected': selectedMode === 'managed_bot',
                }"
                @click="
                  selectedMode = 'managed_bot';
                  webhookUrl = '';
                "
              >
                <div class="mode-option__icon">
                  <VIcon icon="$robot" size="22" />
                </div>
                <div class="mode-option__content">
                  <p class="mode-option__title">Build a bot here</p>
                  <p class="mode-option__desc">
                    Use the bot builder in this dashboard to create automated
                    flows and handle conversations.
                  </p>
                </div>
                <VIcon
                  v-if="selectedMode === 'managed_bot'"
                  icon="$checkCircle"
                  color="primary"
                  size="20"
                  class="mode-option__check"
                />
              </button>

              <!-- Connector option -->
              <button
                type="button"
                class="mode-option"
                :class="{
                  'mode-option--selected': selectedMode === 'connector',
                }"
                @click="selectedMode = 'connector'"
              >
                <div class="mode-option__icon">
                  <VIcon icon="$webhook" size="22" />
                </div>
                <div class="mode-option__content">
                  <p class="mode-option__title">Connect my own system</p>
                  <p class="mode-option__desc">
                    Forward messages to your own chatbot or backend via webhook.
                    You'll receive an API key to authenticate requests.
                  </p>
                </div>
                <VIcon
                  v-if="selectedMode === 'connector'"
                  icon="$checkCircle"
                  color="primary"
                  size="20"
                  class="mode-option__check"
                />
              </button>
            </div>

            <!-- Connector webhook URL field — slides in when connector is selected -->
            <Transition name="expand">
              <div v-if="selectedMode === 'connector'" class="connector-fields">
                <VTextField
                  v-model="webhookUrl"
                  label="Webhook URL"
                  placeholder="https://your-system.com/webhooks/whatsapp"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="errors.webhook_url"
                  hint="We'll POST every inbound WhatsApp message to this URL as Meta's raw JSON."
                  persistent-hint
                />
              </div>
            </Transition>

            <div class="connect-actions mt-4">
              <VBtn
                v-if="!isCompletingSetup"
                variant="text"
                @click="
                  fbStep = 'idle';
                  selectedMode = null;
                "
                >Back</VBtn
              >
              <VBtn v-else variant="text" @click="close">Cancel</VBtn>
              <VBtn
                color="primary"
                variant="flat"
                :disabled="!selectedMode"
                :loading="saving"
                @click="confirmMode"
              >
                {{
                  selectedMode === "connector"
                    ? "Set up connector"
                    : "Start building"
                }}
              </VBtn>
            </div>
          </template>
        </template>

        <!-- ══ MANUAL PATH ══ -->
        <template v-else-if="method === 'manual'">
          <!-- Step progress -->
          <div v-if="manualStep !== 'done'" class="manual-steps">
            <template
              v-for="(label, i) in ['Number', 'Verify', 'PIN']"
              :key="i"
            >
              <div
                class="manual-step"
                :class="{
                  'manual-step--done': manualStepIndex > i,
                  'manual-step--active': manualStepIndex === i,
                }"
              >
                <div class="manual-step__dot">
                  <svg
                    v-if="manualStepIndex > i"
                    width="10"
                    height="10"
                    viewBox="0 0 24 24"
                    fill="none"
                  >
                    <path
                      d="M5 13l4 4L19 7"
                      stroke="currentColor"
                      stroke-width="3"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                    />
                  </svg>
                  <span v-else>{{ i + 1 }}</span>
                </div>
                <span class="manual-step__label">{{ label }}</span>
              </div>
              <div
                v-if="i < 2"
                class="manual-step__line"
                :class="{ 'manual-step__line--done': manualStepIndex > i }"
              />
            </template>
          </div>

          <!-- Step 1: Details -->
          <template v-if="manualStep === 'details'">
            <VAlert type="info" variant="tonal" density="compact" class="mb-4">
              The number must be reachable by SMS or voice call and cannot
              already be active on WhatsApp.
            </VAlert>
            <div class="d-flex gap-3 mb-3">
              <VTextField
                v-model="details.country_code"
                label="Country code"
                prefix="+"
                variant="outlined"
                density="comfortable"
                style="max-width: 110px"
                :error-messages="errors.country_code"
              />
              <VTextField
                v-model="details.local_number"
                label="Phone number"
                placeholder="884 123 456"
                variant="outlined"
                density="comfortable"
                class="flex-grow-1"
                :error-messages="errors.local_number"
              />
            </div>
            <VTextField
              v-model="details.display_name"
              label="Business display name"
              placeholder="NICO Group"
              hint="Shown to customers. Goes through Meta's name review."
              persistent-hint
              variant="outlined"
              density="comfortable"
              class="mb-3"
              :error-messages="errors.display_name"
            />
            <VCheckbox
              v-model="details.migrate"
              label="Migrate from WhatsApp consumer app"
              density="compact"
              hint="Only check if moving an existing personal WhatsApp number."
              persistent-hint
              class="mb-2"
            />
            <div class="connect-actions">
              <VBtn variant="text" @click="method = null">Back</VBtn>
              <VBtn
                color="primary"
                variant="flat"
                :loading="saving"
                @click="submitDetails"
                >Continue</VBtn
              >
            </div>
          </template>

          <!-- Step 2: Verify OTP -->
          <template v-else-if="manualStep === 'code'">
            <p class="text-body-2 text-medium-emphasis mb-4">
              Send a verification code to the phone, then enter it below.
            </p>
            <div class="d-flex gap-3 mb-3 flex-wrap">
              <VBtnToggle
                v-model="codeMethod"
                mandatory
                density="comfortable"
                rounded="lg"
              >
                <VBtn value="sms" size="small">SMS</VBtn>
                <VBtn value="voice" size="small">Voice call</VBtn>
              </VBtnToggle>
              <VSelect
                v-model="language"
                :items="[
                  { title: 'English (US)', value: 'en_US' },
                  { title: 'English (UK)', value: 'en_GB' },
                  { title: 'French', value: 'fr' },
                  { title: 'Portuguese', value: 'pt_BR' },
                ]"
                variant="outlined"
                density="compact"
                hide-details
                style="max-width: 160px"
              />
            </div>
            <VBtn
              :color="codeSent ? 'default' : 'primary'"
              :variant="codeSent ? 'outlined' : 'flat'"
              :loading="resending"
              size="small"
              class="mb-4"
              @click="requestCode"
            >
              {{ codeSent ? "Resend code" : "Send code" }}
            </VBtn>
            <Transition name="expand">
              <VTextField
                v-if="codeSent"
                v-model="otp"
                label="6-digit code"
                placeholder="123456"
                maxlength="6"
                variant="outlined"
                density="comfortable"
                class="mb-1"
                style="max-width: 200px"
                :error-messages="errors.code"
              />
            </Transition>
            <div class="connect-actions">
              <VBtn variant="text" @click="manualStep = 'details'">Back</VBtn>
              <VBtn
                v-if="codeSent"
                color="primary"
                variant="flat"
                :loading="saving"
                @click="submitCode"
                >Verify</VBtn
              >
            </div>
          </template>

          <!-- Step 3: PIN -->
          <template v-else-if="manualStep === 'pin'">
            <VAlert
              type="success"
              variant="tonal"
              density="compact"
              class="mb-4"
              >Number verified. Set a 6-digit 2FA PIN to activate it.</VAlert
            >
            <VTextField
              v-model="pin"
              label="2FA PIN"
              type="password"
              placeholder="••••••"
              maxlength="6"
              hint="6 digits — needed for future number management with Meta."
              persistent-hint
              variant="outlined"
              density="comfortable"
              class="mb-3"
              style="max-width: 200px"
              :error-messages="errors.pin"
            />
            <VTextField
              v-model="pinConfirm"
              label="Confirm PIN"
              type="password"
              placeholder="••••••"
              maxlength="6"
              variant="outlined"
              density="comfortable"
              class="mb-3"
              style="max-width: 200px"
              :error-messages="pinMismatch ? [`PINs don't match`] : []"
            />
            <div class="connect-actions">
              <VBtn variant="text" @click="manualStep = 'code'">Back</VBtn>
              <VBtn
                color="primary"
                variant="flat"
                :disabled="!!pinMismatch"
                :loading="saving"
                @click="completeRegistration"
                >Activate number</VBtn
              >
            </div>
          </template>

          <!-- Done -->
          <template v-else-if="manualStep === 'done'">
            <div class="done-state">
              <div class="done-state__icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none">
                  <path
                    d="M20 6L9 17l-5-5"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  />
                </svg>
              </div>
              <h3 class="done-state__title">Number activated</h3>
              <p class="done-state__desc">
                Your number is live on the WhatsApp Cloud API. Display name
                approval may take a few hours.
              </p>
              <VBtn color="primary" variant="flat" @click="close">Done</VBtn>
            </div>
          </template>
        </template>
      </div>
    </VCard>
  </VDialog>

  <!-- One-time connector API key dialog -->
  <VDialog v-model="apiKeyDialog.show" max-width="480" persistent>
    <VCard rounded="lg" class="pa-6">
      <div class="d-flex align-center gap-2 mb-3">
        <VIcon icon="$checkCircle" color="success" size="22" />
        <h3 class="text-h6 font-weight-bold mb-0">Connector API key</h3>
      </div>
      <p class="text-body-2 text-medium-emphasis mb-3">
        Save this now — it won't be shown again.
      </p>
      <div class="api-key-box mb-4">{{ apiKeyDialog.key }}</div>
      <VAlert type="info" variant="tonal" density="compact" class="mb-4">
        Include this key as the <code>X-Connector-Key</code> header on all
        requests to the send endpoint.
      </VAlert>
      <div class="d-flex justify-end gap-2">
        <VBtn
          variant="tonal"
          prepend-icon="$contentCopy"
          @click="copyToClipboard(apiKeyDialog.key)"
          >Copy key</VBtn
        >
        <VBtn color="primary" variant="flat" @click="closeApiKeyDialog"
          >Done</VBtn
        >
      </div>
    </VCard>
  </VDialog>

  <VSnackbar
    v-model="snackbar.show"
    :color="snackbar.color"
    timeout="4000"
    location="top right"
  >
    {{ snackbar.text }}
    <template #actions>
      <VBtn
        variant="text"
        size="small"
        icon="$close"
        @click="snackbar.show = false"
      />
    </template>
  </VSnackbar>
</template>

<style scoped>
.connect-card {
  border-radius: 20px !important;
  overflow: hidden;
}

.connect-header {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 22px 24px 18px;
}
.connect-header__icon {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  flex-shrink: 0;
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
  display: flex;
  align-items: center;
  justify-content: center;
}
.connect-header__title {
  font-size: 16px;
  font-weight: 700;
  margin: 0 0 3px;
}
.connect-header__sub {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin: 0;
}
.connect-close {
  margin-left: auto;
  flex-shrink: 0;
  width: 28px;
  height: 28px;
  border-radius: 8px;
  border: none;
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
}
.connect-body {
  padding: 22px 24px 24px;
}
.connect-actions {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 20px;
}

/* Method picker */
.method-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 16px;
}
.method-card {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 10px;
  padding: 18px 16px 14px;
  border-radius: 16px;
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
  transition:
    border-color 0.15s,
    box-shadow 0.15s;
}
.method-card:hover {
  border-color: rgb(var(--v-theme-primary));
  box-shadow: 0 0 0 3px rgba(var(--v-theme-primary), 0.08);
}
.method-card__badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 11px;
  font-weight: 700;
  color: #1fc06b;
  background: rgba(31, 192, 107, 0.1);
  border-radius: 6px;
  padding: 2px 7px;
}
.badge-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #1fc06b;
  animation: pulse-dot 2s infinite;
}
@keyframes pulse-dot {
  0%,
  100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.5;
    transform: scale(0.7);
  }
}
.method-card__icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.method-card__icon--blue {
  background: rgba(59, 130, 246, 0.12);
  color: #3b82f6;
}
.method-card__icon--green {
  background: rgba(31, 192, 107, 0.12);
  color: #1fc06b;
}
.method-card__title {
  font-size: 14.5px;
  font-weight: 700;
  margin: 0;
}
.method-card__desc {
  font-size: 12.5px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  line-height: 1.5;
  margin: 0;
  flex: 1;
}
.method-card__footer {
  font-size: 12.5px;
  font-weight: 600;
  color: rgb(var(--v-theme-primary));
  margin-top: 4px;
}
.method-note {
  font-size: 12px;
  color: rgba(var(--v-theme-on-surface), 0.45);
  text-align: center;
  margin: 0;
  line-height: 1.5;
}

/* Facebook intro */
.fb-intro__steps {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.fb-step {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 14px;
  color: rgba(var(--v-theme-on-surface), 0.8);
}
.fb-step__num {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: rgba(var(--v-theme-primary), 0.12);
  color: rgb(var(--v-theme-primary));
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.fb-waiting {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 32px 0 16px;
  text-align: center;
}
.fb-waiting__text {
  font-size: 15px;
  font-weight: 600;
  margin: 0 0 6px;
}
.fb-waiting__sub {
  font-size: 13px;
  color: rgba(var(--v-theme-on-surface), 0.5);
  margin: 0;
}

/* Mode options */
.mode-options {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.mode-option {
  display: grid;
  grid-template-columns: 44px 1fr 24px;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 12px;
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  border: 1.5px solid rgba(var(--v-border-color), var(--v-border-opacity));
  background: rgb(var(--v-theme-surface));
  transition:
    border-color 0.12s,
    background 0.12s;
}
.mode-option:hover {
  border-color: rgba(var(--v-theme-primary), 0.4);
  background: rgba(var(--v-theme-primary), 0.02);
}
.mode-option--selected {
  border-color: rgb(var(--v-theme-primary));
  background: rgba(var(--v-theme-primary), 0.04);
}
.mode-option__icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(var(--v-theme-on-surface), 0.06);
  color: rgba(var(--v-theme-on-surface), 0.6);
}
.mode-option--selected .mode-option__icon {
  background: rgba(var(--v-theme-primary), 0.1);
  color: rgb(var(--v-theme-primary));
}
.mode-option__content {
  min-width: 0;
}
.mode-option__title {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 3px;
}
.mode-option__desc {
  font-size: 12.5px;
  color: rgba(var(--v-theme-on-surface), 0.55);
  margin: 0;
  line-height: 1.4;
}
.mode-option__check {
  flex-shrink: 0;
}

.connector-fields {
  margin-top: 14px;
  padding: 16px;
  background: rgba(var(--v-theme-on-surface), 0.025);
  border-radius: 12px;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}

/* Manual steps */
.manual-steps {
  display: flex;
  align-items: center;
  margin-bottom: 22px;
}
.manual-step {
  display: flex;
  align-items: center;
  gap: 6px;
}
.manual-step__dot {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 700;
  border: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
  color: rgba(var(--v-theme-on-surface), 0.4);
  transition: all 0.15s;
}
.manual-step--active .manual-step__dot {
  border-color: rgb(var(--v-theme-primary));
  color: rgb(var(--v-theme-primary));
}
.manual-step--done .manual-step__dot {
  background: rgb(var(--v-theme-primary));
  border-color: rgb(var(--v-theme-primary));
  color: #fff;
}
.manual-step__label {
  font-size: 12px;
  font-weight: 500;
  color: rgba(var(--v-theme-on-surface), 0.4);
  white-space: nowrap;
}
.manual-step--active .manual-step__label,
.manual-step--done .manual-step__label {
  color: rgb(var(--v-theme-on-surface));
  font-weight: 600;
}
.manual-step__line {
  height: 1px;
  width: 32px;
  background: rgba(var(--v-border-color), var(--v-border-opacity));
  margin: 0 4px;
  transition: background 0.15s;
}
.manual-step__line--done {
  background: rgb(var(--v-theme-primary));
}

/* Done state */
.done-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 12px 0 8px;
  gap: 12px;
}
.done-state__icon {
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: rgba(31, 192, 107, 0.12);
  color: #1fc06b;
  display: flex;
  align-items: center;
  justify-content: center;
}
.done-state__title {
  font-size: 18px;
  font-weight: 700;
  margin: 0;
}
.done-state__desc {
  font-size: 13.5px;
  color: rgba(var(--v-theme-on-surface), 0.6);
  margin: 0;
  max-width: 340px;
  line-height: 1.55;
}

/* API key box */
.api-key-box {
  font-family: monospace;
  font-size: 12.5px;
  word-break: break-all;
  background: rgba(var(--v-theme-on-surface), 0.06);
  border-radius: 10px;
  padding: 12px 14px;
}

/* Transitions */
.expand-enter-active,
.expand-leave-active {
  transition:
    max-height 0.2s ease,
    opacity 0.2s ease;
  max-height: 300px;
  overflow: hidden;
}
.expand-enter-from,
.expand-leave-to {
  max-height: 0;
  opacity: 0;
}
</style>
