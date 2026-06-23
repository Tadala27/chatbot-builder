/**
 * resources/ts/echo.ts
 *
 * Session/cookie-based auth (Laravel Sanctum SPA mode) — there is no bearer
 * token to read from localStorage. Echo authenticates the private/presence
 * channel handshake using the session cookie itself, the same way axios
 * requests are already authenticated.
 *
 * Public channels while debugging — swap channel() → private() later.
 */
import Echo from "laravel-echo";
import Pusher from "pusher-js";

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: InstanceType<typeof Echo>;
  }
}

window.Pusher = Pusher;

export function initEcho(): void {
  if (window.Echo) return;

  window.Echo = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: false,
    encrypted: false,
    disableStats: true,
    enabledTransports: ["ws", "wss"],

    authEndpoint: "/api/broadcasting/auth",
    auth: {
      headers: {
        Authorization: `Bearer ${localStorage.getItem("token")}`,
        Accept: "application/json",
      },
    },
  });

  console.info("[Echo] Initialised.");
}

export function destroyEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect();
    // @ts-ignore
    window.Echo = null;
    console.info("[Echo] Disconnected.");
  }
}
