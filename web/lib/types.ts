/**
 * PriceLens API types (base path /api/v1).
 *
 * The contract lives in ../openapi.yaml. `npm run gen:types` regenerates
 * lib/openapi.d.ts from it; this module re-exports those generated schemas
 * under the names the UI uses, so call sites never import the generated file
 * directly. Hand-written types remain only where the generated ones are
 * awkward (open-ended string unions with known labels, request bodies that
 * accept several shapes, client-side filter state, realtime payloads).
 *
 * To resync after a spec change: `npm run gen:types && npx tsc --noEmit`.
 */
import type { components } from "./openapi";

/** Every schema from the OpenAPI document, keyed by its component name. */
export type Schemas = components["schemas"];
/** Shared query parameters from the OpenAPI document. */
export type ApiParameters = components["parameters"];

/** Accepts any string while keeping editor completion for the known values. */
type Open<T extends string> = T | (string & {});

export type Ulid = Schemas["Ulid"];
/** ISO-8601 UTC timestamp, e.g. "2026-11-01T09:14:22Z". */
export type IsoDateTime = Schemas["DateTime"];

/** `amount` is a decimal string (never a float); `currency` is ISO-4217. */
export type Money = Schemas["Money"];
export type ConvertedMoney = Schemas["ConvertedMoney"];

/* ------------------------------------------------------------------ */
/* Envelopes & errors                                                  */
/* ------------------------------------------------------------------ */

export type ListMeta = Schemas["ListMeta"];

export interface ListResponse<T, M extends ListMeta = ListMeta> {
  data: T[];
  meta: M;
}

export interface ItemResponse<T> {
  data: T;
}

/** RFC 9457 problem details; 422 `errors` keys use dot notation, e.g. "options.max_results". */
export type ProblemDetails = Schemas["Problem"];

/* ------------------------------------------------------------------ */
/* Searches                                                            */
/* ------------------------------------------------------------------ */

export type Search = Schemas["Search"];
export type SearchStatus = Search["status"];

export const ACTIVE_SEARCH_STATUSES: readonly SearchStatus[] = ["queued", "running"];

export type SearchEngineSelection = Schemas["SearchEngineSelection"];
export type SearchSourcesInput = Schemas["SearchSourcesInput"];
/** Options as sent on POST /searches. */
export type SearchOptionsInput = Schemas["SearchOptionsInput"];
/** Options as resolved and echoed back on a Search (includes `max_candidates_per_source`). */
export type SearchOptions = Schemas["SearchOptions"];
export type Condition = NonNullable<SearchOptionsInput["condition"]>;

/** Body of POST /searches (JSON variant). */
export type CreateSearchRequest = Schemas["CreateSearchRequest"];
/** `Search.input`: the request as stored (the uploaded image is reflected by `product_query.image_phash`). */
export type SearchInput = Schemas["SearchInput"];

export type ProductQuery = Schemas["ProductQuery"];
export type Stats = Schemas["Stats"];

/** Per-source progress entry on a Search (`Search.sources[]`). */
export type SearchSourceProgress = Schemas["SearchSourceStatus"];
export type SearchSourceStatus = SearchSourceProgress["status"];

export type Progress = Search["progress"];

/* ------------------------------------------------------------------ */
/* Offers                                                              */
/* ------------------------------------------------------------------ */

type GeneratedOffer = Schemas["Offer"];

export type MatchBand = GeneratedOffer["match"]["band"];
export type MatchType = Open<"exact_gtin" | "mismatch_gtin" | "scored">;
export type KnownExtractionMethod =
  | "json_ld"
  | "microdata"
  | "meta"
  | "embedded_state"
  | "selector"
  | "llm"
  | "provider"
  | "feed"
  | "html";
export type ExtractionMethod = Open<KnownExtractionMethod>;
export type Availability = Open<
  "in_stock" | "out_of_stock" | "preorder" | "backorder" | "limited" | "discontinued" | "unknown"
>;
export type OfferCondition = Open<"new" | "used" | "refurbished" | "unknown">;

