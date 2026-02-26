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
  | "function"
  | "variable"
  | "delay"
  | "api";

export interface ActionDef {
  kind: ActionKind;
  goTo?: string;
  variable?: string;
  operator?: string;
  condValue?: string;
  trueGoTo?: string;
  falseGoTo?: string;
  fnId?: string;
  paramsRaw?: string;
  resultVar?: string;
  varName?: string;
  varValue?: string;
  dataType?: string;
  seconds?: number;
  endpoint?: string;
  method?: string;
  bodyRaw?: string;
  apiResultVar?: string;
  apiConfigId?: string;
}
export interface Btn {
  id: string;
  label: string;
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

export interface SavedResponse {
  nodeId: string;
  nodeLabel: string;
  optionId: string;
  label: string;
}

export interface Section {
  title: string;
  rows: Row[];
}

export interface ListAction {
  button: string;
  sections: Section[];
}

export interface ContactData {
  name: {
    formatted_name: string;
    first_name?: string;
    last_name?: string;
  };
  phones?: Array<{
    phone: string;
    type?: string;
    wa_id?: string;
  }>;
  emails?: Array<{
    email: string;
    type?: string;
  }>;
  addresses?: Array<{
    street?: string;
    city?: string;
    state?: string;
    zip?: string;
    country?: string;
    country_code?: string;
    type?: string;
  }>;
  urls?: Array<{
    url: string;
    type?: string;
  }>;
  org?: {
    company?: string;
    department?: string;
    title?: string;
  };
  birthday?: string;
}

export interface FlowNode {
  id: string;
  kind: NodeKind;

  // Common fields
  text?: string;
  goTo?: string;
  actions?: ActionDef[];
  isFirstNode?: boolean;
  isLastNode?: boolean;
  triggersHandoff?: boolean;
  postHandoffNode?: string;
  inputVariable?: string;

  // Button node fields
  btnText?: string;
  buttons?: Btn[];

  // List node fields
  listHeader?: string;
  listBody?: string;
  action?: ListAction;
  actionButton?: string;
  sections?: Section[];

  // Media node fields
  mediaType?: "image" | "video" | "audio" | "document";
  mediaUrl?: string;
  mediaCaption?: string;
  mediaFilename?: string;

  // Location node fields
  locationLatitude?: number;
  locationLongitude?: number;
  locationName?: string;
  locationAddress?: string;

  // Contact node fields
  contactData?: ContactData;
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
