<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter, useRoute } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const router = useRouter();
const route = useRoute();

const tenantId = computed(() => route.params.id as string | undefined);
const isEditing = computed(() => !!tenantId.value);
const pageTitle = computed(() =>
  isEditing.value ? "Edit tenant" : "Add tenant",
);
const pageSubtitle = computed(() =>
  isEditing.value
    ? "Update tenant details. Slug and ID cannot be changed after provisioning."
    : "Creating a tenant automatically provisions its database — schema, migrations, and default roles & permissions.",
);

const isSaving = ref(false);
const isLoading = ref(false);
const testingMailer = ref(false);
const testMailerEmail = ref("");
const testMailerDialog = ref(false);

const form = ref({
  name: "",
  slug: "",
  subscription_tier: "free" as
    | "free"
    | "starter"
    | "professional"
    | "enterprise",
  subscription_expires_at: null as string | null,
  max_bots: 3,
  max_conversations_per_month: 1000,
  is_active: true,
  settings: {} as Record<string, any>,
  domain: "",
  domain_url: "",
  // Storage
  storage_config: {
    driver: "local" as "local" | "s3" | "sftp",
    key: "",
    secret: "",
    region: "",
    bucket: "",
    url: "",
    endpoint: "",
    use_path_style_endpoint: false,
    host: "",
    username: "",
    password: "",
    private_key: "",
    root: "/uploads",
    port: 22,
  },
  // Mailer
  mailer_config: {
    driver: "system" as "system" | "smtp" | "sendgrid" | "mailgun",
    host: "",
    port: 587,
    encryption: "tls" as "tls" | "ssl" | "none",
    username: "",
    password: "",
    from_address: "",
    from_name: "",
    api_key: "",
    domain: "",
  },
});

const errors = ref<Record<string, string>>({});

