import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { SearchForm, mapServerErrors } from "@/components/search/SearchForm";
import { searchFormSchema, defaultSearchFormValues, zodErrorToFieldErrors } from "@/lib/schemas";

const push = vi.fn();
vi.mock("next/navigation", () => ({
  useRouter: () => ({ push, replace: vi.fn(), prefetch: vi.fn(), back: vi.fn(), refresh: vi.fn() }),
  usePathname: () => "/",
}));

type FetchCall = [string, RequestInit | undefined];

function json(body: unknown, status = 200, contentType = "application/json") {
  return new Response(JSON.stringify(body), { status, headers: { "Content-Type": contentType } });
}

let fetchMock: ReturnType<typeof vi.fn>;

function setup(postResponse: () => Response = () => json({ data: { id: "01NEWSEARCH" } }, 202)) {
  fetchMock = vi.fn(async (input: string, init?: RequestInit) => {
    if (init?.method === "POST" && input === "/api/searches") return postResponse();
    if (input.startsWith("/api/sources/global")) {
      return json({
        data: [
          {
            id: "SRC_GLOBAL",
            type: "site",
            name: "Global Shop",
            domain: "global.example",
            enabled: true,
            is_global: true,
            health_status: "healthy",
          },
        ],
        meta: { next_cursor: null },
      });
    }
    if (input.startsWith("/api/sources")) {
      return json({
        data: [
          { id: "SRC_1", type: "site", name: "Example Electronics", domain: "shop.example.com", enabled: true, is_global: false, health_status: "degraded" },
        ],
        meta: { next_cursor: null },
      });
    }
    return json({}, 404);
  });
  vi.stubGlobal("fetch", fetchMock);
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
  const user = userEvent.setup();
  render(
    <QueryClientProvider client={qc}>
      <SearchForm />
    </QueryClientProvider>,
  );
  return { user };
}

const postCalls = () => (fetchMock.mock.calls as FetchCall[]).filter(([, init]) => init?.method === "POST");

beforeEach(() => {
  push.mockReset();
  vi.unstubAllGlobals();
});

describe("SearchForm validation", () => {
  it("requires at least one of title/image/gtin/mpn/reference_url", async () => {
    const { user } = setup();
    await user.click(screen.getByRole("button", { name: /search prices/i }));
    expect(await screen.findByText(/provide at least one of: title, image, gtin, mpn or reference url/i)).toBeInTheDocument();
    expect(postCalls()).toHaveLength(0);
  });

  it("brand alone is not enough", async () => {
    const { user } = setup();
    await user.type(screen.getByLabelText("Brand"), "Sony");
    await user.click(screen.getByRole("button", { name: /search prices/i }));
    expect(await screen.findByText(/provide at least one of: title/i)).toBeInTheDocument();
  });

  it("rejects a GTIN with a bad check digit and marks the field invalid", async () => {
    const { user } = setup();
    const gtin = screen.getByLabelText("GTIN / EAN / UPC");
    await user.type(gtin, "0027242923781");
    await user.click(screen.getByRole("button", { name: /search prices/i }));
    expect(await screen.findByText(/check digit does not match/i)).toBeInTheDocument();
    expect(gtin).toHaveAttribute("aria-invalid", "true");
    expect(gtin).toHaveAccessibleDescription(/check digit/i);
    expect(postCalls()).toHaveLength(0);
  });

  it("validates URLs and max results", () => {
    const result = searchFormSchema.safeParse({
      ...defaultSearchFormValues,
      reference_url: "not a url",
      options: { ...defaultSearchFormValues.options, max_results: 500 },
    });
    expect(result.success).toBe(false);
    const errors = zodErrorToFieldErrors(result.error!);
    expect(errors.reference_url).toMatch(/valid http/i);
    expect(errors["options.max_results"]).toMatch(/at most 100/i);
  });

  it.each([
    ["title", { title: "Sony WH-1000XM5" }],
    ["gtin", { gtin: "0027242923782" }],
    ["mpn", { mpn: "WH1000XM5/B" }],
    ["reference_url", { reference_url: "https://shop.example.com/p/1" }],
    ["image_url", { image_url: "https://cdn.example.org/x.jpg" }],
  ])("accepts %s as the only identifier", (_, patch) => {
    expect(searchFormSchema.safeParse({ ...defaultSearchFormValues, ...patch }).success).toBe(true);
  });

  it("rejects oversized or wrongly typed images", () => {
    const big = new File([new Uint8Array(10 * 1024 * 1024 + 1)], "big.png", { type: "image/png" });
    const gif = new File(["x"], "a.gif", { type: "image/gif" });
    const e1 = zodErrorToFieldErrors(searchFormSchema.safeParse({ ...defaultSearchFormValues, image: big }).error!);
    const e2 = zodErrorToFieldErrors(searchFormSchema.safeParse({ ...defaultSearchFormValues, image: gif }).error!);
    expect(e1.image).toMatch(/10 MB/);
    expect(e2.image).toMatch(/JPEG, PNG or WebP/);
  });
});

