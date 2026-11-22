import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import { OfferFilters, SORT_OPTIONS } from "@/components/results/OfferFilters";
import { defaultOfferFilters } from "@/lib/offers";
import type { OfferFilters as Filters } from "@/lib/types";

function Harness({ initial = defaultOfferFilters }: { initial?: Filters }) {
  const [value, setValue] = useState<Filters>(initial);
  return (
    <>
      <output data-testid="filters">{JSON.stringify(value)}</output>
      <OfferFilters value={value} onChange={setValue} sites={[]} currency="USD" />
    </>
  );
}

const current = () => JSON.parse(screen.getByTestId("filters").textContent!) as Filters;

describe("OfferFilters", () => {
  it("toggles duplicates on (hidden by default)", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const dupes = screen.getByRole("checkbox", { name: "Show duplicates" });
    expect(dupes).not.toBeChecked();
    await user.click(dupes);
    expect(current().include_duplicates).toBe(true);
    await user.click(dupes);
    expect(current().include_duplicates).toBeUndefined();
  });

  it("shows outliers by default and excludes them explicitly", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const outliers = screen.getByRole("checkbox", { name: "Show outliers" });
    expect(outliers).toBeChecked();
    await user.click(outliers);
    expect(current().include_outliers).toBe(false);
    await user.click(outliers);
    expect(current().include_outliers).toBeUndefined();
  });

  it("offers every API sort, defaulting to rank", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    const select = screen.getByLabelText("Sort by") as HTMLSelectElement;
    expect(select.value).toBe("rank");
    expect([...select.options].map((o) => o.value).sort()).toEqual(
      ["rank", "price", "-price", "total_price", "-total_price", "confidence", "-confidence", "site", "-site", "observed_at", "-observed_at"].sort(),
    );
    expect(SORT_OPTIONS).toHaveLength(11);
    await user.selectOptions(select, "-observed_at");
    expect(current().sort).toBe("-observed_at");
  });

  it("resets the toggles with the other filters", async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ ...defaultOfferFilters, include_duplicates: true, include_outliers: false }} />);
    await user.click(screen.getByRole("button", { name: "Reset filters" }));
    expect(current()).toEqual(defaultOfferFilters);
  });
});
