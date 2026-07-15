<script setup lang="ts">
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const router = useRouter();
const isSaving = ref(false);

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
  domain: "",
  domain_type: "subdomain" as "custom" | "subdomain",
});

const slugManuallyEdited = ref(false);
const idManuallyEdited = ref(false);

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

const baseDomain = "payroll.test";
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

  return Object.keys(errors.value).length === 0;
};

// ── Submit ────────────────────────────────────────────────────────────────────

const submit = async () => {
  if (!validateForm()) return;

  isSaving.value = true;
  try {
    // POST /api/admin/tenants — TenantController::store.
    // TenantObserver calls TenantDatabaseManager::provision() automatically
    // after the row is created: creates the shared schema (or verifies
    // dedicated/self-hosted connection), runs tenant migrations, seeds
    // default permissions/roles. Can take several seconds for shared mode.
    const { data } = await axios.post("/api/admin/tenants", form.value);

    await Swal.fire({
      title: "Tenant Created",
      html: `<p><strong>${form.value.name}</strong> has been created and provisioned.</p>
             <p class="text-caption mt-2">Domain: <code>${form.value.domain}</code></p>`,
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
  <div class="ax-page">
    <div class="ax-header mb-3">
      <div>
        <h1 class="ax-title">Add tenant</h1>
        <p class="ax-subtitle">
          Creating a tenant automatically provisions its database — schema,
          migrations, and default roles &amp; permissions. This can take a few
          seconds for shared-mode tenants.
        </p>
      </div>
      <VBtn
        variant="text"
        class="ax-vbtn ax-vbtn--ghost"
        prepend-icon="mdi-arrow-left"
        @click="router.push('/administration/tenants')"
      >
        Back
      </VBtn>
    </div>

    <div class="ax-form-grid">
      <!-- ── Basic information ─────────────────────────────────────────── -->
      <VCard variant="flat" class="ax-card">
        <div class="ax-section-head">
          <div class="ax-section-icon">
            <VIcon icon="mdi-office-building" size="18" />
          </div>
          <div>
            <p class="ax-section-title">Basic information</p>
            <p class="ax-section-sub">
              The tenant ID is permanent once created — used as the primary key.
            </p>
          </div>
        </div>

        <div class="ax-field-grid">
          <div class="ax-field ax-field--half">
            <label class="ax-label"
              >Company name <span class="ax-req">*</span></label
            >
            <VTextField
              v-model="form.name"
              variant="outlined"
              density="comfortable"
              placeholder="e.g., Acme Holdings"
              hide-details="auto"
              :error-messages="errors.name"
              class="ax-input"
              @update:model-value="onNameInput"
            />
          </div>

          <div class="ax-field ax-field--half">
            <label class="ax-label"
              >Tenant ID <span class="ax-req">*</span></label
            >
            <VTextField
              v-model="form.id"
              variant="outlined"
              density="comfortable"
              placeholder="e.g., acme"
              hide-details="auto"
              :error-messages="errors.id"
              class="ax-input"
              @update:model-value="idManuallyEdited = true"
            />
            <p class="ax-hint">Lowercase, hyphenated, permanent.</p>
          </div>

          <div class="ax-field ax-field--half">
            <label class="ax-label">Slug <span class="ax-req">*</span></label>
            <VTextField
              v-model="form.slug"
              variant="outlined"
              density="comfortable"
              placeholder="e.g., acme"
              hide-details="auto"
              :error-messages="errors.slug"
              class="ax-input"
              @update:model-value="
                (v: string) => {
                  slugManuallyEdited = true;
                  onSlugInput();
                }
              "
            />
            <p class="ax-hint">Used in URLs and as the subdomain suggestion.</p>
          </div>

          <div class="ax-field ax-field--half d-flex align-center">
            <div class="ax-switch-row">
              <VSwitch
                v-model="form.is_active"
                color="success"
                hide-details
                density="compact"
              />
              <div>
                <p class="ax-label mb-0">Active on creation</p>
                <p class="ax-hint mb-0">
                  Inactive tenants cannot log in until activated.
                </p>
              </div>
            </div>
          </div>
        </div>
      </VCard>

      <!-- ── Domain & provisioning ────────────────────────────────────── -->
      <VCard variant="flat" class="ax-card">
        <div class="ax-section-head">
          <div class="ax-section-icon"><VIcon icon="mdi-web" size="18" /></div>
          <div>
            <p class="ax-section-title">Domain &amp; provisioning</p>
            <p class="ax-section-sub">
              This becomes the tenant's login URL and must be unique.
            </p>
          </div>
        </div>

        <div class="ax-field-grid">
          <div class="ax-field ax-field--third">
            <label class="ax-label">Domain type</label>
            <VSelect
              v-model="form.domain_type"
              :items="domainTypeOptions"
              variant="outlined"
              density="comfortable"
              hide-details
              class="ax-input"
              @update:model-value="onSlugInput"
            />
          </div>

          <div class="ax-field ax-field--twothirds">
            <label class="ax-label"
              >Primary domain <span class="ax-req">*</span></label
            >
            <VTextField
              v-model="form.domain"
              variant="outlined"
              density="comfortable"
              :placeholder="
                form.domain_type === 'subdomain'
                  ? suggestedDomain || 'acme.payroll.test'
                  : 'payroll.acme.com'
              "
              hide-details="auto"
              :error-messages="errors.domain"
              class="ax-input"
              @update:model-value="domainManuallyEdited = true"
            />
          </div>

          <div v-if="form.domain" class="ax-field ax-field--full">
            <div class="ax-domain-preview">
              <VIcon color="success" size="20">mdi-check-circle</VIcon>
              <div>
                <p class="mb-0 fs-13">
                  Tenant will be reachable at
                  <strong>https://{{ form.domain }}</strong>
                </p>
                <p class="ax-hint mb-0">
                  {{
                    form.domain_type === "custom"
                      ? "Make sure DNS for this custom domain points to your application server before going live."
                      : "Subdomain routing is handled automatically by the InitializeTenancyByDomain middleware."
                  }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </VCard>

      <!-- ── Limits & subscription ───────────────────────────────────── -->
      <VCard variant="flat" class="ax-card">
        <div class="ax-section-head">
          <div class="ax-section-icon"><VIcon icon="mdi-tune" size="18" /></div>
          <div>
            <p class="ax-section-title">Limits &amp; subscription</p>
            <p class="ax-section-sub">Configure tier and usage ceilings.</p>
          </div>
        </div>

        <div class="ax-field-grid">
          <div class="ax-field ax-field--half">
            <label class="ax-label">Subscription tier</label>
            <VSelect
              v-model="form.subscription_tier"
              :items="tierOptions"
              variant="outlined"
              density="comfortable"
              hide-details
              class="ax-input"
            />
          </div>

          <div class="ax-field ax-field--half">
            <label class="ax-label"
              >Subscription expires
              <span class="ax-hint">(optional)</span></label
            >
            <VTextField
              v-model="form.subscription_expires_at"
              type="date"
              variant="outlined"
              density="comfortable"
              hide-details
              class="ax-input"
            />
          </div>

          <div class="ax-field ax-field--half">
            <label class="ax-label"
              >Max flows <span class="ax-req">*</span></label
            >
            <VTextField
              v-model.number="form.max_flows"
              type="number"
              min="1"
              variant="outlined"
              density="comfortable"
              hide-details="auto"
              :error-messages="errors.max_flows"
              class="ax-input"
            />
          </div>

          <div class="ax-field ax-field--half">
            <label class="ax-label"
              >Max conversations / month <span class="ax-req">*</span></label
            >
            <VTextField
              v-model.number="form.max_conversations_per_month"
              type="number"
              min="100"
              step="100"
              variant="outlined"
              density="comfortable"
              hide-details="auto"
              :error-messages="errors.max_conversations_per_month"
              class="ax-input"
            />
          </div>
        </div>
      </VCard>
    </div>

    <div class="ax-form-actions">
      <VBtn
        variant="text"
        class="ax-vbtn ax-vbtn--ghost"
        @click="router.push('/administration/tenants')"
        >Cancel</VBtn
      >
      <VBtn
        color="primary"
        class="ax-vbtn"
        prepend-icon="mdi-check"
        :loading="isSaving"
        :disabled="isSaving"
        @click="submit"
      >
        Create &amp; provision tenant
      </VBtn>
    </div>
  </div>
</template>

<style scoped>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap");

.ax-page {
  font-family: "Outfit", sans-serif;
}

.ax-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 16px;
}
.ax-title {
  font-size: 22px;
  font-weight: 700;
  margin: 0 0 4px;
}
.ax-subtitle {
  font-size: 13px;
  color: var(--bs-secondary-color, #6b7280);
  margin: 0;
  max-width: 640px;
}

.ax-vbtn {
  text-transform: none;
  font-weight: 600;
  border-radius: 10px;
  letter-spacing: normal;
}
.ax-vbtn--ghost {
  color: inherit;
}

.ax-form-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 16px;
}

.ax-card {
  border: 1px solid var(--bs-border-color, #e5e7eb) !important;
  border-radius: 14px !important;
  padding: 22px !important;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04) !important;
}

.ax-section-head {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 18px;
}
.ax-section-icon {
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(81, 153, 174, 0.1);
  color: #3f7f91;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.ax-section-title {
  font-weight: 700;
  font-size: 15px;
  margin: 0;
}
.ax-section-sub {
  font-size: 12px;
  color: var(--bs-secondary-color, #6b7280);
  margin: 2px 0 0;
}

.ax-field-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 16px;
}
.ax-field--half {
  grid-column: span 3;
}
.ax-field--third {
  grid-column: span 2;
}
.ax-field--twothirds {
  grid-column: span 4;
}
.ax-field--full {
  grid-column: span 6;
}
@media (max-width: 700px) {
  .ax-field--half,
  .ax-field--third,
  .ax-field--twothirds {
    grid-column: span 6;
  }
}

.ax-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--bs-secondary-color, #6b7280);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 6px;
  display: block;
}
.ax-req {
  color: #dc2626;
}
.ax-hint {
  font-size: 11px;
  color: var(--bs-secondary-color, #6b7280);
  margin-top: 4px;
}

.ax-input :deep(.v-field) {
  border-radius: 9px;
}

.ax-switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.ax-domain-preview {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  background: rgba(22, 163, 74, 0.06);
  border: 1px solid rgba(22, 163, 74, 0.2);
  border-radius: 10px;
  padding: 12px 14px;
}

.ax-form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--bs-border-color, #e5e7eb);
}
</style>
