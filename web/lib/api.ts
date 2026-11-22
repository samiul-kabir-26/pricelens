/**
 * Browser-side fetch helpers. Every request goes to the Next.js BFF under
 * /api/**; the BFF attaches the tenant API key server-side.
 */
import { problemDetailsSchema } from "./schemas";
import type { ProblemDetails } from "./types";

export class ApiError extends Error {
  readonly status: number;
  readonly problem: ProblemDetails;

  constructor(problem: ProblemDetails) {
    super(problem.detail || problem.title || `Request failed (${problem.status})`);
    this.name = "ApiError";
    this.status = problem.status;
    this.problem = problem;
  }

  /** Field errors from a 422, keyed by API dot path, first message only. */
  get fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const [key, messages] of Object.entries(this.problem.errors ?? {})) {
      if (messages.length > 0) out[key] = messages[0];
    }
    return out;
  }
}

export type QueryValue = string | number | boolean | null | undefined | readonly string[];

export function buildQuery(params?: Record<string, QueryValue>): string {
  if (!params) return "";
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === "") continue;
    if (Array.isArray(value)) {
      if (value.length > 0) qs.set(key, value.join(","));
    } else {
      qs.set(key, String(value));
    }
  }
  const s = qs.toString();
  return s ? `?${s}` : "";
}

async function toApiError(res: Response): Promise<ApiError> {
  let body: unknown = null;
  try {
    body = await res.json();
  } catch {
    // non-JSON error body
  }
  const parsed = problemDetailsSchema.safeParse(
    body && typeof body === "object" ? { status: res.status, ...body } : { status: res.status },
  );
  if (parsed.success) return new ApiError(parsed.data);
  return new ApiError({
    type: "about:blank",
    title: res.statusText || "Request failed",
    status: res.status,
  });
}

interface RequestOptions {
  method?: "GET" | "POST" | "PATCH" | "PUT" | "DELETE";
  query?: Record<string, QueryValue>;
  /** Plain objects are JSON-encoded; FormData is sent as multipart. */
  body?: unknown;
  signal?: AbortSignal;
  idempotencyKey?: string;
}

export async function apiRequest<T>(path: string, opts: RequestOptions = {}): Promise<T> {
  const { method = "GET", query, body, signal, idempotencyKey } = opts;
  const headers: Record<string, string> = { Accept: "application/json" };
  let payload: BodyInit | undefined;
  if (body instanceof FormData) {
    payload = body;
  } else if (body !== undefined) {
    headers["Content-Type"] = "application/json";
    payload = JSON.stringify(body);
  }
  if (idempotencyKey) headers["Idempotency-Key"] = idempotencyKey;

  let res: Response;
  try {
    res = await fetch(`/api${path}${buildQuery(query)}`, {
      method,
      headers,
      body: payload,
      signal,
      cache: "no-store",
    });
  } catch (err) {
    if (err instanceof DOMException && err.name === "AbortError") throw err;
    throw new ApiError({
      type: "about:blank",
      title: "Network error",
      status: 0,
      detail: "Could not reach the server. Check your connection and try again.",
    });
  }

  if (!res.ok) throw await toApiError(res);
  if (res.status === 204) return undefined as T;
  const text = await res.text();
  return (text ? JSON.parse(text) : undefined) as T;
}

export const api = {
  get: <T>(path: string, query?: Record<string, QueryValue>, signal?: AbortSignal) =>
    apiRequest<T>(path, { query, signal }),
  post: <T>(path: string, body?: unknown, extra?: Omit<RequestOptions, "method" | "body">) =>
    apiRequest<T>(path, { ...extra, method: "POST", body }),
  patch: <T>(path: string, body?: unknown) => apiRequest<T>(path, { method: "PATCH", body }),
  delete: <T = void>(path: string) => apiRequest<T>(path, { method: "DELETE" }),
};

export function newIdempotencyKey(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function errorMessage(err: unknown): string {
  if (err instanceof ApiError) return err.message;
  if (err instanceof Error) return err.message;
  return "Something went wrong";
}
