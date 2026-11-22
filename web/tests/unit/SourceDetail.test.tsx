import { screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { SourceDetail } from "@/components/sources/SourceDetail";
import type { Source } from "@/lib/types";
import { json, mockFetch, renderWithClient } from "../utils";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn(), back: vi.fn(), refresh: vi.fn() }),
  usePathname: () => "/sources",
}));

const base: Source = {
  id: "S1",
  type: "feed",
  name: "Acme feed",
  domain: "acme.example",
  country: "US",
  currency: "USD",
  render: "never",
  enabled: true,
  is_global: false,
  health_status: "healthy",
  config: { feed_url: "https://acme.example/feed.csv", format: "csv" },
  forked_from_id: null,
  feed_refreshed_at: new Date(Date.now() - 2 * 3600_000).toISOString(),
  created_at: "2026-10-01T00:00:00Z",
  updated_at: "2026-10-02T00:00:00Z",
};

describe("SourceDetail", () => {
  it("shows when a feed was last refreshed", async () => {
    mockFetch((url) => (url === "/api/sources/S1" ? json({ data: base }) : undefined));
    renderWithClient(<SourceDetail id="S1" />);
    const note = await screen.findByTestId("feed-refreshed");
    expect(note).toHaveTextContent(/Feed refreshed 2 hours ago/);
    expect(note.querySelector("time")).toHaveAttribute("dateTime", base.feed_refreshed_at);
  });

  it("says never for a feed that has not been fetched", async () => {
    mockFetch((url) => (url === "/api/sources/S1" ? json({ data: { ...base, feed_refreshed_at: null } }) : undefined));
    renderWithClient(<SourceDetail id="S1" />);
    expect(await screen.findByTestId("feed-refreshed")).toHaveTextContent("Feed refreshed never");
  });

  it("omits the refresh time for non-feed sources", async () => {
    mockFetch((url) => (url === "/api/sources/S1" ? json({ data: { ...base, type: "site" } }) : undefined));
    renderWithClient(<SourceDetail id="S1" />);
    expect(await screen.findByRole("heading", { name: "Acme feed" })).toBeInTheDocument();
    expect(screen.queryByTestId("feed-refreshed")).toBeNull();
  });
});
