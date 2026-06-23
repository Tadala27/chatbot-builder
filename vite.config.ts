import { URL, fileURLToPath } from "node:url";
import { defineConfig } from "vite";
import Components from "unplugin-vue-components/vite";
import laravel from "laravel-vite-plugin";
import vue from "@vitejs/plugin-vue";
import vuetify from "vite-plugin-vuetify";
import AutoImport from "unplugin-auto-import/vite";

export default defineConfig({
  plugins: [
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
        "vue-router",
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
  // CORS + HMR for multi-tenant local domains (tnm.test, nbs.test, etc.)
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    cors: true,
    hmr: {
      host: "localhost",
    },
  },
});
