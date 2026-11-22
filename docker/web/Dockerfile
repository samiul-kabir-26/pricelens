# syntax=docker/dockerfile:1.7
#
# PriceLens demo UI (Next.js standalone).
# Build context is the repository root:
#   docker build -f docker/web/Dockerfile \
#     --build-arg NEXT_PUBLIC_REVERB_HOST=ws.pricelens.example \
#     --build-arg NEXT_PUBLIC_REVERB_KEY=... -t pricelens-web .
# Runtime (server-only) env: PRICELENS_API_URL, PRICELENS_API_KEY.

ARG NODE_IMAGE=node:22-alpine

# ---- deps: install exactly what the lockfile pins ---------------------------
FROM ${NODE_IMAGE} AS deps
WORKDIR /app
RUN apk add --no-cache libc6-compat
COPY web/package.json web/package-lock.json ./
RUN --mount=type=cache,target=/root/.npm \
    npm ci --no-audit --no-fund

# ---- builder: compile the standalone server ---------------------------------
FROM ${NODE_IMAGE} AS builder
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
COPY --from=deps /app/node_modules ./node_modules
COPY web/ ./

# NEXT_PUBLIC_* values are inlined into the client bundle at build time.
ARG NEXT_PUBLIC_REVERB_HOST=""
ARG NEXT_PUBLIC_REVERB_KEY=""
ARG NEXT_PUBLIC_REVERB_PORT="443"
ARG NEXT_PUBLIC_REVERB_SCHEME="https"
ENV NEXT_PUBLIC_REVERB_HOST=${NEXT_PUBLIC_REVERB_HOST} \
    NEXT_PUBLIC_REVERB_KEY=${NEXT_PUBLIC_REVERB_KEY} \
    NEXT_PUBLIC_REVERB_PORT=${NEXT_PUBLIC_REVERB_PORT} \
    NEXT_PUBLIC_REVERB_SCHEME=${NEXT_PUBLIC_REVERB_SCHEME}

RUN npm run build

# ---- runner: minimal non-root image -----------------------------------------
FROM ${NODE_IMAGE} AS runner
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

RUN addgroup -S -g 1001 nodejs && adduser -S -u 1001 -G nodejs nextjs

COPY --from=builder --chown=nextjs:nodejs /app/public ./public
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs
EXPOSE 3000

HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
  CMD wget -qO- http://127.0.0.1:3000/api/health >/dev/null || exit 1

CMD ["node", "server.js"]
