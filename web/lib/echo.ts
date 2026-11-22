"use client";

import Echo from "laravel-echo";
import Pusher from "pusher-js";

export type EchoClient = Echo<"reverb">;

export interface ReverbConfig {
  key: string;
  host: string;
  port: number;
  scheme: "http" | "https";
}

/** Reads the public Reverb settings; null when realtime is not configured. */
export function getReverbConfig(): ReverbConfig | null {
  const key = process.env.NEXT_PUBLIC_REVERB_KEY?.trim();
  const host = process.env.NEXT_PUBLIC_REVERB_HOST?.trim();
  if (!key || !host) return null;
  const port = Number(process.env.NEXT_PUBLIC_REVERB_PORT || 443);
  const scheme = process.env.NEXT_PUBLIC_REVERB_SCHEME === "http" ? "http" : "https";
  return { key, host, port: Number.isFinite(port) && port > 0 ? port : 443, scheme };
}

let instance: EchoClient | null = null;
let failed = false;

/**
 * Lazily creates the shared Echo instance (browser only). Returns null when
 * Reverb isn't configured or the client failed to initialise; callers then
 * fall back to polling.
 */
export function getEcho(): EchoClient | null {
  if (typeof window === "undefined" || failed) return null;
  if (instance) return instance;
  const config = getReverbConfig();
  if (!config) return null;
  try {
    instance = new Echo({
      broadcaster: "reverb",
      key: config.key,
      wsHost: config.host,
      wsPort: config.port,
      wssPort: config.port,
      forceTLS: config.scheme === "https",
      enabledTransports: ["ws", "wss"],
      authEndpoint: "/api/broadcasting/auth",
      Pusher,
    });
    return instance;
  } catch {
    failed = true;
    return null;
  }
}

/** Echo expects private channel names without the "private-" prefix. */
export function toEchoChannelName(channel: string): string {
  return channel.replace(/^private-/, "");
}

export type SocketState = "connecting" | "connected" | "unavailable";

/** Subscribe to socket connection state; returns an unsubscribe function. */
export function onSocketState(echo: EchoClient, cb: (state: SocketState) => void): () => void {
  const connection = echo.connector.pusher.connection;
  const map = (state: string): SocketState =>
    state === "connected" ? "connected" : state === "connecting" || state === "initialized" ? "connecting" : "unavailable";
  const handler = ({ current }: { current: string }) => cb(map(current));
  connection.bind("state_change", handler);
  cb(map(connection.state));
  return () => connection.unbind("state_change", handler);
}
