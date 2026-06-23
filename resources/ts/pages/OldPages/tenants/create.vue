<script setup lang="ts">
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

definePage({
  meta: { layout: "default" },
});

const router = useRouter();
const isSaving = ref(false);

// ── Tabs ──────────────────────────────────────────────────────────────────────

const tabs = ["basic", "domain", "limits"] as const;
const currentTabIndex = ref(0);
const tab = computed({
  get: () => tabs[currentTabIndex.value],
  set: (value) => {
    const index = tabs.findIndex((t) => t === value);
    if (index !== -1) currentTabIndex.value = index;
  },
});

const nextTab = () => {
  if (currentTabIndex.value < tabs.length - 1) currentTabIndex.value++;
};
const previousTab = () => {
  if (currentTabIndex.value > 0) currentTabIndex.value--;
};
const isFirstTab = computed(() => currentTabIndex.value === 0);
const isLastTab = computed(() => currentTabIndex.value === tabs.length - 1);

// ── Form — fields exactly match TenantController::store validation ──────────

const form = ref({
  id: "",
  name: "",
  slug: "",
  subscription_tier: "free" as
    | "free"
    | "starter"
    | "professional"
    | "enterprise",
  subscription_expires_at: null as string | null,
  max_flows: 3,
  max_conversations_per_month: 1000,
  is_active: true,
  settings: {} as Record<string, any>,
  // Primary domain
  domain: "",
  domain_type: "subdomain" as "custom" | "subdomain",
});

const slugManuallyEdited = ref(false);
const idManuallyEdited = ref(false);

// Auto-generate slug/id from name unless the user has typed into them directly
const onNameInput = () => {
  const generated = form.value.name
    .toLowerCase()
    .trim()
    .replace(/[^a-z0-9\s-]/g, "")
    .replace(/\s+/g, "-")
    .replace(/-+/g, "-");

  if (!slugManuallyEdited.value) form.value.slug = generated;
  if (!idManuallyEdited.value) form.value.id = generated;
};

// Suggest a subdomain based on the slug, unless the user has overridden it
const baseDomain = "payroll.test"; // adjust to your actual app domain / .env value
const suggestedDomain = computed(() =>
  form.value.slug ? `${form.value.slug}.${baseDomain}` : "",
);
const domainManuallyEdited = ref(false);
const onSlugInput = () => {
  if (!domainManuallyEdited.value && form.value.domain_type === "subdomain") {
    form.value.domain = suggestedDomain.value;
  }
};

const tierOptions = [
  { title: "Free", value: "free" },
  { title: "Starter", value: "starter" },
  { title: "Professional", value: "professional" },
  { title: "Enterprise", value: "enterprise" },
];

const domainTypeOptions = [
  { title: "Subdomain (e.g. acme.payroll.test)", value: "subdomain" },
  { title: "Custom domain (e.g. payroll.acme.com)", value: "custom" },
];

// ── Validation ────────────────────────────────────────────────────────────────

const errors = ref<Record<string, string>>({});

const validateForm = (): boolean => {
  errors.value = {};

  if (!form.value.id) errors.value.id = "Tenant ID is required.";
  else if (!/^[a-z0-9-]+$/.test(form.value.id))
    errors.value.id = "Use lowercase letters, numbers, and hyphens only.";

  if (!form.value.name) errors.value.name = "Company name is required.";

  if (!form.value.slug) errors.value.slug = "Slug is required.";
  else if (!/^[a-z0-9-]+$/.test(form.value.slug))
    errors.value.slug = "Use lowercase letters, numbers, and hyphens only.";

  if (!form.value.domain)
    errors.value.domain =
      "A primary domain is required to provision the tenant.";

  if (form.value.max_flows < 1) errors.value.max_flows = "Must be at least 1.";
  if (form.value.max_conversations_per_month < 100)
    errors.value.max_conversations_per_month = "Must be at least 100.";

  if (Object.keys(errors.value).length > 0) {
    // Jump back to whichever tab has the first error
    if (errors.value.id || errors.value.name || errors.value.slug)
      currentTabIndex.value = 0;
    else if (errors.value.domain) currentTabIndex.value = 1;
    else currentTabIndex.value = 2;
    return false;
  }
  return true;
};

