import { z } from 'zod';

/*
 * outputSchema for the most-used read tools. A tool listed here returns its
 * result twice: as structuredContent (checked against this schema by the SDK)
 * and, for older clients, as the same JSON in a text block.
 *
 * Loose on purpose: each schema names and types the fields agents rely on,
 * and lets the rest through (z.looseObject), so a new field the API adds
 * never turns a working call into a validation error.
 */

const str = z.string();
const opt = <T extends z.ZodTypeAny>(t: T) => t.nullish();
const loose = <S extends z.ZodRawShape>(shape: S) => z.looseObject(shape);

const channelRef = loose({ channel_id: opt(str), platform: opt(str), username: opt(str), status: opt(str) });
const post = loose({
  id: str,
  status: str,
  caption: opt(str),
  scheduled_at: opt(str),
  approval: opt(str),
  media: opt(z.array(str)),
  channels: opt(z.array(channelRef)),
});

export const OUTPUT_SCHEMAS: Record<string, z.ZodRawShape> = {
  list_channels: {
    next_cursor: z.string().nullable(),
    channels: z.array(loose({ id: str, platform: str, username: opt(str), status: str, reconnect_required: z.boolean() })),
    needs_reconnect: z.array(str).optional(),
    next_step: str.optional(),
  },
  list_posts: { count: z.number(), posts: z.array(post), next_cursor: z.string().nullable() },
  get_post: { post },
  get_credits: {
    plan: opt(loose({ key: opt(str), name: opt(str) })),
    credits: opt(loose({})),
    price_per_send: opt(loose({})),
    monthly_posts: opt(loose({})),
    connected_accounts: opt(loose({})),
    scheduling: opt(loose({})),
    approval_clients: opt(loose({})),
    features: opt(loose({})),
    mcp_daily_sends: opt(loose({})),
    urls: opt(loose({})),
    spend: opt(loose({ since: opt(str), total: opt(z.number()) })),
    renewal: opt(loose({ resets_at: str, days_until: z.number() })),
  },
  list_waiting_approvals: {
    next_cursor: z.string().nullable(),
    waiting: z.array(loose({ link: loose({}), waiting: z.number(), posts: z.array(z.unknown()) })),
    note: str,
  },
  // One post (post_id) or one link (link_id): every field is optional.
  check_approval_status: {
    post_id: opt(str),
    status: opt(str),
    approval: opt(str),
    approval_by: opt(str),
    approval_at: opt(str),
    feedback: opt(z.array(z.unknown())),
    link: opt(loose({})),
    posts: opt(z.array(loose({ id: str, status: str, approval: opt(str) }))),
  },
  get_insight_summary: {
    in_one_line: opt(str),
    period: opt(loose({ from: opt(str), to: opt(str) })),
    compared_with: opt(loose({})),
    totals: opt(loose({})),
    change_pct: opt(loose({})),
    channels: opt(z.array(loose({}))),
    workflow: opt(loose({})),
    notes: opt(z.array(str)),
  },
  list_files: {
    next_cursor: z.string().nullable(),
    total: z.number(),
    files: z.array(loose({ id: str, name: str, mime_type: opt(str), status: opt(str), url: opt(str) })),
  },
  list_spaces: {
    next_cursor: z.string().nullable(),
    count: z.number(),
    spaces: z.array(loose({ id: str, title: opt(str), status: opt(str), url: opt(str) })),
  },
  get_onboarding: {
    steps: z.array(loose({ key: str, label: str, done: z.boolean(), at: str.nullable() })),
    done_count: z.number(),
    all_done: z.boolean(),
    draft_post_id: str.nullable(),
    next: loose({ step: str.nullable(), tool: str.nullable(), suggest: str }),
  },
  get_storage_usage: {
    tier: str,
    quota: loose({ used_bytes: z.number(), quota_bytes: z.number(), percent_used: opt(z.number()) }),
    files: loose({ kept: loose({}), auto_delete: loose({}), no_auto_delete: loose({}), scanned: z.number(), complete: z.boolean() }),
    next_step: str.optional(),
  },
};

/**
 * Read-only tools that answer without an outputSchema, on purpose: free-form
 * or link-shaped answers no client renders as a table. Every other
 * read-only tool must have an entry in OUTPUT_SCHEMAS (test/in-step.test.ts):
 * a new read tool is a decision, not an omission.
 */
export const UNSTRUCTURED = new Set([
  'list_conversations',
  'list_approval_links',
  'list_shared_with_me',
  'list_top_posts',
  'get_best_times',
  'get_insight_report_link',
  'get_insight_csv_link',
  'get_approval_audit',
  'get_approval_history',
  'get_connect_link',
  'get_plan_calendar_link',
  'get_account_summary',
  'get_notification_settings',
  'list_failed_posts',
]);
