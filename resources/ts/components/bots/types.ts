export type NodeKind =
  | "trigger"
  | "message"
  | "buttons"
  | "list"
  | "media"
  | "location"
  | "contact"
  | "end";

export type ActionKind =
  | "navigation"
  | "condition"
  | "variable"
  | "api"
  | "function"
  | "delay"
  | "handoff";

export type ConditionType = "variable" | "saved_response" | "api_response";

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
export type ButtonType = "reply" | "url";

export interface ActionCondition {
  id: string;
  type: ConditionType;
  variableKey?: string;
  operator?: ConditionOperator;
  conditionValue?: string;
  optionId?: string;
  responseField?: "status" | "body" | "header";
  responsePath?: string;
  apiConditionValue?: string;
}

export interface ConditionBranch {
  id: string;
  conditionLogic: "AND" | "OR";
  isDefault: boolean;
  branchIndex: number;
  conditions: ActionCondition[];
  actions: Action[];
}

export interface ApiResponseHandler {
  id: string;
  conditions: ActionCondition[];
  actions: Action[];
}

export interface Action {
  id: string;
  kind: ActionKind;
  then?: Action | null;
  goTo?: string;
  branches?: ConditionBranch[];
  defaultBranch?: ConditionBranch | null;
  varName?: string;
  varValue?: string;
  dataType?: DataType;
  apiConfigId?: string;
  apiResultVar?: string;
  responseHandlers?: ApiResponseHandler[];
  defaultActions?: Action[];
  fnId?: string;
  paramsRaw?: string;
  resultVar?: string;
  seconds?: number;
  resumeAt?: string;
}

export interface Btn {
  id: string;
  label: string;
  type: ButtonType;
  url?: string;
  actions: Action[];
  saveResponse: boolean;
}

export interface Row {
  id: string;
  title: string;
  description: string;
  actions: Action[];
  saveResponse: boolean;
}

export interface Section {
  id: string;
  title: string;
  rows: Row[];
}

export interface ListAction {
  button: string;
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

export interface FlowNode {
  id: string;
  kind: NodeKind;
  isFirstNode?: boolean;
  isLastNode?: boolean;
  inputVariable?: string;
  label?: string;
  actions?: Action[];
  triggersHandoff?: boolean;
  postHandoffNode?: string;
  triggerType?: "keyword" | "any" | "first" | "opt_in";
  keywords?: string;
  text?: string;
  btnText?: string;
  buttons?: Btn[];
  listHeader?: string;
  listBody?: string;
  listFooter?: string;
  action?: ListAction;
  actionButton?: string;
  mediaType?: "image" | "video" | "audio" | "document";
  mediaUrl?: string;
  mediaCaption?: string;
  mediaFilename?: string;
  mediaFileId?: string;
  locationLatitude?: number;
  locationLongitude?: number;
  locationName?: string;
  locationAddress?: string;
  contactData?: ContactData;
  goTo?: string;
  waitForReply?: boolean;
  replyVariable?: string;
}

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

export interface OptionPayload {
  external_id: string;
  title: string;
  description?: string | null;
  section_title?: string | null;
  section_order?: number;
  option_order: number;
  save_response: boolean;
}

export interface ActionPayload {
  action_type: ActionKind;
  action_order: number;
  config: Action;
  is_active: boolean;
}

export interface AutoSaveDialog {
  dialog_id: string;
  kind: NodeKind;
  label: string;
  position_x: number;
  position_y: number;
  is_entry_point: boolean;
  is_terminal: boolean;
  config: FlowNode;
  options: OptionPayload[];
  actions: ActionPayload[];
}

export interface AutoSavePayload {
  dialogs: AutoSaveDialog[];
}

function flattenActionChain(
  action: Action,
  startOrder: number = 0,
): ActionPayload[] {
  const result: ActionPayload[] = [];
  let current: Action | null | undefined = action;
  let order = startOrder;

  while (current) {
    result.push({
      action_type: current.kind,
      action_order: order++,
      is_active: true,
      config: { ...current, then: undefined },
    });
    current = current.then ?? null;
  }

  return result;
}

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
      config: {
        ...node,
        waitForReply: node.waitForReply ?? false,
        replyVariable: node.replyVariable ?? "",
        buttons: node.buttons?.map((btn) => ({
          ...btn,
          type: btn.type ?? "reply",
          url: btn.url ?? "",
        })),
      },
      options: extractOptions(node),
      actions: (node.actions ?? []).flatMap((action: any, i: number) =>
        flattenActionChain(action, i * 100),
      ),
    })),
  };
}

function extractOptions(node: FlowNode): OptionPayload[] {
  const opts: OptionPayload[] = [];

  if (node.kind === "buttons" && node.buttons) {
    node.buttons.forEach((btn, i) => {
      const isReply = btn.type === "reply" || !btn.type;
      opts.push({
        external_id: btn.id,
        title: btn.label,
        description: isReply ? null : `URL: ${btn.url || "N/A"}`,
        section_title: null,
        section_order: 0,
        option_order: i,
        save_response: isReply ? btn.saveResponse : false,
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

export function parseDialogsFromBackend(dialogs: any[]): FlowNode[] {
  return dialogs
    .sort((a, b) => (a.position_y ?? 0) - (b.position_y ?? 0))
    .map((d) => {
      const config: FlowNode = d.config ?? {};
      return {
        ...config,
        id: d.config?.id ?? d.uuid ?? String(d.id),
        kind: d.kind ?? config.kind ?? "message",
        isFirstNode: d.is_entry_point ?? config.isFirstNode ?? false,
        isLastNode: d.is_terminal ?? config.isLastNode ?? false,
        inputVariable: d.input_variable ?? config.inputVariable ?? "",
        waitForReply: d.config?.waitForReply ?? config.waitForReply ?? false,
        replyVariable: d.config?.replyVariable ?? config.replyVariable ?? "",
        buttons: config.buttons?.map((btn: any) => ({
          ...btn,
          type: btn.type ?? "reply",
          url: btn.url ?? "",
        })),
      };
    });
}
