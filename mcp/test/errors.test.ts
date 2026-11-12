import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_FULL, TOKEN_READ } from './helpers.js';
import { previewTokens } from '../src/tools/m2.js';

/*
 * One test per error path. Every error must carry the same envelope:
 * a code, a human message, a concrete next_step and non-empty details
 * (at least the tool) — and a next_step that points at details.X only
 * when X is really there.
 */

const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const POST2 = '01JPOSTPOSTPOSTPOSTPOSTPO2';
const POST3 = '01JPOSTPOSTPOSTPOSTPOSTPO3';
const LINK = '01JLINKLINKLINKLINKLINKLI1';
const IG = '01JACCTACCTACCTACCTACCTIG1';
const FILE = '01JFILEFILEFILEFILEFILEFI1';

type Route = () => { status?: number; body?: unknown };

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
  previewTokens.clear();
});

const GENERIC = [/^try again\.?$/i, /^try again in a minute\.?$/i, /^unexpected error\.?$/i, /answered \d{3}/];

// eslint-disable-next-line @typescript-eslint/no-explicit-any -- test assertions
async function fail(tool: string, args: Record<string, unknown>, routes: Record<string, Route> = {}, token = TOKEN_FULL): Promise<any> {
  app = await startApp({ routes });
  const client = await app.connect(token);
  const res = await client.callTool({ name: tool, arguments: args });
  expect(res.isError).toBe(true);
  const { error } = payload(res);
  expect(Object.keys(error).sort()).toEqual(['code', 'details', 'message', 'next_step']);
  expect(typeof error.message).toBe('string');
  expect(error.message.length).toBeGreaterThan(10);
  for (const g of GENERIC) expect(error.message).not.toMatch(g);
  expect(typeof error.next_step).toBe('string');
  expect(error.next_step.length).toBeGreaterThan(20);
  expect(Object.keys(error.details).length).toBeGreaterThan(0);
  // A next_step that names a details field must find it there, non-empty.
  for (const [, field] of error.next_step.matchAll(/details\.(\w+)/g)) {
    expect(error.details[field], `details.${field}`).toBeDefined();
    expect(JSON.stringify(error.details[field])).not.toMatch(/^(\{\}|\[\]|"")$/);
  }
  return error;
}

const v1 = (status: number, body: unknown): Route => () => ({ status, body });
const GET_POST = `GET /social-posts/${POST}`;

