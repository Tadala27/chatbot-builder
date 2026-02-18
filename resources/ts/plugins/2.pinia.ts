import { createPinia } from "pinia";
import piniaPersistedstate from "pinia-plugin-persistedstate";
import type { App } from "vue";

export const store = createPinia();
store.use(piniaPersistedstate);

export default function (app: App) {
  app.use(store);
}
