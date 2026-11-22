import { formatAmount } from "@/lib/money";
import type { Offer, Stats } from "@/lib/types";
import { Skeleton } from "@/components/ui/Skeleton";

interface StatsStripProps {
  stats: Stats | null;
  cheapest?: Offer | null;
  provisional?: boolean;
  loading?: boolean;
}

function Stat({ label, value, sub }: { label: string; value: string; sub?: string }) {
  return (
    <div className="min-w-0 px-4 py-3">
      <dt className="text-xs text-fg-muted">{label}</dt>
      <dd className="mt-0.5 truncate text-lg font-semibold tabular-nums text-fg">{value}</dd>
      {sub && <dd className="truncate text-xs text-fg-muted">{sub}</dd>}
    </div>
  );
}

export function StatsStrip({ stats, cheapest, provisional, loading }: StatsStripProps) {
  if (loading && !stats) return <Skeleton className="h-20 w-full" />;
  const cur = stats?.currency;
  return (
    <section aria-label={provisional ? "Price summary (provisional)" : "Price summary"}>
      <dl className="grid grid-cols-2 divide-border rounded-lg border border-border bg-surface sm:grid-cols-3 lg:grid-cols-5 lg:divide-x [&>div]:border-border max-lg:[&>div]:border-b">
        <Stat
          label="Lowest price"
          value={stats ? formatAmount(stats.min, cur) : "—"}
          sub={cheapest ? (cheapest.site.name ?? cheapest.site.domain) : undefined}
        />
        <Stat label="Median" value={stats ? formatAmount(stats.median, cur) : "—"} />
        <Stat label="Highest" value={stats ? formatAmount(stats.max, cur) : "—"} />
        <Stat label="Offers" value={stats ? String(stats.offer_count) : "—"} />
        <Stat label="Sites" value={stats ? String(stats.site_count) : "—"} />
      </dl>
      {provisional && stats && <p className="mt-1 text-xs text-fg-muted">Provisional — based on offers received so far.</p>}
    </section>
  );
}
