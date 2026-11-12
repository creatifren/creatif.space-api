import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/* The API README's MCP tools table (reused by Growth for the site) must
   name every tool the servers expose, so it cannot drift silently. */

const README = readFileSync(new URL('../../README.md', import.meta.url), 'utf8');
const rows = new Set([...README.matchAll(/^\| `(\w+)` \| [^|]+ \| [^|]+ \|$/gm)].map((m) => m[1]));

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('README MCP tools table', () => {
  it('lists every tool on /mcp and /free, each with a purpose and a plan', async () => {
    app = await startApp();
    const main = (await (await app.connect(TOKEN_FULL)).listTools()).tools.map((t) => t.name);
    const free = (await (await app.connectFree()).listTools()).tools.map((t) => t.name);
    for (const name of [...main, ...free]) expect(rows.has(name), `${name} missing from README.md`).toBe(true);
  });

  it('states the Free limits and keeps the slogan out', () => {
    const section = README.slice(README.indexOf('## MCP tools'), README.indexOf('## Android app'));
    expect(section).toMatch(/7 days ahead, 10 queued posts, approval links for one client/);
    expect(section).not.toMatch(/approved & published faster/i);
  });
});
