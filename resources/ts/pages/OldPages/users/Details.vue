<script setup lang="ts">
import { ref, onMounted, computed, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import axios from 'axios';
import moment from 'moment';

const route = useRoute();
const router = useRouter();

const conversationId = computed(() => route.params.id);
const conversation = ref<any>(null);
const messages = ref([]);
const isLoading = ref(true);
const isHandingOff = ref(false);
const snackbar = ref({ show: false, message: '', color: 'success' });
const messagesContainer = ref<HTMLElement | null>(null);

const fetchConversation = async () => {
    try {
        const response = await axios.get(`/api/conversations/${conversationId.value}`);
        conversation.value = response.data.conversation;
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to load conversation',
            color: 'error',
        };
    }
};

const fetchMessages = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get(`/api/conversations/${conversationId.value}/messages`);
        messages.value = response.data.messages;
        
        // Scroll to bottom after messages load
        await nextTick();
        scrollToBottom();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to load messages',
            color: 'error',
        };
    } finally {
        isLoading.value = false;
    }
};

const scrollToBottom = () => {
    if (messagesContainer.value) {
        messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
    }
};

const handoff = async () => {
    if (!confirm('Hand off this conversation to a human agent?')) {
        return;
    }

    isHandingOff.value = true;
    try {
        await axios.post(`/api/conversations/${conversationId.value}/handoff`);
        
        snackbar.value = {
            show: true,
            message: 'Conversation handed off successfully',
            color: 'success',
        };

        fetchConversation();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to hand off conversation',
            color: 'error',
        };
    } finally {
        isHandingOff.value = false;
    }
};

const endConversation = async () => {
    if (!confirm('End this conversation?')) {
        return;
    }

    try {
        await axios.post(`/api/conversations/${conversationId.value}/end`);
        
        snackbar.value = {
            show: true,
            message: 'Conversation ended successfully',
            color: 'success',
        };

        fetchConversation();
    } catch (error: any) {
        snackbar.value = {
            show: true,
            message: error.response?.data?.message || 'Failed to end conversation',
            color: 'error',
        };
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

const getMessageStatusIcon = (status: string) => {
    const icons: Record<string, string> = {
        sent: '$send',
        delivered: '$checkAll',
        read: '$checkAll',
        failed: '$alertCircle',
    };
    return icons[status] || '$check';
};

const getMessageStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        sent: 'grey',
        delivered: 'success',
        read: 'primary',
        failed: 'error',
    };
    return colors[status] || 'grey';
};

const renderMessageContent = (message: any) => {
    if (message.message_type === 'text') {
        return message.content?.text || message.content;
    } else if (message.message_type === 'interactive') {
        return message.content?.response?.title || 'Interactive message';
    } else if (message.message_type === 'image') {
        return '📷 Image';
    } else if (message.message_type === 'video') {
        return '🎥 Video';
    } else if (message.message_type === 'audio') {
        return '🎵 Audio';
    } else if (message.message_type === 'document') {
        return '📄 Document';
    } else if (message.message_type === 'location') {
        return '📍 Location';
    }
    return 'Message';
};

onMounted(() => {
    fetchConversation();
    fetchMessages();

    // Poll for new messages every 5 seconds
    const interval = setInterval(() => {
        if (conversation.value?.status === 'active') {
            fetchMessages();
        }
    }, 5000);

    // Cleanup
    return () => clearInterval(interval);
});
</script>

