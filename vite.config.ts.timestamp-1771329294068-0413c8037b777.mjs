// vite.config.ts
import { URL, fileURLToPath } from "node:url";
import { defineConfig } from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/vite/dist/node/index.js";
import AutoImport from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/unplugin-auto-import/dist/vite.js";
import Components from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/unplugin-vue-components/dist/vite.js";
import laravel from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/laravel-vite-plugin/dist/index.js";
import vue from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/@vitejs/plugin-vue/dist/index.mjs";
import vuetify from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/vite-plugin-vuetify/dist/index.mjs";
import {
  VueRouterAutoImports,
  getPascalCaseRouteName
} from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/unplugin-vue-router/dist/index.mjs";
import VueRouter from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/unplugin-vue-router/dist/vite.mjs";
import Layouts from "file:///C:/xampp/htdocs/docs/chatbot/node_modules/vite-plugin-vue-layouts/dist/index.mjs";
var __vite_injected_original_import_meta_url = "file:///C:/xampp/htdocs/docs/chatbot/vite.config.ts";
var vite_config_default = defineConfig({
  // base: "/build/",
  plugins: [
    VueRouter({
      getRouteName: (routeNode) => {
        return getPascalCaseRouteName(routeNode).replace(/([a-z\d])([A-Z])/g, "$1-$2").toLowerCase();
      },
      routesFolder: "resources/ts/pages"
    }),
    vue({
      template: {
        compilerOptions: {
          isCustomElement: (tag) => ["v-list-recognize-title"].includes(tag)
        },
        transformAssetUrls: {
          base: null,
          includeAbsolute: false
        }
      }
    }),
    laravel(["resources/ts/main.ts"]),
    vuetify(),
    Layouts({
      layoutsDirs: "./resources/ts/layouts/"
    }),
    Components({
      dirs: [
        "resources/ts/@core/components",
        "resources/ts/views/demos",
        "resources/ts/components"
      ],
      dts: true,
      resolvers: [
        (componentName) => {
          if (componentName === "VueApexCharts")
            return {
              name: "default",
              from: "vue3-apexcharts",
              as: "VueApexCharts"
            };
        }
      ]
    }),
    AutoImport({
      imports: [
        "vue",
        VueRouterAutoImports,
        "@vueuse/core",
        "@vueuse/math",
        "vue-i18n",
        "pinia"
      ],
      dirs: [
        "./resources/ts/@core/utils",
        "./resources/ts/@core/composable/",
        "./resources/ts/composables/",
        "./resources/ts/utils/",
        "./resources/ts/plugins/*/composables/*"
      ],
      vueTemplate: true,
      ignore: ["useCookie"]
    })
  ],
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./resources/ts", __vite_injected_original_import_meta_url)),
      "@layouts": fileURLToPath(
        new URL("./resources/ts/@layouts", __vite_injected_original_import_meta_url)
      ),
      "@images": fileURLToPath(new URL("./resources/images/", __vite_injected_original_import_meta_url)),
      "@styles": fileURLToPath(new URL("./resources/styles/", __vite_injected_original_import_meta_url)),
      "@types": fileURLToPath(new URL("./resources/ts/types", __vite_injected_original_import_meta_url)),
      "@config": fileURLToPath(new URL("./config.ts", __vite_injected_original_import_meta_url)),
      "@core": fileURLToPath(new URL("./resources/ts/@core", __vite_injected_original_import_meta_url))
    }
  },
  optimizeDeps: {
    exclude: ["vuetify"],
    entries: ["./resources/ts/**/*.vue"]
  },
  // THIS IS THE FIX — CORS + proper HMR for multi-tenant domains
  server: {
    host: "0.0.0.0",
    // Allows access from tnm.test, nbs.test, etc.
    port: 5173,
    strictPort: true,
    cors: true,
    // Allows all origins — perfect for *.test:8000 dev
    hmr: {
      host: "localhost"
      // Vite connects back via localhost (safe)
    }
  }
});
export {
  vite_config_default as default
};
//# sourceMappingURL=data:application/json;base64,ewogICJ2ZXJzaW9uIjogMywKICAic291cmNlcyI6IFsidml0ZS5jb25maWcudHMiXSwKICAic291cmNlc0NvbnRlbnQiOiBbImNvbnN0IF9fdml0ZV9pbmplY3RlZF9vcmlnaW5hbF9kaXJuYW1lID0gXCJDOlxcXFx4YW1wcFxcXFxodGRvY3NcXFxcZG9jc1xcXFxjaGF0Ym90XCI7Y29uc3QgX192aXRlX2luamVjdGVkX29yaWdpbmFsX2ZpbGVuYW1lID0gXCJDOlxcXFx4YW1wcFxcXFxodGRvY3NcXFxcZG9jc1xcXFxjaGF0Ym90XFxcXHZpdGUuY29uZmlnLnRzXCI7Y29uc3QgX192aXRlX2luamVjdGVkX29yaWdpbmFsX2ltcG9ydF9tZXRhX3VybCA9IFwiZmlsZTovLy9DOi94YW1wcC9odGRvY3MvZG9jcy9jaGF0Ym90L3ZpdGUuY29uZmlnLnRzXCI7aW1wb3J0IHsgVVJMLCBmaWxlVVJMVG9QYXRoIH0gZnJvbSBcIm5vZGU6dXJsXCI7XG5pbXBvcnQgeyBkZWZpbmVDb25maWcgfSBmcm9tIFwidml0ZVwiO1xuaW1wb3J0IEF1dG9JbXBvcnQgZnJvbSBcInVucGx1Z2luLWF1dG8taW1wb3J0L3ZpdGVcIjtcbmltcG9ydCBDb21wb25lbnRzIGZyb20gXCJ1bnBsdWdpbi12dWUtY29tcG9uZW50cy92aXRlXCI7XG5pbXBvcnQgbGFyYXZlbCBmcm9tIFwibGFyYXZlbC12aXRlLXBsdWdpblwiO1xuaW1wb3J0IHZ1ZSBmcm9tIFwiQHZpdGVqcy9wbHVnaW4tdnVlXCI7XG5pbXBvcnQgdnVldGlmeSBmcm9tIFwidml0ZS1wbHVnaW4tdnVldGlmeVwiO1xuaW1wb3J0IHtcbiAgVnVlUm91dGVyQXV0b0ltcG9ydHMsXG4gIGdldFBhc2NhbENhc2VSb3V0ZU5hbWUsXG59IGZyb20gXCJ1bnBsdWdpbi12dWUtcm91dGVyXCI7XG5pbXBvcnQgVnVlUm91dGVyIGZyb20gXCJ1bnBsdWdpbi12dWUtcm91dGVyL3ZpdGVcIjtcbmltcG9ydCBMYXlvdXRzIGZyb20gXCJ2aXRlLXBsdWdpbi12dWUtbGF5b3V0c1wiO1xuXG5leHBvcnQgZGVmYXVsdCBkZWZpbmVDb25maWcoe1xuICAvLyBiYXNlOiBcIi9idWlsZC9cIixcbiAgcGx1Z2luczogW1xuICAgIFZ1ZVJvdXRlcih7XG4gICAgICBnZXRSb3V0ZU5hbWU6IChyb3V0ZU5vZGUpID0+IHtcbiAgICAgICAgcmV0dXJuIGdldFBhc2NhbENhc2VSb3V0ZU5hbWUocm91dGVOb2RlKVxuICAgICAgICAgIC5yZXBsYWNlKC8oW2EtelxcZF0pKFtBLVpdKS9nLCBcIiQxLSQyXCIpXG4gICAgICAgICAgLnRvTG93ZXJDYXNlKCk7XG4gICAgICB9LFxuICAgICAgcm91dGVzRm9sZGVyOiBcInJlc291cmNlcy90cy9wYWdlc1wiLFxuICAgIH0pLFxuICAgIHZ1ZSh7XG4gICAgICB0ZW1wbGF0ZToge1xuICAgICAgICBjb21waWxlck9wdGlvbnM6IHtcbiAgICAgICAgICBpc0N1c3RvbUVsZW1lbnQ6ICh0YWcpID0+IFtcInYtbGlzdC1yZWNvZ25pemUtdGl0bGVcIl0uaW5jbHVkZXModGFnKSxcbiAgICAgICAgfSxcbiAgICAgICAgdHJhbnNmb3JtQXNzZXRVcmxzOiB7XG4gICAgICAgICAgYmFzZTogbnVsbCxcbiAgICAgICAgICBpbmNsdWRlQWJzb2x1dGU6IGZhbHNlLFxuICAgICAgICB9LFxuICAgICAgfSxcbiAgICB9KSxcbiAgICBsYXJhdmVsKFtcInJlc291cmNlcy90cy9tYWluLnRzXCJdKSxcbiAgICB2dWV0aWZ5KCksXG4gICAgTGF5b3V0cyh7XG4gICAgICBsYXlvdXRzRGlyczogXCIuL3Jlc291cmNlcy90cy9sYXlvdXRzL1wiLFxuICAgIH0pLFxuICAgIENvbXBvbmVudHMoe1xuICAgICAgZGlyczogW1xuICAgICAgICBcInJlc291cmNlcy90cy9AY29yZS9jb21wb25lbnRzXCIsXG4gICAgICAgIFwicmVzb3VyY2VzL3RzL3ZpZXdzL2RlbW9zXCIsXG4gICAgICAgIFwicmVzb3VyY2VzL3RzL2NvbXBvbmVudHNcIixcbiAgICAgIF0sXG4gICAgICBkdHM6IHRydWUsXG4gICAgICByZXNvbHZlcnM6IFtcbiAgICAgICAgKGNvbXBvbmVudE5hbWUpID0+IHtcbiAgICAgICAgICBpZiAoY29tcG9uZW50TmFtZSA9PT0gXCJWdWVBcGV4Q2hhcnRzXCIpXG4gICAgICAgICAgICByZXR1cm4ge1xuICAgICAgICAgICAgICBuYW1lOiBcImRlZmF1bHRcIixcbiAgICAgICAgICAgICAgZnJvbTogXCJ2dWUzLWFwZXhjaGFydHNcIixcbiAgICAgICAgICAgICAgYXM6IFwiVnVlQXBleENoYXJ0c1wiLFxuICAgICAgICAgICAgfTtcbiAgICAgICAgfSxcbiAgICAgIF0sXG4gICAgfSksXG4gICAgQXV0b0ltcG9ydCh7XG4gICAgICBpbXBvcnRzOiBbXG4gICAgICAgIFwidnVlXCIsXG4gICAgICAgIFZ1ZVJvdXRlckF1dG9JbXBvcnRzLFxuICAgICAgICBcIkB2dWV1c2UvY29yZVwiLFxuICAgICAgICBcIkB2dWV1c2UvbWF0aFwiLFxuICAgICAgICBcInZ1ZS1pMThuXCIsXG4gICAgICAgIFwicGluaWFcIixcbiAgICAgIF0sXG4gICAgICBkaXJzOiBbXG4gICAgICAgIFwiLi9yZXNvdXJjZXMvdHMvQGNvcmUvdXRpbHNcIixcbiAgICAgICAgXCIuL3Jlc291cmNlcy90cy9AY29yZS9jb21wb3NhYmxlL1wiLFxuICAgICAgICBcIi4vcmVzb3VyY2VzL3RzL2NvbXBvc2FibGVzL1wiLFxuICAgICAgICBcIi4vcmVzb3VyY2VzL3RzL3V0aWxzL1wiLFxuICAgICAgICBcIi4vcmVzb3VyY2VzL3RzL3BsdWdpbnMvKi9jb21wb3NhYmxlcy8qXCIsXG4gICAgICBdLFxuICAgICAgdnVlVGVtcGxhdGU6IHRydWUsXG4gICAgICBpZ25vcmU6IFtcInVzZUNvb2tpZVwiXSxcbiAgICB9KSxcbiAgXSxcblxuICByZXNvbHZlOiB7XG4gICAgYWxpYXM6IHtcbiAgICAgIFwiQFwiOiBmaWxlVVJMVG9QYXRoKG5ldyBVUkwoXCIuL3Jlc291cmNlcy90c1wiLCBpbXBvcnQubWV0YS51cmwpKSxcbiAgICAgIFwiQGxheW91dHNcIjogZmlsZVVSTFRvUGF0aChcbiAgICAgICAgbmV3IFVSTChcIi4vcmVzb3VyY2VzL3RzL0BsYXlvdXRzXCIsIGltcG9ydC5tZXRhLnVybCksXG4gICAgICApLFxuICAgICAgXCJAaW1hZ2VzXCI6IGZpbGVVUkxUb1BhdGgobmV3IFVSTChcIi4vcmVzb3VyY2VzL2ltYWdlcy9cIiwgaW1wb3J0Lm1ldGEudXJsKSksXG4gICAgICBcIkBzdHlsZXNcIjogZmlsZVVSTFRvUGF0aChuZXcgVVJMKFwiLi9yZXNvdXJjZXMvc3R5bGVzL1wiLCBpbXBvcnQubWV0YS51cmwpKSxcbiAgICAgIFwiQHR5cGVzXCI6IGZpbGVVUkxUb1BhdGgobmV3IFVSTChcIi4vcmVzb3VyY2VzL3RzL3R5cGVzXCIsIGltcG9ydC5tZXRhLnVybCkpLFxuICAgICAgXCJAY29uZmlnXCI6IGZpbGVVUkxUb1BhdGgobmV3IFVSTChcIi4vY29uZmlnLnRzXCIsIGltcG9ydC5tZXRhLnVybCkpLFxuICAgICAgXCJAY29yZVwiOiBmaWxlVVJMVG9QYXRoKG5ldyBVUkwoXCIuL3Jlc291cmNlcy90cy9AY29yZVwiLCBpbXBvcnQubWV0YS51cmwpKSxcbiAgICB9LFxuICB9LFxuXG4gIG9wdGltaXplRGVwczoge1xuICAgIGV4Y2x1ZGU6IFtcInZ1ZXRpZnlcIl0sXG4gICAgZW50cmllczogW1wiLi9yZXNvdXJjZXMvdHMvKiovKi52dWVcIl0sXG4gIH0sXG5cbiAgLy8gVEhJUyBJUyBUSEUgRklYIFx1MjAxNCBDT1JTICsgcHJvcGVyIEhNUiBmb3IgbXVsdGktdGVuYW50IGRvbWFpbnNcbiAgc2VydmVyOiB7XG4gICAgaG9zdDogXCIwLjAuMC4wXCIsIC8vIEFsbG93cyBhY2Nlc3MgZnJvbSB0bm0udGVzdCwgbmJzLnRlc3QsIGV0Yy5cbiAgICBwb3J0OiA1MTczLFxuICAgIHN0cmljdFBvcnQ6IHRydWUsXG4gICAgY29yczogdHJ1ZSwgLy8gQWxsb3dzIGFsbCBvcmlnaW5zIFx1MjAxNCBwZXJmZWN0IGZvciAqLnRlc3Q6ODAwMCBkZXZcbiAgICBobXI6IHtcbiAgICAgIGhvc3Q6IFwibG9jYWxob3N0XCIsIC8vIFZpdGUgY29ubmVjdHMgYmFjayB2aWEgbG9jYWxob3N0IChzYWZlKVxuICAgIH0sXG4gIH0sXG59KTtcbiJdLAogICJtYXBwaW5ncyI6ICI7QUFBZ1IsU0FBUyxLQUFLLHFCQUFxQjtBQUNuVCxTQUFTLG9CQUFvQjtBQUM3QixPQUFPLGdCQUFnQjtBQUN2QixPQUFPLGdCQUFnQjtBQUN2QixPQUFPLGFBQWE7QUFDcEIsT0FBTyxTQUFTO0FBQ2hCLE9BQU8sYUFBYTtBQUNwQjtBQUFBLEVBQ0U7QUFBQSxFQUNBO0FBQUEsT0FDSztBQUNQLE9BQU8sZUFBZTtBQUN0QixPQUFPLGFBQWE7QUFacUosSUFBTSwyQ0FBMkM7QUFjMU4sSUFBTyxzQkFBUSxhQUFhO0FBQUE7QUFBQSxFQUUxQixTQUFTO0FBQUEsSUFDUCxVQUFVO0FBQUEsTUFDUixjQUFjLENBQUMsY0FBYztBQUMzQixlQUFPLHVCQUF1QixTQUFTLEVBQ3BDLFFBQVEscUJBQXFCLE9BQU8sRUFDcEMsWUFBWTtBQUFBLE1BQ2pCO0FBQUEsTUFDQSxjQUFjO0FBQUEsSUFDaEIsQ0FBQztBQUFBLElBQ0QsSUFBSTtBQUFBLE1BQ0YsVUFBVTtBQUFBLFFBQ1IsaUJBQWlCO0FBQUEsVUFDZixpQkFBaUIsQ0FBQyxRQUFRLENBQUMsd0JBQXdCLEVBQUUsU0FBUyxHQUFHO0FBQUEsUUFDbkU7QUFBQSxRQUNBLG9CQUFvQjtBQUFBLFVBQ2xCLE1BQU07QUFBQSxVQUNOLGlCQUFpQjtBQUFBLFFBQ25CO0FBQUEsTUFDRjtBQUFBLElBQ0YsQ0FBQztBQUFBLElBQ0QsUUFBUSxDQUFDLHNCQUFzQixDQUFDO0FBQUEsSUFDaEMsUUFBUTtBQUFBLElBQ1IsUUFBUTtBQUFBLE1BQ04sYUFBYTtBQUFBLElBQ2YsQ0FBQztBQUFBLElBQ0QsV0FBVztBQUFBLE1BQ1QsTUFBTTtBQUFBLFFBQ0o7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLE1BQ0Y7QUFBQSxNQUNBLEtBQUs7QUFBQSxNQUNMLFdBQVc7QUFBQSxRQUNULENBQUMsa0JBQWtCO0FBQ2pCLGNBQUksa0JBQWtCO0FBQ3BCLG1CQUFPO0FBQUEsY0FDTCxNQUFNO0FBQUEsY0FDTixNQUFNO0FBQUEsY0FDTixJQUFJO0FBQUEsWUFDTjtBQUFBLFFBQ0o7QUFBQSxNQUNGO0FBQUEsSUFDRixDQUFDO0FBQUEsSUFDRCxXQUFXO0FBQUEsTUFDVCxTQUFTO0FBQUEsUUFDUDtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsTUFDRjtBQUFBLE1BQ0EsTUFBTTtBQUFBLFFBQ0o7QUFBQSxRQUNBO0FBQUEsUUFDQTtBQUFBLFFBQ0E7QUFBQSxRQUNBO0FBQUEsTUFDRjtBQUFBLE1BQ0EsYUFBYTtBQUFBLE1BQ2IsUUFBUSxDQUFDLFdBQVc7QUFBQSxJQUN0QixDQUFDO0FBQUEsRUFDSDtBQUFBLEVBRUEsU0FBUztBQUFBLElBQ1AsT0FBTztBQUFBLE1BQ0wsS0FBSyxjQUFjLElBQUksSUFBSSxrQkFBa0Isd0NBQWUsQ0FBQztBQUFBLE1BQzdELFlBQVk7QUFBQSxRQUNWLElBQUksSUFBSSwyQkFBMkIsd0NBQWU7QUFBQSxNQUNwRDtBQUFBLE1BQ0EsV0FBVyxjQUFjLElBQUksSUFBSSx1QkFBdUIsd0NBQWUsQ0FBQztBQUFBLE1BQ3hFLFdBQVcsY0FBYyxJQUFJLElBQUksdUJBQXVCLHdDQUFlLENBQUM7QUFBQSxNQUN4RSxVQUFVLGNBQWMsSUFBSSxJQUFJLHdCQUF3Qix3Q0FBZSxDQUFDO0FBQUEsTUFDeEUsV0FBVyxjQUFjLElBQUksSUFBSSxlQUFlLHdDQUFlLENBQUM7QUFBQSxNQUNoRSxTQUFTLGNBQWMsSUFBSSxJQUFJLHdCQUF3Qix3Q0FBZSxDQUFDO0FBQUEsSUFDekU7QUFBQSxFQUNGO0FBQUEsRUFFQSxjQUFjO0FBQUEsSUFDWixTQUFTLENBQUMsU0FBUztBQUFBLElBQ25CLFNBQVMsQ0FBQyx5QkFBeUI7QUFBQSxFQUNyQztBQUFBO0FBQUEsRUFHQSxRQUFRO0FBQUEsSUFDTixNQUFNO0FBQUE7QUFBQSxJQUNOLE1BQU07QUFBQSxJQUNOLFlBQVk7QUFBQSxJQUNaLE1BQU07QUFBQTtBQUFBLElBQ04sS0FBSztBQUFBLE1BQ0gsTUFBTTtBQUFBO0FBQUEsSUFDUjtBQUFBLEVBQ0Y7QUFDRixDQUFDOyIsCiAgIm5hbWVzIjogW10KfQo=
