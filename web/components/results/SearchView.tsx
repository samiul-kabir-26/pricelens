"use client";

import { useInfiniteQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { api, ApiError, errorMessage } from "@/lib/api";
import { isActiveStatus, POLL_INTERVAL_MS, useSearchLive } from "@/lib/hooks/useSearchLive";
import { computeStats, defaultOfferFilters, filtersToQuery, sortOffers } from "@/lib/offers";
import { qk } from "@/lib/query-keys";
import type { ItemResponse, Offer, OfferFilters as Filters, OffersResponse, Search } from "@/lib/types";
import { Button, LinkButton } from "@/components/ui/Button";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { PageSkeleton, Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toast";
import { DiagnosticsDrawer } from "./DiagnosticsDrawer";
import { OfferCard } from "./OfferCard";
import { OfferFilters } from "./OfferFilters";
import { OfferTable } from "./OfferTable";
import { PriceDistribution } from "./PriceDistribution";
import { SearchActions } from "./SearchActions";
import { SearchHeader } from "./SearchHeader";
import { SourceProgressList } from "./SourceProgressList";
import { StatsStrip } from "./StatsStrip";

const PAGE_SIZE = 100;

export function SearchView({ id }: { id: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const live = useSearchLive(id);
  const search = live.data;
  const active = isActiveStatus(search?.status);
  const [filters, setFilters] = useState<Filters>(defaultOfferFilters);
  const [diagnosticsOpen, setDiagnosticsOpen] = useState(false);

  const offers = useInfiniteQuery({
    queryKey: qk.offers(id, filters),
    queryFn: ({ pageParam, signal }) =>
      api.get<OffersResponse>(`/searches/${id}/offers`, { ...filtersToQuery(filters), cursor: pageParam, limit: PAGE_SIZE }, signal),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
    enabled: Boolean(search),
    // Without a socket, refresh offers alongside the 2 s status poll.
    refetchInterval: active && live.transport === "polling" ? POLL_INTERVAL_MS : false,
    placeholderData: (prev) => prev,
  });

  // Reconcile once a search leaves queued/running (covers the polling path;
  // the socket path also invalidates on SearchCompleted).
  const prevActive = useRef(active);
  useEffect(() => {
    if (prevActive.current && !active && search) {
      void qc.invalidateQueries({ queryKey: qk.offersRoot(id) });
      void qc.invalidateQueries({ queryKey: qk.tasks(id) });
      void qc.invalidateQueries({ queryKey: ["searches"] });
    }
    prevActive.current = active;
  }, [active, search, id, qc]);

  const cancel = useMutation({
    mutationFn: () => api.post<ItemResponse<Search>>(`/searches/${id}/cancel`),
    onSuccess: (res) => {
      if (res?.data) qc.setQueryData(qk.search(id), res.data);
      void qc.invalidateQueries({ queryKey: qk.search(id) });
      toast.success("Search cancelled");
    },
    onError: (err) => toast.error("Could not cancel search", errorMessage(err)),
  });

  const allOffers: Offer[] = useMemo(
    () => sortOffers(offers.data?.pages.flatMap((p) => p.data) ?? [], filters.sort),
    [offers.data, filters.sort],
  );
  const serverStats = offers.data?.pages[0]?.meta?.stats ?? search?.stats ?? null;
  const provisional = !serverStats && allOffers.length > 0;
  const stats = serverStats ?? computeStats(allOffers, search?.target_currency ?? null);
  const cheapest = allOffers.find((o) => o.id === stats?.cheapest_offer_id) ?? null;

  const sites = useMemo(() => {
    const map = new Map<string, string>();
    for (const s of search?.sources ?? []) if (s.domain) map.set(s.domain, s.name ?? s.domain);
    for (const o of allOffers) if (!map.has(o.site.domain)) map.set(o.site.domain, o.site.name ?? o.site.domain);
    return [...map.entries()].map(([domain, name]) => ({ domain, name })).sort((a, b) => a.name.localeCompare(b.name));
  }, [search?.sources, allOffers]);

  const onFiltersChange = useCallback((f: Filters) => setFilters(f), []);

  if (live.isPending) return <PageSkeleton />;
  if (live.isError) {
    if (live.error instanceof ApiError && live.error.status === 404) {
      return (
        <EmptyState
          title="Search not found"
          description="It may have been deleted or belongs to another tenant."
          action={<LinkButton href="/searches">Back to searches</LinkButton>}
        />
      );
    }
    return <ErrorState error={live.error} onRetry={() => live.refetch()} />;
  }
  if (!search) return null;

  return (
    <div className="space-y-6">
      <SearchHeader search={search} transport={live.transport} onCancel={() => cancel.mutate()} cancelling={cancel.isPending} />
      <SearchActions search={search} stats={stats} onOpenDiagnostics={() => setDiagnosticsOpen(true)} />
      <SourceProgressList status={search.status} progress={search.progress} sources={search.sources} />
      <StatsStrip stats={stats} cheapest={cheapest} provisional={provisional} loading={offers.isPending} />
      <PriceDistribution offers={allOffers} stats={stats} />
      <OfferFilters value={filters} onChange={onFiltersChange} sites={sites} currency={search.target_currency} />

      <section aria-labelledby="offers-heading" aria-busy={offers.isFetching || undefined}>
        <div className="mb-3 flex items-center justify-between">
          <h2 id="offers-heading" className="text-sm font-semibold text-fg">
            Offers <span className="font-normal text-fg-muted">({allOffers.length}{offers.hasNextPage ? "+" : ""})</span>
          </h2>
          {offers.isFetching && !offers.isPending && <span className="text-xs text-fg-muted">Refreshing…</span>}
        </div>
        {offers.isPending ? (
          <Skeleton className="h-48 w-full" />
        ) : offers.isError ? (
          <ErrorState error={offers.error} onRetry={() => offers.refetch()} />
        ) : allOffers.length === 0 ? (
          <EmptyState
            title={active ? "Waiting for offers…" : "No offers match"}
            description={
              active
                ? "Offers appear here as each source responds."
                : "Try including possible matches, widening the price range, or open Diagnostics to see what each source returned."
            }
            action={
              !active ? (
                <Button size="sm" onClick={() => setDiagnosticsOpen(true)}>
                  Open diagnostics
                </Button>
              ) : undefined
            }
          />
        ) : (
          <>
            <div className="hidden rounded-lg border border-border bg-surface lg:block">
              <OfferTable
                offers={allOffers}
                sort={filters.sort}
                onSortChange={(sort) => setFilters((f) => ({ ...f, sort }))}
                cheapestOfferId={stats?.cheapest_offer_id}
              />
            </div>
            <ul className="grid gap-3 sm:grid-cols-2 lg:hidden">
              {allOffers.map((o) => (
                <li key={o.id}>
                  <OfferCard offer={o} cheapest={o.id === stats?.cheapest_offer_id} />
                </li>
              ))}
            </ul>
            {offers.hasNextPage && (
              <div className="mt-4 flex justify-center">
                <Button onClick={() => offers.fetchNextPage()} loading={offers.isFetchingNextPage}>
                  Load more offers
                </Button>
              </div>
            )}
          </>
        )}
      </section>

      <DiagnosticsDrawer searchId={id} open={diagnosticsOpen} onClose={() => setDiagnosticsOpen(false)} live={active} />
    </div>
  );
}
