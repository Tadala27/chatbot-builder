// router/router.ts
import type { App } from "vue";
import {
  createRouter,
  createWebHistory,
  type RouteRecordRaw,
  type RouteLocationNormalized,
} from "vue-router";
import { useUIStore } from "@/plugins/ui";
import { useUserStore } from "@/stores/user";

import DefaultLayout from "@/layouts/default.vue";
import BlankLayout from "@/layouts/blank.vue";

// ── Page imports — all lazy-loaded ────────────────────────────────────────────

// Auth
const Login = () => import("@/pages/auth/login.vue");
const SystemLogin = () => import("@/pages/auth/system-login.vue");
const ForgotPassword = () => import("@/pages/auth/forgot-password.vue");
const ResetPassword = () => import("@/pages/auth/reset-password.vue");

// Errors
const NotAuthorized = () => import("@/pages/errors/not-authorized.vue");
const AccountError = () => import("@/pages/errors/account-error.vue");

// App
const Dashboard = () => import("@/pages/dashboard.vue");
const Profile = () => import("@/pages/profile.vue");

// Administration
const AdminUsers = () => import("@/pages/administration/users/index.vue");
const AdminUserCreate = () => import("@/pages/administration/users/create.vue");
const AdminUserDetail = () => import("@/pages/administration/users/[id].vue");
const AdminUserEdit = () =>
  import("@/pages/administration/users/[id]-edit.vue");
const AdminTenants = () => import("@/pages/administration/tenants/index.vue");
const AdminTenantCreate = () =>
  import("@/pages/administration/tenants/create.vue");
const AdminTenantDetail = () =>
  import("@/pages/administration/tenants/show.vue");
const AdminTenantEdit = () =>
  import("@/pages/administration/tenants/create.vue");

// Chatbots
const Chatbots = () => import("@/pages/chatbots/index.vue");
const ChatbotCreate = () => import("@/pages/chatbots/create.vue");
const ChatbotDetail = () => import("@/pages/chatbots/[id].vue");
const ChatbotEdit = () => import("@/pages/chatbots/[id]-edit.vue");
const ChatbotFlow = () => import("@/pages/chatbots/[id]-flow.vue");
const ChatbotSettings = () => import("@/pages/chatbots/settings.vue");

// Variables / Functions
const Variables = () => import("@/pages/variables/index.vue");
const Functions = () => import("@/pages/functions/index.vue");

// WhatsApp
const WhatsappAccounts = () => import("@/pages/whatsapp-accounts/index.vue");
const WhatsappDetails = () => import("@/pages/whatsapp-accounts/show.vue");
const RegisterAccount = () => import("@/pages/whatsapp-accounts/register.vue");

// Conversations / Analytics
const Conversations = () => import("@/pages/conversations/index.vue");
const Analytics = () => import("@/pages/analytics/index.vue");

// Integrations / Templates / Webhooks / Settings
const Integrations = () => import("@/pages/integrations/index.vue");
const Templates = () => import("@/pages/templates/index.vue");
const Webhooks = () => import("@/pages/webhooks/index.vue");
const Settings = () => import("@/pages/settings/index.vue");

// ── Meta type augmentation ──────────────────────────────────────────────────────
//
// Declares the custom meta fields used below so TypeScript knows about them
// on RouteRecordRaw.meta / RouteLocationNormalized.meta everywhere in the app.

declare module "vue-router" {
  interface RouteMeta {
    public?: boolean;
    unauthenticatedOnly?: boolean;
    permissions?: string[];
    roles?: string[];
    logic?: "AND" | "OR";
  }
}

// ── Meta shorthand ───────────────────────────────────────────────────────────────

const PUBLIC = { public: true, unauthenticatedOnly: true };
const PUBLIC_UTIL = { public: true };

// ── Route tree (= the permissions map — no separate file) ──────────────────────

