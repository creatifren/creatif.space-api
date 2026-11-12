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

/** Approval states that hold a post: it has not gone to the networks. */
const HELD = new Set(['client', 'awaiting', 'revision']);

/**
 * The status an agent reads. The API keeps a post on an approval link as
 * "scheduled" (with no time when it was a draft); shown as that, an agent
 * tells the user it is scheduled and discard_draft looks wrong. So a held
 * post reads "waiting_for_approval", or "changes_requested" when the client
 * sent it back. list_posts status "scheduled" still includes them.
 */
export function displayStatus(p: Pick<V1Post, 'status' | 'approval'>): string {
  if (p.status !== 'scheduled' || !p.approval || !HELD.has(p.approval)) return p.status;
  return p.approval === 'revision' ? 'changes_requested' : 'waiting_for_approval';
}

export function shapePost(p: V1Post, withComments = false) {
  const held = displayStatus(p) !== p.status;
  return {
    id: p.id,
    status: displayStatus(p),
    type: p.type,
    caption: p.caption,
    scheduled_at: p.scheduled_at,
    // Approving a post with no time is refused (it would publish at once).
    ...(held && !p.scheduled_at ? { needs_time: 'No publish time yet: the client cannot approve it until one is set with update_post scheduled_at.' } : {}),
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
