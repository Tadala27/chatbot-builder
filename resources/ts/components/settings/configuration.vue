<script setup lang="ts">
import { ref, computed, onMounted } from "vue";
import axios from "axios";
import { v4 as uuidv4 } from "uuid";
import RichTextEditor from "@/components/RichTextEditor.vue";
import RichTextField from "@/components/RichTextField.vue";
import Swal from "sweetalert2";
const props = defineProps<{ botId: string | number }>();
const emit = defineEmits<{ (e: "saved"): void }>();

// ── View (dialogs | config) ───────────────────────────────────────────────────
const view = ref<"dialogs" | "config">("dialogs");
function toggleView() {
  view.value = view.value === "dialogs" ? "config" : "dialogs";
  if (view.value === "config" && !configLoaded.value) loadConfig();
}

// ── Snackbar ──────────────────────────────────────────────────────────────────
const snackbar = ref({ show: false, message: "", color: "success" });
const showSnackbar = (msg: string, color = "success") => {
  snackbar.value = { show: true, message: msg, color };
};

// ── List state ────────────────────────────────────────────────────────────────
const allDialogs = ref<any[]>([]);
const availableVariables = ref<string[]>([]);
const customFunctions = ref<any[]>([]);
const loadingList = ref(false);
const savingDialog = ref(false);
const deletingId = ref<number | null>(null);
const search = ref("");
const page = ref(1);
const perPage = ref(10);

const filteredDialogs = computed(() => {
  const q = search.value.toLowerCase().trim();
  return q
    ? allDialogs.value.filter((d) => d.title?.toLowerCase().includes(q))
    : allDialogs.value;
});
const pageCount = computed(() =>
  Math.max(1, Math.ceil(filteredDialogs.value.length / perPage.value)),
);
const paginatedDialogs = computed(() => {
  const start = (page.value - 1) * perPage.value;
  return filteredDialogs.value.slice(start, start + perPage.value);
});
function onSearch() {
  page.value = 1;
}

// ── Dialog types ──────────────────────────────────────────────────────────────
type DialogType = "message" | "buttons";

// ── Bot-level actions (go_home | go_back | talk_to_agent) ─────────────────────
interface BotAction {
  id: string;
  kind: "go_home" | "go_back" | "talk_to_agent";
}

interface DialogButton {
  id: string;
  label: string;
  // Each button carries exactly one bot action
  action: BotAction;
}

// ── Dialog form ───────────────────────────────────────────────────────────────
const dialogOpen = ref(false);
const editingDialog = ref<any>(null);

function blankDialogForm() {
  return {
    title: "",
    type: "message" as DialogType,
    message: "",
    is_entry_point: false,
    is_active: true,
    purpose: "",
    buttons: [] as DialogButton[],
  };
}

const dialogForm = ref(blankDialogForm());

function openCreate() {
  editingDialog.value = null;
  dialogForm.value = blankDialogForm();
  dialogOpen.value = true;
}

function openEdit(d: any) {
  editingDialog.value = d;
  // Reconstruct form from saved record
  const isButtons = (d.kind ?? d.type) === "buttons";
  dialogForm.value = {
    title: d.name ?? "",
    purpose: d.purpose ?? "",
    type: isButtons ? "buttons" : "message",
    message: d.config?.text ?? d.message ?? "",
    is_entry_point: d.is_entry_point ?? false,
    is_active: d.is_active ?? true,
    buttons: isButtons
      ? (d.config?.buttons ?? []).map((b: any) => ({
        id: b.id ?? uuidv4(),
        label: b.label ?? "",
        action: b.action ?? { id: uuidv4(), kind: "go_home" },
      }))
      : [],
  };
  dialogOpen.value = true;
}

function closeDialog() {
  dialogOpen.value = false;
  editingDialog.value = null;
  dialogForm.value = blankDialogForm();
}

// ── Button helpers ────────────────────────────────────────────────────────────
const BOT_ACTIONS: {
  value: BotAction["kind"];
  title: string;
  icon: string;
  color: string;
}[] = [
    {
      value: "start_flow",
      title: "Start Flow",
      icon: "custom-play-outline",
      color: "success",
    },
    {
      value: "go_back",
      title: "Go Back",
      icon: "custom-arrow-left-circle",
      color: "warning",
    },
    {
      value: "go_home",
      title: "Go Home",
      icon: "custom-home-outline",
      color: "primary",
    },
    {
      value: "talk_to_agent",
      title: "Talk to Agent",
      icon: "custom-account-voice",
      color: "error",
    },
  ];

function addButton() {
  if (dialogForm.value.buttons.length >= 3) return;
  dialogForm.value.buttons.push({
    id: uuidv4(),
    label: `Option ${dialogForm.value.buttons.length + 1}`,
    action: { id: uuidv4(), kind: "go_home" },
  });
}

function removeButton(idx: number) {
  dialogForm.value.buttons.splice(idx, 1);
}

function onTypeChange() {
  // Reset type-specific fields when switching
  dialogForm.value.buttons = [];
  dialogForm.value.message = "";
}

// ── Config state ──────────────────────────────────────────────────────────────
const configLoaded = ref(false);
const loadingConfig = ref(false);
const savingConfig = ref(false);

const botDialogs = ref<
  { id: number; name: string; purpose: string; kind: string }[]
>([]);
const purposeLabels = ref<string[]>([]);

