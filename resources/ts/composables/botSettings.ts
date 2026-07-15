/**
 * resources/ts/composables/botSettings.ts
 *
 * ARCHITECTURE CHANGE: dialog routing is now purpose-based.
 *
 * BotConfiguration no longer has starting_dialog_id, welcome_dialog_id,
 * invalid_input_dialog_id, etc. as foreign-key fields. Instead, each bot
 * has a flat list of BotDialogs, each with a hardcoded `purpose` string.
 * The runtime looks up dialogs by purpose — the settings UI lets the user
 * create/edit the dialog for each reserved purpose slot.
 *
 * RESERVED PURPOSES  (must match BotDialog PHP constants exactly):
 *   greeting           → first message from a new contact
 *   main_menu          → home keyword or go_home button
 *   invalid_input      → bot didn't understand (config mode)
 *   flow_invalid_input → bot didn't understand (flow mode)
 *   retry              → user went quiet
 *   handover_in_hours  → talk_to_agent during operating hours
 *   handover_off_hours → talk_to_agent outside operating hours
 */
import { ref, isRef, type Ref } from "vue";
import axios from "axios";

// ── Purpose constants (mirror BotDialog::PURPOSE_* PHP constants) ──────────

export const PURPOSE_GREETING = "greeting" as const;
export const PURPOSE_MAIN_MENU = "main_menu" as const;
export const PURPOSE_INVALID_INPUT = "invalid_input" as const;
export const PURPOSE_FLOW_INVALID_INPUT = "flow_invalid_input" as const;
export const PURPOSE_RETRY = "retry" as const;
export const PURPOSE_HANDOVER_IN_HOURS = "handover_in_hours" as const;
export const PURPOSE_HANDOVER_OFF_HOURS = "handover_off_hours" as const;

export type ReservedPurpose =
  | typeof PURPOSE_GREETING
  | typeof PURPOSE_MAIN_MENU
  | typeof PURPOSE_INVALID_INPUT
  | typeof PURPOSE_FLOW_INVALID_INPUT
  | typeof PURPOSE_RETRY
  | typeof PURPOSE_HANDOVER_IN_HOURS
  | typeof PURPOSE_HANDOVER_OFF_HOURS;

export interface PurposeSlot {
  purpose: ReservedPurpose;
  label: string;
  hint: string;
  /**
   * Purposes that are always shown. Others are only shown
   * when the related feature (retry, handover) is enabled.
   */
  alwaysShow: boolean;
  /**
   * Which feature gate controls visibility. null = always visible.
   */
  gatedBy: "retry_enabled" | "handover_enabled" | null;
}

export const PURPOSE_SLOTS: PurposeSlot[] = [
  {
    purpose: PURPOSE_GREETING,
    label: "Greeting",
    hint: "Sent to every new contact on their first ever message. Should introduce the bot and show navigation options.",
    alwaysShow: true,
    gatedBy: null,
  },
  {
    purpose: PURPOSE_MAIN_MENU,
    label: "Main menu",
    hint: "Shown when a contact types a home keyword or taps Go to home menu. This is the bot's home base.",
    alwaysShow: true,
    gatedBy: null,
  },
  {
    purpose: PURPOSE_INVALID_INPUT,
    label: "Invalid input",
    hint: "Shown when the bot doesn't understand — typically a message + navigation buttons (Home, Talk to Agent).",
    alwaysShow: true,
    gatedBy: null,
  },
  {
    purpose: PURPOSE_FLOW_INVALID_INPUT,
    label: "Flow invalid input",
    hint: "Same as above but shown during the flow builder graph. Includes a Go Back button to resume the flow.",
    alwaysShow: true,
    gatedBy: null,
  },
  {
    purpose: PURPOSE_RETRY,
    label: "Retry",
    hint: "Sent when a contact goes quiet. Usually a nudge with Home and Talk to Agent options.",
    alwaysShow: false,
    gatedBy: "retry_enabled",
  },
  {
    purpose: PURPOSE_HANDOVER_IN_HOURS,
    label: "Handover — in hours",
    hint: "Shown when Talk to Agent is triggered during operating hours. Usually confirms the handover.",
    alwaysShow: false,
    gatedBy: "handover_enabled",
  },
  {
    purpose: PURPOSE_HANDOVER_OFF_HOURS,
    label: "Handover — off hours",
    hint: "Shown when Talk to Agent is triggered outside operating hours. Explains unavailability.",
    alwaysShow: false,
    gatedBy: "handover_enabled",
  },
];

// ── System action vocabulary (mirrors SystemActionHandler) ─────────────────

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
    title: "Go to main menu",
    hint: "Exits any active flow and returns to the Main menu BotDialog",
  },
  {
    value: "go_back",
    title: "Go back",
    hint: "Returns to the previous dialog (works in both config and flow mode)",
  },
  {
    value: "start_flow",
    title: "Start flow",
    hint: "Enters the bot builder flow from the entry-point dialog",
  },
  {
    value: "talk_to_agent",
    title: "Talk to an agent",
    hint: "Hands the conversation off to a human agent",
  },
];