describe("SearchForm submission", () => {
  it("posts the JSON contract body and navigates to the search", async () => {
    const { user } = setup();
    await user.type(screen.getByLabelText("Title"), "Sony WH-1000XM5");
    await user.type(screen.getByLabelText("GTIN / EAN / UPC"), "0027242923782");
    await user.click(await screen.findByLabelText("Example Electronics"));
    await user.type(screen.getByLabelText("Extra domains"), "https://Store.Example.net/path{Enter}");
    await user.click(screen.getByRole("button", { name: /search prices/i }));

    await waitFor(() => expect(push).toHaveBeenCalledWith("/searches/01NEWSEARCH"));
    const [, init] = postCalls()[0];
    expect((init!.headers as Record<string, string>)["Content-Type"]).toBe("application/json");
    expect((init!.headers as Record<string, string>)["Idempotency-Key"]).toBeTruthy();
    const body = JSON.parse(init!.body as string);
    expect(body).toMatchObject({
      title: "Sony WH-1000XM5",
      gtin: "0027242923782",
      mpn: null,
      reference_url: null,
      sources: {
        source_ids: ["SRC_1"],
        domains: ["store.example.net"],
        search_engines: [
          { provider: "serpapi", mode: "shopping", verify: false },
          { provider: "brave", mode: "web", scope: "allowed_domains" },
        ],
        reverse_image: false,
      },
      options: { country: "US", language: "en", target_currency: "USD", condition: "new", max_results: 50, min_confidence: 0.6, fresh: false },
    });
  });

  it("asks the API to verify shopping results when the toggle is on", async () => {
    const { user } = setup();
    const verify = screen.getByRole("checkbox", { name: /verify shopping results by fetching merchant page/i });
    expect(verify).not.toBeChecked();
    expect(verify).toBeEnabled();
    await user.click(verify);
    await user.type(screen.getByLabelText("Title"), "Sony WH-1000XM5");
    await user.click(screen.getByRole("button", { name: /search prices/i }));

    await waitFor(() => expect(postCalls()).toHaveLength(1));
    const body = JSON.parse(postCalls()[0][1]!.body as string);
    expect(body.sources.search_engines[0]).toEqual({ provider: "serpapi", mode: "shopping", verify: true });
  });

  it("disables and clears verification when the shopping engine is off", async () => {
    const { user } = setup();
    const verify = screen.getByRole("checkbox", { name: /verify shopping results/i });
    await user.click(verify);
    await user.click(screen.getByRole("checkbox", { name: "Shopping engine" }));
    expect(verify).toBeDisabled();
    expect(verify).not.toBeChecked();
  });

  it("sends multipart with JSON-encoded sources/options when an image is attached", async () => {
    const { user } = setup();
    const file = new File(["png"], "xm5.png", { type: "image/png" });
    await user.upload(screen.getByTestId("image-file-input"), file);
    expect(await screen.findByText("xm5.png")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /search prices/i }));

    await waitFor(() => expect(postCalls()).toHaveLength(1));
    const body = postCalls()[0][1]!.body as FormData;
    expect(body).toBeInstanceOf(FormData);
    expect((body.get("image") as File).name).toBe("xm5.png");
    expect(JSON.parse(body.get("sources") as string).search_engines).toHaveLength(2);
    expect(JSON.parse(body.get("options") as string).max_results).toBe(50);
    expect(body.get("title")).toBeNull();
  });

  it("maps problem+json 422 errors onto fields", async () => {
    const { user } = setup(() =>
      json(
        {
          type: "https://docs.pricelens.dev/errors/validation",
          title: "The given data was invalid.",
          status: 422,
          errors: { "options.max_results": ["Your plan allows at most 25 results."], "sources.domains.0": ["Domain is blocklisted."] },
        },
        422,
        "application/problem+json",
      ),
    );
    await user.type(screen.getByLabelText("Title"), "Sony");
    await user.click(screen.getByRole("button", { name: /search prices/i }));
    expect(await screen.findByText("Your plan allows at most 25 results.")).toBeInTheDocument();
    expect(screen.getByText("Domain is blocklisted.")).toBeInTheDocument();
    expect(screen.getByLabelText("Max results")).toHaveAttribute("aria-invalid", "true");
    expect(push).not.toHaveBeenCalled();
  });

  it("shows non-validation problems at the top of the form", async () => {
    const { user } = setup(() =>
      json({ type: "quota_exceeded", title: "Monthly search quota exceeded", status: 429, detail: "Plan 'starter' allows 1000 searches per month." }, 429, "application/problem+json"),
    );
    await user.type(screen.getByLabelText("Title"), "Sony");
    await user.click(screen.getByRole("button", { name: /search prices/i }));
    expect(await screen.findByText("Plan 'starter' allows 1000 searches per month.")).toBeInTheDocument();
  });
});

describe("mapServerErrors", () => {
  it("normalizes nested keys and collects unknown ones", () => {
    expect(
      mapServerErrors({
        "sources.search_engines.1.provider": "Provider not on your plan",
        "sources.source_ids.2": "Unknown source",
        callback_url: "Invalid callback",
        gtin: "Bad GTIN",
      }),
    ).toEqual({
      "sources.engines": "Provider not on your plan",
      "sources.source_ids": "Unknown source",
      gtin: "Bad GTIN",
      form: "Invalid callback",
    });
  });
});
