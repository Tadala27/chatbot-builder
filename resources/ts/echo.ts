/**
 * resources/ts/echo.ts
 */
import Echo from "laravel-echo";
import Pusher from "pusher-js";
import axios from "axios";

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo?: InstanceType<typeof Echo>;
  }
}

Pusher.logToConsole = true;
window.Pusher = Pusher;

export function initEcho(): void {
  if (window.Echo) return;

  const echoInstance = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: false,
    encrypted: false,
    disableStats: true,
    enabledTransports: ["ws", "wss"],
    authorizer: (channel: { name: string }) => ({
      authorize(
        socketId: string,
        callback: (error: Error | null, data?: any) => void,
      ) {
        axios
          .post(
            "/tenant/broadcasting/auth",
            { socket_id: socketId, channel_name: channel.name },
            { withCredentials: true },
          )
          .then((response) => callback(null, response.data))
          .catch((error) =>
            callback(error instanceof Error ? error : new Error(String(error))),
          );
      },
    }),
  });

  window.Echo = echoInstance;

  console.log("[Echo] Initialised.");

  // Connection event listeners — useful for debugging Reverb connectivity
  // during development; safe to trim once things are stable.
  echoInstance.connector.pusher.connection.bind("connecting", () => {
    console.log("Connecting to WebSocket...");
  });
  echoInstance.connector.pusher.connection.bind("connected", () => {
    console.log("Successfully connected to WebSocket");
  });
  echoInstance.connector.pusher.connection.bind("disconnected", () => {
    console.log("Disconnected from WebSocket");
  });
  echoInstance.connector.pusher.connection.bind("error", (error: unknown) => {
    console.error("WebSocket connection error:", error);
  });
  echoInstance.connector.pusher.connection.bind(
    "state_change",
    (states: unknown) => {
      console.log("Connection state changed:", states);
    },
  );
}

export function destroyEcho(): void {
  if (window.Echo) {
    window.Echo.disconnect();
    // @ts-ignore
    window.Echo = null;
    console.log("[Echo] Disconnected.");
  }
}
