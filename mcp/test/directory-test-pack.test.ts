import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/* G6: every tool named in docs/directory-test-pack.md exists, and the pack keeps 5 positive and 3 negative prompts. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const md = readFileSync(new URL('../docs/directory-test-pack.md', import.meta.url), 'utf8');

describe('docs/directory-test-pack.md', () => {
  it('names only tools that exist', async () => {
    app = await startApp();
    const names = new Set((await (await app.connect(TOKEN_FULL)).listTools()).tools.map((t) => t.name));
    const lines = md.split('\n').filter((l) => /tools_triggered|expected tools/.test(l));
    const named = lines.flatMap((l) => [...l.matchAll(/`([a-z]+_[a-z_]+)`/g)].map((m) => m[1]!));
    expect(named.length).toBeGreaterThan(0);
    const unknown = named.filter((n) => !names.has(n));
    expect(unknown, `docs/directory-test-pack.md names tools that do not exist: ${unknown.join(', ')}`).toEqual([]);
  });

  it('has 5 positive and 3 negative prompts', () => {
    expect(md.match(/^\*\*P\d — /gm)).toHaveLength(5);
    expect(md.match(/^\*\*N\d — /gm)).toHaveLength(3);
  });
});
