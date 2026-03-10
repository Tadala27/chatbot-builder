// stores/user.ts
import { defineStore } from "pinia";
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";
import { initEcho, destroyEcho } from "@/echo"; // ← import the two helpers

// ── Types (unchanged) ─────────────────────────────────────────────────────────

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
}

export interface User {
  user_id: number;
  name: string;
  email: string;
  username?: string;
  avatar: string | null;
  timezone: string;
  locale: string;
  is_super_admin: boolean;
  is_active: boolean;
  password_reset_required: boolean;
  last_login: string | null;
  failed_login_attempts: number;
  locked_until: string | null;
  is_locked: boolean;
  roles: Role[];
  permissions: Permission[];
  tenants: Tenant[];
  primary_tenant: Tenant | null;
  current_tenant: CurrentTenant | null;
}

export interface LoginResponse {
  message: string;
  accessToken: string;
  userData: User;
  userAbilityRules: string[];
  current_tenant: CurrentTenant | null;
  tenants: (Tenant & { is_primary: boolean })[];
  password_reset_required?: boolean;
  user_id?: number;
  token?: string;
  redirect_to?: string;
}

// ── Store ─────────────────────────────────────────────────────────────────────

export const useUserStore = defineStore(
  "user",
  () => {
    const router = useRouter();

    const user = ref<User | null>(null);
    const accessToken = ref<string | null>(null);
    const isLoading = ref(false);
    const isLoaded = ref(false);
    const error = ref<string | null>(null);
    const currentTenant = ref<CurrentTenant | null>(null);
    const availableTenants = ref<Tenant[]>([]);

    const isLoggedIn = computed(
      () => !!user.value?.user_id && !!accessToken.value,
    );
    const isAuthenticated = computed(() => isLoggedIn.value);
    const token = computed(() => accessToken.value);
    const displayName = computed(() => {
      if (!user.value) return "";
      return user.value.name || user.value.username || user.value.email;
    });
    const userRole = computed(() => user.value?.roles[0]?.name || "");
    const isSuperAdmin = computed(() => user.value?.is_super_admin || false);
    const isTenantAdmin = computed(
      () =>
        user.value?.roles.some((role) => role.name === "tenant-admin") ?? false,
    );
    const isBotBuilder = computed(
      () =>
        user.value?.roles.some((role) => role.name === "bot-builder") ?? false,
    );
    const isAgent = computed(
      () => user.value?.roles.some((role) => role.name === "agent") ?? false,
    );
    const isViewer = computed(
      () => user.value?.roles.some((role) => role.name === "viewer") ?? false,
    );
    const isAdmin = computed(() => isSuperAdmin.value || isTenantAdmin.value);
    const hasPermission = (permission: string) =>
      user.value?.permissions.some((p) => p.name === permission) ?? false;
    const primaryTenant = computed(() => user.value?.primary_tenant || null);
    const currentTenantInfo = computed(
      () => currentTenant.value || user.value?.current_tenant || null,
    );
    const tenantById = (id: number) =>
      availableTenants.value.find((t) => t.id === id) ||
      user.value?.tenants.find((t) => t.id === id) ||
      null;
    const isCurrentTenant = (tenantId: number) =>
      currentTenantInfo.value?.id === tenantId;

    // ── setUser ───────────────────────────────────────────────────────────────
    const setUser = (userData: User, token: string) => {
      user.value = userData;
      accessToken.value = token;
      currentTenant.value = userData.current_tenant || null;
      availableTenants.value = userData.tenants || [];
      isLoaded.value = true;
      error.value = null;

      axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;

      // ← Initialise Echo now that the token is available.
      //   initEcho() reads the token from the persisted "user-data" key, which
      //   Pinia writes synchronously before this line runs.
      initEcho();

      console.log("✅ User set:", {
        id: userData.user_id,
        roles: userData.roles.map((r) => r.name),
        currentTenant: userData.current_tenant?.name,
      });
    };

    const updateUserData = (userData: Partial<User>) => {
      if (user.value) {
        user.value = { ...user.value, ...userData };
        if (userData.current_tenant)
          currentTenant.value = userData.current_tenant;
        if (userData.tenants) availableTenants.value = userData.tenants;
      }
    };

    // ── clearUser ─────────────────────────────────────────────────────────────
    const clearUser = () => {
      console.log("🧹 Clearing user data");

      // ← Disconnect Echo before wiping the token so any in-flight
      //   subscriptions are cleanly removed.
      destroyEcho();

      user.value = null;
      accessToken.value = null;
      currentTenant.value = null;
      availableTenants.value = [];
      isLoaded.value = false;
      error.value = null;
      delete axios.defaults.headers.common["Authorization"];
    };

    // ── login ─────────────────────────────────────────────────────────────────
    const login = async (credentials: { email: string; password: string }) => {
      isLoading.value = true;
      error.value = null;

      try {
        await axios.get("/sanctum/csrf-cookie");
        const { data } = await axios.post("/api/auth/login", credentials);

        if (data.password_reset_required) {
          return {
            success: false,
            passwordResetRequired: true,
            userId: data.user_id,
            token: data.token,
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

        const { accessToken: token, userData } = data;
        setUser(userData, token); // initEcho() is called inside setUser
        return { success: true, data };
      } catch (err: any) {
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
        error.value = err.response?.data?.message || "Login failed";
        return { success: false, error: error.value };
      } finally {
        isLoading.value = false;
      }
    };

    const fetchUser = async () => {
      if (
        window.location.pathname === "/account-error" ||
        window.location.pathname === "/login"
      ) {
        return null;
      }

      if (isLoaded.value && user.value) return user.value;

      if (!accessToken.value) {
        clearUser();
        return null;
      }

      isLoading.value = true;
      try {
        const { data } = await axios.get("/api/auth/me");
        const userData = data.data || data.user;
        setUser(userData, accessToken.value); // initEcho() called here too (idempotent)
        return user.value;
      } catch (err: any) {
        if (err.response?.status === 401) await logout();
        error.value = err.response?.data?.message || "Failed to load user";
        throw err;
      } finally {
        isLoading.value = false;
      }
    };

    const fetchProfile = async () => {
      if (!accessToken.value) return null;
      try {
        const { data } = await axios.get("/api/auth/profile");
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

    const logout = async () => {
      try {
        await axios.post("/api/auth/logout");
      } catch (err) {
        console.warn("Logout request failed", err);
      } finally {
        clearUser(); // destroyEcho() is called inside clearUser
        router.push({ name: "login" });
      }
    };

    const switchTenant = async (tenantId: number) => {
      try {
        const { data } = await axios.post("/api/auth/switch-tenant", {
          tenant_id: tenantId,
        });
        if (data.success && data.tenant) {
          currentTenant.value = data.tenant;
          if (user.value) {
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
        if (data.redirect_to) {
          return {
            success: false,
            redirectTo: data.redirect_to,
            message: data.message,
          };
        }
        return { success: false, error: data.message };
      } catch (err: any) {
        return { success: false, error: err.response?.data?.message };
      }
    };

    const updateProfile = async (profileData: Partial<User>) => {
      try {
        const { data } = await axios.put("/api/auth/profile", profileData);
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
        const { data } = await axios.post(
          "/api/auth/change-password",
          passwordData,
        );
        return { success: true, message: data.message };
      } catch (err: any) {
        return {
          success: false,
          error: err.response?.data?.errors || err.response?.data?.message,
        };
      }
    };

    const forceResetPassword = async (resetData: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => {
      try {
        const { data } = await axios.post(
          "/api/auth/force-reset-password",
          resetData,
        );
        if (data.success) {
          if (user.value) user.value.password_reset_required = false;
          return { success: true, message: data.message };
        }
        return { success: false, error: data.message };
      } catch (err: any) {
        return { success: false, error: err.response?.data?.message };
      }
    };

    return {
      user,
      accessToken,
      isLoading,
      isLoaded,
      error,
      currentTenant,
      availableTenants,
      isLoggedIn,
      isAuthenticated,
      token,
      displayName,
      userRole,
      isSuperAdmin,
      isTenantAdmin,
      isBotBuilder,
      isAgent,
      isViewer,
      isAdmin,
      hasPermission,
      primaryTenant,
      currentTenantInfo,
      tenantById,
      isCurrentTenant,
      setUser,
      updateUserData,
      clearUser,
      login,
      fetchUser,
      fetchProfile,
      logout,
      switchTenant,
      updateProfile,
      changePassword,
      forceResetPassword,
    };
  },
  {
    persist: {
      key: "user-data",
      storage: localStorage,
      paths: [
        "user",
        "accessToken",
        "isLoaded",
        "currentTenant",
        "availableTenants",
      ],
    },
  },
);
