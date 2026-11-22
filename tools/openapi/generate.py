"""Generates openapi.yaml from hand-curated definitions mirroring the Laravel code.

Usage: python3 tools/openapi/generate.py [output-path]   (default: repo-root openapi.yaml; needs PyYAML)
"""
import sys
import yaml

OUT = sys.argv[1] if len(sys.argv) > 1 else __import__("os").path.join(__import__("os").path.dirname(__file__), "../../openapi.yaml")


def ref(name):
    return {"$ref": f"#/components/schemas/{name}"}


def nul(schema):
    """Nullable wrapper (OpenAPI 3.1 / JSON Schema 2020-12)."""
    if "$ref" in schema or "oneOf" in schema or "allOf" in schema:
        return {"oneOf": [schema, {"type": "null"}]}
    s = dict(schema)
    t = s.get("type")
    if isinstance(t, list):
        if "null" not in t:
            s["type"] = t + ["null"]
    else:
        s["type"] = [t, "null"]
    if "enum" in s and None not in s["enum"]:
        s["enum"] = s["enum"] + [None]
    return s


def obj(props, required=None, additional=None, **kw):
    o = {"type": "object"}
    o.update(kw)
    o["properties"] = props
    if required is True:
        o["required"] = list(props.keys())
    elif required:
        o["required"] = required
    if additional is not None:
        o["additionalProperties"] = additional
    return o


STR = {"type": "string"}
INT = {"type": "integer"}
NUM = {"type": "number"}
BOOL = {"type": "boolean"}
ULID = ref("Ulid")
DT = ref("DateTime")
NSTR = nul(STR)
NINT = nul(INT)
NDT = nul(DT)
NULID = nul(ULID)
FREE = {"type": "object", "additionalProperties": True}


def arr(items, **kw):
    a = {"type": "array", "items": items}
    a.update(kw)
    return a


ABILITIES = ["searches:read", "searches:write", "sources:write", "monitors:write", "webhooks:write", "admin"]
EVENTS = ["search.started", "search.offer_found", "search.completed", "search.failed", "monitor.run_completed",
          "alert.price_drop", "alert.price_increase", "alert.map_violation", "alert.back_in_stock",
          "alert.out_of_stock", "alert.new_seller", "source.degraded", "quota.threshold", "ping"]
ALERT_TYPES = ["price_drop", "price_increase", "map_violation", "back_in_stock", "out_of_stock", "new_seller"]
SEARCH_STATUSES = ["queued", "running", "completed", "partially_completed", "failed", "cancelled"]
SOURCE_TYPES = ["site", "search_engine", "shopping_engine", "reverse_image", "feed"]
PROVIDERS = ["serpapi", "brave", "google_vision"]
TASK_STATUSES = ["pending", "running", "succeeded", "failed", "skipped_robots", "skipped_policy", "blocked", "cancelled"]
OFFER_SORTS = ["price", "-price", "total_price", "-total_price", "confidence", "-confidence", "site", "-site",
               "observed_at", "-observed_at", "rank"]
AVAILABILITY = ["in_stock", "out_of_stock", "preorder", "backorder", "limited", "discontinued", "unknown"]
OFFER_CONDITION = ["new", "used", "refurbished", "unknown"]
CONDITION_FILTER = ["new", "refurbished", "used", "any"]
BANDS = ["exact", "likely", "possible", "rejected"]
MATCH_TYPES = ["exact_gtin", "mismatch_gtin", "scored"]

# --------------------------------------------------------------------------------------------------------------------
# Schemas
# --------------------------------------------------------------------------------------------------------------------
schemas = {}

schemas["Ulid"] = {"type": "string", "description": "Public identifier (ULID, Crockford base32, 26 chars).",
                   "pattern": "^[0-9A-HJKMNP-TV-Z]{26}$", "examples": ["01JB7K2M4X9Q8T6V5R3N1P0WZY"]}
schemas["DateTime"] = {"type": "string", "format": "date-time",
                       "description": "ISO-8601 UTC timestamp (Zulu), e.g. `2026-11-01T09:14:22Z`."}
schemas["Money"] = obj({
    "amount": {"type": "string", "description": "Decimal string in major units (never a float), e.g. `329.99`.",
               "pattern": "^-?[0-9]+(\\.[0-9]+)?$", "examples": ["329.99"]},
    "currency": {"type": "string", "description": "ISO 4217 code.", "minLength": 3, "maxLength": 3, "examples": ["USD"]},
}, required=True, description="Monetary amount. Serialized by `App\\Support\\Money::toArray`, which returns `null` when the "
                             "amount or currency is missing; nullable usages wrap this schema.")
schemas["ConvertedMoney"] = {"allOf": [ref("Money"), obj({
    "fx_rate_date": {**NSTR, "format": "date", "description": "Date of the FX rate used for conversion."},
}, required=True, additional=None)], "description": "Money converted into the search's `target_currency`."}

schemas["ListMeta"] = obj({"next_cursor": {**NSTR, "description": "Opaque cursor for the next page; `null` on the last page."}},
                          required=True)

schemas["Problem"] = obj({
    "type": {"type": "string", "format": "uri",
             "description": "Stable error type URI: `{PRICELENS_ERRORS_BASE_URL}{code}` (default base "
                            "`https://docs.pricelens.dev/errors/`). Codes include `validation_failed`, `unauthenticated`, "
                            "`forbidden`, `no_tenant`, `tenant_suspended`, `feature_unavailable`, `global_source_read_only`, "
                            "`not_found`, `rate_limited`, `quota_exceeded`, `concurrency_limit`, `invalid_idempotency_key`, "
                            "`idempotency_conflict`, `idempotency_mismatch`, `server_error`, `http_{status}`.",
             "examples": ["https://docs.pricelens.dev/errors/quota_exceeded"]},
    "title": STR,
    "status": {"type": "integer", "minimum": 100, "maximum": 599},
    "detail": STR,
    "instance": {"type": "string", "description": "Request path, e.g. `/api/v1/searches`."},
    "errors": {"type": "object", "additionalProperties": arr(STR),
               "description": "Present on `validation_failed` (422). Keys use dot notation, e.g. `options.max_results`."},
}, required=["type", "title", "status"], additional=None,
    description="RFC 9457 problem details (`application/problem+json`).")

# --- Searches --------------------------------------------------------------------------------------------------------
schemas["SearchEngineSelection"] = obj({
    "provider": {"type": "string", "enum": PROVIDERS},
    "mode": {"type": "string", "enum": ["web", "shopping"]},
    "scope": nul({"type": "string", "enum": ["open", "allowed_domains"]}),
    "verify": nul(BOOL),
}, required=["provider", "mode"])
schemas["SearchSourcesInput"] = obj({
    "source_ids": nul(arr(ULID, maxItems=100, description="Tenant-owned sources or enabled global sources.")),
    "domains": nul(arr({"type": "string", "description": "Hostname."}, maxItems=100,
                       description="Ad-hoc domains; entries matching the tenant blocklist are silently dropped.")),
    "search_engines": nul(arr(ref("SearchEngineSelection"), maxItems=10,
                              description="Requires the `search-engines` plan feature.")),
    "reverse_image": nul({"type": "boolean", "description": "Requires the `image-search` plan feature."}),
}, description="Sources to query. Total count is capped by the plan's `max_sources_per_search`.")
schemas["SearchOptionsInput"] = obj({
    "country": nul({"type": "string", "minLength": 2, "maxLength": 2, "pattern": "^[A-Za-z]{2}$"}),
    "language": nul({"type": "string", "pattern": "^[a-z]{2}(-[A-Za-z]{2})?$"}),
    "target_currency": nul({"type": "string", "minLength": 3, "maxLength": 3, "description": "ISO 4217 code."}),
    "condition": nul({"type": "string", "enum": CONDITION_FILTER}),
    "max_results": nul({"type": "integer", "minimum": 1, "description": "Maximum is the plan's `max_results_per_search` (default 500)."}),
    "include_possible": nul(BOOL),
    "min_confidence": nul({"type": "number", "minimum": 0, "maximum": 1}),
    "fresh": nul(BOOL),
    "callback_url": nul({"type": "string", "format": "uri", "description": "Public http(s) URL."}),
})
schemas["SearchOptions"] = obj({
    "country": STR, "language": STR, "target_currency": STR,
    "condition": {"type": "string", "enum": CONDITION_FILTER},
    "max_results": INT, "include_possible": BOOL, "min_confidence": NUM, "fresh": BOOL,
    "callback_url": STR, "max_candidates_per_source": INT,
}, additional=True, description="Options after tenant defaults and plan limits are applied (null values omitted).")
schemas["CreateSearchRequest"] = obj({
    "title": nul({"type": "string", "maxLength": 500}),
    "gtin": nul({"type": "string", "description": "GTIN-8/12/13/14 with a valid check digit."}),
    "mpn": nul({"type": "string", "maxLength": 100}),
    "brand": nul({"type": "string", "maxLength": 100}),
    "image_url": nul({"type": "string", "format": "uri", "description": "Public http(s) URL."}),
    "reference_url": nul({"type": "string", "format": "uri", "description": "Public http(s) URL."}),
    "sources": nul(ref("SearchSourcesInput")),
    "options": nul(ref("SearchOptionsInput")),
}, description="At least one of `title`, `image_url`/`image`, `gtin`, `mpn` or `reference_url` is required.")
schemas["CreateSearchMultipartRequest"] = obj({
    "title": {"type": "string", "maxLength": 500},
    "gtin": STR,
    "mpn": {"type": "string", "maxLength": 100},
    "brand": {"type": "string", "maxLength": 100},
    "image_url": {"type": "string", "format": "uri"},
    "image": {"type": "string", "format": "binary", "contentMediaType": "application/octet-stream",
              "description": "JPEG, PNG or WebP, at most 10 MB. Requires the `image-search` plan feature."},
    "reference_url": {"type": "string", "format": "uri"},
    "sources": {"type": "string", "description": "JSON-encoded `SearchSourcesInput`."},
    "options": {"type": "string", "description": "JSON-encoded `SearchOptionsInput`."},
}, description="Multipart variant of `CreateSearchRequest`: nested objects are sent as JSON strings.")
schemas["SearchInput"] = obj({
    "title": STR, "gtin": STR, "mpn": STR, "brand": STR, "image_url": STR, "reference_url": STR,
    "sources": ref("SearchSourcesInput"),
}, additional=True, description="The request input as submitted (null/empty values dropped; upload paths removed).")
schemas["ProductQuery"] = obj({
    "title": NSTR, "cleaned_title": NSTR, "tokens": arr(STR), "brand": NSTR, "model": NSTR, "gtin": NSTR, "mpn": NSTR,
    "image_url": NSTR, "image_phash": NSTR,
    "attributes": {"type": "object", "additionalProperties": {"type": ["string", "integer"]},
                   "description": "Parsed attributes (storage, size, color, pack)."},
    "condition": NSTR, "labels": arr(STR), "reference_url": NSTR,
}, required=True, description="Normalized search input (`image_path` and `visual_matches` are never exposed).")
schemas["Stats"] = obj({
    "currency": {**NSTR, "description": "The search's target currency."},
    "min": NSTR, "p25": NSTR, "median": NSTR, "p75": NSTR, "max": NSTR, "mean": NSTR,
    "offer_count": INT, "site_count": INT, "cheapest_offer_id": NULID,
}, required=True, description="Summary over in-stock exact/likely, non-duplicate, non-outlier offers in the target currency. "
                              "Amounts are decimal strings; all `null` when there are no qualifying offers.")