describe('API answers → tool errors (any tool)', () => {
  it('API unreachable → upstream_unavailable, retryable', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: () => { throw new Error('ECONNREFUSED'); } });
    expect(e).toMatchObject({ code: 'upstream_unavailable', details: { tool: 'get_post', retryable: true } });
  });

  it('an unexpected answer shape → internal_error, not a crash', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(200, {}) });
    expect(e).toMatchObject({ code: 'internal_error', details: { tool: 'get_post' } });
  });

  it('500 on a read → upstream_unavailable with the status, retry', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(500, { message: 'Server Error' }) });
    expect(e).toMatchObject({ code: 'upstream_unavailable', details: { http_status: 500, retryable: true } });
    expect(e.message).not.toMatch(/500/);
  });

  it('500 on a create → verify before retrying (it may have gone through)', async () => {
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG] }, { 'POST /social-posts': v1(500, {}) });
    expect(e).toMatchObject({ code: 'upstream_unavailable', details: { retryable: false, verify_first: true } });
    expect(e.next_step).toMatch(/list_posts/);
  });

  it('account_reconnect_required → reconnect_required naming the accounts', async () => {
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG] }, {
      'POST /social-posts': v1(409, { code: 'account_reconnect_required', message: 'Reconnect Instagram.', accounts: [{ id: IG }] }),
    });
    expect(e).toMatchObject({ code: 'reconnect_required', details: { accounts: [{ id: IG }] } });
  });

  it('reconnect_required without an accounts list still has details', async () => {
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG] }, {
      'POST /social-posts': v1(409, { code: 'account_reconnect_required', message: 'Reconnect a channel.' }),
    });
    expect(e).toMatchObject({ code: 'reconnect_required', details: { accounts: [], reason: 'channel_disconnected' } });
    expect(e.next_step).toMatch(/list_channels/);
  });

  it('plan_required (402) → the API sentence, plan details', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(402, { message: 'Analytics is part of Pro.', feature: 'analytics' }) });
    expect(e).toMatchObject({ code: 'plan_required', message: 'Analytics is part of Pro.', details: { feature: 'analytics' } });
  });

  it('insufficient_credits → needed vs available, nothing charged', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { code: 'insufficient_credits', message: 'Needs 30 Credits, 10 left.', needed: 30, available: 10 }) });
    expect(e).toMatchObject({ code: 'insufficient_credits', details: { needed: 30, available: 10, charged: false } });
  });

  it('older Credits answer without a code → insufficient_credits', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { message: 'Not enough Credits — top up to continue.' }) });
    expect(e).toMatchObject({ code: 'insufficient_credits', details: { charged: false } });
  });

  it('quota_exceeded → resets_at', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { code: 'quota_exceeded', message: 'Monthly Credits used up.', resets_at: '2026-12-01' }) });
    expect(e).toMatchObject({ code: 'quota_exceeded', details: { resets_at: '2026-12-01' } });
  });

  it('daily_send_cap → daily_cap_reached with cap and reset', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(429, { code: 'daily_send_cap', message: 'Daily limit reached.', cap: 20, resets_at: 'tomorrow' }) });
    expect(e).toMatchObject({ code: 'daily_cap_reached', details: { cap: 20, resets_at: 'tomorrow' } });
  });

  it('draft_only → forbidden, do it in creatif.space', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(403, { code: 'draft_only', message: 'Connected apps can only draft.' }) });
    expect(e).toMatchObject({ code: 'forbidden', details: { reason: 'draft_only' } });
  });

  it('not_editable from the API', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { code: 'not_editable', message: 'This post is queued.' }) });
    expect(e).toMatchObject({ code: 'not_editable' });
    expect(e.next_step).toMatch(/creatif\.space/);
  });

  it('approval_required → points at the approval tools', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { code: 'approval_required', message: 'Needs approval.' }) });
    expect(e).toMatchObject({ code: 'approval_required' });
    expect(e.next_step).toMatch(/create_approval_link/);
  });

  it('scope_missing from the API', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(403, { error: 'insufficient_scope', message: 'Missing scope.', required: 'publish' }) });
    expect(e).toMatchObject({ code: 'scope_missing', details: { required: 'publish' } });
  });

  it('401 → unauthorized, reconnect', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(401, { message: 'Unauthenticated.' }) });
    expect(e).toMatchObject({ code: 'unauthorized', details: { reason: 'token_rejected' } });
  });

  it('403 → forbidden by role', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(403, { message: 'Viewers cannot do this.' }) });
    expect(e).toMatchObject({ code: 'forbidden', message: 'Viewers cannot do this.', details: { reason: 'role' } });
  });

  it('404 → not_found with this tool’s way to a valid id', async () => {
    const e = await fail('get_post', { post_id: POST });
    expect(e).toMatchObject({ code: 'not_found', message: 'That post was not found in this workspace.' });
    expect(e.next_step).toMatch(/list_posts/);
  });

  it('404 on a list filter → not_found that says to drop the filter', async () => {
    const e = await fail('list_files', { folder_id: FILE }, { 'GET /files': v1(404, { message: 'Not Found' }) });
    expect(e).toMatchObject({ code: 'not_found', message: 'That folder was not found in this workspace.' });
    expect(e.next_step).toMatch(/without folder_id/);
  });

  it('409 → invalid_input, fetch the current state', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(409, { message: 'It changed.' }) });
    expect(e).toMatchObject({ code: 'invalid_input', details: { reason: 'conflict' } });
  });

  it('422 with field errors → details.errors', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { message: 'Bad caption.', errors: { caption: ['Too long.'] } }) });
    expect(e).toMatchObject({ code: 'invalid_input', details: { errors: { caption: ['Too long.'] } } });
  });

  it('422 without field errors → never points at an empty details.errors', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(422, { message: 'That cannot be done.' }) });
    expect(e).toMatchObject({ code: 'invalid_input', message: 'That cannot be done.', details: { reason: 'refused' } });
    expect(e.next_step).not.toMatch(/details\.errors/);
  });

  it('429 → rate_limited, wait', async () => {
    const e = await fail('get_post', { post_id: POST }, { [GET_POST]: v1(429, { message: 'Too Many Attempts.' }) });
    expect(e).toMatchObject({ code: 'rate_limited', details: { retry_after_seconds: 60 } });
  });
});

