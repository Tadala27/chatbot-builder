<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const router = useRouter();
const isSaving = ref(false);

const form = ref({
  name: "",
  email: "",
  password: "",
  password_confirmation: "",
  is_active: true,
  roles: [] as string[],
});

const availableRoles = ref<string[]>([]);
const loadingRoles = ref(true);

const fetchRoles = async () => {
  loadingRoles.value = true;
  try {
    // Adjust endpoint if you have a dedicated roles list; falling back to a
    // static set matching common admin roles if no endpoint is available.
    const { data } = await axios.get("/api/admin/roles").catch(() => ({ data: { data: null } }));
    availableRoles.value = data.data ?? ["super-admin", "platform-support", "billing-admin"];
  } finally {
    loadingRoles.value = false;
  }
};

const errors = ref<Record<string, string>>({});

const validate = (): boolean => {
  errors.value = {};
  if (!form.value.name) errors.value.name = "Name is required.";
  if (!form.value.email) errors.value.email = "Email is required.";
  if (!form.value.password) errors.value.password = "Password is required.";
  if (form.value.password !== form.value.password_confirmation) {
    errors.value.password_confirmation = "Passwords do not match.";
  }
  return Object.keys(errors.value).length === 0;
};

const submit = async () => {
  if (!validate()) return;

  isSaving.value = true;
  try {
    const { data } = await axios.post("/api/admin/users", form.value);

    if (form.value.roles.length) {
      await axios.put(`/api/admin/users/${data.user?.id ?? data.data?.id}/roles`, {
        roles: form.value.roles,
      });
    }

    await Swal.fire({ title: "Success", text: "Admin user created.", icon: "success" });
    router.push("/administration/users");
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) {
      errors.value = Object.fromEntries(Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]));
    }
    Swal.fire({ title: "Error", text: err.response?.data?.message ?? "Failed to create admin user.", icon: "error" });
  } finally {
    isSaving.value = false;
  }
};

onMounted(fetchRoles);
</script>

<template>
  <VCard elevation="0" variant="outlined">
    <VCardTitle class="d-flex align-center pa-6 bg-primary text-white">
      <VIcon icon="mdi-account-plus" size="28" class="mr-3" />
      <span class="text-h5">Add Admin User</span>
    </VCardTitle>
    <VDivider />

    <VCardText class="pa-6">
      <VRow>
        <VCol cols="12" md="6">
          <VTextField v-model="form.name" label="Full Name *" variant="outlined" density="comfortable" :error-messages="errors.name" />
        </VCol>
        <VCol cols="12" md="6">
          <VTextField v-model="form.email" label="Email *" type="email" variant="outlined" density="comfortable" :error-messages="errors.email" />
        </VCol>
        <VCol cols="12" md="6">
          <VTextField v-model="form.password" label="Password *" type="password" variant="outlined" density="comfortable" autocomplete="new-password" :error-messages="errors.password" />
        </VCol>
        <VCol cols="12" md="6">
          <VTextField v-model="form.password_confirmation" label="Confirm Password *" type="password" variant="outlined" density="comfortable" autocomplete="new-password" :error-messages="errors.password_confirmation" />
        </VCol>
        <VCol cols="12" md="6">
          <VSelect
            v-model="form.roles"
            :items="availableRoles"
            label="Roles"
            variant="outlined"
            density="comfortable"
            multiple
            chips
            :loading="loadingRoles"
            hint="Determines what this admin can manage."
            persistent-hint
          />
        </VCol>
        <VCol cols="12" md="6">
          <VSwitch v-model="form.is_active" label="Active" color="success" />
        </VCol>
      </VRow>
    </VCardText>

    <VDivider />
    <VCardActions class="pa-6">
      <VBtn variant="text" color="error" @click="router.push('/administration/users')">Cancel</VBtn>
      <VSpacer />
      <VBtn color="success" prepend-icon="mdi-check" :loading="isSaving" @click="submit">Create Admin</VBtn>
    </VCardActions>
  </VCard>
</template>
