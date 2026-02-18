// router/additional-routes.ts
import type { RouteRecordRaw } from "vue-router/auto";

export const redirects: RouteRecordRaw[] = [
  {
    path: "/",
    name: "index",
    redirect: "/login", // or { name: "login" }
  },
];

// Keep your other routes as-is
export const routes: RouteRecordRaw[] = [];
