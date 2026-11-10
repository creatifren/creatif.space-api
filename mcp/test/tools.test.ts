import { afterEach, describe, expect, it } from 'vitest';
import { account, post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_READ, USER } from './helpers.js';

const IG = '01JACCTACCTACCTACCTACCTIG1';
const X = '01JACCTACCTACCTACCTACCTXX1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const FILE = '01JFILEFILEFILEFILEFILEFI1';

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const call = async (name: string, args: Record<string, unknown> = {}, routes = {}, token = TOKEN_ALL) => {
  app = await startApp({ routes });
  const client = await app.connect(token);
  return client.callTool({ name, arguments: args });
};

describe('list_channels', () => {
  it('maps GET /social-accounts and forwards the caller token', async () => {
    const res = await call('list_channels', {}, {
      'GET /social-accounts': () => ({ body: { data: [account(IG), account(X, 'x', { status: 'disconnected', reconnect_required: true })] } }),
    });
    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.channels).toHaveLength(2);
    expect(out.channels[0]).toEqual({
      id: IG, platform: 'instagram', username: 'instagram_user', status: 'connected', reconnect_required: false, connected_at: '2026-11-01T10:00:00Z',
    });
    expect(out.channels[1].reconnect_required).toBe(true);
    expect(out.channels[0].profile_photo_url).toBeUndefined();
    expect(app!.v1.calls[0]!.auth).toBe(`Bearer ${TOKEN_ALL}`);
  });

  it('emits mcp_tool_called with no content', async () => {
    await call('list_channels', {}, { 'GET /social-accounts': () => ({ body: { data: [account(IG)] } }) });
    await new Promise((r) => setTimeout(r, 10));
    expect(app!.events).toHaveLength(1);
    const { distinctId, event } = app!.events[0]!;
    expect(distinctId).toBe(USER);
    expect(Object.keys(event).sort()).toEqual(['client_name', 'ms', 'ok', 'tool']);
    expect(event).toMatchObject({ tool: 'list_channels', client_name: 'Claude', ok: true });
  });
});