function blankConfig() {
  return {
    starting_dialog_id: null as number | null,
    welcome_dialog_id: null as number | null,
    session_timeout_minutes: 1440,
    invalid_input_message: "",
    invalid_input_dialog_id: null as number | null,
    max_invalid_attempts: 3,
    invalid_attempts_dialog_id: null as number | null,
    retry_enabled: false,
    retry_dialog_id: null as number | null,
    retry_after_minutes: 60,
    max_retry_attempts: 3,
    home_keywords: [] as string[],
    back_keywords: [] as string[],
    handover_keywords: [] as string[],
    opt_out_keywords: [] as string[],
    opt_in_keywords: [] as string[],
    handover_enabled: false,
    handover_dialog_id_in_hours: null as number | null,
    handover_dialog_id_off_hours: null as number | null,
    handover_unavailable_message: "",
    auto_resolve_after_minutes: 0,
    operating_hours: {
      timezone: "Africa/Blantyre",
      always_open: true,
      schedule: {
        monday: { enabled: true, open: "08:00", close: "17:00" },
        tuesday: { enabled: true, open: "08:00", close: "17:00" },
        wednesday: { enabled: true, open: "08:00", close: "17:00" },
        thursday: { enabled: true, open: "08:00", close: "17:00" },
        friday: { enabled: true, open: "08:00", close: "17:00" },
        saturday: { enabled: false, open: "09:00", close: "13:00" },
        sunday: { enabled: false, open: "09:00", close: "13:00" },
      },
    },
  };
}

const config = ref(blankConfig());
const hasEntryPoint = computed(() => allDialogs.value.some(({ is_entry_point }) => is_entry_point));


const keywordInputs = ref<Record<string, string>>({
  home_keywords: "",
  back_keywords: "",
  handover_keywords: "",
  opt_out_keywords: "",
  opt_in_keywords: "",
});

function addKeyword(field: keyof typeof keywordInputs.value) {
  const val = keywordInputs.value[field].trim().toLowerCase();
  if (!val) return;
  const arr = config.value[field as keyof typeof config.value] as string[];
  if (!arr.includes(val)) arr.push(val);
  keywordInputs.value[field] = "";
}
function removeKeyword(field: string, idx: number) {
  (config.value[field as keyof typeof config.value] as string[]).splice(idx, 1);
}
function onKeywordKeydown(
  e: KeyboardEvent,
  field: keyof typeof keywordInputs.value,
) {
  if (e.key === "Enter" || e.key === ",") {
    e.preventDefault();
    addKeyword(field);
  }
}

const allBotDialogOptions = computed(() =>
  botDialogs.value.map((d) => ({
    title: `${d.name} (${d.kind})`,
    value: d.id,
  })),
);

const DAYS = [
  "monday",
  "tuesday",
  "wednesday",
  "thursday",
  "friday",
  "saturday",
  "sunday",
] as const;

const TIMEZONES = [
  "Africa/Blantyre",
  "Africa/Johannesburg",
  "Africa/Nairobi",
  "Africa/Lagos",
  "Europe/London",
  "Europe/Paris",
  "America/New_York",
  "America/Chicago",
  "America/Los_Angeles",
  "Asia/Dubai",
  "Asia/Kolkata",
  "Asia/Singapore",
  "Australia/Sydney",
  "Pacific/Auckland",
];

const SESSION_TIMEOUT_OPTIONS = [
  { title: "30 minutes", value: 30 },
  { title: "1 hour", value: 60 },
  { title: "2 hours", value: 120 },
  { title: "4 hours", value: 240 },
  { title: "8 hours", value: 480 },
  { title: "12 hours", value: 720 },
  { title: "24 hours", value: 1440 },
  { title: "48 hours", value: 2880 },
  { title: "7 days", value: 10080 },
];

const KEYWORD_GROUPS = [
  {
    field: "home_keywords",
    label: "Home / Menu",
    subtitle: "Restart from the starting dialog",
    color: "primary",
  },
  {
    field: "back_keywords",
    label: "Back",
    subtitle: "Navigate to the previous dialog",
    color: "secondary",
  },
  {
    field: "handover_keywords",
    label: "Handover",
    subtitle: "Transfer to a human agent",
    color: "warning",
  },
  {
    field: "opt_out_keywords",
    label: "Opt-out",
    subtitle: "Unsubscribe the user from messages",
    color: "error",
  },
  {
    field: "opt_in_keywords",
    label: "Opt-in",
    subtitle: "Re-subscribe the user to messages",
    color: "success",
  },
] as const;

// ── API ───────────────────────────────────────────────────────────────────────
async function loadDialogs() {
  loadingList.value = true;
  try {
    const response = await axios.get(`/api/bots/${props.botId}/bot-dialogs`);
    allDialogs.value.splice(
      0,
      allDialogs.value.length,
      ...(response.data.data ?? []),
    );
    availableVariables.value = (response.data.variables ?? []).map((v: any) => v.key);
    customFunctions.value = (response.data.functions ?? []).map((f: any) => f.name);
    const purposeMap = response.data?.purposes ?? {};

    purposeLabels.value = Object.entries(purposeMap).map(([key, label]) => ({
      title: label,
      value: key,
    }));


  } catch {
    showSnackbar("Failed to load dialogs", "error");
  } finally {
    loadingList.value = false;
  }
}