/**
 * Score breakdown. Numeric per-signal scores (gtin, title_similarity, brand,
 * image, variant, …), plus an optional string `reason` and, when the matcher
 * penalised the offer, `hard_negatives`. The spec only declares
 * `number | string` values, so `hard_negatives` is added by hand.
 */
export type MatchBreakdown = {
  reason?: string;
  hard_negatives?: string[];
} & Record<string, number | string | string[] | undefined>;

export interface OfferMatch extends Omit<GeneratedOffer["match"], "breakdown" | "type"> {
  type: MatchType;
  breakdown: MatchBreakdown | null;
}

/** Variant attributes scraped from the listing; `color`, `size`, `storage` are the common keys. */
export type OfferVariant = {
  color?: string | number | null;
  size?: string | number | null;
  storage?: string | number | null;
} & Record<string, unknown>;

export interface Offer extends Omit<GeneratedOffer, "match" | "variant" | "availability" | "condition" | "extraction_method"> {
  availability: Availability;
  condition: OfferCondition;
  extraction_method: ExtractionMethod;
  variant: OfferVariant | null;
  match: OfferMatch;
}

/** `rank` (the API default) = band, non-outliers, in stock, cheapest converted total. */
export type OfferSort = ApiParameters["Offer_sort"];

/** Client-side offer filter state; serialized by `filtersToQuery` (lib/offers.ts). */
export interface OfferFilters {
  band?: MatchBand[];
  availability?: string;
  condition?: string;
  site?: string;
  min_price?: string;
  max_price?: string;
  sort?: OfferSort;
  /** Duplicates are hidden by default. */
  include_duplicates?: boolean;
  /** Outliers are included unless this is explicitly `false`. */
  include_outliers?: boolean;
}

export interface OffersMeta extends Omit<Schemas["OffersMeta"], "stats"> {
  stats: Stats | null;
}

export type OffersResponse = ListResponse<Offer, OffersMeta>;

/* ------------------------------------------------------------------ */
/* Diagnostics                                                         */
/* ------------------------------------------------------------------ */

export type SearchTask = Schemas["SearchTask"];
export type TaskStage = Open<"discover" | "fetch" | "render" | "extract">;
export type TaskStatus = SearchTask["status"];

/* ------------------------------------------------------------------ */
/* Sources                                                             */
/* ------------------------------------------------------------------ */

export type Source = Schemas["Source"];
export type SourceType = Source["type"];
export type RenderMode = Source["render"];
export type HealthStatus = Source["health_status"];

/** Adapter config, see spec §7 "Site Adapter Example". Open-ended on purpose. */
export type SourceConfig = Schemas["SiteAdapterConfig"];

/** Body of POST/PATCH /sources as the UI sends it. */
export type SourceWrite = Partial<
  Pick<Source, "type" | "name" | "domain" | "country" | "currency" | "render" | "enabled">
> & { config?: SourceConfig };

export type SourceTestRequest = Schemas["SourceTestRequest"];
/** `fields` / `field_sources` / `selector_hits` are free-form maps; render defensively. */
export type SourceTestResult = Schemas["SourceTestResult"];

/* ------------------------------------------------------------------ */
/* Tracked products, history, monitors, alerts                         */
/* ------------------------------------------------------------------ */

export type LatestPrice = Schemas["LatestPrice"];
/** `latest_prices` is included on both list and detail responses. */
export type TrackedProduct = Schemas["TrackedProduct"];

/**
 * Tracked-product writes accept a decimal (string or number) or a Money
 * object for `reference_price` / `map_price`. Forms send plain decimals plus
 * `currency`.
 */
export type PriceInput = string | number | Money;

type WithPriceInput<T> = Omit<T, "reference_price" | "map_price"> & {
  reference_price?: PriceInput | null;
  map_price?: PriceInput | null;
};

