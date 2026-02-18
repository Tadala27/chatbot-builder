// router/index.ts
import type { App } from "vue";
import { setupLayouts } from "virtual:generated-layouts";
import type { RouteRecordRaw } from "vue-router/auto";
import { createRouter, createWebHistory } from "vue-router/auto";
import { redirects, routes } from "./additional-routes";
import { setupGuards } from "./guards";

function recursiveLayouts(route: RouteRecordRaw): RouteRecordRaw {
  if (route.children) {
    route.children = route.children.map(recursiveLayouts);
  }
  return setupLayouts([route])[0];
}

const router = createRouter({
  history: createWebHistory("/"),
  scrollBehavior(to) {
    if (to.hash) {
      return { el: to.hash, behavior: "smooth", top: 60 };
    }
    return { top: 0 };
  },
  extendRoutes: (pages) => {
    const finalRoutes = [
      ...redirects,
      ...[...pages, ...routes].map((route) => recursiveLayouts(route)),
    ];
    return finalRoutes;
  },
});

// Setup guards
setupGuards(router);


export { router };

export default function (app: App) {
  app.use(router);
}
