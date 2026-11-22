import type { IncomingHttpHeaders } from 'node:http';
import { ROOT_CONTEXT, SpanKind, SpanStatusCode, defaultTextMapGetter, type Span, type Tracer } from '@opentelemetry/api';
import { W3CTraceContextPropagator } from '@opentelemetry/core';
import { OTLPTraceExporter } from '@opentelemetry/exporter-trace-otlp-http';
import { resourceFromAttributes } from '@opentelemetry/resources';
import {
  BatchSpanProcessor,
  SimpleSpanProcessor,
  TracerProvider,
  type SpanExporter,
} from '@opentelemetry/sdk-trace';

export type SpanAttributes = Record<string, string | number | boolean>;

/** One `render` span per POST /render request. */
export interface RenderSpan {
  setAttributes(attrs: SpanAttributes): void;
  end(httpStatus: number): void;
}

export interface Tracing {
  readonly enabled: boolean;
  /** Start a span, as a child of the incoming W3C `traceparent` when present. */
  startRender(headers: IncomingHttpHeaders): RenderSpan;
  forceFlush(): Promise<void>;
  shutdown(): Promise<void>;
}

const noopSpan: RenderSpan = { setAttributes() {}, end() {} };

export const noopTracing: Tracing = {
  enabled: false,
  startRender: () => noopSpan,
  forceFlush: async () => {},
  shutdown: async () => {},
};

export interface TracingOptions {
  /** Test hook: export synchronously to this exporter instead of OTLP/HTTP. */
  exporter?: SpanExporter;
}

/**
 * OpenTelemetry tracing, enabled only when OTEL_EXPORTER_OTLP_ENDPOINT (or
 * OTEL_EXPORTER_OTLP_TRACES_ENDPOINT) is set. The OTLP/HTTP exporter reads the
 * standard OTEL_EXPORTER_OTLP_* variables itself (endpoint, headers, timeout).
 * Nothing is registered globally and no instrumentation is installed.
 */
export function createTracing(env: NodeJS.ProcessEnv = process.env, opts: TracingOptions = {}): Tracing {
  const endpoint = env.OTEL_EXPORTER_OTLP_TRACES_ENDPOINT?.trim() || env.OTEL_EXPORTER_OTLP_ENDPOINT?.trim();
  if (!endpoint && !opts.exporter) return noopTracing;

  const provider = new TracerProvider({
    resource: resourceFromAttributes({ 'service.name': env.OTEL_SERVICE_NAME?.trim() || 'pricelens-renderer' }),
    spanProcessors: [
      opts.exporter
        ? new SimpleSpanProcessor({ exporter: opts.exporter })
        : new BatchSpanProcessor({ exporter: new OTLPTraceExporter() }),
    ],
  });
  const tracer: Tracer = provider.getTracer('pricelens-renderer');
  const propagator = new W3CTraceContextPropagator();

  return {
    enabled: true,
    startRender(headers) {
      const parent = propagator.extract(ROOT_CONTEXT, headers, defaultTextMapGetter);
      const span: Span = tracer.startSpan('render', { kind: SpanKind.SERVER }, parent);
      return {
        setAttributes: (attrs) => span.setAttributes(attrs),
        end(httpStatus) {
          span.setAttribute('http.status', httpStatus);
          if (httpStatus >= 500) span.setStatus({ code: SpanStatusCode.ERROR });
          span.end();
        },
      };
    },
    async forceFlush() {
      await provider.forceFlush();
    },
    async shutdown() {
      await provider.shutdown();
    },
  };
}
