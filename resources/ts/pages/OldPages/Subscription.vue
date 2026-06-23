<script setup lang="ts">
import { ref, onMounted } from 'vue';
import axios from 'axios';
import moment from 'moment';

const subscription = ref<any>({});
const isLoading = ref(true);

const tiers = [
    { 
        name: 'Starter', 
        price: '$29', 
        bots: 5, 
        conversations: '5,000',
        features: ['5 Chatbots', '5K conversations/month', 'Basic analytics', 'Email support']
    },
    { 
        name: 'Professional', 
        price: '$99', 
        bots: 10, 
        conversations: '10,000',
        features: ['10 Chatbots', '10K conversations/month', 'Advanced analytics', 'Priority support', 'Custom functions']
    },
    { 
        name: 'Enterprise', 
        price: '$299', 
        bots: 'Unlimited', 
        conversations: 'Unlimited',
        features: ['Unlimited Chatbots', 'Unlimited conversations', 'Full analytics', '24/7 support', 'Custom integrations', 'White-label']
    },
];

const fetchSubscription = async () => {
    isLoading.value = true;
    try {
        const response = await axios.get('/api/settings');
        subscription.value = response.data.subscription || {};
    } catch (error) {
        console.error('Failed to load subscription', error);
    } finally {
        isLoading.value = false;
    }
};

onMounted(() => { fetchSubscription(); });
</script>

<template>
    <div>
        <VRow class="mb-4">
            <VCol cols="12">
                <h2 class="text-h4 mb-2">Subscription</h2>
                <p class="text-medium-emphasis">Manage your plan and billing</p>
            </VCol>
        </VRow>

        <VRow v-if="isLoading" justify="center">
            <VCol cols="auto"><VProgressCircular indeterminate color="primary" size="64" /></VCol>
        </VRow>

        <div v-else>
            <VCard class="mb-6">
                <VCardTitle>Current Plan</VCardTitle>
                <VDivider />
                <VCardText>
                    <VRow>
                        <VCol cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">Plan</div>
                            <div class="text-h6 text-capitalize">{{ subscription.tier || 'Starter' }}</div>
                        </VCol>
                        <VCol cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">Expires</div>
                            <div class="text-h6">{{ subscription.expires_at ? moment(subscription.expires_at).format('MMM DD, YYYY') : 'N/A' }}</div>
                        </VCol>
                        <VCol cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">Conversations Used</div>
                            <div class="text-h6">{{ subscription.usage_percentage || 0 }}%</div>
                        </VCol>
                        <VCol cols="12" md="3">
                            <div class="text-caption text-medium-emphasis">Chatbots</div>
                            <div class="text-h6">{{ subscription.chatbots_used || 0 }} / {{ subscription.max_chatbots || 5 }}</div>
                        </VCol>
                    </VRow>
                </VCardText>
            </VCard>

            <VRow>
                <VCol v-for="tier in tiers" :key="tier.name" cols="12" md="4">
                    <VCard :color="tier.name.toLowerCase() === subscription.tier ? 'primary' : undefined" :variant="tier.name.toLowerCase() === subscription.tier ? 'tonal' : 'outlined'">
                        <VCardTitle>{{ tier.name }}</VCardTitle>
                        <VDivider />
                        <VCardText>
                            <div class="text-h3 mb-4">{{ tier.price }}<span class="text-caption">/month</span></div>
                            <VList density="compact">
                                <VListItem v-for="(feature, index) in tier.features" :key="index">
                                    <template #prepend><VIcon icon="$check" color="success" size="20" /></template>
                                    <VListItemTitle>{{ feature }}</VListItemTitle>
                                </VListItem>
                            </VList>
                        </VCardText>
                        <VCardActions class="pa-4">
                            <VBtn v-if="tier.name.toLowerCase() === subscription.tier" block disabled>Current Plan</VBtn>
                            <VBtn v-else block color="primary">Upgrade</VBtn>
                        </VCardActions>
                    </VCard>
                </VCol>
            </VRow>
        </div>
    </div>
</template>