async function saveDialog() {
  if (!dialogForm.value.title.trim()) {
    showSnackbar("Title is required", "error");
    return;
  }
  if (
    dialogForm.value.type === "buttons" &&
    dialogForm.value.buttons.length === 0
  ) {
    showSnackbar("Add at least one button", "error");
    return;
  }

  savingDialog.value = true;
  const isEditing = !!editingDialog.value;

  try {
    // Build config based on type
    const configPayload =
      dialogForm.value.type === "message"
        ? { text: dialogForm.value.message }
        : {
          text: dialogForm.value.message,
          buttons: dialogForm.value.buttons.map((b) => ({
            id: b.id,
            label: b.label,
            action: { id: b.action.id, kind: b.action.kind },
          })),
        };

    const payload = {
      title: dialogForm.value.title,
      purpose: dialogForm.value.purpose,
      kind: dialogForm.value.type, // 'message' | 'buttons'
      is_entry_point: dialogForm.value.is_entry_point,
      is_active: dialogForm.value.is_active,
      config: configPayload,
    };

    if (isEditing) {
      const { data } = await axios.put(
        `/api/bots/${props.botId}/bot-dialogs/${editingDialog.value.id}`,
        payload,
      );
      const idx = allDialogs.value.findIndex((d) => d.id === data.data.id);
      if (idx !== -1) allDialogs.value.splice(idx, 1, data.data);
      else allDialogs.value.push(data.data);
    } else {
      const { data } = await axios.post(
        `/api/bots/${props.botId}/bot-dialogs`,
        payload,
      );
      allDialogs.value.push(data.data);
      page.value = pageCount.value;
    }

    closeDialog();
    showSnackbar(isEditing ? "Dialog updated" : "Dialog created");
  } catch (err: any) {
    showSnackbar(err.response?.data?.message ?? err.message, "error");
  } finally {
    savingDialog.value = false;
  }
}

async function confirmDelete(d: any) {
  const { isConfirmed } = await Swal.fire({
    title: `Delete "${d.name}"?`,
    text: "This cannot be undone.",
    icon: "warning",
    showCancelButton: true,
    confirmButtonColor: "#ef4444",
    confirmButtonText: "Delete",
  });
  if (!isConfirmed) return;
  deletingId.value = d.id;
  try {
    await axios.delete(`/api/bots/${props.botId}/bot-dialogs/${d.id}`);
    const idx = allDialogs.value.findIndex((x) => x.id === d.id);
    if (idx !== -1) allDialogs.value.splice(idx, 1);
    if (page.value > pageCount.value) page.value = Math.max(1, pageCount.value);
    showSnackbar("Dialog deleted");
  } catch (err: any) {
    showSnackbar(err.response?.data?.message ?? err.message, "error");
  } finally {
    deletingId.value = null;
  }
}

async function loadConfig() {
  loadingConfig.value = true;
  try {
    const [cfgRes, dlgRes] = await Promise.all([
      axios.get(`/api/bots/${props.botId}/configuration`),
      axios.get(`/api/bots/${props.botId}/bot-dialogs`),
    ]);
    if (cfgRes.data?.data) {
      const d = cfgRes.data.data;
      const blank = blankConfig();
      config.value = {
        ...blank,
        ...d,
        home_keywords: Array.isArray(d.home_keywords) ? d.home_keywords : [],
        back_keywords: Array.isArray(d.back_keywords) ? d.back_keywords : [],
        handover_keywords: Array.isArray(d.handover_keywords)
          ? d.handover_keywords
          : [],
        opt_out_keywords: Array.isArray(d.opt_out_keywords)
          ? d.opt_out_keywords
          : [],
        opt_in_keywords: Array.isArray(d.opt_in_keywords)
          ? d.opt_in_keywords
          : [],
        operating_hours: {
          ...blank.operating_hours,
          ...(d.operating_hours ?? {}),
          schedule: {
            ...blank.operating_hours.schedule,
            ...(d.operating_hours?.schedule ?? {}),
          },
        },
      };
    }
    botDialogs.value = dlgRes.data?.data ?? [];

    configLoaded.value = true;
  } catch {
    showSnackbar("Failed to load configuration", "error");
  } finally {
    loadingConfig.value = false;
  }
}

async function saveConfig() {
  savingConfig.value = true;
  try {
    await axios.post(`/api/bots/${props.botId}/configuration`, config.value);
    showSnackbar("Configuration saved successfully");
    emit("saved");
  } catch (err: any) {
    showSnackbar(err.response?.data?.message ?? "Failed to save", "error");
  } finally {
    savingConfig.value = false;
  }
}



onMounted(loadDialogs);
defineExpose({ loadDialogs, loadConfig });
</script>

