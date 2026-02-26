// router/routePermissions.ts
import type { RouteLocationNormalized } from "vue-router";
import { useUserStore } from "@/stores/user";

/**
 * Route permission configuration.
 */
export interface RoutePermissions {
  /** Required permissions (any of them, unless logic: 'AND') */
  permissions?: string[];
  /** Required roles (any of them, unless logic: 'AND') */
  roles?: string[];
  /** Logic for combining requirements: 'OR' (default) or 'AND' */
  logic?: "AND" | "OR";
}

// ========================================================================
// SIMPLIFIED PERMISSIONS MAP
// ========================================================================
export const routePermissionsMap: Record<string, RoutePermissions> = {
  // PUBLIC ROUTES
  "/login": {},
  "/forgot-password": {},
  "/reset-password": {},
  "/not-authorized": {},
  "/error": {},
  "/dashboard": {},

  // ========================================================================
  // ADMIN ROUTES
  // ========================================================================
  "/administration/users": { permissions: ["view users"] },
  "/administration/users/create": { permissions: ["invite users"] },
  "/administration/users/:id": { permissions: ["view users"] },
  "/administration/users/:id/edit": { permissions: ["edit users"] },
  "/administration/users/:id/delete": { permissions: ["delete users"] },
  "/administration/users/:id/roles": { permissions: ["assign roles"] },

  // TENANT MANAGEMENT (super-admin only)
  "/administration/tenants": { roles: ["super-admin"] },
  "/administration/tenants/create": { roles: ["super-admin"] },
  "/administration/tenants/:id/edit": { roles: ["super-admin"] },
  "/administration/tenants/:id/delete": { roles: ["super-admin"] },
  "/administration/tenants/:id/subscription": { roles: ["super-admin"] },

  // ========================================================================
  // CHATBOTS
  // ========================================================================
  "/chatbots": { permissions: ["view chatbots"] },
  "/chatbots/create": { permissions: ["create chatbots"] },
  "/chatbots/:id": { permissions: ["view chatbots"] },
  "/chatbots/:id/edit": { permissions: ["edit chatbots"] },
  "/chatbots/:id/delete": { permissions: ["delete chatbots"] },
  "/chatbots/:id/publish": { permissions: ["publish chatbots"] },
  "/chatbots/:id/test": { permissions: ["test chatbots"] },
  "/chatbots/:id/duplicate": { permissions: ["duplicate chatbots"] },

  // FLOW BUILDER - any flow permission
  "/chatbots/:id/flow": {
    permissions: [
      "edit flows",
      "create nodes",
      "edit nodes",
      "delete nodes",
      "validate flows",
    ],
    logic: "OR",
  },

  // ========================================================================
  // VARIABLES
  // ========================================================================
  "/variables": { permissions: ["view variables"] },
  "/variables/create": { permissions: ["create variables"] },
  "/variables/:id/edit": { permissions: ["edit variables"] },
  "/variables/:id/delete": { permissions: ["delete variables"] },

  // ========================================================================
  // FUNCTIONS
  // ========================================================================
  "/functions": { permissions: ["view functions"] },
  "/functions/create": { permissions: ["create functions"] },
  "/functions/:id/edit": { permissions: ["edit functions"] },
  "/functions/:id/delete": { permissions: ["delete functions"] },
  "/functions/:id/execute": { permissions: ["execute functions"] },
  "/functions/test": { permissions: ["test functions"] },

  // ========================================================================
  // WHATSAPP ACCOUNTS
  // ========================================================================
  "/whatsapp-accounts": { permissions: ["view whatsapp-accounts"] },
  "/whatsapp-accounts/connect": { permissions: ["connect whatsapp-accounts"] },
  "/whatsapp-accounts/:id/disconnect": {
    permissions: ["disconnect whatsapp-accounts"],
  },
  "/whatsapp-accounts/:id/manage": {
    permissions: ["manage whatsapp-accounts"],
  },

  // ========================================================================
  // CONVERSATIONS
  // ========================================================================
  "/conversations": { permissions: ["view conversations"] },
  "/conversations/:id": { permissions: ["view conversation-details"] },
  "/conversations/export": { permissions: ["export conversations"] },
  "/conversations/:id/delete": { permissions: ["delete conversations"] },
  "/conversations/:id/handoff": { permissions: ["handoff conversations"] },

  // ========================================================================
  // ANALYTICS
  // ========================================================================
  "/analytics": { permissions: ["view analytics"] },
  "/analytics/detailed": { permissions: ["view detailed-analytics"] },
  "/analytics/export": { permissions: ["export analytics"] },

  // ========================================================================
  // INTEGRATIONS
  // ========================================================================
  "/integrations": { permissions: ["view integrations"] },
  "/integrations/create": { permissions: ["create integrations"] },
  "/integrations/:id/edit": { permissions: ["edit integrations"] },
  "/integrations/:id/delete": { permissions: ["delete integrations"] },
  "/integrations/:id/test": { permissions: ["test integrations"] },

  // ========================================================================
  // TEMPLATES
  // ========================================================================
  "/templates": { permissions: ["view templates"] },
  "/templates/create": { permissions: ["create templates"] },
  "/templates/:id/edit": { permissions: ["edit templates"] },
  "/templates/:id/delete": { permissions: ["delete templates"] },
  "/templates/:id/submit": { permissions: ["submit templates"] },

  // ========================================================================
  // WEBHOOKS
  // ========================================================================
  "/webhooks": { permissions: ["view webhooks"] },
  "/webhooks/create": { permissions: ["create webhooks"] },
  "/webhooks/:id/edit": { permissions: ["edit webhooks"] },
  "/webhooks/:id/delete": { permissions: ["delete webhooks"] },

  // ========================================================================
  // SETTINGS
  // ========================================================================
  "/settings": { permissions: ["view settings"] },
  "/settings/general": { permissions: ["manage settings"] },
  "/settings/billing": { permissions: ["manage billing"] },

  // ========================================================================
  // PROFILE
  // ========================================================================
  "/profile": {},
  "/my-profile": {},
};

