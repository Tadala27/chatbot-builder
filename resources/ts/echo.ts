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

  // Determine the correct protocol based on the scheme
  const scheme = import.meta.env.VITE_REVERB_SCHEME || 'http';
  const host = import.meta.env.VITE_REVERB_HOST || '127.0.0.1';
  const port = import.meta.env.VITE_REVERB_PORT || 8081;
  
  // Force TLS to false for http, true for https
  const forceTLS = scheme === 'https';

  console.log('[Echo] Connecting with config:', {
    scheme,
    host,
    port,
    forceTLS,
    key: import.meta.env.VITE_REVERB_APP_KEY,
  });

  const echoInstance = new Echo({
    broadcaster: "reverb",
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: forceTLS,
    encrypted: forceTLS,
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
  echoInstance.connector.pusher.connection.bind("connecting", () => {
    console.log("[Echo] Connecting to WebSocket...");
  });
  echoInstance.connector.pusher.connection.bind("connected", () => {
    console.log("[Echo] Successfully connected to WebSocket");
  });
  echoInstance.connector.pusher.connection.bind("disconnected", () => {
    console.log("[Echo] Disconnected from WebSocket");
  });
  echoInstance.connector.pusher.connection.bind("error", (error: unknown) => {
    console.error("[Echo] WebSocket connection error:", error);
  });
  echoInstance.connector.pusher.connection.bind(
    "state_change",
    (states: unknown) => {
      console.log("[Echo] Connection state changed:", states);
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