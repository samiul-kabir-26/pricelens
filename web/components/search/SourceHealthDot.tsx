import type { HealthStatus } from "@/lib/types";
import { cn } from "@/lib/format";

const COLOR: Record<HealthStatus, string> = { healthy: "bg-success", degraded: "bg-warning", failing: "bg-danger" };

/** Compact health indicator with a text label for screen readers and tooltip. */
export function SourceHealthDot({ status }: { status: HealthStatus }) {
  return (
    <span className="inline-flex items-center gap-1 text-xs text-fg-muted" title={`Health: ${status}`}>
      <span className={cn("h-2 w-2 rounded-full", COLOR[status] ?? "bg-border")} aria-hidden="true" />
      <span className="sr-only sm:not-sr-only">{status}</span>
    </span>
  );
}
