import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { isAllowedProxyPath, proxyToApi } from "@/lib/api-client";
import { POST as broadcastingAuth } from "@/app/api/broadcasting/auth/route";

const fetchMock = vi.fn();

beforeEach(() => {
  vi.stubEnv("PRICELENS_API_URL", "http://api.test/api/v1/");
  vi.stubEnv("PRICELENS_API_KEY", "pl_live_secret");
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

describe("BFF proxy", () => {
  it("forwards method, query and JSON body with the bearer key", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: { id: "S1" } }), { status: 202, headers: { "Content-Type": "application/json" } }));
    const req = new Request("http://web.test/api/searches?x=1", {
      method: "POST",
      headers: { "Content-Type": "application/json", "Idempotency-Key": "k1", Cookie: "session=abc" },
      body: JSON.stringify({ title: "Sony" }),
    });
    const res = await proxyToApi(req, ["searches"]);
    expect(res.status).toBe(202);
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Headers }];
    expect(url).toBe("http://api.test/api/v1/searches?x=1");
    expect(init.method).toBe("POST");
    expect(init.headers.get("authorization")).toBe("Bearer pl_live_secret");
    expect(init.headers.get("idempotency-key")).toBe("k1");
    expect(init.headers.get("cookie")).toBeNull();
    expect(new TextDecoder().decode(init.body as ArrayBuffer)).toBe('{"title":"Sony"}');
  });

  it("keeps the multipart boundary intact", async () => {
    fetchMock.mockResolvedValue(new Response("{}", { status: 202 }));
    const fd = new FormData();
    fd.append("image", new File(["png"], "a.png", { type: "image/png" }));
    fd.append("options", "{}");
    const req = new Request("http://web.test/api/searches", { method: "POST", body: fd });
    await proxyToApi(req, ["searches"]);
    const init = fetchMock.mock.calls[0][1] as RequestInit & { headers: Headers };
    expect(init.headers.get("content-type")).toMatch(/^multipart\/form-data; boundary=/);
  });

  it("passes problem+json errors and status through", async () => {
    const problem = { type: "quota_exceeded", title: "Monthly search quota exceeded", status: 429 };
    fetchMock.mockResolvedValue(
      new Response(JSON.stringify(problem), { status: 429, headers: { "Content-Type": "application/problem+json", "Retry-After": "60" } }),
    );
    const res = await proxyToApi(new Request("http://web.test/api/searches", { method: "POST", body: "{}" }), ["searches"]);
    expect(res.status).toBe(429);
    expect(res.headers.get("content-type")).toBe("application/problem+json");
    expect(res.headers.get("retry-after")).toBe("60");
    expect(await res.json()).toEqual(problem);
  });

  it("streams CSV exports with content-disposition", async () => {
    const stream = new ReadableStream({
      start(c) {
        c.enqueue(new TextEncoder().encode("site,price\n"));
        c.enqueue(new TextEncoder().encode("shop.example.com,329.99\n"));
        c.close();
      },
    });
    fetchMock.mockResolvedValue(
      new Response(stream, { status: 200, headers: { "Content-Type": "text/csv", "Content-Disposition": 'attachment; filename="s.csv"' } }),
    );
    const res = await proxyToApi(new Request("http://web.test/api/searches/S1/export?format=csv"), ["searches", "S1", "export"]);
    expect(fetchMock.mock.calls[0][0]).toBe("http://api.test/api/v1/searches/S1/export?format=csv");
    expect(res.headers.get("content-disposition")).toContain("s.csv");
    expect(await res.text()).toBe("site,price\nshop.example.com,329.99\n");
  });

  it("rejects unknown roots and traversal without calling upstream", async () => {
    expect(isAllowedProxyPath(["api-keys"])).toBe(false);
    expect(isAllowedProxyPath(["searches", ".."])).toBe(false);
    expect(isAllowedProxyPath(["searches", "a/b"])).toBe(false);
    expect(isAllowedProxyPath(["searches", "01JB", "offers"])).toBe(true);
    const res = await proxyToApi(new Request("http://web.test/api/api-keys"), ["api-keys"]);
    expect(res.status).toBe(404);
    expect(res.headers.get("content-type")).toBe("application/problem+json");
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it("returns 502 problem when Laravel is unreachable", async () => {
    fetchMock.mockRejectedValue(new TypeError("fetch failed"));
    const res = await proxyToApi(new Request("http://web.test/api/usage"), ["usage"]);
    expect(res.status).toBe(502);
    expect((await res.json()).title).toBe("Upstream unavailable");
  });

  it("returns 503 when not configured", async () => {
    vi.stubEnv("PRICELENS_API_KEY", "");
    const res = await proxyToApi(new Request("http://web.test/api/usage"), ["usage"]);
    expect(res.status).toBe(503);
  });
});

describe("broadcasting auth", () => {
  it("forwards socket_id and channel_name as form fields", async () => {
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ auth: "key:sig" }), { status: 200, headers: { "Content-Type": "application/json" } }));
    const body = new URLSearchParams({ socket_id: "123.456", channel_name: "private-tenant.42.search.S1" });
    const req = new Request("http://web.test/api/broadcasting/auth", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body,
    });
    const res = await broadcastingAuth(req as never);
    expect(res.status).toBe(200);
    expect(await res.json()).toEqual({ auth: "key:sig" });
    const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit & { headers: Headers }];
    expect(url).toBe("http://api.test/api/v1/broadcasting/auth");
    expect(init.headers.get("authorization")).toBe("Bearer pl_live_secret");
    expect(String(init.body)).toBe("socket_id=123.456&channel_name=private-tenant.42.search.S1");
  });

  it("rejects malformed requests", async () => {
    const req = new Request("http://web.test/api/broadcasting/auth", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({ socket_id: "x", channel_name: "public" }),
    });
    const res = await broadcastingAuth(req as never);
    expect(res.status).toBe(422);
    expect(fetchMock).not.toHaveBeenCalled();
  });
});
