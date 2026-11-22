import { screen } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { DiagnosticsDrawer } from "@/components/results/DiagnosticsDrawer";
import type { SearchTask } from "@/lib/types";
import { json, mockFetch, renderWithClient } from "../utils";

const task = (overrides: Partial<SearchTask>): SearchTask => ({
  id: "T1",
  source_id: "S1",
  source_name: "Store Net",
  stage: "fetch",
  url: "https://store.example.net/p/1",
  status: "failed",
  attempts: 3,
  http_status: 403,
  error_code: null,
  error_message: null,
  duration_ms: 1500,
  created_at: "2026-11-01T09:14:00Z",
  ...overrides,
});

describe("DiagnosticsDrawer", () => {
  it("shows the error code and the human-readable error message", async () => {
    mockFetch((url) =>
      url.startsWith("/api/searches/S/tasks")
        ? json({
            data: [
              task({ id: "T1", error_code: "blocked_waf", error_message: "Cloudflare challenge page (cf-ray 8a1b)" }),
              task({ id: "T2", status: "succeeded", http_status: 200, error_code: null, error_message: null }),
            ],
          })
        : undefined,
    );
    renderWithClient(<DiagnosticsDrawer searchId="S" open onClose={() => {}} live={false} />);
    expect(await screen.findByText("blocked_waf")).toBeInTheDocument();
    expect(screen.getByTestId("task-error-message")).toHaveTextContent("Cloudflare challenge page (cf-ray 8a1b)");
    expect(screen.getByText("2 tasks · 1 failed")).toBeInTheDocument();
  });
});
