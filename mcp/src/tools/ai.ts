import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool } from './define.js';

/*
 * AI captions over POST /ai/caption (AiCaptionController): a caption and
 * hashtags from a brief, in the user's style profile. A suggestion only —
 * nothing is saved. Behind AI_CAPTION_ENABLED (404 while off); Pro and Max
 * (Free → 402 plan_required, mapped by toToolError).
 */

export const PLATFORMS = ['instagram', 'facebook', 'x', 'tiktok', 'linkedin', 'threads', 'youtube', 'pinterest', 'bluesky'] as const;

const WRITE_IT_YOURSELF = 'write the caption yourself';

export const suggestCaption = defineTool({
  name: 'suggest_caption',
  title: 'Suggest a caption',
  description: describeTool({
    does: 'Writes a caption and hashtags from a short brief, in the user’s own creatif.space style (tone, emoji, hashtag count, language). Saves nothing.',
    when: 'when the user wants creatif.space’s AI to write or rework a caption. Show the result to the user; to save it, pass the caption (with the hashtags if wanted) to draft_post or update_post. If you are writing the caption yourself, you do not need this tool.',
    example: 'suggest_caption { "brief": "Morning coffee promo, 20% off until Friday", "platform": "instagram" } → { caption, hashtags: ["#coffee", …], next_step }',
    rules: 'Not included on every plan, and may not be switched on for every account yet: get_credits features.ai_caption is true only when this tool will answer.',
    errors: [
      ['not_enabled', `AI captions are not switched on yet: ${WRITE_IT_YOURSELF}; do not retry`],
      ['plan_required', 'tell the user it is not available on their plan, then offer to write the caption yourself; do not retry'],
      ['upstream_unavailable', `call once more; if it fails again, ${WRITE_IT_YOURSELF}`],
      ['invalid_input', 'shorten the brief (2000 characters max) or fix the platform'],
      ['forbidden', 'the user’s role (viewer) cannot write captions; tell them'],
      ['scope_missing', 'reconnect with "draft" allowed'],
    ],
  }),
  scope: 'draft',
  input: {
    brief: z.string().trim().min(1).max(2000).describe('What the post is about, in a few sentences (product, offer, mood, call to action). Up to 2000 characters.'),
    platform: z.enum(PLATFORMS).optional().describe('The channel it is for, so length and hashtags fit (optional)'),
  },
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  run: async (args, { v1 }) => {
    let data: { caption: string; hashtags: string[] };
    try {
      ({ data } = await v1.post<{ data: { caption: string; hashtags: string[] } }>('/ai/caption', {
        brief: args.brief,
        ...(args.platform ? { platform: args.platform } : {}),
      }));
    } catch (e) {
      throw aiError(e);
    }
    return {
      caption: data.caption,
      hashtags: data.hashtags,
      ...(args.platform ? { platform: args.platform } : {}),
      next_step:
        'This is a suggestion; nothing was saved. Show it to the user. To keep it, pass the caption (add the hashtags if they want them) to draft_post or update_post.',
    };
  },
});

/** The AI route's own answers; everything else goes through toToolError. */
function aiError(e: unknown): unknown {
  if (!(e instanceof V1Error)) return e;
  if (e.status === 404 || e.status === 503) {
    return new ToolError(
      'not_enabled',
      'AI captions are not switched on for this account yet.',
      `Tell the user creatif.space’s AI captions are not switched on yet, then ${WRITE_IT_YOURSELF}. Do not retry.`,
      { feature: 'ai_caption', retryable: false, ...(e.status === 503 ? { reason: 'not_set_up' } : { reason: 'feature_off' }) },
    );
  }
  if (e.status === 502) {
    return new ToolError(
      'upstream_unavailable',
      typeof e.body?.message === 'string' ? e.body.message : 'The AI did not answer.',
      `Call suggest_caption once more. If it fails again, ${WRITE_IT_YOURSELF}.`,
      { retryable: true, retry_after_seconds: 5, http_status: 502 },
    );
  }
  return e;
}

export const aiTools = [suggestCaption];
