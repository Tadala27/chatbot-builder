// composables/useNodeDefaults.ts
import { v4 as uuidv4 } from "uuid";
import type {
  FlowNode,
  NodeKind,
  Action,
} from "@/components/flowbuilder/types";

export function useNodeDefaults() {
  function makeAction(): Action {
    return { id: uuidv4(), kind: "navigation", goTo: "" };
  }

  function getNodeDefaults(kind: NodeKind, isFirst = false): FlowNode {
    const base: Partial<FlowNode> = {
      id: uuidv4(),
      kind,
      isFirstNode: isFirst,
      isLastNode: false,
      triggersHandoff: false,
      postHandoffNode: "",
      inputVariable: "",
      actions: [],
    };

    switch (kind) {
      case "trigger":
        return {
          ...base,
          isFirstNode: true,
          triggerType: "any",
          keywords: "",
        } as FlowNode;

      case "message":
        return { ...base, text: "" } as FlowNode;

      case "buttons":
        return {
          ...base,
          btnText: "",
          buttons: [
            {
              id: uuidv4(),
              label: "Option 1",
              actions: [makeAction()],
              saveResponse: false,
            },
            {
              id: uuidv4(),
              label: "Option 2",
              actions: [makeAction()],
              saveResponse: false,
            },
          ],
        } as FlowNode;

      case "list":
        return {
          ...base,
          listHeader: "",
          listBody: "",
          listFooter: "",
          action: {
            button: "View Options",
            sections: [
              {
                id: uuidv4(),
                title: "Section 1",
                rows: [
                  {
                    id: uuidv4(),
                    title: "Item 1",
                    description: "",
                    actions: [makeAction()],
                    saveResponse: false,
                  },
                ],
              },
            ],
          },
        } as FlowNode;

      case "media":
        return {
          ...base,
          mediaType: "image",
          mediaUrl: "",
          mediaCaption: "",
          mediaFilename: "",
          goTo: "",
        } as FlowNode;

      case "location":
        return {
          ...base,
          locationLatitude: 0,
          locationLongitude: 0,
          locationName: "",
          locationAddress: "",
        } as FlowNode;

      case "contact":
        return {
          ...base,
          contactData: {
            name: { formatted_name: "", first_name: "", last_name: "" },
            phones: [{ phone: "", type: "Mobile", wa_id: "" }],
            emails: [],
            urls: [],
            org: { company: "", department: "", title: "" },
          },
          goTo: "",
        } as FlowNode;

      case "end":
        return {
          ...base,
          isLastNode: true,
          text: "Thank you! Goodbye 👋",
        } as FlowNode;

      default:
        return base as FlowNode;
    }
  }

  return { getNodeDefaults };
}
