<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import moment from 'moment';

const router = useRouter();
const templates = ref([]);
const isLoading = ref(true);
const searchQuery = ref('');
const categoryFilter = ref('all');
const statusFilter = ref('all');
const snackbar = ref({ show: false, message: '', color: 'success' });

const categories = [
    { title: 'All', value: 'all' },
    { title: 'Marketing', value: 'marketing' },
    { title: 'Utility', value: 'utility' },
    { title: 'Authentication', value: 'authentication' },
];

const statuses = [
    { title: 'All', value: 'all' },
    { title: 'Approved', value: 'approved' },
    { title: 'Pending', value: 'pending' },
    { title: 'Rejected', value: 'rejected' },
];

const filteredTemplates = computed(() => {
    let filtered = templates.value;
    if (categoryFilter.value !== 'all') {
        filtered = filtered.filter((t: any) => t.category === categoryFilter.value);
    }
    if (statusFilter.value !== 'all') {
        filtered = filtered.filter((t: any) => t.status === statusFilter.value);
    }
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((t: any) => t.name?.toLowerCase().includes(query));
    }
    return filtered;
});

const fetchTemplates = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/templates');
        templates.value = response.data.data || [];
    } catch (error: any) {
        snackbar.value = { show: true, message: 'Failed to load templates', color: 'error' };
    } finally {
        isLoading.value = false;
    }
};

const deleteTemplate = async (template: any) => {
    if (!confirm(`Delete template "${template.name}"?`)) return;
    try {
        await axios.delete(`/api/templates/${template.id}`);
        snackbar.value = { show: true, message: 'Template deleted', color: 'success' };
        fetchTemplates();
    } catch (error: any) {
        snackbar.value = { show: true, message: 'Failed to delete template', color: 'error' };
    }
};

const getStatusColor = (status: string) => {
    return status === 'approved' ? 'success' : status === 'pending' ? 'warning' : 'error';
};

onMounted(() => { fetchTemplates(); });
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-h4 mb-2">Message Templates</h2>
                        <p class="text-medium-emphasis">WhatsApp message templates</p>
                    </div>
                    <VBtn color="primary" prepend-icon="$plus" to="/templates/create">Create Template</VBtn>
                </div>
            </VCol>
        </VRow>

        <VRow class="mb-4">
            <VCol cols="12" md="4">
                <VTextField v-model="searchQuery" placeholder="Search templates..." variant="outlined" prepend-inner-icon="$magnify" clearable hide-details />
            </VCol>
            <VCol cols="12" md="4">
                <VSelect v-model="categoryFilter" :items="categories" item-title="title" item-value="value" variant="outlined" label="Category" hide-details />
            </VCol>
            <VCol cols="12" md="4">
                <VSelect v-model="statusFilter" :items="statuses" item-title="title" item-value="value" variant="outlined" label="Status" hide-details />
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <VRow v-else-if="filteredTemplates.length === 0" justify="center">
            <VCol cols="12" md="8">
                <VCard class="pa-8 text-center" variant="outlined">
                    <VIcon icon="$fileDocument" size="80" color="primary" class="mb-4" />
                    <h3 class="text-h5 mb-2">No Templates Found</h3>
                    <p class="text-medium-emphasis mb-6">
                        {{ searchQuery ? 'Try adjusting your search' : 'Create WhatsApp message templates' }}
                    </p>
                    <VBtn v-if="!searchQuery" color="primary" size="large" to="/templates/create">Create Your First Template</VBtn>
                </VCard>
            </VCol>
        </VRow>

        <VRow v-else>
            <VCol v-for="template in filteredTemplates" :key="template.id" cols="12" md="6" lg="4">
                <VCard elevation="2" hover>
                    <VCardTitle class="d-flex align-center justify-space-between">
                        <span class="text-truncate">{{ template.name }}</span>
                        <VChip :color="getStatusColor(template.status)" size="small">{{ template.status }}</VChip>
                    </VCardTitle>
                    <VDivider />
                    <VCardText>
                        <VChip size="small" class="mb-2">{{ template.category }}</VChip>
                        <p class="text-caption text-medium-emphasis">{{ template.language || 'en' }}</p>
                        <div class="text-caption mt-2">Updated {{ moment(template.updated_at).fromNow() }}</div>
                    </VCardText>
                    <VDivider />
                    <VCardActions class="pa-4">
                        <VBtn variant="text" size="small" prepend-icon="$eye" :to="`/templates/${template.id}`">View</VBtn>
                        <VSpacer />
                        <VMenu>
                            <template #activator="{ props }">
                                <VBtn v-bind="props" variant="text" size="small" icon="$dotsVertical" />
                            </template>
                            <VList>
                                <VListItem prepend-icon="$pencil" :to="`/templates/${template.id}/edit`">Edit</VListItem>
                                <VDivider />
                                <VListItem prepend-icon="$delete" class="text-error" @click="deleteTemplate(template)">Delete</VListItem>
                            </VList>
                        </VMenu>
                    </VCardActions>
                </VCard>
            </VCol>
        </VRow>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions><VBtn variant="text" @click="snackbar.show = false">Close</VBtn></template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.gap-3 { gap: 12px; }
</style>
