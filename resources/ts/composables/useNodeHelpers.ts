// @/composables/useNodeHelpers.ts
import type { FlowNode } from "@/components/flowbuilder/types";

export function useNodeHelpers() {
  function cleanDanglingReferences(nodes: FlowNode[], deletedNodeId: string) {
    nodes.forEach((node) => {
      if (node.goTo === deletedNodeId) node.goTo = "";
      if (node.postHandoffNode === deletedNodeId) node.postHandoffNode = "";

      node.buttons?.forEach((btn) => {
        btn.actions.forEach((action) => {
          if (action.goTo === deletedNodeId) action.goTo = "";
          if (action.trueGoTo === deletedNodeId) action.trueGoTo = "";
          if (action.falseGoTo === deletedNodeId) action.falseGoTo = "";
        });
      });

      node.action?.sections?.forEach((section) => {
        section.rows.forEach((row) => {
          row.actions.forEach((action) => {
            if (action.goTo === deletedNodeId) action.goTo = "";
            if (action.trueGoTo === deletedNodeId) action.trueGoTo = "";
            if (action.falseGoTo === deletedNodeId) action.falseGoTo = "";
          });
        });
      });

      node.actions?.forEach((action) => {
        if (action.goTo === deletedNodeId) action.goTo = "";
        if (action.trueGoTo === deletedNodeId) action.trueGoTo = "";
        if (action.falseGoTo === deletedNodeId) action.falseGoTo = "";
      });
    });
  }

  return { cleanDanglingReferences };
}