describe('MCP-side refusals', () => {
  it('a tool outside the granted scopes → scope_missing with required vs granted', async () => {
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG] }, {}, TOKEN_READ);
    expect(e).toMatchObject({ code: 'scope_missing', details: { required: 'draft', granted: ['read'] } });
  });

  it('arguments that fail the schema → invalid_input listing each field', async () => {
    const e = await fail('draft_post', { channel_ids: [IG], type: 'carousel' });
    expect(e).toMatchObject({ code: 'invalid_input', details: { tool: 'draft_post' } });
    const fields = e.details.issues.map((i: { field: string }) => i.field);
    expect(fields).toEqual(expect.arrayContaining(['caption', 'type']));
  });

  it('an unknown tool name → unknown_tool listing the real ones', async () => {
    const e = await fail('delete_post', { post_id: POST });
    expect(e).toMatchObject({ code: 'unknown_tool', details: { requested: 'delete_post' } });
    expect(e.details.available_tools).toContain('draft_post');
  });
});

describe('tool-specific errors', () => {
  it('draft_post: captions_by_channel for a channel not in channel_ids', async () => {
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG], captions_by_channel: { [POST]: 'y' } });
    expect(e).toMatchObject({ code: 'invalid_input', details: { field: 'captions_by_channel', channels: [POST] } });
  });

  it('draft_post: more than 10 media in total', async () => {
    const urls = Array.from({ length: 6 }, (_, i) => `https://cdn.test/${i}.jpg`);
    const files = Array.from({ length: 5 }, (_, i) => `01JFILEFILEFILEFILEFILEF${i}0`);
    const e = await fail('draft_post', { caption: 'x', channel_ids: [IG], media_urls: urls, file_ids: files });
    expect(e).toMatchObject({ code: 'invalid_input', details: { max: 10, file_ids: 5, media_urls: 6 } });
  });

  it('check_approval_status: neither post_id nor link_id', async () => {
    const e = await fail('check_approval_status', {});
    expect(e).toMatchObject({ code: 'invalid_input', details: { given: { post_id: false, link_id: false } } });
  });

  it('update_post: file_ids and add_file_ids together', async () => {
    const e = await fail('update_post', { post_id: POST, file_ids: [FILE], add_file_ids: [FILE] });
    expect(e).toMatchObject({ code: 'invalid_input', details: { fields: ['file_ids', 'add_file_ids'] } });
  });

  it('update_post: nothing to change', async () => {
    const e = await fail('update_post', { post_id: POST });
    expect(e).toMatchObject({ code: 'invalid_input' });
    expect(e.details.fields).toContain('caption');
  });

  it('update_post: a published post → not_editable with its status', async () => {
    const e = await fail('update_post', { post_id: POST, caption: 'new' }, { [GET_POST]: () => ({ body: { data: post(POST, { status: 'published' }) } }) });
    expect(e).toMatchObject({ code: 'not_editable', details: { post_id: POST, status: 'published' } });
  });

  it('update_post: adding past 10 media', async () => {
    const media = Array.from({ length: 9 }, (_, i) => ({ url: `https://cdn.test/${i}.jpg` }));
    const e = await fail('update_post', { post_id: POST, add_file_ids: [FILE, FILE.replace('1', '2')] }, {
      [GET_POST]: () => ({ body: { data: post(POST, { status: 'draft', media }) } }),
    });
    expect(e).toMatchObject({ code: 'invalid_input', details: { max: 10, current: 9, adding: 2 } });
  });

  it('schedule_post: a time in the past', async () => {
    const e = await fail('schedule_post', { post_id: POST, scheduled_at: '2020-01-01T00:00:00Z' });
    expect(e).toMatchObject({ code: 'invalid_input', details: { field: 'scheduled_at' } });
  });

  it('publish_post: not a draft → not_draft with its status', async () => {
    const e = await fail('publish_post', { post_id: POST }, {
      [`GET /social-posts/${POST}/send-preview`]: () => ({ body: { data: { post_id: POST, sendable: false, status: 'published' } } }),
    });
    expect(e).toMatchObject({ code: 'not_draft', details: { post_id: POST, status: 'published' } });
    expect(e.next_step).toMatch(/list_posts with status "draft"/);
  });

  it('publish_post: the API calls the preview stale → preview_stale with the new preview', async () => {
    const preview = {
      post_id: POST, sendable: true, status: 'draft', scheduled_at: null,
      targets: [{ channel_id: IG, platform: 'instagram', username: 'studio', usable: true, credits: 15 }],
      credits: { price: 15, from_topup: 0, monthly_balance: 300, topup_balance: 0, can_afford: true },
      daily_cap: { cap: 20, used: 0, remaining: 20 },
      confirm_token: 't.sig', confirm_expires_at: new Date(Date.now() + 600_000).toISOString(),
    };
    app = await startApp({
      routes: {
        [`GET /social-posts/${POST}/send-preview`]: () => ({ body: { data: preview } }),
        [`POST /social-posts/${POST}/send`]: () => ({ status: 409, body: { code: 'preview_stale', message: 'Stale.' } }),
      },
    });
    const client = await app.connect(TOKEN_FULL);
    await client.callTool({ name: 'publish_post', arguments: { post_id: POST } });
    const res = await client.callTool({ name: 'publish_post', arguments: { post_id: POST, confirm: true } });
    const { error } = payload(res);
    expect(error).toMatchObject({ code: 'preview_stale', details: { tool: 'publish_post', preview: { post_id: POST } } });
    expect(error.next_step).toMatch(/details\.preview/);
  });

  it('confirm_media_upload: a refused upload → invalid_input with its status', async () => {
    const e = await fail('confirm_media_upload', { file_id: FILE }, {
      [`POST /files/${FILE}/complete`]: v1(409, { message: 'Not pending.' }),
      [`GET /files/${FILE}`]: () => ({ body: { data: { id: FILE, name: 'a.jpg', mime_type: 'image/jpeg', size_bytes: 1, status: 'rejected' } } }),
    });
    expect(e).toMatchObject({ code: 'invalid_input', details: { file_id: FILE, status: 'rejected' } });
  });

  it('request_media_upload: upload_limit has details', async () => {
    const e = await fail('request_media_upload', { filename: 'a.jpg', mime: 'image/jpeg', size_bytes: 10 }, {
      'POST /files/presign': v1(429, { reason: 'upload_limit', message: 'Uploads are paused for today.' }),
    });
    expect(e).toMatchObject({ code: 'upload_limit', details: { retryable: false } });
  });

  it('request_media_upload: unsupported_format lists what is accepted', async () => {
    const e = await fail('request_media_upload', { filename: 'a.heic', mime: 'image/heic', size_bytes: 10 }, {
      'POST /files/presign': v1(422, { message: 'Unsupported.', errors: { mime_type: ['HEIC is not supported.'] } }),
    });
    expect(e).toMatchObject({ code: 'unsupported_format', message: 'HEIC is not supported.' });
    expect(e.details.accepted).toContain('image/jpeg');
  });

  it('confirm_media_upload: before the PUT → upload_incomplete with the API errors', async () => {
    const e = await fail('confirm_media_upload', { file_id: FILE }, {
      [`POST /files/${FILE}/complete`]: v1(422, { message: 'x', errors: { file: ['The upload has not arrived.'] } }),
    });
    expect(e).toMatchObject({ code: 'upload_incomplete', details: { errors: { file: ['The upload has not arrived.'] } } });
  });
});

