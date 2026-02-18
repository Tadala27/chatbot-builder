import { URL, fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import AutoImport from "unplugin-auto-import/vite";
import Components from "unplugin-vue-components/vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import vuetify from "vite-plugin-vuetify";
import {
  VueRouterAutoImports,
  getPascalCaseRouteName,
} from "unplugin-vue-router";
import VueRouter from "unplugin-vue-router/vite";
import Layouts from "vite-plugin-vue-layouts";

export default defineConfig({
  // base: "/build/",
  plugins: [
    VueRouter({
      getRouteName: (routeNode) => {
        return getPascalCaseRouteName(routeNode)
          .replace(/([a-z\d])([A-Z])/g, "$1-$2")
          .toLowerCase();
      },
      routesFolder: "resources/ts/pages",
    }),
    vue({
      template: {
        compilerOptions: {
          isCustomElement: (tag) => ["v-list-recognize-title"].includes(tag),
        },
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    laravel(["resources/ts/main.ts"]),
    vuetify(),
    Layouts({
      layoutsDirs: "./resources/ts/layouts/",
    }),
    Components({
      dirs: [
        "resources/ts/@core/components",
        "resources/ts/views/demos",
        "resources/ts/components",
      ],
      dts: true,
      resolvers: [
        (componentName) => {
          if (componentName === "VueApexCharts")
            return {
              name: "default",
              from: "vue3-apexcharts",
              as: "VueApexCharts",
            };
        },
      ],
    }),
    AutoImport({
      imports: [
        "vue",
        VueRouterAutoImports,
        "@vueuse/core",
        "@vueuse/math",
        "vue-i18n",
        "pinia",
      ],
      dirs: [
        "./resources/ts/@core/utils",
        "./resources/ts/@core/composable/",
        "./resources/ts/composables/",
        "./resources/ts/utils/",
        "./resources/ts/plugins/*/composables/*",
      ],
      vueTemplate: true,
      ignore: ["useCookie"],
    }),
  ],

  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources/ts", import.meta.url)),
      "@layouts": fileURLToPath(
        new URL("./resources/ts/@layouts", import.meta.url),
      ),
      "@images": fileURLToPath(new URL("./resources/images/", import.meta.url)),
      "@styles": fileURLToPath(new URL("./resources/styles/", import.meta.url)),
      "@types": fileURLToPath(new URL("./resources/ts/types", import.meta.url)),
      "@config": fileURLToPath(new URL("./config.ts", import.meta.url)),
      "@core": fileURLToPath(new URL("./resources/ts/@core", import.meta.url)),
    },
  },

  optimizeDeps: {
    exclude: ["vuetify"],
    entries: ["./resources/ts/**/*.vue"],
  },

  // THIS IS THE FIX — CORS + proper HMR for multi-tenant domains
  server: {
    host: "0.0.0.0", // Allows access from tnm.test, nbs.test, etc.
    port: 5173,
    strictPort: true,
    cors: true, // Allows all origins — perfect for *.test:8000 dev
    hmr: {
      host: "localhost", // Vite connects back via localhost (safe)
    },
  },
});
