"use client";

import { useQuery, useQueryClient, type InfiniteData, type QueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { api } from "../api";
import { getEcho, onSocketState, toEchoChannelName } from "../echo";
import { offerMatchesFilters } from "../offers";
import { qk } from "../query-keys";
import {
  ACTIVE_SEARCH_STATUSES,
  type ItemResponse,
  type Offer,
  type OfferFilters,
  type OfferFoundEvent,
  type OffersResponse,
  type Search,
  type SearchCompletedEvent,
  type SearchProgressUpdatedEvent,
  type SearchStatus,
} from "../types";

export const POLL_INTERVAL_MS = 2000;
/** Safety-net poll while the socket is healthy (events may be dropped). */
const SOCKET_SAFETY_POLL_MS = 15000;

export function isActiveStatus(status: SearchStatus | undefined): boolean {
  return status !== undefined && ACTIVE_SEARCH_STATUSES.includes(status);
}

export type LiveTransport = "socket" | "polling" | "idle";

/**
 * Optimistically insert a streamed offer into every cached offers list for
 * this search whose filters it satisfies. Lists are reconciled by a refetch
 * when the search completes.
 */
export function insertStreamedOffer(qc: QueryClient, searchId: string, offer: Offer): void {
  const queries = qc.getQueryCache().findAll({ queryKey: qk.offersRoot(searchId) });
  for (const query of queries) {
    const filters = (query.queryKey[3] ?? {}) as OfferFilters;
    if (!offerMatchesFilters(offer, filters)) continue;
    qc.setQueryData<InfiniteData<OffersResponse>>(query.queryKey, (old) => {
      if (!old || old.pages.length === 0) return old;
      if (old.pages.some((p) => p.data.some((o) => o.id === offer.id))) return old;
      const [first, ...rest] = old.pages;
      return { ...old, pages: [{ ...first, data: [...first.data, offer] }, ...rest] };
    });
  }
}

/**
 * Loads a search and keeps it live: subscribes to its private Reverb channel
 * when Echo is configured, otherwise (or when the socket is unavailable)
 * polls GET /searches/{id} every 2 s while the search is queued/running.
 */
export function useSearchLive(id: string) {
  const qc = useQueryClient();
  const [socketHealthy, setSocketHealthy] = useState(false);

  const query = useQuery({
    queryKey: qk.search(id),
    queryFn: ({ signal }) => api.get<ItemResponse<Search>>(`/searches/${id}`, undefined, signal).then((r) => r.data),
    refetchInterval: (q) => {
      if (!isActiveStatus(q.state.data?.status)) return false;
      return socketHealthy ? SOCKET_SAFETY_POLL_MS : POLL_INTERVAL_MS;
    },
    refetchIntervalInBackground: false,
  });

  const channel = query.data?.realtime?.channel;
  const active = isActiveStatus(query.data?.status);

  useEffect(() => {
    if (!channel || !active) return;
    const echo = getEcho();
    if (!echo) return;

    let connected = false;
    let subscribed = false;
    let disposed = false;
    const update = () => {
      if (!disposed) setSocketHealthy(connected && subscribed);
    };

    const name = toEchoChannelName(channel);
    let stopState: () => void = () => {};
    try {
      stopState = onSocketState(echo, (state) => {
        connected = state === "connected";
        update();
      });

      echo
        .private(name)
        .subscribed(() => {
          subscribed = true;
          update();
        })
        .error(() => {
          subscribed = false;
          update();
        })
        .listen(".SearchProgressUpdated", (e: SearchProgressUpdatedEvent) => {
          if (e.search_id && e.search_id !== id) return;
          qc.setQueryData<Search>(qk.search(id), (old) =>
            old ? { ...old, status: e.status, progress: e.progress, sources: e.sources ?? old.sources } : old,
          );
        })
        .listen(".OfferFound", (e: OfferFoundEvent) => {
          if (e.offer) insertStreamedOffer(qc, id, e.offer);
        })
        .listen(".SearchCompleted", (e: SearchCompletedEvent) => {
          if (e.search_id && e.search_id !== id) return;
          qc.setQueryData<Search>(qk.search(id), (old) => (old ? { ...old, status: e.status, stats: e.stats } : old));
          // Reconcile everything (search, offers, tasks) with the server.
          void qc.invalidateQueries({ queryKey: qk.search(id) });
        });
    } catch {
      // Socket setup failed: polling stays in effect.
      subscribed = false;
      update();
    }

    return () => {
      disposed = true;
      stopState();
      try {
        echo.leave(name);
      } catch {
        // ignore
      }
      setSocketHealthy(false);
    };
  }, [channel, active, id, qc]);

  const transport: LiveTransport = !active ? "idle" : socketHealthy ? "socket" : "polling";
  return { ...query, transport, socketHealthy };
}
