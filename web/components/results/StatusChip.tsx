import type { SearchStatus } from "@/lib/types";
import { Badge, type Tone } from "@/components/ui/Badge";

const MAP: Record<SearchStatus, { tone: Tone; label: string }> = {
  queued: { tone: "neutral", label: "Queued" },
  running: { tone: "accent", label: "Running" },
  completed: { tone: "success", label: "Completed" },
  partially_completed: { tone: "warning", label: "Partially completed" },
  failed: { tone: "danger", label: "Failed" },
  cancelled: { tone: "neutral", label: "Cancelled" },
};

export const SEARCH_STATUS_LABEL = Object.fromEntries(Object.entries(MAP).map(([k, v]) => [k, v.label])) as Record<
  SearchStatus,
  string
>;

export function StatusChip({ status }: { status: SearchStatus }) {
  const entry = MAP[status] ?? { tone: "neutral" as Tone, label: status };
  return (
    <Badge tone={entry.tone} dot className={status === "running" ? "[&>span:first-child]:animate-pulse" : undefined}>
      {entry.label}
    </Badge>
  );
}
