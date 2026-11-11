import twitter from 'twitter-text';

const segmenter = new Intl.Segmenter(undefined, { granularity: 'grapheme' });
const EMOJI = /\p{Extended_Pictographic}|\p{Regional_Indicator}/u;

/** Unicode code points. */
export const codePoints = (s: string): number => [...s].length;

/** UTF-16 code units (what JavaScript's .length counts). */
export const utf16Units = (s: string): number => s.length;

export const utf8Bytes = (s: string): number => Buffer.byteLength(s, 'utf8');

/** User-perceived characters (extended grapheme clusters). */
export const graphemes = (s: string): number => Array.from(segmenter.segment(s)).length;

/** X's weighted length (twitter-text v3 config: each URL counts 23). */
export const xWeighted = (s: string): number => twitter.parseTweet(s).weightedLength;

/** Characters, except that each emoji counts as its UTF-8 byte length (Threads). */
export function threadsLength(s: string): number {
  let n = 0;
  for (const { segment } of segmenter.segment(s)) {
    n += EMOJI.test(segment) ? utf8Bytes(segment) : codePoints(segment);
  }
  return n;
}

export const hashtags = (s: string): string[] => twitter.extractHashtags(s);
export const mentions = (s: string): string[] => twitter.extractMentions(s);
export const links = (s: string): string[] => twitter.extractUrls(s);

export type Unit = 'characters' | 'utf16_units' | 'utf8_bytes' | 'weighted' | 'graphemes' | 'characters_emoji_as_utf8_bytes';

export const COUNTERS: Record<Unit, (s: string) => number> = {
  characters: codePoints,
  utf16_units: utf16Units,
  utf8_bytes: utf8Bytes,
  weighted: xWeighted,
  graphemes,
  characters_emoji_as_utf8_bytes: threadsLength,
};
