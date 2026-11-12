import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

const day = (what: string) => z.iso.date().describe(`${what}, YYYY-MM-DD (whole days in the app's timezone)`);

interface V1ShareLink {
  id: string;
  url?: string;
  period: { from: string; to: string; label: string };
  channel_ids: string[];
  recipient_email?: string | null;
  status: string;
  expires_at: string;
}

export const createInsightShareLink = defineTool({
  name: 'create_insight_share_link',
  title: 'Share an Insight report',
  description: describeTool({
    does: 'Creates a read-only web link to an Insight report (the numbers for a date range of up to 92 days) that anyone with the link can open without an account, for 30 days.',
    when: 'when the user wants to send a client their report as a page rather than a file (for a PDF use get_insight_report_link; for the numbers in chat use get_insight_summary). The page shows only the report — no workspace, plan or ids. Keep the returned id: revoke_insight_share_link takes it.',
    example: 'create_insight_share_link { "from": "2026-10-01", "to": "2026-10-31", "recipient_email": "client@example.com" } → { id: "01JD…", url: "https://app.creatif.space/report/…", expires_at, period: { label: "1 Oct – 31 Oct 2026" }, recipient_email }',
    rules: 'recipient_email is optional. With it, that person gets one email with the link, and the report appears under "Reports shared with you" when they sign in with that email (list_shared_with_me). Without it, the link is shared only by whoever you give it to.',
    errors: [
      ['plan_required', 'Insight is not included on this plan: tell the user it is not available on their plan; do not retry'],
      ['invalid_input', 'fix the dates (at most 92 days, not in the future) or the channel ids and call again'],
      ['scope_missing', 'reconnect and allow "approve"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'approve',
  input: {
    from: day('First day'),
    to: day('Last day'),
    channel_ids: z.array(z.string().trim().min(10).max(26)).min(1).max(50).optional().describe('Only these channels (ids from list_channels); default all connected channels'),
    recipient_email: z.string().trim().email().max(254).optional().describe('Optional: who it is for. They get one email with the link and see it under "Reports shared with you".'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  run: async ({ from, to, channel_ids, recipient_email }, { v1 }) => {
    const { data } = await v1.post<{ data: V1ShareLink }>('/insight/share', {
      from,
      to,
      ...(channel_ids ? { channels: channel_ids.join(',') } : {}),
      ...(recipient_email ? { recipient_email } : {}),
    });
    return {
      id: data.id,
      url: data.url,
      expires_at: data.expires_at,
      period: data.period,
      channel_ids: data.channel_ids,
      ...(data.recipient_email ? { recipient_email: data.recipient_email } : {}),
      note: data.recipient_email
        ? `Emailed to ${data.recipient_email}; it also shows under their "Reports shared with you". Anyone with the link can see the report until expires_at. Revoke it with revoke_insight_share_link and this id.`
        : 'Anyone with this link can see the report until expires_at. Give it to the user to share; revoke it with revoke_insight_share_link and this id.',
    };
  },
});

export const revokeInsightShareLink = defineTool({
  name: 'revoke_insight_share_link',
  title: 'Revoke a shared Insight report',
  description: describeTool({
    does: 'Turns off a shared Insight report link at once: the page stops answering for everyone who has the link.',
    when: 'when the user asks to stop sharing a report, or shared the wrong period or channels (make a new link after). Needs the id create_insight_share_link returned. Revoking twice is harmless.',
    example: 'revoke_insight_share_link { "share_id": "01JD…" } → { revoked: true, id: "01JD…" }',
    errors: [
      ['not_found', 'the id is not one of this workspace’s share links: use the id create_insight_share_link returned'],
      ['scope_missing', 'reconnect and allow "approve"'],
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'approve',
  input: {
    share_id: z.string().trim().min(10).max(26).describe('The id create_insight_share_link returned'),
  },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That share link', next: 'Use the id create_insight_share_link returned for this workspace.' },
  run: async ({ share_id }, { v1 }) => {
    await v1.delete(`/insight/share/${encodeURIComponent(share_id)}`);
    return { revoked: true, id: share_id };
  },
});

export const insightShareTools = [createInsightShareLink, revokeInsightShareLink];
