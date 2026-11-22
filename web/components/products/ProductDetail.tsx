"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import Link from "next/link";
import { Badge, type Tone } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSkeleton, Skeleton } from "@/components/ui/Skeleton";
import { useToast } from "@/components/ui/Toast";
import { api, errorMessage } from "@/lib/api";
import { formatDateTime, formatRelative, humanize } from "@/lib/format";
import { formatAmount, formatMoney } from "@/lib/money";
import { qk } from "@/lib/query-keys";
import type { Alert, AlertType, ItemResponse, ListResponse, Monitor, TrackedProduct } from "@/lib/types";
import { AlertRulesForm } from "./AlertRulesForm";
import { PriceHistoryChart } from "./PriceHistoryChart";
import { SitePriceTable } from "./SitePriceTable";

const ALERT_TONE: Record<AlertType, Tone> = {
  price_drop: "success",
  price_increase: "warning",
  map_violation: "danger",
  back_in_stock: "info",
  out_of_stock: "warning",
  new_seller: "accent",
};

export function ProductDetail({ id }: { id: string }) {
  const query = useQuery({
    queryKey: qk.product(id),
    queryFn: ({ signal }) => api.get<ItemResponse<TrackedProduct>>(`/tracked-products/${id}`, undefined, signal),
  });

  if (query.isPending) return <PageSkeleton />;
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const p = query.data.data;

  return (
    <div>
      <nav aria-label="Breadcrumb" className="mb-2 text-sm text-fg-muted">
        <Link href="/products" className="hover:underline">
          Products
        </Link>{" "}
        / <span className="text-fg">{p.title}</span>
      </nav>
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start">
        {p.image_url && (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={p.image_url} alt="" className="h-20 w-20 shrink-0 rounded-md border border-border bg-surface object-contain" />
        )}
        <div className="min-w-0 flex-1">
          <PageHeader
            title={p.title}
            description={
              <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                {p.brand && <span>{p.brand}</span>}
                {p.gtin && <span className="font-mono text-xs">GTIN {p.gtin}</span>}
                {p.mpn && <span className="font-mono text-xs">MPN {p.mpn}</span>}
                {p.external_ref && <span className="font-mono text-xs">Ref {p.external_ref}</span>}
                {p.archived_at && <Badge>Archived</Badge>}
              </span>
            }
          />
        </div>
      </div>
      <dl className="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-4">
        {[
          ["Reference price", formatMoney(p.reference_price)],
          ["MAP price", formatMoney(p.map_price)],
          ["Sites tracked", String(p.latest_prices?.length ?? 0)],
          ["Tracked since", formatDateTime(p.created_at)],
        ].map(([label, value]) => (
          <div key={label} className="rounded-lg border border-border bg-surface px-4 py-3">
            <dt className="text-xs text-fg-muted">{label}</dt>
            <dd className="mt-0.5 text-base font-semibold tabular-nums">{value}</dd>
          </div>
        ))}
      </dl>

      <div className="space-y-6">
        <PriceHistoryChart productId={p.id} sites={(p.latest_prices ?? []).map((l) => l.site_domain)} />
        <div className="grid gap-6 xl:grid-cols-2">
          <SitePriceTable prices={p.latest_prices ?? []} mapPrice={p.map_price} />
          <AlertsCard productId={p.id} currency={p.currency} />
        </div>
        <MonitorsCard productId={p.id} />
      </div>
    </div>
  );
}

function alertSummary(a: Alert, currency: string): string {
  const pl = a.payload as Record<string, unknown>;
  const site = a.site_domain ?? (typeof pl.site_domain === "string" ? pl.site_domain : null);
  const amt = (k: string) => {
    const v = pl[k];
    if (v && typeof v === "object" && "amount" in v) {
      const m = v as { amount: string; currency?: string };
      return formatAmount(m.amount, m.currency ?? currency);
    }
    return typeof v === "string" || typeof v === "number" ? formatAmount(v, currency) : null;
  };
  const parts: string[] = [];
  if (site) parts.push(site);
  const from = amt("previous_price");
  const to = amt("price");
  if (from && to) parts.push(`${from} → ${to}`);
  else if (to) parts.push(to);
  if (typeof pl.change_percent === "number") parts.push(`${pl.change_percent > 0 ? "+" : ""}${pl.change_percent}%`);
  return parts.join(" · ");
}

