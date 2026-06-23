import "vuetify/styles";
import { createApp } from "vue";
import { createPinia } from "pinia";
import piniaPluginPersistedstate from "pinia-plugin-persistedstate";
import { PerfectScrollbarPlugin } from "vue3-perfect-scrollbar";
import "vue3-perfect-scrollbar/style.css";
import "./echo";
import { createI18n } from "vue-i18n";
import Vue3EasyDataTable from "vue3-easy-data-table";
import VueTablerIcons from "vue-tabler-icons";
import print from "vue3-print-nb";
import VueApexCharts from "vue3-apexcharts";
import vuetify from "./plugins/vuetify";
import VueSignaturePad from "vue-signature-pad";
import "@styles/styles.scss";
import App from "./App.vue";
import messages from "@/utils/i18n/locales/messages";
import { registerPlugins } from "@core/utils/plugins";
import router from "./router/router"; // ← was "./router" / virtual auto-routes
import "@fontsource/roboto/400.css";
import "@fontsource/roboto/500.css";
import "@fontsource/roboto/300.css";
import "@fontsource/roboto/700.css";
import "@fontsource/inter/400.css";
import "@fontsource/inter/500.css";
import "@fontsource/inter/600.css";
import "@fontsource/inter/700.css";
import "@fontsource/poppins/400.css";
import "@fontsource/poppins/500.css";
import "@fontsource/poppins/600.css";
import "@fontsource/poppins/700.css";
import "@fontsource/public-sans/400.css";
import "@fontsource/public-sans/500.css";
import "@fontsource/public-sans/600.css";
import "@fontsource/public-sans/700.css";

const pinia = createPinia();
pinia.use(piniaPluginPersistedstate);

const i18n = createI18n({
  locale: "en",
  messages,
  silentTranslationWarn: true,
  silentFallbackWarn: true,
});

const app = createApp(App);

app.use(pinia);
app.use(router);

registerPlugins(app);

app.use(PerfectScrollbarPlugin);
app.component("EasyDataTable", Vue3EasyDataTable);
app.use(VueTablerIcons);
app.use(print);
app.use(i18n);
app.use(VueSignaturePad);
app.use(VueApexCharts);
app.component("apexchart", VueApexCharts);

app.use(vuetify).mount("#app");
