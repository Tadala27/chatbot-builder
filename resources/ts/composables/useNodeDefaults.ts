import type { FlowNode, NodeKind } from "../types";

let seq = 0;
const uid = () => `n${Date.now()}${seq++}`;
const btnUid = () => `btn${Date.now()}${seq++}`;
const rowUid = () => `row${Date.now()}${seq++}`;

export function useNodeDefaults() {
  function getNodeDefaults(
    kind: NodeKind,
    isFirstNode: boolean = false,
  ): FlowNode {
    const baseNode: FlowNode = {
      id: uid(),
      kind,
      goTo: "",
      actions: [],
      isFirstNode,
      isLastNode: false,
      triggersHandoff: false,
    };

    switch (kind) {
      case "trigger":
        return {
          ...baseNode,
          text: "keyword",
          mediaCaption: "",
          inputVariable: "",
        };

      case "message":
        return {
          ...baseNode,
          text: "",
          inputVariable: "",
        };

      case "buttons":
        return {
          ...baseNode,
          btnText: "",
          buttons: [
            {
              id: btnUid(),
              label: "Option 1",
              actions: [{ kind: "navigation", goTo: "" }],
              saveVariable: "",
              saveResponse: false,
            },
            {
              id: btnUid(),
              label: "Option 2",
              actions: [{ kind: "navigation", goTo: "" }],
              saveVariable: "",
              saveResponse: false,
            },
          ],
        };

      case "list":
        return {
          ...baseNode,
          listHeader: "",
          listBody: "",
          action: {
            button: "View Options",
            sections: [
              {
                title: "Section 1",
                rows: [
                  {
                    id: rowUid(),
                    title: "Item 1",
                    description: "",
                    actions: [{ kind: "navigation", goTo: "" }],
                    saveVariable: "",
                    saveResponse: false,
                  },
                  {
                    id: rowUid(),
                    title: "Item 2",
                    description: "",
                    actions: [{ kind: "navigation", goTo: "" }],
                    saveVariable: "",
                    saveResponse: false,
                  },
                ],
              },
            ],
          },
        };

      case "media":
        return {
          ...baseNode,
          mediaType: "image",
          mediaUrl: "",
          mediaCaption: "",
          mediaFilename: "",
        };

      case "location":
        return {
          ...baseNode,
          locationLatitude: 0,
          locationLongitude: 0,
          locationName: "",
          locationAddress: "",
        };

      case "contact":
        return {
          ...baseNode,
          contactData: {
            name: {
              formatted_name: "",
              first_name: "",
              last_name: "",
            },
            phones: [{ phone: "", type: "Mobile", wa_id: "" }],
            emails: [],
            addresses: [],
            urls: [],
            org: {
              company: "",
              department: "",
              title: "",
            },
          },
        };

      case "end":
        return {
          ...baseNode,
          text: "Thanks! Goodbye",
        };

      default:
        return baseNode;
    }
  }

  return {
    getNodeDefaults,
  };
}
