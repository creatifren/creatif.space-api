import { V1Error, V1Unavailable } from './v1.js';

export type ErrorCode =
  | 'reconnect_required'
  | 'scope_missing'
  | 'plan_required'
  | 'not_found'
  | 'invalid_input'
  | 'unauthorized'
  | 'forbidden'
  | 'rate_limited'
  | 'daily_cap_reached'
  | 'insufficient_credits'
  | 'quota_exceeded'
  | 'preview_stale'
  | 'not_editable'
  | 'approval_required'
  | 'upstream_unavailable';

/** An error a tool returns to the model: a machine code plus what to do next. */
export class ToolError extends Error {
  constructor(
    readonly code: ErrorCode,
    message: string,
    readonly hint: string,
    readonly details?: Record<string, unknown>,
  ) {
    super(message);
  }

  toJSON() {
    return {
      error: {
        code: this.code,
        message: this.message,
        hint: this.hint,
        ...(this.details ? { details: this.details } : {}),
      },
    };
  }
}

const PLAN_MESSAGE = /part of (Pro|Max)|upgrade/i;

const pick = (body: Record<string, unknown>, keys: string[]): Record<string, unknown> =>
  Object.fromEntries(keys.filter((k) => body[k] !== undefined).map((k) => [k, body[k]]));

/** V1 answers → specific, actionable tool errors. */
export function toToolError(e: unknown, notFoundWhat = 'That item'): ToolError {
  if (e instanceof ToolError) return e;

  if (e instanceof V1Unavailable) {
    return new ToolError(
      'upstream_unavailable',
      'creatif.space could not be reached.',
      'Try again in a minute. If it keeps failing, check status at creatif.space.',
    );
  }

  if (!(e instanceof V1Error)) {
    return new ToolError('upstream_unavailable', 'Unexpected error.', 'Try again; if it repeats, report it to creatif.space support.');
  }

  const body = e.body ?? {};
  // `code` from V1 controllers, `error` from the API's token fence.
  const code = typeof body.code === 'string' ? body.code : typeof body.error === 'string' ? body.error : undefined;
  const message = typeof body.message === 'string' ? body.message : e.message;

  if (code === 'account_reconnect_required') {
    return new ToolError(
      'reconnect_required',
      message,
      'Ask the user to reconnect these channels in creatif.space → Channels, then retry. Do not retry before that.',
      { accounts: body.accounts },
    );
  }
  // The API writes these messages to be passed on as they stand.
  if (code === 'plan_required' || e.status === 402 || (e.status === 422 && PLAN_MESSAGE.test(message))) {
    return new ToolError(
      'plan_required',
      message,
      'Tell the user this message as it is (it names the plan limit and includes the trial/upgrade link). Do not retry.',
      pick(body, ['reason', 'feature', 'required_plan', 'limit', 'billing_url']),
    );
  }
  if (code === 'insufficient_credits') {
    return new ToolError(
      'insufficient_credits',
      message,
      'Tell the user this message as it is (needed vs available, and the top-up link). Nothing was charged. Retry only after they top up, or send to fewer channels.',
      pick(body, ['needed', 'available', 'monthly_balance', 'topup_balance', 'topup_url', 'resets_at']),
    );
  }
  if (code === 'quota_exceeded') {
    return new ToolError(
      'quota_exceeded',
      message,
      'Tell the user this message as it is: this month’s Credits are used up until resets_at; they can top up (link included) or wait. Nothing was charged. Do not retry before one of those.',
      pick(body, ['needed', 'available', 'resets_at', 'topup_url']),
    );
  }
  if (code === 'daily_send_cap') {
    return new ToolError(
      'daily_cap_reached',
      message,
      'The daily limit for sends from connected apps is reached. Tell the user they can send it from creatif.space, or retry tomorrow (UTC). Do not retry today.',
      { resets_at: body.resets_at, cap: body.cap },
    );
  }
  if (code === 'draft_only' || code === 'not_available') {
    return new ToolError('forbidden', message, 'This action is not open to connected apps; the user can do it in creatif.space.');
  }
  // Older API answers carried no code.
  if (e.status === 422 && /Credits/.test(message) && /top up|balance/i.test(message)) {
    return new ToolError('insufficient_credits', message, 'Tell the user to top up Credits in creatif.space (Settings → Credits) or send to fewer channels.');
  }
  if (code === 'not_editable') {
    return new ToolError('not_editable', message, 'Only drafts and posts waiting for approval can be changed from here. Tell the user to change it in creatif.space.');
  }
  if (code === 'approval_required') {
    return new ToolError(
      'approval_required',
      message,
      'This post needs approval before it can go out. Use create_approval_link / check_approval_status; do not try to send it another way.',
    );
  }
  if (code === 'scope_missing' || code === 'insufficient_scope') {
    return new ToolError('scope_missing', message, 'Reconnect the creatif.space connector and grant the missing permission.');
  }

  switch (e.status) {
    case 401:
      return new ToolError('unauthorized', 'The creatif.space sign-in is no longer valid.', 'Reconnect the creatif.space connector, then retry.');
    case 403:
      return new ToolError('forbidden', message || 'Not allowed.', 'This item belongs to someone else or the user’s role cannot do this.');
    case 404:
      return new ToolError('not_found', `${notFoundWhat} was not found.`, 'Check the id — list it again with the matching list_* tool.');
    case 422:
      return new ToolError('invalid_input', message, 'Fix the fields named in details.errors and call again.', {
        ...(body.errors ? { errors: body.errors } : {}),
      });
    case 429:
      return new ToolError('rate_limited', 'creatif.space is rate limiting these requests.', 'Wait a minute before calling again.');
    default:
      return new ToolError('upstream_unavailable', `creatif.space answered ${e.status}.`, 'Try again in a minute.');
  }
}
