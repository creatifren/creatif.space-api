/*
 * The first line of every tool description: WHEN to use it, at most 120
 * characters, starting "Use …". Where a ready-made prompt walks through the
 * tool, the line names it (plan_week, send_for_approval, monthly_report), so
 * an agent finds the workflow from the tool. registerTool puts it above the
 * describeTool text; test/firstlines.test.ts holds every line to these rules
 * and in a snapshot. A new tool without a line here fails that test.
 */

export const FIRST_LINES: Record<string, string> = {
  // Start here
  get_account_summary: 'Use first with a new user: plan, limits left, Credits, channels and storage in one call.',
  list_channels: 'Use first, before any draft or send: the only source of channel ids. Step 1 of the plan_week prompt.',
  get_connect_link: 'Use when a network must be connected or reconnected: gives the creatif.space link to send the user.',
  get_credits: 'Use when the user asks what they can afford or why a send was refused: plan, limits, Credits, prices.',

  // Posts
  list_posts: 'Use to find post ids or see what is planned; plan_week and send_for_approval prompts start from it.',
  get_post: 'Use for one post’s full detail and review thread; send_for_approval prompt checks each post with it.',
  suggest_caption: 'Use when the user wants a caption written in their style; the plan_week prompt drafts with it.',
  draft_post: 'Use to save any new post safely as a draft (no send, no Credits); the plan_week prompt saves with it.',
  update_post: 'Use to change a draft or a post waiting for approval: caption, time, labels or media.',
  discard_draft: 'Use when the user wants a draft deleted for good; previews first, confirm: true deletes.',
  schedule_post: 'Use when the user wants a draft sent later; previews channels and Credits, confirm: true schedules.',
  schedule_posts: 'Use when the user wants several drafts scheduled together: one preview, then all or none on confirm.',
  publish_post: 'Use when the user wants a draft public now; previews channels and Credits, confirm: true publishes.',
  retry_post: 'Use when a post failed on some channels and the user wants to try again; previews reasons and Credits.',

  // Approvals
  create_approval_link: 'Use to get client approval on posts or a period; the send_for_approval prompt makes the link with it.',
  create_content_plan: 'Use when creatif.space should email a period’s plan to the client; send_for_approval workflow.',
  check_approval_status: 'Use to see where approval stands on a post or link; send_for_approval prompt ends with it.',
  send_approval_reminder: 'Use when the client has not answered: emails the approver once more (send_for_approval follow-up).',
  list_approval_links: 'Use to see every approval link of the workspace and how far each one got.',
  get_approval_audit: 'Use when the user needs the record of decisions on one approval link or Content Plan (CSV too).',
  get_approval_history: 'Use when the user asks who approved one post, and when, across every link it was on.',
  get_plan_calendar_link: 'Use when the user wants a Content Plan in their or their client’s calendar app (ICS feed).',
  list_waiting_approvals: 'Use when OTHER users sent this user posts to approve: what is still waiting on them.',
  decide_approval: 'Use when the user approves or sends back a post sent to them; previews first, confirm: true decides.',
  list_shared_with_me: 'Use when the user asks what others shared with them to look at: plans and reports.',

  // Media
  list_files: 'Use to attach media already in the library: returns file ids for draft_post or update_post.',
  request_media_upload: 'Use to add a NEW image or video you hold the bytes of: step 1, gives the upload URL.',
  confirm_media_upload: 'Use right after PUTting the bytes from request_media_upload: step 2, makes the file ready to attach.',
  get_storage_usage: 'Use before a big upload or when the user asks what will be deleted and when.',
  keep_file: 'Use when a library file must never be deleted automatically.',
  unkeep_file: 'Use to free Keep quota: the file goes back under the automatic-deletion rules.',

  // Insight
  get_insight_summary: 'Use for a period’s numbers (reach, engagement, top posts); the monthly_report prompt starts with it.',
  get_best_times: 'Use when the user asks when to post: best weekday + hour per channel, from their own posts.',
  list_top_posts: 'Use when the user asks which posts did best in a period: the top five by engagement, with links.',
  get_insight_csv_link: 'Use when the user wants the raw post numbers in a spreadsheet: a 24-hour CSV download link.',
  get_insight_report_link: 'Use when the user wants the Insight PDF; a monthly_report alternative to the share link.',
  create_insight_share_link: 'Use to share a report with a client, no account needed; the monthly_report prompt ends with it.',
  revoke_insight_share_link: 'Use when a shared Insight report link must stop working now.',

  // Spaces
  list_spaces: 'Use to find the user’s Spaces (shareable pages of library files) and their ids.',
  create_space_from_files: 'Use to put library files on a new private draft Space.',
  publish_space: 'Use when the user wants a draft Space open to anyone with its link; previews first.',

  // Engage
  list_conversations: 'Use to read comments and DMs on the connected channels, newest first.',
  reply_to_conversation: 'Use to answer a comment or DM on the platform; previews first, confirm: true sends.',

  // Feedback
  send_feedback: 'Use when the user reports a bug or an idea for creatif.space.',
  get_notification_settings: 'Use when the user asks which creatif.space emails they get, or before changing one.',
  update_notification_settings: 'Use when the user wants to stop or start a creatif.space email (failed posts, approvals, weekly digest).',
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
  get_insight_summary: ['monthly_report'],
  get_insight_report_link: ['monthly_report'],
  create_insight_share_link: ['monthly_report'],
};