describe('list_posts', () => {
  it('passes filters to GET /social-posts and drops comments', async () => {
    const res = await call(
      'list_posts',
      { status: 'scheduled', from: '2026-11-01T00:00:00Z', to: '2026-11-30T00:00:00Z', channel_id: IG, limit: 5 },
      { 'GET /social-posts': () => ({ body: { data: [post(POST, { comments: [{ body: 'x' }] })], meta: {} } }) },
    );
    expect(res.isError).toBeFalsy();
    expect(app!.v1.calls[0]!.query).toEqual({
      status: 'scheduled', from: '2026-11-01T00:00:00Z', to: '2026-11-30T00:00:00Z', account_id: IG, limit: '5',
    });
    const out = payload(res);
    expect(out.count).toBe(1);
    expect(out.posts[0]).toMatchObject({ id: POST, status: 'scheduled', media: ['https://cdn.test/1.jpg'] });
    expect(out.posts[0].channels[0]).toMatchObject({ channel_id: IG, platform: 'instagram' });
    expect(out.posts[0].comments).toBeUndefined();
  });

  it('defaults the limit to 25', async () => {
    await call('list_posts', {}, { 'GET /social-posts': () => ({ body: { data: [] } }) });
    expect(app!.v1.calls[0]!.query).toEqual({ limit: '25' });
  });

  it('rejects a bad date before calling V1', async () => {
    const res = await call('list_posts', { from: 'next tuesday' }, {});
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('get_post', () => {
  it('returns the post with its review thread', async () => {
    const res = await call('get_post', { post_id: POST }, {
      [`GET /social-posts/${POST}`]: () => ({ body: { data: post(POST, { comments: [{ id: 'c1', body: 'Looks good', role: 'client' }] }) } }),
    });
    const out = payload(res);
    expect(out.post.id).toBe(POST);
    expect(out.post.comments).toEqual([{ id: 'c1', body: 'Looks good', role: 'client' }]);
  });

  it('answers not_found for a 404', async () => {
    const res = await call('get_post', { post_id: POST }, {});
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'not_found', message: 'That post was not found.' });
  });

  it('answers forbidden for someone else’s post', async () => {
    const res = await call('get_post', { post_id: POST }, {
      [`GET /social-posts/${POST}`]: () => ({ status: 403, body: { message: 'This action is unauthorized.' } }),
    });
    expect(payload(res).error.code).toBe('forbidden');
  });
});

describe('list_files', () => {
  it('maps GET /files with search, type and per_page', async () => {
    const res = await call('list_files', { search: 'noel', type: 'image', limit: 10 }, {
      'GET /files': () => ({
        body: {
          data: [{ id: FILE, name: 'noel.jpg', mime_type: 'image/jpeg', size_bytes: 10, width: 1, height: 1, status: 'ready', url: 'https://r2.test/s', exif: { a: 1 }, created_at: 'x' }],
          meta: { total: 1 },
        },
      }),
    });
    expect(app!.v1.calls[0]!.query).toEqual({ search: 'noel', type: 'image', per_page: '10' });
    const out = payload(res);
    expect(out.total).toBe(1);
    expect(out.files[0]).toMatchObject({ id: FILE, name: 'noel.jpg', url: 'https://r2.test/s' });
    expect(out.files[0].exif).toBeUndefined();
  });
});

describe('draft_post', () => {
  const routes = {
    'POST /social-posts': ({ body }: { body: unknown }) => ({
      status: 201,
      body: { data: post(POST, { status: 'draft', scheduled_at: null, caption: (body as { caption: string }).caption }) },
    }),
  };

  it('always sends draft:true with channels, captions, files and urls', async () => {
    const res = await call(
      'draft_post',
      {
        caption: 'Hello',
        channel_ids: [IG, X],
        file_ids: [FILE],
        media_urls: ['https://cdn.example.com/a.jpg'],
        captions_by_channel: { [X]: 'Short' },
      },
      routes,
    );
    expect(res.isError).toBeFalsy();
    expect(app!.v1.calls[0]!.body).toEqual({
      caption: 'Hello',
      account_ids: [IG, X],
      captions: { [X]: 'Short' },
      media: [{ url: 'https://cdn.example.com/a.jpg' }],
      file_ids: [FILE],
      type: 'post',
      draft: true,
    });
    const out = payload(res);
    expect(out.post.status).toBe('draft');
    expect(out.note).toMatch(/Nothing was published/);
  });

  it('needs the draft scope', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG] }, routes, TOKEN_READ);
    expect(res.isError).toBe(true);
    expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'draft', granted: ['read'] } });
    expect(app!.v1.calls).toHaveLength(0);
    await new Promise((r) => setTimeout(r, 10));
    expect(app!.events[0]!.event).toMatchObject({ tool: 'draft_post', ok: false, error: 'scope_missing' });
  });

  it('refuses http (non-https) media URLs', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG], media_urls: ['http://x.test/a.jpg'] }, routes);
    expect(res.isError).toBe(true);
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('refuses per-channel captions for channels not in channel_ids', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG], captions_by_channel: { [X]: 'x' } }, routes);
    expect(payload(res).error).toMatchObject({ code: 'invalid_input', details: { channels: [X] } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('maps account_reconnect_required to reconnect_required', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG] }, {
      'POST /social-posts': () => ({
        status: 422,
        body: { message: 'This account needs to be reconnected: @studio', code: 'account_reconnect_required', accounts: [{ id: IG }] },
      }),
    });
    expect(payload(res).error).toMatchObject({ code: 'reconnect_required', details: { accounts: [{ id: IG }] } });
  });

  it('maps a validation error to invalid_input with the fields', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG], file_ids: [FILE] }, {
      'POST /social-posts': () => ({
        status: 422,
        body: { message: 'One or more files are not ready files in your library.', errors: { file_ids: ['…'] } },
      }),
    });
    expect(payload(res).error).toMatchObject({ code: 'invalid_input', details: { errors: { file_ids: ['…'] } } });
  });

  it('maps a plan gate to plan_required', async () => {
    const res = await call('draft_post', { caption: 'Hi', channel_ids: [IG] }, {
      'POST /social-posts': () => ({ status: 422, body: { message: 'Client approvals are part of Pro and Max.' } }),
    });
    expect(payload(res).error.code).toBe('plan_required');
  });
});

describe('get_credits', () => {
  it('maps GET /credits', async () => {
    const res = await call('get_credits', {}, {
      'GET /credits': () => ({
        body: {
          data: {
            plan: 'pro', balance: 5, monthly_balance: 100, monthly_resets_at: '2026-12-01T00:00:00Z', monthly_amount: 300,
            topup_balance: 5, total: 105, committed: 10,
            rate: { per_send: 1, per_send_by_platform: { x: 2 }, per_dollar: 100 },
          },
        },
      }),
    });
    expect(payload(res)).toEqual({
      plan: 'pro', total: 105, monthly_balance: 100, monthly_amount: 300, monthly_resets_at: '2026-12-01T00:00:00Z',
      topup_balance: 5, committed: 10, per_send: 1, per_send_by_platform: { x: 2 },
    });
  });

  it('maps an expired upstream token to unauthorized and a 5xx to upstream_unavailable', async () => {
    let res = await call('get_credits', {}, { 'GET /credits': () => ({ status: 401, body: { message: 'Unauthenticated.' } }) });
    expect(payload(res).error.code).toBe('unauthorized');
    await app!.close();
    res = await call('get_credits', {}, { 'GET /credits': () => ({ status: 502 }) });
    expect(payload(res).error.code).toBe('upstream_unavailable');
  });
});
