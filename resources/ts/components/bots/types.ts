// ── Node kinds (→ dialogs.kind) ───────────────────────────────────────────────
export type NodeKind =
  | "trigger"
  | "message"
  | "buttons"
  | "list"
  | "media"
  | "location"
  | "contact"
  | "end";

// ── Action kinds (→ actions.action_type) ─────────────────────────────────────
export type ActionKind =
  | "navigation" // target_dialog_id
  | "condition" // condition_branch children
  | "variable" // variable_name, variable_value, variable_data_type
  | "api" // target_api_id, api_result_var
  | "function" // target_function_id, function_params_raw
  | "delay" // delay_seconds
  | "handoff"; // resume_dialog_id

export type ConditionType =
  | "variable" // compare a stored variable
  | "saved_response" // which button / list item the user tapped
  | "api_response"; // field from last API call result

export type ConditionOperator =
  | "equals"
  | "not_equals"
  | "greater_than"
  | "less_than"
  | "greater_than_or_equal"
  | "less_than_or_equal"
  | "contains"
  | "starts_with"
  | "ends_with"
  | "is_empty"
  | "is_not_empty";

export type DataType = "string" | "number" | "boolean" | "json" | "date";

// ── Condition (→ action_conditions table) ────────────────────────────────────
export interface ActionCondition {
  id: string;
  type: ConditionType;
  // variable
  variableKey?: string;
  operator?: ConditionOperator;
  conditionValue?: string;
  // saved_response
  optionId?: string; // dialog_options.external_id
  // api_response
  responseField?: "status" | "body" | "header";
  responsePath?: string; // e.g. "data.user.status"
  apiConditionValue?: string;
}

// ── Condition branch (→ condition_branch action row) ─────────────────────────
export interface ConditionBranch {
  id: string;
  conditionLogic: "AND" | "OR";
  isDefault: boolean; // → actions.is_default  (the ELSE branch)
  branchIndex: number; // → actions.branch_index
  conditions: ActionCondition[];
  actions: Action[]; // leaf actions inside this branch
}

// ── API response handler (→ api_response_handler action row) ─────────────────
export interface ApiResponseHandler {
  id: string;
  conditions: ActionCondition[];
  actions: Action[];
}

// ── Action (one row in actions table, config stored as JSON) ──────────────────
export interface Action {
  id: string;
  kind: ActionKind;
  // navigation
  goTo?: string;
  // condition — sub-branches, never leaf actions directly
  branches?: ConditionBranch[];
  defaultBranch?: ConditionBranch | null;
  // variable
  varName?: string;
  varValue?: string;
  dataType?: DataType;
  // api
  apiConfigId?: string;
  apiResultVar?: string;
  responseHandlers?: ApiResponseHandler[];
  defaultActions?: Action[];
  // function
  fnId?: string;
  paramsRaw?: string;
  resultVar?: string;
  // delay
  seconds?: number;
  // handoff
  resumeAt?: string;
}

// ── Button (→ dialog_options, synced from config.buttons) ────────────────────
export interface Btn {
  id: string; // → dialog_options.external_id
  label: string; // → dialog_options.title
  actions: Action[];
  saveResponse: boolean; // → dialog_options.save_response
}

// ── List row (→ dialog_options, synced from config.action.sections[].rows) ───
export interface Row {
  id: string; // → dialog_options.external_id
  title: string; // → dialog_options.title
  description: string; // → dialog_options.description
  actions: Action[];
  saveResponse: boolean; // → dialog_options.save_response
}

export interface Section {
  id: string; // local uuid kept in config
  title: string; // → dialog_options.section_title
  rows: Row[];
}

export interface ListAction {
  button: string; // CTA button label
  sections: Section[];
}

export interface ContactData {
  name: { formatted_name: string; first_name?: string; last_name?: string };
  phones?: Array<{ phone: string; type?: string; wa_id?: string }>;
  emails?: Array<{ email: string; type?: string }>;
  addresses?: Array<{
    street?: string;
    city?: string;
    state?: string;
    zip?: string;
    country?: string;
    country_code?: string;
    type?: string;
  }>;
  urls?: Array<{ url: string; type?: string }>;
  org?: { company?: string; department?: string; title?: string };
  birthday?: string;
}

