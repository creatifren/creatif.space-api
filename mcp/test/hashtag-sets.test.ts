import { afterEach, describe, expect, it } from 'vitest';
import { payload, startApp, TOKEN_FULL } from './helpers.js';

/* P12 · v10.2 Hashtag sets + First comment over MCP. */

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>) => {
  app = await startApp({ routes: routes as never });
  return app.connect(TOKEN_FULL);
};

const HOTEL = '01JCLIENTCLIENTCLIENTHOTL1';
const OTHER = '01JCLIENTCLIENTCLIENTOTHR1';
const IG = '01JACCTACCTACCTACCTACCTIG1';
const sets = [
  { id: 'hs1', name: 'Ombak core', tags: ['#ombak', '#bali'], client_id: HOTEL },
  { id: 'hs2', name: 'Everyone', tags: ['#travel'], client_id: null },
  { id: 'hs3', name: 'Other', tags: ['#x'], client_id: OTHER },
];

describe('hashtag sets + first comment', () => {
  it('lists a client’s sets plus the sets for every client', async () => {
    const client = await start({ 'GET /me/social-settings': () => ({ body: { data: { hashtag_sets: sets } } }) });
    const out = payload(await client.callTool({ name: 'list_hashtag_sets', arguments: { client_id: HOTEL } }));
    expect(out.sets.map((s: { name: string }) => s.name)).toEqual(['Ombak core', 'Everyone']);
    expect(out.next_cursor).toBeNull();
  });

  it('draft_post sends first_comment', async () => {
    const post = { id: '01JPOSTPOSTPOSTPOSTPOSTPO1', caption: 'Hi', type: 'post', labels: [], media: [], scheduled_at: null, status: 'draft', fail_reason: null, approval: null, first_comment: '#ombak #bali', created_at: '2026-11-20T00:00:00Z', targets: [] };
    const client = await start({ 'POST /social-posts': () => ({ status: 201, body: { data: post } }) });
    const out = payload(await client.callTool({ name: 'draft_post', arguments: { caption: 'Hi', channel_ids: [IG], first_comment: '#ombak #bali' } }));
    expect(app!.v1.calls[0]).toMatchObject({ method: 'POST', path: '/social-posts', body: { first_comment: '#ombak #bali', draft: true } });
    expect(out.post.first_comment).toBe('#ombak #bali');
  });
});
