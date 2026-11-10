import type { FetchLike } from './auth/introspection.js';

/** A non-2xx answer from the V1 API. */
export class V1Error extends Error {
  constructor(
    readonly status: number,
    readonly body: Record<string, unknown> | null,
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
  ) {}

  get<T = unknown>(path: string, query?: Query): Promise<T> {
    return this.request<T>('GET', path, undefined, query);
  }

  post<T = unknown>(path: string, body: unknown): Promise<T> {
    return this.request<T>('POST', path, body);
  }

  private async request<T>(method: string, path: string, body?: unknown, query?: Query): Promise<T> {
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
          'User-Agent': 'creatif-mcp/0.1',
        },
        ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
        signal: AbortSignal.timeout(20_000),
      });
    } catch (e) {
      throw new V1Unavailable((e as Error).message);
    }

    const text = await res.text();
    let json: unknown = null;
    if (text !== '') {
      try {
        json = JSON.parse(text);
      } catch {
        json = null;
      }
    }

    if (!res.ok) {
      throw new V1Error(res.status, json && typeof json === 'object' ? (json as Record<string, unknown>) : null);
    }
    return json as T;
  }
}
