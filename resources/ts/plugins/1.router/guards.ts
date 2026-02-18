// router/guards.ts
import type { Router } from "vue-router";
import { canNavigate } from "@layouts/plugins/casl";
import { useUIStore } from "@/plugins/ui";
import { useUserStore } from "@/stores/user";
import { canAccessRoute } from "./routePermissions";

export const setupGuards = (router: Router) => {
  router.beforeEach(async (to, from) => {
    const uiStore = useUIStore();
    uiStore.isLoading = true;

    // CRITICAL: Allow account-error page WITHOUT any checks or user fetching
    if (to.name === "account-error") {
      console.log("✅ Allowing access to account-error page, no checks");
      return true;
    }

    // CRITICAL: Allow login and not-authorized pages without checks
    if (to.name === "login" || to.name === "not-authorized") {
      console.log(`✅ Allowing access to ${to.name} page`);
      return true;
    }

    // Public routes
    if (to.meta.public) {
      console.log("✅ Public route, allowing access");
      return true;
    }

    const userStore = useUserStore();

    // If already logged in and trying to access login → redirect
    if (to.meta.unauthenticatedOnly) {
      if (userStore.isLoaded && userStore.user) {
        console.log("🔀 Already logged in, redirecting to dashboard");
        return { name: "dashboard" };
      }
      return true;
    }

    // Not logged in → force login
    if (!userStore.isLoaded || !userStore.user) {
      // Try to load user if we have token but store not loaded yet
      if (userStore.accessToken && !userStore.isLoaded) {
        try {
          console.log("🔄 Attempting to fetch user...");
          await userStore.fetchUser();
          console.log("✅ User fetched successfully");
        } catch (error) {
          console.error("❌ Failed to fetch user:", error);
          userStore.clearUser();
          return {
            name: "login",
            query: { redirect: to.fullPath },
          };
        }
      }

      // Check again after attempting to fetch
      if (!userStore.isLoaded || !userStore.user) {
        console.log("🔓 No user, redirecting to login");
        return {
          name: "login",
          query: { redirect: to.fullPath },
        };
      }
    }

    // Your custom route permission check
    if (!canAccessRoute(to)) {
      console.log("🚫 Custom permissions: Cannot access route");
      return { name: "not-authorized" };
    }

    return true;
  });

  router.afterEach(() => {
    const uiStore = useUIStore();
    uiStore.isLoading = false;
  });
};
