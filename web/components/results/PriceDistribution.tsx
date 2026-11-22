"use client";

import { useMemo, useState } from "react";
import { formatAmount, moneyValue } from "@/lib/money";
import { effectiveTotal } from "@/lib/offers";
import type { Offer, Stats } from "@/lib/types";

interface PriceDistributionProps {
  offers: readonly Offer[];
  stats: Stats | null;
}

/** Deterministic 0..1 jitter from the offer id so dots don't jump on re-render. */
function jitter(id: string): number {
  let h = 0;
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) >>> 0;
  return (h % 1000) / 1000;
}

/**
 * Compact horizontal box plot (p25–p75 box, median line, min–max whiskers)
 * overlaid with a jittered strip of individual offer prices.
 */
export function PriceDistribution({ offers, stats }: PriceDistributionProps) {
  const [hover, setHover] = useState<{ offer: Offer; value: number } | null>(null);
  const currency = stats?.currency ?? null;

  const points = useMemo(
    () =>
      offers
        .filter((o) => o.match.band !== "rejected")
        .map((o) => ({ offer: o, money: effectiveTotal(o) }))
        .filter((p) => p.money && (!currency || p.money.currency === currency))
        .map((p) => ({ offer: p.offer, value: moneyValue(p.money)! }))
        .filter((p) => Number.isFinite(p.value)),
    [offers, currency],
  );

  const s = {
    min: Number(stats?.min ?? NaN),
    p25: Number(stats?.p25 ?? NaN),
    median: Number(stats?.median ?? NaN),
    p75: Number(stats?.p75 ?? NaN),
    max: Number(stats?.max ?? NaN),
  };
  const values = points.map((p) => p.value);
  const lo = Math.min(...values, ...(Number.isFinite(s.min) ? [s.min] : []));
  const hi = Math.max(...values, ...(Number.isFinite(s.max) ? [s.max] : []));

  if (!Number.isFinite(lo) || !Number.isFinite(hi) || points.length < 2) return null;

  const pad = (hi - lo) * 0.04 || Math.max(1, hi * 0.05);
  const x0 = lo - pad;
  const x1 = hi + pad;
  const pct = (v: number) => `${((v - x0) / (x1 - x0)) * 100}%`;
  const fmt = (v: number) => formatAmount(v, currency);
  const hasBox = [s.p25, s.median, s.p75].every(Number.isFinite);

  return (
    <figure className="rounded-lg border border-border bg-surface p-4">
      <figcaption className="flex flex-wrap items-baseline justify-between gap-2">
        <span className="text-sm font-semibold text-fg">Price distribution</span>
        <span className="text-xs text-fg-muted">
          {points.length} offers{currency ? ` · ${currency}` : ""} · box = middle 50%, line = median, hollow = outlier
        </span>
      </figcaption>
      <p className="sr-only">
        {`Prices range from ${fmt(lo)} to ${fmt(hi)}${hasBox ? `, median ${fmt(s.median)}, middle half between ${fmt(s.p25)} and ${fmt(s.p75)}` : ""}.`}
      </p>
      <div className="relative mt-4 h-16" aria-hidden="true" onMouseLeave={() => setHover(null)}>
        {/* whisker */}
        {Number.isFinite(s.min) && Number.isFinite(s.max) && (
          <div className="absolute top-1/2 h-px bg-fg-muted/50" style={{ left: pct(s.min), width: `calc(${pct(s.max)} - ${pct(s.min)})` }} />
        )}
        {hasBox && (
          <>
            <div
              className="absolute top-2 bottom-2 rounded-sm border border-accent/50 bg-accent-soft"
              style={{ left: pct(s.p25), width: `calc(${pct(s.p75)} - ${pct(s.p25)})` }}
            />
            <div className="absolute top-1 bottom-1 w-0.5 -translate-x-1/2 rounded bg-accent" style={{ left: pct(s.median) }} />
          </>
        )}
        {points.map((p) => (
          <span
            key={p.offer.id}
            onMouseEnter={() => setHover(p)}
            className={`absolute h-2.5 w-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full ring-2 ring-surface ${
              p.offer.price_outlier ? "border-2 border-fg bg-surface" : "bg-fg/80"
            }`}
            style={{ left: pct(p.value), top: `${18 + jitter(p.offer.id) * 64}%` }}
          />
        ))}
        {hover && (
          <div
            className="pointer-events-none absolute bottom-full z-10 mb-1 -translate-x-1/2 whitespace-nowrap rounded-md border border-border bg-surface px-2 py-1 text-xs shadow"
            style={{ left: pct(hover.value) }}
          >
            <span className="font-medium text-fg">{fmt(hover.value)}</span>
            <span className="text-fg-muted"> · {hover.offer.site.name ?? hover.offer.site.domain}</span>
          </div>
        )}
      </div>
      <div className="relative mt-1 h-4 text-xs tabular-nums text-fg-muted">
        <span className="absolute -translate-x-0" style={{ left: pct(lo) }}>
          {fmt(lo)}
        </span>
        {hasBox && (
          <span className="absolute -translate-x-1/2 font-medium text-fg" style={{ left: pct(s.median) }}>
            {fmt(s.median)}
          </span>
        )}
        <span className="absolute -translate-x-full" style={{ left: pct(hi) }}>
          {fmt(hi)}
        </span>
      </div>
    </figure>
  );
}
