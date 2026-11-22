import { createHash, timingSafeEqual } from 'node:crypto';
import { realpathSync } from 'node:fs';
import { pathToFileURL } from 'node:url';
import Fastify, {
  type FastifyError,
  type FastifyInstance,
  type FastifyReply,
  type FastifyRequest,
  type FastifyServerOptions,
} from 'fastify';
import { Counter, Gauge, Histogram, Registry, collectDefaultMetrics } from 'prom-client';
import { BrowserPool, type RenderPool } from './browser-pool.js';
import { loadConfig, type Config } from './config.js';
import { BlockedAddressError, NetPolicy } from './net-policy.js';
import type { BlockListener } from './redirect-guard.js';
import {
  isTimeoutError,
  render as defaultRender,
  renderRequestSchema,
  summarizeError,
  validateTargetUrl,
  type RenderFn,
  type RenderRequest,
} from './render.js';
import { createTracing, noopTracing, type RenderSpan, type Tracing } from './tracing.js';

export interface AppDeps {
  config: Config;
  pool: RenderPool;
  render?: RenderFn;
  /** Fastify logger options; `false` disables logging (tests). */
  logger?: FastifyServerOptions['logger'];
  /** Address policy; defaults to one built from config (blockPrivate / allowHosts). */
  netPolicy?: NetPolicy;
  /** Defaults to no-op tracing. */
  tracing?: Tracing;
}

const TRACEPARENT_RE = /^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/;

/** Parse a W3C traceparent header; returns log bindings or null if absent/invalid. */
export function parseTraceparent(
  header: string | string[] | undefined,
): { traceparent: string; trace_id: string; span_id: string } | null {
  const value = (Array.isArray(header) ? header[0] : header)?.trim().toLowerCase();
  if (!value) return null;
  const m = TRACEPARENT_RE.exec(value);
  if (!m || m[1] === 'ff' || /^0+$/.test(m[2]!) || /^0+$/.test(m[3]!)) return null;
  return { traceparent: value, trace_id: m[2]!, span_id: m[3]! };
}

const sha256 = (s: string): Buffer => createHash('sha256').update(s, 'utf8').digest();

function makeAuthHook(token: string) {
  const expected = sha256(token);
  return async (req: FastifyRequest, reply: FastifyReply) => {
    const match = /^Bearer[ \t]+(\S+)[ \t]*$/i.exec(req.headers.authorization ?? '');
    // Compare fixed-length digests so timing is independent of token length/content.
    const ok = token !== '' && match !== null && timingSafeEqual(sha256(match[1]!), expected);
    if (!ok) {
      return reply.code(401).header('www-authenticate', 'Bearer').send({ error: 'unauthorized' });
    }
  };
}