export interface SavedResponse {
  nodeId: string;
  nodeLabel: string;
  optionId: string;
  label: string;
}

// ─────────────────────────────────────────────────────────────────────────────
// FlowNode — the full config blob stored in dialogs.config
// ─────────────────────────────────────────────────────────────────────────────
export interface FlowNode {
  // Extracted as structured columns by the backend
  id: string; // → dialogs.config['id']  AND  auto-save dialog_id
  kind: NodeKind; // → dialogs.kind
  isFirstNode?: boolean; // → dialogs.is_entry_point
  isLastNode?: boolean; // → dialogs.is_terminal
  inputVariable?: string; // → dialogs.input_variable

  // All node types
  label?: string;
  actions?: Action[]; // root actions (message / trigger / location nodes)
  triggersHandoff?: boolean;
  postHandoffNode?: string;

  // trigger
  triggerType?: "keyword" | "any" | "first" | "opt_in";
  keywords?: string;

  // message / end
  text?: string;

  // buttons
  btnText?: string;
  buttons?: Btn[];

  // list
  listHeader?: string;
  listBody?: string;
  listFooter?: string;
  action?: ListAction;
  actionButton?: string;

  // media
  mediaType?: "image" | "video" | "audio" | "document";
  mediaUrl?: string;
  mediaCaption?: string;
  mediaFilename?: string;

  // location
  locationLatitude?: number;
  locationLongitude?: number;
  locationName?: string;
  locationAddress?: string;

  // contact / media "then go to"
  contactData?: ContactData;
  goTo?: string;
}

// ─────────────────────────────────────────────────────────────────────────────
// Node display config
// ─────────────────────────────────────────────────────────────────────────────
export interface NodeConfig {
  label: string;
  color: string;
  icon: string;
  desc: string;
}

export const NODE_CONFIGS: Record<NodeKind, NodeConfig> = {
  trigger: {
    label: "Trigger",
    color: "#10b981",
    icon: "$lightningBoltOutline",
    desc: "Entry point",
  },
  message: {
    label: "Message",
    color: "#3b82f6",
    icon: "$messageText",
    desc: "Send a text",
  },
  buttons: {
    label: "Buttons",
    color: "#8b5cf6",
    icon: "$radioboxMarked",
    desc: "Quick reply",
  },
  list: {
    label: "List",
    color: "#06b6d4",
    icon: "$formatListBulleted",
    desc: "Scrollable menu",
  },
  media: {
    label: "Media",
    color: "#f59e0b",
    icon: "$imageOutline",
    desc: "Image/video/doc",
  },
  location: {
    label: "Location",
    color: "#ec4899",
    icon: "$mapMarkerRadiusOutline",
    desc: "Send location",
  },
  contact: {
    label: "Contact",
    color: "#14b8a6",
    icon: "$cardAccountDetails",
    desc: "Share contact",
  },
  end: {
    label: "End",
    color: "#ef4444",
    icon: "$flagCheckered",
    desc: "Close flow",
  },
};

// ─────────────────────────────────────────────────────────────────────────────
// Auto-save payload — matches FlowBuilderController.autoSave() exactly
// ─────────────────────────────────────────────────────────────────────────────

/**
 * One option row sent to the backend.
 * The backend's syncOptions() upserts these into the dialog_options table
 * matched by external_id.
 */
export interface OptionPayload {
  external_id: string; // btn.id / row.id
  title: string; // btn.label / row.title
  description?: string | null;
  section_title?: string | null; // parent Section.title (list rows only)
  section_order?: number;
  option_order: number;
  save_response: boolean;
}

/**
 * One action row sent to the backend.
 * The backend's syncActions() upserts these into the actions table
 * matched by action_order. The full action tree (including condition branches,
 * api handlers, etc.) lives inside config.
 */
