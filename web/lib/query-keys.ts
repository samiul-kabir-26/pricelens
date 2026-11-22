import type { OfferFilters } from "./types";

export const qk = {
  searches: (filters?: Record<string, unknown>) => ["searches", filters ?? {}] as const,
  search: (id: string) => ["search", id] as const,
  offersRoot: (id: string) => ["search", id, "offers"] as const,
  offers: (id: string, filters: OfferFilters) => ["search", id, "offers", filters] as const,
  tasks: (id: string) => ["search", id, "tasks"] as const,
  sources: () => ["sources"] as const,
  globalSources: () => ["sources", "global"] as const,
  source: (id: string) => ["source", id] as const,
  products: (filters?: Record<string, unknown>) => ["tracked-products", filters ?? {}] as const,
  product: (id: string) => ["tracked-product", id] as const,
  priceHistory: (id: string, params: Record<string, unknown>) => ["tracked-product", id, "history", params] as const,
  monitor: (id: string) => ["monitor", id] as const,
  monitors: (filters?: Record<string, unknown>) => ["monitors", filters ?? {}] as const,
  alerts: (filters?: Record<string, unknown>) => ["alerts", filters ?? {}] as const,
  webhooks: () => ["webhooks"] as const,
  deliveries: (webhookId: string) => ["webhooks", webhookId, "deliveries"] as const,
  usage: () => ["usage"] as const,
};