/** Body of POST /tracked-products. */
export type TrackedProductCreate = WithPriceInput<Schemas["TrackedProductCreateRequest"]>;
/** Body of PATCH /tracked-products/{id}. */
export type TrackedProductWrite = WithPriceInput<Schemas["TrackedProductUpdateRequest"]>;

export type PriceHistoryPoint = Schemas["PriceHistoryPoint"];
export type PriceHistoryResponse = Schemas["PriceHistoryResponse"];
export type PriceHistoryMeta = PriceHistoryResponse["meta"];
export type HistoryInterval = PriceHistoryMeta["interval"];

export type AlertRules = Schemas["AlertRules"];

/** Free-form; known keys are `condition`, `max_results` and `sources`. */
export type MonitorOptions = Schemas["MonitorOptions"];
export type Monitor = Schemas["Monitor"];
export type MonitorStatus = Monitor["status"];
export type MonitorCreate = Schemas["MonitorCreateRequest"];
/** PATCH body. `tracked_product_id` is accepted but ignored by the API. */
export type MonitorWrite = Schemas["MonitorUpdateRequest"] & { tracked_product_id?: Ulid | null };

export type Alert = Schemas["Alert"];
export type AlertType = Alert["type"];
export type AlertPayload = Schemas["AlertPayload"];

/* ------------------------------------------------------------------ */
/* Webhooks                                                            */
/* ------------------------------------------------------------------ */

/** Event names, in display order. Checked against the spec below. */
export const WEBHOOK_EVENTS = [
  "search.started",
  "search.offer_found",
  "search.completed",
  "search.failed",
  "monitor.run_completed",
  "alert.price_drop",
  "alert.price_increase",
  "alert.map_violation",
  "alert.back_in_stock",
  "alert.out_of_stock",
  "alert.new_seller",
  "source.degraded",
  "quota.threshold",
  "ping",
] as const satisfies readonly Schemas["WebhookEventName"][];

/** A webhook event name (`Schemas["WebhookEventName"]`). */
export type WebhookEvent = (typeof WEBHOOK_EVENTS)[number];
/** Endpoints may subscribe to every event with `*`. */
export const ALL_WEBHOOK_EVENTS = "*";
export type WebhookSubscription = WebhookEvent | typeof ALL_WEBHOOK_EVENTS;

// Compile-time guard: fails when the spec adds an event missing from WEBHOOK_EVENTS.
type MissingWebhookEvents = Exclude<Schemas["WebhookEventName"], WebhookEvent>;
const _webhookEventsComplete: [MissingWebhookEvents] extends [never] ? true : MissingWebhookEvents = true;
void _webhookEventsComplete;

/** `secret` is only returned on create and on PATCH with `rotate_secret: true`. */
export type Webhook = Schemas["WebhookEndpoint"];
export type WebhookCreate = Schemas["WebhookEndpointCreateRequest"];
export type WebhookWrite = Schemas["WebhookEndpointUpdateRequest"];
/** `payload` is present only when listing with `?include_payload=1`. */
export type WebhookDelivery = Schemas["WebhookDelivery"];
/** Signed event envelope (`{id, type, created_at, tenant_id, data}`). */
export type WebhookEventEnvelope = Schemas["WebhookEvent"];

/* ------------------------------------------------------------------ */
/* Usage                                                               */
/* ------------------------------------------------------------------ */

export type UsageMetricName = Open<
  "searches" | "render_pages" | "provider_calls" | "active_monitors" | "concurrent_searches"
>;

/** `limits` = raw plan limits (nullable); `features` = feature flags, e.g. `llm-extraction`. */
export type Usage = Schemas["Usage"];

/* ------------------------------------------------------------------ */
/* Realtime (Reverb) payloads                                          */
/* ------------------------------------------------------------------ */

export interface SearchProgressUpdatedEvent {
  search_id: Ulid;
  status: SearchStatus;
  progress: Progress;
  sources: SearchSourceProgress[];
}

export interface OfferFoundEvent {
  offer: Offer;
}

export interface SearchCompletedEvent {
  search_id: Ulid;
  status: SearchStatus;
  stats: Stats | null;
}
