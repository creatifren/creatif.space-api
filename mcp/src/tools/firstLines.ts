/*
 * The first line of every tool description: in one sentence of at most 120
 * characters, starting with a verb ("Shows", "Saves", "Schedules"…), WHAT
 * the tool does for the user. Where a ready-made prompt walks through the
 * tool, the line names it (plan_week, send_for_approval, weekly_results, monthly_report), so
 * an agent finds the workflow from the tool. registerTool puts it above the
 * describeTool text; test/firstlines.test.ts holds every line to these rules
 * and in a snapshot. A new tool without a line here fails that test.
 */

export const FIRST_LINES: Record<string, string> = {
  // Start here
  get_account_summary: 'Shows the user\'s plan, limits left, Credits, channels and storage in one call, so you know what they can do.',
  list_channels: 'Lists the user\'s connected social channels and their ids, the first step of the plan_week prompt.',
  get_connect_link: 'Gives the user the creatif.space link to connect or reconnect a social network.',
  get_credits: 'Shows the user\'s plan, limits and Credits, and what each send costs.',

  // Posts
  list_posts: 'Finds the user\'s posts by status, dates or channel; the plan_week and send_for_approval prompts start here.',
  get_post: 'Shows one post in full, with each channel\'s status and the review thread; send_for_approval checks posts with it.',
  suggest_caption: 'Writes a caption and hashtags in the user\'s own style from a short brief, used by the plan_week prompt.',
  draft_post: 'Saves a new post as a draft without sending it or spending Credits, as the plan_week prompt does.',
  list_failed_posts: 'Shows which posts failed to publish in the last 30 days and why, so the user can fix them and use retry_post.',
  update_post: 'Changes a draft or a post waiting for approval: its caption, time, labels or media.',
  discard_draft: 'Deletes one of the user\'s drafts for good, after showing them what will go.',
  schedule_post: 'Schedules a draft to go out later, after the user agrees to its channels and Credit cost.',
  schedule_posts: 'Schedules up to 10 drafts at once, all or none, after the user agrees to one preview of all of them.',
  publish_post: 'Publishes a draft now, after the user agrees to its channels and Credit cost.',
  retry_post: 'Sends a post again on the channels where it failed, after the user agrees to the reasons and Credit cost.',

  // Approvals
  create_approval_link: 'Gets the user\'s client to approve posts or a period, with the link the send_for_approval prompt makes.',
  create_content_plan: 'Emails a period\'s plan to the user\'s client for approval, in the send_for_approval workflow.',
  check_approval_status: 'Shows where client approval stands on a post or a link, as the send_for_approval prompt checks.',
  send_approval_reminder: 'Reminds the user\'s client by email to answer an approval link, a send_for_approval follow-up.',
  list_approval_links: 'Shows every approval link of the user\'s workspace and how far each one got.',
  get_approval_audit: 'Gives the user the record of every decision on one approval link or Content Plan, with a CSV.',
  get_approval_history: 'Shows who approved one post and when, across every link it was on.',
  get_plan_calendar_link: 'Gives the user a calendar feed of a Content Plan to add to their or their client\'s calendar app.',
  list_waiting_approvals: 'Lists the posts other people sent this user to approve that still wait for an answer.',
  decide_approval: 'Approves a post sent to the user, or sends it back with a reason, after showing them the post.',
  list_shared_with_me: 'Lists the plans and reports other people shared with this user to look at.',

  // Media
  list_files: 'Finds files in the user\'s library to attach to a post.',
  request_media_upload: 'Starts adding a new image or video to the user\'s library by giving the upload link (step 1 of 2).',
  confirm_media_upload: 'Finishes adding an uploaded image or video so the user can attach it to a post (step 2 of 2).',
  get_storage_usage: 'Shows how much media storage the user has used and which files will be deleted when.',
  keep_file: 'Keeps one of the user\'s library files from ever being deleted automatically.',
  unkeep_file: 'Lets a library file be deleted automatically again, freeing the user\'s Keep space.',

  // Insight
  get_insight_summary: 'Shows the user\'s numbers for a period, like reach and engagement, as weekly_results and monthly_report start with.',
  get_best_times: 'Tells the user the best weekday and hour to post on each channel, from their own posts.',
  list_top_posts: 'Shows the user\'s five best posts in a period by engagement, with links.',
  get_insight_csv_link: 'Gives the user a 24-hour link to download their post numbers as a spreadsheet.',
  get_insight_report_link: 'Gives the user a download link to their Insight PDF report, a weekly_results alternative to sharing.',
  create_insight_share_link: 'Shares a report with the user\'s client as a link that needs no account, as weekly_results and monthly_report end with.',
  revoke_insight_share_link: 'Turns off a shared Insight report link at once for everyone who has it.',

  // Spaces
  list_spaces: 'Lists the user\'s Spaces, the shareable pages of their library files.',
  create_space_from_files: 'Puts the user\'s library files on a new private draft Space.',
  publish_space: 'Opens a draft Space to anyone with its link, after the user agrees to who can see it.',

  // Engage
  list_conversations: 'Shows the comments and DMs people sent to the user\'s channels, newest first.',
  reply_to_conversation: 'Answers a comment or DM on the platform for the user, after they agree to the reply.',

  // Feedback
  send_feedback: 'Sends the user\'s bug report or idea to the creatif.space team.',
  get_notification_settings: 'Shows which creatif.space emails the user gets.',
  update_notification_settings: 'Turns a creatif.space email on or off for the user, such as failed posts, approvals or the weekly digest.',
};

/** Which prompt each tool's first line must name (tools a prompt walks through). */
export const FIRST_LINE_PROMPTS: Record<string, string[]> = {
  list_channels: ['plan_week'],
  list_posts: ['plan_week', 'send_for_approval'],
  suggest_caption: ['plan_week'],
  draft_post: ['plan_week'],
  get_post: ['send_for_approval'],
  create_approval_link: ['send_for_approval'],
  create_content_plan: ['send_for_approval'],
  check_approval_status: ['send_for_approval'],
  send_approval_reminder: ['send_for_approval'],
  get_insight_summary: ['weekly_results', 'monthly_report'],
  get_insight_report_link: ['weekly_results'],
  create_insight_share_link: ['weekly_results', 'monthly_report'],
};
