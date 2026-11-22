import type { ComponentProps, ReactNode } from "react";
import { cn } from "@/lib/format";

export function Table({ className, caption, children, ...rest }: ComponentProps<"table"> & { caption?: ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className={cn("w-full border-collapse text-left text-sm", className)} {...rest}>
        {caption && <caption className="sr-only">{caption}</caption>}
        {children}
      </table>
    </div>
  );
}

export function THead({ className, ...rest }: ComponentProps<"thead">) {
  return <thead className={cn("border-b border-border text-xs uppercase tracking-wide text-fg-muted", className)} {...rest} />;
}

export function TBody({ className, ...rest }: ComponentProps<"tbody">) {
  return <tbody className={cn("divide-y divide-border", className)} {...rest} />;
}

export function Th({ className, ...rest }: ComponentProps<"th">) {
  return <th scope="col" className={cn("px-3 py-2 font-medium", className)} {...rest} />;
}

export function Td({ className, ...rest }: ComponentProps<"td">) {
  return <td className={cn("px-3 py-2 align-top", className)} {...rest} />;
}

export type SortDirection = "ascending" | "descending" | "none";

interface SortableThProps {
  label: string;
  direction: SortDirection;
  onSort: () => void;
  className?: string;
}

/** Column header with a keyboard-accessible sort button and aria-sort. */
export function SortableTh({ label, direction, onSort, className }: SortableThProps) {
  const icon = direction === "ascending" ? "↑" : direction === "descending" ? "↓" : "↕";
  return (
    <th scope="col" aria-sort={direction} className={cn("px-3 py-2 font-medium", className)}>
      <button type="button" onClick={onSort} className="inline-flex items-center gap-1 uppercase tracking-wide hover:text-fg">
        {label}
        <span aria-hidden="true" className={direction === "none" ? "opacity-40" : undefined}>
          {icon}
        </span>
      </button>
    </th>
  );
}
