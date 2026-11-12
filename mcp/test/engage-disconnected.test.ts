import { afterEach, describe, expect, it } from 'vitest';
import { DISCONNECTED_RULE } from '../src/tools/engage.js';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

/* P1 privacy: a disconnected channel's DMs are not listed or readable (the API deletes them); the tools say so. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const open = async (routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return app.connect(TOKEN_FULL);
};

describe('Engage after a channel is disconnected', () => {
  it('list_conversations and reply_to_conversation tell the AI that disconnected channels are not listed', async () => {
    const client = await open({});
    const tools = (await client.listTools()).tools;
    for (const name of ['list_conversations', 'reply_to_conversation']) {
      expect(tools.find((t) => t.name === name)!.description).toContain(DISCONNECTED_RULE);
    }
  });

  it('an id of a disconnected channel answers not_found with a next step that says it is gone', async () => {
    const client = await open({
      'GET /inbox/feed/dm_01JD0000000000000000000001': () => ({ status: 404, body: { message: 'No conversation with that id in this workspace.', code: 'not_found' } }),
    });
    const res = await client.callTool({ name: 'reply_to_conversation', arguments: { conversation_id: 'dm_01JD0000000000000000000001', text: 'hi' } });
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'not_found' });
    expect(payload(res).error.next_step).toMatch(/disconnected is gone/);
  });

  it('list_conversations shows only what the API lists (the API leaves disconnected channels out)', async () => {
    const client = await open({ 'GET /inbox/feed': () => ({ body: { data: [], meta: { next_cursor: null } } }) });
    const out = payload(await client.callTool({ name: 'list_conversations', arguments: {} }));
    expect(JSON.stringify(out)).not.toContain('mrizalpurnama_');
  });
});
