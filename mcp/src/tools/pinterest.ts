import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool, type ToolContext } from './define.js';
import { pageInput, pageOf } from './paging.js';
import type { V1Post } from './shape.js';

/*
 * Pinterest boards (26 Nov 2026). A Pin cannot go out without a board:
 * list_pinterest_boards names them, and draft_post / update_post take
 * `pinterest_board_id` for the post's Pinterest channel. An account with a
 * single board needs none (creatif.space picks it).
 */

/** The optional input draft_post and update_post share. */
export const pinterestBoardInput = z
  .string()
  .trim()
  .min(1)
  .max(64)
  .optional()
  .describe('Pinterest only: the board for the Pin (id from list_pinterest_boards). Needed when the Pinterest channel has more than one board.');

function notPinterest(field: string, details: Record<string, unknown>): ToolError {
  return new ToolError(
    'invalid_input',
    'pinterest_board_id was given, but the post has no Pinterest channel.',
    'Leave out pinterest_board_id, or add the Pinterest channel (from list_channels) to the post, and call again.',
    { field, ...details },
  );
}

/** The board as per-channel options for the Pinterest channels among these (draft_post). */
export async function pinterestOptionsFor(ctx: ToolContext, channelIds: string[], boardId: string): Promise<Record<string, { board_id: string }>> {
  const { data } = await ctx.v1.get<{ data: { id: string; platform: string }[] }>('/social-accounts');
  const pins = data.filter((a) => channelIds.includes(a.id) && a.platform === 'pinterest').map((a) => a.id);
  if (pins.length === 0) throw notPinterest('pinterest_board_id', { channel_ids: channelIds });
  return Object.fromEntries(pins.map((id) => [id, { board_id: boardId }]));
}

/** The board as per-channel options for an existing post's Pinterest channels (update_post). */
export function pinterestOptionsOn(post: V1Post, boardId: string): Record<string, { board_id: string }> {
  const pins = (post.targets ?? []).filter((t) => t.account.platform === 'pinterest').map((t) => t.account.id);
  if (pins.length === 0) throw notPinterest('pinterest_board_id', { post_id: post.id });
  return Object.fromEntries(pins.map((id) => [id, { board_id: boardId }]));
}

interface Board {
  id: string;
  name: string;
}

export const listPinterestBoards = defineTool({
  name: 'list_pinterest_boards',
  title: 'List Pinterest boards',
  description: describeTool({
    does: 'Lists the boards of one connected Pinterest channel (id and name).',
    when: 'before drafting a Pin, or when a send failed with missing_board: a Pin needs a board. Ask the user which board when there is more than one, then pass its id as pinterest_board_id to draft_post (new post) or update_post (existing draft).',
    example: 'list_pinterest_boards { "channel_id": "01JD…" } → { boards: [{ id: "8732…", name: "Recipes" }], next_cursor: null }',
    rules: 'Read-only. channel_id is a Pinterest channel id from list_channels. A channel with one board needs no pinterest_board_id. Paged: pass next_cursor back as cursor; null means no more.',
    errors: [
      ['invalid_input', 'the channel is not a Pinterest channel: take a Pinterest channel id from list_channels'],
      ['upstream_unavailable', 'Pinterest did not answer: wait a minute and call again'],
      ['scope_missing', 'reconnect and allow "read"'],
    ],
  }),
  scope: 'read',
  input: {
    channel_id: z.string().trim().min(10).max(26).describe('Pinterest channel id, as returned by list_channels'),
    ...pageInput(100, 50, 'boards'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That channel', next: 'Take a Pinterest channel id from list_channels and call again.' },
  run: async (args, { v1 }) => {
    let boards: Board[];
    try {
      const res = await v1.get<{ data: Board[] }>(`/social-accounts/${encodeURIComponent(args.channel_id)}/pinterest-boards`);
      boards = res.data;
    } catch (e) {
      if (e instanceof V1Error && e.status === 422) {
        throw new ToolError('invalid_input', 'That channel is not a Pinterest channel.', 'Take a Pinterest channel id from list_channels and call again.', {
          field: 'channel_id',
          channel_id: args.channel_id,
        });
      }
      throw e;
    }
    const { page, next_cursor } = pageOf('list_pinterest_boards', { channel_id: args.channel_id }, boards, args.limit, args.cursor);
    return {
      boards: page.map((b) => ({ id: b.id, name: b.name })),
      next_cursor,
      note:
        boards.length === 0
          ? 'This Pinterest account has no boards yet: the user creates one on Pinterest first.'
          : boards.length === 1
            ? 'One board: Pins on this channel go there without pinterest_board_id.'
            : 'Ask the user which board, then pass its id as pinterest_board_id to draft_post or update_post.',
    };
  },
});

export const pinterestTools = [listPinterestBoards];
