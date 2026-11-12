import type { NotFound } from '../errors.js';

/*
 * Per-tool next steps for the API answers every tool can meet (errors
 * audit, test/errors-table.test.ts). A tool's own `notFound` wins; these
 * cover the tools without one, so no tool falls back to the generic
 * "take it from the matching list tool". STATE_TOOL says, for a 409 (it
 * changed while the call ran), which tool shows where it stands now.
 */

const filters = (what: string, list: string) =>
  ({ what, next: `Call again without channel_ids, or take channel ids from ${list}.` }) satisfies NotFound;

export const NOT_FOUND_FOR: Record<string, NotFound> = {
  list_channels: { what: 'The channel list', next: 'Call list_channels again without cursor to start from the first page.' },
  get_credits: { what: 'This workspace’s Credits', next: 'Tell the user to open creatif.space and check they are in the right workspace, then call again.' },
  get_storage_usage: { what: 'This workspace’s storage', next: 'Tell the user to open creatif.space and check they are in the right workspace, then call again.' },
  get_onboarding: { what: 'The setup checklist', next: 'Tell the user to open creatif.space once to see the Get started checklist, then call again.' },
  get_account_summary: { what: 'This workspace', next: 'Tell the user to open creatif.space and check they are in the right workspace, then call again.' },
  get_notification_settings: { what: 'The notification settings', next: 'Tell the user to open creatif.space → Settings → Notifications once, then call again.' },
  update_notification_settings: { what: 'The notification settings', next: 'Call get_notification_settings to see the current settings, then call again with only those fields.' },
  list_approval_links: { what: 'The approval links', next: 'Call list_approval_links again without status or cursor.' },
  list_waiting_approvals: { what: 'The approvals waiting for you', next: 'Call list_waiting_approvals again without cursor.' },
  list_shared_with_me: { what: 'The items shared with you', next: 'Call list_shared_with_me again without cursor.' },
  list_spaces: { what: 'The Spaces', next: 'Call list_spaces again without status, search or cursor.' },
  list_failed_posts: { what: 'The failed posts', next: 'Call list_failed_posts again without cursor.' },
  get_insight_summary: filters('A channel in channel_ids', 'list_channels'),
  list_top_posts: filters('A channel in channel_ids', 'list_channels'),
  get_best_times: filters('A channel in channel_ids', 'list_channels'),
  get_insight_report_link: filters('A channel in channel_ids', 'list_channels'),
  get_insight_csv_link: filters('A channel in channel_ids', 'list_channels'),
  create_insight_share_link: filters('A channel in channel_ids', 'list_channels'),
  get_connect_link: { what: 'That network', next: 'Call get_connect_link again with one of the networks its input lists.' },
  send_feedback: { what: 'The feedback inbox', next: 'Tell the user the message was not sent; they can send it from creatif.space → Help.' },
  suggest_caption: { what: 'AI captions', next: 'Write the caption yourself; AI captions are not available right now.' },
};

/** For a 409: the one tool that shows the item's current state. */
export const STATE_TOOL: Record<string, string> = {
  // Posts
  list_posts: 'list_posts',
  get_post: 'get_post',
  draft_post: 'list_posts',
  update_post: 'get_post',
  discard_draft: 'get_post',
  schedule_post: 'get_post',
  schedule_posts: 'list_posts',
  publish_post: 'get_post',
  retry_post: 'get_post',
  list_failed_posts: 'list_failed_posts',
  suggest_caption: 'suggest_caption',
  // Approvals
  create_approval_link: 'list_posts',
  check_approval_status: 'check_approval_status',
  send_approval_reminder: 'check_approval_status',
  list_approval_links: 'list_approval_links',
  revoke_approval_link: 'list_approval_links',
  get_approval_audit: 'get_approval_audit',
  get_approval_history: 'get_approval_history',
  get_plan_calendar_link: 'check_approval_status',
  create_content_plan: 'list_posts',
  list_waiting_approvals: 'list_waiting_approvals',
  decide_approval: 'list_waiting_approvals',
  list_shared_with_me: 'list_shared_with_me',
  // Media
  list_files: 'list_files',
  request_media_upload: 'list_files',
  confirm_media_upload: 'list_files',
  keep_file: 'list_files',
  unkeep_file: 'list_files',
  get_storage_usage: 'get_storage_usage',
  // Channels
  list_channels: 'list_channels',
  get_connect_link: 'list_channels',
  // Insight
  get_insight_summary: 'get_insight_summary',
  list_top_posts: 'list_top_posts',
  get_best_times: 'get_best_times',
  get_insight_report_link: 'get_insight_report_link',
  get_insight_csv_link: 'get_insight_csv_link',
  create_insight_share_link: 'get_insight_summary',
  revoke_insight_share_link: 'list_shared_with_me',
  // Spaces
  list_spaces: 'list_spaces',
  create_space_from_files: 'list_spaces',
  publish_space: 'list_spaces',
  // Engage
  list_conversations: 'list_conversations',
  reply_to_conversation: 'list_conversations',
  // Account
  get_credits: 'get_credits',
  get_account_summary: 'get_account_summary',
  get_onboarding: 'get_onboarding',
  get_notification_settings: 'get_notification_settings',
  update_notification_settings: 'get_notification_settings',
  send_feedback: 'send_feedback',
};