onMounted(async () => {
  if (!isEditing.value) return;
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/tenants/${tenantId.value}`);
    const t = data;
    form.value.name = t.name ?? "";
    form.value.slug = t.slug ?? "";
    form.value.subscription_tier = t.subscription_tier ?? "free";
    form.value.subscription_expires_at =
      t.subscription_expires_at?.substring(0, 10) ?? null;
    form.value.max_bots = t.max_bots ?? 3;
    form.value.max_conversations_per_month =
      t.max_conversations_per_month ?? 1000;
    form.value.is_active = t.is_active ?? true;
    form.value.settings = t.settings ?? {};
    const primary = t.domains?.find((d: any) => d.is_primary) ?? t.domains?.[0];
    form.value.domain = primary?.domain ?? "";
    form.value.domain_url = primary?.url ?? "";
    if (t.storage_config)
      Object.assign(form.value.storage_config, t.storage_config);
    if (t.mailer_config)
      Object.assign(form.value.mailer_config, t.mailer_config);
  } catch {
    await Swal.fire({
      title: "Error",
      text: "Failed to load tenant.",
      icon: "error",
    });
    router.push("/administration/tenants");
  } finally {
    isLoading.value = false;
  }
});

const slugManuallyEdited = ref(false);
const domainManuallyEdited = ref(false);
const baseDomain = "payroll.test";
const suggestedDomain = computed(() =>
  form.value.slug ? `${form.value.slug}.${baseDomain}` : "",
);
const suggestedUrl = computed(() =>
  suggestedDomain.value ? `https://${suggestedDomain.value}` : "",
);

function onNameInput() {
  const generated = form.value.name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-");
  if (!slugManuallyEdited.value) form.value.slug = generated;
  onSlugInput();
}
function onSlugInput() {
  if (!domainManuallyEdited.value) {
    form.value.domain = suggestedDomain.value;
    form.value.domain_url = suggestedUrl.value;
  }
}
watch(
  () => form.value.domain,
  (val) => {
    if (
      !domainManuallyEdited.value &&
      form.value.domain_url !== `https://${val}`
    ) {
      form.value.domain_url = val ? `https://${val}` : "";
    }
  },
);

const tierOptions = [
  { title: "Free", value: "free" },
  { title: "Starter", value: "starter" },
  { title: "Professional", value: "professional" },
  { title: "Enterprise", value: "enterprise" },
];
const tierChipColor: Record<string, string> = {
  free: "default",
  starter: "info",
  professional: "primary",
  enterprise: "warning",
};
const storageDriverOptions = [
  { title: "Local (server disk)", value: "local" },
  { title: "Amazon S3 / Compatible", value: "s3" },
  { title: "SFTP", value: "sftp" },
];
const mailerDriverOptions = [
  { title: "System default (shared)", value: "system" },
  { title: "Custom SMTP", value: "smtp" },
  { title: "SendGrid API", value: "sendgrid" },
  { title: "Mailgun API", value: "mailgun" },
];
const encryptionOptions = [
  { title: "TLS (recommended)", value: "tls" },
  { title: "SSL", value: "ssl" },
  { title: "None", value: "none" },
];

function validate(): boolean {
  errors.value = {};
  if (!form.value.name) errors.value.name = "Company name is required.";
  if (!form.value.slug) errors.value.slug = "Slug is required.";
  else if (!/^[a-z0-9-]+$/.test(form.value.slug))
    errors.value.slug = "Lowercase letters, numbers, and hyphens only.";
  if (!form.value.domain) errors.value.domain = "A primary domain is required.";
  if (!form.value.domain_url)
    errors.value.domain_url = "Domain URL is required.";
  if (form.value.max_bots < 1) errors.value.max_bots = "Must be at least 1.";
  if (form.value.max_conversations_per_month < 100)
    errors.value.max_conversations_per_month = "Must be at least 100.";

  const sc = form.value.storage_config;
  if (sc.driver === "s3") {
    if (!sc.key) errors.value["storage_config.key"] = "Access key is required.";
    if (!sc.secret)
      errors.value["storage_config.secret"] = "Secret is required.";
    if (!sc.region)
      errors.value["storage_config.region"] = "Region is required.";
    if (!sc.bucket)
      errors.value["storage_config.bucket"] = "Bucket is required.";
  }
  if (sc.driver === "sftp") {
    if (!sc.host) errors.value["storage_config.host"] = "Host is required.";
    if (!sc.username)
      errors.value["storage_config.username"] = "Username is required.";
  }

  const mc = form.value.mailer_config;
  if (mc.driver === "smtp") {
    if (!mc.host) errors.value["mailer_config.host"] = "SMTP host is required.";
    if (!mc.username)
      errors.value["mailer_config.username"] = "Username is required.";
    if (!mc.password)
      errors.value["mailer_config.password"] = "Password is required.";
  }
  if (mc.driver === "sendgrid" && !mc.api_key)
    errors.value["mailer_config.api_key"] = "API key is required.";
  if (mc.driver === "mailgun" && !mc.api_key)
    errors.value["mailer_config.api_key"] = "API key is required.";
  if (mc.driver !== "system" && !mc.from_address)
    errors.value["mailer_config.from_address"] = "From address is required.";

  return Object.keys(errors.value).length === 0;
}

async function submit() {
  if (!validate()) return;
  isSaving.value = true;
  try {
    const payload = {
      ...form.value,
      storage_config:
        form.value.storage_config.driver === "local"
          ? { driver: "local" }
          : form.value.storage_config,
      mailer_config:
        form.value.mailer_config.driver === "system"
          ? { driver: "system" }
          : form.value.mailer_config,
    };
    if (isEditing.value) {
      const { data } = await axios.put(
        `/api/admin/tenants/${tenantId.value}`,
        payload,
      );
      await Swal.fire({
        title: "Tenant Updated",
        html: `<p><strong>${form.value.name}</strong> has been updated.</p>`,
        icon: "success",
        confirmButtonText: "OK",
      });
      router.push(
        `/administration/tenants/${data.tenant?.id ?? tenantId.value}`,
      );
    } else {
      const { data } = await axios.post("/api/admin/tenants", payload);
      await Swal.fire({
        title: "Tenant Created",
        html: `<p><strong>${form.value.name}</strong> has been created and provisioned.</p>
               <p style="margin-top:8px;font-size:13px">Domain: <code>${form.value.domain}</code></p>`,
        icon: "success",
        confirmButtonText: "View Tenant",
      });
      router.push(`/administration/tenants/${data.tenant.id}`);
    }
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(
        Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]),
      );
    }
    await Swal.fire({
      title: "Error",
      text:
        err.response?.data?.message ??
        "Something went wrong. Check the form for errors.",
      icon: "error",
    });
  } finally {
    isSaving.value = false;
  }
}

