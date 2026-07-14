// composables/useFlowBuilder.ts
//
// Merges what used to be three separate files:
//   - composables/useNodeDefaults.ts  → getNodeDefaults()
//   - composables/useEditGuard.ts     → canEdit() / isReadOnly()
//   - composables/useNodeHelpers.ts   → cleanDanglingReferences()
//
// All three are small, single-purpose, and only ever used together inside
// FlowBuilder.vue and NodeEditor.vue — splitting them bought no real
// isolation benefit, just import overhead.

import { ref, type Ref } from "vue";
import { v4 as uuidv4 } from "uuid";
import Swal from "sweetalert2";
import type {
  FlowNode,
  NodeKind,
  Action,
  ConditionBranch,
  ApiResponseHandler,
} from "@/components/bots/types";

export function useFlowBuilder() {
  // ───────────────────────────────────────────────────────────────────────────
  // NODE DEFAULTS — was useNodeDefaults.ts
  // ───────────────────────────────────────────────────────────────────────────

  function makeDefaultAction(): Action {
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
              actions: [makeDefaultAction()],
              saveResponse: false,
            },
            {
              id: uuidv4(),
              label: "Option 2",
              actions: [makeDefaultAction()],
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
                    actions: [makeDefaultAction()],
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

  // ───────────────────────────────────────────────────────────────────────────
  // EDIT GUARD — was useEditGuard.ts
  // ───────────────────────────────────────────────────────────────────────────

  const isCheckingVersion = ref(false);

  async function canEdit(
    botVersion: Ref<any> | any,
    onCreateVersion: () => Promise<void>,
  ): Promise<boolean> {
    const version = "value" in botVersion ? botVersion.value : botVersion;

    if (!version || version.status === "draft") return true;

    if (version.status === "published" || version.status === "locked") {
      if (isCheckingVersion.value) return false;
      isCheckingVersion.value = true;

      const result = await Swal.fire({
        title: "Version is Published",
        html: `
          <div style="text-align: left;">
            <p>This version (v${version.version_number}) is currently <strong>${version.status}</strong> and cannot be edited.</p>
            <p>Would you like to create a new draft version to make changes?</p>
          </div>
        `,
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Create New Version",
        cancelButtonText: "Cancel",
        confirmButtonColor: "#4CAF50",
        cancelButtonColor: "#757575",
      });

      isCheckingVersion.value = false;

      if (result.isConfirmed) {
        try {
          await onCreateVersion();
          Swal.fire({
            title: "Version Created!",
            text: "You can now make changes to the new draft version.",
            icon: "success",
            timer: 2000,
            showConfirmButton: false,
          });
          return true;
        } catch (error) {
          console.error("Failed to create version:", error);
          Swal.fire({
            title: "Error",
            text: "Failed to create new version. Please try again.",
            icon: "error",
          });
          return false;
        }
      }
      return false;
    }

    return true;
  }

  function isReadOnly(botVersion: Ref<any> | any): boolean {
    const version = "value" in botVersion ? botVersion.value : botVersion;
    if (!version) return false;
    return version.status === "published" || version.status === "locked";
  }

  // ───────────────────────────────────────────────────────────────────────────
  // NODE HELPERS — was useNodeHelpers.ts
  // ───────────────────────────────────────────────────────────────────────────

  function cleanAction(action: Action, deletedId: string): void {
    if (action.goTo === deletedId) action.goTo = "";
    if (action.resumeAt === deletedId) action.resumeAt = "";
    action.branches?.forEach((branch) => cleanBranch(branch, deletedId));
    if (action.defaultBranch) cleanBranch(action.defaultBranch, deletedId);
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

  function cleanDanglingReferences(
    nodes: FlowNode[],
    deletedNodeId: string,
  ): void {
    nodes.forEach((node) => {
      if (node.goTo === deletedNodeId) node.goTo = "";
      if (node.postHandoffNode === deletedNodeId) node.postHandoffNode = "";

      node.actions?.forEach((action) => cleanAction(action, deletedNodeId));

      node.buttons?.forEach((btn) => {
        btn.actions.forEach((action) => cleanAction(action, deletedNodeId));
      });

      node.action?.sections?.forEach((section) => {
        section.rows.forEach((row) => {
          row.actions.forEach((action) => cleanAction(action, deletedNodeId));
        });
      });
    });
  }

  return {
    // node defaults
    getNodeDefaults,
    // edit guard
    canEdit,
    isReadOnly,
    isCheckingVersion,
    // node helpers
    cleanDanglingReferences,
  };
}
