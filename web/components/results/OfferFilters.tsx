"use client";

import { useEffect, useId, useState } from "react";
import type { MatchBand, OfferFilters as Filters, OfferSort } from "@/lib/types";
import { BAND_LABEL, DEFAULT_SORT, defaultOfferFilters } from "@/lib/offers";
import { Button } from "@/components/ui/Button";
import { SelectField } from "@/components/ui/Select";
import { Checkbox, controlClasses } from "@/components/ui/Input";
import { cn } from "@/lib/format";

const BANDS: MatchBand[] = ["exact", "likely", "possible", "rejected"];

export const SORT_OPTIONS: { value: OfferSort; label: string }[] = [
  { value: "rank", label: "Best match (recommended)" },
  { value: "total_price", label: "Total price: low to high" },
  { value: "-total_price", label: "Total price: high to low" },
  { value: "price", label: "Item price: low to high" },
  { value: "-price", label: "Item price: high to low" },
  { value: "-confidence", label: "Confidence: high to low" },
  { value: "confidence", label: "Confidence: low to high" },
  { value: "site", label: "Site A–Z" },
  { value: "-site", label: "Site Z–A" },
  { value: "-observed_at", label: "Observed: newest first" },
  { value: "observed_at", label: "Observed: oldest first" },
];

interface OfferFiltersProps {
  value: Filters;
  onChange: (value: Filters) => void;
  sites: { domain: string; name: string }[];
  currency?: string | null;
}

export function OfferFilters({ value, onChange, sites, currency }: OfferFiltersProps) {
  const id = useId();
  const [minDraft, setMinDraft] = useState(value.min_price ?? "");
  const [maxDraft, setMaxDraft] = useState(value.max_price ?? "");

  // Debounce price range inputs.
  useEffect(() => {
    const t = window.setTimeout(() => {
      const min = /^\d+(\.\d+)?$/.test(minDraft) ? minDraft : undefined;
      const max = /^\d+(\.\d+)?$/.test(maxDraft) ? maxDraft : undefined;
      if (min !== value.min_price || max !== value.max_price) onChange({ ...value, min_price: min, max_price: max });
    }, 400);
    return () => window.clearTimeout(t);
  }, [minDraft, maxDraft, value, onChange]);

  const bands = value.band ?? [];
  const toggleBand = (b: MatchBand) =>
    onChange({ ...value, band: bands.includes(b) ? bands.filter((x) => x !== b) : [...bands, b] });

  return (
    <section aria-label="Filter and sort offers" className="space-y-3 rounded-lg border border-border bg-surface p-3">
      <div className="flex flex-wrap items-center gap-2">
        <span id={`${id}-bands`} className="text-xs font-medium text-fg-muted">
          Match
        </span>
        <div role="group" aria-labelledby={`${id}-bands`} className="flex flex-wrap gap-1.5">
          {BANDS.map((b) => {
            const on = bands.includes(b);
            return (
              <button
                key={b}
                type="button"
                aria-pressed={on}
                onClick={() => toggleBand(b)}
                className={cn(
                  "rounded-full border px-2.5 py-1 text-xs font-medium",
                  on ? "border-accent bg-accent-soft text-accent" : "border-border text-fg-muted hover:text-fg",
                )}
              >
                {BAND_LABEL[b]}
              </button>
            );
          })}
        </div>
      </div>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <SelectField
          label="Availability"
          value={value.availability ?? ""}
          onChange={(e) => onChange({ ...value, availability: e.target.value || undefined })}
          options={[
            { value: "", label: "Any" },
            { value: "in_stock", label: "In stock" },
            { value: "limited", label: "Limited" },
            { value: "preorder", label: "Pre-order" },
            { value: "backorder", label: "Backorder" },
            { value: "out_of_stock", label: "Out of stock" },
          ]}
        />
        <SelectField
          label="Condition"
          value={value.condition ?? ""}
          onChange={(e) => onChange({ ...value, condition: e.target.value || undefined })}
          options={[
            { value: "", label: "Any" },
            { value: "new", label: "New" },
            { value: "used", label: "Used" },
            { value: "refurbished", label: "Refurbished" },
          ]}
        />
        <SelectField
          label="Site"
          value={value.site ?? ""}
          onChange={(e) => onChange({ ...value, site: e.target.value || undefined })}
          options={[{ value: "", label: "All sites" }, ...sites.map((s) => ({ value: s.domain, label: s.name }))]}
        />
        <div className="space-y-1">
          <label htmlFor={`${id}-min`} className="block text-sm font-medium text-fg">
            Min{currency ? ` (${currency})` : ""}
          </label>
          <input id={`${id}-min`} inputMode="decimal" value={minDraft} onChange={(e) => setMinDraft(e.target.value.trim())} className={controlClasses} placeholder="0" />
        </div>
        <div className="space-y-1">
          <label htmlFor={`${id}-max`} className="block text-sm font-medium text-fg">
            Max{currency ? ` (${currency})` : ""}
          </label>
          <input id={`${id}-max`} inputMode="decimal" value={maxDraft} onChange={(e) => setMaxDraft(e.target.value.trim())} className={controlClasses} placeholder="Any" />
        </div>
        <SelectField
          label="Sort by"
          value={value.sort ?? DEFAULT_SORT}
          onChange={(e) => onChange({ ...value, sort: e.target.value as OfferSort })}
          options={SORT_OPTIONS}
        />
      </div>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div role="group" aria-label="Include" className="flex flex-wrap gap-x-5 gap-y-2">
          <Checkbox
            label="Show duplicates"
            description="Same listing found via several sources"
            checked={Boolean(value.include_duplicates)}
            onChange={(e) => onChange({ ...value, include_duplicates: e.target.checked || undefined })}
          />
          <Checkbox
            label="Show outliers"
            description="Prices far from the median"
            checked={value.include_outliers !== false}
            onChange={(e) => onChange({ ...value, include_outliers: e.target.checked ? undefined : false })}
          />
        </div>
        <Button
          size="sm"
          variant="ghost"
          onClick={() => {
            setMinDraft("");
            setMaxDraft("");
            onChange(defaultOfferFilters);
          }}
        >
          Reset filters
        </Button>
      </div>
    </section>
  );
}
