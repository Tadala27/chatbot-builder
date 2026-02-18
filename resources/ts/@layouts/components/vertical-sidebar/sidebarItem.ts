import { computed } from "vue";
import { useUserStore } from "@/stores/user";

// ============================================================================
// TYPES
// ============================================================================

export interface MenuItem {
  id?: string;
  title?: string;
  icon?: string;
  to?: string;
  divider?: boolean;
  chip?: string;
  chipColor?: string;
  chipVariant?: string;
  chipIcon?: string;
  children?: MenuItem[];
  disabled?: boolean;
  type?: string;
  subCaption?: string;
  permissions?: string[];
  permissionLogic?: "AND" | "OR";
  roles?: string[];
  roleLogic?: "AND" | "OR";
}

// ============================================================================
// MENU CONFIGURATION
// ============================================================================

const rawSidebarItems: MenuItem[] = [
  // ========================================================================
  // DASHBOARD
  // ========================================================================
  {
    title: "Dashboard",
    icon: "$home",
    to: "/dashboard",
  },

  // ========================================================================
  // SUPER ADMIN ONLY - Tenant Management
  // ========================================================================
  {
    title: "Tenants",
    icon: "$homeCity",
    to: "/admin/tenants",
    roles: ["super-admin"],
    permissions: ["view tenants", "create tenants"],
    permissionLogic: "OR",
  },

  // ========================================================================
  // WHATSAPP ACCOUNTS
  // ========================================================================
  {
    title: "WhatsApp Accounts",
    icon: "$whatsapp",
    to: "/whatsapp",
    permissions: ["view whatsapp-accounts"],
    children: [
      {
        title: "Connected Accounts",
        to: "/whatsapp/accounts",
        permissions: ["view whatsapp-accounts"],
      },
      {
        title: "Connect New Account",
        to: "/whatsapp/connect",
        permissions: ["connect whatsapp-accounts"],
      },
      {
        title: "Account Health",
        to: "/whatsapp/health",
        permissions: ["view whatsapp-accounts"],
      },
    ],
  },

  // ========================================================================
  // CHATBOTS
  // ========================================================================
  {
    title: "Chatbots",
    icon: "$robot",
    to: "/chatbots",
    permissions: ["view chatbots"],
    children: [
      {
        title: "All Chatbots",
        to: "/chatbots",
        permissions: ["view chatbots"],
      },
      {
        title: "Create Chatbot",
        to: "/chatbots/create",
        permissions: ["create chatbots"],
      },
      {
        title: "Published Bots",
        to: "/chatbots/published",
        permissions: ["view chatbots"],
      },
      {
        title: "Draft Bots",
        to: "/chatbots/drafts",
        permissions: ["view chatbots"],
      },
    ],
  },

  // ========================================================================
  // CONVERSATIONS
  // ========================================================================
  {
    title: "Conversations",
    icon: "$chatOutline",
    to: "/conversations",
    permissions: ["view conversations"],
    children: [
      {
        title: "All Conversations",
        to: "/conversations",
        permissions: ["view conversations"],
      },
      {
        title: "Active",
        to: "/conversations/active",
        permissions: ["view conversations"],
      },
      {
        title: "Completed",
        to: "/conversations/completed",
        permissions: ["view conversations"],
      },
      {
        title: "Handed Off",
        to: "/conversations/handoff",
        permissions: ["view conversations", "handoff conversations"],
        permissionLogic: "OR",
      },
    ],
  },

  // ========================================================================
  // ANALYTICS & REPORTS
  // ========================================================================
  {
    title: "Analytics",
    icon: "$chartLine",
    to: "/analytics",
    permissions: ["view analytics"],
    children: [
      {
        title: "Overview",
        to: "/analytics/overview",
        permissions: ["view analytics"],
      },
      {
        title: "Chatbot Performance",
        to: "/analytics/chatbots",
        permissions: ["view analytics"],
      },
      {
        title: "Conversation Metrics",
        to: "/analytics/conversations",
        permissions: ["view analytics"],
      },
      {
        title: "Popular Paths",
        to: "/analytics/paths",
        permissions: ["view detailed-analytics"],
      },
      {
        title: "Drop-off Points",
        to: "/analytics/dropoff",
        permissions: ["view detailed-analytics"],
      },
      {
        title: "Export Data",
        to: "/analytics/export",
        permissions: ["export analytics"],
      },
    ],
  },

  // ========================================================================
  // VARIABLES & FUNCTIONS
  // ========================================================================
  {
    title: "Development",
    icon: "$codeJson",
    to: "/development",
    permissions: ["view variables", "view functions"],
    permissionLogic: "OR",
    children: [
      {
        title: "Global Variables",
        to: "/variables/global",
        permissions: ["view variables"],
      },
      {
        title: "Custom Functions",
        to: "/functions",
        permissions: ["view functions"],
      },
      {
        title: "Built-in Functions",
        to: "/functions/built-in",
        permissions: ["view functions"],
      },
      {
        title: "API Integrations",
        to: "/integrations",
        permissions: ["view integrations"],
      },
    ],
  },

  // ========================================================================
  // MESSAGE TEMPLATES
  // ========================================================================
  {
    title: "Templates",
    icon: "$fileDocument",
    to: "/templates",
    permissions: ["view templates"],
    children: [
      {
        title: "All Templates",
        to: "/templates",
        permissions: ["view templates"],
      },
      {
        title: "Create Template",
        to: "/templates/create",
        permissions: ["create templates"],
      },
      {
        title: "Pending Approval",
        to: "/templates/pending",
        permissions: ["submit templates"],
      },
    ],
  },

  // ========================================================================
  // TEAM MANAGEMENT
  // ========================================================================
  {
    title: "Team",
    icon: "$accountGroup",
    to: "/team",
    permissions: ["view users"],
    roles: ["tenant-admin", "super-admin"],
    roleLogic: "OR",
    children: [
      {
        title: "Team Members",
        to: "/team/members",
        permissions: ["view users"],
      },
      {
        title: "Invite User",
        to: "/team/invite",
        permissions: ["invite users"],
      },
      {
        title: "Roles & Permissions",
        to: "/team/roles",
        permissions: ["assign roles"],
      },
    ],
  },

  // ========================================================================
  // SETTINGS
  // ========================================================================
  {
    title: "Settings",
    icon: "$cog",
    to: "/settings",
    permissions: ["view settings"],
    children: [
      {
        title: "General Settings",
        to: "/settings/general",
        permissions: ["manage settings"],
      },
      {
        title: "Subscription",
        to: "/settings/subscription",
        permissions: ["manage billing"],
        roles: ["tenant-admin"],
      },
      {
        title: "Notifications",
        to: "/settings/notifications",
        permissions: ["view settings"],
      },
      {
        title: "API Keys",
        to: "/settings/api-keys",
        permissions: ["manage settings"],
        roles: ["tenant-admin"],
      },
    ],
  },

  // ========================================================================
  // MY PROFILE
  // ========================================================================
  {
    title: "My Profile",
    icon: "$account",
    to: "/profile",
  },

  // ========================================================================
  // ACTIVITY LOG (Admin Only)
  // ========================================================================
  {
    title: "Activity Log",
    icon: "$history",
    to: "/activity-log",
    permissions: ["view settings"],
    roles: ["tenant-admin", "super-admin"],
    roleLogic: "OR",
  },
];

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