schemas["SearchSourceStatus"] = obj({
    "source_id": NULID,
    "name": NSTR,
    "domain": NSTR,
    "type": {"type": "string", "enum": SOURCE_TYPES},
    "status": {"type": "string", "enum": ["pending", "running", "completed", "partially_completed", "failed", "cancelled"]},
    "candidates_found": INT,
    "offers_found": INT,
    "error": NSTR,
}, required=True, description="Per-source progress of a search.")
schemas["Search"] = obj({
    "id": ULID,
    "status": {"type": "string", "enum": SEARCH_STATUSES},
    "input": ref("SearchInput"),
    "product_query": nul(ref("ProductQuery")),
    "options": ref("SearchOptions"),
    "target_currency": NSTR,
    "country": NSTR,
    "progress": obj({"total": INT, "done": INT}, required=True),
    "stats": nul(ref("Stats")),
    "error": {**NSTR, "description": "e.g. `no_sources`, `all_tasks_failed`."},
    "sources": arr(ref("SearchSourceStatus")),
    "monitor_id": NULID,
    "started_at": NDT,
    "completed_at": NDT,
    "created_at": NDT,
    "links": obj({"self": STR, "offers": STR, "image": {"type": "string", "description": "Present when the search has an input image; redirects to a short-lived signed URL."}}, required=["self", "offers"]),
    "realtime": obj({"channel": {"type": "string", "description": "Reverb private channel, `private-tenant.{tenantId}.search.{searchUlid}`."}},
                    required=True),
}, required=True)

schemas["Offer"] = obj({
    "id": ULID,
    "site": obj({"domain": STR, "name": NSTR}, required=True),
    "url": STR,
    "title": NSTR,
    "image_url": NSTR,
    "price": ref("Money"),
    "list_price": nul(ref("Money")),
    "shipping": nul(ref("Money")),
    "total": {"allOf": [ref("Money")], "description": "price + shipping, in the offer currency."},
    "converted_total": nul(ref("ConvertedMoney")),
    "availability": {"type": "string", "description": "Normalized availability.", "examples": AVAILABILITY},
    "condition": {"type": "string", "description": "Normalized condition.", "examples": OFFER_CONDITION},
    "seller": NSTR,
    "brand": NSTR,
    "rating": nul(NUM),
    "review_count": NINT,
    "variant": nul(FREE),
    "identifiers": obj({"gtin": NSTR, "mpn": NSTR, "sku": NSTR}, required=True),
    "match": obj({
        "confidence": {"type": "number", "minimum": 0, "maximum": 1},
        "band": {"type": "string", "enum": BANDS},
        "type": {"type": "string", "description": "e.g. `exact_gtin`, `mismatch_gtin`, `scored`.", "examples": MATCH_TYPES},
        "breakdown": nul({"type": "object", "additionalProperties": {"oneOf": [{"type": ["number", "string"]}, {"type": "array", "items": {"type": "string"}}]},
                          "description": "Per-signal scores (e.g. `{\"gtin\": 1.0}`); may contain a string `reason`."}),
    }, required=True),
    "extraction_method": {"type": "string",
                          "description": "e.g. `json_ld`, `microdata`, `meta`, `embedded_state`, `selector`, `llm`, or a provider/feed method."},
    "price_outlier": BOOL,
    "is_duplicate": BOOL,
    "observed_at": NDT,
}, required=True)

schemas["SearchTask"] = obj({
    "id": ULID,
    "source_id": NULID,
    "source_name": NSTR,
    "stage": {"type": "string", "description": "`discover`, `fetch`, `render` or `extract`.",
              "examples": ["discover", "fetch", "render", "extract"]},
    "url": NSTR,
    "status": {"type": "string", "enum": TASK_STATUSES},
    "attempts": INT,
    "http_status": NINT,
    "error_code": NSTR,
    "error_message": NSTR,
    "duration_ms": NINT,
    "created_at": NDT,
}, required=True)

# --- Sources ---------------------------------------------------------------------------------------------------------
schemas["SiteAdapterConfig"] = obj({
    "search_url_template": {"type": "string", "maxLength": 500, "description": "http(s) URL containing `{query}`."},
    "product_url_patterns": arr({"type": "string", "description": "Regex."}, maxItems=20),
    "rate_limit": obj({
        "requests": {"type": "integer", "minimum": 1, "maximum": 20},
        "per_seconds": {"type": "integer", "minimum": 1, "maximum": 3600},
        "concurrency": {"type": "integer", "minimum": 1, "maximum": 10},
    }, additional=True),
    "listing": {"type": "object", "additionalProperties": True,
                "description": "Listing selectors: `item`, `item_link`, `item_title`, `item_price`, `item_image` (strings ≤ 500 chars), `listing_mode` (bool)."},
    "product": {"type": "object", "additionalProperties": True,
                "description": "Field selectors (string, or `{selector, json_path, regex, static}` object) plus `price_regex`. "
                               "Selector syntax: CSS by default, `xpath:` prefix for XPath, `@attr` suffix to read an attribute."},
    "embedded_state": {"type": "object", "additionalProperties": {"type": ["string", "null"], "maxLength": 500}},
    "headers": {"type": "object", "additionalProperties": {"type": "string", "maxLength": 500}, "maxProperties": 10},
    "wait_for_selector": {"type": "string", "maxLength": 500},
    "provider": {"type": "string", "enum": PROVIDERS, "description": "Required for search_engine/shopping_engine/reverse_image."},
    "scope": {"type": "string", "enum": ["open", "allowed_domains"]},
    "allowed_domains": arr(STR, maxItems=100),
    "verify": BOOL,
    "feed_url": {"type": "string", "format": "uri", "description": "Required for feed sources."},
    "format": {"type": "string", "enum": ["csv", "xml"]},
    "refresh_interval": {"type": "integer", "minimum": 15, "maximum": 10080, "description": "Minutes."},
}, additional=True, description="Adapter configuration (spec §7). Loosely typed; known keys are validated. Max 64 KB as JSON.")
schemas["Source"] = obj({
    "id": ULID,
    "type": {"type": "string", "enum": SOURCE_TYPES},
    "name": STR,
    "domain": NSTR,
    "country": NSTR,
    "currency": NSTR,
    "render": {"type": "string", "enum": ["never", "auto", "always"]},
    "enabled": {"type": "boolean", "description": "For global adapters, whether the current tenant has enabled it."},
    "is_global": BOOL,
    "health_status": {"type": "string", "enum": ["healthy", "degraded", "failing"]},
    "config": nul(ref("SiteAdapterConfig")),
    "forked_from_id": NULID,
    "feed_refreshed_at": NDT,
    "created_at": NDT,
    "updated_at": NDT,
}, required=True)
_source_write_props = {
    "name": {"type": "string", "maxLength": 255},
    "type": {"type": "string", "enum": SOURCE_TYPES},
    "domain": nul({"type": "string", "description": "Hostname. Required for `site` sources."}),
    "country": nul({"type": "string", "minLength": 2, "maxLength": 2}),
    "currency": nul({"type": "string", "minLength": 3, "maxLength": 3}),
    "enabled": BOOL,
    "render": {"type": "string", "enum": ["never", "auto", "always"]},
    "config": ref("SiteAdapterConfig"),
}
schemas["SourceCreateRequest"] = {
    "allOf": [ref("SiteAdapterConfig"), obj(_source_write_props, required=["name", "type"], additional=None)],
    "description": "Either send adapter keys inside `config`, or flat at the top level next to `name`/`type` (spec §7 "
                   "example); when `config` is absent every non-top-level key is treated as adapter config.",
}
_upd = {k: v for k, v in _source_write_props.items() if k != "type"}
schemas["SourceUpdateRequest"] = {
    "allOf": [ref("SiteAdapterConfig"), obj(_upd, additional=None)],
    "description": "Partial update. `type` is prohibited. Flat adapter keys are deep-merged into the existing config; an "
                   "explicit `config` replaces it. For global adapters only `enabled` may be sent (otherwise 403 "
                   "`global_source_read_only`).",
}
schemas["SourceTestRequest"] = obj({
    "url": nul({"type": "string", "format": "uri", "description": "Product URL to fetch + extract. Required without `query`."}),
    "query": nul({"type": "string", "maxLength": 300, "description": "Discovery query. Required without `url`."}),
    "render": BOOL,
})
schemas["SourceTestResult"] = obj({
    "http_status": NINT,
    "timing_ms": NINT,
    "extraction_method": NSTR,
    "fields": {"type": "object", "additionalProperties": True,
               "description": "Extracted fields (URL mode) or `{direct_offers: [...]}` (query mode)."},
    "field_sources": {"type": "object", "additionalProperties": True},
    "selector_hits": {"type": "object", "additionalProperties": True},
    "candidates": arr(STR),
    "errors": arr(STR),
}, required=True)

