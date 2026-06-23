<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";

const router = useRouter();
const isSubmitting = ref(false);
const isLoadingAccounts = ref(true);
const snack = ref({ show: false, msg: "", color: "success" });

// ── Tabs ─────────────────────────────────────────────────────────────────────
const tabs = ["details", "messages"];
const currentTabIndex = ref(0);
const tab = computed({
  get: () => tabs[currentTabIndex.value],
  set: (value) => {
    const index = tabs.findIndex((t) => t === value);
    if (index !== -1) currentTabIndex.value = index;
  },
});
const isFirstTab = computed(() => currentTabIndex.value === 0);
const isLastTab = computed(() => currentTabIndex.value === tabs.length - 1);
const nextTab = () => { if (!isLastTab.value) currentTabIndex.value++; };
const previousTab = () => { if (!isFirstTab.value) currentTabIndex.value--; };

// ── Breadcrumbs ───────────────────────────────────────────────────────────────
const breadcrumbs = ref([
  { title: "Chatbots", disabled: false, href: "/chatbots" },
  { title: "Create", disabled: true, href: "#" },
]);

// ── Form ──────────────────────────────────────────────────────────────────────
const form = ref({
  name: "" as string,
  description: "" as string,
  whatsapp_account_id: null as number | null,
  welcome_message: "" as string,
  fallback_message: "Sorry, I didn't understand that. Please try again." as string,
  default_language: "en" as string,
  supported_languages: ["en"] as string[],
});

// ── WhatsApp accounts ─────────────────────────────────────────────────────────
const whatsappAccounts = ref<any[]>([]);

const selectedAccount = computed(() =>
  whatsappAccounts.value.find((a) => a.id === form.value.whatsapp_account_id) ?? null
);

const qualityColor: Record<string, string> = {
  GREEN: "success",
  YELLOW: "warning",
  RED: "error",
  UNKNOWN: "default",
};

const tierLabel = (tier: string) =>
  tier?.replace("TIER_", "").replace("UNLIMITED", "∞") ?? "—";

async function fetchAccounts() {
  isLoadingAccounts.value = true;
  try {
    const res = await axios.get("/api/whatsapp-accounts");
    // supports paginated { data: [...] } or plain array
    whatsappAccounts.value = (res.data.data ?? res.data).filter((a: any) => a.is_active);
  } catch {
    toast("Could not load WhatsApp accounts", "error");
  } finally {
    isLoadingAccounts.value = false;
  }
}

// ── Languages ─────────────────────────────────────────────────────────────────
const languageOptions = [
  { title: "English", value: "en" },
  { title: "Chichewa", value: "ny" },
  { title: "French", value: "fr" },
  { title: "Portuguese", value: "pt" },
  { title: "Swahili", value: "sw" },
];

// ── Validation ────────────────────────────────────────────────────────────────
const tab1Valid = computed(
  () => form.value.name.length >= 2 && !!form.value.whatsapp_account_id
);
const canNext = computed(() => currentTabIndex.value === 0 ? tab1Valid.value : true);
const canSubmit = computed(() => tab1Valid.value && form.value.fallback_message.length >= 5);

// ── Helpers ───────────────────────────────────────────────────────────────────
const toast = (msg: string, color = "success") => {
  snack.value = { show: true, msg, color };
};

const submit = async () => {
  if (!canSubmit.value) return;
  isSubmitting.value = true;
  try {
    const res = await axios.post("/api/bots", form.value);
    toast("Bot created successfully!");
    setTimeout(
      () => router.push({ name: "bots-bot-id-flowbuilder", params: { id: res.data.bot.id } }),
      800
    );
  } catch (e: any) {
    toast(e.response?.data?.message || "Failed to create bot", "error");
  } finally {
    isSubmitting.value = false;
  }
};

onMounted(fetchAccounts);
</script>

