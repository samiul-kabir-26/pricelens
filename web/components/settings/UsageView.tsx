"use client";

import { useQuery } from "@tanstack/react-query";
import { Badge } from "@/components/ui/Badge";
import { Card, CardBody, CardHeader } from "@/components/ui/Card";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { Skeleton } from "@/components/ui/Skeleton";
import { api } from "@/lib/api";
import { formatDate, formatNumber, humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import type { ItemResponse, Usage } from "@/lib/types";

const METRIC_LABEL: Record<string, string> = {
  searches: "Searches this period",
  render_pages: "Rendered pages",
  provider_calls: "Search provider calls",
  active_monitors: "Active monitors",
  concurrent_searches: "Concurrent searches",
};

const FEATURE_LABEL: Record<string, string> = {
  "llm-extraction": "LLM extraction",
  "image-search": "Image search",
  monitors: "Monitors",
  "search-engines": "Search engines",
  "byo-proxies": "Bring-your-own proxies",
};

export function featureLabel(key: string): string {
  return FEATURE_LABEL[key] ?? humanize(key.replace(/-/g, "_"));
}

function limitValue(value: unknown): string {
  if (value === null || value === undefined) return "Unlimited";
  if (typeof value === "number") return formatNumber(value);
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "string") return value;
  return JSON.stringify(value);
}

/** Plan feature flags as on/off badges. */
export function FeatureFlags({ features }: { features: Usage["features"] }) {
  const entries = Object.entries(features ?? {}).sort(([a], [b]) => featureLabel(a).localeCompare(featureLabel(b)));
  if (entries.length === 0) return <p className="text-sm text-fg-muted">No feature flags reported for this plan.</p>;
  return (
    <ul className="flex flex-wrap gap-2" aria-label="Plan features">
      {entries.map(([key, on]) => (
        <li key={key}>
          <Badge tone={on ? "success" : "neutral"} dot data-feature={key} data-enabled={on ? "true" : "false"}>
            {featureLabel(key)}
            <span className="sr-only">:</span>
            <span className={on ? "font-semibold" : "font-normal"}>{on ? "On" : "Off"}</span>
          </Badge>
        </li>
      ))}
    </ul>
  );
}

export function UsageView() {
  const query = useQuery({
    queryKey: qk.usage(),
    queryFn: ({ signal }) => api.get<ItemResponse<Usage>>("/usage", undefined, signal),
    refetchInterval: 60_000,
  });

  if (query.isPending) {
    return (
      <div className="space-y-4" role="status" aria-label="Loading usage">
        <Skeleton className="h-8 w-1/3" />
        <div className="grid gap-4 sm:grid-cols-2">
          {Array.from({ length: 4 }, (_, i) => (
            <Skeleton key={i} className="h-24" />
          ))}
        </div>
      </div>
    );
  }
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const usage = query.data.data;

  return (
    <div>
      <PageHeader
        title="Usage"
        description={
          <>
            {usage.plan.name} plan · {formatDate(usage.period.start)} – {formatDate(usage.period.end)}
          </>
        }
      />
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {usage.metrics.map((m) => {
          const ratio = m.limit ? m.used / m.limit : 0;
          const tone = m.limit === null ? "accent" : ratio >= 1 ? "danger" : ratio >= 0.8 ? "warning" : "accent";
          const label = METRIC_LABEL[m.metric] ?? humanize(m.metric);
          return (
            <li key={m.metric}>
              <Card className="p-4">
                <p className="text-sm text-fg-muted">{label}</p>
                <p className="mt-1 text-2xl font-semibold tabular-nums text-fg">
                  {formatNumber(m.used)}
                  <span className="ml-1 text-sm font-normal text-fg-muted">
                    / {m.limit === null ? "Unlimited" : formatNumber(m.limit)}
                  </span>
                </p>
                {m.limit !== null ? (
                  <>
                    <ProgressBar className="mt-3" value={m.used} max={m.limit} tone={tone} label={`${label}: ${m.used} of ${m.limit}`} />
                    <p className={`mt-1.5 text-xs ${tone === "danger" ? "text-danger" : tone === "warning" ? "text-warning" : "text-fg-muted"}`}>
                      {ratio >= 1 ? "Limit reached" : `${Math.round(ratio * 100)}% used`}
                    </p>
                  </>
                ) : (
                  <p className="mt-3 text-xs text-fg-muted">No limit on this plan</p>
                )}
              </Card>
            </li>
          );
        })}
      </ul>

      <div className="mt-6 grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Features" description="Capabilities included in your plan." />
          <CardBody>
            <FeatureFlags features={usage.features} />
          </CardBody>
        </Card>
        {usage.limits && Object.keys(usage.limits).length > 0 && (
          <Card>
            <CardHeader title="Plan limits" description="Raw limits configured for this plan." />
            <CardBody>
              <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                {Object.entries(usage.limits).map(([key, value]) => (
                  <div key={key} className="flex justify-between gap-3 border-b border-border pb-1.5 last:border-b-0">
                    <dt className="text-fg-muted">{METRIC_LABEL[key] ?? humanize(key)}</dt>
                    <dd className="break-all text-right font-medium tabular-nums text-fg">{limitValue(value)}</dd>
                  </div>
                ))}
              </dl>
            </CardBody>
          </Card>
        )}
      </div>
    </div>
  );
}
