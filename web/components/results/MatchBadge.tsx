"use client";

import { useId } from "react";
import type { MatchBreakdown, Offer, OfferMatch } from "@/lib/types";
import { Badge, type Tone } from "@/components/ui/Badge";
import { BAND_LABEL } from "@/lib/offers";
import { humanize } from "@/lib/format";

const BAND_TONE: Record<Offer["match"]["band"], Tone> = {
  exact: "success",
  likely: "accent",
  possible: "warning",
  rejected: "neutral",
};

const TYPE_LABEL: Record<string, string> = {
  exact_gtin: "GTIN match",
  mismatch_gtin: "GTIN mismatch",
  scored: "Scored match",
};

/** Split a breakdown into numeric signal scores, the free-text reason and hard negatives. */
export function splitBreakdown(breakdown: MatchBreakdown | null): {
  scores: [string, number][];
  reason: string | null;
  hardNegatives: string[];
} {
  const scores: [string, number][] = [];
  let reason: string | null = null;
  let hardNegatives: string[] = [];
  for (const [key, value] of Object.entries(breakdown ?? {})) {
    if (key === "hard_negatives" && Array.isArray(value)) hardNegatives = value.map(String);
    else if (key === "reason" && typeof value === "string") reason = value;
    else if (typeof value === "number" && Number.isFinite(value)) scores.push([key, value]);
  }
  return { scores, reason, hardNegatives };
}

export function confidencePercent(confidence: number): string {
  return `${Math.round(Math.max(0, Math.min(1, confidence)) * 100)}%`;
}

/**
 * Match band + confidence. Hover or keyboard focus reveals a tooltip with the
 * score breakdown; the tooltip is linked via aria-describedby so screen
 * readers announce it too.
 */
export function MatchBadge({ match }: { match: OfferMatch }) {
  const id = useId();
  const { scores, reason, hardNegatives } = splitBreakdown(match.breakdown);
  const empty = scores.length === 0 && !reason && hardNegatives.length === 0;
  return (
    <span className="group relative inline-flex">
      <span tabIndex={0} aria-describedby={`${id}-tip`} className="rounded-full focus-visible:outline-2">
        <Badge tone={BAND_TONE[match.band]} data-band={match.band}>
          {BAND_LABEL[match.band]} · {confidencePercent(match.confidence)}
        </Badge>
      </span>
      <span
        id={`${id}-tip`}
        role="tooltip"
        className="pointer-events-none invisible absolute left-0 top-full z-30 mt-1 w-56 rounded-md border border-border bg-surface p-2 text-xs text-fg opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100"
      >
        <span className="block font-medium">
          {TYPE_LABEL[match.type] ?? humanize(match.type)} — {confidencePercent(match.confidence)} confidence
        </span>
        {scores.length > 0 && (
          <span className="mt-1 block space-y-0.5">
            {scores.map(([k, v]) => (
              <span key={k} className="flex justify-between gap-2">
                <span className="text-fg-muted">{humanize(k)}</span>
                <span className="tabular-nums">{v.toFixed(2)}</span>
              </span>
            ))}
          </span>
        )}
        {hardNegatives.length > 0 && (
          <span className="mt-1 block text-warning">Penalised: {hardNegatives.map((n) => humanize(n).toLowerCase()).join(", ")}</span>
        )}
        {reason && <span className="mt-1 block text-fg-muted">Reason: {humanize(reason).toLowerCase()}</span>}
        {empty && <span className="mt-1 block text-fg-muted">No breakdown available</span>}
      </span>
    </span>
  );
}
