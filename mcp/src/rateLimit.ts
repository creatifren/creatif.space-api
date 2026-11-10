import type { NextFunction, Request, Response } from 'express';
import type { AuthInfo } from '@modelcontextprotocol/sdk/server/auth/types.js';
import { tokenKey } from './auth/introspection.js';

/** Fixed one-minute window per token. In memory: one process serves mcp.creatif.space. */
export class TokenRateLimiter {
  private readonly windows = new Map<string, { start: number; count: number }>();

  constructor(
    private readonly limit = 60,
    private readonly windowMs = 60_000,
    private readonly now: () => number = Date.now,
  ) {}

  /** Returns 0 when allowed, otherwise the seconds until the window resets. */
  hit(token: string): number {
    const key = tokenKey(token);
    const t = this.now();
    let w = this.windows.get(key);
    if (!w || t - w.start >= this.windowMs) {
      w = { start: t, count: 0 };
      this.windows.set(key, w);
      if (this.windows.size > 50_000) this.sweep(t);
    }
    w.count += 1;
    if (w.count > this.limit) {
      return Math.max(1, Math.ceil((w.start + this.windowMs - t) / 1000));
    }
    return 0;
  }

  private sweep(t: number) {
    for (const [k, w] of this.windows) {
      if (t - w.start >= this.windowMs) this.windows.delete(k);
    }
  }

  middleware() {
    return (req: Request & { auth?: AuthInfo }, res: Response, next: NextFunction) => {
      const token = req.auth?.token;
      if (!token) {
        next();
        return;
      }
      const retry = this.hit(token);
      if (retry > 0) {
        res.status(429).setHeader('Retry-After', String(retry)).json({
          jsonrpc: '2.0',
          error: {
            code: -32000,
            message: `rate_limited: more than ${this.limit} requests a minute for this token; retry in ${retry}s.`,
          },
          id: null,
        });
        return;
      }
      next();
    };
  }
}