const routes: RouteRecordRaw[] = [
  { path: "/", name: "index", redirect: { name: "login" } },

  // ── Blank layout: auth + error pages, always public ─────────────────────
  {
    path: "/",
    component: BlankLayout,
    children: [
      { path: "login", name: "login", component: Login, meta: PUBLIC },
      {
        path: "system-login",
        name: "system-login",
        component: SystemLogin,
        meta: PUBLIC,
      },
      {
        path: "forgot-password",
        name: "forgot-password",
        component: ForgotPassword,
        meta: PUBLIC_UTIL,
      },
      {
        path: "reset-password",
        name: "reset-password",
        component: ResetPassword,
        meta: PUBLIC_UTIL,
      },
      {
        path: "not-authorized",
        name: "not-authorized",
        component: NotAuthorized,
        meta: PUBLIC_UTIL,
      },
      {
        path: "account-error",
        name: "account-error",
        component: AccountError,
        meta: PUBLIC_UTIL,
      },
    ],
  },

  // ── Default layout: private app pages ────────────────────────────────────
  {
    path: "/",
    component: DefaultLayout,
    children: [
      { path: "dashboard", name: "dashboard", component: Dashboard },
      { path: "profile", name: "profile", component: Profile },
      { path: "my-profile", name: "my-profile", component: Profile },

      // Administration
      {
        path: "administration/users",
        name: "admin-users",
        component: AdminUsers,
        meta: { permissions: ["view users"] },
      },
      {
        path: "administration/users/create",
        name: "admin-users-create",
        component: AdminUserCreate,
        meta: { permissions: ["invite users"] },
      },
      {
        path: "administration/users/:id",
        name: "admin-user-detail",
        component: AdminUserDetail,
        meta: { permissions: ["view users"] },
      },
      {
        path: "administration/users/:id/edit",
        name: "admin-user-edit",
        component: AdminUserEdit,
        meta: { permissions: ["edit users"] },
      },

      {
        path: "administration/tenants",
        name: "admin-tenants",
        component: AdminTenants,
        meta: { roles: ["super-admin"] },
      },
      {
        path: "administration/tenants/create",
        name: "admin-tenants-create",
        component: AdminTenantCreate,
        meta: { roles: ["super-admin"] },
      },
      {
        path: "administration/tenants/:id",
        name: "admin-tenant-detail",
        props: (route) => ({ id: route.params.id }),
        component: AdminTenantDetail,
        meta: { roles: ["super-admin"] },
      },
      {
        path: "administration/tenants/:id/edit",
        name: "admin-tenant-edit",
        component: AdminTenantEdit,
        meta: { roles: ["super-admin"] },
      },

      // Chatbots
      {
        path: "chatbots",
        name: "chatbots",
        component: Chatbots,
        meta: { permissions: ["view bots"] },
      },
      {
        path: "chatbots/create",
        name: "chatbots-create",
        component: ChatbotCreate,
        meta: { permissions: ["create bots"] },
      },
      {
        path: "chatbots/:id",
        name: "chatbot-detail",
        component: ChatbotDetail,
        meta: { permissions: ["view bots"] },
      },
      {
        path: "chatbots/:id/edit",
        name: "chatbot-edit",
        component: ChatbotEdit,
        meta: { permissions: ["edit bots"] },
      },
      {
        path: "chatbots/:id/flow",
        name: "chatbot-flow",
        component: ChatbotFlow,
        meta: {
          permissions: ["create bots", "edit bots"],
          logic: "OR",
        },
      },
      {
        path: "chatbots/:id/settings",
        name: "chatbot-settings",
        component: ChatbotSettings,
        props: (route) => ({ botId: route.params.id }),
        meta: {
          permissions: ["create bots", "edit bots"],
          logic: "OR",
        },
      },

      // Variables / Functions
      {
        path: "variables",
        name: "variables",
        component: Variables,
        meta: { permissions: ["view variables"] },
      },
      {
        path: "functions",
        name: "functions",
        component: Functions,
        meta: { permissions: ["view functions"] },
      },

      // WhatsApp
      {
        path: "whatsapp-accounts",
        name: "whatsapp-accounts",
        component: WhatsappAccounts,
        meta: { permissions: ["view whatsapp-accounts"] },
      },
      {
        path: "whatsapp-account/:id/detail",
        name: "whatsapp-account-detail",
        component: WhatsappDetails,
        meta: { permissions: ["view whatsapp-accounts"] },
      },
      {
        path: "register",
        name: "register-accounts",
        component: RegisterAccount,
        meta: { permissions: ["view whatsapp-accounts"] },
      },

      // Conversations / Analytics
      {
        path: "conversations",
        name: "conversations",
        component: Conversations,
        meta: { permissions: ["view conversations"] },
      },
      {
        path: "analytics",
        name: "analytics",
        component: Analytics,
        meta: { permissions: ["view analytics"] },
      },

      // Integrations / Templates / Webhooks / Settings
      {
        path: "integrations",
        name: "integrations",
        component: Integrations,
        meta: { permissions: ["view integrations"] },
      },
      {
        path: "templates",
        name: "templates",
        component: Templates,
        meta: { permissions: ["view templates"] },
      },
      {
        path: "webhooks",
        name: "webhooks",
        component: Webhooks,
        meta: { permissions: ["view webhooks"] },
      },
      {
        path: "settings",
        name: "settings",
        component: Settings,
        meta: { permissions: ["view settings"] },
      },
    ],
  },

  // ── Catch-all ────────────────────────────────────────────────────────────
  { path: "/:pathMatch(.*)*", redirect: { name: "not-authorized" } },
];

