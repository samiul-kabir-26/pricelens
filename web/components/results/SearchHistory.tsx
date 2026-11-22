"use client";

import { useInfiniteQuery } from "@tanstack/react-query";
import Link from "next/link";
import { useState } from "react";
import { api } from "@/lib/api";
import { formatDateTime, formatRelative } from "@/lib/format";
import { formatAmount } from "@/lib/money";
import { qk } from "@/lib/query-keys";
import type { ListResponse, Search, SearchStatus } from "@/lib/types";
import { Button, LinkButton } from "@/components/ui/Button";
import { EmptyState, ErrorState } from "@/components/ui/EmptyState";
import { TextField } from "@/components/ui/Input";
import { SelectField } from "@/components/ui/Select";
import { Skeleton } from "@/components/ui/Skeleton";
import { Table, TBody, Td, Th, THead } from "@/components/ui/Table";
import { searchDisplayTitle } from "./SearchHeader";
import { SEARCH_STATUS_LABEL, StatusChip } from "./StatusChip";

interface HistoryFilters {
  status: SearchStatus | "";
  created_from: string;
  created_to: string;
}

const STATUS_OPTIONS = [
  { value: "", label: "All statuses" },
  ...(Object.keys(SEARCH_STATUS_LABEL) as SearchStatus[]).map((s) => ({ value: s, label: SEARCH_STATUS_LABEL[s] })),
];

export function SearchHistory() {
  const [filters, setFilters] = useState<HistoryFilters>({ status: "", created_from: "", created_to: "" });

  const query = useInfiniteQuery({
    queryKey: qk.searches({ ...filters }),
    queryFn: ({ pageParam, signal }) =>
      api.get<ListResponse<Search>>(
        "/searches",
        {
          status: filters.status,
          created_from: filters.created_from,
          created_to: filters.created_to,
          cursor: pageParam,
          limit: 25,
        },
        signal,
      ),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.meta?.next_cursor ?? undefined,
    refetchInterval: (q) =>
      q.state.data?.pages.some((p) => p.data.some((s) => s.status === "queued" || s.status === "running")) ? 5000 : false,
  });

  const rows = query.data?.pages.flatMap((p) => p.data) ?? [];

  return (
    <div className="space-y-4">
      <div className="grid gap-3 rounded-lg border border-border bg-surface p-3 sm:grid-cols-3">
        <SelectField
          label="Status"
          value={filters.status}
          onChange={(e) => setFilters((f) => ({ ...f, status: e.target.value as HistoryFilters["status"] }))}
          options={STATUS_OPTIONS}
        />
        <TextField label="From" type="date" value={filters.created_from} max={filters.created_to || undefined} onChange={(e) => setFilters((f) => ({ ...f, created_from: e.target.value }))} />
        <TextField label="To" type="date" value={filters.created_to} min={filters.created_from || undefined} onChange={(e) => setFilters((f) => ({ ...f, created_to: e.target.value }))} />
      </div>

      {query.isPending ? (
        <div className="space-y-2">
          {Array.from({ length: 5 }, (_, i) => (
            <Skeleton key={i} className="h-14 w-full" />
          ))}
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => query.refetch()} />
      ) : rows.length === 0 ? (
        <EmptyState
          title="No searches yet"
          description="Searches you run appear here with their status and best price."
          action={<LinkButton href="/" variant="primary">New search</LinkButton>}
        />
      ) : (
        <>
          <div className="hidden rounded-lg border border-border bg-surface md:block">
            <Table caption="Search history">
              <THead>
                <tr>
                  <Th>Query</Th>
                  <Th>Status</Th>
                  <Th className="text-right">Offers</Th>
                  <Th className="text-right">Lowest</Th>
                  <Th className="text-right">Median</Th>
                  <Th>Created</Th>
                </tr>
              </THead>
              <TBody>
                {rows.map((s) => (
                  <tr key={s.id} className="hover:bg-surface-muted/60">
                    <Td>
                      <Link href={`/searches/${s.id}`} className="font-medium text-fg hover:text-accent hover:underline">
                        {searchDisplayTitle(s)}
                      </Link>
                      <span className="block font-mono text-xs text-fg-muted">{s.id}</span>
                    </Td>
                    <Td>
                      <StatusChip status={s.status} />
                    </Td>
                    <Td className="text-right tabular-nums">{s.stats?.offer_count ?? "—"}</Td>
                    <Td className="text-right tabular-nums">{s.stats ? formatAmount(s.stats.min, s.stats.currency) : "—"}</Td>
                    <Td className="text-right tabular-nums">{s.stats ? formatAmount(s.stats.median, s.stats.currency) : "—"}</Td>
                    <Td className="whitespace-nowrap text-fg-muted">
                      <time dateTime={s.created_at ?? undefined} title={formatDateTime(s.created_at)}>
                        {formatRelative(s.created_at)}
                      </time>
                    </Td>
                  </tr>
                ))}
              </TBody>
            </Table>
          </div>
          <ul className="space-y-2 md:hidden">
            {rows.map((s) => (
              <li key={s.id}>
                <Link href={`/searches/${s.id}`} className="block rounded-lg border border-border bg-surface p-3 hover:bg-surface-muted/60">
                  <div className="flex items-start justify-between gap-2">
                    <span className="font-medium text-fg">{searchDisplayTitle(s)}</span>
                    <StatusChip status={s.status} />
                  </div>
                  <p className="mt-1 text-xs text-fg-muted">
                    {s.stats ? `${s.stats.offer_count} offers · from ${formatAmount(s.stats.min, s.stats.currency)} · ` : ""}
                    {formatRelative(s.created_at)}
                  </p>
                </Link>
              </li>
            ))}
          </ul>
          {query.hasNextPage && (
            <div className="flex justify-center">
              <Button onClick={() => query.fetchNextPage()} loading={query.isFetchingNextPage}>
                Load more
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
