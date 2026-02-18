<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const router = useRouter();
const paths = ref([]);
const chatbots = ref([]);
const selectedChatbot = ref<any>(null);
const isLoading = ref(true);

const fetchChatbots = async () => {
    try {
        const response = await axios.get('/api/chatbots');
        chatbots.value = response.data.data || [];
        if (chatbots.value.length > 0) {
            selectedChatbot.value = chatbots.value[0].id;
            fetchPaths();
        }
    } catch (error) {
        console.error('Failed to load chatbots', error);
    }
};

const fetchPaths = async () => {
    if (!selectedChatbot.value) return;
    isLoading.value = true;
    try {
        const response = await axios.get(`/api/analytics/chatbot/${selectedChatbot.value}/popular-paths`);
        paths.value = response.data.paths || [];
    } catch (error) {
        console.error('Failed to load paths', error);
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => {
    fetchChatbots();
});
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <div class="d-flex align-center">
                    <VBtn icon variant="text" @click="router.push('/analytics/overview')">
                        <VIcon icon="$arrowLeft" />
                    </VBtn>
                    <div class="ml-4">
                        <h2 class="text-h4 mb-2">Popular Conversation Paths</h2>
                        <p class="text-medium-emphasis">Most common flows users take</p>
                    </div>
                </div>
            </VCol>
        </VRow>

        <VRow class="mb-4">
            <VCol cols="12" md="6">
                <VSelect
                    v-model="selectedChatbot"
                    :items="chatbots"
                    item-title="name"
                    item-value="id"
                    label="Select Chatbot"
                    variant="outlined"
                    @update:model-value="fetchPaths"
                />
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <VRow v-else-if="paths.length === 0" justify="center">
            <VCol cols="12" md="8">
                <VCard class="pa-8 text-center" variant="outlined">
                    <VIcon icon="$routeArrowRight" size="80" color="primary" class="mb-4" />
                    <h3 class="text-h5 mb-2">No Data Yet</h3>
                    <p class="text-medium-emphasis">Popular paths will appear once users interact with this chatbot</p>
                </VCard>
            </VCol>
        </VRow>

        <VCard v-else>
            <VCardTitle>Top 10 Conversation Paths</VCardTitle>
            <VDivider />
            <VList>
                <VListItem v-for="(path, index) in paths" :key="index">
                    <template #prepend>
                        <VChip color="primary" size="small">{{ index + 1 }}</VChip>
                    </template>
                    <VListItemTitle>
                        <code class="path-display">{{ path.path }}</code>
                    </VListItemTitle>
                    <VListItemSubtitle class="mt-2">
                        <VChip size="small" color="success">{{ path.count }} users</VChip>
                    </VListItemSubtitle>
                </VListItem>
            </VList>
        </VCard>
    </div>
</template>

<style scoped>
.path-display {
    background: rgba(var(--v-theme-primary), 0.1);
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 13px;
    display: inline-block;
}
</style>
