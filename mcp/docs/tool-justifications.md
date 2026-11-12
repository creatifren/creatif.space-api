# Tool justifications

One line per tool, for directory review forms (Claude Connectors Directory,
ChatGPT Apps). Hints are the tool's MCP annotations; `test/annotations.test.ts`
fails if this file misses a tool or a tool lacks its title or any hint.

Rules: `openWorldHint` is true only for tools that post publicly to a social
network or the public web; `destructiveHint` is true for tools that post
publicly, delete, revoke or record an irreversible decision. Every tool that
is destructive previews first and acts only on `confirm: true`.

| Tool | Title | RO | Destr. | Open | Why |
|---|---|---|---|---|---|
| `get_account_summary` | Get an account summary | yes | no | no | Reads the user's plan, limits, Credits, channels and storage. |
| `get_onboarding` | Get the setup checklist | yes | no | no | Reads which getting-started steps the workspace has done; changes nothing. |
| `list_channels` | List connected channels | yes | no | no | Reads the user's connected social channels (ids needed by every post tool). |
| `get_connect_link` | Get a link to connect a channel | yes | no | no | Returns a creatif.space link; the user connects the network themselves. |
| `get_credits` | Get plan, limits and Credits | yes | no | no | Reads plan limits and Credit balance. |
| `list_posts` | List posts | yes | no | no | Reads the workspace's posts. |
| `list_failed_posts` | List failed posts | yes | no | no | Reads the user's own posts that failed to publish in the last 30 days, with each failure's reason. |
| `get_post` | Get a post | yes | no | no | Reads one post and its review thread. |
| `suggest_caption` | Suggest a caption | yes | no | no | Generates caption text; saves and sends nothing. |
| `draft_post` | Draft a post | no | no | no | Saves a private draft in creatif.space; never sends or spends Credits. |
| `update_post` | Update a post | no | no | no | Edits a draft or a post awaiting approval; nothing is sent. |
| `discard_draft` | Discard a draft | no | yes | no | Deletes a draft for good (preview, then confirm). |
| `schedule_post` | Schedule a draft | no | yes | yes | Queues a post to go public on social networks (preview, then confirm). |
| `schedule_posts` | Schedule several drafts at once | no | yes | yes | Queues up to 10 posts to go public on social networks, all or none (one preview, then confirm). |
| `publish_post` | Publish a draft now | no | yes | yes | Posts publicly on social networks now (preview, then confirm). |
| `retry_post` | Retry a failed post | no | yes | yes | Posts again publicly on the channels that failed (preview, then confirm). |
| `create_approval_link` | Create a client approval link | no | no | no | Creates a private review link for the user's client; posts nothing publicly. |
| `create_content_plan` | Create a Content Plan and email the approver | no | no | no | Creates a private plan and emails the approver the user named; posts nothing publicly. |
| `check_approval_status` | Check approval status | yes | no | no | Reads approval state of a post or link. |
| `send_approval_reminder` | Send an approval reminder | no | no | no | Emails the approver once more about a private link; posts nothing publicly. |
| `list_approval_links` | List approval links | yes | no | no | Reads the workspace's approval links. |
| `get_approval_audit` | Get the approval audit of a link | yes | no | no | Reads the decision record of one link. |
| `get_approval_history` | Get the approval history of a post | yes | no | no | Reads who approved one post and when. |
| `get_plan_calendar_link` | Get a Content Plan calendar feed link | yes | no | no | Returns a private ICS feed link. |
| `list_waiting_approvals` | List posts waiting for your approval | yes | no | no | Reads posts others sent this user to approve. |
| `decide_approval` | Approve or request changes on a post sent to you | no | yes | no | Records an approval decision that cannot be taken back (preview, then confirm). |
| `list_shared_with_me` | List reports shared with you | yes | no | no | Reads plans and reports shared with this user. |
| `request_media_upload` | Request a media upload URL | no | no | no | Returns a private upload URL for the user's own file. |
| `confirm_media_upload` | Confirm a media upload | no | no | no | Adds the uploaded file to the user's private library. |
| `list_files` | List library files | yes | no | no | Reads the user's media library. |
| `get_storage_usage` | Get media storage usage | yes | no | no | Reads storage used and the files that count towards it. |
| `keep_file` | Keep a file | no | no | no | Marks a library file to be kept; deletes nothing. |
| `unkeep_file` | Un-keep a file | no | yes | no | Lets storage clean-up delete the file later. |
| `get_insight_summary` | Get Insight numbers | yes | no | no | Reads the user's own post analytics. |
| `list_top_posts` | List top posts | yes | no | no | Reads the best-performing posts. |
| `get_best_times` | Get best times to post | yes | no | no | Reads when the user's audience engages most. |
| `get_insight_report_link` | Get an Insight PDF link | yes | no | no | Returns a short-lived private link to a PDF report. |
| `get_insight_csv_link` | Get an Insight CSV link | yes | no | no | Returns a short-lived private link to the user's own post numbers as CSV. |
| `create_insight_share_link` | Share an Insight report | no | no | no | Creates an unlisted link for the user to send their client; posts nothing publicly. |
| `revoke_insight_share_link` | Revoke a shared Insight report | no | yes | no | Turns a share link off for good. |
| `list_conversations` | List Engage conversations | yes | no | no | Reads comments and DMs on the user's channels. |
| `reply_to_conversation` | Reply to a comment or DM | no | yes | yes | Posts a reply on a social network (preview, then confirm). |
| `list_spaces` | List Spaces | yes | no | no | Reads the user's Spaces. |
| `create_space_from_files` | Create a draft Space from library files | no | no | no | Creates a private draft Space. |
| `publish_space` | Publish a Space | no | yes | yes | Makes a Space public on the web (preview, then confirm). |
| `get_notification_settings` | Get the user’s email notification settings | yes | no | no | Reads the user's email preferences. |
| `update_notification_settings` | Turn creatif.space emails on or off | no | no | no | Changes the user's own email preferences. |
| `send_feedback` | Send feedback to the creatif.space team | no | no | no | Sends the user's feedback to creatif.space only. |
