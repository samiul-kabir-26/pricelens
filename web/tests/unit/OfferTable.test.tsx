import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import { OfferTable, nextSort } from "@/components/results/OfferTable";
import type { Offer, OfferSort } from "@/lib/types";
import { makeOffer } from "../fixtures";

const usd = (amount: string) => ({ amount, currency: "USD" });

const offers: Offer[] = [
  makeOffer({
    id: "A",
    site: { domain: "bravo.example", name: "Bravo" },
    price: usd("320.00"),
    total: usd("335.00"),
    converted_total: null,
    match: { confidence: 0.7, band: "likely", type: "scored", breakdown: {} },
  }),
  makeOffer({
    id: "B",
    site: { domain: "alpha.example", name: "Alpha" },
    price: usd("299.00"),
    total: usd("349.00"),
    converted_total: null,
    match: { confidence: 0.95, band: "exact", type: "exact_gtin", breakdown: {} },
  }),
  makeOffer({
    id: "C",
    site: { domain: "charlie.example", name: "Charlie" },
    price: usd("310.00"),
    total: usd("310.00"),
    converted_total: null,
    match: { confidence: 0.8, band: "likely", type: "scored", breakdown: {} },
  }),
];

function Harness({ initial }: { initial?: OfferSort }) {
  const [sort, setSort] = useState<OfferSort | undefined>(initial);
  return (
    <>
      <output data-testid="sort">{sort ?? ""}</output>
      <OfferTable offers={offers} sort={sort} onSortChange={setSort} />
    </>
  );
}

const siteOrder = () =>
  screen.getAllByTestId("offer-row").map((row) => within(row).getAllByRole("cell")[1].textContent);

describe("OfferTable sorting", () => {
  it("renders rows sorted by total ascending by default sort", () => {
    render(<Harness initial="total_price" />);
    expect(siteOrder()).toEqual(["Charlie", "Bravo", "Alpha"]);
    expect(screen.getByRole("columnheader", { name: /total/i })).toHaveAttribute("aria-sort", "ascending");
  });

  it("toggles total ascending/descending on header click", async () => {
    const user = userEvent.setup();
    render(<Harness initial="total_price" />);
    await user.click(screen.getByRole("button", { name: /^total/i }));
    expect(screen.getByTestId("sort")).toHaveTextContent("-total_price");
    expect(siteOrder()).toEqual(["Alpha", "Bravo", "Charlie"]);
    expect(screen.getByRole("columnheader", { name: /total/i })).toHaveAttribute("aria-sort", "descending");
  });

  it("sorts by match confidence, highest first", async () => {
    const user = userEvent.setup();
    render(<Harness initial="total_price" />);
    await user.click(screen.getByRole("button", { name: /^match/i }));
    expect(screen.getByTestId("sort")).toHaveTextContent("-confidence");
    expect(siteOrder()).toEqual(["Alpha", "Charlie", "Bravo"]);
    expect(screen.getByRole("columnheader", { name: /total/i })).toHaveAttribute("aria-sort", "none");
  });

  it("sorts by site name and item price", async () => {
    const user = userEvent.setup();
    render(<Harness />);
    await user.click(screen.getByRole("button", { name: /^site/i }));
    expect(siteOrder()).toEqual(["Alpha", "Bravo", "Charlie"]);
    await user.click(screen.getByRole("button", { name: /^price/i }));
    expect(screen.getByTestId("sort")).toHaveTextContent("price");
    expect(siteOrder()).toEqual(["Alpha", "Charlie", "Bravo"]);
  });

  it("is keyboard operable", async () => {
    const user = userEvent.setup();
    render(<Harness initial="total_price" />);
    screen.getByRole("button", { name: /^total/i }).focus();
    await user.keyboard("{Enter}");
    expect(screen.getByTestId("sort")).toHaveTextContent("-total_price");
  });

  it("cycles sort values per column", () => {
    expect(nextSort("total", undefined)).toBe("total_price");
    expect(nextSort("total", "total_price")).toBe("-total_price");
    expect(nextSort("total", "-total_price")).toBe("total_price");
    expect(nextSort("match", "total_price")).toBe("-confidence");
    expect(nextSort("match", "-confidence")).toBe("confidence");
  });

  it("cycles site, price and observed columns in both directions", () => {
    expect(nextSort("site", "site")).toBe("-site");
    expect(nextSort("price", "price")).toBe("-price");
    expect(nextSort("observed", undefined)).toBe("-observed_at");
    expect(nextSort("total", "rank")).toBe("total_price");
  });

  it("marks no column as sorted for the default rank order", () => {
    render(<Harness initial="rank" />);
    for (const header of screen.getAllByRole("columnheader")) {
      expect(header.getAttribute("aria-sort") ?? "none").toBe("none");
    }
  });

  it("renders outbound links safely", () => {
    render(<Harness />);
    for (const link of screen.getAllByRole("link", { name: /view/i })) {
      expect(link).toHaveAttribute("target", "_blank");
      expect(link).toHaveAttribute("rel", "noopener noreferrer nofollow");
    }
  });
});

describe("OfferTable product details", () => {
  it("shows brand, rating, variant chips and duplicate/outlier badges", () => {
    const offer = makeOffer({
      id: "R",
      brand: "Sony",
      seller: "Example Electronics",
      rating: 4.64,
      review_count: 1234,
      variant: { color: "Black", storage: "256 GB", size: null, material: "plastic" },
      is_duplicate: true,
      price_outlier: true,
    });
    render(<OfferTable offers={[offer]} sort={undefined} onSortChange={() => {}} />);
    const row = screen.getByTestId("offer-row");
    expect(row).toHaveTextContent("Sony · Example Electronics · GTIN 0027242923782");
    expect(within(row).getByTitle("Rated 4.6 out of 5 from 1,234 reviews")).toHaveTextContent("★ 4.6 (1,234)");
    const chips = within(row).getByRole("list", { name: "Variant" });
    expect(within(chips).getAllByRole("listitem").map((li) => li.textContent)).toEqual(["ColorBlack", "Storage256 GB"]);
    expect(within(row).getByText("Duplicate")).toBeInTheDocument();
    expect(within(row).getByText("Price outlier")).toBeInTheDocument();
  });

  it("handles a missing title, rating and variant", () => {
    const offer = makeOffer({ id: "N", title: null, rating: null, review_count: null, variant: null, observed_at: null });
    render(<OfferTable offers={[offer]} sort={undefined} onSortChange={() => {}} />);
    const row = screen.getByTestId("offer-row");
    expect(row).toHaveTextContent("Listing on Example Electronics");
    expect(row).not.toHaveTextContent("★");
    expect(within(row).queryByRole("list", { name: "Variant" })).toBeNull();
  });
});
