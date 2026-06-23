<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";

const router = useRouter();

interface Bot {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  whatsapp_account_id: number;
  default_language: string;
  created_at: string;
}

const isLoading = ref(true);
const bots = ref<Bot[]>([]);
const search = ref("");

const fetchBots = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/bots", { params: { search: search.value || undefined } });
    bots.value = data.data ?? data;
  } catch (e: any) {
    console.error(e);
  } finally {
    isLoading.value = false;
  }
};

const deleteBot = async (bot: Bot) => {
  const { isConfirmed } = await Swal.fire({
    title: "Delete Bot",
    text: `Delete "${bot.name}"? This removes all its flows and configuration.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
  });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${bot.id}`);
    fetchBots();
  } catch (e: any) {
    Swal.fire({ title: "Error", text: e.response?.data?.message ?? "Failed to delete bot.", icon: "error" });
  }
};

watchDebounced(search, fetchBots, { debounce: 400 });
onMounted(fetchBots);
</script>

<template>
  <div>
    <div class="d-flex justify-space-between align-center mb-5 flex-wrap gap-3">
      <div>
        <h1 class="text-h4">Bots</h1>
        <p class="text-subtitle-1 text-medium-emphasis">Build and manage your WhatsApp chatbots</p>
      </div>
      <VBtn color="primary" prepend-icon="mdi-plus" @click="router.push('/chatbots/create')">New Bot</VBtn>
    </div>

    <VTextField
      v-model="search"
      label="Search bots…"
      prepend-inner-icon="mdi-magnify"
      variant="outlined"
      clearable
      hide-details
      density="comfortable"
      class="mb-4"
    />

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <VCol v-if="!bots.length" cols="12">
        <VCard variant="outlined" class="text-center py-12">
          <VIcon size="64" color="grey-lighten-1">mdi-robot-outline</VIcon>
          <h3 class="text-h6 text-grey mt-4">No bots yet</h3>
          <VBtn color="primary" variant="outlined" class="mt-4" @click="router.push('/chatbots/create')">Create your first bot</VBtn>
        </VCard>
      </VCol>

      <VCol v-for="bot in bots" :key="bot.id" cols="12" sm="6" md="4">
        <VCard variant="outlined" elevation="0" class="h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-3">
              <VAvatar color="primary" variant="tonal" size="44"><VIcon>mdi-robot-outline</VIcon></VAvatar>
              <VChip :color="bot.is_active ? 'success' : 'error'" size="small" variant="tonal">
                {{ bot.is_active ? "Active" : "Inactive" }}
              </VChip>
            </div>
            <p class="text-subtitle-1 font-weight-medium mb-1">{{ bot.name }}</p>
            <p class="text-caption text-medium-emphasis mb-0" style="min-height: 36px;">
              {{ bot.description || "No description" }}
            </p>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4 py-2">
            <VBtn size="small" variant="text" @click="router.push(`/chatbots/${bot.id}`)">View</VBtn>
            <VBtn size="small" variant="text" @click="router.push(`/chatbots/${bot.id}/flow`)">Flows</VBtn>
            <VSpacer />
            <VBtn size="small" icon variant="text" @click="router.push(`/chatbots/${bot.id}/edit`)"><VIcon size="18">mdi-pencil</VIcon></VBtn>
            <VBtn size="small" icon variant="text" color="error" @click="deleteBot(bot)"><VIcon size="18">mdi-trash-can</VIcon></VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>