<template>
  <div>
    <div class="slider-track">
      <!-- ════════════ DIALOGS VIEW ════════════════════════════════════ -->
      <div class="slide-panel" :class="view === 'dialogs' ? 'panel--visible' : 'panel--hidden-left'">
        <v-row align="center" class="mb-4">
          <v-col cols="12" sm="7">
            <p class="text-h6 font-weight-bold mb-0">Bot Dialogs</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Message and button dialogs for this bot.
            </p>
          </v-col>
          <v-col cols="12" sm="5" class="d-flex justify-end ga-2">
            <v-btn variant="outlined" size="small" @click="toggleView">
              <template #prepend>
                <SvgSprite name="custom-setting-outline-1" style="width: 16px; height: 16px" />
              </template>
              Bot Configuration
            </v-btn>
            <v-btn color="primary" size="small" @click="openCreate">
              <template #prepend>
                <SvgSprite name="custom-plus" style="width: 16px; height: 16px" />
              </template>
              Add Dialog
            </v-btn>
          </v-col>
        </v-row>

        <v-row align="center" class="mb-3">
          <v-col cols="12" sm="5">
            <v-text-field v-model="search" label="Search dialogs…" variant="outlined" density="comfortable" clearable
              hide-details @update:model-value="onSearch">
              <template #prepend-inner>
                <SvgSprite name="custom-search" class="text-lightText" style="width: 20px; height: 20px" />
              </template>
            </v-text-field>
          </v-col>
          <v-col cols="12" sm="7" class="d-flex justify-end align-center ga-3">
            <span class="text-caption text-medium-emphasis">
              {{ filteredDialogs.length }}
              {{ filteredDialogs.length === 1 ? "dialog" : "dialogs" }}
            </span>
            <v-select v-model="perPage" :items="[5, 10, 25, 50]" label="Per page" variant="outlined" density="compact"
              hide-details style="max-width: 100px" @update:model-value="page = 1" />
          </v-col>
        </v-row>

        <v-table class="bordered-table" density="comfortable">
          <thead class="table-head">
            <tr>
              <th class="text-left pa-4">Title</th>
              <th class="text-left pa-4">Type</th>
              <th class="text-left pa-4">Preview</th>
              <th class="text-center pa-4">Entry point</th>
              <th class="text-left pa-4">Status</th>
              <th class="text-left pa-4" style="width: 90px">Actions</th>
            </tr>
          </thead>

          <tbody v-if="loadingList">
            <tr>
              <td colspan="6" class="text-center py-12">
                <v-progress-circular indeterminate color="primary" size="44" />
              </td>
            </tr>
          </tbody>

          <tbody v-else>
            <tr v-if="!filteredDialogs.length">
              <td colspan="6" class="text-center py-10">
                <SvgSprite name="custom-message-outline" class="text-grey mb-2" style="width: 48px; height: 48px" />
                <p class="text-body-2 text-medium-emphasis mb-3">
                  {{
                    search ? "No dialogs match your search." : "No dialogs yet."
                  }}
                </p>
                <v-btn v-if="!search" color="primary" size="small" @click="openCreate">
                  <template #prepend>
                    <SvgSprite name="custom-plus" style="width: 14px; height: 14px" />
                  </template>
                  Add Dialog
                </v-btn>
              </td>
            </tr>

            <tr v-for="d in paginatedDialogs" :key="d.id" class="table-row">
              <td class="pa-4 font-weight-medium">{{ d.name }}</td>
              <td class="pa-4">
                <v-chip size="small" variant="tonal" :color="(d.kind ?? d.type) === 'buttons' ? 'secondary' : 'primary'
                  ">
                  {{ (d.kind ?? d.type) === "buttons" ? "Buttons" : "Message" }}
                </v-chip>
              </td>
              <td class="pa-4">
                <span class="text-caption text-medium-emphasis text-truncate d-block" style="max-width: 220px">
                  {{ d.config?.text || d.message || "—" }}
                </span>
              </td>
              <td class="pa-4 text-center">
                <v-chip v-if="d.is_entry_point" size="small" color="success" variant="tonal">
                  Entry point
                </v-chip>
                <span v-else class="text-caption text-medium-emphasis">—</span>
              </td>
              <td class="pa-4">
                <v-chip size="small" :color="d.is_active ? 'success' : 'default'" variant="tonal">
                  {{ d.is_active ? "Active" : "Inactive" }}
                </v-chip>
              </td>
              <td class="pa-4">
                <div class="d-flex align-center ga-1 cursor-pointer">
                  <SvgSprite name="custom-edit-outline" class="action-icon text-primary me-2" @click="openEdit(d)" />
                  <SvgSprite v-if="deletingId !== d.id" name="custom-trash-fill" class="action-icon text-error"
                    @click="confirmDelete(d)" />
                  <v-progress-circular v-else indeterminate size="16" width="2" color="error" />
                </div>
              </td>
            </tr>
          </tbody>
        </v-table>

        <div v-if="pageCount > 1" class="d-flex justify-center mt-4">
          <v-pagination v-model="page" :length="pageCount" :total-visible="6" density="comfortable" rounded="circle" />
        </div>
      </div>

      <!-- ════════════ CONFIG VIEW ══════════════════════════════════════ -->
      <div class="slide-panel" :class="view === 'config' ? 'panel--visible' : 'panel--hidden-right'">
        <v-row align="center" class="mb-6">
          <v-col>
            <p class="text-h6 font-weight-bold mb-0">Bot Configuration</p>
            <p class="text-body-2 text-medium-emphasis mb-0">
              Control how your bot behaves, recovers from errors, and hands off
              to humans.
            </p>
          </v-col>
          <v-col cols="auto" class="d-flex ga-2">
            <v-btn variant="outlined" size="small" @click="toggleView">
              <template #prepend>
                <SvgSprite name="custom-message-outline" style="width: 16px; height: 16px" />
              </template>
              Bot Dialogs
            </v-btn>
            <v-btn color="primary" :loading="savingConfig" size="small" @click="saveConfig">
              <template #prepend>
                <SvgSprite name="custom-content-save-outline" style="width: 16px; height: 16px" />
              </template>
              Save Configuration
            </v-btn>
          </v-col>
        </v-row>

        <div v-if="loadingConfig" class="d-flex flex-column align-center py-16 ga-3">
          <v-progress-circular indeterminate color="primary" size="44" width="3" />
          <p class="text-body-2 text-medium-emphasis">Loading configuration…</p>
        </div>

        <template v-else>
          <v-row dense><v-col cols="12">
              <!-- 1. General -->
              <v-card variant="outlined" rounded="lg" class="mb-4">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-setting-outline-1" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">General</v-card-title>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <v-row>
                    <v-col cols="12" sm="6">
                      <p class="text-subtitle-2 font-weight-medium mb-1">
                        Starting dialog
                      </p>
                      <p class="text-caption text-medium-emphasis mb-3">
                        Overrides the flow's entry-point dialog for all new
                        conversations.
                      </p>
                      <v-select v-model="config.starting_dialog_id" :items="allBotDialogOptions" label="Starting Dialog"
                        variant="outlined" density="compact" clearable
                        hint="Leave blank to use the published flow's own entry point." persistent-hint />
                    </v-col>
                    <v-col cols="12" sm="6">
                      <p class="text-subtitle-2 font-weight-medium mb-1">
                        Welcome dialog
                      </p>
                      <p class="text-caption text-medium-emphasis mb-3">
                        Sent once to first-time contacts before the normal flow
                        begins.
                      </p>
                      <v-select v-model="config.welcome_dialog_id" :items="allBotDialogOptions" label="Welcome Dialog"
                        variant="outlined" density="compact" clearable
                        hint="Leave blank to skip the welcome step entirely." persistent-hint />
                    </v-col>
                  </v-row>
                  <v-divider class="my-5" />
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Session timeout
                  </p>
                  <p class="text-caption text-medium-emphasis mb-3">
                    How long before an idle conversation is marked abandoned.
                  </p>
                  <v-select v-model="config.session_timeout_minutes" :items="SESSION_TIMEOUT_OPTIONS"
                    label="Timeout Duration" variant="outlined" density="compact" style="max-width: 260px" />
                </v-card-text>
              </v-card>

              <!-- 2. Fallback -->
              <v-card variant="outlined" rounded="lg" class="mb-4">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-alert-outline" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">Fallback &amp; Input Handling</v-card-title>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Invalid input
                  </p>
                  <p class="text-caption text-medium-emphasis mb-3">
                    Triggered when the user's reply doesn't match any expected
                    input.
                  </p>
                  <v-text-field v-model="config.invalid_input_message" label="Fallback Message (plain text)"
                    variant="outlined" density="compact"
                    placeholder="Sorry, I didn't understand that. Please choose one of the options." class="mb-3" />
                  <v-select v-model="config.invalid_input_dialog_id" :items="allBotDialogOptions"
                    label="Fallback Dialog (optional)" variant="outlined" density="compact" clearable
                    hint="Runs this bot dialog instead of (or after) the plain text message." persistent-hint />
                  <v-divider class="my-5" />
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Max invalid attempts
                  </p>
                  <p class="text-caption text-medium-emphasis mb-3">
                    After this many consecutive failed replies the conversation
                    escalates.
                  </p>
                  <v-row>
                    <v-col cols="12" sm="4">
                      <v-text-field v-model.number="config.max_invalid_attempts" label="Max Attempts" type="number"
                        :min="1" :max="10" variant="outlined" density="compact" />
                    </v-col>
                    <v-col cols="12" sm="8">
                      <v-select v-model="config.invalid_attempts_dialog_id" :items="allBotDialogOptions"
                        label="Escalation Bot Dialog" variant="outlined" density="compact" clearable
                        hint="Point to a handover or simplified-menu bot dialog." persistent-hint />
                    </v-col>
                  </v-row>
                  <v-alert type="warning" variant="tonal" density="compact" rounded="lg" class="mt-2" :icon="false">
                    <template #prepend>
                      <SvgSprite name="custom-lightbulb-outline" style="width: 20px; height: 20px" />
                    </template>
                    Point the escalation dialog to a human handover or a
                    simplified menu to avoid dead ends.
                  </v-alert>
                </v-card-text>
              </v-card>

              <!-- 3. Re-engagement -->
              <v-card variant="outlined" rounded="lg" class="mb-4">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-reload" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">Re-engagement</v-card-title>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Nudge silent users
                  </p>
                  <p class="text-caption text-medium-emphasis mb-3">
                    Automatically follow up with users who stopped responding
                    mid-flow.
                  </p>
                  <v-switch v-model="config.retry_enabled" label="Enable re-engagement" color="primary" hide-details
                    inset density="compact" />
                  <v-expand-transition>
                    <div v-if="config.retry_enabled">
                      <v-sheet border rounded="lg" color="surface-variant" class="pa-4 mt-4">
                        <v-row>
                          <v-col cols="12" sm="6">
                            <v-text-field v-model.number="config.retry_after_minutes"
                              label="Wait Before Retry (minutes)" type="number" :min="5" variant="outlined"
                              density="compact" hint="Minimum 5 minutes." persistent-hint bg-color="surface" />
                          </v-col>
                          <v-col cols="12" sm="6">
                            <v-text-field v-model.number="config.max_retry_attempts" label="Max Retry Attempts"
                              type="number" :min="1" :max="5" variant="outlined" density="compact" hint="1–5 attempts."
                              persistent-hint bg-color="surface" />
                          </v-col>
                        </v-row>
                        <v-select v-model="config.retry_dialog_id" :items="allBotDialogOptions" label="Retry Bot Dialog"
                          variant="outlined" density="compact" clearable class="mt-3" hint="e.g. 'Are you still there?'"
                          persistent-hint bg-color="surface" />
                      </v-sheet>
                    </div>
                  </v-expand-transition>
                </v-card-text>
              </v-card>

              <!-- 4. Keywords -->
              <v-card variant="outlined" rounded="lg" class="mb-4">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-tag-outline" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">Keyword Triggers</v-card-title>
                  <v-card-subtitle>
                    Matched case-insensitively. Press <kbd>Enter</kbd> or
                    <kbd>,</kbd> to add.
                  </v-card-subtitle>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <v-row>
                    <v-col v-for="grp in KEYWORD_GROUPS" :key="grp.field" cols="12" md="6">
                      <v-card variant="outlined" rounded="lg" height="100%">
                        <v-card-item class="pb-1">
                          <template #prepend>
                            <SvgSprite name="custom-tag-fill" style="width: 16px; height: 16px" />
                          </template>
                          <v-card-title class="text-body-2 font-weight-semibold">{{ grp.label }}</v-card-title>
                          <v-card-subtitle class="text-caption">{{
                            grp.subtitle
                          }}</v-card-subtitle>
                        </v-card-item>
                        <v-card-text class="pt-1">
                          <div class="d-flex flex-wrap ga-2 mb-3" style="min-height: 32px">
                            <v-chip v-for="(tag, i) in config[grp.field] as string[]" :key="i" size="small" closable
                              :color="grp.color" variant="tonal" @click:close="removeKeyword(grp.field, i)">{{ tag
                              }}</v-chip>
                            <span v-if="!(config[grp.field] as string[]).length"
                              class="text-caption text-disabled align-self-center">
                              No keywords yet
                            </span>
                          </div>
                          <div class="d-flex ga-2">
                            <v-text-field v-model="keywordInputs[grp.field]" placeholder="Type a keyword…"
                              variant="outlined" density="compact" hide-details style="flex: 1"
                              @keydown="onKeywordKeydown($event, grp.field)" />
                            <v-btn icon size="small" variant="tonal" :color="grp.color" @click="addKeyword(grp.field)">
                              <SvgSprite name="custom-plus" style="width: 14px; height: 14px" />
                            </v-btn>
                          </div>
                        </v-card-text>
                      </v-card>
                    </v-col>
                  </v-row>
                </v-card-text>
              </v-card>

              <!-- 5. Handover -->
              <v-card variant="outlined" rounded="lg" class="mb-4">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-swap-arrows-circle" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">Human Handover</v-card-title>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Agent routing
                  </p>
                  <p class="text-caption text-medium-emphasis mb-3">
                    Route conversations to a live agent based on availability.
                  </p>
                  <v-switch v-model="config.handover_enabled" label="Enable human handover" color="primary" hide-details
                    inset density="compact" />
                  <v-expand-transition>
                    <div v-if="config.handover_enabled">
                      <v-sheet border rounded="lg" class="pa-4 mt-4">
                        <v-row>
                          <v-col cols="12" sm="6">
                            <v-select v-model="config.handover_dialog_id_in_hours" :items="allBotDialogOptions"
                              label="Bot Dialog — Within Hours" variant="outlined" density="compact" clearable
                              hint="Shown when an agent is available." persistent-hint bg-color="surface" />
                          </v-col>
                          <v-col cols="12" sm="6">
                            <v-select v-model="config.handover_dialog_id_off_hours" :items="allBotDialogOptions"
                              label="Bot Dialog — Off Hours" variant="outlined" density="compact" clearable
                              hint="Shown outside operating hours." persistent-hint bg-color="surface" />
                          </v-col>
                        </v-row>
                        <v-text-field v-model="config.handover_unavailable_message" label="Unavailable Message"
                          variant="outlined" density="compact" class="mt-3"
                          placeholder="All agents are currently busy. We'll get back to you shortly."
                          hint="Plain text fallback when no handover dialog is configured." persistent-hint
                          bg-color="surface" />
                        <v-row class="mt-3">
                          <v-col cols="12" sm="5">
                            <v-text-field v-model.number="config.auto_resolve_after_minutes"
                              label="Auto-resolve After (minutes)" type="number" :min="0" variant="outlined"
                              density="compact" hint="Set 0 to never auto-resolve." persistent-hint
                              bg-color="surface" />
                          </v-col>
                        </v-row>
                        <v-alert type="info" variant="tonal" density="compact" rounded="lg" :icon="false" class="mt-4">
                          <template #prepend>
                            <SvgSprite name="custom-info-circle-outline" style="width: 20px; height: 20px" />
                          </template>
                          Operating hours (configured below) determine which
                          handover bot dialog is selected at runtime.
                        </v-alert>
                      </v-sheet>
                    </div>
                  </v-expand-transition>
                </v-card-text>
              </v-card>

              <!-- 6. Operating Hours -->
              <v-card variant="outlined" rounded="lg" class="mb-6">
                <v-card-item class="pb-0">
                  <template #prepend>
                    <SvgSprite name="custom-clock-outline" style="width: 20px; height: 20px" />
                  </template>
                  <v-card-title class="text-body-1 font-weight-bold">Operating Hours</v-card-title>
                </v-card-item>
                <v-divider class="mt-3" />
                <v-card-text>
                  <p class="text-subtitle-2 font-weight-medium mb-1">
                    Availability window
                  </p>
                  <p class="text-caption text-medium-emphasis mb-4">
                    Define when your team is reachable.
                  </p>
                  <v-row align="center" class="mb-2">
                    <v-col cols="12" sm="5">
                      <v-select v-model="config.operating_hours.timezone" :items="TIMEZONES" label="Timezone"
                        variant="outlined" density="compact" hide-details />
                    </v-col>
                    <v-col cols="12" sm="7">
                      <v-switch v-model="config.operating_hours.always_open" label="Always open (24 / 7)"
                        color="success" hide-details inset density="compact" />
                    </v-col>
                  </v-row>
                  <v-expand-transition>
                    <div v-if="!config.operating_hours.always_open">
                      <v-list lines="one" class="mt-2 pa-0">
                        <v-list-item v-for="day in DAYS" :key="day" :disabled="!config.operating_hours.schedule[day].enabled
                          " rounded="lg" class="mb-1 px-3" border>
                          <template #prepend>
                            <v-checkbox v-model="config.operating_hours.schedule[day].enabled
                              " :label="day.charAt(0).toUpperCase() + day.slice(1)
                                " density="compact" hide-details color="primary" style="min-width: 140px"
                              @click.stop />
                          </template>
                          <div class="d-flex align-center ga-2">
                            <v-text-field v-model="config.operating_hours.schedule[day].open
                              " type="time" variant="outlined" density="compact" hide-details :disabled="!config.operating_hours.schedule[day].enabled
                                " style="width: 130px; flex-shrink: 0" />
                            <span class="text-medium-emphasis text-body-2">—</span>
                            <v-text-field v-model="config.operating_hours.schedule[day].close
                              " type="time" variant="outlined" density="compact" hide-details :disabled="!config.operating_hours.schedule[day].enabled
                                " style="width: 130px; flex-shrink: 0" />
                          </div>
                          <template #append>
                            <v-chip :color="config.operating_hours.schedule[day].enabled
                              ? 'success'
                              : 'default'
                              " size="x-small" variant="tonal">
                              {{
                                config.operating_hours.schedule[day].enabled
                                  ? "Open"
                                  : "Closed"
                              }}
                            </v-chip>
                          </template>
                        </v-list-item>
                      </v-list>
                    </div>
                  </v-expand-transition>
                  <v-alert v-if="config.operating_hours.always_open" type="success" variant="tonal" density="compact"
                    rounded="lg" :icon="false" class="mt-3">
                    <template #prepend>
                      <SvgSprite name="custom-check-circle-fill" style="width: 20px; height: 20px" />
                    </template>
                    The bot is treated as always available — the within-hours
                    handover dialog will always be used.
                  </v-alert>
                </v-card-text>
              </v-card>

              <!-- Bottom save -->
              <div class="d-flex justify-end mb-2">
                <v-btn color="primary" :loading="savingConfig" @click="saveConfig">
                  <template #prepend>
                    <SvgSprite name="custom-content-save-outline" style="width: 16px; height: 16px" />
                  </template>
                  Save Configuration
                </v-btn>
              </div>
            </v-col></v-row>
        </template>
      </div>
    </div>

    <!-- ════════════ CREATE / EDIT DIALOG ════════════════════════════════ -->
    <v-dialog v-model="dialogOpen" max-width="680" persistent scrollable>
      <v-card rounded="lg">
        <v-card-item class="pb-0 pt-4 px-5">
          <template #prepend>
            <SvgSprite name="custom-message-outline" style="width: 20px; height: 20px" />
          </template>
          <v-card-title class="text-body-1 font-weight-bold">
            {{ editingDialog ? `Edit — ${editingDialog.title}` : "New Dialog" }}
          </v-card-title>
          <template #append>
            <v-btn icon size="small" variant="text" @click="closeDialog">
              <SvgSprite name="custom-close" style="width: 24px; height: 24px;transform: rotate(40deg)" />
            </v-btn>
          </template>
        </v-card-item>

        <v-divider class="mt-3" />

        <v-card-text class="px-5 pt-4">
          <!-- Title + type row -->
          <v-row class="mb-1">
            <v-col cols="12" sm="4">
              <v-text-field v-model="dialogForm.title" label="Dialog Title *" variant="outlined" density="compact"
                placeholder="e.g. Welcome message" autofocus />
            </v-col>
            <v-col cols="12" sm="4">
              <VAutocomplete v-model="dialogForm.purpose" :items="purposeLabels" label="Dialog Title *"
                variant="outlined" density="compact" clearable />
            </v-col>
            <v-col cols="12" sm="4">
              <!-- Type toggle — clean button group -->
              <div class="d-flex ga-2 pt-1">
                <v-btn :variant="dialogForm.type === 'message' ? 'flat' : 'outlined'"
                  :color="dialogForm.type === 'message' ? 'primary' : undefined" size="small" style="flex: 1" @click="
                    dialogForm.type = 'message';
                  onTypeChange();
                  ">
                  <template #prepend>
                    <SvgSprite name="custom-message-outline" style="width: 14px; height: 14px" />
                  </template>
                  Message
                </v-btn>
                <v-btn :variant="dialogForm.type === 'buttons' ? 'flat' : 'outlined'" :color="dialogForm.type === 'buttons' ? 'secondary' : undefined
                  " size="small" style="flex: 1" @click="
                    dialogForm.type = 'buttons';
                  onTypeChange();
                  ">
                  <template #prepend>
                    <SvgSprite name="custom-view-grid-outline" style="width: 14px; height: 14px; " />
                  </template>
                  Buttons
                </v-btn>
              </div>
            </v-col>
          </v-row>

          <!-- Message body (both types have a message) -->
          <p class="text-subtitle-2 font-weight-medium mb-1">
            {{
              dialogForm.type === "buttons"
                ? "Message above buttons"
                : "Message"
            }}
          </p>
          <p class="text-caption text-medium-emphasis mb-2">
            {{
              dialogForm.type === "buttons"
                ? "Text shown to the user before the button choices."
                : "The text that will be sent to the user when this dialog runs."
            }}
          </p>
          <RichTextEditor v-model="dialogForm.message" label="Message text" placeholder="Type your message…"
            :available-variables="availableVariables" :available-functions="customFunctions" :show-formatting="true"
            field-type="body" :max-length="4096" :min-rows="3" :max-rows="8" />

          <!-- ── Buttons section (only when type = buttons) ─────── -->
          <template v-if="dialogForm.type === 'buttons'">
            <v-divider class="my-4" />

            <div class="d-flex align-center justify-space-between mb-3">
              <div>
                <span class="text-subtitle-2 font-weight-bold">
                  Buttons ({{ dialogForm.buttons.length }}/3)
                </span>
                <span class="text-caption text-medium-emphasis ml-2">
                  Each button triggers a bot-level action
                </span>
              </div>
              <v-btn size="x-small" variant="outlined" :disabled="dialogForm.buttons.length >= 3" @click="addButton">
                <template #prepend>
                  <SvgSprite name="custom-plus" style="width: 12px; height: 12px" />
                </template>
                Add Button
              </v-btn>
            </div>

            <v-card v-for="(btn, bIdx) in dialogForm.buttons" :key="btn.id" variant="outlined" rounded="lg"
              class="mb-3">
              <v-card-text class="pa-3">
                <div class="d-flex align-center ga-2 mb-3">
                  <!-- Button label -->
                  <div style="flex: 1">
                    <RichTextField v-model="btn.label" label="Button label" :available-variables="availableVariables"
                      :available-functions="customFunctions" field-type="button" :max-length="20" show-variable-picker
                      density="compact" />
                  </div>
                  <!-- Remove -->
                  <v-btn icon size="x-small" variant="text" color="error" class="mt-n3" @click="removeButton(bIdx)">
                    <SvgSprite name="custom-trash-fill" style="width: 14px; height: 14px" />
                  </v-btn>
                </div>

                <!-- Bot-level action selector -->
                <p class="text-caption text-medium-emphasis mb-2">
                  Action when tapped
                </p>
                <div class="d-flex ga-2 flex-wrap">
                  <v-btn v-for="act in BOT_ACTIONS" :key="act.value" :variant="btn.action.kind === act.value ? 'flat' : 'outlined'
                    " :color="btn.action.kind === act.value ? act.color : undefined
                      " size="x-small" @click="btn.action = { id: uuidv4(), kind: act.value }">
                    <template #prepend>
                      <SvgSprite :name="act.icon" style="width: 12px; height: 12px" />
                    </template>
                    {{ act.title }}
                  </v-btn>
                </div>
              </v-card-text>
            </v-card>

            <v-alert v-if="!dialogForm.buttons.length" type="info" variant="tonal" density="compact" rounded="lg"
              :icon="false">
              <template #prepend>
                <SvgSprite name="custom-info-circle-outline" style="width: 18px; height: 18px" />
              </template>
              Add up to 3 buttons. Each triggers a bot navigation action.
            </v-alert>
          </template>

          <!-- Flags -->
          <v-row class="mt-4">
            <v-col cols="12" sm="6">
              <v-switch v-model="dialogForm.is_entry_point"
                :disabled="hasEntryPoint && (!editingDialog || !editingDialog.is_entry_point)" label="Entry point"
                color="success" inset density="compact" hide-details />
            </v-col>
            <v-col cols="12" sm="6">
              <v-switch v-model="dialogForm.is_active" label="Active" color="primary" inset density="compact"
                hide-details />
            </v-col>
          </v-row>
        </v-card-text>

        <v-divider />

        <v-card-actions class="px-5 py-3">
          <v-spacer />
          <v-btn variant="outlined" @click="closeDialog">Cancel</v-btn>
          <v-btn color="primary" :loading="savingDialog" @click="saveDialog">
            {{ editingDialog ? "Update Dialog" : "Create Dialog" }}
          </v-btn>
        </v-card-actions>
      </v-card>
    </v-dialog>

    <!-- Snackbar -->
    <v-snackbar v-model="snackbar.show" :color="snackbar.color" location="top right" :timeout="3500" rounded="lg">
      <div class="d-flex align-center ga-2">
        <SvgSprite :name="snackbar.color === 'success'
          ? 'custom-check-circle-outline'
          : 'custom-alert-circle-outline'
          " style="width: 16px; height: 16px" />
        {{ snackbar.message }}
      </div>
      <template #actions>
        <v-btn variant="text" size="small" @click="snackbar.show = false">Dismiss</v-btn>
      </template>
    </v-snackbar>
  </div>