/**
 * Extract permission names from user permissions array
 */
function extractPermissionNames(permissions: any[]): string[] {
  if (!Array.isArray(permissions)) return [];
  return permissions.map((p) => (typeof p === "string" ? p : p.name));
}

/**
 * Check if conditions match using AND/OR logic
 */
function checkConditions(
  requiredItems: string[],
  availableItems: string[],
  logic: "AND" | "OR" = "AND",
): boolean {
  if (requiredItems.length === 0) return true;

  return logic === "OR"
    ? requiredItems.some((item) => availableItems.includes(item))
    : requiredItems.every((item) => availableItems.includes(item));
}

// ============================================================================
// PERMISSION & ROLE CHECKING
// ============================================================================

/**
 * Check if user has required permissions
 */
function hasRequiredPermissions(
  item: MenuItem,
  userPermissions: string[],
): boolean {
  if (!item.permissions?.length) return true;

  const logic = item.permissionLogic || "AND";
  return checkConditions(item.permissions, userPermissions, logic);
}

/**
 * Check if user has required roles
 */
function hasRequiredRoles(item: MenuItem, userRoles: string[]): boolean {
  if (!item.roles?.length) return true;

  const logic = item.roleLogic || "OR";
  return checkConditions(item.roles, userRoles, logic);
}