// ── Submit ────────────────────────────────────────────────────────────────────

const submit = async () => {
  if (!validateForm()) return;

  isSaving.value = true;
  try {
    // POST /api/admin/tenants — TenantController::store.
    // The TenantObserver (enabled by default) calls TenantDatabaseManager::provision()
    // automatically after the row is created: creates the shared schema (or verifies
    // dedicated/self-hosted connection), runs tenant migrations, then seeds default
    // permissions and roles. This can take several seconds for shared-mode tenants.
    const { data } = await axios.post("/api/admin/tenants", form.value);

    await Swal.fire({
      title: "Tenant Created",
      html: `
        <p><strong>${form.value.name}</strong> has been created and provisioned.</p>
        <p class="text-caption mt-2">Domain: <code>${form.value.domain}</code></p>
      `,
      icon: "success",
      confirmButtonText: "View Tenant",
    });

    router.push(`/administration/tenants/${data.tenant.id}`);
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
        "Failed to create tenant. Check the form for errors.",
      icon: "error",
    });
  } finally {
    isSaving.value = false;
  }
};
</script>

<template>
  <VCard elevation="0">
    <VCardTitle class="d-flex align-center pa-6 bg-primary text-white">
      <VIcon icon="mdi-plus" size="28" class="mr-3" />
      <span class="text-h5">Create New Tenant</span>
    </VCardTitle>

    <VDivider />

    <VTabs v-model="tab" color="primary" align-tabs="center" grow>
      <VTab value="basic">
        <VIcon start>mdi-office-building</VIcon>
        Basic Information
      </VTab>
      <VTab value="domain">
        <VIcon start>mdi-web</VIcon>
        Domain &amp; Provisioning
      </VTab>
      <VTab value="limits">
        <VIcon start>mdi-tune</VIcon>
        Limits &amp; Subscription
      </VTab>
    </VTabs>

    <VDivider />

    <VWindow v-model="tab">
      <!-- ── Tab 1: Basic Information ──────────────────────────────────── -->
      <VWindowItem value="basic">
        <VCardText class="pa-6">
          <VRow>
            <VCol cols="12">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
              >
                <template #prepend><VIcon>mdi-information</VIcon></template>
                The tenant ID is permanent once created and is used as the
                primary key.
              </VAlert>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.name"
                label="Company Name *"
                variant="outlined"
                density="compact"
                placeholder="e.g., Acme Holdings"
                :error-messages="errors.name"
                @update:model-value="onNameInput"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.id"
                label="Tenant ID *"
                variant="outlined"
                density="compact"
                placeholder="e.g., acme"
                hint="Lowercase, hyphenated, permanent. Used as the primary key."
                persistent-hint
                :error-messages="errors.id"
                @update:model-value="idManuallyEdited = true"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.slug"
                label="Slug *"
                variant="outlined"
                density="compact"
                placeholder="e.g., acme"
                hint="Used in URLs and as the basis for the subdomain suggestion."
                persistent-hint
                :error-messages="errors.slug"
                @update:model-value="
                  (v: string) => {
                    slugManuallyEdited = true;
                    onSlugInput();
                  }
                "
              />
            </VCol>

            <VCol cols="12" md="6">
              <VSwitch
                v-model="form.is_active"
                label="Active on Creation"
                color="success"
                hint="Inactive tenants cannot log in until activated."
                persistent-hint
              />
            </VCol>
          </VRow>
        </VCardText>
      </VWindowItem>

      <!-- ── Tab 2: Domain & Provisioning ──────────────────────────────── -->
      <VWindowItem value="domain">
        <VCardText class="pa-6">
          <VRow>
            <VCol cols="12">
              <VAlert
                type="info"
                variant="tonal"
                density="compact"
                class="mb-4"
              >
                <template #prepend><VIcon>mdi-information</VIcon></template>
                Creating the tenant automatically provisions its database:
                creates the schema, runs tenant migrations, and seeds default
                roles &amp; permissions. This can take a few seconds for
                shared-mode tenants.
              </VAlert>
            </VCol>

            <VCol cols="12" md="5">
              <VSelect
                v-model="form.domain_type"
                :items="domainTypeOptions"
                label="Domain Type"
                variant="outlined"
                density="compact"
                @update:model-value="onSlugInput"
              />
            </VCol>

            <VCol cols="12" md="7">
              <VTextField
                v-model="form.domain"
                label="Primary Domain *"
                variant="outlined"
                density="compact"
                :placeholder="
                  form.domain_type === 'subdomain'
                    ? suggestedDomain || 'acme.payroll.test'
                    : 'payroll.acme.com'
                "
                hint="Must be unique across all tenants. This becomes the tenant's login URL."
                persistent-hint
                :error-messages="errors.domain"
                @update:model-value="domainManuallyEdited = true"
              />
            </VCol>

            <VCol cols="12" v-if="form.domain">
              <VCard variant="outlined" color="success">
                <VCardText class="d-flex align-center gap-3">
                  <VIcon color="success">mdi-check-circle</VIcon>
                  <div>
                    <p class="text-body-2 mb-0">
                      Tenant will be reachable at
                      <strong
                        >{{
                          form.domain_type === "custom"
                            ? "https://"
                            : "https://"
                        }}{{ form.domain }}</strong
                      >
                    </p>
                    <p class="text-caption text-medium-emphasis mb-0">
                      {{
                        form.domain_type === "custom"
                          ? "Make sure DNS for this custom domain points to your application server before going live."
                          : "Subdomain routing is handled automatically by the InitializeTenancyByDomain middleware."
                      }}
                    </p>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
          </VRow>
        </VCardText>
      </VWindowItem>

      <!-- ── Tab 3: Limits & Subscription ──────────────────────────────── -->
      <VWindowItem value="limits">
        <VCardText class="pa-6">
          <VRow>
            <VCol cols="12">
              <h6 class="text-h6 mb-3">Subscription</h6>
            </VCol>

            <VCol cols="12" md="6">
              <VSelect
                v-model="form.subscription_tier"
                :items="tierOptions"
                label="Subscription Tier"
                variant="outlined"
                density="compact"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model="form.subscription_expires_at"
                type="date"
                label="Subscription Expires (optional)"
                variant="outlined"
                density="compact"
                hint="Leave empty for no expiry."
                persistent-hint
              />
            </VCol>

            <VCol cols="12">
              <h6 class="text-h6 mb-3 mt-4">Usage Limits</h6>
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.max_flows"
                type="number"
                label="Max Flows"
                variant="outlined"
                density="compact"
                min="1"
                :error-messages="errors.max_flows"
              />
            </VCol>

            <VCol cols="12" md="6">
              <VTextField
                v-model.number="form.max_conversations_per_month"
                type="number"
                label="Max Conversations / Month"
                variant="outlined"
                density="compact"
                min="100"
                step="100"
                :error-messages="errors.max_conversations_per_month"
              />
            </VCol>
          </VRow>
        </VCardText>
      </VWindowItem>
    </VWindow>

    <VDivider />

    <!-- ── Actions ────────────────────────────────────────────────────── -->
    <VCardActions class="pa-6">
      <VBtn
        variant="text"
        color="error"
        prepend-icon="mdi-close"
        @click="router.push('/administration/tenants')"
      >
        Cancel
      </VBtn>

      <VSpacer />

      <VBtn
        v-if="!isFirstTab"
        variant="text"
        color="grey"
        prepend-icon="mdi-arrow-left"
        size="large"
        @click="previousTab"
      >
        Previous
      </VBtn>

      <VBtn
        v-if="!isLastTab"
        variant="text"
        color="primary"
        append-icon="mdi-arrow-right"
        size="large"
        @click="nextTab"
      >
        Next
      </VBtn>

      <VBtn
        v-if="isLastTab"
        color="success"
        prepend-icon="mdi-check"
        :loading="isSaving"
        :disabled="isSaving"
        @click="submit"
      >
        Create &amp; Provision Tenant
      </VBtn>
    </VCardActions>
  </VCard>
</template>
