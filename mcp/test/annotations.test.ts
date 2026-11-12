import { afterEach, describe, expect, it } from 'vitest';
import { startApp, TOKEN_FULL } from './helpers.js';

/*
 * MCP tool annotations (readOnlyHint / destructiveHint / idempotentHint /
 * openWorldHint) on every tool, by rule, and pinned by a snapshot so a
 * change is deliberate (vitest -u).
 *
 * Rules: list_* / get_* / check_* are read-only. Anything that goes public or
 * removes something — publish, schedule, reply, discard, revoke — is
 * destructive. A read-only tool is never destructive. A tool that reaches
 * outside creatif.space (the platforms, the client's inbox, the public web)
 * is open-world.
 */

const READ_PREFIXES = /^(list|get|check)_/;
const DESTRUCTIVE = ['publish_post', 'schedule_post', 'reply_to_conversation', 'discard_draft', 'publish_space', 'revoke_insight_share_link', 'retry_post'];
const OPEN_WORLD = ['publish_post', 'schedule_post', 'reply_to_conversation', 'publish_space', 'create_approval_link', 'create_content_plan', 'send_approval_reminder', 'decide_approval', 'create_insight_share_link', 'retry_post'];

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
  it('every tool sets all four hints as booleans, plus a title', async () => {
    for (const t of await tools()) {
      expect(t.annotations, t.name).toMatchObject({
        title: expect.any(String),
        readOnlyHint: expect.any(Boolean),
        destructiveHint: expect.any(Boolean),
        idempotentHint: expect.any(Boolean),
        openWorldHint: expect.any(Boolean),
      });
    }
  });

  it('list / get / check tools are read-only; read-only tools are never destructive', async () => {
    for (const t of await tools()) {
      if (READ_PREFIXES.test(t.name)) expect(t.annotations!.readOnlyHint, t.name).toBe(true);
      if (t.annotations!.readOnlyHint) expect(t.annotations!.destructiveHint, t.name).toBe(false);
    }
  });

  it('publish, schedule, reply, discard and revoke are destructive — and only they are', async () => {
    const list = await tools();
    expect(list.filter((t) => t.annotations!.destructiveHint).map((t) => t.name).sort()).toEqual([...DESTRUCTIVE].sort());
  });

  it('tools that reach outside creatif.space are open-world — and only they are', async () => {
    const list = await tools();
    expect(list.filter((t) => t.annotations!.openWorldHint).map((t) => t.name).sort()).toEqual([...OPEN_WORLD].sort());
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
