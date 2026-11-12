import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';

/* docs/registry-publish.md names both entries at the versions in their files,
   and the publish commands for both — it is the runbook Rizal follows. */

const read = (rel: string) => readFileSync(new URL(rel, import.meta.url), 'utf8');
const doc = read('../docs/registry-publish.md');
const main = JSON.parse(read('../server.json'));
const free = JSON.parse(read('../free-tools/server.json'));

describe('docs/registry-publish.md', () => {
  it('names both entries at the versions their server.json files carry', () => {
    for (const s of [main, free]) {
      expect(doc).toContain(`\`${s.name}\``);
      expect(doc).toContain(`\`${s.version}\``);
      expect(doc).toContain(s.remotes[0].url);
    }
  });

  it('gives device login, validate and publish for both files, with the pinned publisher', () => {
    expect(doc).toContain('./mcp-publisher login github');
    for (const f of ['mcp/server.json', 'mcp/free-tools/server.json']) {
      expect(doc).toContain(`./mcp-publisher validate ${f}`);
      expect(doc).toContain(`./mcp-publisher publish ${f}`);
    }
    const workflow = read('../../.github/workflows/mcp-registry.yml');
    const pinned = /MCP_PUBLISHER_VERSION: (v[\d.]+)/.exec(workflow)![1];
    expect(doc).toContain(`VERSION=${pinned}`);
  });

  it('says publishing is Rizal’s, and that nothing here was run', () => {
    expect(doc).toMatch(/Only Rizal runs these commands/);
    expect(doc).toMatch(/Nothing in this file has been executed/);
  });
});
