import path from "node:path";
import type { NextConfig } from "next";

// Pin the workspace root to this app so the standalone output is flat
// (.next/standalone/server.js) regardless of lockfiles in parent dirs.
const appRoot = path.resolve(__dirname);

const nextConfig: NextConfig = {
  output: "standalone",
  outputFileTracingRoot: appRoot,
  poweredByHeader: false,
  // Allow `next dev` assets/HMR when browsing via 127.0.0.1 (Playwright, Docker port maps).
  allowedDevOrigins: ["127.0.0.1"],
  cacheComponents: true,
  partialPrefetching: true,
  images: {
    // Offer thumbnails come from arbitrary retailer domains; we render them with
    // plain <img> tags instead of the optimizer so the BFF never fetches them.
    unoptimized: true,
  },
  turbopack: {
    root: appRoot,
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
  async headers() {
    return [
      {
        source: "/:path*",
        headers: [
          { key: "X-Content-Type-Options", value: "nosniff" },
          { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
          { key: "X-Frame-Options", value: "DENY" },
        ],
      },
    ];
  },
};

export default nextConfig;