export function buildApp(deps: AppDeps): FastifyInstance {
  const { config, pool } = deps;
  const renderFn = deps.render ?? defaultRender;
  const netPolicy = deps.netPolicy ?? new NetPolicy({ blockPrivate: config.blockPrivate, allowHosts: config.allowHosts });
  const tracing = deps.tracing ?? noopTracing;
  const spans = new WeakMap<FastifyRequest, RenderSpan>();

  const app = Fastify({
    logger: deps.logger ?? {
      level: config.logLevel,
      base: { service: 'pricelens-renderer' },
      redact: ['req.headers.authorization'],
    },
    bodyLimit: 64 * 1024,
    requestIdHeader: 'x-request-id',
    // Bind trace context into every request-scoped log line.
    childLoggerFactory(logger, bindings, opts, rawReq) {
      const trace = parseTraceparent(rawReq.headers['traceparent']);
      return logger.child(trace ? { ...bindings, ...trace } : bindings, opts);
    },
    ajv: { customOptions: { coerceTypes: false, removeAdditional: false, allErrors: false } },
  });

  // ---- metrics ----
  const registry = new Registry();
  collectDefaultMetrics({ register: registry, prefix: 'pricelens_renderer_' });
  new Gauge({
    name: 'pricelens_render_pool_utilization',
    help: 'Fraction of browser context slots currently in use (0..1)',
    registers: [registry],
    collect() {
      this.set(pool.utilization());
    },
  });
  new Gauge({
    name: 'pricelens_render_pool_ready_instances',
    help: 'Chromium instances currently connected',
    registers: [registry],
    collect() {
      this.set(pool.readyInstances);
    },
  });
  const requestsTotal = new Counter({
    name: 'pricelens_render_requests_total',
    help: 'POST /render responses by HTTP status',
    labelNames: ['status'] as const,
    registers: [registry],
  });
  const renderDuration = new Histogram({
    name: 'pricelens_render_duration_seconds',
    help: 'Wall-clock duration of render attempts by outcome',
    labelNames: ['outcome'] as const,
    buckets: [0.25, 0.5, 1, 2, 3, 5, 8, 13, 20, 30, 60],
    registers: [registry],
  });
  const blockedTotal = new Counter({
    name: 'pricelens_render_blocked_requests_total',
    help: 'Browser requests aborted by the private/reserved address policy',
    labelNames: ['kind'] as const,
    registers: [registry],
  });
  blockedTotal.inc({ kind: 'document' }, 0);
  blockedTotal.inc({ kind: 'subresource' }, 0);

  // ---- error handling ----
  app.setErrorHandler<FastifyError>((err, req, reply) => {
    if (err.validation) {
      return reply.code(400).send({ error: 'invalid_request', message: err.message });
    }
    const status = err.statusCode ?? 500;
    if (status >= 400 && status < 500) {
      return reply.code(status).send({ error: 'invalid_request', message: err.message });
    }
    req.log.error({ err }, 'unhandled error');
    return reply.code(500).send({ error: 'internal_error' });
  });
  app.setNotFoundHandler((_req, reply) => reply.code(404).send({ error: 'not_found' }));

  // ---- routes ----
  app.get('/health', async () => ({
    status: 'ok',
    pool: { ready_instances: pool.readyInstances, active: pool.active, capacity: pool.capacity },
  }));

  app.get('/metrics', async (_req, reply) => {
    reply.header('content-type', registry.contentType);
    return registry.metrics();
  });

  app.post<{ Body: RenderRequest }>(
    '/render',
    {
      schema: { body: renderRequestSchema },
      onRequest: [
        async (req) => {
          spans.set(req, tracing.startRender(req.headers));
        },
        makeAuthHook(config.rendererToken),
      ],
      onResponse: async (req, reply) => {
        requestsTotal.inc({ status: String(reply.statusCode) });
        spans.get(req)?.end(reply.statusCode);
      },
    },
    async (req, reply) => {
      const body = req.body;
      const urlError = validateTargetUrl(body.url);
      if (urlError) {
        return reply.code(400).send({ error: 'invalid_request', message: urlError });
      }

      const host = new URL(body.url).host;
      const span = spans.get(req);
      span?.setAttributes({ 'url.host': host });
      let blockedCount = 0;
      const onBlocked: BlockListener = (ev) => {
        blockedTotal.inc({ kind: ev.kind });
        if (!ev.attributed) return; // counted, but belongs to no particular request
        blockedCount++;
        span?.setAttributes({ 'render.blocked_requests': blockedCount });
        req.log.info({ blocked_host: ev.host, kind: ev.kind, reason: ev.reason }, 'request blocked by address policy');
      };
      const blockedResponse = (err: BlockedAddressError) => {
        req.log.warn({ host, blocked_host: err.host, reason: err.reason }, 'render blocked by address policy');
        return reply.code(403).send({ error: 'blocked_address', message: err.message });
      };

      // Cheap pre-check of the initial URL so a blocked target never takes a slot.
      // Redirect hops and subresources are enforced inside the browser.
      const pre = await netPolicy.checkUrl(body.url);
      if (pre.action === 'block') {
        onBlocked({ kind: 'document', url: body.url, host: pre.host, reason: pre.reason, attributed: true });
        return blockedResponse(new BlockedAddressError(pre.host, pre.reason));
      }

      const lease = pool.acquire();
      if (!lease) {
        req.log.warn({ active: pool.active, capacity: pool.capacity }, 'render pool saturated');
        return reply.code(503).header('retry-after', '2').send({ error: 'saturated' });
      }

      const stopTimer = renderDuration.startTimer();
      let outcome = 'ok';
      try {
        const result = await renderFn(lease.browser, body, {
          defaultTimeoutMs: config.defaultTimeoutMs,
          netPolicy,
          onBlocked,
        });
        span?.setAttributes({
          'render.timing_ms': result.timing_ms,
          ...(result.status !== null ? { 'render.target_status': result.status } : {}),
        });
        if (result.truncated) reply.header('x-html-truncated', 'true');
        req.log.info(
          { host, status: result.status, timing_ms: result.timing_ms, instance: lease.instanceId },
          'render ok',
        );
        return reply.code(200).send({
          final_url: result.final_url,
          status: result.status,
          html: result.html,
          timing_ms: result.timing_ms,
          screenshot_base64: result.screenshot_base64,
        });
      } catch (err) {
        if (err instanceof BlockedAddressError) {
          outcome = 'blocked';
          return blockedResponse(err);
        }
        if (isTimeoutError(err)) {
          outcome = 'timeout';
          req.log.warn({ host, instance: lease.instanceId }, 'render timeout');
          return reply.code(504).send({ error: 'timeout' });
        }
        outcome = 'navigation_failed';
        const message = summarizeError(err);
        req.log.warn({ host, instance: lease.instanceId, message }, 'render navigation failed');
        return reply.code(502).send({ error: 'navigation_failed', message });
      } finally {
        lease.release();
        stopTimer({ outcome });
      }
    },
  );

  app.addHook('onClose', async () => {
    await pool.close();
    await tracing.shutdown().catch((err: unknown) => app.log.warn({ err }, 'tracing shutdown failed'));
  });

  return app;
}

