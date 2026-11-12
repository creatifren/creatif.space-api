import { readFileSync, writeFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { ALL_TOOLS } from '../src/server.js';
import { DATA_NOTICE } from '../src/tools/define.js';
import { startApp, TOKEN_FULL } from './helpers.js';
import { renderToolsDoc } from './toolsDoc.js';

/* docs/mcp-tools.md is generated from the served tool list; CI fails when it is stale. UPDATE_TOOLS_DOC=1 rewrites it. */

const DOC = new URL('../docs/mcp-tools.md', import.meta.url);

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('docs/mcp-tools.md', () => {
  it('is up to date with the served tools (npm run docs:tools regenerates it)', async () => {
    app = await startApp();
    const { tools } = await (await app.connect(TOKEN_FULL)).listTools();
    const scopes = Object.fromEntries(ALL_TOOLS.map((t) => [t.name, t.scope]));
    const want = renderToolsDoc(tools, scopes, DATA_NOTICE);
    if (process.env.UPDATE_TOOLS_DOC === '1') writeFileSync(DOC, want);
    const have = readFileSync(DOC, 'utf8');
    expect(have === want, 'docs/mcp-tools.md is out of date: run `npm run docs:tools` in mcp/ and commit it').toBe(true);
  });

  it('documents every served tool, with its scope', async () => {
    app = await startApp();
    const { tools } = await (await app.connect(TOKEN_FULL)).listTools();
    const doc = readFileSync(DOC, 'utf8');
    expect(tools.length).toBe(ALL_TOOLS.length);
    for (const t of tools) expect(doc, t.name).toContain(`\n## ${t.name}\n`);
  });
});
