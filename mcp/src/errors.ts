import { V1Error, V1Unavailable } from './v1.js';

export type ErrorCode =
  | 'reconnect_required'
  | 'scope_missing'
  | 'plan_required'
  | 'not_found'
  | 'unknown_tool'
  | 'not_enabled'
  | 'invalid_input'
  | 'unauthorized'
  | 'forbidden'
  | 'rate_limited'
  | 'daily_cap_reached'
  | 'insufficient_credits'
  | 'quota_exceeded'
  | 'preview_stale'
  | 'not_editable'
  | 'not_draft'
  | 'not_approvable'
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
  | 'relay_unavailable'
  | 'internal_error';

/**
 * An error a tool returns to the model. Every one carries the same four
 * fields: a machine `code`, a `message` a person can read, `next_step` —
 * the one thing the model should do now — and `details`, the concrete
 * data behind it (never empty: at least the tool that failed).
 */
export class ToolError extends Error {
  readonly details: Record<string, unknown>;

  constructor(
    readonly code: ErrorCode,
    message: string,
    readonly nextStep: string,
    details: Record<string, unknown> = {},
  ) {
    super(message);
    this.details = defined(details);
  }

  /** The envelope the model sees; `tool` always lands in details. */
  toJSON(tool?: string) {
    return {
      error: {
        code: this.code,
        message: this.message,
        next_step: this.nextStep,
        details: { ...(tool ? { tool } : {}), ...this.details },
      },
    };
  }
}

/** Drop undefined values so details never claims a field it does not have. */
const defined = (o: Record<string, unknown>): Record<string, unknown> =>
  Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined));

/** What a 404 means for one tool, and how to get a valid id instead. */
export interface NotFound {
  what: string;
  next: string;
}

const DEFAULT_NOT_FOUND: NotFound = {
  what: 'That item',
  next: 'Check the id: take it from the matching list tool (list_posts, list_channels, list_files) and call again.',
};

const PLAN_MESSAGE = /part of (Pro|Max)|upgrade/i;

const pick = (body: Record<string, unknown>, keys: string[]): Record<string, unknown> =>
  Object.fromEntries(keys.filter((k) => body[k] !== undefined).map((k) => [k, body[k]]));

