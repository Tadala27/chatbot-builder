// stores/user.ts
import { defineStore } from "pinia";
import { ref, computed } from "vue";
import { useRouter } from "vue-router";
import axios from "axios";

/**
 * Tenant object structure
 */
export interface Tenant {
  id: number;
  name: string;
  slug: string;
  domain: string;
  is_primary?: boolean;
  is_current?: boolean;
  is_active?: boolean;
}

/**
 * Permission object structure
 */
export interface Permission {
  id: number;
  name: string;
}

/**
 * Role object structure
 */
export interface Role {
  id: number;
  name: string;
}

/**
 * Current tenant context
 */
export interface CurrentTenant extends Tenant {
  subscription_tier?: string;
  subscription_expires_at?: string;
  max_chatbots?: number;
  max_conversations_per_month?: number;
  settings?: Record<string, any>;
}

/**
 * User object as returned by the API (AuthController@login and @me)
 */
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
  roles: Role[]; // Array of role objects with id and name
  permissions: Permission[]; // Array of permission objects with id and name
  tenants: Tenant[]; // All tenants user has access to
  primary_tenant: Tenant | null;
  current_tenant: CurrentTenant | null;
}

/**
 * Login response structure
 */
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

export const useUserStore = defineStore(
  "user",
  () => {
    const router = useRouter();

    // State
    const user = ref<User | null>(null);
    const accessToken = ref<string | null>(null);
    const isLoading = ref(false);
    const isLoaded = ref(false);
    const error = ref<string | null>(null);
    const currentTenant = ref<CurrentTenant | null>(null);
    const availableTenants = ref<Tenant[]>([]);

    // Computed
    const isLoggedIn = computed(() => !!user.value?.user_id && !!accessToken.value);
    const isAuthenticated = computed(() => isLoggedIn.value);
    const token = computed(() => accessToken.value);

    const displayName = computed(() => {
      if (!user.value) return "";
      return user.value.name || user.value.username || user.value.email;
    });

    const userRole = computed(() => user.value?.roles[0]?.name || ""); // primary role

    // Role checks
    const isSuperAdmin = computed(() => user.value?.is_super_admin || false);
    const isTenantAdmin = computed(
      () => user.value?.roles.some(role => role.name === "tenant-admin") ?? false,
    );
    const isBotBuilder = computed(
      () => user.value?.roles.some(role => role.name === "bot-builder") ?? false,
    );
    const isAgent = computed(
      () => user.value?.roles.some(role => role.name === "agent") ?? false,
    );
    const isViewer = computed(
      () => user.value?.roles.some(role => role.name === "viewer") ?? false,
    );
    const isAdmin = computed(() => isSuperAdmin.value || isTenantAdmin.value);

    // Permission check
    const hasPermission = (permission: string) =>
      user.value?.permissions.some(p => p.name === permission) ?? false;

    // Tenant helpers
    const primaryTenant = computed(() => user.value?.primary_tenant || null);
    const currentTenantInfo = computed(() => currentTenant.value || user.value?.current_tenant || null);
    
    const tenantById = (id: number) => {
      return availableTenants.value.find(t => t.id === id) || 
             user.value?.tenants.find(t => t.id === id) || null;
    };

    const isCurrentTenant = (tenantId: number) => {
      return currentTenantInfo.value?.id === tenantId;
    };

    // Actions
    const setUser = (userData: User, token: string) => {
      user.value = userData;
      accessToken.value = token;
      currentTenant.value = userData.current_tenant || null;
      availableTenants.value = userData.tenants || [];
      isLoaded.value = true;
      error.value = null;

      // Set default axios header
      axios.defaults.headers.common["Authorization"] = `Bearer ${token}`;

      console.log("✅ User set:", {
        id: userData.user_id,
        roles: userData.roles.map(r => r.name),
        permissions: userData.permissions.length,
        currentTenant: userData.current_tenant?.name,
        tenantsCount: userData.tenants.length,
      });
    };

    const updateUserData = (userData: Partial<User>) => {
      if (user.value) {
        user.value = { ...user.value, ...userData };
        
        // Update current tenant if provided
        if (userData.current_tenant) {
          currentTenant.value = userData.current_tenant;
        }
        
        // Update tenants list if provided
        if (userData.tenants) {
          availableTenants.value = userData.tenants;
        }
      }
    };

    const clearUser = () => {
      console.log("🧹 Clearing user data");
      user.value = null;
      accessToken.value = null;
      currentTenant.value = null;
      availableTenants.value = [];
      isLoaded.value = false;
      error.value = null;

      // Remove axios auth header
      delete axios.defaults.headers.common["Authorization"];
    };

    const login = async (credentials: { email: string; password: string }) => {
      isLoading.value = true;
      error.value = null;
      try {
        const { data } = await axios.post("/api/auth/login", credentials);
        
        // Handle password reset required
        if (data.password_reset_required) {
          return { 
            success: false, 
            passwordResetRequired: true,
            userId: data.user_id,
            token: data.token,
            message: data.message 
          };
        }

        // Handle redirect (wrong domain)
        if (data.redirect_to) {
          return { 
            success: false, 
            redirectTo: data.redirect_to,
            message: data.message 
          };
        }

        // Expected response: { accessToken: string, userData: User, ... }
        const { accessToken: token, userData, current_tenant, tenants } = data;

        setUser(userData, token);

        return { success: true, data };
      } catch (err: any) {
        // Handle locked account
        if (err.response?.status === 423) {
          error.value = err.response?.data?.message || "Account is locked";
          return { 
            success: false, 
            locked: true,
            minutesLeft: err.response?.data?.minutes_left,
            lockedUntil: err.response?.data?.locked_until,
            error: error.value 
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
        console.log("⚠️ On error/login page, skipping user fetch");
        return null;
      }

      if (isLoaded.value && user.value) {
        console.log("✅ User already loaded, returning cached data");
        return user.value;
      }

      if (!accessToken.value) {
        clearUser();
        return null;
      }

      isLoading.value = true;
      try {
        const { data } = await axios.get("/api/auth/me");
        // Expected response: { success: true, data: User }
        const userData = data.data || data.user; // Handle both response formats
        
        setUser(userData, accessToken.value); // reuse existing token
        return user.value;
      } catch (err: any) {
        if (err.response?.status === 401) {
          await logout();
        }
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
        // Expected response: { success: true, data: { user: User, current_tenant, tenants } }
        if (data.success && data.data) {
          updateUserData(data.data.user);
          if (data.data.current_tenant) {
            currentTenant.value = data.data.current_tenant;
          }
          if (data.data.tenants) {
            availableTenants.value = data.data.tenants;
          }
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
        clearUser();
        router.push({ name: "login" });
      }
    };

    const switchTenant = async (tenantId: number) => {
      try {
        const { data } = await axios.post("/api/auth/switch-tenant", {
          tenant_id: tenantId,
        });
        
        if (data.success && data.tenant) {
          // Update current tenant
          currentTenant.value = data.tenant;
          
          // Update user's primary tenant
          if (user.value) {
            user.value.primary_tenant = data.tenant;
            
            // Mark this tenant as current in the tenants list
            user.value.tenants = user.value.tenants.map(t => ({
              ...t,
              is_current: t.id === data.tenant.id,
              is_primary: t.id === data.tenant.id ? true : t.is_primary
            }));
            
            availableTenants.value = user.value.tenants;
          }
          
          return { success: true, tenant: data.tenant };
        }
        
        // Handle redirect
        if (data.redirect_to) {
          return { 
            success: false, 
            redirectTo: data.redirect_to,
            message: data.message 
          };
        }
        
        return { success: false, error: data.message };
      } catch (err: any) {
        console.error("Failed to switch tenant", err);
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
        console.error("Failed to update profile", err);
        return { success: false, error: err.response?.data?.message };
      }
    };

    const changePassword = async (passwordData: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => {
      try {
        const { data } = await axios.post("/api/auth/change-password", passwordData);
        return { success: true, message: data.message };
      } catch (err: any) {
        console.error("Failed to change password", err);
        return { 
          success: false, 
          error: err.response?.data?.errors || err.response?.data?.message 
        };
      }
    };

    const forceResetPassword = async (resetData: {
      current_password: string;
      password: string;
      password_confirmation: string;
    }) => {
      try {
        const { data } = await axios.post("/api/auth/force-reset-password", resetData);
        if (data.success) {
          // Update password reset required flag
          if (user.value) {
            user.value.password_reset_required = false;
          }
          return { success: true, message: data.message };
        }
        return { success: false, error: data.message };
      } catch (err: any) {
        console.error("Failed to reset password", err);
        return { success: false, error: err.response?.data?.message };
      }
    };

    return {
      // State
      user,
      accessToken,
      isLoading,
      isLoaded,
      error,
      currentTenant,
      availableTenants,
      
      // Computed
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
      
      // Actions
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
      paths: ["user", "accessToken", "isLoaded", "currentTenant", "availableTenants"],
    },
  },
);