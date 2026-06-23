// stores/user.ts
import { defineStore } from "pinia";
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { initEcho, destroyEcho } from "@/echo";

// ── Types ─────────────────────────────────────────────────────────────────────

export interface Tenant {
  id: number;
  name: string;
  slug: string;
  domain: string;
  is_primary?: boolean;
  is_current?: boolean;
  is_active?: boolean;
}

export interface Permission {
  id: number;
  name: string;
}

export interface Role {
  id: number;
  name: string;
}

export interface CurrentTenant extends Tenant {
  subscription_tier?: string;
  subscription_expires_at?: string;
  max_chatbots?: number;
  max_conversations_per_month?: number;
  settings?: Record<string, any>;
  features?: Record<string, any>;
  logo_url?: string | null;
  logo_url_full?: string | null;
  country?: string | null;
  currency_code?: string | null;
  currency_symbol?: string | null;
}

export interface User {
  id?: number;
  user_id?: number;
  name: string;
  email: string;
  username?: string;
  avatar?: string | null;
  timezone?: string;
  locale?: string;
  is_super_admin?: boolean;
  is_active?: boolean;
  password_reset_required?: boolean;
  last_login?: string | null;
  failed_login_attempts?: number;
  locked_until?: string | null;
  is_locked?: boolean;
  roles: string[] | Role[];
  permissions: string[] | Permission[];
  tenants?: Tenant[];
  primary_tenant?: Tenant | null;
  current_tenant?: CurrentTenant | null;
}

export type UserType = "system" | "tenant" | null;

// ── Normalisation helpers ─────────────────────────────────────────────────────

const toRoleNames = (roles: string[] | Role[] | undefined): string[] => {
  if (!roles?.length) return [];
  return typeof roles[0] === "string"
    ? (roles as string[])
    : (roles as Role[]).map((r) => r.name);
};

const toPermNames = (perms: string[] | Permission[] | undefined): string[] => {
  if (!perms?.length) return [];
  return typeof perms[0] === "string"
    ? (perms as string[])
    : (perms as Permission[]).map((p) => p.name);
};

const resolveUserId = (u: User): number | undefined => u.id ?? u.user_id;

// System-side roles that should be treated as having full admin access.
// Your backend currently returns "admin" for system users (not
// "super-admin") — both are recognised here so canAccessRoute() doesn't
// block a legitimately privileged system user from any route.
const SUPER_ADMIN_ROLE_NAMES = ["super-admin", "admin"];

// ── Store ─────────────────────────────────────────────────────────────────────