# --- Tracked products / history ---------------------------------------------------------------------------------------
schemas["LatestPrice"] = obj({
    "site_domain": STR, "url": NSTR, "seller": NSTR,
    "price": nul(ref("Money")), "total": nul(ref("Money")),
    "converted_total": nul({"allOf": [ref("Money")], "description": "In the tracked product's currency."}),
    "availability": NSTR, "observed_at": NDT,
}, required=True)
schemas["TrackedProduct"] = obj({
    "id": ULID, "external_ref": NSTR, "title": STR, "brand": NSTR, "gtin": NSTR, "mpn": NSTR, "image_url": NSTR,
    "reference_price": nul(ref("Money")), "map_price": nul(ref("Money")),
    "currency": STR, "attributes": nul(FREE), "archived_at": NDT, "created_at": NDT,
    "latest_prices": arr(ref("LatestPrice"), description="Latest snapshot per site, cheapest first."),
}, required=["id", "external_ref", "title", "brand", "gtin", "mpn", "image_url", "reference_price", "map_price", "currency",
             "attributes", "archived_at", "created_at", "latest_prices"])
_tp = {
    "external_ref": nul({"type": "string", "maxLength": 255, "description": "Unique per tenant."}),
    "title": {"type": "string", "maxLength": 512},
    "brand": nul({"type": "string", "maxLength": 255}),
    "gtin": nul({"type": "string", "description": "Valid GTIN check digit."}),
    "mpn": nul({"type": "string", "maxLength": 255}),
    "image_url": nul({"type": "string", "format": "uri"}),
    "reference_price": nul({"oneOf": [{"type": ["number", "string"]}, ref("Money")],
                            "description": "Decimal amount in `currency` (> 0), or a Money object (its currency is used when `currency` is omitted)."}),
    "map_price": nul({"oneOf": [{"type": ["number", "string"]}, ref("Money")], "description": "Same format as `reference_price`."}),
    "currency": nul({"type": "string", "minLength": 3, "maxLength": 3, "description": "Defaults to the tenant's default currency."}),
    "attributes": nul(FREE),
    "archived": BOOL,
}
schemas["TrackedProductCreateRequest"] = obj(_tp, required=["title"])
schemas["TrackedProductUpdateRequest"] = obj(_tp)
schemas["PriceHistoryPoint"] = obj({
    "period": {"type": "string", "format": "date", "description": "Day, or week start (Monday) for `interval=week`."},
    "site_domain": STR,
    "min": {"type": "string", "description": "Decimal amount in `currency`."},
    "avg": STR, "max": STR,
    "samples": INT,
    "currency": STR,
}, required=True)

# --- Monitors / alerts --------------------------------------------------------------------------------------------------
schemas["AlertRules"] = obj({
    "price_drop_percent": nul({"type": "number", "minimum": 0.1, "maximum": 100}),
    "price_increase_percent": nul({"type": "number", "minimum": 0.1, "maximum": 1000}),
    "target_price": nul({"type": ["number", "string"], "description": "Decimal amount (> 0) in the tracked product currency."}),
    "map_enforcement": nul(BOOL),
    "availability_changes": nul(BOOL),
    "new_sellers": nul(BOOL),
    "cooldown_minutes": nul({"type": "integer", "minimum": 0, "maximum": 43200, "description": "Default 720."}),
}, additional=True)
schemas["MonitorOptions"] = obj({
    "condition": nul({"type": "string", "enum": CONDITION_FILTER}),
    "max_results": nul({"type": "integer", "minimum": 1, "maximum": 500}),
    "sources": nul(FREE),
}, additional=True)
schemas["Monitor"] = obj({
    "id": ULID, "tracked_product_id": NULID,
    "schedule": {"type": "string", "description": "Cron expression."},
    "timezone": STR,
    "source_ids": arr(ULID),
    "options": ref("MonitorOptions"),
    "alert_rules": ref("AlertRules"),
    "status": {"type": "string", "enum": ["active", "paused"]},
    "next_run_at": NDT, "last_run_at": NDT, "created_at": NDT,
}, required=True)
_mon = {
    "tracked_product_id": {**ULID, "description": "Required on create; prohibited on update."},
    "schedule": {"type": "string", "maxLength": 64, "description": "Cron expression; runs at most every 15 minutes."},
    "timezone": {"type": "string", "description": "IANA timezone (default `UTC`)."},
    "source_ids": nul(arr(ULID, maxItems=100)),
    "options": nul(ref("MonitorOptions")),
    "alert_rules": nul(ref("AlertRules")),
    "status": {"type": "string", "enum": ["active", "paused"]},
}
schemas["MonitorCreateRequest"] = obj(_mon, required=["tracked_product_id", "schedule"])
schemas["MonitorUpdateRequest"] = obj({**{k: v for k, v in _mon.items() if k != "tracked_product_id"},
                                      "tracked_product_id": {**ULID, "description": "Accepted and ignored (immutable after creation)."}})
schemas["Alert"] = obj({
    "id": ULID, "monitor_id": NULID, "tracked_product_id": NULID,
    "type": {"type": "string", "enum": ALERT_TYPES},
    "site_domain": NSTR,
    "payload": ref("AlertPayload"),
    "triggered_at": NDT, "acknowledged_at": NDT,
}, required=True)
schemas["AlertPayload"] = obj({
    "site_domain": STR, "url": NSTR, "seller": NSTR,
    "price": nul(ref("Money")), "previous_price": nul(ref("Money")),
    "availability": NSTR,
    "change_percent": {"type": "number", "description": "price_drop / price_increase (percentage rules)."},
    "target_price": {"allOf": [ref("Money")], "description": "price_drop triggered by `target_price`."},
    "map_price": {"allOf": [ref("Money")], "description": "map_violation."},
    "rule": {"type": "string", "description": "Rule variant, e.g. `target`."},
}, additional=True, description="Snapshot details at trigger time; amounts in the tracked product currency.")

# --- Webhooks ---------------------------------------------------------------------------------------------------------
schemas["WebhookEventName"] = {"type": "string", "enum": EVENTS}
schemas["WebhookEndpoint"] = obj({
    "id": ULID, "url": STR,
    "events": arr({"type": "string", "description": "Event name or `*` (all except `search.offer_found`)."}),
    "enabled": BOOL, "failure_count": INT, "disabled_at": NDT, "created_at": NDT,
    "secret": {"type": "string", "description": "`whsec_...` signing secret. Only returned on create and when `rotate_secret` is true."},
}, required=["id", "url", "events", "enabled", "failure_count", "disabled_at", "created_at"])
_wh_events = arr({"type": "string", "enum": EVENTS + ["*"]}, minItems=1)
schemas["WebhookEndpointCreateRequest"] = obj({
    "url": {"type": "string", "format": "uri", "description": "Public URL; must be https in production."},
    "events": _wh_events,
    "enabled": BOOL,
    "rotate_secret": BOOL,
}, required=["url", "events"])
schemas["WebhookEndpointUpdateRequest"] = obj({
    "url": {"type": "string", "format": "uri"},
    "events": _wh_events,
    "enabled": {"type": "boolean", "description": "Setting `true` also resets `failure_count` and `disabled_at`."},
    "rotate_secret": {"type": "boolean", "description": "Generates a new secret, returned once in `secret`."},
})
schemas["WebhookDelivery"] = obj({
    "id": ULID, "event": STR, "event_id": STR,
    "status": {"type": "string", "enum": ["pending", "delivered", "failed"]},
    "attempts": INT, "response_status": NINT, "response_body_excerpt": NSTR,
    "next_attempt_at": NDT, "delivered_at": NDT, "created_at": NDT,
    "payload": {"allOf": [ref("WebhookEvent")], "description": "Only present when `include_payload=true`."},
    "target_url": {"type": "string", "description": "Search callback deliveries only (`options.callback_url`)."},
}, required=["id", "event", "event_id", "status", "attempts", "response_status", "response_body_excerpt",
             "next_attempt_at", "delivered_at", "created_at"])
schemas["WebhookEvent"] = obj({
    "id": {"type": "string", "description": "`evt_` + lowercase ULID; stable across retries/redeliveries.",
           "examples": ["evt_01jb7k2m4x9q8t6v5r3n1p0wzy"]},
    "type": ref("WebhookEventName"),
    "created_at": DT,
    "tenant_id": ULID,
    "data": FREE,
}, required=True, description="Webhook envelope POSTed to subscribed endpoints.")


