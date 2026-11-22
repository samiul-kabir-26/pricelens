"use client";

import { useEffect, useState } from "react";
import { formatDateTime, formatDuration } from "@/lib/format";
import type { Search } from "@/lib/types";
import type { LiveTransport } from "@/lib/hooks/useSearchLive";
import { Button } from "@/components/ui/Button";
import { MonitorLink } from "./MonitorLink";
import { OfferThumb } from "./OfferParts";
import { StatusChip } from "./StatusChip";

function useNow(active: boolean): number {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!active) return;
    const t = window.setInterval(() => setNow(Date.now()), 1000);
    return () => window.clearInterval(t);
  }, [active]);
  return now;
}

export function searchDisplayTitle(s: Pick<Search, "product_query" | "input">): string {
  return (
    s.product_query?.cleaned_title ||
    s.product_query?.title ||
    s.input.title ||
    (s.input.gtin && `GTIN ${s.input.gtin}`) ||
    (s.input.mpn && `MPN ${s.input.mpn}`) ||
    s.input.reference_url ||
    // An uploaded image is only visible through its perceptual hash.
    (s.input.image_url || s.product_query?.image_phash ? "Image search" : "Search")
  );
}

interface SearchHeaderProps {
  search: Search;
  transport: LiveTransport;
  onCancel: () => void;
  cancelling: boolean;
}

export function SearchHeader({ search, transport, onCancel, cancelling }: SearchHeaderProps) {
  const active = search.status === "queued" || search.status === "running";
  const now = useNow(active);
  const start = search.started_at ?? search.created_at;
  const end = search.completed_at ? new Date(search.completed_at).getTime() : active ? now : null;
  const elapsed = start && end ? Math.max(0, end - new Date(start).getTime()) : null;
  const pq = search.product_query;
  const identifiers = [
    pq?.brand && { label: "Brand", value: pq.brand },
    pq?.model && { label: "Model", value: pq.model },
    (pq?.gtin ?? search.input.gtin) && { label: "GTIN", value: pq?.gtin ?? search.input.gtin },
    (pq?.mpn ?? search.input.mpn) && { label: "MPN", value: pq?.mpn ?? search.input.mpn },
  ].filter(Boolean) as { label: string; value: string }[];

  return (
    <header className="flex flex-col gap-4 sm:flex-row sm:items-start">
      <OfferThumb src={pq?.image_url ?? search.input.image_url ?? null} alt="Query product image" size="h-20 w-20" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2" data-testid="search-status">
          <StatusChip status={search.status} />
          {active && (
            <span className="text-xs text-fg-muted">{transport === "socket" ? "Live" : "Updating every 2 s"}</span>
          )}
        </div>
        <h1 className="mt-1 text-lg font-semibold tracking-tight text-fg sm:text-xl">{searchDisplayTitle(search)}</h1>
        {identifiers.length > 0 && (
          <dl className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-fg-muted">
            {identifiers.map((i) => (
              <div key={i.label} className="flex gap-1">
                <dt>{i.label}</dt>
                <dd className="font-medium text-fg">{i.value}</dd>
              </div>
            ))}
          </dl>
        )}
        <p className="mt-1 text-xs text-fg-muted">
          Started {formatDateTime(start)} · Elapsed <span className="tabular-nums">{formatDuration(elapsed)}</span>
          {search.target_currency ? ` · ${search.target_currency}` : ""}
          {search.country ? ` · ${search.country}` : ""}
        </p>
        {search.monitor_id && <MonitorLink monitorId={search.monitor_id} />}
        {search.error && (
          <p role="alert" className="mt-2 text-sm text-danger">
            {search.error}
          </p>
        )}
      </div>
      {active && (
        <Button variant="danger" onClick={onCancel} loading={cancelling} className="self-start">
          Cancel search
        </Button>
      )}
    </header>
  );
}