describe('create_approval_link refusals (QA P3)', () => {
  const REFUSE = { 'POST /social-approval-links': v1(422, { message: 'None of these posts can still be approved.' }) };

  it('names each refused post with its status and reason, and the next step', async () => {
    const e = await fail('create_approval_link', { post_ids: [POST, POST2, POST3], approver_email: 'rani@client.test' }, {
      ...REFUSE,
      [GET_POST]: () => ({ body: { data: post(POST, { status: 'published' }) } }),
      [`GET /social-posts/${POST2}`]: () => ({ body: { data: post(POST2, { status: 'scheduled', approval: 'approved' }) } }),
      // POST3: not in this workspace (404 from the mock).
    });
    expect(e.code).toBe('not_approvable');
    expect(e.details.refused).toEqual([
      { post_id: POST, status: 'published', reason: expect.stringMatching(/already published/) },
      { post_id: POST2, status: 'scheduled', reason: expect.stringMatching(/handed to the channels/) },
      { post_id: POST3, status: 'not_found', reason: 'no such post in this workspace' },
    ]);
    expect(e.next_step).toMatch(/list_posts with status "draft"/);
    expect(e.next_step).toMatch(/draft_post/);
    expect(e.next_step).not.toMatch(/details\.errors/);
  });

  it('a refused post that cannot be read right now is still listed', async () => {
    const e = await fail('create_approval_link', { post_ids: [POST], approver_email: 'rani@client.test' }, {
      ...REFUSE,
      [GET_POST]: v1(500, {}),
    });
    expect(e.details.refused).toEqual([{ post_id: POST, status: 'unknown', reason: 'could not be checked right now' }]);
  });

  it('plan limits on the same endpoint still come back as plan_required', async () => {
    const e = await fail('create_approval_link', { post_ids: [POST], approver_email: 'rani@client.test' }, {
      'POST /social-approval-links': v1(402, { code: 'plan_required', message: 'Free covers one client.', feature: 'approval_clients' }),
    });
    expect(e).toMatchObject({ code: 'plan_required', details: { feature: 'approval_clients' } });
  });

  it('a link made with some posts left off names the skipped ones (not an error)', async () => {
    app = await startApp({
      routes: {
        'POST /social-approval-links': () => ({
          status: 201,
          body: { data: { id: LINK, url: 'https://creatif.space/a/t', status: 'active', posts: 1, approver_email: 'rani@client.test', expires_at: null } },
        }),
        [`GET /social-approval-links/${LINK}`]: () => ({ body: { data: { link: { id: LINK }, posts: [post(POST, { status: 'scheduled', approval: 'client' })] } } }),
        [`GET /social-posts/${POST2}`]: () => ({ body: { data: post(POST2, { status: 'published' }) } }),
      },
    });
    const client = await app.connect(TOKEN_FULL);
    const res = await client.callTool({ name: 'create_approval_link', arguments: { post_ids: [POST, POST2], approver_email: 'rani@client.test' } });
    expect(res.isError).toBeFalsy();
    const out = payload(res);
    expect(out.link.posts).toBe(1);
    expect(out.skipped).toEqual([{ post_id: POST2, status: 'published', reason: expect.stringMatching(/already published/) }]);
    expect(out.note).toMatch(/skipped/);
  });

  it('a full link has no skipped list and makes no extra reads', async () => {
    app = await startApp({
      routes: {
        'POST /social-approval-links': () => ({
          status: 201,
          body: { data: { id: LINK, url: 'u', status: 'active', posts: 1, approver_email: 'rani@client.test', expires_at: null } },
        }),
      },
    });
    const client = await app.connect(TOKEN_FULL);
    const out = payload(await client.callTool({ name: 'create_approval_link', arguments: { post_ids: [POST, POST], approver_email: 'rani@client.test' } }));
    expect(out.skipped).toBeUndefined();
    expect(app.v1.calls).toHaveLength(1);
    expect((app.v1.calls[0]!.body as { post_ids: string[] }).post_ids).toEqual([POST]);
  });
});

