import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * The API README's "MCP tools" section (for Growth and the site): one row per
 * /mcp tool and per /free tool, every prompt named, no stale rows, the Free
 * limits stated. Fails when a tool is added, renamed or removed without the
 * README following.
 */

const readme = readFileSync(new URL('../../README.md', import.meta.url), 'utf8');
const section = (() => {
  const start = readme.indexOf('\n## MCP tools');
  return readme.slice(start, readme.indexOf('\n## ', start + 1));
})();
const [main, free] = section.split('**Free Tools**');
const rows = (text: string) => [...text!.matchAll(/^\|(?: [A-Za-z]+ \|)? `([a-z0-9_]+)` \|/gm)].map((m) => m[1]!);

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('API README → MCP tools', () => {
  it('has one row per /mcp tool, and no row for a tool that is gone', async () => {
    app = await startApp();
    const tools = (await (await app.connect(TOKEN_FULL)).listTools()).tools.map((t) => t.name).sort();
    expect(rows(main!).sort()).toEqual(tools);
  });

  it('has one row per /free tool', async () => {
    app = await startApp();
    const tools = (await (await app.connectFree()).listTools()).tools.map((t) => t.name).sort();
    expect(rows(free!).sort()).toEqual(tools);
  });

  it('names every prompt and states the Free limits and the connector URL', async () => {
    app = await startApp();
    const prompts = (await (await app.connect(TOKEN_FULL)).listPrompts()).prompts.map((p) => p.name);
    for (const p of prompts) expect(section, p).toContain(`\`${p}\``);
    expect(section).toMatch(/7 days ahead.*10\s+queued posts.*one client/s);
    expect(section).toContain('https://mcp.creatif.space/mcp');
  });
});