<template>
  <div class="create-bot-page">
    <BaseBreadcrumb title="Create a New Bot" description="Configure your WhatsApp chatbot" :breadcrumbs="breadcrumbs" />

    <VRow justify="center">
      <VCol cols="12">
        <VDivider />

        <!-- ── Tabs ─────────────────────────────────────────────────────── -->
        <VTabs v-model="tab" color="primary" align-tabs="center" grow>
          <VTab value="details">
            <VIcon start>$robot</VIcon>
            Bot Details
          </VTab>
          <VTab value="messages" :disabled="!tab1Valid">
            <VIcon start>$messageTextOutline</VIcon>
            Messages &amp; Language
          </VTab>
        </VTabs>

        <VDivider />

        <!-- ── Tab windows ───────────────────────────────────────────────── -->
        <VWindow v-model="tab">

          <!-- ── Tab 1: Bot Details ──────────────────────────────────────── -->
          <VWindowItem value="details">
            <VCardText class="pa-6">
              <VRow>
                <!-- Bot name -->
                <VCol cols="12" md="6">
                  <VTextField v-model="form.name" label="Bot Name *" placeholder="e.g. Customer Support Bot"
                    variant="outlined" rounded="lg" prepend-inner-icon="$robot"
                    :rules="[(v) => !!v || 'Name is required', (v) => v.length >= 2 || 'Minimum 2 characters']"
                    hint="A unique name to identify your bot" persistent-hint />
                </VCol>

                <!-- WhatsApp account selector -->
                <VCol cols="12" md="6">
                  <VSelect v-model="form.whatsapp_account_id" :items="whatsappAccounts" item-title="verified_name"
                    item-value="id" label="WhatsApp Account *" variant="outlined" rounded="lg"
                    :loading="isLoadingAccounts" :rules="[(v) => !!v || 'Please select a WhatsApp account']"
                    :no-data-text="isLoadingAccounts ? 'Loading…' : 'No active accounts found'"
                    hint="The phone number this bot will send from" persistent-hint>
                    <!-- Dropdown rows -->
                    <template #item="{ item, props: iProps }">
                      <VListItem v-bind="iProps" :subtitle="item.raw.display_phone_number ?? item.raw.phone_number">
                        <template #prepend>
                          <VAvatar size="32" color="success" variant="tonal" class="mr-2">
                            <VIcon size="18">$whatsapp</VIcon>
                          </VAvatar>
                        </template>
                        <template #append>
                          <div class="d-flex flex-column align-end gap-1">
                            <VChip :color="qualityColor[item.raw.quality_rating] ?? 'default'" size="x-small"
                              variant="tonal">
                              {{ item.raw.quality_rating }}
                            </VChip>
                            <span class="text-caption text-medium-emphasis">
                              {{ tierLabel(item.raw.messaging_limit) }}/day
                            </span>
                          </div>
                        </template>
                      </VListItem>
                    </template>

                    <!-- Chip shown after selection -->
                    <template #selection="{ item }">
                      <div class="d-flex align-center gap-2">
                        <VIcon color="success" size="16">$whatsapp</VIcon>
                        <span class="text-body-2 font-weight-medium">{{ item.raw.verified_name }}</span>
                        <span class="text-caption text-medium-emphasis">
                          {{ item.raw.display_phone_number ?? item.raw.phone_number }}
                        </span>
                      </div>
                    </template>

                    <!-- No accounts footer -->
                    <template v-if="!isLoadingAccounts && whatsappAccounts.length === 0" #no-data>
                      <VListItem>
                        <VListItemTitle class="text-caption text-medium-emphasis">
                          No active WhatsApp accounts on your workspace.
                        </VListItemTitle>
                        <template #append>
                          <VBtn size="x-small" variant="text" color="primary"
                            @click="router.push({ name: 'whatsapp-accounts' })">
                            Connect one →
                          </VBtn>
                        </template>
                      </VListItem>
                    </template>
                  </VSelect>
                </VCol>

                <!-- Selected account info banner -->
                <VCol v-if="selectedAccount" cols="12">
                  <VAlert type="success" variant="tonal" rounded="lg" density="compact" icon="$whatsapp">
                    <div class="d-flex align-center justify-space-between flex-wrap gap-2">
                      <div>
                        <span class="font-weight-semibold">{{ selectedAccount.verified_name }}</span>
                        <span class="text-caption text-medium-emphasis ml-2">
                          {{ selectedAccount.display_phone_number ?? selectedAccount.phone_number }}
                          · WABA {{ selectedAccount.waba_id }}
                        </span>
                      </div>
                      <div class="d-flex gap-2">
                        <VChip :color="qualityColor[selectedAccount.quality_rating] ?? 'default'" size="x-small"
                          variant="tonal">
                          Quality: {{ selectedAccount.quality_rating }}
                        </VChip>
                        <VChip size="x-small" variant="tonal" color="primary">
                          {{ tierLabel(selectedAccount.messaging_limit) }} msgs/day
                        </VChip>
                      </div>
                    </div>
                  </VAlert>
                </VCol>

                <!-- Description -->
                <VCol cols="12">
                  <VTextarea v-model="form.description" label="Description"
                    placeholder="Describe what this bot does and who it's for…" variant="outlined" rounded="lg" rows="3"
                    auto-grow hint="Help others understand the purpose of this bot" persistent-hint />
                </VCol>
              </VRow>
            </VCardText>
          </VWindowItem>

          <!-- ── Tab 2: Messages & Language ─────────────────────────────── -->
          <VWindowItem value="messages">
            <VCardText class="pa-6">
              <VRow>
                <!-- Welcome message -->
                <VCol cols="12">
                  <VTextarea v-model="form.welcome_message" label="Welcome Message"
                    placeholder="Hi! 👋 Welcome. How can I help you today?" variant="outlined" rounded="lg" rows="3"
                    hint="Sent when a user first starts a conversation" persistent-hint />
                </VCol>

                <!-- Fallback message -->
                <VCol cols="12">
                  <VTextarea v-model="form.fallback_message" label="Fallback Message *"
                    placeholder="Sorry, I didn't understand that. Please try again." variant="outlined" rounded="lg"
                    rows="3" :rules="[(v) => !!v || 'Required', (v) => v.length >= 5 || 'Too short']"
                    hint="Shown when the bot doesn't understand user input" persistent-hint />
                </VCol>

                <VCol cols="12">
                  <VDivider />
                </VCol>

                <!-- Default language -->
                <VCol cols="12" md="6">
                  <VSelect v-model="form.default_language" :items="languageOptions" item-title="title"
                    item-value="value" label="Default Language *" variant="outlined" rounded="lg"
                    prepend-inner-icon="$web" hint="The main language your bot will use" persistent-hint />
                </VCol>

                <!-- Supported languages -->
                <VCol cols="12" md="6">
                  <VSelect v-model="form.supported_languages" :items="languageOptions" item-title="title"
                    item-value="value" label="Supported Languages" variant="outlined" rounded="lg" multiple chips
                    closable-chips hint="All languages this bot can handle" persistent-hint>
                    <template #chip="{ item, props: chipProps }">
                      <VChip v-bind="chipProps" size="small" color="primary" variant="tonal">
                        {{ item.title }}
                      </VChip>
                    </template>
                  </VSelect>
                </VCol>

                <!-- Summary card -->
                <VCol cols="12">
                  <VCard variant="tonal" color="primary" rounded="lg" class="mt-2">
                    <VCardText class="pa-4">
                      <div class="text-subtitle-2 font-weight-semibold mb-3">Summary</div>
                      <VRow dense>
                        <VCol cols="12" sm="4">
                          <div class="d-flex align-center gap-2">
                            <VIcon size="15">$robot</VIcon>
                            <span class="text-body-2">{{ form.name || "—" }}</span>
                          </div>
                        </VCol>
                        <VCol cols="12" sm="4">
                          <div class="d-flex align-center gap-2">
                            <VIcon size="15" color="success">$whatsapp</VIcon>
                            <span class="text-body-2">
                              {{ selectedAccount?.display_phone_number
                                ?? selectedAccount?.phone_number
                                ?? "No account" }}
                            </span>
                          </div>
                        </VCol>
                        <VCol cols="12" sm="4">
                          <div class="d-flex align-center gap-2">
                            <VIcon size="15">$translate</VIcon>
                            <span class="text-body-2">
                              {{ form.default_language.toUpperCase() }}
                              <template v-if="form.supported_languages.length > 1">
                                + {{ form.supported_languages.length - 1 }} more
                              </template>
                            </span>
                          </div>
                        </VCol>
                      </VRow>
                    </VCardText>
                  </VCard>
                </VCol>
              </VRow>
            </VCardText>
          </VWindowItem>
        </VWindow>

        <VDivider />

        <!-- ── Footer actions ────────────────────────────────────────────── -->
        <VCardActions class="pa-6">
          <VBtn variant="text" color="error" prepend-icon="$close" @click="router.back()">
            Cancel
          </VBtn>

          <VSpacer />

          <VBtn v-if="!isFirstTab" variant="text" color="grey" prepend-icon="$arrowLeft" size="large"
            @click="previousTab">
            Previous
          </VBtn>

          <VBtn v-if="!isLastTab" color="primary" append-icon="$arrowRight" size="large" :disabled="!canNext"
            @click="nextTab">
            Next
          </VBtn>

          <VBtn v-if="isLastTab" color="success" prepend-icon="$check" size="large" :loading="isSubmitting"
            :disabled="isSubmitting || !canSubmit" @click="submit">
            Create Bot
          </VBtn>
        </VCardActions>
      </VCol>
    </VRow>

    <VSnackbar v-model="snack.show" :color="snack.color" :timeout="3500" location="top right" rounded="lg">
      {{ snack.msg }}
    </VSnackbar>
  </div>
</template>

<style scoped>
:deep(.v-tab) {
  text-transform: none;
  letter-spacing: normal;
  font-weight: 500;
}

:deep(.v-tab.v-tab--selected) {
  background-color: rgba(var(--v-theme-primary), 0.05);
}

.gap-2 {
  gap: 8px;
}
</style>