async function main(): Promise<void> {
  let config: Config;
  try {
    config = loadConfig();
  } catch (err) {
    console.error(`[renderer] ${err instanceof Error ? err.message : String(err)}`);
    process.exit(1);
  }

  const pool = new BrowserPool({
    instances: config.browserInstances,
    contextsPerInstance: config.contextsPerInstance,
  });
  const tracing = createTracing(process.env);
  const app = buildApp({ config, pool, tracing });
  pool.logger = app.log.child({ component: 'browser-pool' });
  if (!config.blockPrivate) app.log.warn('RENDERER_BLOCK_PRIVATE=false: private/internal addresses are NOT blocked');
  if (tracing.enabled) app.log.info('OpenTelemetry tracing enabled (OTLP/HTTP)');

  try {
    await pool.start();
    await app.listen({ port: config.port, host: config.host });
  } catch (err) {
    app.log.fatal({ err }, 'failed to start renderer');
    await app.close().catch(() => {});
    process.exit(1);
  }

  let shuttingDown = false;
  const shutdown = (signal: NodeJS.Signals) => {
    if (shuttingDown) return;
    shuttingDown = true;
    app.log.info({ signal }, 'shutting down');
    setTimeout(() => {
      app.log.error('graceful shutdown timed out; forcing exit');
      process.exit(1);
    }, 30_000).unref();
    app.close().then(
      () => process.exit(0),
      (err: unknown) => {
        app.log.error({ err }, 'error during shutdown');
        process.exit(1);
      },
    );
  };
  process.once('SIGTERM', shutdown);
  process.once('SIGINT', shutdown);
}

function isEntrypoint(): boolean {
  const entry = process.argv[1];
  if (!entry) return false;
  try {
    return import.meta.url === pathToFileURL(realpathSync(entry)).href;
  } catch {
    return false;
  }
}

if (isEntrypoint()) {
  void main();
}
