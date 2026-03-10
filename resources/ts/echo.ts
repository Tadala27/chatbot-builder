/**
 * resources/ts/echo.ts
 * Public channels while debugging — swap channel() → private() later.
 */
import Echo from "laravel-echo";
import Pusher from "pusher-js";

window.Pusher = Pusher;

function getBearerToken(): string {
  try {
    const raw = localStorage.getItem("user-data");
    if (!raw) return "";
    const parsed = JSON.parse(raw) as { accessToken?: string };
    return parsed.accessToken ?? "";
  } catch {
    return "";
  }
}

export function initEcho(): void {
  const token = getBearerToken();
  if (!token) {
    console.warn("[Echo] No accessToken found — Echo not initialised.");
    return;
  }
  if (window.Echo) return;

  window.Echo = new Echo({
    broadcaster: "pusher",
    key: import.meta.env.VITE_PUSHER_APP_KEY as string,
    cluster: (import.meta.env.VITE_PUSHER_APP_CLUSTER as string) ?? "ap2",
    forceTLS: true,
    authEndpoint: "/api/broadcasting/auth",
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
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

if (getBearerToken()) {
  initEcho();
}
