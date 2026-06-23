<script setup lang="ts">
import { ref, reactive, onMounted } from "vue";
import axios from "axios";
import { useUserStore } from "@/stores/user";

const userStore = useUserStore();

const loading = ref(true);
const saving = ref(false);
const savingPassword = ref(false);

const snackbar = ref({ show: false, message: "", color: "success" });
const notify = (message: string, color = "success") => {
  snackbar.value = { show: true, message, color };
};

const profile = reactive({
  name: "",
  email: "",
  timezone: "",
  locale: "",
  avatar: null as string | null,
});

const passwordForm = reactive({
  current_password: "",
  password: "",
  password_confirmation: "",
});

const showPassword = ref(false);

const fetchProfile = async () => {
  loading.value = true;
  try {
    const url = userStore.isSystemUser ? "/api/admin/auth/profile" : "/api/auth/profile";
    const { data } = await axios.get(url);
    const u = data.data ?? data.user ?? data;
    profile.name = u.name;
    profile.email = u.email;
    profile.timezone = u.timezone ?? "";
    profile.locale = u.locale ?? "";
    profile.avatar = u.avatar ?? null;
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to load profile", "error");
  } finally {
    loading.value = false;
  }
};

const saveProfile = async () => {
  saving.value = true;
  try {
    const url = userStore.isSystemUser ? "/api/admin/auth/profile" : "/api/auth/profile";
    await axios.put(url, {
      name: profile.name,
      timezone: profile.timezone,
      locale: profile.locale,
    });
    userStore.updateUserData({ name: profile.name });
    notify("Profile updated successfully");
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to update profile", "error");
  } finally {
    saving.value = false;
  }
};

const updatePassword = async () => {
  if (passwordForm.password !== passwordForm.password_confirmation) {
    notify("Passwords do not match", "error");
    return;
  }

  savingPassword.value = true;
  try {
    const url = userStore.isSystemUser ? "/api/admin/auth/password" : "/api/auth/password";
    await axios.put(url, passwordForm);
    notify("Password updated successfully");
    passwordForm.current_password = "";
    passwordForm.password = "";
    passwordForm.password_confirmation = "";
  } catch (e: any) {
    notify(e.response?.data?.message ?? "Failed to update password", "error");
  } finally {
    savingPassword.value = false;
  }
};

onMounted(fetchProfile);
</script>

<template>
  <div>
    <div class="mb-6">
      <h1 class="text-h4">My Profile</h1>
      <p class="text-subtitle-1 text-medium-emphasis">Manage your account details and security</p>
    </div>

    <div v-if="loading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <!-- Profile details -->
      <VCol cols="12" md="6">
        <VCard variant="outlined" elevation="0">
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="mdi-account-outline" />
            Account Details
          </VCardTitle>
          <VDivider />
          <VCardText>
            <div class="d-flex align-center gap-4 mb-6">
              <VAvatar size="64" color="primary" variant="tonal">
                <span class="text-h6">{{ (profile.name || "?")[0]?.toUpperCase() }}</span>
              </VAvatar>
              <div>
                <p class="text-subtitle-1 font-weight-medium mb-0">{{ profile.name }}</p>
                <p class="text-caption text-medium-emphasis mb-0">{{ profile.email }}</p>
              </div>
            </div>

            <VTextField v-model="profile.name" label="Full Name" variant="outlined" density="comfortable" class="mb-4" />
            <VTextField :model-value="profile.email" label="Email Address" variant="outlined" density="comfortable" class="mb-4" disabled hint="Email cannot be changed here" persistent-hint />
            <VTextField v-model="profile.timezone" label="Timezone" variant="outlined" density="comfortable" class="mb-4" placeholder="Africa/Blantyre" />
            <VTextField v-model="profile.locale" label="Locale" variant="outlined" density="comfortable" placeholder="en" />
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn color="primary" :loading="saving" @click="saveProfile">Save Changes</VBtn>
          </VCardActions>
        </VCard>
      </VCol>

      <!-- Password -->
      <VCol cols="12" md="6">
        <VCard variant="outlined" elevation="0">
          <VCardTitle class="d-flex align-center gap-2">
            <VIcon icon="mdi-lock-outline" />
            Change Password
          </VCardTitle>
          <VDivider />
          <VCardText>
            <VTextField
              v-model="passwordForm.current_password"
              :type="showPassword ? 'text' : 'password'"
              label="Current Password"
              variant="outlined"
              density="comfortable"
              class="mb-4"
              autocomplete="current-password"
            />
            <VTextField
              v-model="passwordForm.password"
              :type="showPassword ? 'text' : 'password'"
              label="New Password"
              variant="outlined"
              density="comfortable"
              class="mb-4"
              autocomplete="new-password"
            />
            <VTextField
              v-model="passwordForm.password_confirmation"
              :type="showPassword ? 'text' : 'password'"
              label="Confirm New Password"
              variant="outlined"
              density="comfortable"
              autocomplete="new-password"
            >
              <template #append-inner>
                <VIcon :icon="showPassword ? 'mdi-eye-off' : 'mdi-eye'" class="cursor-pointer" @click="showPassword = !showPassword" />
              </template>
            </VTextField>
          </VCardText>
          <VDivider />
          <VCardActions class="pa-4">
            <VSpacer />
            <VBtn
              color="primary"
              :loading="savingPassword"
              :disabled="!passwordForm.current_password || !passwordForm.password"
              @click="updatePassword"
            >
              Update Password
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>

    <VSnackbar v-model="snackbar.show" :color="snackbar.color" location="top right" timeout="4000">
      {{ snackbar.message }}
    </VSnackbar>
  </div>
</template>