</template>

<style scoped lang="scss">
.slider-track {
  position: relative;
  width: 100%;
}

.slide-panel {
  width: 100%;
  transition:
    transform 0.35s cubic-bezier(0.4, 0, 0.2, 1),
    opacity 0.25s ease;
  will-change: transform, opacity;

  &.panel--visible {
    transform: translateX(0);
    opacity: 1;
    pointer-events: all;
    position: relative;
  }

  &.panel--hidden-left {
    transform: translateX(-100%);
    opacity: 0;
    pointer-events: none;
    position: absolute;
    inset: 0;
  }

  &.panel--hidden-right {
    transform: translateX(100%);
    opacity: 0;
    pointer-events: none;
    position: absolute;
    inset: 0;
  }
}

.bordered-table {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 10px;
  overflow: hidden;
}

.table-head th {
  font-size: 0.7rem !important;
  font-weight: 700 !important;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: rgba(var(--v-theme-on-surface), 0.5) !important;
  background-color: rgba(var(--v-theme-on-surface), 0.03);
  border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)) !important;
}

.table-row {
  transition: background 0.12s;

  &:hover {
    background: rgba(var(--v-theme-primary), 0.04);
  }

  &:not(:last-child) td {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  }
}

kbd {
  display: inline-block;
  padding: 1px 5px;
  font-size: 0.72rem;
  font-family: monospace;
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 4px;
  background: rgba(var(--v-theme-on-surface), 0.05);
}
</style>
