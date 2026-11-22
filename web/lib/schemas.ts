import { z } from "zod";
import { isValidGtin, normalizeGtin } from "./gtin";
import type { CreateSearchRequest, SearchEngineSelection } from "./types";

export const MAX_IMAGE_BYTES = 10 * 1024 * 1024;
export const ACCEPTED_IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"] as const;

const HOSTNAME_RE = /^(?=.{1,253}$)(?!-)([a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,63}$/i;

export function isHostname(value: string): boolean {
  return HOSTNAME_RE.test(value);
}

/** "https://www.Shop.example.com/x" -> "www.shop.example.com"; plain hostnames pass through. */
export function normalizeDomain(input: string): string {
  let v = input.trim().toLowerCase();
  v = v.replace(/^[a-z]+:\/\//, "");
  v = v.split(/[/?#]/)[0] ?? "";
  v = v.replace(/:\d+$/, "");
  return v.replace(/\.$/, "");
}

const isHttpUrl = (v: string) => {
  try {
    const u = new URL(v);
    return u.protocol === "http:" || u.protocol === "https:";
  } catch {
    return false;
  }
};

const optionalUrl = z
  .string()
  .trim()
  .max(2048, "URL is too long")
  .refine((v) => v === "" || isHttpUrl(v), "Enter a valid http(s) URL");

const imageFile = z
  .custom<File>((v) => typeof File !== "undefined" && v instanceof File, "Invalid file")
  .refine((f) => f.size <= MAX_IMAGE_BYTES, "Image must be 10 MB or smaller")
  .refine(
    (f) => (ACCEPTED_IMAGE_TYPES as readonly string[]).includes(f.type),
    "Image must be JPEG, PNG or WebP",
  );

export const CONDITIONS = ["new", "used", "refurbished", "any"] as const;

/**
 * Search form schema. Field paths mirror the API's dot-notation error keys
 * (e.g. "options.max_results") so server 422 errors map 1:1 onto inputs.
 */
export const searchFormSchema = z
  .object({
    title: z.string().trim().max(500, "Title must be at most 500 characters"),
    brand: z.string().trim().max(120, "Brand must be at most 120 characters"),
    gtin: z
      .string()
      .trim()
      .refine(
        (v) => v === "" || isValidGtin(v),
        "Enter a valid GTIN-8/12/13/14 (check digit does not match)",
      ),
    mpn: z.string().trim().max(120, "MPN must be at most 120 characters"),
    image_url: optionalUrl,
    image: imageFile.nullable(),
    reference_url: optionalUrl,
    sources: z.object({
      source_ids: z.array(z.string().min(1)),
      domains: z.array(z.string().refine(isHostname, "Invalid hostname")).max(50, "At most 50 domains"),
      web: z.boolean(),
      shopping: z.boolean(),
      /** Fetch each shopping result's merchant page to confirm price/availability. */
      verify_shopping: z.boolean(),
      reverse_image: z.boolean(),
      open_web: z.boolean(),
    }),
    options: z.object({
      country: z.string().regex(/^[A-Z]{2}$/, "Use a 2-letter country code"),
      language: z.string().regex(/^[a-z]{2}$/, "Use a 2-letter language code"),
      target_currency: z.string().regex(/^[A-Z]{3}$/, "Use a 3-letter currency code"),
      condition: z.enum(CONDITIONS),
      max_results: z
        .number({ error: "Enter a number" })
        .int("Must be a whole number")
        .min(1, "At least 1")
        .max(100, "At most 100"),
      min_confidence: z.number().min(0).max(1),
      include_possible: z.boolean(),
      fresh: z.boolean(),
    }),
  })
  .superRefine((v, ctx) => {
    const hasAny =
      v.title !== "" ||
      v.image !== null ||
      v.image_url !== "" ||
      v.gtin !== "" ||
      v.mpn !== "" ||
      v.reference_url !== "";
    if (!hasAny) {
      ctx.addIssue({
        code: "custom",
        path: ["form"],
        message: "Provide at least one of: title, image, GTIN, MPN or reference URL.",
      });
    }
  });

export type SearchFormValues = z.input<typeof searchFormSchema>;

export const defaultSearchFormValues: SearchFormValues = {
  title: "",
  brand: "",
  gtin: "",
  mpn: "",
  image_url: "",
  image: null,
  reference_url: "",
  sources: {
    source_ids: [],
    domains: [],
    web: true,
    shopping: true,
    verify_shopping: false,
    reverse_image: false,
    open_web: false,
  },
  options: {
    country: "US",
    language: "en",
    target_currency: "USD",
    condition: "new",
    max_results: 50,
    min_confidence: 0.6,
    include_possible: false,
    fresh: false,
  },
};

const blankToNull = (v: string) => (v.trim() === "" ? null : v.trim());

/** Map validated form values to the API request (JSON variant). */
export function toCreateSearchRequest(v: SearchFormValues): CreateSearchRequest {
  const engines: SearchEngineSelection[] = [];
  if (v.sources.shopping) engines.push({ provider: "serpapi", mode: "shopping", verify: v.sources.verify_shopping });
  if (v.sources.web) {
    engines.push({
      provider: "brave",
      mode: "web",
      scope: v.sources.open_web ? "open" : "allowed_domains",
    });
  }
  return {
    title: blankToNull(v.title),
    gtin: v.gtin.trim() === "" ? null : normalizeGtin(v.gtin),
    mpn: blankToNull(v.mpn),
    brand: blankToNull(v.brand),
    image_url: v.image ? null : blankToNull(v.image_url),
    reference_url: blankToNull(v.reference_url),
    sources: {
      source_ids: v.sources.source_ids,
      domains: v.sources.domains,
      search_engines: engines,
      reverse_image: v.sources.reverse_image,
    },
    options: {
      country: v.options.country,
      language: v.options.language,
      target_currency: v.options.target_currency,
      condition: v.options.condition,
      max_results: v.options.max_results,
      include_possible: v.options.include_possible,
      min_confidence: v.options.min_confidence,
      fresh: v.options.fresh,
      callback_url: null,
    },
  };
}

/**
 * Multipart variant for image uploads: file field `image`, scalars as form
 * fields, `sources` / `options` as JSON-encoded strings.
 */
export function toCreateSearchFormData(v: SearchFormValues): FormData {
  const req = toCreateSearchRequest(v);
  const fd = new FormData();
  if (v.image) fd.append("image", v.image);
  for (const key of ["title", "gtin", "mpn", "brand", "image_url", "reference_url"] as const) {
    const value = req[key];
    if (value) fd.append(key, value);
  }
  fd.append("sources", JSON.stringify(req.sources));
  fd.append("options", JSON.stringify(req.options));
  return fd;
}

/** Flatten a ZodError into { "dot.path": "first message" }. */
export function zodErrorToFieldErrors(error: z.ZodError): Record<string, string> {
  const out: Record<string, string> = {};
  for (const issue of error.issues) {
    const key = issue.path.map(String).join(".") || "form";
    if (!(key in out)) out[key] = issue.message;
  }
  return out;
}

/* ------------------------------------------------------------------ */
/* Other forms                                                         */
/* ------------------------------------------------------------------ */

const money2dp = z
  .string()
  .trim()
  .refine((v) => v === "" || /^\d+(\.\d{1,2})?$/.test(v), "Enter an amount like 299.00");

export const trackedProductFormSchema = z.object({
  title: z.string().trim().min(1, "Title is required").max(500),
  brand: z.string().trim().max(120),
  gtin: z
    .string()
    .trim()
    .refine((v) => v === "" || isValidGtin(v), "Enter a valid GTIN (check digit does not match)"),
  mpn: z.string().trim().max(120),
  external_ref: z.string().trim().max(120),
  image_url: optionalUrl,
  currency: z.string().regex(/^[A-Z]{3}$/, "Use a 3-letter currency code"),
  reference_price: money2dp,
  map_price: money2dp,
});
export type TrackedProductFormValues = z.input<typeof trackedProductFormSchema>;

const optionalPercent = z.number().min(0).max(100).nullable();

export const alertRulesSchema = z.object({
  price_drop_percent: optionalPercent,
  price_increase_percent: optionalPercent,
  target_price: money2dp,
  map_enforcement: z.boolean(),
  availability_changes: z.boolean(),
  new_sellers: z.boolean(),
  cooldown_minutes: z.number().int().min(0, "Must be 0 or more").max(43200, "At most 30 days"),
});
export type AlertRulesFormValues = z.input<typeof alertRulesSchema>;

export const webhookFormSchema = z.object({
  url: z
    .string()
    .trim()
    .refine((v) => {
      try {
        return new URL(v).protocol === "https:";
      } catch {
        return false;
      }
    }, "Enter a valid https:// URL"),
  events: z.array(z.string()).min(1, "Select at least one event"),
});
export type WebhookFormValues = z.input<typeof webhookFormSchema>;

export const SOURCE_TYPES = ["site", "search_engine", "shopping_engine", "reverse_image", "feed"] as const;
export const RENDER_MODES = ["never", "auto", "always"] as const;

export const sourceFormSchema = z
  .object({
    name: z.string().trim().min(1, "Name is required").max(120),
    type: z.enum(SOURCE_TYPES),
    domain: z.string().trim(),
    country: z.string().trim().refine((v) => v === "" || /^[A-Z]{2}$/.test(v), "Use a 2-letter country code"),
    currency: z.string().trim().refine((v) => v === "" || /^[A-Z]{3}$/.test(v), "Use a 3-letter currency code"),
    render: z.enum(RENDER_MODES),
    enabled: z.boolean(),
  })
  .superRefine((v, ctx) => {
    if (v.type === "site" && !isHostname(v.domain)) {
      ctx.addIssue({ code: "custom", path: ["domain"], message: "A valid domain is required for site adapters" });
    } else if (v.domain !== "" && !isHostname(v.domain)) {
      ctx.addIssue({ code: "custom", path: ["domain"], message: "Invalid hostname" });
    }
  });
export type SourceFormValues = z.input<typeof sourceFormSchema>;

export const problemDetailsSchema = z.object({
  type: z.string().default("about:blank"),
  title: z.string().default("Request failed"),
  status: z.number(),
  detail: z.string().optional(),
  instance: z.string().optional(),
  errors: z.record(z.string(), z.array(z.string())).optional(),
});
