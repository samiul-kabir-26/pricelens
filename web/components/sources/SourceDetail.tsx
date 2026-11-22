"use client";

import { useQuery } from "@tanstack/react-query";
import Link from "next/link";
import { Badge } from "@/components/ui/Badge";
import { ErrorState } from "@/components/ui/EmptyState";
import { PageHeader } from "@/components/ui/PageHeader";
import { PageSkeleton } from "@/components/ui/Skeleton";
import { api } from "@/lib/api";
import { formatDateTime, formatRelative, humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import type { ItemResponse, Source } from "@/lib/types";
import { AdapterEditor } from "./AdapterEditor";
import { AdapterTestPanel } from "./AdapterTestPanel";
import { SourceHealthBadge } from "./SourceHealthBadge";

export function SourceDetail({ id }: { id: string }) {
  const query = useQuery({
    queryKey: qk.source(id),
    queryFn: ({ signal }) => api.get<ItemResponse<Source>>(`/sources/${id}`, undefined, signal),
  });

  if (query.isPending) return <PageSkeleton />;
  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />;
  const source = query.data.data;

  return (
    <div>
      <nav aria-label="Breadcrumb" className="mb-2 text-sm text-fg-muted">
        <Link href="/sources" className="hover:underline">
          Sources
        </Link>{" "}
        / <span className="text-fg">{source.name}</span>
      </nav>
      <PageHeader
        title={source.name}
        description={
          <span className="flex flex-wrap items-center gap-2">
            <span>{humanize(source.type)}</span>
            {source.domain && <span className="font-mono text-xs">{source.domain}</span>}
            <SourceHealthBadge status={source.health_status} />
            {source.is_global && <Badge tone="info">Global</Badge>}
            {!source.enabled && <Badge>Disabled</Badge>}
            <span className="text-xs">Updated {formatDateTime(source.updated_at)}</span>
            {source.type === "feed" && (
              <span className="text-xs" data-testid="feed-refreshed">
                Feed refreshed{" "}
                {source.feed_refreshed_at ? (
                  <time dateTime={source.feed_refreshed_at} title={formatDateTime(source.feed_refreshed_at)}>
                    {formatRelative(source.feed_refreshed_at)}
                  </time>
                ) : (
                  "never"
                )}
              </span>
            )}
          </span>
        }
      />
      {source.forked_from_id && (
        <p className="mb-4 text-sm text-fg-muted">
          Forked from{" "}
          <Link href={`/sources/${source.forked_from_id}`} className="text-accent hover:underline">
            the global adapter
          </Link>
          .
        </p>
      )}
      <div className="grid gap-6 xl:grid-cols-2">
        <AdapterEditor key={source.updated_at ?? source.id} source={source} />
        <AdapterTestPanel sourceId={source.id} />
      </div>
    </div>
  );
}
