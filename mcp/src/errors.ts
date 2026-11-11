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
  | 'storage_full'
  | 'file_too_large'
  | 'unsupported_format'
  | 'upload_limit'
  | 'upload_incomplete'
  | 'file_not_ready'
  | 'upstream_unavailable'
  | 'reply_window_closed'
  | 'reply_rejected'
  | 'reply_not_possible'
  | 'relay_unavailable';

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

  const media = mediaError(e.status, body, message);
  if (media) return media;

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
  // Engage replies: the relay's answer, made readable by the API.
  if (code === 'provider_error') {
    return new ToolError(
      'relay_unavailable',
      message,
      'Nothing was sent and any Credits were returned. Tell the user the platform could not be reached; try again in a few minutes.',
    );
  }
  if (code === 'reply_window_closed') {
    return new ToolError(
      'reply_window_closed',
      message,
      'Tell the user Instagram only allows a DM reply within 24 hours of the contact’s last message; they can answer in the Instagram app. Do not retry.',
      pick(body, ['can_reply_until']),
    );
  }
  if (code === 'provider_rejected' || code === 'conflict') {
    return new ToolError('reply_rejected', message, 'The platform refused this reply. Pass its message on; change the text only if the user wants to.', pick(body, ['provider_code']));
  }
  if (code === 'permission_missing') {
    return new ToolError('reconnect_required', message, 'Ask the user to reconnect this channel in creatif.space → Channels to allow messages, then retry.');
  }
  if (code === 'post_unknown' || code === 'inbox_unsupported' || code === 'already_replied') {
    return new ToolError('reply_not_possible', message, 'This cannot be answered from here; the user can reply on the platform itself. Do not retry.');
  }
  if (code === 'inbox_disabled') {
    return new ToolError('forbidden', message, 'Engage is not enabled for this account yet. Tell the user; do not retry.');
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

const str = (v: unknown): string | undefined => (typeof v === 'string' && v !== '' ? v : undefined);

/**
 * Media refusals (MediaRefused: `reason`, plus links) and the upload-related
 * validation answers, each with the one next step that fixes it.
 */
function mediaError(status: number, body: Record<string, unknown>, message: string): ToolError | undefined {
  const reason = str(body.reason);
  const errors = (body.errors && typeof body.errors === 'object' ? body.errors : {}) as Record<string, unknown>;
  const billing = str(body.billing_url);
  const files = str(body.files_url);

  if (reason === 'quota_exceeded' && (body.feature === 'media_quota' || body.feature === 'keep_quota')) {
    const freeUp =
      body.feature === 'media_quota'
        ? `free up space by deleting files in creatif.space → Files${files ? ` (${files})` : ''} and then emptying the Trash (trashed files still count)`
        : `free up Keep space in creatif.space → Files${files ? ` (${files})` : ''}: un-Keep files or delete them, or remove media from drafts and scheduled posts (their media counts too)`;
    const upgrade =
      body.next_step === 'contact_sales'
        ? 'contact creatif.space for more storage'
        : `upgrade to ${body.next_step === 'upgrade_max' ? 'Max' : 'Pro'}${billing ? `: ${billing}` : ' in creatif.space → Settings → Subscription'}`;
    return new ToolError(
      'storage_full',
      message,
      `Nothing was kept. Tell the user this message, and that they can ${freeUp}, or ${upgrade}. Do not retry until they have done one of those.`,
      pick(body, ['feature', 'next_step', 'billing_url', 'files_url']),
    );
  }
  if (reason === 'file_too_large') {
    const cap = typeof body.max_bytes === 'number' ? ` (limit ${body.max_bytes} bytes)` : '';
    return new ToolError(
      'file_too_large',
      message,
      `Nothing was kept. Make the file smaller than the limit${cap} — compress it, lower the resolution or trim the video — then call request_media_upload again with the new size.` +
        (billing ? ` Or the user can upgrade for a bigger limit: ${billing}` : ''),
      pick(body, ['max_bytes', 'next_step', 'billing_url']),
    );
  }
  if (reason === 'unsupported_format' || (status === 422 && errors.mime_type !== undefined)) {
    const text = reason === 'unsupported_format' ? message : String((errors.mime_type as unknown[] | undefined)?.[0] ?? message);
    return new ToolError(
      'unsupported_format',
      text,
      'Convert the file to JPEG, PNG, WebP or GIF (images) or MP4 or MOV (video) — or PDF/SVG for the library — then start again with request_media_upload. ' +
        'The mime you declare must match the real bytes; a refused upload was deleted and its file id cannot be used.',
    );
  }
  if (reason === 'upload_limit') {
    return new ToolError('upload_limit', message, 'Tell the user uploads are paused for today. Do not retry today; they can try again tomorrow.');
  }
  if (status === 422 && errors.file !== undefined) {
    return new ToolError(
      'upload_incomplete',
      String((errors.file as unknown[])[0] ?? message),
      'PUT the file bytes to upload_url (with the Content-Type header from request_media_upload) and wait for a 200 before calling confirm_media_upload. ' +
        'If expires_at has passed, call request_media_upload again for a new URL.',
    );
  }
  if (status === 422 && errors.file_ids !== undefined) {
    return new ToolError(
      'file_not_ready',
      String((errors.file_ids as unknown[])[0] ?? message),
      'Every file id must be a ready file in this workspace. For a file you uploaded with request_media_upload, call confirm_media_upload first and use the id it returns; otherwise take ids from list_files. Nothing was saved.',
      { errors: body.errors },
    );
  }
  return undefined;
}
