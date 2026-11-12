import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { FIRST_LINES } from '../src/tools/firstLines.js';
import { OUTPUT_SCHEMAS, UNSTRUCTURED } from '../src/tools/outputs.js';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * Meta-test: the tools the server serves are the one source of truth, and
 * every list that names them stays in step — the CHANGELOG count, the README
 * Tools table, server.json, docs/tool-justifications.md and the first lines;
 * every tool carries all four annotations; and every read-only tool either
 * declares an outputSchema or is listed in UNSTRUCTURED on purpose. A new
 * tool fails here with the exact list to update.
 */

const read = (rel: string) => readFileSync(new URL(rel, import.meta.url), 'utf8');

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const served = async () => {
  app = await startApp();
  return (await (await app.connect(TOKEN_FULL)).listTools()).tools;
};

/** Names in one list against the served names: what is missing, what is stale. */
const diff = (names: string[], list: string[]) => ({
  missing: names.filter((n) => !list.includes(n)).sort(),
  stale: list.filter((n) => !names.includes(n)).sort(),
  duplicates: list.filter((n, i) => list.indexOf(n) !== i).sort(),
});
const NONE = { missing: [], stale: [], duplicates: [] };

const readmeRows = () => {
  const readme = read('../README.md');
  const start = readme.indexOf('\n## Tools');
  return readme
    .slice(start, readme.indexOf('\n## ', start + 1))
    .split('\n')
    .map((l) => /^\| `([a-z0-9_]+)`/.exec(l)?.[1])
    .filter((n): n is string => !!n);
};
const justificationRows = () =>
  read('../docs/tool-justifications.md')
    .split('\n')
    .map((l) => /^\| `([a-z_]+)` \|/.exec(l)?.[1])
    .filter((n): n is string => !!n);
const serverJsonTools = (): string[] => JSON.parse(read('../server.json'))._meta['io.modelcontextprotocol.registry/publisher-provided'].tools;
const changelogCount = () => Number(/^(\d+) tools \(was \d+\)/m.exec(read('../CHANGELOG.md'))?.[1]);

describe('every tool list in step with the server', () => {
  it('CHANGELOG count, README Tools table, server.json, tool-justifications.md and first lines name exactly the served tools', async () => {
    const names = (await served()).map((t) => t.name);
    expect(names.length).toBeGreaterThan(40);
    expect(changelogCount(), 'mcp/CHANGELOG.md: "<N> tools (was 11)" at the top').toBe(names.length);
    expect(diff(names, readmeRows()), 'mcp/README.md ## Tools: one "| `tool` |" row each').toEqual(NONE);
    expect(diff(names, serverJsonTools()), 'mcp/server.json _meta tools').toEqual(NONE);
    expect(diff(names, justificationRows()), 'mcp/docs/tool-justifications.md: one row each').toEqual(NONE);
    expect(diff(names, Object.keys(FIRST_LINES)), 'src/tools/firstLines.ts').toEqual(NONE);
  });

  it('every tool carries all four annotation hints as booleans, and a title', async () => {
    const missing = (await served()).flatMap((t) =>
      (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as const)
        .filter((h) => typeof t.annotations?.[h] !== 'boolean')
        .map((h) => `${t.name}.${h}`)
        .concat(t.annotations?.title ? [] : [`${t.name}.title`]),
    );
    expect(missing).toEqual([]);
  });

  it('every read-only tool declares an outputSchema, or is in UNSTRUCTURED on purpose', async () => {
    const tools = await served();
    const names = tools.map((t) => t.name);
    const undecided = tools.filter((t) => t.annotations?.readOnlyHint && !OUTPUT_SCHEMAS[t.name] && !UNSTRUCTURED.has(t.name)).map((t) => t.name);
    expect(undecided, 'add an OUTPUT_SCHEMAS entry (src/tools/outputs.ts), or list the tool in UNSTRUCTURED').toEqual([]);

    // Both lists hold only served tools, never the same one twice, and a schema is really advertised.
    expect(diff(names, [...Object.keys(OUTPUT_SCHEMAS), ...UNSTRUCTURED]).stale).toEqual([]);
    expect(Object.keys(OUTPUT_SCHEMAS).filter((n) => UNSTRUCTURED.has(n))).toEqual([]);
    for (const name of Object.keys(OUTPUT_SCHEMAS)) {
      expect(tools.find((t) => t.name === name)?.outputSchema, name).toBeDefined();
    }
    for (const name of UNSTRUCTURED) expect(tools.find((t) => t.name === name)?.outputSchema, name).toBeUndefined();
  });

  it('catches the mistakes it is for', () => {
    expect(diff(['a', 'b'], ['b', 'c', 'c'])).toEqual({ missing: ['a'], stale: ['c', 'c'], duplicates: ['c'] });
    expect(diff(['a'], ['a'])).toEqual(NONE);
  });
});