// ── Router instance ────────────────────────────────────────────────────────────

const router = createRouter({
  history: createWebHistory("/"),
  routes,
  scrollBehavior(to) {
    if (to.hash) return { el: to.hash, behavior: "smooth", top: 60 };
    return { top: 0 };
  },
});

// ── Access control — reads route.meta directly, no separate map to keep in sync ─

const ALWAYS_PUBLIC = new Set([
  "login",
  "system-login",
  "not-authorized",
  "account-error",
  "forgot-password",
  "reset-password",
]);

/**
 * Returns true if the current user (from the store) satisfies the target
 * route's meta.permissions / meta.roles. Returns false if there's no user
 * at all — callers should check userStore.isLoggedIn separately if they
 * want to distinguish "not logged in" from "logged in but lacks access".
 */
export const canAccessRoute = (route: RouteLocationNormalized): boolean => {
  const userStore = useUserStore();
  if (!userStore.user) return false;
  if (userStore.isSuperAdmin) return true;

  const { permissions, roles, logic = "OR" } = route.meta;

  if (roles?.length) {
    const ok =
      logic === "AND"
        ? userStore.hasAnyRole(roles) && roles.every(userStore.hasRole)
        : userStore.hasAnyRole(roles);
    if (!ok) return false;
  }

  if (permissions?.length) {
    const ok =
      logic === "AND"
        ? permissions.every((p) => userStore.hasPermission(p))
        : permissions.some((p) => userStore.hasPermission(p));
    if (!ok) return false;
  }

  return true;
};

/** Check if the current user has a given role (or any of a list of roles). */
export const hasRole = (role: string | string[]): boolean => {
  const userStore = useUserStore();
  if (!userStore.user) return false;
  if (userStore.isSuperAdmin) return true;
  return userStore.hasAnyRole(Array.isArray(role) ? role : [role]);
};

/** Check if the current user has a given permission (or any of a list). */
export const hasPermission = (permission: string | string[]): boolean => {
  const userStore = useUserStore();
  if (!userStore.user) return false;
  if (userStore.isSuperAdmin) return true;
  const targets = Array.isArray(permission) ? permission : [permission];
  return targets.some((p) => userStore.hasPermission(p));
};

/** Check if the current user can manage a specific chatbot. */
export const canManageChatbot = (chatbotOwnerId?: number): boolean => {
  const userStore = useUserStore();
  if (!userStore.user) return false;
  if (userStore.isSuperAdmin || userStore.isTenantAdmin) return true;
  if (
    userStore.isBotBuilder &&
    chatbotOwnerId &&
    userStore.user.id === chatbotOwnerId
  )
    return true;
  return false;
};

// ── Guards ───────────────────────────────────────────────────────────────────────

router.beforeEach(async (to, _from) => {
  const uiStore = useUIStore();
  uiStore.isLoading = true;

  const userStore = useUserStore();

  console.debug("[guard]", to.fullPath, {
    isLoggedIn: userStore.isLoggedIn,
    userType: userStore.userType,
    isLoaded: userStore.isLoaded,
    hasUser: !!userStore.user,
  });

  // 1. Always-public pages
  if (ALWAYS_PUBLIC.has(to.name as string) || to.meta.public) {
    return true;
  }

  // 2. unauthenticatedOnly (login screens) — bounce logged-in users away
  if (to.meta.unauthenticatedOnly) {
    if (userStore.isLoggedIn) {
      const dest = "dashboard";
      return { name: dest };
    }
    return true;
  }

  // 3. Trust an in-memory user without re-fetching
  if (userStore.isLoggedIn) {
    return canAccessRoute(to) ? true : { name: "not-authorized" };
  }

  // 4. Cold load — attempt session rehydration
  try {
    await userStore.fetchMe();
  } catch {
    userStore._clearSession();
    return redirectToLogin(userStore.userType, to.fullPath);
  }

  if (!userStore.isLoggedIn) {
    return redirectToLogin(userStore.userType, to.fullPath);
  }

  return canAccessRoute(to) ? true : { name: "not-authorized" };
});

router.afterEach(() => {
  const uiStore = useUIStore();
  uiStore.isLoading = false;
});

function redirectToLogin(
  userType: string | null,
  redirectPath: string,
): { name: string; query: Record<string, string> } {
  const isAdminRoute = redirectPath.startsWith("/admin");
  const loginName =
    userType === "system" || isAdminRoute ? "system-login" : "login";
  return { name: loginName, query: { redirect: redirectPath } };
}

// ── Export ───────────────────────────────────────────────────────────────────────

export { router };

export default function (app: App) {
  app.use(router);
}
