import type { Availability } from "@/lib/types";
import { Badge, type Tone } from "@/components/ui/Badge";
import { humanize } from "@/lib/format";

const MAP: Record<string, { tone: Tone; label: string }> = {
  in_stock: { tone: "success", label: "In stock" },
  limited: { tone: "warning", label: "Limited stock" },
  preorder: { tone: "info", label: "Pre-order" },
  backorder: { tone: "warning", label: "Backorder" },
  out_of_stock: { tone: "danger", label: "Out of stock" },
  discontinued: { tone: "danger", label: "Discontinued" },
  unknown: { tone: "neutral", label: "Unknown" },
};

export function availabilityLabel(a: Availability): string {
  return MAP[a]?.label ?? humanize(a);
}

export function AvailabilityBadge({ availability }: { availability: Availability }) {
  const entry = MAP[availability] ?? { tone: "neutral" as Tone, label: humanize(availability) };
  return (
    <Badge tone={entry.tone} dot>
      {entry.label}
    </Badge>
  );
}
