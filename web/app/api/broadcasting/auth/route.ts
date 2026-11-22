import type { NextRequest } from "next/server";
import { apiFetch, problemResponse, relayResponse } from "@/lib/api-client";

/**
 * Pusher/Reverb private-channel authorization. pusher-js posts
 * `socket_id` + `channel_name` (form-encoded); we forward them to Laravel's
 * /broadcasting/auth with the tenant API key and relay the signed response.
 */
export async function POST(request: NextRequest): Promise<Response> {
  let socketId: string | null = null;
  let channelName: string | null = null;

  const contentType = request.headers.get("content-type") ?? "";
  try {
    if (contentType.includes("application/json")) {
      const json = (await request.json()) as Record<string, unknown>;
      socketId = typeof json.socket_id === "string" ? json.socket_id : null;
      channelName = typeof json.channel_name === "string" ? json.channel_name : null;
    } else {
      const form = await request.formData();
      socketId = form.get("socket_id")?.toString() ?? null;
      channelName = form.get("channel_name")?.toString() ?? null;
    }
  } catch {
    return problemResponse(400, "Invalid request body");
  }

  if (!socketId || !/^\d+\.\d+$/.test(socketId) || !channelName || !channelName.startsWith("private-")) {
    return problemResponse(422, "Invalid channel authorization request");
  }

  const body = new URLSearchParams({ socket_id: socketId, channel_name: channelName });
  const upstream = await apiFetch("/broadcasting/auth", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded", Accept: "application/json" },
    body: body.toString(),
    signal: request.signal,
  });
  return relayResponse(upstream);
}