/** V1 answers → specific, actionable tool errors. */
export function toToolError(e: unknown, notFound: NotFound = DEFAULT_NOT_FOUND): ToolError {
  if (e instanceof ToolError) return e;

  if (e instanceof V1Unavailable) {
    return new ToolError(
      'upstream_unavailable',
      'creatif.space could not be reached, so nothing was done.',
      'Wait about a minute and call again. If it fails three times, tell the user creatif.space is not answering and to try later.',
      { retryable: true, retry_after_seconds: 60 },
    );
  }

  if (!(e instanceof V1Error)) {
    return new ToolError(
      'internal_error',
      'The creatif.space connector hit an unexpected problem handling this call.',
      'Call again once. If the same error comes back, tell the user to do this step in creatif.space and report it to creatif.space support.',
      { retryable: true, retry_after_seconds: 5 },
    );
  }

  const body = e.body ?? {};
  // `code` from V1 controllers, `error` from the API's token fence.
  const code = typeof body.code === 'string' ? body.code : typeof body.error === 'string' ? body.error : undefined;
  const message = typeof body.message === 'string' ? body.message : e.message;

  const media = mediaError(e.status, body, message);
  if (media) return media;

  if (code === 'account_reconnect_required') {
    const accounts = Array.isArray(body.accounts) ? body.accounts : [];
    return new ToolError(
      'reconnect_required',
      message,
      `Ask the user to reconnect ${accounts.length ? 'the channels in details.accounts' : 'the channels list_channels marks reconnect_required'} in creatif.space → Channels, then call again. Do not retry before that.`,
      { accounts, reason: 'channel_disconnected' },
    );
  }
  // The API writes these messages to be passed on as they stand.
  if (code === 'plan_required' || e.status === 402 || (e.status === 422 && PLAN_MESSAGE.test(message))) {
    return new ToolError(
      'plan_required',
      message,
      'Tell the user this message as it is (it names the plan limit and includes the trial/upgrade link). Do not retry; get_credits shows the plan and its limits.',
      { reason: 'plan_limit', ...pick(body, ['reason', 'feature', 'required_plan', 'limit', 'billing_url']) },
    );
  }
  if (code === 'insufficient_credits') {
    return new ToolError(
      'insufficient_credits',
      message,
      'Tell the user this message as it is (needed vs available, and the top-up link). Nothing was charged. Retry only after they top up, or send to fewer channels.',
      { charged: false, ...pick(body, ['needed', 'available', 'monthly_balance', 'topup_balance', 'topup_url', 'resets_at']) },
    );
  }
  if (code === 'quota_exceeded') {
    return new ToolError(
      'quota_exceeded',
      message,
      'Tell the user this message as it is: this month’s Credits are used up until resets_at; they can top up (link included) or wait. Nothing was charged. Do not retry before one of those.',
      { charged: false, ...pick(body, ['needed', 'available', 'resets_at', 'topup_url']) },
    );
  }
  if (code === 'daily_send_cap') {
    return new ToolError(
      'daily_cap_reached',
      message,
      'The daily limit for sends from connected apps is reached. Tell the user they can send it from creatif.space, or retry after details.resets_at. Do not retry today.',
      { cap: body.cap ?? 20, resets_at: body.resets_at },
    );
  }
  if (code === 'draft_only' || code === 'not_available') {
    return new ToolError(
      'forbidden',
      message,
      'This action is not open to connected apps. Tell the user to do it in creatif.space; do not retry.',
      { reason: code },
    );
  }
  // Older API answers carried no code.
  if (e.status === 422 && /Credits/.test(message) && /top up|balance/i.test(message)) {
    return new ToolError(
      'insufficient_credits',
      message,
      'Tell the user to top up Credits in creatif.space (Settings → Credits) or send to fewer channels. Nothing was charged; get_credits shows the balance.',
      { charged: false },
    );
  }
  if (code === 'not_editable') {
    return new ToolError(
      'not_editable',
      message,
      'Only drafts and posts waiting for approval can be changed from here. Tell the user to change it in creatif.space, or draft_post a new version.',
      { reason: 'not_editable' },
    );
  }
  if (code === 'approval_required') {
    return new ToolError(
      'approval_required',
      message,
      'This post needs approval before it can go out. Use create_approval_link (or check_approval_status if a link exists); do not try to send it another way.',
      { reason: 'approval_required' },
    );
  }
  // Engage replies: the relay's answer, made readable by the API.
  if (code === 'provider_error') {
    return new ToolError(
      'relay_unavailable',
      message,
      'Nothing was sent and any Credits were returned. Tell the user the platform could not be reached; call again in a few minutes.',
      { sent: false, retryable: true, retry_after_seconds: 180 },
    );
  }
  if (code === 'reply_window_closed') {
    return new ToolError(
      'reply_window_closed',
      message,
      'Tell the user Instagram only allows a DM reply within 24 hours of the contact’s last message; they can answer in the Instagram app. Do not retry.',
      { reason: 'reply_window_closed', retryable: false, ...pick(body, ['can_reply_until']) },
    );
  }
  if (code === 'provider_rejected' || code === 'conflict') {
    return new ToolError(
      'reply_rejected',
      message,
      'The platform refused this reply. Pass its message on; change the text only if the user wants to, then preview again with reply_to_conversation.',
      { reason: code, sent: false, ...pick(body, ['provider_code']) },
    );
  }
  if (code === 'permission_missing') {
    return new ToolError(
      'reconnect_required',
      message,
      'Ask the user to reconnect this channel in creatif.space → Channels and allow messages, then call again.',
      { reason: 'permission_missing', accounts: Array.isArray(body.accounts) ? body.accounts : [] },
    );
  }
  if (code === 'post_unknown' || code === 'inbox_unsupported' || code === 'already_replied') {
    return new ToolError(
      'reply_not_possible',
      message,
      'This cannot be answered from here; tell the user they can reply on the platform itself. Do not retry.',
      { reason: code, retryable: false },
    );
  }
  if (code === 'inbox_disabled') {
    return new ToolError('forbidden', message, 'Engage is not enabled for this account yet. Tell the user; do not retry.', { reason: 'inbox_disabled' });
  }
  // Approver side (decide_approval): the API's `reason`.
  if (body.reason === 'approver_mismatch') {
    return new ToolError(
      'forbidden',
      message,
      'This approval link was sent to a different email than this account’s (expected_hint shows which). Tell the user; they can decide only on links sent to their own address.',
      pick(body, ['expected_hint']),
    );
  }
  if (body.reason === 'outside_window') {
    return new ToolError(
      'invalid_input',
      message,
      'Tell the user this post can be decided from decidable_from (the sender’s plan allows deciding 7 days ahead). Do not retry before then.',
      pick(body, ['decidable_from']),
    );
  }
  if (code === 'scope_missing' || code === 'insufficient_scope') {
    return new ToolError(
      'scope_missing',
      message,
      'Ask the user to reconnect the creatif.space connector and allow the missing permission, then call again.',
      pick(body, ['required', 'scope']),
    );
  }

  switch (e.status) {
    case 401:
      return new ToolError(
        'unauthorized',
        'The creatif.space sign-in is no longer valid.',
        'Ask the user to reconnect the creatif.space connector (sign in again), then call again.',
        { reason: 'token_rejected' },
      );
    case 403:
      return new ToolError(
        'forbidden',
        message || 'The user’s role in this workspace cannot do this.',
        'Do not retry. Tell the user their role in this workspace (for example viewer) cannot do this; the workspace owner or an editor can.',
        { reason: 'role' },
      );
    case 404:
      return new ToolError('not_found', `${notFound.what} was not found in this workspace.`, notFound.next, { reason: 'not_found' });
    case 409:
      return new ToolError(
        'invalid_input',
        message || 'The item changed state, so this cannot be done now.',
        'Fetch it again (get_post or the matching list tool) to see its current state, then decide whether to call again.',
        { reason: 'conflict' },
      );
    case 422: {
      const errors = body.errors && typeof body.errors === 'object' && Object.keys(body.errors).length > 0 ? body.errors : undefined;
      return errors
        ? new ToolError('invalid_input', message, 'Fix the fields named in details.errors and call again.', { errors })
        : new ToolError(
            'invalid_input',
            message,
            'Tell the user this message as it is; it explains why creatif.space refused. Change what it names before calling again.',
            { reason: 'refused' },
          );
    }
    case 429:
      return new ToolError(
        'rate_limited',
        'creatif.space is rate limiting these requests.',
        'Wait a minute before calling again; do not loop.',
        { retryable: true, retry_after_seconds: 60 },
      );
    default:
      return new ToolError(
        'upstream_unavailable',
        'creatif.space had a problem handling this request.',
        'Wait about a minute and call again. If it keeps failing, tell the user creatif.space is having trouble and to try later.',
        { retryable: true, retry_after_seconds: 60, http_status: e.status },
      );
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
      { accepted: ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'application/pdf', 'image/svg+xml'] },
    );
  }
  if (reason === 'upload_limit') {
    return new ToolError('upload_limit', message, 'Tell the user uploads are paused for today. Do not retry today; they can try again tomorrow.', {
      retryable: false,
      ...pick(body, ['resets_at', 'limit']),
    });
  }
  if (status === 422 && errors.file !== undefined) {
    return new ToolError(
      'upload_incomplete',
      String((errors.file as unknown[])[0] ?? message),
      'PUT the file bytes to upload_url (with the Content-Type header from request_media_upload) and wait for a 200 before calling confirm_media_upload. ' +
        'If expires_at has passed, call request_media_upload again for a new URL.',
      { errors: body.errors },
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