def event_schema(name, data):
    return {"allOf": [ref("WebhookEvent"), obj({"type": {"const": name}, "data": data}, required=["type", "data"], additional=None)]}


schemas["SearchStartedData"] = obj({"search_id": ULID, "sources": arr(ref("SearchSourceStatus")),
                                    "product_query": ref("ProductQuery")}, required=True)
schemas["OfferFoundData"] = obj({"search_id": ULID, "offer": ref("Offer")}, required=True)
schemas["SearchFinishedData"] = obj({"search_id": ULID, "status": {"type": "string", "enum": SEARCH_STATUSES},
                                     "stats": nul(ref("Stats")), "error": NSTR, "monitor_id": NULID}, required=True)
schemas["MonitorRunCompletedData"] = obj({"monitor_id": ULID, "tracked_product_id": NULID, "search_id": ULID,
                                          "snapshots_written": INT, "alerts_triggered": INT, "stats": nul(ref("Stats"))},
                                         required=True)
schemas["AlertEventData"] = {"allOf": [ref("AlertPayload"), obj({"alert_id": ULID, "monitor_id": ULID, "tracked_product_id": ULID,
                                                                 "external_ref": NSTR},
                                                                required=["alert_id", "monitor_id", "tracked_product_id", "external_ref"],
                                                                additional=None)]}
schemas["SourceDegradedData"] = obj({"source_id": ULID, "name": STR, "domain": NSTR,
                                     "health_status": {"type": "string", "enum": ["failing"]},
                                     "extraction_success_rate": {"type": "number", "minimum": 0, "maximum": 1},
                                     "auto_paused": {"type": "boolean", "description": "True for global adapters (paused pending review)."}},
                                    required=True)
schemas["QuotaThresholdData"] = obj({"metric": STR, "percent": {"type": "integer", "enum": [80, 100]}, "used": INT, "limit": INT,
                                     "period": obj({"start": {"type": "string", "format": "date-time"},
                                                    "end": {"type": "string", "format": "date-time"}}, required=True)},
                                    required=True)
schemas["PingData"] = obj({"message": STR, "endpoint_id": ULID}, required=True)

# --- API keys / usage -------------------------------------------------------------------------------------------------
schemas["Ability"] = {"type": "string", "enum": ABILITIES}
schemas["ApiKey"] = obj({
    "id": ULID, "name": STR,
    "prefix": {"type": "string", "description": "`pl_live_{prefix}` (non-secret part of the key)."},
    "abilities": arr(ref("Ability")),
    "last_used_at": NDT, "expires_at": NDT, "revoked_at": NDT, "created_at": NDT,
    "key": {"type": "string", "description": "Full plaintext key `pl_live_{prefix}_{secret}`; only returned once, on create."},
}, required=["id", "name", "prefix", "abilities", "last_used_at", "expires_at", "revoked_at", "created_at"])
schemas["ApiKeyCreateRequest"] = obj({
    "name": {"type": "string", "maxLength": 255},
    "abilities": arr(ref("Ability"), minItems=1),
    "expires_at": nul({"type": "string", "format": "date-time", "description": "Must be in the future."}),
}, required=["name", "abilities"])
schemas["Usage"] = obj({
    "period": obj({"start": DT, "end": DT}, required=True),
    "plan": obj({"code": STR, "name": STR}, required=True),
    "metrics": arr(obj({
        "metric": {"type": "string", "examples": ["searches", "render_pages", "provider_calls", "active_monitors", "concurrent_searches"]},
        "used": INT,
        "limit": {**NINT, "description": "`null` = unlimited."},
    }, required=True)),
    "limits": {"type": ["object", "null"], "additionalProperties": True, "description": "Raw plan limits."},
    "features": {"type": "object", "additionalProperties": BOOL,
                 "description": "Keys: `llm-extraction`, `image-search`, `monitors`, `search-engines`, `byo-proxies`."},
}, required=True)

# --- Misc -------------------------------------------------------------------------------------------------------------
schemas["BroadcastAuthRequest"] = obj({
    "socket_id": {"type": "string", "pattern": "^\\d+\\.\\d+$"},
    "channel_name": {"type": "string", "maxLength": 200, "description": "`private-tenant.{tenantId}.search.{searchUlid}`."},
}, required=True)
schemas["Health"] = obj({"status": {"type": "string", "const": "ok"}}, required=True)
_check = obj({"ok": BOOL, "error": STR}, required=["ok"])
schemas["Readiness"] = obj({
    "status": {"type": "string", "enum": ["ready", "not_ready"]},
    "checks": obj({
        "database": _check, "cache": _check, "renderer": _check,
        "queues": obj({"ok": BOOL, "depth": {"type": "object", "additionalProperties": NINT}}, required=True),
    }, required=True),
}, required=True)



# --- Dashboard auth / members / callbacks -----------------------------------------------------------------------------
schemas["TenantMembership"] = obj({"id": ULID, "name": STR, "slug": STR, "role": ref("Role"), "plan": NSTR}, required=True)
schemas["Role"] = {"type": "string", "enum": ["owner", "admin", "member", "viewer"]}
schemas["UserProfile"] = obj({"id": ULID, "name": STR, "email": {"type": "string", "format": "email"},
                              "current_tenant_id": NULID, "tenants": arr(ref("TenantMembership"))}, required=True)
schemas["UserProfileResponse"] = obj({"data": ref("UserProfile")}, required=True)
schemas["LoginRequest"] = obj({"email": {"type": "string", "format": "email"}, "password": STR}, required=True)
schemas["TokenRequest"] = obj({"email": {"type": "string", "format": "email"}, "password": STR,
                               "device_name": {"type": "string", "maxLength": 255}}, required=True)
schemas["TokenResponse"] = obj({"data": obj({"token": {"type": "string", "description": "Sanctum personal access token (Bearer)."},
                                             "expires_at": NDT, "user": ref("UserProfile")}, required=True)}, required=True)
schemas["RegisterRequest"] = obj({"name": STR, "email": {"type": "string", "format": "email"}, "password": {"type": "string", "minLength": 12},
                                  "password_confirmation": STR, "company": STR}, required=True)
schemas["RegisterResponse"] = obj({"data": obj({"user": ref("UserProfile"), "token": STR,
                                                "api_key": {"type": "string", "description": "Admin API key, shown once."}}, required=True)}, required=True)
schemas["ForgotPasswordRequest"] = obj({"email": {"type": "string", "format": "email"}}, required=True)
schemas["ResetPasswordRequest"] = obj({"token": STR, "email": {"type": "string", "format": "email"},
                                       "password": {"type": "string", "minLength": 12}, "password_confirmation": STR}, required=True)
schemas["SwitchTenantRequest"] = obj({"tenant_id": ULID}, required=True)
schemas["StatusMessage"] = obj({"data": obj({"status": STR}, required=True)}, required=True)
schemas["Member"] = obj({"id": ULID, "name": STR, "email": STR, "role": ref("Role"), "joined_at": NDT}, required=True)
schemas["MemberResponse"] = obj({"data": ref("Member")}, required=True)
schemas["MemberList"] = obj({"data": arr(ref("Member"))}, required=True)
schemas["MemberCreateRequest"] = obj({"email": {"type": "string", "format": "email"}, "name": NSTR, "role": ref("Role")},
                                     required=["email", "role"])
schemas["MemberUpdateRequest"] = obj({"role": ref("Role")}, required=True)
schemas["CallbackSecretResponse"] = obj({"data": obj({"secret": {"type": "string", "description": "`cbsec_…`, shown once."}}, required=True)},
                                        required=True)

# Envelopes
def env(name):
    schemas[f"{name}Response"] = obj({"data": ref(name)}, required=True)


def lst(name, meta="ListMeta"):
    schemas[f"{name}List"] = obj({"data": arr(ref(name)), "meta": ref(meta)}, required=True)


for n in ["Search", "Source", "TrackedProduct", "Monitor", "Alert", "WebhookEndpoint", "WebhookDelivery", "ApiKey", "Usage",
          "SourceTestResult"]:
    env(n)
for n in ["Search", "Source", "TrackedProduct", "Monitor", "Alert", "WebhookEndpoint", "WebhookDelivery", "ApiKey"]:
    lst(n)
schemas["OffersMeta"] = obj({"stats": nul(ref("Stats")), "next_cursor": {**NSTR, "description": "Opaque base64 offset cursor."}},
                            required=True)
lst("Offer", "OffersMeta")
schemas["SearchTaskList"] = obj({"data": arr(ref("SearchTask"), maxItems=1000)}, required=True,
                                description="Up to 1000 tasks ordered by id; not paginated (no `meta`).")
schemas["PriceHistoryResponse"] = obj({
    "data": arr(ref("PriceHistoryPoint")),
    "meta": obj({"from": DT, "to": DT, "interval": {"type": "string", "enum": ["day", "week"]},
                 "source": {"type": "string", "enum": ["price_snapshots", "price_daily"],
                            "description": "Ranges over 90 days read the nightly `price_daily` rollup."}}, required=True),
}, required=True)
schemas["ImportQueuedResponse"] = obj({"data": obj({"status": {"type": "string", "const": "queued"}}, required=True)}, required=True)
schemas["BroadcastAuthResponse"] = obj({"auth": {"type": "string", "description": "`{reverbKey}:{hmacSignature}`."}}, required=True)

