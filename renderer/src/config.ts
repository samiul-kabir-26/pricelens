import { parseHostPattern } from './net-policy.js';

export interface Config {
  readonly port: number;
  readonly host: string;
  readonly rendererToken: string;
  readonly browserInstances: number;
  readonly contextsPerInstance: number;
  readonly defaultTimeoutMs: number;
  readonly logLevel: string;
  readonly nodeEnv: string;
  /** RENDERER_BLOCK_PRIVATE: enforce the private/reserved address policy. */
  readonly blockPrivate: boolean;
  /** RENDERER_ALLOW_HOSTS: normalized host patterns that bypass the address policy. */
  readonly allowHosts: readonly string[];
}

export class ConfigError extends Error {
  override readonly name = 'ConfigError';
}

/** Upper bound accepted for per-request `timeout_ms` (and DEFAULT_TIMEOUT_MS). */
export const MAX_TIMEOUT_MS = 120_000;
export const MIN_TIMEOUT_MS = 500;

function readInt(
  env: NodeJS.ProcessEnv,
  name: string,
  fallback: number,
  min: number,
  max: number,
): number {
  const raw = env[name]?.trim();
  if (raw === undefined || raw === '') return fallback;
  if (!/^\d+$/.test(raw)) {
    throw new ConfigError(`${name} must be a positive integer, got "${raw}"`);
  }
  const value = Number(raw);
  if (value < min || value > max) {
    throw new ConfigError(`${name} must be between ${min} and ${max}, got ${value}`);
  }
  return value;
}

function readBool(env: NodeJS.ProcessEnv, name: string, fallback: boolean): boolean {
  const raw = env[name]?.trim().toLowerCase();
  if (raw === undefined || raw === '') return fallback;
  if (['true', '1', 'yes', 'on'].includes(raw)) return true;
  if (['false', '0', 'no', 'off'].includes(raw)) return false;
  throw new ConfigError(`${name} must be true or false, got "${env[name]}"`);
}

function readHostList(env: NodeJS.ProcessEnv, name: string): string[] {
  const raw = env[name]?.trim() ?? '';
  if (raw === '') return [];
  return raw
    .split(',')
    .map((s) => s.trim())
    .filter((s) => s !== '')
    .map((entry) => {
      try {
        return parseHostPattern(entry);
      } catch (err) {
        throw new ConfigError(`${name}: ${err instanceof Error ? err.message : String(err)}`);
      }
    });
}

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const nodeEnv = env.NODE_ENV?.trim() || 'production';
  const rendererToken = env.RENDERER_TOKEN?.trim() ?? '';

  if (rendererToken === '' && nodeEnv !== 'test') {
    throw new ConfigError(
      'RENDERER_TOKEN is empty; refusing to start an unauthenticated renderer (set NODE_ENV=test to bypass in tests)',
    );
  }

  const blockPrivate = readBool(env, 'RENDERER_BLOCK_PRIVATE', true);
  if (!blockPrivate && nodeEnv === 'production') {
    throw new ConfigError(
      'RENDERER_BLOCK_PRIVATE=false is not allowed when NODE_ENV=production (use RENDERER_ALLOW_HOSTS for specific hosts)',
    );
  }

  return {
    port: readInt(env, 'PORT', 3100, 0, 65_535),
    host: env.HOST?.trim() || '0.0.0.0',
    rendererToken,
    browserInstances: readInt(env, 'BROWSER_INSTANCES', 4, 1, 64),
    contextsPerInstance: readInt(env, 'CONTEXTS_PER_INSTANCE', 4, 1, 64),
    defaultTimeoutMs: readInt(env, 'DEFAULT_TIMEOUT_MS', 20_000, MIN_TIMEOUT_MS, MAX_TIMEOUT_MS),
    logLevel: env.LOG_LEVEL?.trim() || 'info',
    nodeEnv,
    blockPrivate,
    allowHosts: readHostList(env, 'RENDERER_ALLOW_HOSTS'),
  };
}
