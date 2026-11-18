import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { pageInput, pageOf } from './paging.js';
import type { V1Post } from './shape.js';

/*
 * Pinterest boards. A Pin cannot go out without a board: list_pinterest_boards
 * names them, and draft_post / update_post / schedule_post / schedule_posts /
 * publish_post take `pinterest.board_id` for the post's Pinterest channel.
 * The board used last on a channel is remembered by creatif.space, so a Pin
 * that names none goes there (and an account with one board needs nothing).
 */

/** The optional input every post tool shares. */
export const pinterestInput = z
  .object({
    board_id: z.string().trim().min(1).max(64).describe('Board id from list_pinterest_boards for this post’s Pinterest channel'),
  })
  .optional()
  .describe('Pinterest only: the board for the Pin. Optional — without it the channel’s last-used board is used (or its only board).');

/** The board for the Pinterest channel among these, as the API's per-channel options. */
export async function pinterestOptions(ctx: ToolContext, channelIds: string[], boardId: string): Promise<Record<string, { board_id: string }>> {
  const { data } = await ctx.v1.get<{ data: { id: string; platform: string }[] }>('/social-accounts');
  const pins = data.filter((a) => channelIds.includes(a.id) && a.platform === 'pinterest').map((a) => a.id);
  if (pins.length === 0) {
    throw new ToolError('invalid_input', 'pinterest.board_id was given, but none of these channels is a Pinterest channel.', 'Leave out pinterest, or add the Pinterest channel (from list_channels) to the post, and call again.', {
      field: 'pinterest.board_id',
      channel_ids: channelIds,
    });
  }
  return Object.fromEntries(pins.map((id) => [id, { board_id: boardId }]));
}

/** Set the board on an existing draft's Pinterest channel (PATCH options). */
export async function setPinterestBoard(ctx: ToolContext, postId: string, boardId: string): Promise<void> {
  const { data: post } = await ctx.v1.get<{ data: V1Post }>(`/social-posts/${encodeURIComponent(postId)}`);
  const pin = (post.targets ?? []).find((t) => t.account.platform === 'pinterest');
  if (!pin) {
    throw new ToolError('invalid_input', 'pinterest.board_id was given, but this post has no Pinterest channel.', 'Leave out pinterest and call again.', {
      field: 'pinterest.board_id',
      post_id: postId,
    });
  }
  await ctx.v1.patch(`/social-posts/${encodeURIComponent(postId)}`, { options: { [pin.account.id]: { board_id: boardId } } });
}

interface Board {
  id: string;
  name: string;
}

export const listPinterestBoards = defineTool({
  name: 'list_pinterest_boards',
  title: 'List Pinterest boards',
  description: describeTool({
    does: 'Lists the boards of one connected Pinterest channel (id and name), and which board the channel used last (default_board_id).',
    when: 'before sending a Pin when the user has more than one board, or when a send says missing_board: a Pin needs a board. Pass the chosen id as pinterest.board_id to draft_post, update_post, schedule_post, schedule_posts or publish_post. Without one, the channel’s last-used board is used, or its only board.',
    example: 'list_pinterest_boards { "account_id": "01JD…" } → { boards: [{ id: "8732…", name: "Recipes", default: true }], next_cursor: null, default_board_id: "8732…" }',
    rules: 'Read-only. account_id is a Pinterest channel id from list_channels. Paged: pass next_cursor back as cursor; null means no more.',
    errors: [
      ['invalid_input', 'the channel is not a Pinterest channel: take a Pinterest channel id from list_channels'],
      ['upstream_unavailable', 'Pinterest did not answer: wait a minute and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
    ],
  }),
  scope: 'read',
  input: {
    account_id: z.string().trim().min(10).max(26).describe('Pinterest channel id, as returned by list_channels'),
    ...pageInput(100, 50, 'boards'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That channel', next: 'Take a Pinterest channel id from list_channels and call again.' },
  run: async (args, { v1 }) => {
    try {
      const res = await v1.get<{ data: Board[]; meta?: { default_board_id?: string | null } }>(
        `/social-accounts/${encodeURIComponent(args.account_id)}/pinterest-boards`,
      );
      const def = res.meta?.default_board_id ?? null;
      const all = res.data.map((b) => ({ id: b.id, name: b.name, default: b.id === def }));
      const { page: boards, next_cursor } = pageOf('list_pinterest_boards', { account_id: args.account_id }, all, args.limit, args.cursor);
      return {
        boards,
        next_cursor,
        default_board_id: all.some((b) => b.default) ? def : null,
        next_step:
          all.length === 0
            ? 'This Pinterest account has no boards. Tell the user to create a board on Pinterest first.'
            : 'Ask the user which board to use (or keep default_board_id), then pass it as pinterest: { "board_id": "<id>" } to draft_post, update_post, schedule_post, schedule_posts or publish_post.',
      };
    } catch (e) {
      if (e instanceof V1Error && e.status === 422) {
        throw new ToolError('invalid_input', 'Only a Pinterest channel has boards.', 'Take a Pinterest channel id from list_channels and call again.', {
          field: 'account_id',
          account_id: args.account_id,
        });
      }
      throw e;
    }
  },
});

export const pinterestTools = [listPinterestBoards];
