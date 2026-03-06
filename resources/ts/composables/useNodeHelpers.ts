// @/composables/useNodeHelpers.ts
import type {
  FlowNode,
  Action,
  ConditionBranch,
  ApiResponseHandler,
} from "@/components/flowbuilder/types";

export function useNodeHelpers() {
  // ── Recursively cleans goTo / resumeAt references inside an Action tree ───
  function cleanAction(action: Action, deletedId: string): void {
    // navigation
    if (action.goTo === deletedId) action.goTo = "";

    // handoff
    if (action.resumeAt === deletedId) action.resumeAt = "";

    // condition branches
    action.branches?.forEach((branch) => cleanBranch(branch, deletedId));
    if (action.defaultBranch) cleanBranch(action.defaultBranch, deletedId);

    // api response handlers
    action.responseHandlers?.forEach((handler) =>
      cleanHandler(handler, deletedId),
    );
    action.defaultActions?.forEach((a) => cleanAction(a, deletedId));
  }

  function cleanBranch(branch: ConditionBranch, deletedId: string): void {
    branch.actions.forEach((a) => cleanAction(a, deletedId));
  }

  function cleanHandler(handler: ApiResponseHandler, deletedId: string): void {
    handler.actions.forEach((a) => cleanAction(a, deletedId));
  }

  // ── Main exported function ────────────────────────────────────────────────
  function cleanDanglingReferences(
    nodes: FlowNode[],
    deletedNodeId: string,
  ): void {
    nodes.forEach((node) => {
      // Flat node-level references
      if (node.goTo === deletedNodeId) node.goTo = "";
      if (node.postHandoffNode === deletedNodeId) node.postHandoffNode = "";

      // Root actions (message / trigger / location nodes)
      node.actions?.forEach((action) => cleanAction(action, deletedNodeId));

      // Button actions
      node.buttons?.forEach((btn) => {
        btn.actions.forEach((action) => cleanAction(action, deletedNodeId));
      });

      // List row actions
      node.action?.sections?.forEach((section) => {
        section.rows.forEach((row) => {
          row.actions.forEach((action) => cleanAction(action, deletedNodeId));
        });
      });
    });
  }

  return { cleanDanglingReferences };
}
