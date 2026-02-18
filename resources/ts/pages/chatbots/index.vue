<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import moment from 'moment';

const router = useRouter();

const flows = ref([]);
const isLoading = ref(true);
const searchQuery = ref('');
const statusFilter = ref('all');
const snackbar = ref({ show: false, message: '', color: 'success' });

const statusOptions = [
    { title: 'All', value: 'all' },
    { title: 'Published', value: 'published' },
    { title: 'Draft', value: 'draft' },
    { title: 'Archived', value: 'archived' },
];

const filteredFlows = computed(() => {
    let filtered = flows.value;

    // Filter by status
    if (statusFilter.value !== 'all') {
        filtered = filtered.filter((flow: any) => flow.status === statusFilter.value);
    }

    // Filter by search
    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((flow: any) => 
            flow.name.toLowerCase().includes(query) ||
            flow.description?.toLowerCase().includes(query)
        );
    }

    return filtered;
});

const fetchFlows = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/flows');
        flows.value = response.data.data || response.data.flows || [];
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to load flows',
            color: 'error',
        };
    } finally {
        isLoading.value = false;
    }
};

const getStatusColor = (status: string) => {
    return status === 'published' ? 'success' : 
           status === 'draft' ? 'warning' : 'error';
};

const publish = async (flow: any) => {
    try {
        await axios.post(`/api/flows/${flow.id}/publish`);
        snackbar.value = {
            show: true,
            message: 'Flow published successfully!',
            color: 'success',
        };
        fetchFlows();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to publish flow',
            color: 'error',
        };
    }
};

const unpublish = async (flow: any) => {
    try {
        await axios.post(`/api/flows/${flow.id}/unpublish`);
        snackbar.value = {
            show: true,
            message: 'Flow unpublished successfully!',
            color: 'success',
        };
        fetchFlows();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to unpublish flow',
            color: 'error',
        };
    }
};

const deleteFlow = async (flow: any) => {
    if (!confirm(`Are you sure you want to delete "${flow.name}"?`)) {
        return;
    }

    try {
        await axios.delete(`/api/flows/${flow.id}`);
        snackbar.value = {
            show: true,
            message: 'Flow deleted successfully!',
            color: 'success',
        };
        fetchFlows();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to delete flow',
            color: 'error',
        };
    }
};

const duplicateFlow = async (flow: any) => {
    try {
        const response = await axios.post(`/api/flows/${flow.id}/duplicate`);
        snackbar.value = {
            show: true,
            message: 'Flow duplicated successfully!',
            color: 'success',
        };
        fetchFlows();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to duplicate flow',
            color: 'error',
        };
    }
};