export interface ActionPayload {
  action_type: ActionKind; // → actions.action_type
  config: Action; // full action blob → actions.config
  is_active: boolean;
}

/** One dialog entry in the auto-save request body */
export interface AutoSaveDialog {
  dialog_id: string; // node.id — matched against dialogs.config['id']
  kind: NodeKind; // → dialogs.kind
  label: string; // → dialogs.label
  position_x: number;
  position_y: number;
  is_entry_point: boolean; // → dialogs.is_entry_point
  is_terminal: boolean; // → dialogs.is_terminal
  config: FlowNode; // → dialogs.config  (full node blob)
  options: OptionPayload[]; // → dialog_options  (buttons / list rows)
  actions: ActionPayload[]; // → actions table   (root actions only for non-button nodes)
}

/** Full auto-save request body */
export interface AutoSavePayload {
  dialogs: AutoSaveDialog[];
}

// ─────────────────────────────────────────────────────────────────────────────
// buildAutoSavePayload()
// Converts the frontend FlowNode[] into the exact shape the backend expects.
// Call this inside FlowBuilder's performSave() instead of hand-rolling the map.
// ─────────────────────────────────────────────────────────────────────────────
export function buildAutoSavePayload(nodes: FlowNode[]): AutoSavePayload {
  return {
    dialogs: nodes.map((node, index) => ({
      dialog_id: node.id,
      kind: node.kind,
      label: node.label ?? node.kind,
      position_x: 0,
      position_y: index * 200,
      is_entry_point: node.isFirstNode ?? false,
      is_terminal: node.isLastNode ?? false,
      config: node,
      options: extractOptions(node),
      // Root actions are only relevant for non-button / non-list nodes.
      // Button and list row actions are stored inside config.buttons / config.action.sections
      // and are resolved by the backend from config when building the action tree.
      actions: extractRootActions(node),
    })),
  };
}

function extractOptions(node: FlowNode): OptionPayload[] {
  const opts: OptionPayload[] = [];

  if (node.kind === "buttons" && node.buttons) {
    node.buttons.forEach((btn, i) => {
      opts.push({
        external_id: btn.id,
        title: btn.label,
        description: null,
        section_title: null,
        section_order: 0,
        option_order: i,
        save_response: btn.saveResponse,
      });
    });
  }

  if (node.kind === "list" && node.action?.sections) {
    node.action.sections.forEach((section, sIdx) => {
      section.rows.forEach((row, rIdx) => {
        opts.push({
          external_id: row.id,
          title: row.title,
          description: row.description || null,
          section_title: section.title,
          section_order: sIdx,
          option_order: rIdx,
          save_response: row.saveResponse,
        });
      });
    });
  }

  return opts;
}

function extractRootActions(node: FlowNode): ActionPayload[] {
  // Buttons and list nodes have their actions inside config.buttons[].actions
  // and config.action.sections[].rows[].actions — not as top-level node actions.
  if (node.kind === "buttons" || node.kind === "list") return [];
  if (!node.actions?.length) return [];

  return node.actions.map((action) => ({
    action_type: action.kind,
    config: action,
    is_active: true,
  }));
}

// ─────────────────────────────────────────────────────────────────────────────
// parseDialogsFromBackend()
// Reconstructs FlowNode[] from GET /builder response dialogs array.
// ─────────────────────────────────────────────────────────────────────────────
export function parseDialogsFromBackend(dialogs: any[]): FlowNode[] {
  return dialogs
    .sort((a, b) => (a.position_y ?? 0) - (b.position_y ?? 0))
    .map((d) => {
      const config: FlowNode = d.config ?? {};
      return {
        ...config,
        // Structured columns always win over what's inside config JSON
        id: d.config?.id ?? d.uuid ?? String(d.id),
        kind: d.kind ?? config.kind ?? "message",
        isFirstNode: d.is_entry_point ?? config.isFirstNode ?? false,
        isLastNode: d.is_terminal ?? config.isLastNode ?? false,
        inputVariable: d.input_variable ?? config.inputVariable ?? "",
      };
    });
}