describe('Engage errors (list_conversations / reply_to_conversation)', () => {
  const CONV = 'comment_01JCOMMCOMMCOMMCOMMCOMMCOM';
  const REPLY = `GET /inbox/feed/${CONV}`;
  const engage = (status: number, body: unknown) => fail('reply_to_conversation', { conversation_id: CONV, text: 'Thanks!' }, { [REPLY]: v1(status, body) });

  it('provider_error → relay_unavailable, nothing sent', async () => {
    const e = await engage(502, { code: 'provider_error', message: 'Instagram could not be reached.' });
    expect(e).toMatchObject({ code: 'relay_unavailable', details: { sent: false, retryable: true } });
  });

  it('reply_window_closed → do not retry, with the window', async () => {
    const e = await engage(422, { code: 'reply_window_closed', message: 'Window closed.', can_reply_until: '2026-11-10T00:00:00Z' });
    expect(e).toMatchObject({ code: 'reply_window_closed', details: { retryable: false, can_reply_until: '2026-11-10T00:00:00Z' } });
  });

  it('provider_rejected → reply_rejected', async () => {
    const e = await engage(422, { code: 'provider_rejected', message: 'Instagram refused this reply.', provider_code: 'spam' });
    expect(e).toMatchObject({ code: 'reply_rejected', details: { reason: 'provider_rejected', provider_code: 'spam' } });
  });

  it('permission_missing → reconnect_required', async () => {
    const e = await engage(403, { code: 'permission_missing', message: 'Messages permission missing.' });
    expect(e).toMatchObject({ code: 'reconnect_required', details: { reason: 'permission_missing' } });
  });

  it('already_replied → reply_not_possible', async () => {
    const e = await engage(422, { code: 'already_replied', message: 'Already answered.' });
    expect(e).toMatchObject({ code: 'reply_not_possible', details: { reason: 'already_replied', retryable: false } });
  });

  it('inbox_disabled → forbidden', async () => {
    const e = await engage(403, { code: 'inbox_disabled', message: 'Engage is not enabled.' });
    expect(e).toMatchObject({ code: 'forbidden', details: { reason: 'inbox_disabled' } });
  });

  it('an unknown conversation → not_found pointing back at list_conversations', async () => {
    const e = await fail('reply_to_conversation', { conversation_id: CONV, text: 'Thanks!' });
    expect(e).toMatchObject({ code: 'not_found', message: 'That conversation was not found in this workspace.' });
    expect(e.next_step).toMatch(/list_conversations/);
  });
});

describe('402 next steps per Free limit', () => {
  const plan = (feature: string) =>
    fail('get_post', { post_id: POST }, { [GET_POST]: v1(402, { code: 'plan_required', message: 'That is past the Free plan limit.', feature }) });

  it('schedule_window → a date within 7 days, or upgrade', async () => {
    const e = await plan('schedule_window');
    expect(e).toMatchObject({ code: 'plan_required', message: 'That is past the Free plan limit.', details: { feature: 'schedule_window' } });
    expect(e.next_step).toMatch(/7 days ahead/);
    expect(e.next_step).toMatch(/Do not retry/);
  });

  it('queue_limit → wait for queued posts, or upgrade', async () => {
    expect((await plan('queue_limit')).next_step).toMatch(/10 posts can be queued.*list_posts status "scheduled"/);
  });

  it('approval_clients → same client, or upgrade', async () => {
    expect((await plan('approval_clients')).next_step).toMatch(/one client at a time.*same approver_email/);
  });

  it('any other feature → get_credits, with the Free limits named', async () => {
    expect((await plan('insight')).next_step).toMatch(/get_credits.*7 days ahead, 10 queued posts, approval links for one client/);
  });
});
