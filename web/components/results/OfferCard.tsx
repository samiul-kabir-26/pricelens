"use client";

import { formatRelative, humanize } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import { offerTitle } from "@/lib/offers";
import type { Offer } from "@/lib/types";
import { Badge } from "@/components/ui/Badge";
import { ExternalLink } from "@/components/ui/ExternalLink";
import { AvailabilityBadge } from "./AvailabilityBadge";
import { MatchBadge } from "./MatchBadge";
import { OfferThumb, PriceCell, Rating, SiteLabel, VariantChips, methodLabel } from "./OfferParts";

export function OfferCard({ offer: o, cheapest }: { offer: Offer; cheapest?: boolean }) {
  return (
    <article className="flex flex-col gap-3 rounded-lg border border-border bg-surface p-3" aria-label={offerTitle(o)}>
      <div className="flex gap-3">
        <OfferThumb src={o.image_url} alt="" size="h-16 w-16" />
        <div className="min-w-0 flex-1">
          <h3 className="line-clamp-2 text-sm font-medium text-fg">{offerTitle(o)}</h3>
          <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-fg-muted">
            <SiteLabel site={o.site} />
            {o.brand && <span>{o.brand}</span>}
            <Rating rating={o.rating} count={o.review_count} />
          </p>
          <div className="mt-1">
            <VariantChips variant={o.variant} />
          </div>
        </div>
      </div>
      <div className="flex items-end justify-between gap-2">
        <div>
          <p className="text-lg font-semibold tabular-nums text-fg">{formatMoney(o.total ?? o.price)}</p>
          <div className="text-xs text-fg-muted">
            <PriceCell offer={o} />
            <span>Shipping {formatMoney(o.shipping)}</span>
            {o.converted_total && o.converted_total.currency !== o.total?.currency && (
              <span className="block">≈ {formatMoney(o.converted_total)}</span>
            )}
          </div>
        </div>
        <div className="flex flex-col items-end gap-1">
          {cheapest && <Badge tone="success">Lowest</Badge>}
          {o.price_outlier && <Badge tone="warning">Outlier</Badge>}
          {o.is_duplicate && <Badge tone="info">Duplicate</Badge>}
        </div>
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <AvailabilityBadge availability={o.availability} />
        <MatchBadge match={o.match} />
        <Badge>{humanize(o.condition)}</Badge>
      </div>
      <div className="flex items-center justify-between border-t border-border pt-2 text-xs text-fg-muted">
        <span>
          {methodLabel(o.extraction_method)} · <time dateTime={o.observed_at ?? undefined}>{formatRelative(o.observed_at)}</time>
        </span>
        <ExternalLink href={o.url} className="font-medium text-accent hover:underline">
          View offer
        </ExternalLink>
      </div>
    </article>
  );
}
