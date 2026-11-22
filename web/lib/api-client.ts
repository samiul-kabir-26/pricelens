import "server-only";

/**
 * Server-side client for the Laravel API. This is the only module that reads
 * PRICELENS_API_KEY; it must never be imported from client components.
 */

import type { ProblemDetails } from "./types";

export interface ApiConfig {
  baseUrl: string;
  apiKey: string;
}

export function getApiConfig(): ApiConfig | null {
  const baseUrl = process.env.PRICELENS_API_URL?.trim();
  const apiKey = process.env.PRICELENS_API_KEY?.trim();
  if (!baseUrl || !apiKey) return null;
  return { baseUrl: baseUrl.replace(/\/+$/, ""), apiKey };
}

/** Top-level API resources the browser may reach through the generic proxy. */
export const PROXY_ALLOWED_ROOTS = new Set([
  "searches",
  "sources",
  "tracked-products",
  "monitors",
  "alerts",
  "webhooks",
  "usage",
]);

const SAFE_SEGMENT = /^[A-Za-z0-9._~-]+$/;

/** Validates path segments for the proxy; rejects traversal and unknown roots. */
export function isAllowedProxyPath(segments: readonly string[]): boolean {
  if (segments.length === 0 || !PROXY_ALLOWED_ROOTS.has(segments[0])) return false;
  return segments.every((s) => SAFE_SEGMENT.test(s) && s !== "." && s !== "..");
}

export function problemResponse(status: number, title: string, detail?: string, type = "about:blank"): Response {
  const body: ProblemDetails = { type, title, status, ...(detail ? { detail } : {}) };
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/problem+json", "Cache-Control": "no-store" },
  });
}

/** Request headers forwarded from the browser to Laravel. */
const FORWARD_REQUEST_HEADERS = ["accept", "content-type", "idempotency-key", "accept-language"];

/** Response headers forwarded from Laravel to the browser. */
const FORWARD_RESPONSE_HEADERS = [
  "content-type",
  "content-disposition",
  "cache-control",
  "retry-after",
  "x-ratelimit-limit",
  "x-ratelimit-remaining",
  "x-ratelimit-reset",
  "location",
  "etag",
  "last-modified",
];

export interface ApiFetchInit {
  method?: string;
  headers?: HeadersInit;
  body?: BodyInit | null;
  signal?: AbortSignal;
}

/**
 * Typed-agnostic fetch against the Laravel API. `path` is relative to the
 * configured base (e.g. "/searches/01J.../offers?band=exact").
 */
export async function apiFetch(path: string, init: ApiFetchInit = {}): Promise<Response> {
  const config = getApiConfig();
  if (!config) {
    return problemResponse(
      503,
      "API not configured",
      "Set PRICELENS_API_URL and PRICELENS_API_KEY on the web server.",
    );
  }
  const headers = new Headers(init.headers);
  headers.set("Authorization", `Bearer ${config.apiKey}`);
  if (!headers.has("Accept")) headers.set("Accept", "application/json");

  try {
    return await fetch(`${config.baseUrl}${path.startsWith("/") ? path : `/${path}`}`, {
      method: init.method ?? "GET",
      headers,
      body: init.body,
      signal: init.signal,
      cache: "no-store",
      redirect: "manual",
    });
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") throw err;
    return problemResponse(502, "Upstream unavailable", "The PriceLens API could not be reached.");
  }
}

/** JSON helper for server components / route handlers. Throws the problem on non-2xx. */
export async function apiJson<T>(path: string, init?: ApiFetchInit): Promise<T> {
  const res = await apiFetch(path, init);
  const text = await res.text();
  const body = text ? JSON.parse(text) : undefined;
  if (!res.ok) {
    throw Object.assign(new Error((body as ProblemDetails | undefined)?.title ?? "API request failed"), {
      problem: body as ProblemDetails | undefined,
      status: res.status,
    });
  }
  return body as T;
}

/** Copy the whitelisted upstream headers and stream the body back unchanged. */
export function relayResponse(upstream: Response): Response {
  const headers = new Headers();
  for (const name of FORWARD_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);
    if (value) headers.set(name, value);
  }
  if (!headers.has("cache-control")) headers.set("Cache-Control", "no-store");
  const nullBody = upstream.status === 204 || upstream.status === 304;
  return new Response(nullBody ? null : upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers,
  });
}

/**
 * Generic BFF proxy: forwards method, query string, JSON or multipart body to
 * `${PRICELENS_API_URL}/${segments}` and streams the response (CSV export).
 */
export async function proxyToApi(request: Request, segments: readonly string[]): Promise<Response> {
  if (!isAllowedProxyPath(segments)) {
    return problemResponse(404, "Not found", "Unknown API resource.");
  }
  const incoming = new URL(request.url);
  const path = `/${segments.map(encodeURIComponent).join("/")}${incoming.search}`;

  const headers = new Headers();
  for (const name of FORWARD_REQUEST_HEADERS) {
    const value = request.headers.get(name);
    if (value) headers.set(name, value);
  }

  const method = request.method.toUpperCase();
  let body: BodyInit | null = null;
  if (method !== "GET" && method !== "HEAD") {
    // Buffer the body: keeps the multipart boundary intact (content-type is
    // forwarded verbatim) and avoids half-duplex streaming requirements.
    const buf = await request.arrayBuffer();
    body = buf.byteLength > 0 ? buf : null;
  }

  const upstream = await apiFetch(path, { method, headers, body, signal: request.signal });
  return relayResponse(upstream);
}
