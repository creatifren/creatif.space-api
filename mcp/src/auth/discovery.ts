import express, { type NextFunction, type Request, type Response } from 'express';
import type { AuthInfo } from '@modelcontextprotocol/sdk/server/auth/types.js';
import { challenge, type CallerAuth } from './middleware.js';
import type { TokenRateLimiter } from '../rateLimit.js';

/*
 * Public discovery (MCP_PUBLIC_DISCOVERY, off by default): a POST with no
 * Authorization header whose JSON-RPC messages are ALL handshake or listing
 * — initialize, notifications/initialized, ping, tools/list — is answered
 * without a token, so registry crawlers and client previews can see the
 * tools. Anything else without a token (tools/call above all) gets the same
 * 401 + WWW-Authenticate (resource_metadata) as before, which is what makes
 * Claude and ChatGPT show the sign-in. A request that carries a token always
 * goes through normal auth, so a bad token is still a 401, even for
 * initialize. Off: this middleware only passes the request on.
 */

export const PUBLIC_METHODS = new Set(['initialize', 'notifications/initialized', 'ping', 'tools/list']);

/** Who the anonymous caller is to the tool layer: no token, no scopes. */
export const ANONYMOUS: CallerAuth = {
  token: '',
  clientId: 'anonymous',
  scopes: [],
  extra: { userUlid: 'anonymous', clientName: 'anonymous', tokenType: 'oauth', anonymous: true },
};

const isPublic = (body: unknown): boolean => {
  const msgs = Array.isArray(body) ? body : [body];
  return (
    msgs.length > 0 &&
    msgs.every((m) => typeof m === 'object' && m !== null && typeof (m as { method?: unknown }).method === 'string' && PUBLIC_METHODS.has((m as { method: string }).method))
  );
};

export function publicDiscovery(opts: {
  enabled: boolean;
  resourceUrl: string;
  /** Per-IP limit for anonymous calls. */
  limiter: TokenRateLimiter;
  handle: (req: Request & { auth?: AuthInfo }, res: Response) => Promise<void>;
}) {
  const json = express.json({ limit: '64kb' });
  return (req: Request & { auth?: AuthInfo }, res: Response, next: NextFunction) => {
    if (!opts.enabled || req.headers.authorization !== undefined) {
      next();
      return;
    }
    json(req, res, (err?: unknown) => {
      if (err || !isPublic(req.body)) {
        challenge(res, opts.resourceUrl);
        return;
      }
      const retry = opts.limiter.hit(`anon-ip:${req.ip ?? 'unknown'}`);
      if (retry > 0) {
        res.status(429).setHeader('Retry-After', String(retry)).json({
          jsonrpc: '2.0',
          error: { code: -32000, message: `rate_limited: too many requests without sign-in from this address; retry in ${retry}s.` },
          id: null,
        });
        return;
      }
      req.auth = ANONYMOUS;
      void opts.handle(req, res);
    });
  };
}
