import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, expect, it } from "vitest";
import { MatchBadge } from "@/components/results/MatchBadge";

describe("MatchBadge", () => {
  it("shows the band and rounded confidence", () => {
    render(<MatchBadge match={{ band: "likely", type: "scored", confidence: 0.834, breakdown: {} }} />);
    expect(screen.getByText("Likely · 83%")).toBeInTheDocument();
  });

  it("uses a distinct tone per band", () => {
    const { rerender } = render(<MatchBadge match={{ band: "exact", type: "exact_gtin", confidence: 1, breakdown: {} }} />);
    expect(screen.getByText(/Exact/).className).toMatch(/success/);
    rerender(<MatchBadge match={{ band: "possible", type: "scored", confidence: 0.5, breakdown: {} }} />);
    expect(screen.getByText(/Possible/).className).toMatch(/warning/);
    rerender(<MatchBadge match={{ band: "rejected", type: "mismatch_gtin", confidence: 0.1, breakdown: {} }} />);
    expect(screen.getByText(/Rejected/).closest("[data-band]")).toHaveAttribute("data-band", "rejected");
  });

  it("exposes the score breakdown as an accessible tooltip on focus", async () => {
    const user = userEvent.setup();
    render(
      <MatchBadge
        match={{ band: "likely", type: "scored", confidence: 0.82, breakdown: { title_similarity: 0.91, brand: 1, price_sanity: 0.6 } }}
      />,
    );
    await user.tab();
    const trigger = document.activeElement as HTMLElement;
    const tooltip = screen.getByRole("tooltip", { hidden: true });
    expect(trigger).toHaveAttribute("aria-describedby", tooltip.id);
    expect(tooltip).toHaveTextContent("Scored match — 82% confidence");
    expect(tooltip).toHaveTextContent("Title similarity0.91");
    expect(tooltip).toHaveTextContent("Brand1.00");
    expect(trigger).toHaveAccessibleDescription(/Price sanity\s*0\.60/);
  });

  it("labels GTIN mismatches", () => {
    render(<MatchBadge match={{ band: "rejected", type: "mismatch_gtin", confidence: 0, breakdown: { gtin: 0 } }} />);
    expect(screen.getByRole("tooltip", { hidden: true })).toHaveTextContent("GTIN mismatch");
  });

  it("handles a null breakdown", () => {
    render(<MatchBadge match={{ band: "possible", type: "scored", confidence: 0.45, breakdown: null }} />);
    expect(screen.getByRole("tooltip", { hidden: true })).toHaveTextContent("No breakdown available");
  });

  it("shows the reason and hard negatives without treating them as scores", () => {
    render(
      <MatchBadge
        match={{
          band: "rejected",
          type: "scored",
          confidence: 0.2,
          breakdown: { title_similarity: 0.66, reason: "no_signals", hard_negatives: ["accessory", "condition_mismatch"] },
        }}
      />,
    );
    const tooltip = screen.getByRole("tooltip", { hidden: true });
    expect(tooltip).toHaveTextContent("Title similarity0.66");
    expect(tooltip).toHaveTextContent("Penalised: accessory, condition mismatch");
    expect(tooltip).toHaveTextContent("Reason: no signals");
    expect(tooltip).not.toHaveTextContent("No breakdown available");
  });

  it("falls back to a humanized label for unknown match types", () => {
    render(<MatchBadge match={{ band: "likely", type: "image_similarity", confidence: 0.7, breakdown: {} }} />);
    expect(screen.getByRole("tooltip", { hidden: true })).toHaveTextContent("Image similarity — 70% confidence");
  });
});
