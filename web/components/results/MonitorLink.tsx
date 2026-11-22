"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { api } from "@/lib/api";
import { qk } from "@/lib/query-keys";
import type { ItemResponse, Monitor } from "@/lib/types";

/**
 * "Run by a monitor" note for searches started on a schedule. Monitors live on
 * their tracked product's page, so the monitor is fetched to find it.
 */
export function MonitorLink({ monitorId }: { monitorId: string }) {
  const query = useQuery({
    queryKey: qk.monitor(monitorId),
    queryFn: ({ signal }) => api.get<ItemResponse<Monitor>>(`/monitors/${monitorId}`, undefined, signal).then((r) => r.data),
    staleTime: 5 * 60_000,
    retry: false,
  });
  const productId = query.data?.tracked_product_id ?? null;

  return (
    <p className="mt-1 text-xs text-fg-muted" data-testid="search-monitor">
      Scheduled run by{" "}
      {productId ? (
        <Link href={`/products/${productId}#monitor-${monitorId}`} className="font-medium text-accent hover:underline">
          monitor <span className="font-mono">{monitorId}</span>
        </Link>
      ) : (
        <span className="font-medium text-fg">
          monitor <span className="font-mono">{monitorId}</span>
        </span>
      )}
      {query.isError && " (no longer available)"}
    </p>
  );
}
