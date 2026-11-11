import type { NextFunction, Request, Response } from 'express';

/*
 * CORS for browser-based MCP clients (MCP Inspector web, web IDEs). Auth is
 * the Bearer header only — no cookies — so any origin may call, and
 * credentials are never allowed. The same headers go on every response from
 * these paths, 401 and 429 included, so a browser can read WWW-Authenticate
 * and start OAuth discovery.
 */
export const CORS_PATHS = ['/mcp', '/', '/free'] as const;

export const CORS_HEADERS = {
  'Access-Control-Allow-Origin': '*',
  'Access-Control-Allow-Methods': 'GET, POST, DELETE, OPTIONS',
  'Access-Control-Allow-Headers': 'authorization, content-type, mcp-protocol-version, mcp-session-id, last-event-id',
  'Access-Control-Expose-Headers': 'mcp-session-id, www-authenticate',
  'Access-Control-Max-Age': '600',
} as const;

/** Registered before every route: answers the preflight, labels the rest. */
export function mcpCors(paths: readonly string[] = CORS_PATHS) {
  const match = new Set(paths.map((p) => p.replace(/\/+$/, '') || '/'));
  return (req: Request, res: Response, next: NextFunction) => {
    const path = req.path.replace(/\/+$/, '') || '/';
    if (!match.has(path)) {
      next();
      return;
    }
    for (const [k, v] of Object.entries(CORS_HEADERS)) res.setHeader(k, v);
    if (req.method === 'OPTIONS') {
      res.status(204).end();
      return;
    }
    next();
  };
}