<template>
    <div class="conversation-details">
        <!-- Header -->
        <VCard elevation="0" class="mb-4">
            <VCardText>
                <div class="d-flex align-center justify-space-between flex-wrap gap-3">
                    <div class="d-flex align-center">
                        <VBtn icon variant="text" @click="router.push('/conversations')">
                            <VIcon icon="$arrowLeft" />
                        </VBtn>

                        <VAvatar color="primary" size="48" class="ml-3">
                            <VIcon icon="$account" />
                        </VAvatar>

                        <div class="ml-4">
                            <h3 class="text-h6 d-flex align-center">
                                {{ conversation?.whatsapp_user_name || conversation?.whatsapp_user_phone }}
                                <VChip
                                    :color="getStatusColor(conversation?.status)"
                                    size="small"
                                    class="ml-2"
                                >
                                    {{ conversation?.status }}
                                </VChip>
                            </h3>
                            <p class="text-caption text-medium-emphasis mb-0">
                                <VIcon icon="$robot" size="14" class="mr-1" />
                                {{ conversation?.chatbot?.name }}
                                <span class="mx-2">•</span>
                                <VIcon icon="$whatsapp" size="14" class="mr-1" />
                                {{ conversation?.whatsapp_user_phone }}
                            </p>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <VBtn
                            v-if="conversation?.status === 'active'"
                            variant="outlined"
                            prepend-icon="$accountSwitch"
                            @click="handoff"
                            :loading="isHandingOff"
                        >
                            Hand Off
                        </VBtn>
                        <VBtn
                            v-if="conversation?.status === 'active'"
                            variant="outlined"
                            color="error"
                            prepend-icon="$closeCircle"
                            @click="endConversation"
                        >
                            End
                        </VBtn>
                    </div>
                </div>
            </VCardText>
        </VCard>

        <!-- Conversation Stats -->
        <VRow class="mb-4">
            <VCol cols="6" sm="3">
                <VCard>
                    <VCardText class="text-center">
                        <div class="text-h4 text-primary">{{ conversation?.message_count || 0 }}</div>
                        <div class="text-caption text-medium-emphasis">Messages</div>
                    </VCardText>
                </VCard>
            </VCol>
            <VCol cols="6" sm="3">
                <VCard>
                    <VCardText class="text-center">
                        <div class="text-h4 text-success">
                            {{ moment(conversation?.started_at).format('HH:mm') }}
                        </div>
                        <div class="text-caption text-medium-emphasis">Started</div>
                    </VCardText>
                </VCard>
            </VCol>
            <VCol cols="6" sm="3">
                <VCard>
                    <VCardText class="text-center">
                        <div class="text-h4">
                            {{ conversation?.ended_at ? moment(conversation.ended_at).format('HH:mm') : '-' }}
                        </div>
                        <div class="text-caption text-medium-emphasis">Ended</div>
                    </VCardText>
                </VCard>
            </VCol>
            <VCol cols="6" sm="3">
                <VCard>
                    <VCardText class="text-center">
                        <div class="text-h4">
                            {{ conversation?.duration ? Math.round(conversation.duration / 60) : '-' }}m
                        </div>
                        <div class="text-caption text-medium-emphasis">Duration</div>
                    </VCardText>
                </VCard>
            </VCol>
        </VRow>

        <!-- Messages Timeline -->
        <VCard class="messages-card">
            <VCardTitle class="border-b">
                <VIcon icon="$messageText" class="mr-2" />
                Message Timeline
            </VCardTitle>

            <!-- Loading State -->
            <div v-if="isLoading" class="d-flex align-center justify-center pa-8">
                <VProgressCircular indeterminate color="primary" size="48" />
            </div>

            <!-- Messages -->
            <VCardText v-else class="messages-container pa-0" ref="messagesContainer">
                <div v-if="messages.length === 0" class="text-center pa-8 text-medium-emphasis">
                    No messages yet
                </div>

                <div v-else class="messages-list pa-4">
                    <div
                        v-for="message in messages"
                        :key="message.id"
                        class="message-wrapper mb-4"
                        :class="{ 'message-outbound': message.direction === 'outbound' }"
                    >
                        <div class="message-bubble">
                            <div class="message-header mb-1">
                                <span class="text-caption font-weight-bold">
                                    {{ message.direction === 'outbound' ? 'Bot' : conversation?.whatsapp_user_name || 'User' }}
                                </span>
                                <span class="text-caption text-medium-emphasis ml-2">
                                    {{ moment(message.created_at).format('HH:mm') }}
                                </span>
                            </div>

                            <div class="message-content">
                                {{ renderMessageContent(message) }}
                            </div>

                            <!-- Message Status (for outbound only) -->
                            <div v-if="message.direction === 'outbound'" class="message-status mt-1">
                                <VIcon
                                    :icon="getMessageStatusIcon(message.status)"
                                    :color="getMessageStatusColor(message.status)"
                                    size="14"
                                />
                                <span class="text-caption text-medium-emphasis ml-1">
                                    {{ message.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </VCardText>
        </VCard>

        <!-- Snackbar -->
        <VSnackbar v-model="snackbar.show" :color="snackbar.color" :timeout="4000" location="top right">
            {{ snackbar.message }}
            <template #actions>
                <VBtn variant="text" @click="snackbar.show = false">Close</VBtn>
            </template>
        </VSnackbar>
    </div>
</template>

<style scoped>
.conversation-details {
    max-width: 1200px;
    margin: 0 auto;
}

.messages-card {
    height: calc(100vh - 400px);
    display: flex;
    flex-direction: column;
}

.messages-container {
    flex: 1;
    overflow-y: auto;
    background-color: #f5f5f5;
}

.messages-list {
    max-width: 800px;
    margin: 0 auto;
}

.message-wrapper {
    display: flex;
}

.message-wrapper.message-outbound {
    justify-content: flex-end;
}

.message-bubble {
    max-width: 70%;
    padding: 12px 16px;
    border-radius: 12px;
    background-color: white;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
}

.message-outbound .message-bubble {
    background-color: rgba(var(--v-theme-primary), 0.1);
}

.message-header {
    display: flex;
    align-items: center;
}

.message-content {
    word-wrap: break-word;
}

.message-status {
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

.gap-2 {
    gap: 8px;
}

.gap-3 {
    gap: 12px;
}

.border-b {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
}
</style>
