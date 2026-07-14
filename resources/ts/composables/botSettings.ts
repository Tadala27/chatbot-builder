/**
 * resources/ts/botSettings.ts
 */
import { ref, isRef, type Ref } from "vue";
import axios from "axios";

/**
 * A single button (or list row) on an interactive config-level dialog.
 * `kind` is intentionally restricted to the same vocabulary
 * SystemActionHandler::execute() switches on in the backend — a button
 * built here and a button on a flow dialog run through identical code.
 */
export type SystemActionKind =
  | "start_flow"
  | "go_home"
  | "go_back"
  | "talk_to_agent";

export const SYSTEM_ACTION_OPTIONS: {
  value: SystemActionKind;
  title: string;
  hint: string;
}[] = [
  {
    value: "go_home",
    title: "Go to home menu",
    hint: "Clears history and jumps to the entry-point dialog",
  },
  {
    value: "go_back",
    title: "Go back",
    hint: "Returns to the previous dialog in this conversation",
  },
  {
    value: "talk_to_agent",
    title: "Talk to an agent",
    hint: "Hands the conversation off to a human",
  },
  {
    value: "start_flow",
    title: "Restart flow",
    hint: "Jumps to the entry-point dialog without clearing history",
  },
];

export interface DialogButton {
  id: string;
  label: string;
  kind: SystemActionKind;
}

export interface DialogListRow {
  id: string;
  label: string;
  kind: SystemActionKind;
}

export interface DialogListSection {
  title: string | null;
  rows: DialogListRow[];
}

export interface DialogConfig {
  text?: string | null;
  buttons?: DialogButton[];
  sections?: DialogListSection[];
}

export interface DialogOption {
  id: string;
  label: string;
  kind: "message" | "buttons" | "list";
  is_entry_point: boolean;
  display: string;
  config?: DialogConfig;
  purpose?: string;
}

export interface DialogFormInput {
  purpose: string;
  name: string;
  description?: string | null;
  kind: "message" | "buttons" | "list";
  is_entry_point?: boolean;
  is_active?: boolean;
  config: DialogConfig;
}

export interface DaySchedule {
  enabled: boolean;
  open: string | null;
  close: string | null;
  timezone: string | null;
}

export type OperatingHours = Record<
  | "monday"
  | "tuesday"
  | "wednesday"
  | "thursday"
  | "friday"
  | "saturday"
  | "sunday",
  DaySchedule
>;

export const DAYS_OF_WEEK: Array<keyof OperatingHours> = [
  "monday",
  "tuesday",
  "wednesday",
  "thursday",
  "friday",
  "saturday",
  "sunday",
];

export interface BotConfiguration {
  id?: string;
  bot_id: string;
  starting_dialog_id: string | null;
  welcome_dialog_id: string | null;
  session_timeout_minutes: number | null;

  invalid_input_message: string | null;
  invalid_input_dialog_id: string | null;
  max_invalid_attempts: number | null;
  invalid_attempts_dialog_id: string | null;

  retry_enabled: boolean;
  retry_dialog_id: string | null;
  retry_after_minutes: number | null;
  max_retry_attempts: number | null;

  home_keywords: string[];
  back_keywords: string[];
  handover_keywords: string[];
  opt_out_keywords: string[];
  opt_in_keywords: string[];

  handover_enabled: boolean;
  handover_dialog_id_in_hours: number | null;
  handover_dialog_id_off_hours: number | null;
  handover_unavailable_message: string | null;
  auto_resolve_after_minutes: number | null;

  operating_hours: OperatingHours;

  // Hydrated relations the backend eager-loads — present on GET, unused on PUT.
  // Names match BotConfiguration's relation methods exactly.
  startingDialog?: DialogOption | null;
  welcomeDialog?: DialogOption | null;
  invalidInputDialog?: DialogOption | null;
  invalidAttemptsDialog?: DialogOption | null;
  retryDialog?: DialogOption | null;
  handoverDialogInHours?: DialogOption | null;
  handoverDialogOffHours?: DialogOption | null;
}

/** Validation bounds lifted directly from BotConfigurationController::upsert(), shown in the UI as min/max hints. */
export const VALIDATION_BOUNDS = {
  retryAfterMinutes: { min: 1, max: 10080 }, // 1 minute .. 7 days
  maxRetryAttempts: { min: 1, max: 20 },
  sessionTimeoutMinutes: { min: 1, max: 43200 }, // 1 minute .. 30 days
  maxInvalidAttempts: { min: 1, max: 20 },
  autoResolveAfterMinutes: { min: 1, max: 43200 },
};

