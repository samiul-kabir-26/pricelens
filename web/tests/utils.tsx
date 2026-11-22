import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render } from "@testing-library/react";
import type { ReactElement } from "react";
import { vi } from "vitest";
import { ToastProvider } from "@/components/ui/Toast";

export type FetchCall = [string, RequestInit | undefined];

export function json(body: unknown, status = 200, contentType = "application/json") {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
}

/** Stubs global fetch with a router `(url, init) => Response`; unmatched requests 404. */
export function mockFetch(route: (url: string, init: RequestInit | undefined) => Response | undefined) {
  const fn = vi.fn(async (input: string, init?: RequestInit) => route(input, init) ?? json({ title: "Not found", status: 404 }, 404));
  vi.stubGlobal("fetch", fn);
  return {
    fn,
    calls: () => fn.mock.calls as FetchCall[],
    bodies: (method: string, prefix: string) =>
      (fn.mock.calls as FetchCall[])
        .filter(([url, init]) => (init?.method ?? "GET") === method && url.startsWith(prefix))
        .map(([, init]) => JSON.parse(String(init?.body ?? "null"))),
  };
}

export function renderWithClient(ui: ReactElement) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <ToastProvider>{ui}</ToastProvider>
    </QueryClientProvider>,
  );
}