// ── BotDialog types ────────────────────────────────────────────────────────

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

/** A standalone config-level dialog (no bot_version_id). */
export interface BotDialogRecord {
  id: string;
  bot_id: string;
  purpose: ReservedPurpose | string;
  name: string;
  description?: string | null;
  kind: "message" | "buttons" | "list";
  is_active: boolean;
  config: DialogConfig;
}

export interface BotDialogFormInput {
  purpose: string;
  name: string;
  description?: string | null;
  kind: "message" | "buttons" | "list";
  is_active?: boolean;
  config: DialogConfig;
}

// ── BotConfiguration (no dialog_id fields) ────────────────────────────────

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

  // Timeouts & limits
  session_timeout_minutes: number | null;
  max_invalid_attempts: number | null;

  // Retry
  retry_enabled: boolean;
  retry_after_minutes: number | null;
  max_retry_attempts: number | null;

  // Handover
  handover_enabled: boolean;
  handover_unavailable_message: string | null;
  auto_resolve_after_minutes: number | null;
  operating_hours: OperatingHours;

  // Keywords
  home_keywords: string[];
  back_keywords: string[];
  handover_keywords: string[];
  opt_out_keywords: string[];
  opt_in_keywords: string[];

  // Inline text for invalid input (shown before the BotDialog fires)
  invalid_input_message: string | null;
}

export const VALIDATION_BOUNDS = {
  retryAfterMinutes: { min: 1, max: 10080 },
  maxRetryAttempts: { min: 1, max: 20 },
  sessionTimeoutMinutes: { min: 1, max: 43200 },
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

// ── Composable ─────────────────────────────────────────────────────────────

export function useBotConfiguration(botId: string | Ref<string>) {
  const id = isRef(botId) ? botId : ref(botId);

  const configuration = ref<BotConfiguration | null>(null);
  /** BotDialogs keyed by purpose for O(1) lookup in the UI */
  const dialogsByPurpose = ref<Partial<Record<string, BotDialogRecord>>>({});

  const isLoading = ref(false);
  const isSaving = ref(false);
  const errors = ref<Record<string, string[]>>({});

  async function load(): Promise<void> {
    isLoading.value = true;
    errors.value = {};
    try {
      const [configRes, dialogsRes] = await Promise.all([
        axios.get(`/tenant/bots/${id.value}/settings`),
        axios.get(`/tenant/bots/${id.value}/dialogs`),
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

      for (const field of [
        "home_keywords",
        "back_keywords",
        "handover_keywords",
        "opt_out_keywords",
        "opt_in_keywords",
      ] as const) {
        if (!Array.isArray(loaded[field])) (loaded as any)[field] = [];
      }

      configuration.value = loaded;

      const dialogs: BotDialogRecord[] = dialogsRes.data.dialogs ?? [];
      dialogsByPurpose.value = Object.fromEntries(
        dialogs.map((d) => [d.purpose, d]),
      );
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
        `/tenant/bots/${id.value}/settings`,
        configuration.value,
      );
      configuration.value = data.configuration;
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

  // ── BotDialog CRUD ──────────────────────────────────────────────────────

  async function saveBotDialog(
    input: BotDialogFormInput,
  ): Promise<BotDialogRecord | null> {
    const existing = dialogsByPurpose.value[input.purpose];
    try {
      const { data } = existing
        ? await axios.put(
            `/tenant/bots/${id.value}/dialogs/${existing.id}`,
            input,
          )
        : await axios.post(`/tenant/bots/${id.value}/dialogs`, input);

      const saved: BotDialogRecord = data.dialog;
      dialogsByPurpose.value = {
        ...dialogsByPurpose.value,
        [saved.purpose]: saved,
      };
      return saved;
    } catch (error) {
      if (axios.isAxiosError(error) && error.response?.status === 422) {
        errors.value = error.response.data.errors ?? {};
      }
      return null;
    }
  }

  async function deleteBotDialog(purpose: string): Promise<boolean> {
    const existing = dialogsByPurpose.value[purpose];
    if (!existing) return true;
    try {
      await axios.delete(`/tenant/bots/${id.value}/dialogs/${existing.id}`);
      const updated = { ...dialogsByPurpose.value };
      delete updated[purpose];
      dialogsByPurpose.value = updated;
      return true;
    } catch {
      return false;
    }
  }

  return {
    configuration,
    dialogsByPurpose,
    isLoading,
    isSaving,
    errors,
    load,
    save,
    saveBotDialog,
    deleteBotDialog,
  };
}