export function emptyOperatingHours(): OperatingHours {
  const hours = {} as OperatingHours;
  for (const day of DAYS_OF_WEEK) {
    hours[day] = {
      enabled: false,
      open: "09:00",
      close: "17:00",
      timezone: null,
    };
  }
  return hours;
}

export function useBotConfiguration(botId: string | Ref<string>) {
  const id = isRef(botId) ? botId : ref(botId);
  const configuration = ref<BotConfiguration | null>(null);
  const dialogs = ref<DialogOption[]>([]);
  const exists = ref(false);
  const isLoading = ref(false);
  const isSaving = ref(false);
  const errors = ref<Record<string, string[]>>({});

  async function load(): Promise<void> {
    isLoading.value = true;
    errors.value = {};
    try {
      const [configRes, dialogsRes] = await Promise.all([
        axios.get(`/tenant/bots/${id.value}/settings`),
        axios.get(`/tenant/bots/${id.value}/settings/dialogs`),
      ]);
      const loaded: BotConfiguration = configRes.data.configuration;

      if (
        !loaded.operating_hours ||
        Object.keys(loaded.operating_hours).length < 7
      ) {
        loaded.operating_hours = {
          ...emptyOperatingHours(),
          ...loaded.operating_hours,
        };
      }
      if (!Array.isArray(loaded.home_keywords)) loaded.home_keywords = [];
      if (!Array.isArray(loaded.back_keywords)) loaded.back_keywords = [];
      if (!Array.isArray(loaded.handover_keywords))
        loaded.handover_keywords = [];
      if (!Array.isArray(loaded.opt_out_keywords)) loaded.opt_out_keywords = [];
      if (!Array.isArray(loaded.opt_in_keywords)) loaded.opt_in_keywords = [];

      configuration.value = loaded;
      exists.value = configRes.data.exists;
      dialogs.value = dialogsRes.data.dialogs ?? [];
    } finally {
      isLoading.value = false;
    }
  }

  async function save(): Promise<boolean> {
    if (!configuration.value) return false;
    isSaving.value = true;
    errors.value = {};
    try {
      const { data } = await axios.put(
        `/tenant/chatbots/${id.value}/settings`,
        configuration.value,
      );
      configuration.value = data.configuration;
      exists.value = true;
      return true;
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        errors.value = error.response.data.errors ?? {};
      }
      return false;
    } finally {
      isSaving.value = false;
    }
  }

  // ── Config-level dialog CRUD ──────────────────────────────────────────
  // Separate endpoint from settings itself (/tenant/bots/{bot}/dialogs),
  // since these are their own resource, not fields on BotConfiguration.

  async function createDialog(
    input: DialogFormInput,
  ): Promise<DialogOption | null> {
    try {
      const { data } = await axios.post(
        `/tenant/bots/${id.value}/dialogs`,
        input,
      );
      dialogs.value.push(data.dialog);
      return data.dialog as DialogOption;
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        errors.value = error.response.data.errors ?? {
          config: [error.response.data.message],
        };
      }
      return null;
    }
  }

  async function updateDialog(
    dialogId: string,
    input: DialogFormInput,
  ): Promise<DialogOption | null> {
    try {
      const { data } = await axios.put(
        `/tenant/bots/${id.value}/dialogs/${dialogId}`,
        input,
      );
      const index = dialogs.value.findIndex((d) => d.id === dialogId);
      if (index !== -1) dialogs.value[index] = data.dialog;
      return data.dialog as DialogOption;
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        errors.value = error.response.data.errors ?? {
          config: [error.response.data.message],
        };
      }
      return null;
    }
  }

  async function deleteDialog(dialogId: string): Promise<boolean> {
    try {
      await axios.delete(`/tenant/bots/${id.value}/dialogs/${dialogId}`);
      dialogs.value = dialogs.value.filter((d) => d.id !== dialogId);
      return true;
    } catch {
      return false;
    }
  }

  return {
    configuration,
    dialogs,
    exists,
    isLoading,
    isSaving,
    errors,
    load,
    save,
    createDialog,
    updateDialog,
    deleteDialog,
  };
}