async function sendTestEmail() {
  if (!testMailerEmail.value || !tenantId.value) return;
  testingMailer.value = true;
  try {
    await axios.post(`/api/admin/tenants/${tenantId.value}/test-mailer`, {
      email: testMailerEmail.value,
    });
    testMailerDialog.value = false;
    await Swal.fire({
      title: "Test email sent",
      text: `Check ${testMailerEmail.value}`,
      icon: "success",
      timer: 3000,
      showConfirmButton: false,
    });
    testMailerEmail.value = "";
  } catch (e: any) {
    await Swal.fire({
      title: "Failed",
      text: e.response?.data?.message ?? "Could not send test email.",
      icon: "error",
    });
  } finally {
    testingMailer.value = false;
  }
}
</script>

<template>
  <!-- ── Header ───────────────────────────────────────────────────── -->
  <div class="d-flex align-start justify-space-between flex-wrap gap-3 mb-6">
    <div>
      <h1 class="text-h5 font-weight-bold mb-1">{{ pageTitle }}</h1>
      <p class="text-body-2 text-medium-emphasis mb-0" style="max-width: 600px">
        {{ pageSubtitle }}
      </p>
    </div>
    <VBtn
      variant="text"
      prepend-icon="$arrowLeft"
      @click="router.push('/administration/tenants')"
    >
      Back
    </VBtn>
  </div>

  <!-- ── Loading skeleton ─────────────────────────────────────────── -->
  <template v-if="isLoading">
    <VSkeletonLoader type="card" class="mb-4" />
    <VSkeletonLoader type="card" class="mb-4" />
    <VSkeletonLoader type="card" />
  </template>

  <template v-else>
    <VRow>
      <!-- ══ SECTION 1 — Basic info ═══════════════════════════════════ -->
      <VCol cols="12">
        <VCard border flat rounded="lg">
          <VCardItem>
            <template #prepend>
              <VAvatar color="primary" variant="tonal" size="36" rounded="lg">
                <VIcon icon="$officeBuilding" size="18" />
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-bold"
              >Basic information</VCardTitle
            >
            <VCardSubtitle>Identity, domain, and access settings</VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.name"
                  label="Company name *"
                  variant="outlined"
                  density="comfortable"
                  placeholder="e.g. Acme Holdings"
                  :error-messages="errors.name"
                  @update:model-value="onNameInput"
                />
              </VCol>

              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.slug"
                  label="Slug *"
                  variant="outlined"
                  density="comfortable"
                  placeholder="e.g. acme"
                  :error-messages="errors.slug"
                  :disabled="isEditing"
                  @update:model-value="
                    () => {
                      slugManuallyEdited = true;
                      onSlugInput();
                    }
                  "
                />
              </VCol>

              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.domain"
                  label="Primary domain *"
                  variant="outlined"
                  density="comfortable"
                  placeholder="acme.payroll.test"
                  :error-messages="errors.domain"
                  @update:model-value="domainManuallyEdited = true"
                />
              </VCol>

              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.domain_url"
                  label="Domain URL *"
                  variant="outlined"
                  density="comfortable"
                  :placeholder="suggestedUrl || 'https://acme.payroll.test'"
                  :error-messages="errors.domain_url"
                  hint="Full URL including https:// — used to build links inside the tenant"
                  persistent-hint
                  @update:model-value="domainManuallyEdited = true"
                >
                  <template #prepend-inner>
                    <VIcon
                      icon="$linkVariant"
                      size="16"
                      class="text-medium-emphasis mr-1"
                    />
                  </template>
                </VTextField>
              </VCol>

              <VCol cols="12" sm="3" class="me-2">
                <VSwitch
                  v-model="form.is_active"
                  color="success"
                  hide-details
                  density="compact"
                  :label="
                    form.is_active
                      ? 'Active on creation'
                      : 'Inactive on creation'
                  "
                />
              </VCol>

              <VCol v-if="form.domain" cols="12">
                <VAlert
                  type="success"
                  variant="tonal"
                  density="compact"
                  class="mb-4 border-solid border-success border-thin border-opacity-25"
                  icon="false"
                  rounded="md"
                  closable
                >
                  <template #prepend>
                    <SvgSprite
                      name="custom-checkbox-marked-circle-outline"
                      style="width: 20px; height: 20px"
                    />
                  </template>
                  <div>
                    Tenant will be reachable at
                    <strong>{{
                      form.domain_url || `https://${form.domain}`
                    }}</strong>
                  </div>
                </VAlert>
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ══ SECTION 2 — Subscription & limits ════════════════════════ -->
      <VCol cols="12">
        <VCard border flat rounded="lg">
          <VCardItem>
            <template #prepend>
              <VAvatar color="warning" variant="tonal" size="36" rounded="lg">
                <VIcon icon="$tune" size="18" />
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-bold"
              >Subscription &amp; limits</VCardTitle
            >
            <VCardSubtitle
              >Configure tier, expiry, and usage ceilings</VCardSubtitle
            >
            <template v-if="isEditing" #append>
              <VChip
                :color="tierChipColor[form.subscription_tier]"
                variant="tonal"
                size="small"
                label
              >
                {{ form.subscription_tier }}
              </VChip>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" sm="6">
                <VSelect
                  v-model="form.subscription_tier"
                  :items="tierOptions"
                  label="Subscription tier"
                  variant="outlined"
                  density="comfortable"
                  hide-details
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model="form.subscription_expires_at"
                  type="date"
                  label="Subscription expires (optional)"
                  variant="outlined"
                  density="comfortable"
                  hide-details
                  clearable
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model.number="form.max_bots"
                  type="number"
                  min="1"
                  label="Max bots *"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="errors.max_bots"
                  hide-details="auto"
                />
              </VCol>
              <VCol cols="12" sm="6">
                <VTextField
                  v-model.number="form.max_conversations_per_month"
                  type="number"
                  min="100"
                  step="100"
                  label="Max conversations / month *"
                  variant="outlined"
                  density="comfortable"
                  :error-messages="errors.max_conversations_per_month"
                  hide-details="auto"
                />
              </VCol>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ══ SECTION 3 — Storage ══════════════════════════════════════ -->
      <VCol cols="12">
        <VCard border flat rounded="lg">
          <VCardItem>
            <template #prepend>
              <VAvatar color="info" variant="tonal" size="36" rounded="lg">
                <VIcon icon="$database" size="18" />
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-bold"
              >Storage</VCardTitle
            >
            <VCardSubtitle>
              Where this tenant's files live. Each tenant can have their own
              isolated bucket for data residency and security.
            </VCardSubtitle>
            <template #append>
              <VChip
                :color="
                  form.storage_config.driver === 'local' ? 'default' : 'success'
                "
                variant="tonal"
                size="small"
              >
                {{
                  form.storage_config.driver === "local"
                    ? "Shared server disk"
                    : form.storage_config.driver.toUpperCase()
                }}
              </VChip>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="form.storage_config.driver"
                  :items="storageDriverOptions"
                  label="Storage driver"
                  variant="outlined"
                  density="comfortable"
                  hide-details
                />
              </VCol>

              <!-- Local notice -->
              <VCol v-if="form.storage_config.driver === 'local'" cols="12">
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                  rounded="md"
                  icon="$info"
                >
                  Files stored in
                  <code>storage/app/tenants/{{ form.slug || "{slug}" }}/</code>
                  on your server. Suitable for development or single-server
                  setups — upgrade to S3 for multi-server deployments or
                  tenant-owned data residency.
                </VAlert>
              </VCol>

              <!-- S3 fields -->
              <template v-if="form.storage_config.driver === 's3'">
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.storage_config.key"
                    label="Access key ID *"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="errors['storage_config.key']"
                    hide-details="auto"
                    autocomplete="off"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.storage_config.secret"
                    label="Secret access key *"
                    type="password"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="errors['storage_config.secret']"
                    hide-details="auto"
                    autocomplete="new-password"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.region"
                    label="Region *"
                    variant="outlined"
                    density="comfortable"
                    placeholder="us-east-1"
                    :error-messages="errors['storage_config.region']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.bucket"
                    label="Bucket name *"
                    variant="outlined"
                    density="comfortable"
                    placeholder="tenant-acme-media"
                    :error-messages="errors['storage_config.bucket']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.url"
                    label="CDN / public URL"
                    variant="outlined"
                    density="comfortable"
                    placeholder="https://cdn.acme.com"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="8">
                  <VTextField
                    v-model="form.storage_config.endpoint"
                    label="Custom endpoint (optional)"
                    variant="outlined"
                    density="comfortable"
                    placeholder="https://s3.us-east-1.amazonaws.com"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="4" class="d-flex align-center">
                  <VSwitch
                    v-model="form.storage_config.use_path_style_endpoint"
                    label="Path-style endpoint"
                    color="primary"
                    hide-details
                    density="compact"
                  />
                </VCol>
                <VCol cols="12">
                  <VAlert
                    type="success"
                    variant="tonal"
                    density="compact"
                    rounded="md"
                    icon="$checkCircle"
                  >
                    Files stored in
                    <strong>{{
                      form.storage_config.bucket || "your-bucket"
                    }}</strong>
                    ({{ form.storage_config.region || "region" }}). Temporary
                    signed URLs are generated per request — no public bucket
                    access needed.
                  </VAlert>
                </VCol>
              </template>

              <!-- SFTP fields -->
              <template v-if="form.storage_config.driver === 'sftp'">
                <VCol cols="12" sm="5">
                  <VTextField
                    v-model="form.storage_config.host"
                    label="SFTP host *"
                    variant="outlined"
                    density="comfortable"
                    placeholder="sftp.acme.com"
                    :error-messages="errors['storage_config.host']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="2">
                  <VTextField
                    v-model.number="form.storage_config.port"
                    label="Port"
                    type="number"
                    variant="outlined"
                    density="comfortable"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.root"
                    label="Root path"
                    variant="outlined"
                    density="comfortable"
                    placeholder="/uploads"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.username"
                    label="Username *"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="errors['storage_config.username']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="4">
                  <VTextField
                    v-model="form.storage_config.password"
                    label="Password"
                    type="password"
                    variant="outlined"
                    density="comfortable"
                    hint="Leave blank if using a private key"
                    persistent-hint
                    autocomplete="new-password"
                  />
                </VCol>
                <VCol cols="12">
                  <VTextarea
                    v-model="form.storage_config.private_key"
                    label="Private key (optional)"
                    variant="outlined"
                    density="comfortable"
                    rows="4"
                    placeholder="-----BEGIN RSA PRIVATE KEY-----&#10;..."
                    hide-details
                  />
                </VCol>
              </template>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>

      <!-- ══ SECTION 4 — Mailer ═══════════════════════════════════════ -->
      <VCol cols="12">
        <VCard border flat rounded="lg">
          <VCardItem>
            <template #prepend>
              <VAvatar color="secondary" variant="tonal" size="36" rounded="lg">
                <VIcon icon="$email" size="18" />
              </VAvatar>
            </template>
            <VCardTitle class="text-body-1 font-weight-bold">Mailer</VCardTitle>
            <VCardSubtitle>
              Dedicated SMTP or API mailer so emails come from the tenant's own
              domain.
            </VCardSubtitle>
            <template #append>
              <div class="d-flex align-center gap-2">
                <VChip
                  :color="
                    form.mailer_config.driver === 'system'
                      ? 'default'
                      : 'success'
                  "
                  variant="tonal"
                  size="small"
                >
                  {{
                    form.mailer_config.driver === "system"
                      ? "System default"
                      : form.mailer_config.driver
                  }}
                </VChip>
                <VBtn
                  v-if="isEditing && form.mailer_config.driver !== 'system'"
                  size="small"
                  variant="tonal"
                  color="secondary"
                  prepend-icon="$send"
                  @click="testMailerDialog = true"
                >
                  Test
                </VBtn>
              </div>
            </template>
          </VCardItem>
          <VDivider />
          <VCardText>
            <VRow dense>
              <VCol cols="12" sm="4">
                <VSelect
                  v-model="form.mailer_config.driver"
                  :items="mailerDriverOptions"
                  label="Mailer"
                  variant="outlined"
                  density="comfortable"
                  hide-details
                />
              </VCol>

              <!-- System notice -->
              <VCol v-if="form.mailer_config.driver === 'system'" cols="12">
                <VAlert
                  type="info"
                  variant="tonal"
                  density="compact"
                  rounded="md"
                  icon="$info"
                >
                  This tenant uses the platform's shared mailer from
                  <code>.env</code>. Emails will appear from the system's
                  <code>MAIL_FROM_ADDRESS</code>. Configure a dedicated mailer
                  if the tenant needs emails from their own domain.
                </VAlert>
              </VCol>

              <!-- From fields — all non-system drivers -->
              <template v-if="form.mailer_config.driver !== 'system'">
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.mailer_config.from_address"
                    label="From address *"
                    type="email"
                    variant="outlined"
                    density="comfortable"
                    placeholder="noreply@acme.com"
                    :error-messages="errors['mailer_config.from_address']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.mailer_config.from_name"
                    label="From name"
                    variant="outlined"
                    density="comfortable"
                    placeholder="Acme Notifications"
                    hide-details
                  />
                </VCol>
              </template>

              <!-- SMTP fields -->
              <template v-if="form.mailer_config.driver === 'smtp'">
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.mailer_config.host"
                    label="SMTP host *"
                    variant="outlined"
                    density="comfortable"
                    placeholder="smtp.sendgrid.net"
                    :error-messages="errors['mailer_config.host']"
                    hide-details="auto"
                  />
                </VCol>
                <VCol cols="12" sm="3">
                  <VTextField
                    v-model.number="form.mailer_config.port"
                    label="Port"
                    type="number"
                    variant="outlined"
                    density="comfortable"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="3">
                  <VSelect
                    v-model="form.mailer_config.encryption"
                    :items="encryptionOptions"
                    label="Encryption"
                    variant="outlined"
                    density="comfortable"
                    hide-details
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.mailer_config.username"
                    label="Username *"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="errors['mailer_config.username']"
                    hide-details="auto"
                    autocomplete="off"
                  />
                </VCol>
                <VCol cols="12" sm="6">
                  <VTextField
                    v-model="form.mailer_config.password"
                    label="Password *"
                    type="password"
                    variant="outlined"
                    density="comfortable"
                    :error-messages="errors['mailer_config.password']"
                    hide-details="auto"
                    autocomplete="new-password"
                  />
                </VCol>
              </template>

              <!-- SendGrid / Mailgun -->
              <template
                v-if="
                  form.mailer_config.driver === 'sendgrid' ||
                  form.mailer_config.driver === 'mailgun'
                "
              >
                <VCol
                  cols="12"
                  :sm="form.mailer_config.driver === 'mailgun' ? 6 : 8"
                >
                  <VTextField
                    v-model="form.mailer_config.api_key"
                    label="API key *"
                    type="password"
                    variant="outlined"
                    density="comfortable"
                    :placeholder="
                      form.mailer_config.driver === 'sendgrid'
                        ? 'SG.xxxx'
                        : 'key-xxxx'
                    "
                    :error-messages="errors['mailer_config.api_key']"
                    hide-details="auto"
                    autocomplete="new-password"
                  />
                </VCol>
                <VCol
                  v-if="form.mailer_config.driver === 'mailgun'"
                  cols="12"
                  sm="6"
                >
                  <VTextField
                    v-model="form.mailer_config.domain"
                    label="Mailgun sending domain"
                    variant="outlined"
                    density="comfortable"
                    placeholder="mg.acme.com"
                    hide-details
                  />
                </VCol>
                <VCol cols="12">
                  <VAlert
                    type="info"
                    variant="tonal"
                    density="compact"
                    rounded="md"
                    icon="$info"
                  >
                    <template v-if="form.mailer_config.driver === 'sendgrid'">
                      Create a SendGrid API key with
                      <strong>Mail Send</strong> permission only. Never use a
                      full-access key here.
                    </template>
                    <template v-else>
                      Ensure your Mailgun sending domain is verified before
                      saving.
                    </template>
                  </VAlert>
                </VCol>
              </template>
            </VRow>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- ── Actions ──────────────────────────────────────────────────── -->
    <VDivider class="mt-6 mb-4" />
    <div class="d-flex justify-end gap-3">
      <VBtn variant="text" @click="router.push('/administration/tenants')"
        >Cancel</VBtn
      >
      <VBtn
        color="primary"
        :prepend-icon="isEditing ? '$contentSave' : '$check'"
        :loading="isSaving"
        :disabled="isSaving"
        @click="submit"
      >
        {{ isEditing ? "Save changes" : "Create & provision tenant" }}
      </VBtn>
    </div>
  </template>

  <!-- ── Test mailer dialog ────────────────────────────────────────── -->
  <VDialog v-model="testMailerDialog" max-width="420">
    <VCard rounded="lg">
      <VCardItem>
        <VCardTitle class="text-subtitle-1 font-weight-bold"
          >Send test email</VCardTitle
        >
        <VCardSubtitle
          >Verifies this tenant's mailer configuration is
          working.</VCardSubtitle
        >
      </VCardItem>
      <VDivider />
      <VCardText class="pt-4">
        <VTextField
          v-model="testMailerEmail"
          label="Recipient email"
          type="email"
          variant="outlined"
          density="comfortable"
          placeholder="you@example.com"
          prepend-inner-icon="$email"
          hide-details
        />
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="testMailerDialog = false">Cancel</VBtn>
        <VBtn
          color="primary"
          prepend-icon="$send"
          :loading="testingMailer"
          :disabled="!testMailerEmail"
          @click="sendTestEmail"
        >
          Send test
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
