import { defineStore } from "pinia";
import type {
  FlowNode,
  Btn,
  Row,
  SavedResponse,
} from "@/components/bots/types";

interface ActionEditorState {
  show: boolean;
  targetNode: FlowNode | null;
  targetButton: Btn | null;
  targetRow: Row | null;
  availableVariables: string[];
  savedResponses: SavedResponse[]; // ← ADDED
  customFunctions: any[];
  apiIntegrations: any[];
  nodeOptions: any[];
}

export interface OpenActionEditorPayload {
  targetNode: FlowNode;
  targetButton?: Btn;
  targetRow?: Row;
  availableVariables: string[];
  savedResponses?: SavedResponse[]; // ← ADDED (optional so callers don't break)
  customFunctions?: any[];
  apiIntegrations?: any[];
  nodeOptions: any[];
}

export const useActionEditorStore = defineStore("actionEditor", {
  state: (): ActionEditorState => ({
    show: false,
    targetNode: null,
    targetButton: null,
    targetRow: null,
    availableVariables: [],
    savedResponses: [], // ← ADDED
    customFunctions: [],
    apiIntegrations: [],
    nodeOptions: [],
  }),
  actions: {
    openActionEditor(payload: OpenActionEditorPayload) {
      this.show = true;
      this.targetNode = payload.targetNode;
      this.targetButton = payload.targetButton || null;
      this.targetRow = payload.targetRow || null;
      this.availableVariables = payload.availableVariables;
      this.savedResponses = payload.savedResponses || []; // ← ADDED
      this.customFunctions = payload.customFunctions || [];
      this.apiIntegrations = payload.apiIntegrations || [];
      this.nodeOptions = payload.nodeOptions;
    },
    closeActionEditor() {
      this.show = false;
      setTimeout(() => {
        this.targetNode = null;
        this.targetButton = null;
        this.targetRow = null;
      }, 300);
    },
  },
});
