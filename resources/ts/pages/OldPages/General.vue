<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';

const form = ref({
    company_name: '',
    timezone: 'Africa/Blantyre',
    locale: 'en',
    date_format: 'YYYY-MM-DD',
    time_format: '24h',
    notifications_email: true,
    notifications_sms: false,
});

const isLoading = ref(true);
const isSaving = ref(false);
const snackbar = ref({ show: false, message: '', color: 'success' });

const timezones = [
    'Africa/Blantyre', 'Africa/Johannesburg', 'Africa/Nairobi', 'Africa/Lagos',
    'Europe/London', 'Europe/Paris', 'America/New_York', 'America/Los_Angeles',
    'Asia/Dubai', 'Asia/Singapore', 'Australia/Sydney'
];

const locales = [
    { title: 'English', value: 'en' },
    { title: 'French', value: 'fr' },
    { title: 'Portuguese', value: 'pt' },
];

const fetchSettings = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/settings');
        Object.assign(form.value, response.data.settings || {});
    } catch (error) {
        console.error('Failed to load settings', error);
    } finally {
        isLoading.value = false;
    }
};

const saveSettings = async () => {
    isSaving.value = true;
    try {
        await axios.put('/api/settings', form.value);
        snackbar.value = { show: true, message: 'Settings saved successfully', color: 'success' };
    } catch (error: any) {
        snackbar.value = { show: true, message: 'Failed to save settings', color: 'error' };
    } finally {
        isSaving.value = false;
    }
};

onMounted(() => { fetchSettings(); });
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <h2 class="text-h4 mb-2">General Settings</h2>
                <p class="text-medium-emphasis">Configure your account preferences</p>
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <VCard v-else>
            <VCardText class="pa-6">
                <VForm @submit.prevent="saveSettings">
                    <VRow>
                        <VCol cols="12"><h3 class="text-h6 mb-4">Organization</h3></VCol>
                        <VCol cols="12" md="6">
                            <VTextField v-model="form.company_name" label="Company Name" variant="outlined" />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12"><h3 class="text-h6 mb-4">Localization</h3></VCol>
                        <VCol cols="12" md="6">
                            <VSelect v-model="form.timezone" :items="timezones" label="Timezone" variant="outlined" />
                        </VCol>
                        <VCol cols="12" md="6">
                            <VSelect v-model="form.locale" :items="locales" item-title="title" item-value="value" label="Language" variant="outlined" />
                        </VCol>
                        <VCol cols="12" md="6">
                            <VSelect v-model="form.date_format" :items="['YYYY-MM-DD', 'DD/MM/YYYY', 'MM/DD/YYYY']" label="Date Format" variant="outlined" />
                        </VCol>
                        <VCol cols="12" md="6">
                            <VSelect v-model="form.time_format" :items="['12h', '24h']" label="Time Format" variant="outlined" />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12"><h3 class="text-h6 mb-4">Notifications</h3></VCol>
                        <VCol cols="12">
                            <VCheckbox v-model="form.notifications_email" label="Email Notifications" hide-details />
                            <VCheckbox v-model="form.notifications_sms" label="SMS Notifications" hide-details class="mt-2" />
                        </VCol>
                    </VRow>

                    <VDivider class="my-6" />

                    <VRow>
                        <VCol cols="12" class="d-flex justify-end">
                            <VBtn type="submit" color="primary" size="large" prepend-icon="$contentSave" :loading="isSaving">
                                Save Settings
                            </VBtn>
                        </VCol>
                    </VRow>
                </VForm>
            </VCardText>
        </VCard>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions><VBtn variant="text" @click="snackbar.show = false">Close</VBtn></template>
        </VSnackbar>
    </div>
</template>