/**
 * Main access check - combines roles AND permissions
 */
function hasAccess(
  item: MenuItem,
  userRoles: string[],
  userPermissions: string[],
): boolean {
  const hasRoleRequirement = item.roles && item.roles.length > 0;
  const hasPermissionRequirement =
    item.permissions && item.permissions.length > 0;

  // No requirements = everyone can access
  if (!hasRoleRequirement && !hasPermissionRequirement) {
    return true;
  }

  // Only role check
  if (hasRoleRequirement && !hasPermissionRequirement) {
    return hasRequiredRoles(item, userRoles);
  }

  // Only permission check
  if (!hasRoleRequirement && hasPermissionRequirement) {
    return hasRequiredPermissions(item, userPermissions);
  }

  // Both specified: User needs EITHER correct role OR correct permission
  const roleCheck = hasRequiredRoles(item, userRoles);
  const permissionCheck = hasRequiredPermissions(item, userPermissions);

  return roleCheck || permissionCheck;
}

// ============================================================================
// MENU FILTERING
// ============================================================================

/**
 * Filter menu items recursively based on permissions and roles
 */
function filterMenuItems(
  items: MenuItem[],
  userRoles: string[],
  userPermissions: string[],
): MenuItem[] {
  return items
    .filter((item) => {
      return hasAccess(item, userRoles, userPermissions);
    })
    .map((item) => {
      if (!item.children?.length) return item;

      // Recursively filter children
      const filteredChildren = filterMenuItems(
        item.children,
        userRoles,
        userPermissions,
      );

      // If parent has no valid children, hide it
      if (filteredChildren.length === 0) {
        return null;
      }

      return { ...item, children: filteredChildren };
    })
    .filter(Boolean) as MenuItem[];
}

// ============================================================================
// REACTIVE SIDEBAR ITEMS
// ============================================================================

export const sidebarItems = computed(() => {
  const userStore = useUserStore();

  // 1. If we have a token but user not loaded → trigger fetch (non-blocking)
  if (userStore.token && !userStore.isLoaded && !userStore.isLoading) {
    userStore.fetchUser().catch(() => {
      // Silently fail — navigation guard will redirect anyway
    });
  }

  // 2. While loading or not ready → return empty menu (no flicker)
  if (!userStore.isLoaded || !userStore.user) {
    return [];
  }

  // 3. User is fully loaded → build menu
  const userPermissions = extractPermissionNames(
    userStore.user?.permissions || [],
  );
  const userRoles = userStore.user?.roles || [];

  return filterMenuItems(rawSidebarItems, userRoles, userPermissions);
});

// ============================================================================
// COMPOSABLE API
// ============================================================================

export const useFilteredMenu = () => {
  const userStore = useUserStore();

  const refreshMenu = async () => {
    if (!userStore.isLoaded || !userStore.user) {
      await userStore.fetchUser();
    }
    return sidebarItems.value;
  };

  const getUserPermissions = (): string[] => {
    return extractPermissionNames(userStore.user?.permissions || []);
  };

  const getUserRoles = (): string[] => {
    return userStore.user?.roles || [];
  };

  const hasPermission = (permission: string): boolean => {
    const permissions = getUserPermissions();
    return permissions.includes(permission);
  };

  const hasAnyPermission = (permissions: string[]): boolean => {
    const userPermissions = getUserPermissions();
    return permissions.some((p) => userPermissions.includes(p));
  };

  const hasAllPermissions = (permissions: string[]): boolean => {
    const userPermissions = getUserPermissions();
    return permissions.every((p) => userPermissions.includes(p));
  };

  const hasRole = (role: string): boolean => {
    return getUserRoles().includes(role);
  };

  const hasAnyRole = (roles: string[]): boolean => {
    const userRoles = getUserRoles();
    return roles.some((r) => userRoles.includes(r));
  };

  return {
    sidebarItems,
    refreshMenu,
    getUserPermissions,
    getUserRoles,
    hasPermission,
    hasAnyPermission,
    hasAllPermissions,
    hasRole,
    hasAnyRole,
  };
};

export default sidebarItems;
