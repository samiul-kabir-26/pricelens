import type { ComponentProps } from "react";

/** Outbound link to a retailer: new tab, no referrer/opener, nofollow. */
export function ExternalLink({ children, ...rest }: Omit<ComponentProps<"a">, "target" | "rel">) {
  return (
    <a target="_blank" rel="noopener noreferrer nofollow" {...rest}>
      {children}
      <span className="sr-only"> (opens in a new tab)</span>
    </a>
  );
}
