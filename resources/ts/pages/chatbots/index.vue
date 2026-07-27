<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import Swal from "sweetalert2";
import { watchDebounced } from "@vueuse/core";
import PageHeader from "@/components/PageHeader.vue";

const router = useRouter();

const MAX_ACTIVE_BOTS = 1;

interface Bot {
  id: string;
  name: string;
  description: string | null;
  is_active: boolean;
  whatsapp_account_id: string;
  default_language: string;
  published_at: string | null;
  created_at: string;
  stats?: {
    published_version: number | null;
    total_conversations: number;
    active_conversations: number;
  };
}

const isLoading = ref(true);
const bots = ref<Bot[]>([]);
const search = ref("");
const togglingId = ref<string | null>(null);

const snackbar = ref({
  show: false,
  text: "",
  color: "success" as "success" | "error" | "warning",
});

function showSnackbar(
  text: string,
  color: "success" | "error" | "warning" = "success",
) {
  snackbar.value = { show: true, text, color };
}

const activeCount = computed(
  () => bots.value.filter((b) => b.is_active).length,
);
const atLimit = computed(() => activeCount.value >= MAX_ACTIVE_BOTS);

async function fetchBots() {
  isLoading.value = true;
  try {
    const { data } = await axios.get("/tenant/bots", {
      params: { search: search.value || undefined },
    });
    bots.value = data.data ?? data;
  } catch (e: any) {
    showSnackbar("Failed to load bots.", "error");
  } finally {
    isLoading.value = false;
  }
}

async function toggleActive(bot: Bot) {
  if (togglingId.value) return;

  if (!bot.is_active && atLimit.value) {
    const activeNames = bots.value
      .filter((b) => b.is_active)
      .map((b) => b.name)
      .join(" and ");

    const { isConfirmed } = await Swal.fire({
      title: "Active bot limit reached",
      html: `You already have ${MAX_ACTIVE_BOTS} active bots (<strong>${activeNames}</strong>).<br><br>To activate <strong>${bot.name}</strong>, you need to deactivate one first.`,
      icon: "warning",
      showCancelButton: true,
      confirmButtonText: "Deactivate one",
      cancelButtonText: "Cancel",
    });

    if (!isConfirmed) return;

    // Let them pick which to deactivate
    const activeOptions = bots.value.filter((b) => b.is_active);
    const { value: deactivateId } = await Swal.fire({
      title: "Which bot to deactivate?",
      input: "radio",
      inputOptions: Object.fromEntries(
        activeOptions.map((b) => [b.id, b.name]),
      ),
      inputValidator: (v) => (!v ? "Please select a bot." : null),
      showCancelButton: true,
    });

    if (!deactivateId) return;

    togglingId.value = deactivateId;
    try {
      await axios.post(`/tenant/bots/${deactivateId}/deactivate`);
      const target = bots.value.find((b) => b.id === deactivateId);
      if (target) target.is_active = false;
    } catch (e: any) {
      showSnackbar(
        e.response?.data?.message ?? "Failed to deactivate bot.",
        "error",
      );
      togglingId.value = null;
      return;
    }
    togglingId.value = null;
  }

  togglingId.value = bot.id;
  const endpoint = bot.is_active ? "deactivate" : "activate";
  try {
    const { data } = await axios.post(`/tenant/bots/${bot.id}/${endpoint}`);
    bot.is_active = data.is_active;
    showSnackbar(data.message);
  } catch (e: any) {
    showSnackbar(
      e.response?.data?.message ?? `Failed to ${endpoint} bot.`,
      "error",
    );
  } finally {
    togglingId.value = null;
  }
}

async function deleteBot(bot: Bot) {
  const { isConfirmed } = await Swal.fire({
    title: "Delete bot",
    text: `Delete "${bot.name}"? This removes all its flows and configuration.`,
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    confirmButtonText: "Delete",
  });
  if (!isConfirmed) return;
  try {
    await axios.delete(`/tenant/bots/${bot.id}`);
    showSnackbar(`${bot.name} deleted.`);
    fetchBots();
  } catch (e: any) {
    showSnackbar(e.response?.data?.message ?? "Failed to delete bot.", "error");
  }
}

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
          >
            Create your first bot
          </VBtn>
        </VCard>
      </VCol>

      <VCol v-for="bot in bots" :key="bot.id" cols="12" sm="6" md="3">
        <VCard
          variant="flat"
          border
          rounded="lg"
          class="bot-card h-100"
          :class="{ 'bot-card--active': bot.is_active }"
        >
          <VCardText>
            <!-- Top row: avatar + status chip + toggle -->
            <div class="d-flex align-center justify-space-between mb-3">
              <VAvatar color="primary" variant="tonal" size="44" rounded="lg">
                <VIcon icon="$robot" />
              </VAvatar>

              <div class="d-flex align-center gap-3">
                <VChip
                  :color="bot.is_active ? 'success' : 'default'"
                  size="small"
                  variant="tonal"
                >
                  {{ bot.is_active ? "Active" : "Inactive" }}
                </VChip>

                <!-- Activate/deactivate toggle -->
                <VTooltip
                  :text="
                    bot.is_active
                      ? 'Deactivate bot'
                      : atLimit
                        ? 'Limit reached — deactivate another first'
                        : 'Activate bot'
                  "
                  location="top"
                >
                  <template #activator="{ props }">
                    <VSwitch
                      v-bind="props"
                      :model-value="bot.is_active"
                      :loading="togglingId === bot.id"
                      :disabled="togglingId !== null"
                      color="success"
                      hide-details
                      density="compact"
                      style="flex: none"
                      @change="toggleActive(bot)"
                    />
                  </template>
                </VTooltip>
              </div>
            </div>

            <p class="text-subtitle-1 font-weight-medium mb-1">
              {{ bot.name }}
            </p>
            <p
              class="text-caption text-medium-emphasis mb-2"
              style="min-height: 36px"
            >
              {{ bot.description || "No description" }}
            </p>

            <!-- Stats -->
            <div class="d-flex gap-3 mt-2">
              <div class="text-center">
                <p class="text-caption text-medium-emphasis mb-0">
                  Conversations
                </p>
                <p class="text-body-2 font-weight-medium mb-0">
                  {{ bot.stats?.total_conversations ?? 0 }}
                </p>
              </div>
              <VDivider vertical />
              <div class="text-center">
                <p class="text-caption text-medium-emphasis mb-0">Version</p>
                <p class="text-body-2 font-weight-medium mb-0">
                  {{
                    bot.stats?.published_version
                      ? `v${bot.stats.published_version}`
                      : "—"
                  }}
                </p>
              </div>
            </div>
          </VCardText>

          <VDivider />

          <VCardActions class="px-4 py-2">
            <VBtn
              size="small"
              variant="text"
              color="primary"
              @click="router.push(`/chatbots/${bot.id}/settings`)"
            >
              Settings
            </VBtn>
            <VBtn
              size="small"
              variant="text"
              color="info"
              @click="router.push(`/chatbots/${bot.id}/flow`)"
            >
              Flows
            </VBtn>
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

    <VSnackbar
      v-model="snackbar.show"
      :color="snackbar.color"
      timeout="4000"
      location="top right"
      closable
    >
      {{ snackbar.text }}
      <template #actions>
        <VBtn
          size="small"
          variant="text"
          icon="$close"
          @click="snackbar.show = false"
        />
      </template>
    </VSnackbar>
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
.bot-card--active {
  border-color: rgba(var(--v-theme-success), 0.4) !important;
}
.gap-3 {
  gap: 12px;
}
.gap-2 {
  gap: 8px;
}
</style>