function AlertsCard({ productId, currency }: { productId: string; currency: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const filters = { tracked_product_id: productId };
  const query = useQuery({
    queryKey: qk.alerts(filters),
    queryFn: ({ signal }) => api.get<ListResponse<Alert>>("/alerts", { ...filters, limit: 50 }, signal),
  });

  const ack = useMutation({
    mutationFn: (id: string) => api.post<ItemResponse<Alert>>(`/alerts/${id}/acknowledge`),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["alerts"] }),
    onError: (err) => toast.error("Could not acknowledge alert", errorMessage(err)),
  });

  const alerts = (query.data?.data ?? []).filter((a) => a.tracked_product_id === productId);
  const open = alerts.filter((a) => !a.acknowledged_at).length;

  return (
    <Card>
      <CardHeader title="Alerts" description={open > 0 ? `${open} unacknowledged` : "Price and availability alerts from monitor runs."} />
      <CardBody>
        {query.isPending ? (
          <Skeleton className="h-32 w-full" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : alerts.length === 0 ? (
          <EmptyState title="No alerts yet" />
        ) : (
          <ul className="divide-y divide-border">
            {alerts.map((a) => (
              <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <Badge tone={ALERT_TONE[a.type] ?? "neutral"}>{humanize(a.type)}</Badge>
                    <span className="text-xs text-fg-muted" title={formatDateTime(a.triggered_at)}>
                      {formatRelative(a.triggered_at)}
                    </span>
                  </div>
                  <p className="mt-1 truncate text-sm">{alertSummary(a, currency) || "—"}</p>
                </div>
                {a.acknowledged_at ? (
                  <span className="text-xs text-fg-muted">Acknowledged</span>
                ) : (
                  <Button size="sm" onClick={() => ack.mutate(a.id)} loading={ack.isPending && ack.variables === a.id}>
                    Acknowledge
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}

function MonitorsCard({ productId }: { productId: string }) {
  const qc = useQueryClient();
  const toast = useToast();
  const filters = { tracked_product_id: productId };
  const query = useQuery({
    queryKey: qk.monitors(filters),
    queryFn: ({ signal }) => api.get<ListResponse<Monitor>>("/monitors", { ...filters, limit: 50 }, signal),
  });

  const run = useMutation({
    mutationFn: (id: string) => api.post<unknown>(`/monitors/${id}/run`),
    onSuccess: () => {
      toast.success("Monitor run queued", "Prices will refresh when the search completes.");
      qc.invalidateQueries({ queryKey: ["monitors"] });
    },
    onError: (err) => toast.error("Could not run monitor", errorMessage(err)),
  });

  const setStatus = useMutation({
    mutationFn: ({ id, status }: { id: string; status: Monitor["status"] }) =>
      api.patch<ItemResponse<Monitor>>(`/monitors/${id}`, { status }),
    onSuccess: (_r, v) => {
      toast.success(v.status === "paused" ? "Monitor paused" : "Monitor resumed");
      qc.invalidateQueries({ queryKey: ["monitors"] });
    },
    onError: (err) => toast.error("Could not update monitor", errorMessage(err)),
  });

  // The API may ignore the filter; enforce it client-side as well.
  const monitors = (query.data?.data ?? []).filter((m) => m.tracked_product_id === productId);

  return (
    <Card>
      <CardHeader title="Monitors" description="Scheduled searches that write price snapshots and evaluate alert rules." />
      <CardBody>
        {query.isPending ? (
          <Skeleton className="h-32 w-full" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : monitors.length === 0 ? (
          <EmptyState title="No monitors" description='Create one from a search with "Track this product".' />
        ) : (
          <ul className="space-y-6">
            {monitors.map((m) => (
              <li key={m.id} id={`monitor-${m.id}`} className="scroll-mt-20 rounded-lg border border-border p-4 target:border-accent">
                <div className="flex flex-wrap items-start justify-between gap-3">
                  <dl className="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                    <div>
                      <dt className="text-xs text-fg-muted">Status</dt>
                      <dd>
                        <Badge tone={m.status === "active" ? "success" : "neutral"} dot>
                          {humanize(m.status)}
                        </Badge>
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-fg-muted">Schedule</dt>
                      <dd className="font-mono text-xs">
                        {m.schedule} <span className="text-fg-muted">({m.timezone})</span>
                      </dd>
                    </div>
                    <div>
                      <dt className="text-xs text-fg-muted">Next run</dt>
                      <dd title={formatDateTime(m.next_run_at)}>{m.status === "active" ? formatRelative(m.next_run_at) : "—"}</dd>
                    </div>
                    <div>
                      <dt className="text-xs text-fg-muted">Last run</dt>
                      <dd title={formatDateTime(m.last_run_at)}>{formatRelative(m.last_run_at)}</dd>
                    </div>
                  </dl>
                  <div className="flex gap-2">
                    <Button size="sm" onClick={() => run.mutate(m.id)} loading={run.isPending && run.variables === m.id}>
                      Run now
                    </Button>
                    <Button
                      size="sm"
                      onClick={() => setStatus.mutate({ id: m.id, status: m.status === "active" ? "paused" : "active" })}
                      loading={setStatus.isPending && setStatus.variables?.id === m.id}
                    >
                      {m.status === "active" ? "Pause" : "Resume"}
                    </Button>
                  </div>
                </div>
                <p className="mt-2 text-xs text-fg-muted">{m.source_ids.length} sources</p>
                <div className="mt-4 border-t border-border pt-4">
                  <AlertRulesForm monitor={m} />
                </div>
              </li>
            ))}
          </ul>
        )}
      </CardBody>
    </Card>
  );
}
