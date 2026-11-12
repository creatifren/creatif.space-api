import type { FetchLike } from './auth/introspection.js';
import { VERSION } from './version.js';

/** A non-2xx answer from the V1 API. */
export class V1Error extends Error {
  constructor(
    readonly status: number,
    readonly body: Record<string, unknown> | null,
    /** Seconds from a Retry-After header (seconds or an HTTP date), when the API sent one. */
    readonly retryAfter?: number,
  ) {
    super(typeof body?.message === 'string' ? body.message : `V1 API answered ${status}`);
  }
}

/** The V1 API could not be reached at all. */
export class V1Unavailable extends Error {}

type Query = Record<string, string | number | boolean | undefined | null>;

/**
 * The V1 API, called as the MCP caller: their bearer token is forwarded,
 * so every ownership, plan and Credit rule stays in Laravel.
 */
export class V1Client {
  constructor(
    private readonly baseUrl: string,
    private readonly token: string,
    private readonly fetchImpl: FetchLike = fetch,
    private readonly extraHeaders: Record<string, string> = {},
  ) {}

  /** The same caller, labelled for the API's audit log (X-MCP-Tool / X-MCP-Client). Never content. */
  forTool(tool: string, clientName: string): V1Client {
    return new V1Client(this.baseUrl, this.token, this.fetchImpl, {
      'X-MCP-Tool': tool,
      'X-MCP-Client': clientName.replace(/[^\x20-\x7E]/g, '').slice(0, 120),
    });
  }

  patch<T = unknown>(path: string, body: unknown): Promise<T> {
    return this.request<T>('PATCH', path, body);
  }

  get<T = unknown>(path: string, query?: Query): Promise<T> {
    return this.request<T>('GET', path, undefined, query);
  }

  delete<T = unknown>(path: string): Promise<T> {
    return this.request<T>('DELETE', path);
  }

  post<T = unknown>(path: string, body: unknown): Promise<T> {
    return this.request<T>('POST', path, body);
  }

  /** A GET whose success body is not JSON (e.g. a CSV export): the raw text. Errors are still JSON. */
  getText(path: string, query?: Query): Promise<string> {
    return this.request<string>('GET', path, undefined, query, true);
  }

  private async request<T>(method: string, path: string, body?: unknown, query?: Query, raw = false): Promise<T> {
    const url = new URL(this.baseUrl + path);
    for (const [k, v] of Object.entries(query ?? {})) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    }

    let res: Response;
    try {
      res = await this.fetchImpl(url.toString(), {
        method,
        headers: {
          Accept: 'application/json',
          Authorization: `Bearer ${this.token}`,
          ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
          'User-Agent': `creatif-mcp/${VERSION}`,
          ...this.extraHeaders,
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        signal: AbortSignal.timeout(20_000),
      });
    } catch (e) {
      throw new V1Unavailable((e as Error).message);
    }

    const text = await res.text();
    if (raw && res.ok) return text as T;
    let json: unknown = null;
    if (text !== '') {
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
    }

    if (!res.ok) {
      throw new V1Error(res.status, json && typeof json === 'object' ? (json as Record<string, unknown>) : null, retryAfter(res.headers.get('retry-after')));
    }
    return json as T;
  }
}

/** Retry-After as whole seconds: "17" → 17, an HTTP date → seconds from now; anything else → undefined. */
export function retryAfter(header: string | null, now: number = Date.now()): number | undefined {
  if (!header) return undefined;
  const trimmed = header.trim();
  if (/^\d+$/.test(trimmed)) return Number(trimmed) > 0 ? Number(trimmed) : undefined;
  const at = Date.parse(trimmed);
  if (Number.isNaN(at)) return undefined;
  const secs = Math.ceil((at - now) / 1000);
  return secs > 0 ? secs : undefined;
}
