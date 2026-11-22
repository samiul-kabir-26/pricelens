import type { Offer, Search } from "@/lib/types";

export function makeOffer(overrides: Partial<Offer> & { id: string }): Offer {
  return {
    site: { domain: "shop.example.com", name: "Example Electronics" },
    url: "https://shop.example.com/p/sony-wh-1000xm5-black",
    title: "Sony WH-1000XM5 Wireless Headphones - Black",
    image_url: null,
    price: { amount: "329.99", currency: "USD" },
    list_price: { amount: "399.99", currency: "USD" },
    shipping: { amount: "0.00", currency: "USD" },
    total: { amount: "329.99", currency: "USD" },
    converted_total: { amount: "329.99", currency: "USD", fx_rate_date: "2026-10-30" },
    availability: "in_stock",
    condition: "new",
    seller: "Example Electronics",
    brand: "Sony",
    rating: null,
    review_count: null,
    variant: null,
    identifiers: { gtin: "0027242923782", mpn: "WH1000XM5/B", sku: "EX-88231" },
    match: { confidence: 1, band: "exact", type: "exact_gtin", breakdown: { gtin: 1 } },
    extraction_method: "json_ld",
    price_outlier: false,
    is_duplicate: false,
    observed_at: "2026-11-01T09:14:22Z",
    ...overrides,
  };
}

export function makeSearch(overrides: Partial<Search> = {}): Search {
  const id = overrides.id ?? "01JB7K2M4X9Q8T6V5R3N1P0WZY";
  return {
    id,
    status: "completed",
    input: { title: "Sony WH-1000XM5", gtin: "0027242923782" },
    product_query: {
      title: "Sony WH-1000XM5",
      cleaned_title: "Sony WH-1000XM5 Wireless Headphones",
      tokens: ["sony", "wh-1000xm5"],
      brand: "Sony",
      model: "WH-1000XM5",
      gtin: "0027242923782",
      mpn: "WH1000XM5/B",
      image_url: null,
      image_phash: null,
      attributes: {},
      condition: "new",
      labels: [],
      reference_url: null,
    },
    options: { country: "US", target_currency: "USD" },
    target_currency: "USD",
    country: "US",
    progress: { total: 4, done: 4 },
    stats: null,
    error: null,
    sources: [],
    monitor_id: null,
    started_at: "2026-11-01T09:14:00Z",
    completed_at: "2026-11-01T09:14:30Z",
    created_at: "2026-11-01T09:14:00Z",
    links: { self: `/api/v1/searches/${id}`, offers: `/api/v1/searches/${id}/offers` },
    realtime: { channel: `private-tenant.42.search.${id}` },
    ...overrides,
  };
}