# --------------------------------------------------------------------------------------------------------------------
# Parameters, headers, responses
# --------------------------------------------------------------------------------------------------------------------
H = lambda n: {"$ref": f"#/components/headers/{n}"}
P = lambda n: {"$ref": f"#/components/parameters/{n}"}
R = lambda n: {"$ref": f"#/components/responses/{n}"}

headers = {
    "X-Request-Id": {"description": "Request correlation id (echoes a valid inbound `X-Request-Id`, else a generated UUID).",
                     "schema": STR},
    "X-RateLimit-Limit": {"description": "Requests allowed per minute.", "schema": INT},
    "X-RateLimit-Remaining": {"description": "Requests remaining in the current window.", "schema": INT},
    "Retry-After": {"description": "Seconds to wait before retrying.", "schema": INT},
    "Idempotent-Replayed": {"description": "`true` when this response was replayed for a repeated `Idempotency-Key`.",
                            "schema": {"type": "string", "enum": ["true"]}},
    "Location": {"description": "URL of the created search.", "schema": STR},
}

parameters = {
    "Cursor": {"name": "cursor", "in": "query", "required": False, "description": "Opaque cursor from `meta.next_cursor`.",
               "schema": STR},
    "Limit": {"name": "limit", "in": "query", "required": False, "description": "Page size (1–100, default 25; out-of-range values are clamped).",
              "schema": {"type": "integer", "minimum": 1, "maximum": 100, "default": 25}},
    "OffersLimit": {"name": "limit", "in": "query", "required": False, "description": "Page size (1–100, default 50).",
                    "schema": {"type": "integer", "minimum": 1, "maximum": 100, "default": 50}},
    "IdempotencyKey": {"name": "Idempotency-Key", "in": "header", "required": False,
                       "description": "Replays the first response for 24 h for the same tenant, method and path. Max 255 chars. "
                                      "Reusing a key with a different body → 422 `idempotency_mismatch`; concurrent use → 409.",
                       "schema": {"type": "string", "maxLength": 255}},
    "SearchId": {"name": "search", "in": "path", "required": True, "schema": ULID},
    "MemberId": {"name": "member", "in": "path", "required": True, "schema": ULID, "description": "User id."},
    "SourceId": {"name": "source", "in": "path", "required": True, "schema": ULID},
    "TrackedProductId": {"name": "trackedProduct", "in": "path", "required": True, "schema": ULID},
    "MonitorId": {"name": "monitor", "in": "path", "required": True, "schema": ULID},
    "AlertId": {"name": "alert", "in": "path", "required": True, "schema": ULID},
    "WebhookId": {"name": "webhook", "in": "path", "required": True, "schema": ULID},
    "DeliveryId": {"name": "delivery", "in": "path", "required": True, "schema": ULID},
    "ApiKeyId": {"name": "apiKey", "in": "path", "required": True, "schema": ULID},
    "IncludePayload": {"name": "include_payload", "in": "query", "required": False,
                       "description": "Include the event envelope in `payload`.", "schema": BOOL},
}
OFFER_FILTERS = [
    {"name": "band", "in": "query", "description": "Comma-separated match bands (`exact,likely,possible,rejected`). Default: "
                                                   "`exact,likely` (+`possible` when the search set `include_possible`), filtered by `min_confidence`.",
     "schema": {"type": "string", "maxLength": 64}},
    {"name": "availability", "in": "query", "description": "Comma-separated availability values.", "schema": {"type": "string", "maxLength": 128}},
    {"name": "condition", "in": "query", "description": "Comma-separated condition values.", "schema": {"type": "string", "maxLength": 64}},
    {"name": "site", "in": "query", "description": "Comma-separated site domains.", "schema": {"type": "string", "maxLength": 255}},
    {"name": "min_price", "in": "query", "description": "Minimum converted total (target currency).", "schema": {"type": "number", "minimum": 0}},
    {"name": "max_price", "in": "query", "description": "Maximum converted total (target currency).", "schema": {"type": "number", "minimum": 0}},
    {"name": "sort", "in": "query", "description": "Default `rank` (band, non-outliers, in stock, cheapest converted total).",
     "schema": {"type": "string", "enum": OFFER_SORTS, "default": "rank"}},
    {"name": "include_duplicates", "in": "query", "schema": {"type": "boolean", "default": False}},
    {"name": "include_outliers", "in": "query", "description": "Outliers are included unless this is explicitly `false`.", "schema": BOOL},
]
for p in OFFER_FILTERS:
    p["required"] = False
    parameters["Offer_" + p["name"]] = p


def problem_response(desc, example_code, status, title):
    return {"description": desc, "headers": {"X-Request-Id": H("X-Request-Id")},
            "content": {"application/problem+json": {"schema": ref("Problem"), "example": {
                "type": f"https://docs.pricelens.dev/errors/{example_code}", "title": title, "status": status,
                "instance": "/api/v1/searches"}}}}


responses = {
    "BadRequest": problem_response("Invalid `Idempotency-Key` (`invalid_idempotency_key`).", "invalid_idempotency_key", 400,
                                   "Invalid Idempotency-Key"),
    "Unauthenticated": problem_response("Missing, invalid or revoked API key (`unauthenticated`).", "unauthenticated", 401,
                                        "Authentication required"),
    "Forbidden": problem_response("Missing ability (`forbidden`), no active tenant (`no_tenant`), suspended tenant "
                                  "(`tenant_suspended`) or plan feature unavailable (`feature_unavailable`).", "forbidden", 403,
                                  "Missing ability"),
    "NotFound": problem_response("Resource not found or belongs to another tenant (`not_found`).", "not_found", 404,
                                 "Resource not found"),
    "Conflict": problem_response("A request with the same `Idempotency-Key` is still in progress (`idempotency_conflict`).",
                                 "idempotency_conflict", 409, "Request in progress"),
    "ValidationFailed": {"description": "Validation failed (`validation_failed`, with `errors`) or `Idempotency-Key` reused with a "
                                        "different request (`idempotency_mismatch`).",
                         "headers": {"X-Request-Id": H("X-Request-Id")},
                         "content": {"application/problem+json": {"schema": ref("Problem"), "example": {
                             "type": "https://docs.pricelens.dev/errors/validation_failed", "title": "The request is invalid",
                             "status": 422, "detail": "The options.max_results field must not be greater than 500.",
                             "instance": "/api/v1/searches",
                             "errors": {"options.max_results": ["The options.max_results field must not be greater than 500."]}}}}},
    "TooManyRequests": {"description": "Rate limited (`rate_limited`), or plan quota/concurrency exceeded (`quota_exceeded`, "
                                       "`concurrency_limit`).",
                        "headers": {"X-Request-Id": H("X-Request-Id"), "Retry-After": H("Retry-After"),
                                    "X-RateLimit-Limit": H("X-RateLimit-Limit"), "X-RateLimit-Remaining": H("X-RateLimit-Remaining")},
                        "content": {"application/problem+json": {"schema": ref("Problem"), "example": {
                            "type": "https://docs.pricelens.dev/errors/rate_limited", "title": "Too many requests", "status": 429,
                            "detail": "Rate limit exceeded; retry after the time given in Retry-After.", "instance": "/api/v1/searches"}}}},
}

# --------------------------------------------------------------------------------------------------------------------
# Operations
# --------------------------------------------------------------------------------------------------------------------
paths = {}


def std_headers(post):
    h = {"X-Request-Id": H("X-Request-Id"), "X-RateLimit-Limit": H("X-RateLimit-Limit"),
         "X-RateLimit-Remaining": H("X-RateLimit-Remaining")}
    if post:
        h["Idempotent-Replayed"] = H("Idempotent-Replayed")
    return h


def op(method, path, opid, summary, tag, ability, *, desc="", params=None, body=None, ok=None, path_params=None,
       validates=False, extra_responses=None, query=None):
    method = method.lower()
    post = method == "post"
    o = {"operationId": opid, "summary": summary, "tags": [tag]}
    d = (desc + "\n\n" if desc else "") + f"Requires ability: `{ability}`."
    o["description"] = d
    o["x-ability"] = ability
    plist = []
    if post:
        plist.append(P("IdempotencyKey"))
    plist += params or []
    if plist:
        o["parameters"] = plist
    if body:
        o["requestBody"] = body
    res = {}
    for status, spec in ok.items():
        spec = dict(spec)
        spec["headers"] = {**std_headers(post), **spec.get("headers", {})}
        res[str(status)] = spec
    if post:
        res["400"] = R("BadRequest")
    res["401"] = R("Unauthenticated")
    res["403"] = R("Forbidden")
    if path_params or "{" in path:
        res["404"] = R("NotFound")
    if post:
        res["409"] = R("Conflict")
    if validates or post:
        res["422"] = R("ValidationFailed")
    res["429"] = R("TooManyRequests")
    for k, v in (extra_responses or {}).items():
        res[str(k)] = v
    o["responses"] = dict(sorted(res.items()))
    item = paths.setdefault(path, {})
    # path params
    import re
    names = re.findall(r"\{(\w+)\}", path)
    if names and "parameters" not in item:
        pmap = {"search": "SearchId", "source": "SourceId", "trackedProduct": "TrackedProductId", "monitor": "MonitorId",
                "alert": "AlertId", "webhook": "WebhookId", "delivery": "DeliveryId", "apiKey": "ApiKeyId",
                "member": "MemberId"}
        item["parameters"] = [P(pmap[n]) for n in names]
    item[method] = o


def json_ok(desc, schema_name, extra_headers=None):
    r = {"description": desc, "content": {"application/json": {"schema": ref(schema_name)}}}
    if extra_headers:
        r["headers"] = extra_headers
    return r


