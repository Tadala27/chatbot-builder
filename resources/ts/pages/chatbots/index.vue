<script setup lang="ts">
import { ref, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";
import PageHeader from "@/components/PageHeader.vue";

const router = useRouter();

interface Bot {
  id: string;
  name: string;
  description: string | null;
  is_active: boolean;
  whatsapp_account_id: string;
  default_language: string;
  created_at: string;
}

const isLoading = ref(true);
const bots = ref<Bot[]>([]);
const search = ref("");

const fetchBots = async () => {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/bots", {
      params: { search: search.value || undefined },
    });
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
    Swal.fire({
      title: "Error",
      text: e.response?.data?.message ?? "Failed to delete bot.",
      icon: "error",
    });
  }
};

watchDebounced(search, fetchBots, { debounce: 400 });
onMounted(fetchBots);
</script>

<template>
  <div>
    <PageHeader
      title="Bots"
      subtitle="Build and manage your WhatsApp chatbots"
      icon="$robot"
      action-label="New Bot"
      action-icon="$plus"
      @action="router.push('/chatbots/create')"
    />

    <VTextField
      v-model="search"
      label="Search bots…"
      prepend-inner-icon="$magnify"
      variant="outlined"
      clearable
      hide-details
      density="comfortable"
      rounded="lg"
      class="mb-5"
    />

    <div v-if="isLoading" class="d-flex justify-center py-12">
      <VProgressCircular indeterminate color="primary" size="48" />
    </div>

    <VRow v-else>
      <VCol v-if="!bots.length" cols="12">
        <VCard variant="flat" border rounded="lg" class="text-center py-12">
          <VAvatar
            size="64"
            color="primary"
            variant="tonal"
            class="mx-auto mb-4"
          >
            <VIcon size="32" icon="$robot" />
          </VAvatar>
          <h3 class="text-h6 mb-1">No bots yet</h3>
          <p class="text-body-2 text-medium-emphasis mb-4">
            Create your first bot to start automating conversations.
          </p>
          <VBtn
            color="primary"
            variant="flat"
            @click="router.push('/chatbots/create')"
            >Create your first bot</VBtn
          >
        </VCard>
      </VCol>

      <VCol v-for="bot in bots" :key="bot.id" cols="12" sm="6" md="3">
        <VCard variant="flat" border rounded="lg" class="bot-card h-100">
          <VCardText>
            <div class="d-flex align-center justify-space-between mb-3">
              <VAvatar color="primary" variant="tonal" size="44" rounded="lg">
                <VIcon icon="$robot" />
              </VAvatar>
              <VChip
                :color="bot.is_active ? 'success' : 'error'"
                size="small"
                variant="tonal"
              >
                {{ bot.is_active ? "Active" : "Inactive" }}
              </VChip>
            </div>
            <p class="text-subtitle-1 font-weight-medium mb-1">
              {{ bot.name }}
            </p>
            <p
              class="text-caption text-medium-emphasis mb-0"
              style="min-height: 36px"
            >
              {{ bot.description || "No description" }}
            </p>
          </VCardText>
          <VDivider />
          <VCardActions class="px-4 py-2">
            <VBtn
              size="small"
              variant="text"
              color="primary"
              @click="router.push(`/chatbots/${bot.id}/settings`)"
              >Settings</VBtn
            >
            <VBtn
              size="small"
              variant="text"
              color="info"
              @click="router.push(`/chatbots/${bot.id}/flow`)"
              >Flows</VBtn
            >
            <VSpacer />
            <VBtn
              size="small"
              icon
              variant="text"
              @click="router.push(`/chatbots/${bot.id}/edit`)"
            >
              <VIcon size="18">$pencil</VIcon>
            </VBtn>
            <VBtn
              size="small"
              icon
              variant="text"
              color="error"
              @click="deleteBot(bot)"
            >
              <VIcon size="18">$trashCan</VIcon>
            </VBtn>
          </VCardActions>
        </VCard>
      </VCol>
    </VRow>
  </div>
</template>

<style scoped>
.bot-card {
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease;
}
.bot-card:hover {
  box-shadow: 0 8px 24px rgba(39, 55, 117, 0.08);
  transform: translateY(-2px);
}
</style>
