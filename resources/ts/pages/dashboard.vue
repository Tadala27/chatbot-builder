<script setup lang="ts">
import { ref, onMounted } from "vue";
import axios from "axios";

const stats = ref({
  flows: { total: 0, active: 0, draft: 0 },
  conversations: { total: 0, active: 0, today: 0, this_month: 0 },
  whatsapp_accounts: { total: 0, active: 0 },
  usage: {
    conversations_used: 0,
    conversations_limit: 0,
    usage_percentage: 0,
    remaining: 0,
  },
  recent_conversations: [],
});

const isLoading = ref(true);

const fetchStats = async () => {
  try {
    const response = await axios.get("/api/dashboard/stats");
    stats.value = response.data;
  } catch (error) {
    console.error("Failed to load dashboard stats", error);
  } finally {
    isLoading.value = false;
  }
};

const getStatusColor = (status: string) => {
  return status === "active"
    ? "success"
    : status === "completed"
      ? "primary"
      : status === "handed_off"
        ? "warning"
        : "error";
};

onMounted(() => {
  fetchStats();
});
</script>

<template>
  <div>
    <VRow class="mb-6">
      <VCol cols="12">
        <h2 class="text-h4 mb-2">Dashboard</h2>
        <p class="text-medium-emphasis">Welcome back! Here's your overview.</p>
      </VCol>
    </VRow>

    <!-- Loading State -->
    <VRow v-if="isLoading" justify="center">
      <VCol cols="auto">
        <VProgressCircular indeterminate color="primary" size="64" />
      </VCol>
    </VRow>

    <div v-else>
      <!-- Stats Cards -->
      <VRow>
        <VCol cols="12" sm="6" lg="3">
          <VCard>
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <VIcon icon="$robot" size="40" color="primary" />
                <VChip color="success" size="small">
                  {{ stats.flows.active }} Active
                </VChip>
              </div>
              <h3 class="text-h3">{{ stats.flows.total }}</h3>
              <p class="text-caption text-medium-emphasis mb-0">Total flows</p>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" sm="6" lg="3">
          <VCard>
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <VIcon icon="$messageText" size="40" color="success" />
                <VChip color="info" size="small">
                  {{ stats.conversations.today }} Today
                </VChip>
              </div>
              <h3 class="text-h3">{{ stats.conversations.total }}</h3>
              <p class="text-caption text-medium-emphasis mb-0">
                Conversations
              </p>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" sm="6" lg="3">
          <VCard>
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <VIcon icon="$whatsapp" size="40" color="success" />
                <VChip color="success" size="small">
                  {{ stats.whatsapp_accounts.active }} Connected
                </VChip>
              </div>
              <h3 class="text-h3">{{ stats.whatsapp_accounts.total }}</h3>
              <p class="text-caption text-medium-emphasis mb-0">
                WhatsApp Accounts
              </p>
            </VCardText>
          </VCard>
        </VCol>

        <VCol cols="12" sm="6" lg="3">
          <VCard>
            <VCardText>
              <div class="d-flex align-center justify-space-between mb-2">
                <VIcon icon="$chartLine" size="40" color="warning" />
                <VChip
                  :color="
                    stats.usage.usage_percentage > 80 ? 'error' : 'success'
                  "
                  size="small"
                >
                  {{ stats.usage.usage_percentage.toFixed(0) }}% Used
                </VChip>
              </div>
              <h3 class="text-h3">{{ stats.usage.remaining }}</h3>
              <p class="text-caption text-medium-emphasis mb-0">
                Remaining / {{ stats.usage.conversations_limit }}
              </p>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <!-- Recent Conversations -->
      <VRow class="mt-6">
        <VCol cols="12">
          <VCard>
            <VCardTitle class="d-flex align-center justify-space-between">
              <span>Recent Conversations</span>
              <VBtn variant="text" color="primary" to="/conversations">
                View All
              </VBtn>
            </VCardTitle>
            <VDivider />
            <VCardText v-if="stats.recent_conversations.length === 0">
              <p class="text-center text-medium-emphasis my-8">
                No recent conversations
              </p>
            </VCardText>
            <VList v-else>
              <VListItem
                v-for="conversation in stats.recent_conversations"
                :key="conversation.id"
                :to="`/conversations/${conversation.id}`"
              >
                <template #prepend>
                  <VAvatar color="primary" size="40">
                    <VIcon icon="$account" />
                  </VAvatar>
                </template>
                <VListItemTitle>
                  {{
                    conversation.whatsapp_user_name ||
                    conversation.whatsapp_user_phone
                  }}
                </VListItemTitle>
                <VListItemSubtitle>
                  {{ conversation.chatbot?.name }} •
                  {{ conversation.message_count }} messages
                </VListItemSubtitle>
                <template #append>
                  <VChip
                    :color="getStatusColor(conversation.status)"
                    size="small"
                  >
                    {{ conversation.status }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
          </VCard>
        </VCol>
      </VRow>

      <!-- Quick Actions -->
      <VRow class="mt-6">
        <VCol cols="12" md="4">
          <VCard
            class="text-center pa-6"
            hover
            @click="$router.push('/flows/create')"
          >
            <VIcon icon="$plus" size="48" color="primary" class="mb-3" />
            <h4 class="text-h6 mb-2">Create New Chatbot</h4>
            <p class="text-caption text-medium-emphasis">
              Build a new chatbot from scratch
            </p>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard
            class="text-center pa-6"
            hover
            @click="$router.push('/whatsapp/connect')"
          >
            <VIcon icon="$whatsapp" size="48" color="success" class="mb-3" />
            <h4 class="text-h6 mb-2">Connect WhatsApp</h4>
            <p class="text-caption text-medium-emphasis">
              Add a new WhatsApp account
            </p>
          </VCard>
        </VCol>
        <VCol cols="12" md="4">
          <VCard
            class="text-center pa-6"
            hover
            @click="$router.push('/analytics/overview')"
          >
            <VIcon icon="$chartBar" size="48" color="info" class="mb-3" />
            <h4 class="text-h6 mb-2">View Analytics</h4>
            <p class="text-caption text-medium-emphasis">
              Check your bot performance
            </p>
          </VCard>
        </VCol>
      </VRow>
    </div>
  </div>
</template>
