import { screen, within } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { UsageView } from "@/components/settings/UsageView";
import type { Usage } from "@/lib/types";
import { json, mockFetch, renderWithClient } from "../utils";

const usage: Usage = {
  period: { start: "2026-11-01T00:00:00Z", end: "2026-11-30T23:59:59Z" },
  plan: { code: "pro", name: "Pro" },
  metrics: [{ metric: "searches", used: 120, limit: 1000 }],
  limits: { searches_per_month: 1000, max_results: 100, render_pages: null },
  features: { "llm-extraction": true, "byo-proxies": false, "image-search": true },
};

describe("UsageView", () => {
  it("renders feature flags as on/off badges and the raw plan limits", async () => {
    mockFetch((url) => (url === "/api/usage" ? json({ data: usage }) : undefined));
    renderWithClient(<UsageView />);

    const features = await screen.findByRole("list", { name: "Plan features" });
    const badge = (label: string) => within(features).getByText(label).closest("[data-feature]")!;
    expect(badge("LLM extraction")).toHaveAttribute("data-enabled", "true");
    expect(badge("LLM extraction")).toHaveTextContent("LLM extraction:On");
    expect(badge("Bring-your-own proxies")).toHaveAttribute("data-enabled", "false");
    expect(badge("Bring-your-own proxies")).toHaveTextContent("Off");
    expect(badge("Image search")).toHaveAttribute("data-enabled", "true");

    expect(screen.getByText("Searches per month").nextSibling).toHaveTextContent("1,000");
    expect(screen.getByText("Rendered pages").nextSibling).toHaveTextContent("Unlimited");
  });

  it("copes with null limits and no features", async () => {
    mockFetch((url) => (url === "/api/usage" ? json({ data: { ...usage, limits: null, features: {} } }) : undefined));
    renderWithClient(<UsageView />);
    expect(await screen.findByText("No feature flags reported for this plan.")).toBeInTheDocument();
    expect(screen.queryByText("Plan limits")).toBeNull();
  });
});
