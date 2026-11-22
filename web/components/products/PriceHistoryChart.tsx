"use client";

import { useQuery } from "@tanstack/react-query";
import { useMemo, useState } from "react";
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { Skeleton } from "@/components/ui/Skeleton";
import { TBody, THead, Table, Td, Th } from "@/components/ui/Table";
import { api } from "@/lib/api";
import { cn, formatDate } from "@/lib/format";
import { formatAmount, parseAmount } from "@/lib/money";
import { qk } from "@/lib/query-keys";
import type { HistoryInterval, PriceHistoryMeta, PriceHistoryResponse } from "@/lib/types";

/**
 * Categorical series palette (8 fixed slots, assigned in order, never cycled).
 * Validated with the dataviz validator against this app's light (#ffffff) and
 * dark (#161a21) surfaces: CVD ΔE ≥ 8.4, normal-vision ΔE ≥ 19.3. Three light
 * slots sit below 3:1 contrast, so a table view is always available.
 */
const SERIES_LIGHT = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4", "#008300", "#6250d6", "#e34948"];
const SERIES_DARK = ["#3987e5", "#d95926", "#199e70", "#c98500", "#d55181", "#008300", "#9085e9", "#e66767"];
const MAX_SERIES = SERIES_LIGHT.length;

const paletteCss = `
.ph-chart{${SERIES_LIGHT.map((c, i) => `--ph-s${i + 1}:${c};`).join("")}}
[data-theme="dark"] .ph-chart{${SERIES_DARK.map((c, i) => `--ph-s${i + 1}:${c};`).join("")}}
`;

const RANGES = [
  { days: 30, label: "30 days" },
  { days: 90, label: "90 days" },
  { days: 365, label: "1 year" },
] as const;

function isoDaysAgo(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() - days);
  return d.toISOString().slice(0, 10);
}

interface Props {
  productId: string;
  /** Sites offered in the site filter (e.g. from latest_prices). */
  sites?: string[];
}

interface Row {
  period: string;
  [site: string]: number | string | null;
}

