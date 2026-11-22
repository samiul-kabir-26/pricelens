"use client";

import { useState } from "react";
import { faviconUrl, humanize } from "@/lib/format";
import { discountPercent, formatMoney, isDiscounted } from "@/lib/money";
import type { KnownExtractionMethod, Offer, OfferVariant } from "@/lib/types";

export function OfferThumb({ src, alt, size = "h-12 w-12" }: { src: string | null; alt: string; size?: string }) {
  const [failed, setFailed] = useState(false);
  if (!src || failed) return <div className={`${size} shrink-0 rounded bg-surface-muted`} aria-hidden="true" />;
  return (
    // eslint-disable-next-line @next/next/no-img-element -- arbitrary retailer hosts
    <img
      src={src}
      alt={alt}
      loading="lazy"
      referrerPolicy="no-referrer"
      onError={() => setFailed(true)}
      className={`${size} shrink-0 rounded bg-surface-muted object-contain`}
    />
  );
}

export function SiteLabel({ site }: { site: Offer["site"] }) {
  const [failed, setFailed] = useState(false);
  return (
    <span className="inline-flex min-w-0 items-center gap-1.5">
      {!failed ? (
        // eslint-disable-next-line @next/next/no-img-element -- favicon from the retailer itself
        <img
          src={faviconUrl(site.domain)}
          alt=""
          width={16}
          height={16}
          loading="lazy"
          referrerPolicy="no-referrer"
          onError={() => setFailed(true)}
          className="h-4 w-4 shrink-0 rounded-sm"
        />
      ) : (
        <span className="h-4 w-4 shrink-0 rounded-sm bg-surface-muted" aria-hidden="true" />
      )}
      <span className="truncate">{site.name ?? site.domain}</span>
    </span>
  );
}

export function PriceCell({ offer }: { offer: Offer }) {
  const pct = discountPercent(offer.price, offer.list_price);
  return (
    <span className="flex flex-col">
      <span className="tabular-nums">{formatMoney(offer.price)}</span>
      {isDiscounted(offer.price, offer.list_price) && (
        <span className="text-xs text-fg-muted">
          <s aria-label={`List price ${formatMoney(offer.list_price)}`}>{formatMoney(offer.list_price)}</s>
          {pct !== null && <span className="ml-1 text-success">−{pct}%</span>}
        </span>
      )}
    </span>
  );
}

export const METHOD_LABEL: Record<KnownExtractionMethod, string> = {
  json_ld: "JSON-LD",
  microdata: "Microdata",
  meta: "Meta tags",
  embedded_state: "Embedded state",
  selector: "Selectors",
  llm: "LLM",
  provider: "Provider",
  feed: "Feed",
  html: "HTML",
};

export function methodLabel(m: Offer["extraction_method"]): string {
  return (METHOD_LABEL as Record<string, string>)[m] ?? humanize(m);
}

/** "★ 4.6 (1,234)" with a full sentence for assistive tech; nothing when unrated. */
export function Rating({ rating, count, className }: { rating: number | null; count: number | null; className?: string }) {
  if (rating === null || !Number.isFinite(rating)) return null;
  const value = rating.toFixed(1);
  const reviews = count !== null && count !== undefined ? new Intl.NumberFormat().format(count) : null;
  const label = `Rated ${value} out of 5${reviews !== null ? ` from ${reviews} review${count === 1 ? "" : "s"}` : ""}`;
  return (
    <span className={`inline-flex items-center gap-0.5 whitespace-nowrap tabular-nums ${className ?? ""}`} title={label}>
      <span className="sr-only">{label}</span>
      <span aria-hidden="true">
        <span className="text-warning">★</span> {value}
        {reviews !== null && <span className="text-fg-muted"> ({reviews})</span>}
      </span>
    </span>
  );
}

const VARIANT_KEYS = ["color", "size", "storage"] as const;

/** Known variant attributes (color/size/storage) with a printable value, in a fixed order. */
export function variantEntries(variant: OfferVariant | null): { key: (typeof VARIANT_KEYS)[number]; value: string }[] {
  if (!variant) return [];
  return VARIANT_KEYS.flatMap((key) => {
    const v = variant[key];
    return typeof v === "string" || typeof v === "number" ? (String(v).trim() ? [{ key, value: String(v).trim() }] : []) : [];
  });
}

export function VariantChips({ variant }: { variant: OfferVariant | null }) {
  const entries = variantEntries(variant);
  if (entries.length === 0) return null;
  return (
    <ul className="flex flex-wrap gap-1" aria-label="Variant">
      {entries.map(({ key, value }) => (
        <li
          key={key}
          className="inline-flex items-center gap-1 rounded border border-border bg-surface-muted px-1.5 py-0.5 text-[11px] leading-4 text-fg"
        >
          <span className="text-fg-muted">{humanize(key)}</span>
          <span className="font-medium">{value}</span>
        </li>
      ))}
    </ul>
  );
}
