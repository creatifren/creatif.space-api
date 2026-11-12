import { afterEach, describe, expect, it } from 'vitest';
import { retryAfter } from '../src/v1.js';
import { payload, startApp, USER } from './helpers.js';

/*
 * An API 429 reaches the model the same way from every tool:
 * {code: 'rate_limited', message, next_step, details: {retry_after_seconds}}.
 * The wait comes from the body's retry_after, else the Retry-After header
 * (the token limit sends only the header), else 60 seconds.
 */

const TOKEN = `stub:${USER}:read,draft,approve,publish,approvals:decide:Claude`;
const ID = '01JIDIDIDIDIDIDIDIDIDIDID1';

/** Valid arguments for every tool, so each one reaches the API. A new tool must be added here. */
const ARGS: Record<string, Record<string, unknown>> = {
  list_channels: {},
  list_posts: {},
  get_post: { post_id: ID },
  list_files: {},
  draft_post: { caption: 'Hello', channel_ids: [ID] },
  get_account_summary: {},
  get_credits: {},
  create_approval_link: { approver_email: 'client@example.com', post_ids: [ID] },
  create_content_plan: { approver_email: 'client@example.com', date_from: '2026-12-01', date_to: '2026-12-07' },
  check_approval_status: { post_id: ID },
  send_approval_reminder: { link_id: ID },
  update_post: { post_id: ID, caption: 'New caption' },
  schedule_post: { post_id: ID, scheduled_at: '2099-01-01T09:00:00Z' },
  schedule_posts: { posts: [{ post_id: ID, scheduled_at: '2099-01-01T09:00:00Z' }] },
  publish_post: { post_id: ID },
  retry_post: { post_id: ID },
  request_media_upload: { filename: 'a.jpg', mime: 'image/jpeg', size_bytes: 10 },
  confirm_media_upload: { file_id: ID },
  list_conversations: {},
  reply_to_conversation: { conversation_id: `dm_${ID}`, text: 'Thanks!' },
  suggest_caption: { brief: 'Coffee promo' },
  get_storage_usage: {},
  keep_file: { file_id: ID },
  unkeep_file: { file_id: ID },
  discard_draft: { post_id: ID },
  list_approval_links: {},
  list_waiting_approvals: {},
  decide_approval: { link: ID, post_id: ID, decision: 'approve' },
  list_shared_with_me: {},
  get_insight_summary: { period: { month: '2026-10' } },
  list_top_posts: { period: { month: '2026-10' } },
  get_best_times: {},
  get_insight_csv_link: { from: '2026-10-01', to: '2026-10-31' },
  get_insight_report_link: { from: '2026-10-01', to: '2026-10-31' },
  list_spaces: {},
  create_space_from_files: { title: 'Winter', file_ids: [ID] },
  publish_space: { space_id: ID },
  get_approval_audit: { link_id: ID },
  get_approval_history: { post_id: ID },
  get_plan_calendar_link: { link_id: ID },
  get_connect_link: { network: 'instagram' },
  send_feedback: { kind: 'idea', message: 'More filters please' },
  get_notification_settings: {},
  update_notification_settings: { weekly_digest: false },
  create_insight_share_link: { from: '2026-10-01', to: '2026-10-31' },
  revoke_insight_share_link: { share_id: ID },
};

/** A V1 that answers every request with this 429. */
const all429 = (reply: { body?: unknown; headers?: Record<string, string> }) =>
  new Proxy({}, { get: () => () => ({ status: 429, ...reply }) }) as Record<string, () => { status: number }>;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

describe('API 429 → rate_limited on every tool', () => {
  it('has arguments for every tool on the server', async () => {
    app = await startApp();
    const names = (await (await app.connect(TOKEN)).listTools()).tools.map((t) => t.name).sort();
    expect(Object.keys(ARGS).sort()).toEqual(names);
  });

  it('every tool: code rate_limited, the API message, retry_after_seconds from Retry-After, a next step', async () => {
    app = await startApp({ routes: all429({ body: { message: 'Too many requests with this token. Try again in 17 seconds.', error: 'rate_limited' }, headers: { 'Retry-After': '17' } }) });
    const c = await app.connect(TOKEN);
    for (const [name, args] of Object.entries(ARGS)) {
      const res = await c.callTool({ name, arguments: args });
      expect(res.isError, name).toBe(true);
      const { error } = payload(res);
      expect(error, name).toMatchObject({
        code: 'rate_limited',
        message: 'Too many requests with this token. Try again in 17 seconds.',
        details: { tool: name, retryable: true, retry_after_seconds: 17 },
      });
      expect(error.next_step, name).toMatch(/Wait 17 seconds.*do not loop/);
      expect(app.v1.calls.length, `${name} reached the API`).toBeGreaterThan(0);
    }
  });

  it('the body’s retry_after wins over the header; neither → 60 seconds and a plain message', async () => {
    app = await startApp({ routes: all429({ body: { message: 'Slow down.', retry_after: 5 }, headers: { 'Retry-After': '30' } }) });
    let error = payload(await (await app.connect(TOKEN)).callTool({ name: 'list_posts', arguments: {} })).error;
    expect(error.details.retry_after_seconds).toBe(5);
    await app.close();

    app = await startApp({ routes: all429({}) });
    error = payload(await (await app.connect(TOKEN)).callTool({ name: 'list_posts', arguments: {} })).error;
    expect(error).toMatchObject({ code: 'rate_limited', message: 'creatif.space is rate limiting these requests.', details: { retry_after_seconds: 60 } });
  });

  it('specific 429s keep their own codes: upload_limit and the daily send cap', async () => {
    app = await startApp({ routes: all429({ body: { message: 'Upload limit reached, try again tomorrow', reason: 'upload_limit' } }) });
    expect(payload(await (await app.connect(TOKEN)).callTool({ name: 'request_media_upload', arguments: ARGS.request_media_upload! })).error.code).toBe('upload_limit');
    await app.close();

    app = await startApp({ routes: all429({ body: { message: 'Daily limit reached.', code: 'daily_send_cap', cap: 20, resets_at: '2026-11-13T00:00:00Z' } }) });
    expect(payload(await (await app.connect(TOKEN)).callTool({ name: 'publish_post', arguments: ARGS.publish_post! })).error.code).toBe('daily_cap_reached');
  });
});

describe('Retry-After parsing', () => {
  const now = Date.parse('2026-11-12T10:00:00Z');
  it('reads seconds and HTTP dates, ignores the rest', () => {
    expect(retryAfter('17', now)).toBe(17);
    expect(retryAfter(' 3 ', now)).toBe(3);
    expect(retryAfter('Thu, 12 Nov 2026 10:00:42 GMT', now)).toBe(42);
    expect(retryAfter('0', now)).toBeUndefined();
    expect(retryAfter('Thu, 12 Nov 2026 09:00:00 GMT', now)).toBeUndefined();
    expect(retryAfter('soon', now)).toBeUndefined();
    expect(retryAfter(null, now)).toBeUndefined();
  });
});
