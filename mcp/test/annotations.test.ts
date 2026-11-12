import { readFileSync } from 'node:fs';
import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * MCP tool annotations (readOnlyHint / destructiveHint / idempotentHint /
 * openWorldHint) on every tool, by rule, and pinned by a snapshot so a
 * change is deliberate (vitest -u).
 *
 * Rules (directory readiness, G1): list_* / get_* / check_* are read-only.
 * openWorldHint is true only for tools that post publicly (a social network
 * or the public web); everything else is false. destructiveHint is true for
 * tools that post publicly, delete, revoke or record a decision that cannot
 * be taken back. A read-only tool is never destructive. schedule_posts is
 * held to the same rule as schedule_post once it is registered.
 */

const READ_PREFIXES = /^(list|get|check)_/;
const DESTRUCTIVE = ['publish_post', 'schedule_post', 'schedule_posts', 'reply_to_conversation', 'discard_draft', 'unkeep_file', 'decide_approval', 'revoke_approval_link', 'revoke_insight_share_link', 'publish_space', 'retry_post'];
const OPEN_WORLD = ['publish_post', 'schedule_post', 'schedule_posts', 'reply_to_conversation', 'publish_space', 'retry_post'];
const HINTS = ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as const;

/** The table in docs/tool-justifications.md: name → [title, ro, destructive, open]. */
function justifications() {
  const md = readFileSync(new URL('../docs/tool-justifications.md', import.meta.url), 'utf8');
  const rows = new Map<string, { title: string; readOnly: boolean; destructive: boolean; openWorld: boolean; why: string }>();
  for (const line of md.split('\n')) {
    const m = /^\| `([a-z_]+)` \| (.+?) \| (yes|no) \| (yes|no) \| (yes|no) \| (.+?) \|$/.exec(line);
    if (m) rows.set(m[1]!, { title: m[2]!, readOnly: m[3] === 'yes', destructive: m[4] === 'yes', openWorld: m[5] === 'yes', why: m[6]! });
  }
  return rows;
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function tools() {
  app = await startApp();
  return (await (await app.connect(TOKEN_FULL)).listTools()).tools;
}

describe('tool annotations', () => {
  it('every tool has a human title and sets all four hints as explicit booleans', async () => {
    for (const t of await tools()) {
      expect(typeof t.title, `${t.name} title`).toBe('string');
      expect(t.title!.trim().length, `${t.name} title`).toBeGreaterThan(3);
      expect(t.title, `${t.name} title is not the tool name`).not.toBe(t.name);
      expect(t.annotations?.title, t.name).toBe(t.title);
      for (const h of HINTS) expect(typeof t.annotations?.[h], `${t.name}.${h}`).toBe('boolean');
    }
  });

  it('list / get / check tools are read-only; read-only tools are never destructive', async () => {
    for (const t of await tools()) {
      if (READ_PREFIXES.test(t.name)) expect(t.annotations!.readOnlyHint, t.name).toBe(true);
      if (t.annotations!.readOnlyHint) expect(t.annotations!.destructiveHint, t.name).toBe(false);
    }
  });

  it('public posting, deleting, revoking and deciding are destructive — and only they are', async () => {
    const list = await tools();
    const names = new Set(list.map((t) => t.name));
    expect(list.filter((t) => t.annotations!.destructiveHint).map((t) => t.name).sort()).toEqual(DESTRUCTIVE.filter((n) => names.has(n)).sort());
  });

  it('only tools that post publicly are open-world', async () => {
    const list = await tools();
    const names = new Set(list.map((t) => t.name));
    expect(list.filter((t) => t.annotations!.openWorldHint).map((t) => t.name).sort()).toEqual(OPEN_WORLD.filter((n) => names.has(n)).sort());
  });

  it('docs/tool-justifications.md has one matching line per tool, and none for tools that do not exist', async () => {
    const list = await tools();
    const rows = justifications();
    const yesNo = (b: boolean | undefined) => (b ? 'yes' : 'no');
    const missing = list.filter((t) => !rows.has(t.name));
    // One message naming every missing tool and the exact line to add, so a
    // new tool merged without its line says how to fix it.
    expect(
      missing.map((t) => t.name),
      missing.length === 0
        ? ''
        : `Tool(s) without a line in mcp/docs/tool-justifications.md: ${missing.map((t) => t.name).join(', ')}.\n` +
            'Add one table row per tool (Title, RO, Destr., Open must match the tool; Why is one sentence):\n' +
            missing
              .map((t) => `| \`${t.name}\` | ${t.title} | ${yesNo(t.annotations?.readOnlyHint)} | ${yesNo(t.annotations?.destructiveHint)} | ${yesNo(t.annotations?.openWorldHint)} | <why> |`)
              .join('\n'),
    ).toEqual([]);
    for (const t of list) {
      const row = rows.get(t.name);
      expect(row, `mcp/docs/tool-justifications.md: the line for ${t.name} must match its title and hints`).toEqual({
        title: t.title,
        readOnly: t.annotations!.readOnlyHint,
        destructive: t.annotations!.destructiveHint,
        openWorld: t.annotations!.openWorldHint,
        why: expect.stringMatching(/\S{3}/),
      });
    }
    const names = new Set(list.map((t) => t.name));
    const stale = [...rows.keys()].filter((n) => !names.has(n));
    expect(stale, `mcp/docs/tool-justifications.md has lines for tools that do not exist: ${stale.join(', ')} — remove them.`).toEqual([]);
  });

  it('match the snapshot', async () => {
    const list = await tools();
    const table = Object.fromEntries(
      list
        .map((t) => [t.name, { readOnly: t.annotations!.readOnlyHint, destructive: t.annotations!.destructiveHint, idempotent: t.annotations!.idempotentHint, openWorld: t.annotations!.openWorldHint }] as const)
        .sort(([a], [b]) => a.localeCompare(b)),
    );
    expect(table).toMatchSnapshot();
  });
});
