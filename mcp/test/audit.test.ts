import { afterEach, describe, expect, it } from 'vitest';
import { parseCsv } from '../src/tools/audit.js';
import { payload, startApp, TOKEN_FULL, USER } from './helpers.js';

const LINK = '01JLINKLINKLINKLINKLINKLI1';
const ROUTE = `GET /social-approval-links/${LINK}/audit.csv`;
const HEADER = 'decided_at_utc,approver_name,approver_email,decision,comment,post_id,post_caption,post_scheduled_at_utc,source';
const TOKEN_NOREAD = `stub:${USER}:draft:Claude`;

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

const start = async (routes: Record<string, unknown>, token = TOKEN_FULL) => {
  app = await startApp({ routes: routes as never });
  return app.connect(token);
};

describe('parseCsv', () => {
  it('reads quoted fields, doubled quotes, commas and newlines inside quotes, CRLF', () => {
    expect(parseCsv('a,b\r\n"x, ""y""","line1\nline2"\n')).toEqual([['a', 'b'], ['x, "y"', 'line1\nline2']]);
    expect(parseCsv('a,,c')).toEqual([['a', '', 'c']]);
  });
});

describe('get_approval_audit', () => {
  it('returns the decisions as rows and the CSV as it came', async () => {
    const csv = `${HEADER}\n2026-11-20T03:00:00Z,Rani,rani@client.test,changes_requested,"Swap the photo, please",01JPOST,Launch day,2026-12-05T03:00:00Z,mcp\n2026-11-20T04:00:00Z,Rani,rani@client.test,approved,,01JPOST,Launch day,2026-12-05T03:00:00Z,web\n`;
    const client = await start({ [ROUTE]: () => ({ text: csv, contentType: 'text/csv; charset=UTF-8' }) });
    const out = payload(await client.callTool({ name: 'get_approval_audit', arguments: { link_id: LINK } }));
    expect(out.count).toBe(2);
    expect(out.decisions[0]).toEqual({
      decided_at: '2026-11-20T03:00:00Z', approver_name: 'Rani', approver_email: 'rani@client.test',
      decision: 'changes_requested', comment: 'Swap the photo, please', post_id: '01JPOST',
      post_caption: 'Launch day', post_scheduled_at: '2026-12-05T03:00:00Z', source: 'mcp',
    });
    expect(out.decisions[1]).toMatchObject({ decision: 'approved', comment: '', source: 'web' });
    expect(out.csv).toBe(csv);
    expect(app!.v1.calls[0]).toMatchObject({ method: 'GET', path: `/social-approval-links/${LINK}/audit.csv` });
  });

  it('says so when nobody has decided yet', async () => {
    const client = await start({ [ROUTE]: () => ({ text: `${HEADER}\n` }) });
    const out = payload(await client.callTool({ name: 'get_approval_audit', arguments: { link_id: LINK } }));
    expect(out).toMatchObject({ count: 0, decisions: [] });
    expect(out.note).toMatch(/Nobody has decided/);
  });

  it('another workspace’s link (404) is not_found with a next step', async () => {
    const client = await start({});
    const err = payload(await client.callTool({ name: 'get_approval_audit', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'not_found', message: expect.any(String), next_step: expect.stringMatching(/check_approval_status/) });
  });

  it('needs read', async () => {
    const client = await start({}, TOKEN_NOREAD);
    const err = payload(await client.callTool({ name: 'get_approval_audit', arguments: { link_id: LINK } })).error;
    expect(err).toMatchObject({ code: 'scope_missing', details: { required: 'read' } });
    expect(app!.v1.calls).toHaveLength(0);
  });

  it('caps the rows it returns as objects', async () => {
    const rows = Array.from({ length: 205 }, (_, i) => `2026-11-20T03:00:00Z,Rani,rani@client.test,approved,,01JPOST${i},c,,web`).join('\n');
    const client = await start({ [ROUTE]: () => ({ text: `${HEADER}\n${rows}\n` }) });
    const out = payload(await client.callTool({ name: 'get_approval_audit', arguments: { link_id: LINK } }));
    expect(out).toMatchObject({ count: 205, truncated: true });
    expect(out.decisions).toHaveLength(200);
  });
});
