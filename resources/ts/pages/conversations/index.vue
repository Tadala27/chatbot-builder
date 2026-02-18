<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';
import moment from 'moment';

const router = useRouter();

const conversations = ref([]);
const isLoading = ref(true);
const searchQuery = ref('');
const statusFilter = ref('all');
const chatbotFilter = ref('all');
const chatbots = ref([]);
const snackbar = ref({ show: false, message: '', color: 'success' });

// Pagination
const page = ref(1);
const perPage = ref(20);
const total = ref(0);

const statusOptions = [
    { title: 'All', value: 'all' },
    { title: 'Active', value: 'active' },
    { title: 'Completed', value: 'completed' },
    { title: 'Handed Off', value: 'handed_off' },
    { title: 'Abandoned', value: 'abandoned' },
];

const filteredConversations = computed(() => {
    let filtered = conversations.value;

    if (statusFilter.value !== 'all') {
        filtered = filtered.filter((conv: any) => conv.status === statusFilter.value);
    }

    if (chatbotFilter.value !== 'all') {
        filtered = filtered.filter((conv: any) => conv.chatbot_id === chatbotFilter.value);
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        filtered = filtered.filter((conv: any) => 
            conv.whatsapp_user_phone?.toLowerCase().includes(query) ||
            conv.whatsapp_user_name?.toLowerCase().includes(query) ||
            conv.chatbot?.name?.toLowerCase().includes(query)
        );
    }

    return filtered;
});

const fetchConversations = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/conversations', {
            params: {
                page: page.value,
                per_page: perPage.value,
                status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
                chatbot_id: chatbotFilter.value !== 'all' ? chatbotFilter.value : undefined,
            }
        });

        conversations.value = response.data.data;
        total.value = response.data.total;
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to load conversations',
            color: 'error',
        };
    } finally {
        isLoading.value = false;
    }
};

const fetchChatbots = async () => {
    try {
        const response = await axios.get('/api/chatbots');
        chatbots.value = [
            { id: 'all', name: 'All Chatbots' },
            ...response.data.data
        ];
    } catch (error) {
        console.error('Failed to load chatbots', error);
    }
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'success',
        completed: 'primary',
        handed_off: 'warning',
        abandoned: 'error',
    };
    return colors[status] || 'grey';
};

const getStatusIcon = (status: string) => {
    const icons: Record<string, string> = {
        active: '$messageText',
        completed: '$checkCircle',
        handed_off: '$accountSwitch',
        abandoned: '$closeCircle',
    };
    return icons[status] || '$message';
};

const formatDuration = (duration: number | null) => {
    if (!duration) return 'N/A';
    
    const hours = Math.floor(duration / 3600);
    const minutes = Math.floor((duration % 3600) / 60);
    const seconds = duration % 60;

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    } else if (minutes > 0) {
        return `${minutes}m ${seconds}s`;
    } else {
        return `${seconds}s`;
    }
};

const endConversation = async (conversation: any) => {
    if (!confirm(`End conversation with ${conversation.whatsapp_user_phone}?`)) {
        return;
    }

    try {
        await axios.post(`/api/conversations/${conversation.id}/end`);
        snackbar.value = {
            show: true,
            message: 'Conversation ended successfully',
            color: 'success',
        };
        fetchConversations();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to end conversation',
            color: 'error',
        };
    }
};

const exportConversations = async () => {
    try {
        const response = await axios.post('/api/conversations/export', {
            format: 'csv',
            status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
            chatbot_id: chatbotFilter.value !== 'all' ? chatbotFilter.value : undefined,
        });

        const blob = new Blob([atob(response.data.data)], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = response.data.filename;
        a.click();

        snackbar.value = {
            show: true,
            message: 'Conversations exported successfully',
            color: 'success',
        };
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to export conversations',
            color: 'error',
        };
    }
};

