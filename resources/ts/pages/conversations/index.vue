<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { watchDebounced } from "@vueuse/core";

const router = useRouter();

interface Conversation {
  id: number;
  whatsapp_user_phone: string;
  whatsapp_user_name: string | null;
  status: "active" | "completed" | "handed_off" | "abandoned";
  message_count: number;
  started_at: string;
  last_message_at: string;
  flow?: { id: number; name: string };
}

const isLoading = ref(true);
const conversations = ref<Conversation[]>([]);
const search = ref("");
const statusFilter = ref("All");
const page = ref(1);
const meta = ref({ current_page: 1, from: 0, to: 0, total: 0, last_page: 1 });
const totalPages = computed(() => meta.value.last_page || 1);

const statusOptions = ["All", "active", "completed", "handed_off", "abandoned"];
const statusColor: Record<string, string> = { active: "success", completed: "primary", handed_off: "warning", abandoned: "error" };

const fmtDate = (iso: string) => new Date(iso).toLocaleDateString("en-GB", { day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });

const fetchConversations = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/conversations", {
      params: {
        page: page.value,
        search: search.value || undefined,
        status: statusFilter.value !== "All" ? statusFilter.value : undefined,
      },
    });
    conversations.value = data.data ?? [];
    meta.value = { current_page: data.current_page, from: data.from ?? 0, to: data.to ?? 0, total: data.total, last_page: data.last_page };
  } finally {
    isLoading.value = false;
  }
};

const exportConversations = () => {
  window.open("/tenant/conversations/export", "_blank");
};

watchDebounced(search, () => { page.value = 1; fetchConversations(); }, { debounce: 400 });
watchDebounced([statusFilter, page], () => fetchConversations(), { debounce: 0 });

onMounted(fetchConversations);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Conversations</h1>
        <p class="text-subtitle-1 text-medium-emphasis">All WhatsApp conversations across your bots</p>
      </div>
      <VBtn variant="outlined" prepend-icon="mdi-download" @click="exportConversations">Export</VBtn>
    </div>

    <VRow class="mb-2">
      <VCol cols="12" md="8">
        <VTextField v-model="search" label="Search by phone or name…" prepend-inner-icon="mdi-magnify" variant="outlined" clearable hide-details density="comfortable" />
      </VCol>
      <VCol cols="12" md="4">
        <VSelect v-model="statusFilter" :items="statusOptions" label="Status" variant="outlined" hide-details density="comfortable" class="text-capitalize" />
      </VCol>
    </VRow>

    <VCard variant="outlined" elevation="0" class="mt-4">
      <div v-if="isLoading" class="d-flex justify-center py-12">
        <VProgressCircular indeterminate color="primary" size="48" />
      </div>
      <VTable v-else density="comfortable">
        <thead>
          <tr>
            <th class="text-left pa-4">User</th>
            <th class="text-left pa-4">Flow</th>
            <th class="text-left pa-4">Status</th>
            <th class="text-right pa-4">Messages</th>
            <th class="text-right pa-4">Last Activity</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!conversations.length"><td colspan="5" class="text-center py-12 text-grey">No conversations found.</td></tr>
          <tr
            v-for="conv in conversations"
            :key="conv.id"
            class="cursor-pointer"
            @click="router.push(`/conversations/${conv.id}`)"
          >
            <td class="pa-4">
              <p class="text-body-2 mb-0">{{ conv.whatsapp_user_name ?? "—" }}</p>
              <p class="text-caption text-medium-emphasis mb-0">{{ conv.whatsapp_user_phone }}</p>
            </td>
            <td class="pa-4 text-body-2">{{ conv.flow?.name ?? "—" }}</td>
            <td class="pa-4"><VChip :color="statusColor[conv.status]" size="x-small" variant="tonal" class="text-capitalize">{{ conv.status.replace("_", " ") }}</VChip></td>
            <td class="pa-4 text-right">{{ conv.message_count }}</td>
            <td class="pa-4 text-right text-caption text-medium-emphasis">{{ fmtDate(conv.last_message_at) }}</td>
          </tr>
        </tbody>
      </VTable>

      <VCardText v-if="conversations.length" class="pt-4">
        <VRow class="align-center" justify="space-between">
          <VCol cols="12" sm="6"><span class="text-medium-emphasis">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</span></VCol>
          <VCol cols="12" sm="6" class="d-flex justify-end"><VPagination v-model="page" :length="totalPages" density="comfortable" color="primary" /></VCol>
        </VRow>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.cursor-pointer { cursor: pointer; }
.cursor-pointer:hover { background: rgba(0,0,0,0.02); }
</style>