onMounted(() => {
    fetchFlows();
});
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-h4 mb-2">Flows</h2>
                        <p class="text-medium-emphasis">
                            Manage your WhatsApp conversation flows
                        </p>
                    </div>
                    <VBtn
                        color="primary"
                        prepend-icon="mdi-plus"
                        size="large"
                        to="/flows/create"
                    >
                        Create Flow
                    </VBtn>
                </div>
            </VCol>
        </VRow>

        <!-- Filters -->
        <VRow class="mb-4">
            <VCol cols="12" md="6">
                <VTextField
                    v-model="searchQuery"
                    placeholder="Search flows..."
                    variant="outlined"
                    prepend-inner-icon="mdi-magnify"
                    clearable
                    hide-details
                />
            </VCol>
            <VCol cols="12" md="3">
                <VSelect
                    v-model="statusFilter"
                    :items="statusOptions"
                    item-title="title"
                    item-value="value"
                    variant="outlined"
                    hide-details
                />
            </VCol>
        </VRow>

        <!-- Loading State -->
        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto">
                <VProgressCircular indeterminate color="primary" size="64" />
            </VCol>
        </VRow>

        <!-- Empty State -->
        <VRow v-else-if="filteredFlows.length === 0" justify="center">
            <VCol cols="12" md="8" lg="6">
                <VCard class="pa-8 text-center" variant="outlined">
                    <VIcon icon="mdi-sitemap" size="80" color="primary" class="mb-4" />
                    <h3 class="text-h5 mb-2">No Flows Found</h3>
                    <p class="text-medium-emphasis mb-6">
                        {{ searchQuery ? 'Try adjusting your search' : 'Create your first flow to get started' }}
                    </p>
                    <VBtn
                        v-if="!searchQuery"
                        color="primary"
                        size="large"
                        prepend-icon="mdi-plus"
                        to="/flows/create"
                    >
                        Create Your First Flow
                    </VBtn>
                </VCard>
            </VCol>
        </VRow>

        <!-- Flows Grid -->
        <VRow v-else>
            <VCol v-for="flow in filteredFlows" :key="flow.id" cols="12" md="6" lg="4">
                <VCard elevation="2" hover>
                    <VCardTitle class="d-flex align-center justify-space-between">
                        <div class="d-flex align-center">
                            <VIcon icon="mdi-sitemap" color="primary" size="24" class="mr-2" />
                            <span class="text-truncate">{{ flow.name }}</span>
                        </div>
                        <VChip :color="getStatusColor(flow.status)" size="small">
                            {{ flow.status }}
                        </VChip>
                    </VCardTitle>

                    <VDivider />

                    <VCardText>
                        <div class="mb-4" style="min-height: 60px;">
                            <div v-html="flow.description" class="text-caption text-medium-emphasis line-clamp-3"></div>
                        </div>

                        <VRow dense class="text-caption">
                            <VCol cols="6">
                                <div class="d-flex align-center">
                                    <VIcon icon="mdi-whatsapp" size="16" color="success" class="mr-1" />
                                    <span>{{ flow.whatsapp_account?.display_phone_number || 'N/A' }}</span>
                                </div>
                            </VCol>
                            <VCol cols="6">
                                <div class="d-flex align-center">
                                    <VIcon icon="mdi-message-text-outline" size="16" color="info" class="mr-1" />
                                    <span>{{ flow.stats?.total_conversations || 0 }} conversations</span>
                                </div>
                            </VCol>
                            <VCol cols="6" class="mt-2">
                                <div class="d-flex align-center">
                                    <VIcon icon="mdi-layers-triple-outline" size="16" color="warning" class="mr-1" />
                                    <span>v{{ flow.current_version?.version_number || 1 }}</span>
                                </div>
                            </VCol>
                            <VCol cols="12" class="mt-2">
                                <div class="text-disabled">
                                    Updated {{ moment(flow.updated_at).fromNow() }}
                                </div>
                            </VCol>
                        </VRow>
                    </VCardText>

                    <VDivider />

                    <VCardActions class="pa-4">
                        <VBtn
                            variant="text"
                            size="small"
                            prepend-icon="mdi-pencil"
                            :to="`/flows/${flow.id}/builder`"
                        >
                            Edit Flow
                        </VBtn>
                        <VSpacer />
                        <VMenu>
                            <template #activator="{ props }">
                                <VBtn
                                    v-bind="props"
                                    variant="text"
                                    size="small"
                                    icon="mdi-dots-vertical"
                                />
                            </template>
                            <VList>
                                <VListItem
                                    prepend-icon="mdi-eye"
                                    :to="`/flows/${flow.id}`"
                                >
                                    View Details
                                </VListItem>
                                <VListItem
                                    prepend-icon="mdi-chart-line"
                                    :to="`/flows/${flow.id}/analytics`"
                                >
                                    Analytics
                                </VListItem>
                                <VDivider />
                                <VListItem
                                    v-if="flow.status === 'draft'"
                                    prepend-icon="mdi-publish"
                                    @click="publish(flow)"
                                >
                                    Publish
                                </VListItem>
                                <VListItem
                                    v-if="flow.status === 'published'"
                                    prepend-icon="mdi-cloud-off-outline"
                                    @click="unpublish(flow)"
                                >
                                    Unpublish
                                </VListItem>
                                <VListItem
                                    prepend-icon="mdi-content-copy"
                                    @click="duplicateFlow(flow)"
                                >
                                    Duplicate
                                </VListItem>
                                <VDivider />
                                <VListItem
                                    prepend-icon="mdi-delete"
                                    @click="deleteFlow(flow)"
                                    class="text-error"
                                >
                                    Delete
                                </VListItem>
                            </VList>
                        </VMenu>
                    </VCardActions>
                </VCard>
            </VCol>
        </VRow>

        <!-- Snackbar -->
        <VSnackbar
            v-model="snackbar.show"
            :color="snackbar.color"
            :timeout="4000"
            location="top right"
        >
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.gap-3 {
    gap: 12px;
}
</style>