import { Badge, type Tone } from "@/components/ui/Badge";
import type { HealthStatus } from "@/lib/types";

const TONE: Record<HealthStatus, Tone> = { healthy: "success", degraded: "warning", failing: "danger" };
const LABEL: Record<HealthStatus, string> = { healthy: "Healthy", degraded: "Degraded", failing: "Failing" };

export function SourceHealthBadge({ status }: { status: HealthStatus }) {
  return (
    <Badge tone={TONE[status] ?? "neutral"} dot aria-label={`Health: ${LABEL[status] ?? status}`}>
      {LABEL[status] ?? status}
    </Badge>
  );
}