function Segmented<T extends string | number>({
  label,
  options,
  value,
  onChange,
}: {
  label: string;
  options: readonly { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
}) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-md border border-border p-0.5">
      {options.map((o) => (
        <button
          key={String(o.value)}
          type="button"
          role="radio"
          aria-checked={value === o.value}
          onClick={() => onChange(o.value)}
          className={cn(
            "rounded px-2.5 py-1 text-xs",
            value === o.value ? "bg-surface-muted font-medium text-fg" : "text-fg-muted hover:text-fg",
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

const SOURCE_LABEL: Record<PriceHistoryMeta["source"], string> = {
  price_snapshots: "raw snapshots",
  price_daily: "daily rollups",
};

/** What the API actually served: resolved range, interval and backing table. */
function MetaNote({ meta, samples }: { meta: PriceHistoryMeta; samples: number }) {
  return (
    <p className="text-xs text-fg-muted" data-testid="price-history-meta">
      {meta.interval === "week" ? "Weekly" : "Daily"} aggregates from {SOURCE_LABEL[meta.source] ?? meta.source},{" "}
      {formatDate(meta.from)} – {formatDate(meta.to)} · {new Intl.NumberFormat().format(samples)} samples
    </p>
  );
}

export function PriceHistoryChart({ productId, sites = [] }: Props) {
  const [interval, setHistoryInterval] = useState<HistoryInterval>("day");
  const [rangeDays, setRangeDays] = useState<number>(90);
  const [site, setSite] = useState("");
  const [view, setView] = useState<"chart" | "table">("chart");

  const params = { interval, from: isoDaysAgo(rangeDays), to: isoDaysAgo(0), site: site || undefined };
  const query = useQuery({
    queryKey: qk.priceHistory(productId, params),
    queryFn: ({ signal }) =>
      api.get<PriceHistoryResponse>(`/tracked-products/${productId}/price-history`, params, signal),
  });

  const points = useMemo(() => query.data?.data ?? [], [query.data]);
  const meta = query.data?.meta;

  const { rows, seriesSites, hiddenSites, currency } = useMemo(() => {
    // Stable series order: sites by number of points (desc), then name.
    const counts = new Map<string, number>();
    for (const p of points) counts.set(p.site_domain, (counts.get(p.site_domain) ?? 0) + 1);
    const ordered = [...counts.keys()].sort((a, b) => (counts.get(b)! - counts.get(a)!) || a.localeCompare(b));
    const shown = ordered.slice(0, MAX_SERIES);
    const byPeriod = new Map<string, Row>();
    for (const p of points) {
      if (!shown.includes(p.site_domain)) continue;
      const row = byPeriod.get(p.period) ?? { period: p.period };
      row[p.site_domain] = parseAmount(p.avg);
      byPeriod.set(p.period, row);
    }
    return {
      rows: [...byPeriod.values()].sort((a, b) => a.period.localeCompare(b.period)),
      seriesSites: shown,
      hiddenSites: ordered.slice(MAX_SERIES),
      currency: points[0]?.currency ?? null,
    };
  }, [points]);

  const siteOptions = useMemo(() => {
    const all = new Set([...sites, ...points.map((p) => p.site_domain)]);
    return [...all].sort();
  }, [sites, points]);

  const summary = useMemo(() => {
    const avgs = points.map((p) => parseAmount(p.avg)).filter((n): n is number => n !== null);
    if (avgs.length === 0 || !currency) return null;
    return `Average price ranged from ${formatAmount(Math.min(...avgs), currency)} to ${formatAmount(Math.max(...avgs), currency)} across ${new Set(points.map((p) => p.site_domain)).size} sites.`;
  }, [points, currency]);

  return (
    <Card>
      <CardHeader
        title="Price history"
        description={summary ?? "Average observed price per site."}
        actions={
          <Segmented
            label="View"
            options={[
              { value: "chart", label: "Chart" },
              { value: "table", label: "Table" },
            ]}
            value={view}
            onChange={setView}
          />
        }
      />
      <CardBody className="space-y-4">
        <div className="flex flex-wrap items-center gap-2">
          <Segmented
            label="Range"
            options={RANGES.map((r) => ({ value: r.days, label: r.label }))}
            value={rangeDays}
            onChange={setRangeDays}
          />
          <Segmented
            label="Interval"
            options={[
              { value: "day", label: "Daily" },
              { value: "week", label: "Weekly" },
            ]}
            value={interval}
            onChange={setHistoryInterval}
          />
          <label className="sr-only" htmlFor={`ph-site-${productId}`}>
            Site
          </label>
          <select
            id={`ph-site-${productId}`}
            value={site}
            onChange={(e) => setSite(e.target.value)}
            className="h-8 rounded-md border border-border bg-surface px-2 text-xs text-fg"
          >
            <option value="">All sites</option>
            {siteOptions.map((s) => (
              <option key={s} value={s}>
                {s}
              </option>
            ))}
          </select>
        </div>

        {meta && points.length > 0 && <MetaNote meta={meta} samples={points.reduce((n, p) => n + (p.samples ?? 0), 0)} />}

        {query.isPending ? (
          <Skeleton className="h-72 w-full" />
        ) : query.isError ? (
          <ErrorState error={query.error} onRetry={() => query.refetch()} />
        ) : points.length === 0 ? (
          <EmptyState title="No price history yet" description="Snapshots appear after the first monitor run." />
        ) : view === "chart" ? (
          <div className="ph-chart">
            <style>{paletteCss}</style>
            <div className="h-72 w-full" role="img" aria-label={`Price history line chart. ${summary ?? ""}`}>
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={rows} margin={{ top: 8, right: 12, bottom: 0, left: 0 }}>
                  <CartesianGrid stroke="var(--border)" strokeDasharray="0" vertical={false} />
                  <XAxis
                    dataKey="period"
                    tickFormatter={(v: string) => formatDate(v)}
                    tick={{ fill: "var(--fg-muted)", fontSize: 11 }}
                    axisLine={{ stroke: "var(--border)" }}
                    tickLine={false}
                    minTickGap={24}
                  />
                  <YAxis
                    tickFormatter={(v: number) => formatAmount(v, currency)}
                    tick={{ fill: "var(--fg-muted)", fontSize: 11 }}
                    axisLine={false}
                    tickLine={false}
                    width={72}
                    domain={["auto", "auto"]}
                  />
                  <Tooltip
                    cursor={{ stroke: "var(--fg-muted)", strokeWidth: 1 }}
                    content={({ active, payload, label }) => {
                      if (!active || !payload?.length) return null;
                      const items = [...payload]
                        .filter((p) => typeof p.value === "number")
                        .sort((a, b) => (b.value as number) - (a.value as number));
                      return (
                        <div className="rounded-md border border-border bg-surface px-3 py-2 text-xs shadow-md">
                          <p className="mb-1 text-fg-muted">{formatDate(String(label))}</p>
                          <ul className="space-y-0.5">
                            {items.map((p) => (
                              <li key={String(p.dataKey)} className="flex items-center gap-2">
                                <span aria-hidden="true" className="inline-block h-0.5 w-3" style={{ background: p.color }} />
                                <strong className="font-semibold text-fg">{formatAmount(p.value as number, currency)}</strong>
                                <span className="text-fg-muted">{String(p.dataKey)}</span>
                              </li>
                            ))}
                          </ul>
                        </div>
                      );
                    }}
                  />
                  {seriesSites.length > 1 && (
                    <Legend
                      iconType="plainline"
                      wrapperStyle={{ fontSize: 12, color: "var(--fg-muted)" }}
                      formatter={(value: string) => <span style={{ color: "var(--fg)" }}>{value}</span>}
                    />
                  )}
                  {seriesSites.map((s, i) => (
                    <Line
                      key={s}
                      type="monotone"
                      dataKey={s}
                      name={s}
                      stroke={`var(--ph-s${i + 1})`}
                      strokeWidth={2}
                      dot={false}
                      activeDot={{ r: 4, strokeWidth: 2, stroke: "var(--surface)" }}
                      connectNulls
                      isAnimationActive={false}
                    />
                  ))}
                </LineChart>
              </ResponsiveContainer>
            </div>
            {hiddenSites.length > 0 && (
              <p className="mt-2 text-xs text-fg-muted">
                {hiddenSites.length} more site{hiddenSites.length > 1 ? "s" : ""} not plotted — filter by site or use the table view.
              </p>
            )}
          </div>
        ) : (
          <Table caption="Price history by period and site">
            <THead>
              <tr>
                <Th>Period</Th>
                <Th>Site</Th>
                <Th className="text-right">Min</Th>
                <Th className="text-right">Avg</Th>
                <Th className="text-right">Max</Th>
                <Th className="text-right">Samples</Th>
              </tr>
            </THead>
            <TBody>
              {[...points]
                .sort((a, b) => b.period.localeCompare(a.period) || a.site_domain.localeCompare(b.site_domain))
                .map((p) => (
                  <tr key={`${p.period}-${p.site_domain}`}>
                    <Td>{formatDate(p.period)}</Td>
                    <Td>{p.site_domain}</Td>
                    <Td className="text-right tabular-nums">{formatAmount(p.min, p.currency)}</Td>
                    <Td className="text-right tabular-nums">{formatAmount(p.avg, p.currency)}</Td>
                    <Td className="text-right tabular-nums">{formatAmount(p.max, p.currency)}</Td>
                    <Td className="text-right tabular-nums text-fg-muted">{p.samples}</Td>
                  </tr>
                ))}
            </TBody>
          </Table>
        )}
      </CardBody>
    </Card>
  );
}