NO_CONTENT = {204: {"description": "Deleted."}}
PAGE = [P("Cursor"), P("Limit")]


def jbody(schema_name, required=True):
    return {"required": required, "content": {"application/json": {"schema": ref(schema_name)}}}


def q(name, schema, desc=None):
    p = {"name": name, "in": "query", "required": False, "schema": schema}
    if desc:
        p["description"] = desc
    return p


# Health (unauthenticated)
paths["/health"] = {"get": {
    "operationId": "getHealth", "summary": "Liveness probe", "tags": ["Health"], "security": [],
    "description": "Unauthenticated.",
    "responses": {"200": {"description": "Alive.", "headers": {"X-Request-Id": H("X-Request-Id")},
                          "content": {"application/json": {"schema": ref("Health")}}}}}}
paths["/ready"] = {"get": {
    "operationId": "getReadiness", "summary": "Readiness probe", "tags": ["Health"], "security": [],
    "description": "Unauthenticated. Checks DB, cache/Redis, renderer (informational) and queue depth. Ready when DB and cache are OK.",
    "responses": {
        "200": {"description": "Ready.", "headers": {"X-Request-Id": H("X-Request-Id")},
                "content": {"application/json": {"schema": ref("Readiness")}}},
        "503": {"description": "Not ready.", "headers": {"X-Request-Id": H("X-Request-Id")},
                "content": {"application/json": {"schema": ref("Readiness")}}}}}}

op("post", "/broadcasting/auth", "authorizeBroadcastChannel", "Authorize a Reverb private channel", "Realtime", "searches:read",
   desc="Pusher-protocol private-channel auth. The channel must be `private-tenant.{tenantId}.search.{searchUlid}` for the "
        "caller's tenant and an existing search, otherwise 403 `forbidden` (`Channel access denied`).",
   body={"required": True, "content": {"application/json": {"schema": ref("BroadcastAuthRequest")},
                                       "application/x-www-form-urlencoded": {"schema": ref("BroadcastAuthRequest")}}},
   ok={200: json_ok("Channel authorized.", "BroadcastAuthResponse")})

# Searches
op("get", "/searches", "listSearches", "List searches", "Searches", "searches:read", validates=True,
   params=PAGE + [q("status", {"type": "string", "enum": SEARCH_STATUSES}),
                  q("created_from", {"type": "string", "format": "date-time"}, "Inclusive lower bound on `created_at` (any parseable date)."),
                  q("created_to", {"type": "string", "format": "date-time"}, "Inclusive upper bound on `created_at`."),
                  q("monitor_id", ULID, "Only searches started by this monitor.")],
   ok={200: json_ok("Searches, newest first.", "SearchList")})
op("post", "/searches", "createSearch", "Create a search", "Searches", "searches:write",
   desc="Accepts JSON or `multipart/form-data` (with an `image` upload). Returns `202` with a `Location` header. Subject to "
        "the monthly search quota and concurrent search limit (429 `quota_exceeded` / `concurrency_limit`). An "
        "`Idempotency-Key` also de-duplicates at the search level (same key → same search).",
   body={"required": True, "content": {
       "application/json": {"schema": ref("CreateSearchRequest")},
       "multipart/form-data": {"schema": ref("CreateSearchMultipartRequest"),
                               "encoding": {"sources": {"contentType": "application/json"},
                                            "options": {"contentType": "application/json"},
                                            "image": {"contentType": "image/jpeg, image/png, image/webp"}}}}},
   ok={202: json_ok("Search accepted and queued.", "SearchResponse", {"Location": H("Location")})})
op("get", "/searches/{search}", "getSearch", "Get a search", "Searches", "searches:read",
   desc="Status, progress, per-source progress and stats.",
   ok={200: json_ok("The search.", "SearchResponse")})
op("get", "/searches/{search}/offers", "listSearchOffers", "List offers of a search", "Searches", "searches:read", validates=True,
   desc="Offset-based pagination with an opaque base64 `cursor`. Responses for terminal searches are cached. `meta.stats` "
        "repeats the search's summary statistics.",
   params=[P("Cursor"), P("OffersLimit")] + [P("Offer_" + p["name"]) for p in OFFER_FILTERS],
   ok={200: json_ok("Offers.", "OfferList")})
op("get", "/searches/{search}/tasks", "listSearchTasks", "List search task diagnostics", "Searches", "searches:read",
   params=[q("stage", {"type": "string"}, "Filter by stage (`discover`, `fetch`, `render`, `extract`)."),
           q("status", {"type": "string", "enum": TASK_STATUSES})],
   ok={200: json_ok("Tasks (max 1000, oldest first).", "SearchTaskList")})
op("get", "/searches/{search}/export", "exportSearchOffers", "Export offers as CSV", "Searches", "searches:read", validates=True,
   desc="Streams a CSV download (`search-{id}.csv`) with the same filters/sort as the offers list. Columns: offer_id, "
        "site_domain, site_name, title, url, price, list_price, shipping, total, currency, converted_total, target_currency, "
        "availability, condition, seller, gtin, mpn, sku, match_band, match_confidence, match_type, extraction_method, "
        "price_outlier, observed_at. Cells starting with `= + - @` are prefixed with `'`.",
   params=[q("format", {"type": "string", "enum": ["csv"], "default": "csv"})] + [P("Offer_" + p["name"]) for p in OFFER_FILTERS],
   ok={200: {"description": "CSV stream.",
             "headers": {"Content-Disposition": {"description": "`attachment; filename=search-{id}.csv`", "schema": STR}},
             "content": {"text/csv": {"schema": {"type": "string"}}}}})
op("post", "/searches/{search}/cancel", "cancelSearch", "Cancel a search", "Searches", "searches:write",
   desc="Cancels the batch and finalizes the search. No-op for terminal searches.",
   ok={200: json_ok("The (cancelled) search.", "SearchResponse")})
op("post", "/searches/{search}/rerun", "rerunSearch", "Re-run a search", "Searches", "searches:write",
   desc="Creates a new search with identical input and options (quota rules apply).",
   ok={202: json_ok("The new search.", "SearchResponse")})

# Sources
op("get", "/sources", "listSources", "List tenant sources", "Sources", "searches:read", validates=True,
   params=PAGE + [q("type", {"type": "string", "enum": SOURCE_TYPES}), q("enabled", BOOL)],
   ok={200: json_ok("Tenant-owned sources.", "SourceList")})
op("post", "/sources", "createSource", "Create a source", "Sources", "sources:write",
   desc="Feed sources are refreshed in the background right after creation.",
   body=jbody("SourceCreateRequest"), ok={201: json_ok("Created.", "SourceResponse")})
op("get", "/sources/global", "listGlobalSources", "Browse the global adapter library", "Sources", "searches:read",
   params=PAGE + [q("type", {"type": "string", "enum": SOURCE_TYPES}), q("q", STR, "Substring match on name or domain.")],
   ok={200: json_ok("Global adapters.", "SourceList")})
op("get", "/sources/{source}", "getSource", "Get a source", "Sources", "searches:read",
   ok={200: json_ok("The source.", "SourceResponse")})
op("patch", "/sources/{source}", "updateSource", "Update a source", "Sources", "sources:write",
   desc="For global adapters only `enabled` (tenant opt-in/out) may be changed; anything else → 403 `global_source_read_only`.",
   body=jbody("SourceUpdateRequest"), validates=True, ok={200: json_ok("Updated.", "SourceResponse")})
op("delete", "/sources/{source}", "deleteSource", "Delete a source", "Sources", "sources:write", ok=NO_CONTENT)
op("post", "/sources/{source}/fork", "forkSource", "Fork a source", "Sources", "sources:write",
   desc="Copies a global (or tenant) adapter into a tenant-owned source.",
   ok={201: json_ok("The fork.", "SourceResponse")})
op("post", "/sources/{source}/test", "testSource", "Dry-run an adapter", "Sources", "sources:write",
   desc="Runs the adapter against a URL (fetch + extract) or a query (discovery). Additionally rate limited to 30/min per tenant.",
   body=jbody("SourceTestRequest"), ok={200: json_ok("Test result.", "SourceTestResultResponse")})

# Tracked products
op("get", "/tracked-products", "listTrackedProducts", "List tracked products", "Tracked products", "searches:read",
   params=PAGE + [q("archived", BOOL, "Include archived products."),
                  q("q", STR, "Title substring, or exact external_ref / gtin / mpn.")],
   ok={200: json_ok("Tracked products (without `latest_prices`).", "TrackedProductList")})
op("post", "/tracked-products", "createTrackedProduct", "Create a tracked product", "Tracked products", "monitors:write",
   body=jbody("TrackedProductCreateRequest"), ok={201: json_ok("Created.", "TrackedProductResponse")})
op("post", "/tracked-products/import", "importTrackedProducts", "Bulk import tracked products from CSV", "Tracked products",
   "monitors:write", desc="Queues a CSV import.",
   body={"required": True, "content": {"multipart/form-data": {"schema": obj({"file": {
       "type": "string", "format": "binary", "contentMediaType": "text/csv", "description": "CSV file, at most 20 MB."}},
       required=["file"])}}},
   ok={202: json_ok("Import queued.", "ImportQueuedResponse")})
op("get", "/tracked-products/{trackedProduct}", "getTrackedProduct", "Get a tracked product", "Tracked products", "searches:read",
   ok={200: json_ok("The tracked product with `latest_prices`.", "TrackedProductResponse")})
