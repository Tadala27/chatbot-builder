<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';

const form = ref({
    name: '',
    email: '',
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
});

const isLoading = ref(true);
const isSaving = ref(false);
const isChangingPassword = ref(false);
const snackbar = ref({ show: false, message: '', color: 'success' });

const fetchProfile = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/me');
        form.value.name = response.data.name;
        form.value.email = response.data.email;
    } catch (error) {
        console.error('Failed to load profile', error);
    } finally {
        isLoading.value = false;
    }
};

const updateProfile = async () => {
    isSaving.value = true;
    try {
        await axios.put('/api/profile', {
            name: form.value.name,
            email: form.value.email,
        });
        snackbar.value = { show: true, message: 'Profile updated successfully', color: 'success' };
    } catch (error: any) {
        snackbar.value = { show: true, message: 'Failed to update profile', color: 'error' };
    } finally {
        isSaving.value = false;
    }
};

const changePassword = async () => {
    if (form.value.new_password !== form.value.new_password_confirmation) {
        snackbar.value = { show: true, message: 'Passwords do not match', color: 'error' };
        return;
    }

    isChangingPassword.value = true;
    try {
        await axios.post('/api/password', {
            current_password: form.value.current_password,
            new_password: form.value.new_password,
            new_password_confirmation: form.value.new_password_confirmation,
        });
        snackbar.value = { show: true, message: 'Password changed successfully', color: 'success' };
        form.value.current_password = '';
        form.value.new_password = '';
        form.value.new_password_confirmation = '';
    } catch (error: any) {
        snackbar.value = { show: true, message: error.response?.data?.message || 'Failed to change password', color: 'error' };
    } finally {
        isChangingPassword.value = false;
    }
};

onMounted(() => { fetchProfile(); });
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <h2 class="text-h4 mb-2">My Profile</h2>
                <p class="text-medium-emphasis">Manage your account settings</p>
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <div v-else>
            <VCard class="mb-6">
                <VCardTitle>Profile Information</VCardTitle>
                <VDivider />
                <VCardText class="pa-6">
                    <VForm @submit.prevent="updateProfile">
                        <VRow>
                            <VCol cols="12" md="6">
                                <VTextField v-model="form.name" label="Full Name" variant="outlined" required />
                            </VCol>
                            <VCol cols="12" md="6">
                                <VTextField v-model="form.email" label="Email" type="email" variant="outlined" required />
                            </VCol>
                        </VRow>
                        <VRow>
                            <VCol cols="12" class="d-flex justify-end">
                                <VBtn type="submit" color="primary" prepend-icon="$contentSave" :loading="isSaving">
                                    Save Changes
                                </VBtn>
                            </VCol>
                        </VRow>
                    </VForm>
                </VCardText>
            </VCard>

            <VCard>
                <VCardTitle>Change Password</VCardTitle>
                <VDivider />
                <VCardText class="pa-6">
                    <VForm @submit.prevent="changePassword">
                        <VRow>
                            <VCol cols="12" md="4">
                                <VTextField v-model="form.current_password" label="Current Password" type="password" variant="outlined" required />
                            </VCol>
                            <VCol cols="12" md="4">
                                <VTextField v-model="form.new_password" label="New Password" type="password" variant="outlined" required />
                            </VCol>
                            <VCol cols="12" md="4">
                                <VTextField v-model="form.new_password_confirmation" label="Confirm New Password" type="password" variant="outlined" required />
                            </VCol>
                        </VRow>
                        <VRow>
                            <VCol cols="12" class="d-flex justify-end">
                                <VBtn type="submit" color="primary" prepend-icon="$lock" :loading="isChangingPassword">
                                    Change Password
                                </VBtn>
                            </VCol>
                        </VRow>
                    </VForm>
                </VCardText>
            </VCard>
        </div>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions><VBtn variant="text" @click="snackbar.show = false">Close</VBtn></template>
        </VSnackbar>
    </div>
</template>
