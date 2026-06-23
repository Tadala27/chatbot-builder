<script setup lang="ts">
import { ref, onMounted, nextTick } from "vue";
import { useRoute, useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";

const route = useRoute();
const router = useRouter();
const isLoading = ref(true);
const conversation = ref<any>(null);
const messages = ref<any[]>([]);
const messagesEl = ref<HTMLElement | null>(null);

const statusColor: Record<string, string> = { active: "success", completed: "primary", handed_off: "warning", abandoned: "error" };

const fetchConversation = async () => {
  isLoading.value = true;
  try {
    const [{ data: convData }, { data: msgData }] = await Promise.all([
      axios.get(`/tenant/conversations/${route.params.id}`),
      axios.get(`/tenant/conversations/${route.params.id}/messages`),
    ]);
    conversation.value = convData.data ?? convData;
    messages.value = msgData.data ?? msgData;
    await nextTick();
    messagesEl.value?.scrollTo({ top: messagesEl.value.scrollHeight });
  } finally {
    isLoading.value = false;
  }
};

const handoff = async () => {
  try {
    await axios.post(`/tenant/conversations/${route.params.id}/handoff`);
    fetchConversation();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to hand off.", icon: "error" });
  }
};

const endConversation = async () => {
  const { isConfirmed } = await Swal.fire({ title: "End Conversation", text: "Mark this conversation as completed?", icon: "question", showCancelButton: true });
  if (!isConfirmed) return;
  try {
    await axios.post(`/tenant/conversations/${route.params.id}/end`);
    fetchConversation();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to end conversation.", icon: "error" });
  }
};

const fmtTime = (iso: string) => new Date(iso).toLocaleTimeString("en-GB", { hour: "2-digit", minute: "2-digit" });

onMounted(fetchConversation);
</script>

<template>
  <div>
    <VBtn variant="text" prepend-icon="mdi-arrow-left" class="mb-4" @click="router.push('/conversations')">Back</VBtn>

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <template v-else-if="conversation">
      <VCard variant="outlined" elevation="0" class="mb-4">
        <VCardText class="d-flex align-center justify-space-between flex-wrap gap-3">
          <div class="d-flex align-center gap-3">
            <VAvatar color="secondary" variant="tonal" size="44">
              <span>{{ (conversation.whatsapp_user_name ?? conversation.whatsapp_user_phone)[0].toUpperCase() }}</span>
            </VAvatar>
            <div>
              <p class="text-subtitle-1 font-weight-medium mb-0">{{ conversation.whatsapp_user_name ?? "Unknown" }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ conversation.whatsapp_user_phone }}</p>
            </div>
            <VChip :color="statusColor[conversation.status]" size="small" variant="tonal" class="text-capitalize ml-2">
              {{ conversation.status.replace("_", " ") }}
            </VChip>
          </div>
          <div class="d-flex gap-2">
            <VBtn v-if="conversation.status === 'active'" variant="outlined" prepend-icon="mdi-account-switch" @click="handoff">Hand Off</VBtn>
            <VBtn v-if="conversation.status !== 'completed'" color="primary" variant="tonal" prepend-icon="mdi-check" @click="endConversation">End</VBtn>
          </div>
        </VCardText>
      </VCard>

      <VCard variant="outlined" elevation="0">
        <VCardTitle>Messages</VCardTitle>
        <VDivider />
        <div ref="messagesEl" class="pa-4" style="max-height: 60vh; overflow-y: auto;">
          <div v-if="!messages.length" class="text-center text-grey py-8">No messages yet.</div>
          <div
            v-for="msg in messages"
            :key="msg.id"
            class="d-flex mb-3"
            :class="msg.direction === 'outbound' ? 'justify-end' : 'justify-start'"
          >
            <div
              class="pa-3 rounded-lg"
              :style="{
                maxWidth: '70%',
                background: msg.direction === 'outbound' ? 'rgb(var(--v-theme-primary))' : '#f1f1f1',
                color: msg.direction === 'outbound' ? 'white' : 'black',
              }"
            >
              <p class="text-body-2 mb-1" style="white-space: pre-wrap;">
                {{ typeof msg.content === "string" ? msg.content : msg.content?.text ?? JSON.stringify(msg.content) }}
              </p>
              <p class="text-caption mb-0" :class="msg.direction === 'outbound' ? 'text-white' : 'text-medium-emphasis'" style="opacity: 0.7;">
                {{ fmtTime(msg.sent_at) }}
              </p>
            </div>
          </div>
        </div>
      </VCard>
    </template>
  </div>
</template>
