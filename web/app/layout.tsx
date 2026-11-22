import type { Metadata, Viewport } from "next";
import Link from "next/link";
import { Suspense } from "react";
import "./globals.css";
import { Providers } from "./providers";
import { DesktopNav, MobileNav, NavLinksFallback } from "@/components/layout/AppNav";
import { ThemeToggle, themeInitScript } from "@/components/layout/ThemeToggle";

export const metadata: Metadata = {
  title: { default: "PriceLens", template: "%s · PriceLens" },
  description: "Multi-tenant product price intelligence — demo UI",
  robots: { index: false, follow: false },
};

export const viewport: Viewport = {
  themeColor: [
    { media: "(prefers-color-scheme: light)", color: "#f8f9fb" },
    { media: "(prefers-color-scheme: dark)", color: "#0e1116" },
  ],
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    <html lang="en" suppressHydrationWarning className="h-full antialiased">
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeInitScript }} />
      </head>
      <body className="flex min-h-full flex-col">
        <a
          href="#main"
          className="sr-only focus:not-sr-only focus:fixed focus:left-2 focus:top-2 focus:z-50 focus:rounded-md focus:bg-surface focus:px-3 focus:py-2 focus:text-sm focus:shadow"
        >
          Skip to content
        </a>
        <Providers>
          <header className="relative border-b border-border bg-surface">
            <div className="mx-auto flex h-14 max-w-7xl items-center gap-4 px-4 sm:px-6">
              <Link href="/" className="flex items-center gap-2 font-semibold tracking-tight text-fg">
                <span aria-hidden="true" className="inline-block h-5 w-5 rounded bg-accent" />
                PriceLens
              </Link>
              <nav aria-label="Main" className="hidden flex-1 lg:block">
                <Suspense fallback={<NavLinksFallback />}>
                  <DesktopNav />
                </Suspense>
              </nav>
              <div className="ml-auto flex items-center gap-2">
                <ThemeToggle />
                <Suspense fallback={null}>
                  <MobileNav />
                </Suspense>
              </div>
            </div>
          </header>
          <main id="main" className="mx-auto w-full max-w-7xl flex-1 px-4 py-6 sm:px-6 sm:py-8">
            {children}
          </main>
        </Providers>
      </body>
    </html>
  );
}
