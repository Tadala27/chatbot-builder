<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const tenant = ref<any>(null);
const provisioning = ref(false);

const tierMeta: Record<string, { label: string; color: string }> = {
  free: { label: "Free", color: "default" },
  starter: { label: "Starter", color: "info" },
  professional: { label: "Professional", color: "primary" },
  enterprise: { label: "Enterprise", color: "purple" },
};

const formatDate = (d: string | null) =>
  d
    ? new Date(d).toLocaleDateString("en-GB", {
        day: "2-digit",
        month: "short",
        year: "numeric",
      })
    : "—";

const initials = (name: string) => (name ?? "").slice(0, 2).toUpperCase();

const fetchTenant = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/tenants/${route.params.id}`);
    tenant.value = data;
  } catch (e) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
};

// ── Provisioning ──────────────────────────────────────────────────
const runProvision = async () => {
  const r = await Swal.fire({
    title: "Provision tenant?",
    text: "This will create the tenant's database, run migrations, and seed default roles and permissions.",
    icon: "question",
    showCancelButton: true,
    confirmButtonText: "Provision",
  });
  if (!r.isConfirmed) return;

  provisioning.value = true;
  try {
    const { data } = await axios.post(
      `/api/admin/tenants/${tenant.value.id}/provision`,
    );
    tenant.value = data.tenant;
    Swal.fire({
      icon: "success",
      title: "Provisioned",
      text: data.message,
      timer: 2500,
      showConfirmButton: false,
    });
  } catch (e: any) {
    Swal.fire({
      title: "Provisioning failed",
      text:
        e.response?.data?.error ??
        e.response?.data?.message ??
        "Something went wrong.",
      icon: "error",
    });
  } finally {
    provisioning.value = false;
  }
};

// ── Create user dialog ────────────────────────────────────────────
const userDialog = ref(false);
const rolesLoading = ref(false);
const roles = ref<{ id: number; name: string }[]>([]);
const creatingUser = ref(false);
const userForm = ref({ name: "", email: "", password: "", role: "" });
const userFormValid = ref(false);

const rules = {
  required: (v: string) => !!v || "Required",
  email: (v: string) => /.+@.+\..+/.test(v) || "Enter a valid email",
  minLen: (v: string) => (v?.length ?? 0) >= 8 || "Minimum 8 characters",
};

const openUserDialog = async () => {
  userDialog.value = true;
  userForm.value = { name: "", email: "", password: "", role: "" };

  if (!roles.value.length) {
    rolesLoading.value = true;
    try {
      const { data } = await axios.get(
        `/api/admin/tenants/${tenant.value.id}/roles`,
      );
      roles.value = data.roles;
    } catch (e: any) {
      Swal.fire({
        title: "Could not load roles",
        text: e.response?.data?.message ?? "Something went wrong.",
        icon: "error",
      });
      userDialog.value = false;
    } finally {
      rolesLoading.value = false;
    }
  }
};

const createUser = async () => {
  creatingUser.value = true;
  try {
    await axios.post(
      `/api/admin/tenants/${tenant.value.id}/users`,
      userForm.value,
    );
    userDialog.value = false;
    Swal.fire({
      icon: "success",
      title: "User created",
      text: `${userForm.value.email} has been added to ${tenant.value.name}.`,
      timer: 2500,
      showConfirmButton: false,
    });
  } catch (e: any) {
    Swal.fire({
      title: "Could not create user",
      text: e.response?.data?.message ?? "Something went wrong.",
      icon: "error",
    });
  } finally {
    creatingUser.value = false;
  }
};

onMounted(fetchTenant);
</script>

<template>
  <VBreadcrumbs
    :items="[
      { title: 'Tenants', to: '/administration/tenants' },
      { title: tenant?.name ?? '…', disabled: true },
    ]"
    class="pa-0 mb-4"
  />

  <div v-if="isLoading" class="d-flex justify-center py-16">
    <VProgressCircular indeterminate color="primary" size="48" />
  </div>

  <VRow v-else-if="tenant">
    <!-- ── Left column: identity + actions ──────────────────────── -->
    <VCol cols="12" md="4" lg="3">
      <div class="d-flex flex-column align-center text-center mb-6">
        <VAvatar size="88" color="primary" class="mb-3">
          <span class="text-h5 font-weight-bold">{{
            initials(tenant.name)
          }}</span>
        </VAvatar>
        <span class="text-h6 font-weight-bold">{{ tenant.name }}</span>
        <span class="text-caption text-medium-emphasis">{{ tenant.slug }}</span>

        <div class="d-flex ga-2 mt-3 flex-wrap justify-center">
          <VChip
            :color="tenant.is_active ? 'success' : 'error'"
            size="small"
            variant="tonal"
          >
            {{ tenant.is_active ? "Active" : "Inactive" }}
          </VChip>
          <VChip
            :color="tierMeta[tenant.subscription_tier]?.color"
            size="small"
            variant="tonal"
            class="text-capitalize"
          >
            {{ tierMeta[tenant.subscription_tier]?.label }}
          </VChip>
          <VChip
            :color="tenant.provisioned_at ? 'success' : 'warning'"
            size="small"
            variant="tonal"
          >
            {{ tenant.provisioned_at ? "Provisioned" : "Not provisioned" }}
          </VChip>
        </div>
      </div>

      <VDivider class="mb-4" />

      <div class="d-flex flex-column ga-2">
        <VBtn
          color="primary"
          block
          prepend-icon="$pencil"
          @click="router.push(`/administration/tenants/${tenant.id}/edit`)"
        >
          Edit tenant
        </VBtn>

        <VBtn
          v-if="!tenant.provisioned_at"
          color="warning"
          block
          prepend-icon="$databaseArrowUp"
          :loading="provisioning"
          @click="runProvision"
        >
          Provision tenant
        </VBtn>
        <VBtn
          v-else
          variant="outlined"
          color="warning"
          block
          prepend-icon="$databaseRefresh"
          :loading="provisioning"
          @click="runProvision"
        >
          Re-run provisioning
        </VBtn>

        <VBtn
          variant="outlined"
          block
          prepend-icon="$accountPlus"
          :disabled="!tenant.provisioned_at"
          @click="openUserDialog"
        >
          Add user
        </VBtn>

        <VBtn
          variant="text"
          block
          prepend-icon="$arrowLeft"
          @click="router.push('/administration/tenants')"
        >
          Back to list
        </VBtn>
      </div>
    </VCol>

    <!-- ── Right column: detail sections ────────────────────────── -->
    <VCol cols="12" md="8" lg="9">
      <!-- Subscription -->
      <p class="text-subtitle-1 font-weight-bold mb-2">Subscription</p>
      <VRow dense class="mb-2">
        <VCol cols="6" sm="3">
          <p class="text-caption text-medium-emphasis mb-1">Status</p>
          <p
            class="text-body-2 mb-0"
            :class="tenant.isSubscriptionActive ? 'text-success' : 'text-error'"
          >
            {{ tenant.isSubscriptionActive ? "Active" : "Inactive" }}
          </p>
        </VCol>
        <VCol cols="6" sm="3">
          <p class="text-caption text-medium-emphasis mb-1">Tier</p>
          <p class="text-body-2 text-capitalize mb-0">
            {{ tierMeta[tenant.subscription_tier]?.label }}
          </p>
        </VCol>
        <VCol cols="6" sm="3">
          <p class="text-caption text-medium-emphasis mb-1">Expires</p>
          <p class="text-body-2 mb-0">
            {{ formatDate(tenant.subscription_expires_at) }}
          </p>
        </VCol>
        <VCol cols="6" sm="3">
          <p class="text-caption text-medium-emphasis mb-1">Created</p>
          <p class="text-body-2 mb-0">{{ formatDate(tenant.created_at) }}</p>
        </VCol>
      </VRow>

      <VDivider class="my-5" />

      <!-- Limits & deployment -->
      <p class="text-subtitle-1 font-weight-bold mb-2">
        Limits &amp; deployment
      </p>
      <VRow dense class="mb-2">
        <VCol cols="6" sm="4">
          <p class="text-caption text-medium-emphasis mb-1">Deployment</p>
          <p class="text-body-2 text-capitalize mb-0">
            {{ tenant.deployment_mode?.replace("_", " ") }}
          </p>
        </VCol>
        <VCol cols="6" sm="4">
          <p class="text-caption text-medium-emphasis mb-1">Max Bots</p>
          <p class="text-body-2 mb-0">{{ tenant.max_bots }}</p>
        </VCol>
        <VCol cols="6" sm="4">
          <p class="text-caption text-medium-emphasis mb-1">Max conv. / mo</p>
          <p class="text-body-2 mb-0">
            {{ tenant.max_conversations_per_month?.toLocaleString() }}
          </p>
        </VCol>
      </VRow>

      <VDivider class="my-5" />

      <!-- Database -->
      <p class="text-subtitle-1 font-weight-bold mb-2">Database</p>
      <VRow dense class="mb-2">
        <VCol cols="12" sm="6">
          <p class="text-caption text-medium-emphasis mb-1">Schema</p>
          <VChip
            size="small"
            variant="tonal"
            color="primary"
            prepend-icon="$databaseCogOutline"
          >
            {{ tenant.db_schema }}
          </VChip>
        </VCol>
        <VCol cols="12" sm="6">
          <p class="text-caption text-medium-emphasis mb-1">Provisioned</p>
          <p class="text-body-2 mb-0">
            {{
              tenant.provisioned_at
                ? formatDate(tenant.provisioned_at)
                : "Not yet"
            }}
          </p>
        </VCol>
      </VRow>

      <VDivider class="my-5" />

      <!-- Domains -->
      <p class="text-subtitle-1 font-weight-bold mb-2">Domains</p>
      <VList
        v-if="tenant.domains?.length"
        density="comfortable"
        class="pa-0 bg-transparent"
      >
        <VListItem v-for="d in tenant.domains" :key="d.id" class="px-0">
          <VListItemTitle>
            <VIcon icon="$web" size="18" class="text-medium-emphasis mr-2" />{{
              d.domain
            }}
          </VListItemTitle>
          <VListItemSubtitle class="text-medium-emphasis">
            {{ d.url ?? undefined }}
          </VListItemSubtitle>

          <template #append>
            <VChip
              size="x-small"
              variant="tonal"
              :color="d.is_primary ? 'primary' : 'default'"
              class="mr-2"
            >
              {{ d.is_primary ? "Primary" : "Secondary" }}
            </VChip>
            <VIcon v-if="d.is_primary" icon="$star" size="16" color="primary" />
          </template>
        </VListItem>
      </VList>
      <p v-else class="text-body-2 text-medium-emphasis">
        No domains configured.
      </p>
    </VCol>
  </VRow>

  <!-- ── Create user dialog ──────────────────────────────────────── -->
  <VDialog v-model="userDialog" max-width="480">
    <VCard rounded="lg">
      <VCardItem>
        <VCardTitle class="text-subtitle-1 font-weight-bold">
          Add user to {{ tenant?.name }}
        </VCardTitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <div v-if="rolesLoading" class="d-flex justify-center py-8">
          <VProgressCircular indeterminate color="primary" />
        </div>
        <VForm v-else v-model="userFormValid" @submit.prevent="createUser">
          <VTextField
            v-model="userForm.name"
            label="Full name"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :rules="[rules.required]"
            prepend-inner-icon="$account"
          />
          <VTextField
            v-model="userForm.email"
            label="Email"
            type="email"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :rules="[rules.required, rules.email]"
            prepend-inner-icon="$email"
          />
          <VTextField
            v-model="userForm.password"
            label="Password"
            type="password"
            variant="outlined"
            density="comfortable"
            class="mb-3"
            :rules="[rules.required, rules.minLen]"
            prepend-inner-icon="$lock"
          />
          <VSelect
            v-model="userForm.role"
            :items="roles"
            item-title="name"
            item-value="name"
            label="Role"
            variant="outlined"
            density="comfortable"
            prepend-inner-icon="$shieldAccount"
            :rules="[rules.required]"
          />
        </VForm>
      </VCardText>
      <VDivider />
      <VCardActions class="pa-4">
        <VSpacer />
        <VBtn variant="text" @click="userDialog = false">Cancel</VBtn>
        <VBtn
          color="primary"
          prepend-icon="$accountPlus"
          :loading="creatingUser"
          :disabled="!userFormValid"
          @click="createUser"
        >
          Create user
        </VBtn>
      </VCardActions>
    </VCard>
  </VDialog>
</template>