op("patch", "/tracked-products/{trackedProduct}", "updateTrackedProduct", "Update a tracked product", "Tracked products",
   "monitors:write", body=jbody("TrackedProductUpdateRequest"), validates=True,
   ok={200: json_ok("Updated.", "TrackedProductResponse")})
op("delete", "/tracked-products/{trackedProduct}", "deleteTrackedProduct", "Delete a tracked product", "Tracked products",
   "monitors:write", ok=NO_CONTENT)
op("get", "/tracked-products/{trackedProduct}/price-history", "getTrackedProductPriceHistory", "Price history",
   "Tracked products", "searches:read", validates=True,
   desc="Min/avg/max per site and period, in the tracked product currency. Default range: last 30 days.",
   params=[q("site", {"type": "string", "maxLength": 255}, "Comma-separated site domains."),
           q("from", {"type": "string", "format": "date"}), q("to", {"type": "string", "format": "date"}),
           q("interval", {"type": "string", "enum": ["day", "week"], "default": "day"})],
   ok={200: json_ok("History.", "PriceHistoryResponse")})

# Monitors
op("get", "/monitors", "listMonitors", "List monitors", "Monitors", "searches:read",
   params=PAGE + [q("status", {"type": "string", "enum": ["active", "paused"]}), q("tracked_product_id", ULID)],
   ok={200: json_ok("Monitors.", "MonitorList")})
op("post", "/monitors", "createMonitor", "Create a monitor", "Monitors", "monitors:write",
   desc="Activating requires the `monitors` feature (403 `feature_unavailable`) and respects the plan's `active_monitors` "
        "limit (429 `quota_exceeded`).",
   body=jbody("MonitorCreateRequest"), ok={201: json_ok("Created.", "MonitorResponse")})
op("get", "/monitors/{monitor}", "getMonitor", "Get a monitor", "Monitors", "searches:read",
   ok={200: json_ok("The monitor.", "MonitorResponse")})
op("patch", "/monitors/{monitor}", "updateMonitor", "Update a monitor", "Monitors", "monitors:write",
   body=jbody("MonitorUpdateRequest"), validates=True, ok={200: json_ok("Updated.", "MonitorResponse")})
op("delete", "/monitors/{monitor}", "deleteMonitor", "Delete a monitor", "Monitors", "monitors:write", ok=NO_CONTENT)
op("post", "/monitors/{monitor}/run", "runMonitor", "Run a monitor now", "Monitors", "monitors:write",
   desc="Starts a search for the monitor immediately (search quota rules apply).",
   ok={202: json_ok("The started search.", "SearchResponse")})

# Alerts
op("get", "/alerts", "listAlerts", "List alerts", "Alerts", "searches:read", validates=True,
   params=PAGE + [q("type", {"type": "string", "enum": ALERT_TYPES}), q("tracked_product_id", ULID), q("monitor_id", ULID),
                  q("acknowledged", BOOL)],
   ok={200: json_ok("Alerts.", "AlertList")})
op("post", "/alerts/{alert}/acknowledge", "acknowledgeAlert", "Acknowledge an alert", "Alerts", "monitors:write",
   desc="Idempotent: keeps the first `acknowledged_at`.", ok={200: json_ok("The alert.", "AlertResponse")})

# Webhooks
op("get", "/webhooks", "listWebhooks", "List webhook endpoints", "Webhooks", "webhooks:write", params=PAGE,
   ok={200: json_ok("Endpoints.", "WebhookEndpointList")})
op("post", "/webhooks", "createWebhook", "Create a webhook endpoint", "Webhooks", "webhooks:write",
   desc="The signing `secret` is returned only in this response.",
   body=jbody("WebhookEndpointCreateRequest"), ok={201: json_ok("Created (includes `secret`).", "WebhookEndpointResponse")})
op("get", "/webhooks/{webhook}", "getWebhook", "Get a webhook endpoint", "Webhooks", "webhooks:write",
   ok={200: json_ok("The endpoint.", "WebhookEndpointResponse")})
op("patch", "/webhooks/{webhook}", "updateWebhook", "Update a webhook endpoint", "Webhooks", "webhooks:write",
   body=jbody("WebhookEndpointUpdateRequest"), validates=True,
   ok={200: json_ok("Updated (includes `secret` when rotated).", "WebhookEndpointResponse")})
op("delete", "/webhooks/{webhook}", "deleteWebhook", "Delete a webhook endpoint", "Webhooks", "webhooks:write", ok=NO_CONTENT)
op("post", "/webhooks/{webhook}/test", "testWebhook", "Send a ping event", "Webhooks", "webhooks:write",
   desc="Queues a `ping` delivery to this endpoint.", params=[P("IncludePayload")],
   ok={202: json_ok("The queued delivery.", "WebhookDeliveryResponse")})
op("get", "/webhooks/{webhook}/deliveries", "listWebhookDeliveries", "List deliveries", "Webhooks", "webhooks:write",
   params=PAGE + [q("status", {"type": "string", "enum": ["pending", "delivered", "failed"]}), q("event", STR), P("IncludePayload")],
   ok={200: json_ok("Deliveries, newest first.", "WebhookDeliveryList")})
op("post", "/webhooks/{webhook}/deliveries/{delivery}/redeliver", "redeliverWebhookDelivery", "Redeliver an event", "Webhooks",
   "webhooks:write", desc="Queues a new delivery with the same event id and payload.", params=[P("IncludePayload")],
   ok={202: json_ok("The new delivery.", "WebhookDeliveryResponse")})

# API keys
op("get", "/api-keys", "listApiKeys", "List API keys", "API keys", "admin",
   params=PAGE + [q("include_revoked", BOOL)], ok={200: json_ok("API keys.", "ApiKeyList")})
op("post", "/api-keys", "createApiKey", "Create an API key", "API keys", "admin",
   desc="The plaintext `key` is returned only in this response.",
   body=jbody("ApiKeyCreateRequest"), ok={201: json_ok("Created (includes `key`).", "ApiKeyResponse")})
op("delete", "/api-keys/{apiKey}", "revokeApiKey", "Revoke an API key", "API keys", "admin", ok=NO_CONTENT)

# Usage
op("get", "/usage", "getUsage", "Usage vs plan limits", "Usage", "searches:read",
   desc="Current billing period usage, plan, limits and feature flags.", ok={200: json_ok("Usage.", "UsageResponse")})


# Search image
op("get", "/searches/{search}/image", "getSearchImage", "Input image (signed URL redirect)", "Searches", "searches:read",
   desc="Redirects to a short-lived (5 min) signed URL for the normalized input image.",
   ok={302: {"description": "Redirect to the signed image URL.", "headers": {"Location": {"schema": {"type": "string"}}}}})

# Callbacks
op("post", "/callbacks/secret", "rotateCallbackSecret", "Create/rotate the callback signing secret", "Callbacks", "admin",
   desc="Required before using `options.callback_url`. Callback deliveries are signed like webhooks with this secret. "
        "The secret is returned only in this response.",
   ok={201: json_ok("New secret.", "CallbackSecretResponse")})
op("get", "/callbacks/deliveries", "listCallbackDeliveries", "Search callback delivery log", "Callbacks", "admin",
   params=PAGE + [q("status", {"type": "string", "enum": ["pending", "delivered", "failed"]})],
   ok={200: json_ok("Callback deliveries, newest first.", "WebhookDeliveryList")})

# Members
op("get", "/members", "listMembers", "List tenant members", "Members", "admin", ok={200: json_ok("Members.", "MemberList")})
op("post", "/members", "addMember", "Add a member", "Members", "admin",
   desc="Creates the user if needed and emails a password-set link. Only owners (or API keys) can grant `owner`.",
   body=jbody("MemberCreateRequest"), ok={201: json_ok("Added.", "MemberResponse")})
op("patch", "/members/{member}", "updateMember", "Change a member's role", "Members", "admin",
   desc="Owner roles can only be changed by owners; the last owner can't be demoted (409 `last_owner`).",
   body=jbody("MemberUpdateRequest"), validates=True, ok={200: json_ok("Updated.", "MemberResponse")},
   extra_responses={409: R("Conflict")})
op("delete", "/members/{member}", "removeMember", "Remove a member", "Members", "admin",
   desc="Revokes the user's tokens. The last owner can't be removed.", ok=NO_CONTENT, extra_responses={409: R("Conflict")})

# Dashboard auth (Sanctum)
def auth_op(method, path, opid, summary, desc, body=None, ok=None, public=True, extra=None):
    o = {"operationId": opid, "summary": summary, "tags": ["Auth"], "description": desc,
         "security": [] if public else [{"SanctumToken": []}, {"SanctumSession": []}]}
    if body:
        o["requestBody"] = jbody(body)
    res = {str(k): v for k, v in ok.items()}
    res["422" if body else "401"] = R("ValidationFailed") if body else R("Unauthenticated")
    res["429"] = R("TooManyRequests")
    for k, v in (extra or {}).items():
        res[str(k)] = v
    o["responses"] = dict(sorted(res.items()))
    paths.setdefault(path, {})[method] = o

auth_op("post", "/auth/login", "login", "Session login (first-party SPA)",
        "Stateful origins only (`SANCTUM_STATEFUL_DOMAINS`); sets the session cookie. Call `/sanctum/csrf-cookie` first.",
        body="LoginRequest", ok={200: json_ok("Logged in.", "UserProfileResponse")}, extra={400: R("BadRequest")})
auth_op("post", "/auth/token", "createToken", "Personal access token",
        "For server-side dashboard clients. Token expires after 30 days; use it as `Authorization: Bearer <token>`.",
        body="TokenRequest", ok={201: json_ok("Token issued.", "TokenResponse")})
