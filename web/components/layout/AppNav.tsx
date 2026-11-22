"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useState } from "react";
import { cn } from "@/lib/format";

export const NAV_ITEMS = [
  { href: "/", label: "New search", match: (p: string) => p === "/" },
  { href: "/searches", label: "Searches", match: (p: string) => p.startsWith("/searches") },
  { href: "/products", label: "Products", match: (p: string) => p.startsWith("/products") },
  { href: "/sources", label: "Sources", match: (p: string) => p.startsWith("/sources") },
  { href: "/settings/webhooks", label: "Webhooks", match: (p: string) => p.startsWith("/settings/webhooks") },
  { href: "/settings/usage", label: "Usage", match: (p: string) => p.startsWith("/settings/usage") },
];

function NavLinks({ onNavigate, vertical }: { onNavigate?: () => void; vertical?: boolean }) {
  const pathname = usePathname();
  return (
    <ul className={cn("flex gap-1", vertical ? "flex-col" : "items-center")}>
      {NAV_ITEMS.map((item) => {
        const active = item.match(pathname);
        return (
          <li key={item.href}>
            <Link
              href={item.href}
              onClick={onNavigate}
              aria-current={active ? "page" : undefined}
              className={cn(
                "block rounded-md px-3 py-1.5 text-sm",
                active ? "bg-surface-muted font-medium text-fg" : "text-fg-muted hover:bg-surface-muted hover:text-fg",
              )}
            >
              {item.label}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}

/** Static fallback (no active state) rendered while the pathname resolves. */
export function NavLinksFallback() {
  return (
    <ul className="flex items-center gap-1">
      {NAV_ITEMS.map((item) => (
        <li key={item.href}>
          <Link href={item.href} className="block rounded-md px-3 py-1.5 text-sm text-fg-muted hover:bg-surface-muted hover:text-fg">
            {item.label}
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function DesktopNav() {
  return <NavLinks />;
}

export function MobileNav() {
  const [open, setOpen] = useState(false);
  return (
    <div className="lg:hidden">
      <button
        type="button"
        aria-expanded={open}
        aria-controls="mobile-nav"
        onClick={() => setOpen((v) => !v)}
        className="inline-flex h-8 items-center rounded-md border border-border px-2.5 text-sm text-fg hover:bg-surface-muted"
      >
        Menu
      </button>
      {open && (
        <nav id="mobile-nav" aria-label="Main" className="absolute inset-x-0 top-full z-40 border-b border-border bg-surface p-3 shadow-sm">
          <NavLinks vertical onNavigate={() => setOpen(false)} />
        </nav>
      )}
    </div>
  );
}
