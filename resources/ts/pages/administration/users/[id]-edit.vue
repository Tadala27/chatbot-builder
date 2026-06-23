<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const isSaving = ref(false);
const savingRoles = ref(false);

const form = ref({ name: "", email: "", is_active: true });
const selectedRoles = ref<string[]>([]);
const availableRoles = ref<string[]>(["super-admin", "platform-support", "billing-admin"]);

const errors = ref<Record<string, string>>({});

const fetchUser = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get(`/api/admin/users/${route.params.id}`);
    const u = data.data ?? data;
    form.value = { name: u.name, email: u.email, is_active: u.is_active };
    selectedRoles.value = u.roles ?? [];
  } finally {
    isLoading.value = false;
  }
};

const saveDetails = async () => {
  errors.value = {};
  isSaving.value = true;
  try {
    await axios.put(`/api/admin/users/${route.params.id}`, form.value);
    Swal.fire({ title: "Saved", text: "Admin details updated.", icon: "success", timer: 1500, showConfirmButton: false });
  } catch (err: any) {
    const serverErrors = err.response?.data?.errors;
    if (serverErrors) errors.value = Object.fromEntries(Object.entries(serverErrors).map(([k, v]) => [k, (v as string[])[0]]));
    Swal.fire({ title: "Error", text: err.response?.data?.message ?? "Failed to update.", icon: "error" });
  } finally {
    isSaving.value = false;
  }
};

const saveRoles = async () => {
  savingRoles.value = true;
  try {
    await axios.put(`/api/admin/users/${route.params.id}/roles`, { roles: selectedRoles.value });
    Swal.fire({ title: "Saved", text: "Roles updated.", icon: "success", timer: 1500, showConfirmButton: false });
  } catch (err: any) {
    Swal.fire({ title: "Error", text: err.response?.data?.message ?? "Failed to update roles.", icon: "error" });
  } finally {
    savingRoles.value = false;
  }
};

onMounted(fetchUser);
</script>

<template>
  <div>
    <VBtn variant="text" prepend-icon="mdi-arrow-left" class="mb-4" @click="router.push('/administration/users')">Back</VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <VCol cols="12" md="6">
        <VCard variant="outlined" elevation="0">
          <VCardTitle>Edit Details</VCardTitle>
          <VDivider />
          <VCardText>
            <VTextField v-model="form.name" label="Full Name" variant="outlined" density="comfortable" class="mb-4" :error-messages="errors.name" />
            <VTextField v-model="form.email" label="Email" type="email" variant="outlined" density="comfortable" class="mb-4" :error-messages="errors.email" />
            <VSwitch v-model="form.is_active" label="Active" color="success" />
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn color="primary" :loading="isSaving" @click="saveDetails">Save Details</VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <VCol cols="12" md="6">
        <VCard variant="outlined" elevation="0">
          <VCardTitle>Assign Roles</VCardTitle>
          <VDivider />
          <VCardText>
            <VSelect v-model="selectedRoles" :items="availableRoles" label="Roles" variant="outlined" multiple chips class="text-capitalize" />
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn color="primary" :loading="savingRoles" @click="saveRoles">Save Roles</VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