auth_op("post", "/auth/register", "register", "Self-serve signup",
        "Disabled unless `PRICELENS_SELF_SIGNUP=true` (403 `signup_disabled`). Creates the user, a tenant on the default plan, "
        "and an admin API key.", body="RegisterRequest", ok={201: json_ok("Registered.", "RegisterResponse")},
        extra={403: R("Forbidden")})
auth_op("post", "/auth/forgot-password", "forgotPassword", "Request a password reset email",
        "Always 202 (no account enumeration).", body="ForgotPasswordRequest", ok={202: json_ok("Accepted.", "StatusMessage")})
auth_op("post", "/auth/reset-password", "resetPassword", "Reset a password", "Revokes all of the user's tokens.",
        body="ResetPasswordRequest", ok={200: json_ok("Password updated.", "StatusMessage")})
auth_op("get", "/auth/me", "me", "Current user", "Profile, memberships and current tenant.",
        ok={200: json_ok("Profile.", "UserProfileResponse")}, public=False)
auth_op("post", "/auth/logout", "logout", "Log out", "Revokes the current token and/or ends the session.",
        ok={204: {"description": "Logged out."}}, public=False)
auth_op("post", "/auth/switch-tenant", "switchTenant", "Switch the current tenant", "Must be a member (404 otherwise).",
        body="SwitchTenantRequest", ok={200: json_ok("Switched.", "UserProfileResponse")}, public=False,
        extra={401: R("Unauthenticated"), 404: R("NotFound")})

# Metrics (outside /api/v1)
paths["/metrics"] = {
    "servers": [{"url": "/", "description": "Application root (outside `/api/v1`)."}],
    "get": {
        "operationId": "getMetrics", "summary": "Prometheus metrics", "tags": ["Health"],
        "description": "Served at `/metrics` (web route, not under `/api/v1`). When `PRICELENS_METRICS_TOKEN` is set, a "
                       "matching bearer token is required.",
        "security": [{}, {"MetricsToken": []}],
        "responses": {
            "200": {"description": "Prometheus text exposition format.",
                    "content": {"text/plain": {"schema": {"type": "string"}}}},
            "401": {"description": "Metrics token required or invalid."},
        }}}

# --------------------------------------------------------------------------------------------------------------------
# Webhooks (outbound)
# --------------------------------------------------------------------------------------------------------------------
wh_params = [
    {"name": "X-PriceLens-Event", "in": "header", "required": True, "description": "Event name.", "schema": ref("WebhookEventName")},
    {"name": "X-PriceLens-Delivery", "in": "header", "required": True, "description": "Delivery id (ULID); new per redelivery.",
     "schema": ULID},
    {"name": "X-PriceLens-Timestamp", "in": "header", "required": True,
     "description": "Unix seconds at send time. Reject if older than 5 minutes.", "schema": {"type": "string", "pattern": "^\\d+$"}},
    {"name": "X-PriceLens-Signature", "in": "header", "required": True,
     "description": "`v1=<hex(HMAC_SHA256(secret, timestamp + \".\" + raw_body))>`. May contain several comma-separated "
                    "signatures; accept if any matches.", "schema": {"type": "string", "pattern": "^v1=[0-9a-f]{64}"}},
    {"name": "User-Agent", "in": "header", "required": False, "schema": {"type": "string", "const": "PriceLens-Webhooks/1.0"}},
]
WH_DESC = ("Delivered as `POST` with `Content-Type: application/json`, no redirects followed, 10 s timeout. Any 2xx = delivered. "
           "Otherwise retried with backoff 1m, 5m, 15m, 1h, 3h, 6h, 12h, 24h (max 8 attempts). `410 Gone` disables the endpoint "
           "immediately; 50 consecutive failures auto-disable it.")
event_data = {
    "search.started": ("Plan built, tasks dispatched.", ref("SearchStartedData")),
    "search.offer_found": ("Visible offer persisted (high volume; must be subscribed explicitly — not covered by `*`).", ref("OfferFoundData")),
    "search.completed": ("Search finalized as `completed` or `partially_completed`.", ref("SearchFinishedData")),
    "search.failed": ("Search finalized as `failed`.", ref("SearchFinishedData")),
    "monitor.run_completed": ("Monitor search finished and snapshots were written.", ref("MonitorRunCompletedData")),
    "source.degraded": ("Source health dropped to `failing`.", ref("SourceDegradedData")),
    "quota.threshold": ("80% / 100% of a monthly plan limit reached.", ref("QuotaThresholdData")),
    "ping": ("Test event from `POST /webhooks/{id}/test`; sent to every endpoint regardless of subscription.", ref("PingData")),
}
for t in ALERT_TYPES:
    event_data[f"alert.{t}"] = (f"Monitor alert `{t}` triggered.", ref("AlertEventData"))

webhooks = {}
for ev in EVENTS:
    d, data = event_data[ev]
    sname = "Event_" + ev.replace(".", "_")
    schemas[sname] = event_schema(ev, data)
    webhooks[ev] = {"post": {
        "operationId": "webhook_" + ev.replace(".", "_"),
        "summary": ev,
        "description": d + "\n\n" + WH_DESC,
        "tags": ["Webhook events"],
        "security": [],
        "parameters": wh_params,
        "requestBody": {"required": True, "content": {"application/json": {"schema": ref(sname)}}},
        "responses": {
            "2XX": {"description": "Delivered."},
            "410": {"description": "Endpoint gone; PriceLens disables the endpoint."},
            "default": {"description": "Any other status (or network error) is retried."},
        }}}

# --------------------------------------------------------------------------------------------------------------------
doc = {
    "openapi": "3.1.0",
    "info": {
        "title": "PriceLens API",
        "version": "1.0.0",
        "description": (
            "Multi-tenant price intelligence API.\n\n"
            "**Authentication.** `Authorization: Bearer pl_live_{prefix}_{secret}` (API key) or a Sanctum session (dashboard / "
            "UI BFF). Each operation requires one ability (`x-ability`): `searches:read`, `searches:write`, `sources:write`, "
            "`monitors:write`, `webhooks:write` or `admin`. Dashboard users map by role: owner/admin → all; member → "
            "searches:read, searches:write, sources:write, monitors:write; viewer → searches:read.\n\n"
            "**Conventions.** ULIDs for all public ids; cursor pagination (`cursor`, `limit` ≤ 100) returning "
            "`{data, meta: {next_cursor}}`; money as `{amount: \"329.99\", currency: \"USD\"}`; timestamps ISO-8601 UTC.\n\n"
            "**Idempotency.** `Idempotency-Key` on any `POST` replays the first response for 24 h "
            "(`Idempotent-Replayed: true`).\n\n"
            "**Errors.** RFC 9457 `application/problem+json` with stable `type` codes.\n\n"
            "**Rate limits.** Per tenant (plan `api_rpm`, default 600/min) and per API key; `X-RateLimit-Limit`, "
            "`X-RateLimit-Remaining`, and `Retry-After` on 429. Every response carries `X-Request-Id`."
        ),
    },
    "servers": [{"url": "/api/v1", "description": "API base path"}],
    "security": [{"ApiKey": []}, {"SanctumToken": []}, {"SanctumSession": []}],
    "tags": [{"name": n, "description": d} for n, d in [
        ("Searches", "Price searches, their offers, diagnostics and CSV export."),
        ("Sources", "Tenant sources (site adapters, engines, feeds) and the global adapter library."),
        ("Tracked products", "Catalog items and their price history."),
        ("Monitors", "Scheduled searches for tracked products."),
        ("Alerts", "Alerts raised by monitors."),
        ("Webhooks", "Webhook endpoint management and delivery log."),
        ("API keys", "API key management (admin)."),
        ("Usage", "Usage against plan limits."),
        ("Auth", "Dashboard users (Sanctum): login, tokens, password reset, tenant switching."),
        ("Members", "Tenant membership and roles (admin)."),
        ("Callbacks", "Per-search `options.callback_url` signing secret and delivery log."),
        ("Realtime", "Reverb (Pusher protocol) channel authorization."),
        ("Health", "Liveness, readiness and metrics probes."),
        ("Webhook events", "Events PriceLens POSTs to subscribed endpoints."),
    ]],
    "paths": paths,
    "webhooks": webhooks,
    "components": {
        "securitySchemes": {
            "ApiKey": {"type": "http", "scheme": "bearer", "bearerFormat": "pl_live_{prefix}_{secret}",
                       "description": "Tenant API key. Invalid or revoked keys → 401."},
            "SanctumToken": {"type": "http", "scheme": "bearer",
                             "description": "Sanctum personal access token from `POST /auth/token` (dashboard users; abilities by role)."},
            "SanctumSession": {"type": "apiKey", "in": "cookie", "name": "pricelens_session",
                               "description": "Sanctum SPA session cookie from `POST /auth/login` (stateful origins only)."},
            "MetricsToken": {"type": "http", "scheme": "bearer",
                             "description": "Static `PRICELENS_METRICS_TOKEN` for `/metrics` (only when configured)."},
        },
        "schemas": schemas,
        "parameters": parameters,
        "headers": headers,
        "responses": responses,
    },
}


class Dumper(yaml.SafeDumper):
    def ignore_aliases(self, data):
        return True


with open(OUT, "w") as f:
    f.write("# PriceLens API — generated from the Laravel code (api/). The code is the source of truth.\n")
    yaml.dump(doc, f, Dumper=Dumper, sort_keys=False, allow_unicode=True, width=120)
print("wrote", OUT)
