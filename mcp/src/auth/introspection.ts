import { createHash } from 'node:crypto';

/** The contract with the Laravel side: POST /api/v1/oauth/introspect. */
export interface Introspection {
  active: boolean;
  user_ulid?: string;
  scopes?: string[];
  client_id?: string;
  client_name?: string;
  /** 'chatgpt' when the OAuth client is ChatGPT (by its registered name or redirect host); see clientSurface(). */
  client_surface?: 'chatgpt' | 'other';
  token_type?: 'oauth' | 'api_key';
  /** Unix seconds; null/absent for a key that does not expire. */
  exp?: number | null;
}

export interface Introspector {
  introspect(token: string): Promise<Introspection>;
}

/** The introspection endpoint itself failed (network, 5xx) — not a bad token. */
export class IntrospectionUnavailable extends Error {}

export type FetchLike = (input: string, init?: RequestInit) => Promise<Response>;

export class HttpIntrospector implements Introspector {
  constructor(
    private readonly v1ApiUrl: string,
    private readonly secret: string,
    private readonly fetchImpl: FetchLike = fetch,
  ) {}

  async introspect(token: string): Promise<Introspection> {
    let res: Response;
    try {
      res = await this.fetchImpl(`${this.v1ApiUrl}/oauth/introspect`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-MCP-Secret': this.secret,
        },
        body: JSON.stringify({ token }),
        signal: AbortSignal.timeout(5_000),
      });
    } catch (e) {
      throw new IntrospectionUnavailable(`introspection request failed: ${(e as Error).message}`);
    }

    if (res.status >= 500 || res.status === 401 || res.status === 403) {
      // 401/403 here means OUR secret is wrong — a server problem, not the caller's.
      throw new IntrospectionUnavailable(`introspection answered ${res.status}`);
    }
    if (!res.ok) {
      return { active: false };
    }
    const body = (await res.json()) as Introspection;
    return body && body.active === true ? body : { active: false };
  }
}

/**
 * Tests only (MCP_AUTH_MODE=stub). Accepts tokens shaped
 * `stub:<user_ulid>:<scope,scope>[:<client name>]`; anything else is inactive.
 */
export class StubIntrospector implements Introspector {
  async introspect(token: string): Promise<Introspection> {
    // stub:<user>:<scope,scope>[:<client>] — a scope may itself hold a
    // colon (approvals:decide), so the client is the last part only when
    // there are more than three.
    const parts = token.split(':');
    const [prefix, user] = parts;
    const client = parts.length > 3 ? parts[parts.length - 1] : undefined;
    const scopes = parts.slice(2, parts.length > 3 ? -1 : undefined).join(':');
    if (prefix !== 'stub' || !user) {
      return { active: false };
    }
    return {
      active: true,
      user_ulid: user,
      scopes: scopes ? scopes.split(',').filter(Boolean) : [],
      client_id: 'stub-client',
      client_name: client ?? 'Stub client',
      token_type: 'oauth',
      exp: Math.floor(Date.now() / 1000) + 3600,
    };
  }
}

export const tokenKey = (token: string): string =>
  createHash('sha256').update(token).digest('hex');

/** Reuses an answer for `ttlMs`, never past the token's own expiry. */
export class CachedIntrospector implements Introspector {
  private readonly cache = new Map<string, { value: Introspection; until: number }>();

  constructor(
    private readonly inner: Introspector,
    private readonly ttlMs = 60_000,
    private readonly maxEntries = 10_000,
    private readonly now: () => number = Date.now,
  ) {}

  async introspect(token: string): Promise<Introspection> {
    const key = tokenKey(token);
    const t = this.now();
    const hit = this.cache.get(key);
    if (hit && hit.until > t) {
      return hit.value;
    }
    this.cache.delete(key);

    const value = await this.inner.introspect(token);
    let until = t + this.ttlMs;
    if (value.active && typeof value.exp === 'number') {
      until = Math.min(until, value.exp * 1000);
    }
    if (this.cache.size >= this.maxEntries) {
      const oldest = this.cache.keys().next().value;
      if (oldest !== undefined) this.cache.delete(oldest);
    }
    this.cache.set(key, { value, until });
    return value;
  }
}
