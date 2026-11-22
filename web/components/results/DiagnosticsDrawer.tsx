"use client";

import { useQuery } from "@tanstack/react-query";
import { useMemo } from "react";
import { api } from "@/lib/api";
import { formatDuration, humanize } from "@/lib/format";
import { qk } from "@/lib/query-keys";
import type { SearchTask, TaskStatus } from "@/lib/types";
import { Badge, type Tone } from "@/components/ui/Badge";
import { Drawer } from "@/components/ui/Drawer";
import { ErrorState, EmptyState } from "@/components/ui/EmptyState";
import { Skeleton } from "@/components/ui/Skeleton";
import { Table, TBody, Td, Th, THead } from "@/components/ui/Table";

const TASK_TONE: Record<TaskStatus, Tone> = {
  pending: "neutral",
  running: "accent",
  succeeded: "success",
  failed: "danger",
  skipped_robots: "info",
  skipped_policy: "info",
  blocked: "warning",
  cancelled: "neutral",
};

interface DiagnosticsDrawerProps {
  searchId: string;
  open: boolean;
  onClose: () => void;
  live: boolean;
}

/** Per-source task diagnostics: why did a site return nothing? */
export function DiagnosticsDrawer({ searchId, open, onClose, live }: DiagnosticsDrawerProps) {
  const tasks = useQuery({
    queryKey: qk.tasks(searchId),
    queryFn: ({ signal }) =>
      api.get<{ data: SearchTask[] } | SearchTask[]>(`/searches/${searchId}/tasks`, undefined, signal).then((r) => (Array.isArray(r) ? r : r.data)),
    enabled: open,
    refetchInterval: open && live ? 3000 : false,
  });

  const groups = useMemo(() => {
    const map = new Map<string, SearchTask[]>();
    for (const t of tasks.data ?? []) {
      const key = t.source_name ?? "Unknown source";
      map.set(key, [...(map.get(key) ?? []), t]);
    }
    return [...map.entries()];
  }, [tasks.data]);

  return (
    <Drawer open={open} onClose={onClose} title="Diagnostics" description="Per-source pipeline tasks with status, HTTP code, error and duration.">
      {tasks.isPending ? (
        <div className="space-y-2">
          <Skeleton className="h-6 w-1/3" />
          <Skeleton className="h-32 w-full" />
        </div>
      ) : tasks.isError ? (
        <ErrorState error={tasks.error} onRetry={() => tasks.refetch()} />
      ) : groups.length === 0 ? (
        <EmptyState title="No tasks yet" description="Tasks appear once the search has been planned." />
      ) : (
        <div className="space-y-6">
          {groups.map(([source, list]) => {
            const failed = list.filter((t) => t.status === "failed" || t.status === "blocked").length;
            return (
              <section key={source} aria-label={source}>
                <div className="mb-2 flex items-center justify-between gap-2">
                  <h3 className="text-sm font-semibold text-fg">{source}</h3>
                  <span className="text-xs text-fg-muted">
                    {list.length} tasks{failed ? ` · ${failed} failed` : ""}
                  </span>
                </div>
                <Table caption={`Tasks for ${source}`}>
                  <THead>
                    <tr>
                      <Th>Stage</Th>
                      <Th>Status</Th>
                      <Th className="text-right">HTTP</Th>
                      <Th>Error</Th>
                      <Th className="text-right">Tries</Th>
                      <Th className="text-right">Duration</Th>
                    </tr>
                  </THead>
                  <TBody>
                    {list.map((t) => (
                      <tr key={t.id}>
                        <Td>
                          <span className="font-medium">{humanize(t.stage)}</span>
                          {t.url && (
                            <span className="block max-w-[16rem] truncate text-xs text-fg-muted" title={t.url}>
                              {t.url}
                            </span>
                          )}
                        </Td>
                        <Td>
                          <Badge tone={TASK_TONE[t.status] ?? "neutral"}>{humanize(t.status)}</Badge>
                        </Td>
                        <Td className="text-right tabular-nums">{t.http_status ?? "—"}</Td>
                        <Td className="max-w-[18rem] text-xs">
                          {t.error_code || t.error_message ? (
                            <>
                              {t.error_code && <span className="block font-mono">{t.error_code}</span>}
                              {t.error_message && (
                                <span className="block break-words text-fg-muted" data-testid="task-error-message">
                                  {t.error_message}
                                </span>
                              )}
                            </>
                          ) : (
                            "—"
                          )}
                        </Td>
                        <Td className="text-right tabular-nums">{t.attempts}</Td>
                        <Td className="text-right tabular-nums">{formatDuration(t.duration_ms)}</Td>
                      </tr>
                    ))}
                  </TBody>
                </Table>
              </section>
            );
          })}
        </div>
      )}
    </Drawer>
  );
}
