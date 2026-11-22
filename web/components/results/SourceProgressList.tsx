import type { Progress, SearchSourceProgress, SearchSourceStatus, SearchStatus } from "@/lib/types";
import { Badge, type Tone } from "@/components/ui/Badge";
import { ProgressBar } from "@/components/ui/ProgressBar";
import { humanize } from "@/lib/format";

const SOURCE_TONE: Record<SearchSourceStatus, Tone> = {
  pending: "neutral",
  running: "accent",
  completed: "success",
  partially_completed: "warning",
  failed: "danger",
  cancelled: "neutral",
};

interface SourceProgressListProps {
  status: SearchStatus;
  progress: Progress;
  sources: SearchSourceProgress[];
}

export function progressSummary(status: SearchStatus, progress: Progress): string {
  if (status === "queued") return "Search queued, waiting for workers.";
  if (status === "running") {
    return progress.total > 0 ? `${progress.done} of ${progress.total} tasks complete.` : "Planning search…";
  }
  return `Search ${humanize(status).toLowerCase()}. ${progress.done} of ${progress.total} tasks processed.`;
}

export function SourceProgressList({ status, progress, sources }: SourceProgressListProps) {
  const running = status === "queued" || status === "running";
  return (
    <section aria-labelledby="source-progress-heading" className="space-y-3">
      <div className="flex items-center justify-between gap-3">
        <h2 id="source-progress-heading" className="text-sm font-semibold text-fg">
          Sources
        </h2>
        {/* Polite live region announces progress to assistive tech. */}
        <p aria-live="polite" aria-atomic="true" className="text-xs text-fg-muted">
          {progressSummary(status, progress)}
        </p>
      </div>
      <ProgressBar value={progress.done} max={Math.max(progress.total, 1)} label="Overall search progress" tone={running ? "accent" : status === "failed" ? "danger" : "success"} />
      {sources.length > 0 && (
        <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
          {sources.map((s, i) => (
            <li key={s.source_id ?? `${s.name ?? s.domain ?? s.type}-${i}`} className="rounded-md border border-border bg-surface px-3 py-2">
              <div className="flex items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="truncate text-sm font-medium text-fg">{s.name ?? s.domain ?? humanize(s.type)}</p>
                  <p className="truncate text-xs text-fg-muted">{s.domain ?? humanize(s.type)}</p>
                </div>
                <Badge tone={SOURCE_TONE[s.status] ?? "neutral"}>{humanize(s.status)}</Badge>
              </div>
              <p className="mt-1 text-xs tabular-nums text-fg-muted">
                {s.candidates_found} candidates · {s.offers_found} offers
              </p>
              {s.error && <p className="mt-1 text-xs text-danger">{s.error}</p>}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
