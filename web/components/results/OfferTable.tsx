"use client";

import { formatDateTime, formatRelative, humanize } from "@/lib/format";
import { formatMoney } from "@/lib/money";
import type { Offer, OfferSort } from "@/lib/types";
import { offerTitle, sortOffers } from "@/lib/offers";
import { SortableTh, Table, TBody, Td, Th, THead, type SortDirection } from "@/components/ui/Table";
import { ExternalLink } from "@/components/ui/ExternalLink";
import { Badge } from "@/components/ui/Badge";
import { AvailabilityBadge } from "./AvailabilityBadge";
import { MatchBadge } from "./MatchBadge";
import { OfferThumb, PriceCell, Rating, SiteLabel, VariantChips, methodLabel } from "./OfferParts";

type Column = "site" | "price" | "total" | "match" | "observed";

/** Header click cycle per column, using API sort values. */
const SORT_CYCLE: Record<Column, OfferSort[]> = {
  site: ["site", "-site"],
  price: ["price", "-price"],
  total: ["total_price", "-total_price"],
  match: ["-confidence", "confidence"],
  observed: ["-observed_at", "observed_at"],
};

export function nextSort(column: Column, current: OfferSort | undefined): OfferSort {
  const cycle = SORT_CYCLE[column];
  const idx = current ? cycle.indexOf(current) : -1;
  return idx === -1 ? cycle[0] : cycle[(idx + 1) % cycle.length];
}

function directionFor(column: Column, current: OfferSort | undefined): SortDirection {
  if (!current || !SORT_CYCLE[column].includes(current)) return "none";
  return current.startsWith("-") ? "descending" : "ascending";
}

interface OfferTableProps {
  offers: readonly Offer[];
  sort: OfferSort | undefined;
  onSortChange: (sort: OfferSort) => void;
  cheapestOfferId?: string | null;
  caption?: string;
}

/** Sortable results table (≥ lg). Rows are sorted locally so streamed offers land in place. */
export function OfferTable({ offers, sort, onSortChange, cheapestOfferId, caption = "Offers" }: OfferTableProps) {
  const rows = sortOffers(offers, sort);
  const th = (column: Column, label: string, className?: string) => (
    <SortableTh
      label={label}
      direction={directionFor(column, sort)}
      onSort={() => onSortChange(nextSort(column, sort))}
      className={className}
    />
  );

  return (
    <Table caption={caption} className="min-w-[1180px]">
      <THead>
        <tr>
          <Th className="min-w-[18rem]">Product</Th>
          {th("site", "Site")}
          {th("price", "Price", "text-right")}
          <Th className="text-right">Shipping</Th>
          {th("total", "Total", "text-right")}
          <Th className="text-right">Converted</Th>
          <Th>Availability</Th>
          <Th>Condition</Th>
          {th("match", "Match")}
          <Th>Method</Th>
          {th("observed", "Observed")}
          <Th>
            <span className="sr-only">Link</span>
          </Th>
        </tr>
      </THead>
      <TBody>
        {rows.map((o) => (
          <tr
            key={o.id}
            data-testid="offer-row"
            className={o.id === cheapestOfferId ? "bg-success-soft/40" : o.is_duplicate ? "opacity-75" : undefined}
          >
            <Td>
              <div className="flex gap-3">
                <OfferThumb src={o.image_url} alt="" />
                <div className="min-w-0 space-y-1">
                  <p className="line-clamp-2 font-medium text-fg" title={offerTitle(o)}>
                    {offerTitle(o)}
                  </p>
                  <p
                    className="truncate text-xs text-fg-muted"
                    title={[o.identifiers.gtin && `GTIN ${o.identifiers.gtin}`, o.identifiers.mpn && `MPN ${o.identifiers.mpn}`, o.identifiers.sku && `SKU ${o.identifiers.sku}`]
                      .filter(Boolean)
                      .join(" · ")}
                  >
                    {[o.brand, o.seller && o.seller !== o.brand ? o.seller : null, o.identifiers.gtin && `GTIN ${o.identifiers.gtin}`]
                      .filter(Boolean)
                      .join(" · ")}
                  </p>
                  <Rating rating={o.rating} count={o.review_count} className="text-xs" />
                  <VariantChips variant={o.variant} />
                  {(o.id === cheapestOfferId || o.price_outlier || o.is_duplicate) && (
                    <div className="flex flex-wrap gap-1">
                      {o.id === cheapestOfferId && <Badge tone="success">Lowest</Badge>}
                      {o.price_outlier && <Badge tone="warning">Price outlier</Badge>}
                      {o.is_duplicate && <Badge tone="info">Duplicate</Badge>}
                    </div>
                  )}
                </div>
              </div>
            </Td>
            <Td className="max-w-[10rem]">
              <SiteLabel site={o.site} />
            </Td>
            <Td className="text-right">
              <PriceCell offer={o} />
            </Td>
            <Td className="text-right tabular-nums">{formatMoney(o.shipping)}</Td>
            <Td className="text-right font-semibold tabular-nums" data-testid="offer-total">
              {formatMoney(o.total)}
            </Td>
            <Td className="text-right tabular-nums text-fg-muted">
              {o.converted_total && o.converted_total.currency !== o.total?.currency ? (
                <span title={o.converted_total.fx_rate_date ? `FX rate of ${o.converted_total.fx_rate_date}` : undefined}>
                  {formatMoney(o.converted_total)}
                </span>
              ) : (
                "—"
              )}
            </Td>
            <Td>
              <AvailabilityBadge availability={o.availability} />
            </Td>
            <Td>{humanize(o.condition)}</Td>
            <Td>
              <MatchBadge match={o.match} />
            </Td>
            <Td className="text-xs text-fg-muted">{methodLabel(o.extraction_method)}</Td>
            <Td className="whitespace-nowrap text-xs text-fg-muted">
              <time dateTime={o.observed_at ?? undefined} title={formatDateTime(o.observed_at)}>
                {formatRelative(o.observed_at)}
              </time>
            </Td>
            <Td>
              <ExternalLink href={o.url} className="text-sm font-medium text-accent hover:underline">
                View
              </ExternalLink>
            </Td>
          </tr>
        ))}
      </TBody>
    </Table>
  );
}
