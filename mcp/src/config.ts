export type AuthMode = 'real' | 'stub';

export interface Config {
  port: number;
  host: string;
  /** Base of the V1 API, e.g. https://api.creatif.space/api/v1 (no trailing slash). */
  v1ApiUrl: string;
  /** The exact connector URL — the protected-resource identifier (token audience). */
  resourceUrl: string;
  /** The OAuth authorization server (Laravel / Passport). */
  authorizationServer: string;
  introspectSecret: string;
  authMode: AuthMode;
  posthogKey: string | null;
  posthogHost: string;
  /** Glama connector-ownership claim; public, published permanently. */
  glamaClaim: string;
  /** Introspection results are reused this long. */
  introspectionTtlMs: number;
  /** Calls per token per minute. */
  rateLimitPerMinute: number;
  /**
   * MCP_PUBLIC_DISCOVERY=true: initialize and tools/list answer without a
   * token (registry crawlers, client previews); tools/call and everything
   * else still need OAuth. Off unless set to exactly "true".
   */
  publicDiscovery: boolean;
  /** OpenAI Apps domain-verification token (OPENAI_APPS_CHALLENGE, else DEFAULT_OPENAI_APPS_CHALLENGE). */
  openaiAppsChallenge: string | null;
}

/** Not a secret: Glama reads it from a public URL to verify we own the connector. */
export const DEFAULT_GLAMA_CLAIM = 'glama_claim_U8l5ipmr_QunqGhWyIg0VXQeWfhkoEky';

/**
 * Not a secret: OpenAI reads it from https://mcp.creatif.space/.well-known/openai-apps-challenge to verify we own the
 * domain (ChatGPT Apps directory, 14 Nov 2026). OPENAI_APPS_CHALLENGE still overrides it.
 */
export const DEFAULT_OPENAI_APPS_CHALLENGE = 'uYNaMuKD7ghXNsfqvWYJtgy4EnJSE7mSD51RdcYzwDI';

/** /free calls per IP per minute (no env var: a code default, like rateLimitPerMinute). */
export const DEFAULT_FREE_RATE_LIMIT = 30;

const trimSlash = (s: string): string => s.replace(/\/+$/, '');

export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  const authMode = (env.MCP_AUTH_MODE ?? 'real') as AuthMode;
  if (authMode !== 'real' && authMode !== 'stub') {
    throw new Error(`MCP_AUTH_MODE must be "real" or "stub", got "${authMode}"`);
  }
  if (authMode === 'stub' && env.NODE_ENV === 'production') {
    throw new Error('MCP_AUTH_MODE=stub is for tests only and is refused when NODE_ENV=production');
  }

  const introspectSecret = env.MCP_INTROSPECT_SECRET ?? '';
  if (authMode === 'real' && introspectSecret === '') {
    throw new Error('MCP_INTROSPECT_SECRET is required when MCP_AUTH_MODE=real');
  }

  return {
    port: Number(env.PORT ?? 3010),
    host: env.HOST ?? '127.0.0.1',
    v1ApiUrl: trimSlash(env.V1_API_URL ?? 'https://api.creatif.space/api/v1'),
    resourceUrl: trimSlash(env.MCP_RESOURCE_URL ?? 'https://mcp.creatif.space/mcp'),
    authorizationServer: trimSlash(env.MCP_AUTHORIZATION_SERVER ?? 'https://api.creatif.space'),
    introspectSecret,
    authMode,
    posthogKey: env.POSTHOG_KEY ? env.POSTHOG_KEY : null,
    posthogHost: trimSlash(env.POSTHOG_HOST ?? 'https://us.i.posthog.com'),
    glamaClaim: env.GLAMA_CLAIM || DEFAULT_GLAMA_CLAIM,
    introspectionTtlMs: 60_000,
    rateLimitPerMinute: 60,
    publicDiscovery: env.MCP_PUBLIC_DISCOVERY === 'true',
    openaiAppsChallenge: env.OPENAI_APPS_CHALLENGE?.trim() || DEFAULT_OPENAI_APPS_CHALLENGE,
  };
}