// ========================================================================
// HELPER FUNCTIONS
// ========================================================================

/**
 * Get permissions for a route
 */
export const getRoutePermissions = (
  route: RouteLocationNormalized,
): RoutePermissions | null => {
  const path = route.path.replace(/\/$/, "") || "/";

  // Exact match
  if (routePermissionsMap[path]) return routePermissionsMap[path];

  // Match dynamic segments
  const segments = path.split("/").filter(Boolean);

  for (let i = segments.length; i > 0; i--) {
    const partialPath = "/" + segments.slice(0, i).join("/");

    // Check parent path
    if (routePermissionsMap[partialPath])
      return routePermissionsMap[partialPath];

    // Check dynamic patterns
    const pattern = Object.keys(routePermissionsMap).find((p) => {
      if (!p.includes(":")) return false;
      const pSegments = p.split("/").filter(Boolean);
      if (pSegments.length !== i) return false;
      return pSegments.every(
        (seg, idx) => seg.startsWith(":") || seg === segments[idx],
      );
    });

    if (pattern) return routePermissionsMap[pattern];
  }

  // Fallback to route.meta
  const meta = route.meta;
  if (meta.permissions || meta.roles) {
    return {
      permissions: meta.permissions as string[],
      roles: meta.roles as string[],
      logic: (meta.logic as "AND" | "OR") || "OR",
    };
  }

  return null;
};

// ========================================================================
// ACCESS CHECKER
// ========================================================================

/**
 * Check if user can access a route
 */
export const canAccessRoute = (route: RouteLocationNormalized): boolean => {
  const userStore = useUserStore();
  const user = userStore.user;

  if (!user) return false;

  const perms = getRoutePermissions(route);
  if (!perms) return true; // No restrictions

  // Super-admin can access everything
  if (user.is_super_admin) return true;

  // Get user roles as strings
  const userRoles = user.roles?.map((r) => r.name) || [];

  // Check roles
  if (perms.roles?.length) {
    const hasRequiredRole =
      perms.logic === "AND"
        ? perms.roles.every((r) => userRoles.includes(r))
        : perms.roles.some((r) => userRoles.includes(r));

    if (!hasRequiredRole) return false;
  }

  // Check permissions
  if (perms.permissions?.length) {
    const userPermissions = user.permissions?.map((p) => p.name) || [];
    const hasRequiredPerm =
      perms.logic === "AND"
        ? perms.permissions.every((p) => userPermissions.includes(p))
        : perms.permissions.some((p) => userPermissions.includes(p));

    if (!hasRequiredPerm) return false;
  }

  return true;
};

// ========================================================================
// HELPER CHECKERS
// ========================================================================

/**
 * Check if user has specific role(s)
 */
export const hasRole = (role: string | string[]): boolean => {
  const user = useUserStore().user;
  if (!user) return false;
  if (user.is_super_admin) return true;

  const roles = user.roles?.map((r) => r.name) || [];
  const targetRoles = Array.isArray(role) ? role : [role];

  return targetRoles.some((r) => roles.includes(r));
};

/**
 * Check if user has specific permission(s)
 */
export const hasPermission = (permission: string | string[]): boolean => {
  const user = useUserStore().user;
  if (!user) return false;
  if (user.is_super_admin) return true;

  const permissions = user.permissions?.map((p) => p.name) || [];
  const targetPerms = Array.isArray(permission) ? permission : [permission];

  return targetPerms.some((p) => permissions.includes(p));
};

/**
 * Check if user can manage a specific chatbot
 */
export const canManageChatbot = (chatbotOwnerId?: number): boolean => {
  const user = useUserStore().user;
  if (!user) return false;

  // Super-admin and tenant-admin can manage all
  if (user.is_super_admin || hasRole("tenant-admin")) return true;

  // Bot-builder can manage chatbots they own
  if (
    hasRole("bot-builder") &&
    chatbotOwnerId &&
    user.user_id === chatbotOwnerId
  ) {
    return true;
  }

  return false;
};
