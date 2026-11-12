/* Light presentation shaping of V1 resources: drop what a model does not
   need (avatars, internal counters), keep ids and states. No rules here. */

type Obj = Record<string, unknown>;

export interface V1Target {
  account: { id: string; platform: string; username: string | null };
  status: string;
  fail_reason: string | null;
  /** PublishFailure's reason code, when it failed. */
  fail_code?: string | null;
  platform_url: string | null;
}

export interface V1Post {
  id: string;
  caption: string;
  type: string;
  labels: string[];
  media: { url: string; thumbnail_url?: string | null }[] | null;
  scheduled_at: string | null;
  status: string;
  fail_reason: string | null;
  approval: string | null;
  approval_by?: string | null;
  approval_at?: string | null;
  author?: string;
  comments?: Obj[];
  created_at: string;
  targets?: V1Target[];
}

export function shapePost(p: V1Post, withComments = false) {
  return {
    id: p.id,
    status: p.status,
    type: p.type,
    caption: p.caption,
    scheduled_at: p.scheduled_at,
    approval: p.approval,
    ...(p.approval_by ? { approval_by: p.approval_by, approval_at: p.approval_at } : {}),
    fail_reason: p.fail_reason,
    labels: p.labels ?? [],
    media: (p.media ?? []).map((m) => m.url),
    channels: (p.targets ?? []).map((t) => ({
      channel_id: t.account.id,
      platform: t.account.platform,
      username: t.account.username,
      status: t.status,
      fail_reason: t.fail_reason,
      platform_url: t.platform_url,
    })),
    ...(p.author ? { author: p.author } : {}),
    created_at: p.created_at,
    ...(withComments ? { comments: (p.comments ?? []).map(trimComment) } : {}),
  };
}

/**
 * A review-thread comment without the internal comment id (nothing takes
 * it as input); replies the same way. Author, role, text, state and time stay.
 */
function trimComment(c: Obj): Obj {
  const rest = Object.fromEntries(Object.entries(c).filter(([k]) => k !== 'id' && k !== 'replies'));
  return { ...rest, ...(Array.isArray(c.replies) ? { replies: (c.replies as Obj[]).map(trimComment) } : {}) };
}
