// layouts/components/vertical-sidebar/sidebarItem.ts
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
  // ── Dashboard — no restrictions, route has no meta.permissions/roles ────
  {
    title: "Dashboard",
    icon: "$home",
    to: "/dashboard",
  },

  // ── Administration — Tenants (super-admin only, per route meta) ────────
  {
    title: "Tenants",
    icon: "$homeCity",
    to: "/administration/tenants",
    roles: ["super-admin", "admin"],
  },

  // ── Administration — Users ──────────────────────────────────────────────
  {
    title: "Admin Users",
    icon: "$accountGroup",
    to: "/administration/users",
    permissions: ["view users"],
  },

  // ── Chatbots ─────────────────────────────────────────────────────────────
  {
    title: "Chatbots",
    icon: "$robot",
    to: "/chatbots",
    permissions: ["view bots"],
  },

  // ── Variables ────────────────────────────────────────────────────────────
  {
    title: "Variables",
    icon: "$variable",
    to: "/variables",
    permissions: ["view variables"],
  },

  // ── Functions ────────────────────────────────────────────────────────────
  {
    title: "Functions",
    icon: "$codeJson",
    to: "/functions",
    permissions: ["view functions"],
  },

  // ── WhatsApp Accounts ────────────────────────────────────────────────────
  {
    title: "WhatsApp Accounts",
    icon: "$whatsapp",
    to: "/whatsapp-accounts",
    permissions: ["view whatsapp-accounts"],
  },

  // ── Conversations ────────────────────────────────────────────────────────
  {
    title: "Conversations",
    icon: "$chatOutline",
    to: "/conversations",
    permissions: ["view conversations"],
  },

  // ── Analytics ────────────────────────────────────────────────────────────
  {
    title: "Analytics",
    icon: "$chartLine",
    to: "/analytics",
    permissions: ["view analytics"],
  },

  // ── Integrations ─────────────────────────────────────────────────────────
  {
    title: "Integrations",
    icon: "$apiOff",
    to: "/integrations",
    permissions: ["view integrations"],
  },

  // ── Templates ────────────────────────────────────────────────────────────
  {
    title: "Templates",
    icon: "$fileDocument",
    to: "/templates",
    permissions: ["view templates"],
  },

  // ── Webhooks ─────────────────────────────────────────────────────────────
  {
    title: "Webhooks",
    icon: "$webhook",
    to: "/webhooks",
    permissions: ["view webhooks"],
  },
  // ── Webhooks ─────────────────────────────────────────────────────────────
  {
    title: "Register Account",
    icon: "$whatsApp",
    to: "/register",
    permissions: ["view whatsapp-accounts"],
  },

  { divider: true },

  // ── Settings ─────────────────────────────────────────────────────────────
  {
    title: "Settings",
    icon: "$cog",
    to: "/settings",
    permissions: ["view settings"],
  },

  // ── My Profile — no restrictions ────────────────────────────────────────
  {
    title: "My Profile",
    icon: "$account",
    to: "/profile",
  },
];

function hasRequiredPermissions(item: MenuItem): boolean {
  if (!item.permissions?.length) return true;

  const userStore = useUserStore();
  const logic = item.permissionLogic || "AND";

  return logic === "OR"
    ? item.permissions.some((p) => userStore.hasPermission(p))
    : item.permissions.every((p) => userStore.hasPermission(p));
}

function hasRequiredRoles(item: MenuItem): boolean {
  if (!item.roles?.length) return true;

  const userStore = useUserStore();

  if (userStore.isSuperAdmin) return true; // super-admin bypasses role gates everywhere

  const logic = item.roleLogic || "OR";

  return logic === "OR"
    ? item.roles.some((r) => userStore.hasRole(r))
    : item.roles.every((r) => userStore.hasRole(r));
}

function hasAccess(item: MenuItem): boolean {
  const userStore = useUserStore();

  if (userStore.isSuperAdmin) return true;

  const hasRoleRequirement = !!item.roles?.length;
  const hasPermissionRequirement = !!item.permissions?.length;

  if (!hasRoleRequirement && !hasPermissionRequirement) return true;
  if (hasRoleRequirement && !hasPermissionRequirement)
    return hasRequiredRoles(item);
  if (!hasRoleRequirement && hasPermissionRequirement)
    return hasRequiredPermissions(item);

  return hasRequiredRoles(item) || hasRequiredPermissions(item);
}

function filterMenuItems(items: MenuItem[]): MenuItem[] {
  return items
    .filter((item) => item.divider || hasAccess(item))
    .map((item) => {
      if (!item.children?.length) return item;

      const filteredChildren = filterMenuItems(item.children);
      if (filteredChildren.length === 0) return null;

      return { ...item, children: filteredChildren };
    })
    .filter((item): item is MenuItem => item !== null);
}

// ============================================================================
// REACTIVE SIDEBAR ITEMS
// ============================================================================

export const sidebarItems = computed(() => {
  const userStore = useUserStore();

  if (!userStore.isLoggedIn) {
    return [];
  }

  return filterMenuItems(rawSidebarItems);
});

// ============================================================================
// COMPOSABLE API
// ============================================================================

export const useFilteredMenu = () => {
  const userStore = useUserStore();

  const refreshMenu = async () => {
    if (!userStore.isLoggedIn) {
      await userStore.fetchMe();
    }
    return sidebarItems.value;
  };

  return {
    sidebarItems,
    refreshMenu,
    hasPermission: userStore.hasPermission,
    hasAnyRole: userStore.hasAnyRole,
    hasRole: userStore.hasRole,
  };
};

export default sidebarItems;
