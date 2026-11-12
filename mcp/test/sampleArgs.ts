/* Minimal valid arguments for any served tool, from its own inputSchema (shared by the all-tools tables). */

export const ULID = '01JD2ZK4M0Q5X8Y9R7T6V5W4X3';

export type Schema = {
  type?: string | string[];
  properties?: Record<string, Schema>;
  required?: string[];
  items?: Schema;
  enum?: unknown[];
  const?: unknown;
  anyOf?: Schema[];
  oneOf?: Schema[];
  format?: string;
  pattern?: string;
  minimum?: number;
  minLength?: number;
  maxLength?: number;
  minItems?: number;
  default?: unknown;
};

/** The smallest value that satisfies a JSON schema (as the SDK publishes zod schemas). */
export function sample(s: Schema, key = ''): unknown {
  if (s.const !== undefined) return s.const;
  if (s.enum) return s.enum[0];
  const alt = s.anyOf ?? s.oneOf;
  if (alt) return sample(alt[0]!, key);
  const type = Array.isArray(s.type) ? s.type.find((t) => t !== 'null') : s.type;
  switch (type) {
    case 'object': {
      const out: Record<string, unknown> = {};
      for (const k of s.required ?? []) out[k] = sample(s.properties![k]!, k);
      return out;
    }
    case 'array':
      return Array.from({ length: Math.max(1, s.minItems ?? 1) }, () => sample(s.items ?? { type: 'string' }, key));
    case 'integer':
    case 'number':
      return s.minimum ?? 1;
    case 'boolean':
      return false;
    default:
      if (s.format === 'email') return 'client@example.test';
      if (s.format === 'uri') return 'https://example.test/a.png';
      if (s.format === 'date-time') return '2030-01-04T10:00:00Z';
      if (s.pattern?.includes('\\d{4}-\\d{2}-\\d{2}') || /(^|_)(day|from|to|date)$/.test(key)) return '2026-10-01';
      if (s.pattern?.includes('\\d{4}-\\d{2}')) return '2026-10';
      if ((s.maxLength ?? 0) >= 26 && (s.minLength ?? 0) >= 10) return ULID;
      if (/(^|_)id$/.test(key) || key === 'link' || key.endsWith('_ids')) return ULID;
      return 'test text';
  }
}

/** Tools whose rules the schema cannot express ("one of x or y", a past month): minimal valid arguments by hand. */
export const ARGS: Record<string, Record<string, unknown>> = {
  create_approval_link: { post_ids: [ULID], approver_email: 'client@example.test' },
  check_approval_status: { post_id: ULID },
  update_post: { post_id: ULID, caption: 'New caption' },
  reply_to_conversation: { conversation_id: `comment_${ULID}`, text: 'Thank you!' },
  get_insight_summary: { period: { month: '2026-10' } },
  list_top_posts: { period: { month: '2026-10' } },
  update_notification_settings: { weekly_digest: false },
};

/** Arguments for one tool: the hand-written ones, else sampled from its schema. */
export const argsFor = (t: { name: string; inputSchema: unknown }): Record<string, unknown> =>
  ARGS[t.name] ?? (sample(t.inputSchema as Schema) as Record<string, unknown>);