export const useUserStore = defineStore(
  "user",
  () => {
    const router = useRouter();

    // ── State ─────────────────────────────────────────────────────────────────

    const user = ref<User | null>(null);
    const accessToken = ref<string | null>(null);
    const userType = ref<UserType>(null);
    const isLoading = ref(false);
    const isLoaded = ref(false);
    const error = ref<string | null>(null);
    const currentTenant = ref<CurrentTenant | null>(null);
    const availableTenants = ref<Tenant[]>([]);

    // ── Computed ──────────────────────────────────────────────────────────────

    const isLoggedIn = computed(
      () => !!user.value && !!resolveUserId(user.value),
    );
    const isAuthenticated = computed(() => isLoggedIn.value);
    const token = computed(() => accessToken.value);

    const isSystemUser = computed(() => userType.value === "system");
    const isTenantUser = computed(() => userType.value === "tenant");

    const displayName = computed(() => {
      if (!user.value) return "";
      return user.value.name || user.value.username || user.value.email;
    });

    const _roleNames = computed(() => toRoleNames(user.value?.roles));
    const _permNames = computed(() => toPermNames(user.value?.permissions));

    const userRole = computed(() => _roleNames.value[0] ?? "");

    // FIXED: previously only recognised the literal string "super-admin",
    // but the system auth backend returns roles: ["admin"]. That mismatch
    // made isSuperAdmin always false for system admins, which meant
    // canAccessRoute() fell through to the normal permission/role checks
    // instead of bypassing them — fine for /dashboard (no meta restrictions)
    // but it WOULD have blocked any route with meta.roles: ["super-admin"].
    const isSuperAdmin = computed(
      () =>
        !!user.value?.is_super_admin ||
        _roleNames.value.some((r) => SUPER_ADMIN_ROLE_NAMES.includes(r)),
    );
    const isTenantAdmin = computed(() =>
      _roleNames.value.includes("tenant-admin"),
    );
    const isBotBuilder = computed(() =>
      _roleNames.value.includes("bot-builder"),
    );
    const isAgent = computed(() => _roleNames.value.includes("agent"));
    const isViewer = computed(() => _roleNames.value.includes("viewer"));
    const isAdmin = computed(() => isSuperAdmin.value || isTenantAdmin.value);

    const hasPermission = (permission: string) =>
      _permNames.value.includes(permission);
    const hasRole = (role: string) => _roleNames.value.includes(role);
    const hasAnyRole = (roles: string[]) => roles.some(hasRole);

    const primaryTenant = computed(() => user.value?.primary_tenant ?? null);
    const currentTenantInfo = computed(
      () => currentTenant.value || user.value?.current_tenant || null,
    );

    const tenantById = (id: number) =>
      availableTenants.value.find((t) => t.id === id) ||
      user.value?.tenants?.find((t) => t.id === id) ||
      null;

    const isCurrentTenant = (tenantId: number) =>
      currentTenantInfo.value?.id === tenantId;

    // ── Session helpers ───────────────────────────────────────────────────────

    const _setSession = (data: any, type: UserType) => {
      const userData: User = data.userData ?? data.user;
      const tkn: string | null = data.accessToken ?? data.token ?? null;

      user.value = userData;
      accessToken.value = tkn;
      userType.value = data.type ?? type;
      currentTenant.value = data.tenant ?? userData?.current_tenant ?? null;
      availableTenants.value = userData?.tenants ?? [];
      isLoaded.value = true;
      error.value = null;

      if (tkn) {
        axios.defaults.headers.common["Authorization"] = `Bearer ${tkn}`;
      } else {
        delete axios.defaults.headers.common["Authorization"];
      }

      initEcho();

      console.log("✅ Session set:", {
        type: userType.value,
        id: resolveUserId(userData),
        roles: toRoleNames(userData?.roles),
        tenant: currentTenant.value?.name ?? null,
      });
    };

    const _clearSession = () => {
      console.log("🧹 Clearing session");
      destroyEcho();
      user.value = null;
      accessToken.value = null;
      userType.value = null;
      currentTenant.value = null;
      availableTenants.value = [];
      isLoaded.value = false;
      error.value = null;
      delete axios.defaults.headers.common["Authorization"];
    };

    // ── Auth actions ──────────────────────────────────────────────────────────

    const systemLogin = async (credentials: {
      email: string;
      password: string;
    }) => {
      isLoading.value = true;
      error.value = null;

      try {
        const { data } = await axios.post("/api/auth/login", credentials);

        if (data.password_reset_required) {
          return {
            success: false,
            passwordResetRequired: true,
            userId: data.user_id ?? data.user?.id,
            token: data.token ?? null,
            message: data.message,
          };
        }

        if (data.redirect_to) {
          return {
            success: false,
            redirectTo: data.redirect_to,
            message: data.message,
          };
        }

        _setSession(data, "system");
        return { success: true, data };
      } catch (err: any) {
        _clearSession();

        console.log("Error logging in:", err);
        if (err.response?.status === 423) {
          error.value = err.response.data.message;
          return {
            success: false,
            locked: true,
            minutesLeft: err.response.data.minutes_left,
            lockedUntil: err.response.data.locked_until,
            error: error.value,
          };
        }

        const message =
          err.response?.data?.errors?.email?.[0] ??
          err.response?.data?.message ??
          "Login failed.";
        error.value = message;
        return { success: false, error: message };
      } finally {
        isLoading.value = false;
      }
    };

    const tenantLogin = async (credentials: {
      email: string;
      password: string;
      tenant_slug: string;
    }) => {
      isLoading.value = true;
      error.value = null;

      try {
        const { data } = await axios.post("/tenant/auth/login", credentials);

        if (data.password_reset_required) {
          return {
            success: false,
            passwordResetRequired: true,
            userId: data.user_id ?? data.user?.id,
            token: data.token ?? null,
            message: data.message,
          };
        }

        _setSession(data, "tenant");

        const roleNames = toRoleNames(data.user?.roles);
        return {
          success: true,
          data,
          isEmployee: roleNames.includes("Employee"),
          passwordResetRequired: data.user?.password_reset_required === true,
        };
      } catch (err: any) {
        _clearSession();
        const message = err.response?.data?.message ?? "Login failed.";
        error.value = message;
        return { success: false, error: message };
      } finally {
        isLoading.value = false;
      }
    };

    const logout = async () => {
      const wasSystem = userType.value === "system";
      try {
        const url = wasSystem ? "/api/auth/logout" : "/tenant/auth/logout";
        await axios.post(url);
      } catch {
        /* ignore */
      } finally {
        _clearSession();
        router.push({ name: wasSystem ? "system-login" : "login" } as any);
      }
    };

    // ── User data ─────────────────────────────────────────────────────────────

    const fetchMe = async () => {
      if (
        ["/account-error", "/login", "/system-login"].includes(
          window.location.pathname,
        )
      ) {
        return null;
      }

      if (isLoaded.value && user.value) return user.value;

      isLoading.value = true;
      try {
        if (userType.value) {
          const url =
            userType.value === "system" ? "/api/auth/me" : "/tenant/auth/me";
          const { data } = await axios.get(url);
          _setSession(data, userType.value);
          return user.value;
        }

        // userType unknown — probe tenant first, then system.
        try {
          const { data } = await axios.get("/tenant/auth/me");
          _setSession(data, "tenant");
          return user.value;
        } catch (tenantErr: any) {
          if (tenantErr.response?.status !== 401) throw tenantErr;

          const { data } = await axios.get("/api/auth/me");
          _setSession(data, "system");
          return user.value;
        }
      } catch (err: any) {
        if (err.response?.status === 401) _clearSession();
        error.value = err.response?.data?.message ?? "Failed to load user";
        throw err;
      } finally {
        isLoading.value = false;
      }
    };

    const fetchUser = fetchMe;

    const fetchProfile = async () => {
      try {
        const url =
          userType.value === "system"
            ? "/api/auth/profile"
            : "/tenant/auth/profile";
        const { data } = await axios.get(url);
        if (data.success && data.data) {
          updateUserData(data.data.user);
          if (data.data.current_tenant)
            currentTenant.value = data.data.current_tenant;
          if (data.data.tenants) availableTenants.value = data.data.tenants;
        }
        return data.data;
      } catch (err) {
        console.error("Failed to fetch profile", err);
        return null;
      }
    };

    const updateUserData = (userData: Partial<User>) => {
      if (user.value) {
        user.value = { ...user.value, ...userData };
        if (userData.current_tenant)
          currentTenant.value = userData.current_tenant;
        if (userData.tenants) availableTenants.value = userData.tenants;
      }
    };

    // ── Other actions ─────────────────────────────────────────────────────────

    const switchTenant = async (tenantId: number) => {
      try {
        const { data } = await axios.post("/api/auth/switch-tenant", {
          tenant_id: tenantId,
        });
        if (data.success && data.tenant) {
          currentTenant.value = data.tenant;
          if (user.value?.tenants) {
            user.value.primary_tenant = data.tenant;
            user.value.tenants = user.value.tenants.map((t) => ({
              ...t,
              is_current: t.id === data.tenant.id,
              is_primary: t.id === data.tenant.id ? true : t.is_primary,
            }));
            availableTenants.value = user.value.tenants;
          }
          return { success: true, tenant: data.tenant };
        }
        if (data.redirect_to)
          return {
            success: false,
            redirectTo: data.redirect_to,
            message: data.message,
          };
        return { success: false, error: data.message };
      } catch (err: any) {
        return { success: false, error: err.response?.data?.message };
      }
    };

    const updateProfile = async (profileData: Partial<User>) => {
      try {
        const url =
          userType.value === "system"
            ? "/api/auth/profile"
            : "/tenant/auth/profile";
        const { data } = await axios.put(url, profileData);
        if (data.success && data.data) {
          updateUserData(data.data);
          return { success: true, data: data.data };
        }
        return { success: false, error: data.message };
      } catch (err: any) {
        return { success: false, error: err.response?.data?.message };
      }
    };

    const changePassword = async (passwordData: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => {
      try {
        const url =
          userType.value === "system"
            ? "/api/auth/change-password"
            : "/tenant/auth/change-password";
        const { data } = await axios.post(url, passwordData);
        if (user.value) user.value.password_reset_required = false;
        return { success: true, message: data.message };
      } catch (err: any) {
        return {
          success: false,
          error: err.response?.data?.errors ?? err.response?.data?.message,
        };
      }
    };

    const forceResetPassword = async (resetData: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => {
      try {
        const url =
          userType.value === "system"
            ? "/api/auth/force-reset-password"
            : "/tenant/auth/force-reset-password";
        const { data } = await axios.post(url, resetData);
        if (data.success) {
          if (user.value) user.value.password_reset_required = false;
          return { success: true, message: data.message };
        }
        return { success: false, error: data.message };
      } catch (err: any) {
        return { success: false, error: err.response?.data?.message };
      }
    };

    const clearError = () => {
      error.value = null;
    };

    // ── Expose ────────────────────────────────────────────────────────────────

    return {
      user,
      accessToken,
      userType,
      isLoading,
      isLoaded,
      error,
      currentTenant,
      availableTenants,
      isLoggedIn,
      isAuthenticated,
      token,
      isSystemUser,
      isTenantUser,
      displayName,
      userRole,
      isSuperAdmin,
      isTenantAdmin,
      isBotBuilder,
      isAgent,
      isViewer,
      isAdmin,
      hasPermission,
      hasRole,
      hasAnyRole,
      primaryTenant,
      currentTenantInfo,
      tenantById,
      isCurrentTenant,
      systemLogin,
      tenantLogin,
      logout,
      fetchMe,
      fetchUser,
      fetchProfile,
      updateUserData,
      switchTenant,
      updateProfile,
      changePassword,
      forceResetPassword,
      clearError,
      _setSession,
      _clearSession,
    };
  },
  {
    persist: {
      key: "user-data",
      storage: localStorage,
      pick: [
        "user",
        "accessToken",
        "userType",
        "isLoaded",
        "currentTenant",
        "availableTenants",
      ],
    },
  },
);
