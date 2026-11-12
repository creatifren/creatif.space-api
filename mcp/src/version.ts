import { readFileSync } from 'node:fs';

/*
 * The server's version, read once from mcp/package.json — the single source
 * for serverInfo, GET /health and the User-Agent sent to the V1 API. src/ and
 * dist/ are both one level below mcp/, so the same relative path works from
 * source and from the build.
 */
export const VERSION: string = (JSON.parse(readFileSync(new URL('../package.json', import.meta.url), 'utf8')) as { version: string }).version;
