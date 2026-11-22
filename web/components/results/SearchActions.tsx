"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { api, errorMessage, newIdempotencyKey } from "@/lib/api";
import type {
  AlertRules,
  ItemResponse,
  Monitor,
  MonitorCreate,
  MonitorOptions,
  Search,
  Stats,
  TrackedProduct,
  TrackedProductCreate,
} from "@/lib/types";
import { Button, buttonClasses } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toast";
import { searchDisplayTitle } from "./SearchHeader";

export const MONITOR_SCHEDULE = "0 */6 * * *";

export const DEFAULT_ALERT_RULES: AlertRules = {
  price_drop_percent: 10,
  price_increase_percent: 15,
  target_price: null,
  map_enforcement: true,
  availability_changes: true,
  new_sellers: true,
  cooldown_minutes: 720,
};

/** Body for POST /tracked-products: the median becomes the reference price (plain decimal + currency). */
export function trackedProductFromSearch(search: Search, stats: Stats | null): TrackedProductCreate {
  const pq = search.product_query;
  const fallbackCurrency = search.target_currency ?? stats?.currency ?? search.options.target_currency ?? "USD";
  const median = stats?.median && stats.currency ? stats.median : null;
  return {
    title: searchDisplayTitle(search),
    brand: pq?.brand ?? search.input.brand ?? null,
    gtin: pq?.gtin ?? search.input.gtin ?? null,
    mpn: pq?.mpn ?? search.input.mpn ?? null,
    image_url: pq?.image_url ?? search.input.image_url ?? null,
    currency: median ? stats!.currency : fallbackCurrency,
    reference_price: median,
    attributes: { source_search_id: search.id },
  };
}

/**
 * Monitor options are free-form: the search options are carried over (minus
 * the one-off callback) and `sources` repeats the non-adapter sources so each
 * run searches the same domains and engines.
 */
export function monitorOptionsFromSearch(search: Search): MonitorOptions {
  const options: MonitorOptions = { ...search.options };
  delete options.callback_url;
  const src = search.input.sources;
  if (src) {
    const sources: Record<string, unknown> = {};
    if (src.domains?.length) sources.domains = src.domains;
    if (src.search_engines?.length) sources.search_engines = src.search_engines;
    if (src.reverse_image) sources.reverse_image = true;
    if (Object.keys(sources).length > 0) options.sources = sources;
  }
  return options;
}

/** Creates a tracked product from the search, then a 6-hourly monitor over the same sources. */
export async function trackSearch(search: Search, stats: Stats | null): Promise<{ product: TrackedProduct; monitor: Monitor }> {
  const product = await api.post<ItemResponse<TrackedProduct>>("/tracked-products", trackedProductFromSearch(search, stats), {
    idempotencyKey: newIdempotencyKey(),
  });
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC";
  const body: MonitorCreate = {
    tracked_product_id: product.data.id,
    schedule: MONITOR_SCHEDULE,
    timezone,
    source_ids: search.sources.map((s) => s.source_id).filter((id): id is string => Boolean(id)),
    options: monitorOptionsFromSearch(search),
    alert_rules: DEFAULT_ALERT_RULES,
    status: "active",
  };
  const monitor = await api.post<ItemResponse<Monitor>>("/monitors", body, { idempotencyKey: newIdempotencyKey() });
  return { product: product.data, monitor: monitor.data };
}

interface SearchActionsProps {
  search: Search;
  stats: Stats | null;
  onOpenDiagnostics: () => void;
}

export function SearchActions({ search, stats, onOpenDiagnostics }: SearchActionsProps) {
  const router = useRouter();
  const qc = useQueryClient();
  const toast = useToast();
  const active = search.status === "queued" || search.status === "running";

  const rerun = useMutation({
    mutationFn: () => api.post<ItemResponse<Search>>(`/searches/${search.id}/rerun`, undefined, { idempotencyKey: newIdempotencyKey() }),
    onSuccess: (res) => {
      void qc.invalidateQueries({ queryKey: ["searches"] });
      router.push(`/searches/${res.data.id}`);
    },
    onError: (err) => toast.error("Could not re-run search", errorMessage(err)),
  });

  const track = useMutation({
    mutationFn: () => trackSearch(search, stats),
    onSuccess: ({ product }) => {
      void qc.invalidateQueries({ queryKey: ["tracked-products"] });
      toast.success("Tracking started", "Prices will be checked every 6 hours.", {
        href: `/products/${product.id}`,
        label: "View tracked product",
      });
    },
    onError: (err) => toast.error("Could not track product", errorMessage(err)),
  });

  return (
    <div className="flex flex-wrap items-center gap-2" role="toolbar" aria-label="Search actions">
      <a
        href={`/api/searches/${search.id}/export?format=csv`}
        download={`pricelens-search-${search.id}.csv`}
        className={buttonClasses("secondary", "sm")}
        aria-disabled={active || undefined}
      >
        Export CSV
      </a>
      <Button size="sm" onClick={() => track.mutate()} loading={track.isPending} disabled={track.isSuccess}>
        {track.isSuccess ? "Tracking" : "Track this product"}
      </Button>
      <Button size="sm" onClick={() => rerun.mutate()} loading={rerun.isPending}>
        Re-run
      </Button>
      <Button size="sm" variant="ghost" onClick={onOpenDiagnostics}>
        Diagnostics
      </Button>
    </div>
  );
}