onMounted(() => {
    fetchConversations();
    fetchChatbots();
});
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                    <div>
                        <h2 class="text-h4 mb-2">Conversations</h2>
                        <p class="text-medium-emphasis">
                            View and manage all chatbot conversations
                        </p>
                    </div>
                    <VBtn variant="outlined" prepend-icon="$download" @click="exportConversations">
                        Export
                    </VBtn>
                </div>
            </VCol>
        </VRow>

        <VRow class="mb-4">
            <VCol cols="12" md="4">
                <VTextField
                    v-model="searchQuery"
                    placeholder="Search by phone, name, or chatbot..."
                    variant="outlined"
                    prepend-inner-icon="$magnify"
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
                    label="Status"
                    hide-details
                    @update:model-value="fetchConversations"
                />
            </VCol>
            <VCol cols="12" md="3">
                <VSelect
                    v-model="chatbotFilter"
                    :items="chatbots"
                    item-title="name"
                    item-value="id"
                    variant="outlined"
                    label="Chatbot"
                    hide-details
                    @update:model-value="fetchConversations"
                />
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto">
                <VProgressCircular indeterminate color="primary" size="64" />
            </VCol>
        </VRow>

        <VRow v-else-if="filteredConversations.length === 0" justify="center">
            <VCol cols="12" md="8" lg="6">
                <VCard class="pa-8 text-center" variant="outlined">
                    <VIcon icon="$messageText" size="80" color="primary" class="mb-4" />
                    <h3 class="text-h5 mb-2">No Conversations Found</h3>
                    <p class="text-medium-emphasis">
                        {{ searchQuery ? 'Try adjusting your search or filters' : 'Conversations will appear here when users interact with your chatbots' }}
                    </p>
                </VCard>
            </VCol>
        </VRow>

        <VCard v-else>
            <VList>
                <template v-for="(conversation, index) in filteredConversations" :key="conversation.id">
                    <VListItem :to="`/conversations/${conversation.id}`" class="conversation-item">
                        <template #prepend>
                            <VAvatar color="primary" size="48">
                                <VIcon icon="$account" />
                            </VAvatar>
                        </template>

                        <VListItemTitle class="d-flex align-center">
                            <span class="text-h6">
                                {{ conversation.whatsapp_user_name || conversation.whatsapp_user_phone }}
                            </span>
                            <VChip :color="getStatusColor(conversation.status)" size="small" class="ml-2">
                                <VIcon :icon="getStatusIcon(conversation.status)" size="14" class="mr-1" />
                                {{ conversation.status }}
                            </VChip>
                        </VListItemTitle>

                        <VListItemSubtitle class="mt-1">
                            <div class="d-flex align-center gap-4 flex-wrap">
                                <div class="d-flex align-center">
                                    <VIcon icon="$robot" size="16" color="primary" class="mr-1" />
                                    <span>{{ conversation.chatbot?.name }}</span>
                                </div>
                                <div class="d-flex align-center">
                                    <VIcon icon="$whatsapp" size="16" color="success" class="mr-1" />
                                    <span>{{ conversation.whatsapp_user_phone }}</span>
                                </div>
                                <div class="d-flex align-center">
                                    <VIcon icon="$messageText" size="16" class="mr-1" />
                                    <span>{{ conversation.message_count }} messages</span>
                                </div>
                                <div class="d-flex align-center" v-if="conversation.duration">
                                    <VIcon icon="$clock" size="16" class="mr-1" />
                                    <span>{{ formatDuration(conversation.duration) }}</span>
                                </div>
                            </div>
                        </VListItemSubtitle>

                        <VListItemSubtitle class="mt-2 text-caption">
                            Started {{ moment(conversation.started_at).fromNow() }}
                            <span v-if="conversation.ended_at">
                                • Ended {{ moment(conversation.ended_at).fromNow() }}
                            </span>
                            <span v-else-if="conversation.last_message_at">
                                • Last message {{ moment(conversation.last_message_at).fromNow() }}
                            </span>
                        </VListItemSubtitle>

                        <template #append>
                            <div class="d-flex align-center gap-2">
                                <VBtn icon variant="text" size="small" :to="`/conversations/${conversation.id}`">
                                    <VIcon icon="$eye" />
                                </VBtn>

                                <VMenu v-if="conversation.status === 'active'">
                                    <template #activator="{ props }">
                                        <VBtn v-bind="props" icon variant="text" size="small">
                                            <VIcon icon="$dotsVertical" />
                                        </VBtn>
                                    </template>
                                    <VList>
                                        <VListItem prepend-icon="$closeCircle" @click.prevent="endConversation(conversation)">
                                            End Conversation
                                        </VListItem>
                                    </VList>
                                </VMenu>
                            </div>
                        </template>
                    </VListItem>

                    <VDivider v-if="index < filteredConversations.length - 1" />
                </template>
            </VList>

            <VDivider />
            <div class="d-flex justify-center pa-4">
                <VPagination
                    v-model="page"
                    :length="Math.ceil(total / perPage)"
                    @update:model-value="fetchConversations"
                    total-visible="7"
                />
            </div>
        </VCard>

        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.conversation-item {
    transition: background-color 0.2s ease;
}

.conversation-item:hover {
    background-color: rgba(var(--v-theme-primary), 0.05);
}

.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.gap-4 { gap: 16px; }
</style>
