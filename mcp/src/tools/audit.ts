import { z } from 'zod';
import { defineTool, describeTool } from './define.js';

/**
 * The owner side of the approval audit: every decision on one of the
 * user's approval links or Content Plans — who, on what post, when, the
 * decision and the approver's comment. The API serves it as CSV
 * (GET /social-approval-links/{id}/audit.csv); this tool returns the rows
 * as objects too, so the model does not have to parse CSV.
 */

/** At most this many rows go back as objects; the CSV is always whole up to MAX_CSV_CHARS. */
const MAX_ROWS = 200;
const MAX_CSV_CHARS = 100_000;

/** RFC 4180: quoted fields, doubled quotes, CRLF or LF. */
export function parseCsv(text: string): string[][] {
  const rows: string[][] = [];
  let row: string[] = [];
  let field = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i]!;
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') {
        field += '"';
        i++;
      } else if (c === '"') {
        quoted = false;
      } else {
        field += c;
      }
    } else if (c === '"') {
      quoted = true;
    } else if (c === ',') {
      row.push(field);
      field = '';
    } else if (c === '\n' || c === '\r') {
      if (c === '\r' && text[i + 1] === '\n') i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = '';
    } else {
      field += c;
    }
  }
  if (field !== '' || row.length > 0) {
    row.push(field);
    rows.push(row);
  }
  return rows;
}

export const getApprovalAudit = defineTool({
  name: 'get_approval_audit',
  title: 'Get the approval audit of a link',
  description: describeTool({
    does: 'Returns the audit trail of one of the user’s approval links or Content Plans: every decision, oldest first — who decided, on which post, when, approved / changes requested / taken back, and the approver’s comment. Also as CSV.',
    when: 'when the user asks who approved what and when, needs proof of a client’s approval, or wants the audit as a CSV/spreadsheet. For the current state of each post on a link, use check_approval_status with link_id instead.',
    example: 'get_approval_audit { "link_id": "01JD…" } → { link_id, decisions: [{ decided_at, approver_name, approver_email, decision, comment, post_id, post_caption, post_scheduled_at, source }], csv }',
    rules: 'Read-only. Only links in the user’s own workspace (create_approval_link or the app made them). decision is approved, changes_requested or retracted; source is web (approval page) or mcp (an AI chat). Times are UTC. Offer the csv to the user as a file when they want a spreadsheet.',
    errors: [
      ['not_found', 'the id is not a link in this workspace: check it with check_approval_status, or ask the user which link'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'read',
  input: {
    link_id: z.string().trim().min(10).max(26).describe('Approval link id (create_approval_link returns it as link.id)'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'An approval link in this workspace', next: 'Check the link id (create_approval_link returned it as link.id) with check_approval_status, or ask the user which link they mean.' },
  run: async (args, { v1 }) => {
    const csv = await v1.getText(`/social-approval-links/${encodeURIComponent(args.link_id)}/audit.csv`);
    const [header = [], ...rows] = parseCsv(csv.replace(/^\uFEFF/, ''));
    const key = (h: string) => h.replace(/_utc$/, '');
    const decisions = rows
      .filter((r) => r.some((cell) => cell !== ''))
      .map((r) => Object.fromEntries(header.map((h, i) => [key(h), r[i] ?? ''])));
    const truncated = decisions.length > MAX_ROWS;
    return {
      link_id: args.link_id,
      count: decisions.length,
      decisions: decisions.slice(0, MAX_ROWS),
      ...(truncated ? { truncated: true } : {}),
      csv: csv.length > MAX_CSV_CHARS ? csv.slice(0, MAX_CSV_CHARS) : csv,
      ...(csv.length > MAX_CSV_CHARS ? { csv_truncated: true } : {}),
      note: decisions.length === 0
        ? 'Nobody has decided on this link yet.'
        : truncated || csv.length > MAX_CSV_CHARS
          ? 'Only part is shown here. The full export is in creatif.space (approval link → audit CSV).'
          : 'Times are UTC. Offer the csv to the user if they want a spreadsheet.',
    };
  },
});

interface V1PostDecision {
  decided_at: string | null;
  decision: 'approved' | 'changes_requested' | 'retracted';
  approver: { name: string | null; email: string | null };
  comment: string | null;
  source: 'web' | 'mcp';
  link: { id: string; title: string | null; kind: 'plan' | 'list' } | null;
}

export const getApprovalHistory = defineTool({
  name: 'get_approval_history',
  title: 'Get the approval history of a post',
  description: describeTool({
    does: 'Returns every approval decision on one post, oldest first, across every approval link or Content Plan it was on: who decided (name, email masked), when, approved / changes requested / taken back, and their comment.',
    when: 'when the user asks who approved a post, when, or what the client said about it. For every decision on one approval link (with full emails, as CSV) use get_approval_audit; for the post’s current approval state only, check_approval_status is shorter.',
    example: 'get_approval_history { "post_id": "01JD…" } → { post_id, approval: "approved", decisions: [{ decided_at, decision: "changes_requested", approver: { name, email: "r***@c***.com" }, comment, source, link }] }',
    rules: 'Read-only. Posts in the user’s own workspace only (ids from list_posts). Times are UTC. source is web (approval page) or mcp (an AI chat). The approver’s email is masked on purpose; do not try to guess it.',
    errors: [
      ['not_found', 'the id is not a post in this workspace: take ids from list_posts'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['unauthorized', 'reconnect creatif.space'],
      ['upstream_unavailable', 'call again in a minute'],
    ],
  }),
  scope: 'read',
  input: {
    post_id: z.string().trim().min(10).max(26).describe('Post id, as list_posts returns it'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'A post in this workspace', next: 'Check the post id with list_posts and call again.' },
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: { post_id: string; approval: string | null; decisions: V1PostDecision[] } }>(
      `/social-posts/${encodeURIComponent(args.post_id)}/approval-audit`,
    );
    return {
      post_id: data.post_id,
      approval: data.approval,
      count: data.decisions.length,
      decisions: data.decisions,
      note: data.decisions.length === 0
        ? 'Nobody has decided on this post yet.'
        : 'Oldest first; times are UTC. The approver email is masked.',
    };
  },
});

export const auditTools = [getApprovalAudit, getApprovalHistory];
