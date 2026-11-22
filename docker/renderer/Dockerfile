# syntax=docker/dockerfile:1
#
# PriceLens renderer. Build from the repository root:
#   docker build -f docker/renderer/Dockerfile -t pricelens/renderer .
#
# The base image tag MUST match the `playwright` version pinned in
# renderer/package.json so the bundled Chromium revision lines up.
ARG PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.64.1-noble

# ---- build: compile TypeScript, then prune to production deps ----
FROM ${PLAYWRIGHT_IMAGE} AS build
ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
WORKDIR /app
COPY renderer/package.json renderer/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY renderer/tsconfig.json renderer/tsconfig.build.json ./
COPY renderer/src ./src
RUN npm run build \
 && npm prune --omit=dev --no-audit --no-fund

# ---- runtime: dist + production node_modules on the Playwright image ----
FROM ${PLAYWRIGHT_IMAGE} AS runtime
ENV NODE_ENV=production \
    PORT=3100 \
    PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1
WORKDIR /app
COPY --from=build --chown=pwuser:pwuser /app/package.json ./package.json
COPY --from=build --chown=pwuser:pwuser /app/node_modules ./node_modules
COPY --from=build --chown=pwuser:pwuser /app/dist ./dist
USER pwuser
EXPOSE 3100
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
  CMD ["node", "-e", "fetch('http://127.0.0.1:'+(process.env.PORT||3100)+'/health').then(r=>process.exit(r.ok?0:1),()=>process.exit(1))"]
# Run with an init process (`docker run --init` / compose `init: true`) so
# orphaned Chromium children are reaped; the app handles SIGTERM itself.
CMD ["node", "dist/server.js"]
