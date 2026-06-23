<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";

const router = useRouter();

interface AdminUser {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  roles: string[];
  last_login: string | null;
  created_at: string;
}

const isLoading = ref(true);
const loading = ref(false);
const users = ref<AdminUser[]>([]);
const search = ref("");
const page = ref(1);
const perPage = ref(20);

const meta = ref({ current_page: 1, from: 0, to: 0, total: 0, last_page: 1 });
const totalPages = computed(() => meta.value.last_page || 1);

const snackbar = ref({ show: false, message: "", color: "success" });
const notify = (message: string, color = "success") => { snackbar.value = { show: true, message, color }; };

const getInitials = (name: string) => {
  const parts = name.trim().split(" ");
  return parts.length >= 2 ? (parts[0][0] + parts[1][0]).toUpperCase() : name.substring(0, 2).toUpperCase();
};

const formatDate = (d: string | null) =>
  d ? new Date(d).toLocaleDateString("en-GB", { day: "2-digit", month: "short", year: "numeric" }) : "Never";

const fetchUsers = async () => {
  loading.value = true;
  try {
    const { data } = await axios.get("/api/admin/users", {
      params: { page: page.value, per_page: perPage.value, search: search.value || undefined },
    });
    users.value = data.data ?? [];
    meta.value = {
      current_page: data.current_page,
      from: data.from ?? 0,
      to: data.to ?? 0,
      total: data.total,
      last_page: data.last_page,
    };
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to load admin users", "error");
  } finally {
    isLoading.value = false;
    loading.value = false;
  }
};

const deleteUser = async (user: AdminUser) => {
  const { isConfirmed } = await Swal.fire({
    title: "Delete Admin User",
    text: `Remove ${user.name} from the platform? This cannot be undone.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    confirmButtonText: "Yes, delete",
  });
  if (!isConfirmed) return;

  try {
    await axios.delete(`/api/admin/users/${user.id}`);
    notify("Admin user deleted");
    fetchUsers();
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to delete user", "error");
  }
};

watchDebounced(search, () => { page.value = 1; fetchUsers(); }, { debounce: 400 });
watchDebounced(page, () => fetchUsers(), { debounce: 0 });

onMounted(fetchUsers);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Admin Users</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Manage central / landlord administrator accounts</p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="router.push('/administration/users/create')">
        Add Admin
      </VBtn>
    </div>

    <VTextField
      v-model="search"
      label="Search by name or email…"
      prepend-inner-icon="mdi-magnify"
      variant="outlined"
      clearable
      hide-details
      density="comfortable"
      class="mb-4"
      :loading="loading"
    />

    <VCard variant="outlined" elevation="0">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>

      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th class="text-left pa-4">Admin</th>
            <th class="text-left pa-4">Roles</th>
            <th class="text-left pa-4">Status</th>
            <th class="text-left pa-4">Last Login</th>
            <th class="text-center pa-4">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!users.length">
            <td colspan="5" class="text-center py-12 text-grey">No admin users found.</td>
          </tr>
          <tr v-for="user in users" :key="user.id">
            <td class="pa-4">
              <div class="d-flex align-center gap-3">
                <VAvatar color="primary" variant="tonal" size="40">
                  <span class="text-subtitle-2">{{ getInitials(user.name) }}</span>
                </VAvatar>
                <div>
                  <p class="text-subtitle-2 font-weight-medium mb-0">{{ user.name }}</p>
                  <p class="text-caption text-medium-emphasis mb-0">{{ user.email }}</p>
                </div>
              </div>
            </td>
            <td class="pa-4">
              <VChip v-for="role in user.roles" :key="role" size="x-small" class="mr-1 text-capitalize" variant="tonal" color="primary">
                {{ role }}
              </VChip>
            </td>
            <td class="pa-4">
              <VChip :color="user.is_active ? 'success' : 'error'" size="small" variant="tonal">
                {{ user.is_active ? "Active" : "Inactive" }}
              </VChip>
            </td>
            <td class="pa-4 text-caption text-medium-emphasis">{{ formatDate(user.last_login) }}</td>
            <td class="pa-4 text-center">
              <VMenu>
                <template #activator="{ props }">
                  <VBtn v-bind="props" icon variant="text" size="small"><VIcon>mdi-dots-vertical</VIcon></VBtn>
                </template>
                <VList density="compact">
                  <VListItem @click="router.push(`/administration/users/${user.id}`)">
                    <template #prepend><VIcon size="small">mdi-eye</VIcon></template>
                    <VListItemTitle>View</VListItemTitle>
                  </VListItem>
                  <VListItem @click="router.push(`/administration/users/${user.id}/edit`)">
                    <template #prepend><VIcon size="small">mdi-pencil</VIcon></template>
                    <VListItemTitle>Edit / Assign Roles</VListItemTitle>
                  </VListItem>
                  <VDivider />
                  <VListItem class="text-error" @click="deleteUser(user)">
                    <template #prepend><VIcon size="small">mdi-trash-can</VIcon></template>
                    <VListItemTitle>Delete</VListItemTitle>
                  </VListItem>
                </VList>
              </VMenu>
            </td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-if="users.length" class="pt-4">
        <VRow class="align-center" justify="space-between">
          <VCol cols="12" sm="6">
            <span class="text-medium-emphasis">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span>
          </VCol>
          <VCol cols="12" sm="6" class="d-flex justify-end">
            <VPagination v-model="page" :length="totalPages" density="comfortable" color="primary" />
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" location="top right" timeout="4000">
      {{ snackbar.message }}
    </VSnackbar>
  </div>
</template>
