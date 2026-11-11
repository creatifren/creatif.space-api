import { describe, expect, it } from 'vitest';
import { CAPTION_NETWORKS, checkCaption, checkOne } from '../src/free/checkCaption.js';
import { codePoints, graphemes, threadsLength, utf16Units, utf8Bytes, xWeighted } from '../src/free/counting.js';

const FAMILY = '👨‍👩‍👧'; // man ZWJ woman ZWJ girl: 5 code points, 8 UTF-16 units, 18 bytes, 1 grapheme
const SMILE = '😀'; // 1 code point, 2 UTF-16 units, 4 bytes
const E_ACUTE = 'é'; // e + combining acute: 2 code points, 1 grapheme, 3 bytes

describe('counters', () => {
  it('count emoji per unit', () => {
    expect(codePoints(SMILE)).toBe(1);
    expect(utf16Units(SMILE)).toBe(2);
    expect(utf8Bytes(SMILE)).toBe(4);
    expect(graphemes(SMILE)).toBe(1);

    expect(codePoints(FAMILY)).toBe(5);
    expect(utf16Units(FAMILY)).toBe(8);
    expect(utf8Bytes(FAMILY)).toBe(18);
    expect(graphemes(FAMILY)).toBe(1);

    expect(codePoints(E_ACUTE)).toBe(2);
    expect(graphemes(E_ACUTE)).toBe(1);
  });

  it('Threads: characters, with each emoji as its UTF-8 bytes', () => {
    expect(threadsLength('abc')).toBe(3);
    expect(threadsLength(`a${SMILE}`)).toBe(5);
    expect(threadsLength(FAMILY)).toBe(18);
    expect(threadsLength('é')).toBe(1);
  });

  it('X: every URL weighs 23 whatever its length', () => {
    expect(xWeighted('hi https://example.com')).toBe(3 + 23);
    expect(xWeighted(`hi https://example.com/${'p'.repeat(200)}`)).toBe(3 + 23);
    expect(xWeighted('a b.co c')).toBe(4 + 23); // bare domains are links too
  });
});

describe('check_caption per network', () => {
  it('X: 280 weighted, URLs at 23', () => {
    const url = `https://example.com/${'x'.repeat(100)}`;
    expect(checkOne(`${'a'.repeat(256)} ${url}`, 'x')).toMatchObject({ length: 280, limit: 280, unit: 'weighted', ok: true });
    expect(checkOne(`${'a'.repeat(257)} ${url}`, 'x')).toMatchObject({ length: 281, ok: false });
    // An emoji weighs 2 on X.
    expect(checkOne(SMILE.repeat(140), 'x')).toMatchObject({ length: 280, ok: true });
    expect(checkOne(SMILE.repeat(141), 'x')).toMatchObject({ length: 282, ok: false });
  });

  it('Bluesky: 300 graphemes AND 3000 bytes', () => {
    expect(checkOne('a'.repeat(300), 'bluesky')).toMatchObject({ length: 300, unit: 'graphemes', ok: true });
    expect(checkOne('a'.repeat(301), 'bluesky')).toMatchObject({ ok: false });
    // 300 combined characters are 300 graphemes even though they are 600 code points.
    expect(checkOne(E_ACUTE.repeat(300), 'bluesky')).toMatchObject({ length: 300, ok: true });
    // 166 family emoji: 166 graphemes but 2988 bytes — fine; 167 is 3006 bytes — over.
    expect(checkOne(FAMILY.repeat(166), 'bluesky')).toMatchObject({ length: 166, ok: true });
    const over = checkOne(FAMILY.repeat(167), 'bluesky');
    expect(over).toMatchObject({ length: 167, ok: false });
    expect(over.notes).toContain('3006 UTF-8 bytes of 3000.');
  });

  it('Instagram: 2200 code points, 30 hashtags, 20 mentions', () => {
    expect(checkOne(FAMILY.repeat(440), 'instagram')).toMatchObject({ length: 2200, ok: true });
    expect(checkOne(FAMILY.repeat(440) + 'a', 'instagram')).toMatchObject({ length: 2201, ok: false });
    const tags = (n: number) => Array.from({ length: n }, (_, i) => `#tag${i}`).join(' ');
    expect(checkOne(tags(30), 'instagram')).toMatchObject({ hashtags: 30, hashtag_limit: 30, ok: true });
    expect(checkOne(tags(31), 'instagram')).toMatchObject({ hashtags: 31, ok: false });
    const ats = (n: number) => Array.from({ length: n }, (_, i) => `@user${i}`).join(' ');
    expect(checkOne(ats(20), 'instagram')).toMatchObject({ mentions: 20, mention_limit: 20, ok: true });
    expect(checkOne(ats(21), 'instagram')).toMatchObject({ mentions: 21, ok: false });
  });

  it('TikTok: 2200 UTF-16 units; hashtag and mention limits not stated', () => {
    expect(checkOne(SMILE.repeat(1100), 'tiktok')).toMatchObject({ length: 2200, unit: 'utf16_units', ok: true });
    const r = checkOne(SMILE.repeat(1101), 'tiktok');
    expect(r).toMatchObject({ length: 2202, ok: false, hashtag_limit: null, mention_limit: null });
    expect(r.notes).toContain('TikTok does not state a hashtag limit in its API docs.');
  });

  it('Threads: 500 with emoji as bytes, 1 topic tag, 5 unique links', () => {
    expect(checkOne(SMILE.repeat(125), 'threads')).toMatchObject({ length: 500, ok: true });
    expect(checkOne(SMILE.repeat(125) + 'a', 'threads')).toMatchObject({ length: 501, ok: false });
    expect(checkOne('#one', 'threads')).toMatchObject({ hashtags: 1, hashtag_limit: 1, ok: true });
    expect(checkOne('#one #two', 'threads')).toMatchObject({ ok: false });
    const links = (n: number) => Array.from({ length: n }, (_, i) => `https://e${i}.com`).join(' ');
    expect(checkOne(`${links(5)} https://e0.com`, 'threads').ok).toBe(true); // repeats are not unique
    expect(checkOne(links(6), 'threads').ok).toBe(false);
  });

  it('YouTube description counts UTF-8 bytes; title counts characters', () => {
    expect(checkOne('é'.repeat(2500), 'youtube_description')).toMatchObject({ length: 5000, unit: 'utf8_bytes', ok: true });
    expect(checkOne('é'.repeat(2501), 'youtube_description')).toMatchObject({ length: 5002, ok: false });
    expect(checkOne('é'.repeat(100), 'youtube_title')).toMatchObject({ length: 100, ok: true });
    expect(checkOne('é'.repeat(101), 'youtube_title').ok).toBe(false);
  });

  it('Pinterest: title 100, description 800', () => {
    expect(checkOne('a'.repeat(100), 'pinterest_title').ok).toBe(true);
    expect(checkOne('a'.repeat(101), 'pinterest_title').ok).toBe(false);
    expect(checkOne('a'.repeat(800), 'pinterest_description').ok).toBe(true);
    expect(checkOne('a'.repeat(801), 'pinterest_description').ok).toBe(false);
  });

  it('LinkedIn and Facebook: length only, ok null, never a guessed limit', () => {
    const li = checkOne('a'.repeat(50_00), 'linkedin');
    expect(li).toMatchObject({ length: 5000, limit: null, ok: null, hashtag_limit: null, mention_limit: null });
    expect(li.notes).toContain('LinkedIn does not publish a limit in its API docs');
    const fb = checkOne('hello', 'facebook');
    expect(fb).toMatchObject({ length: 5, limit: null, ok: null });
    expect(fb.notes).toContain('Facebook does not publish a limit in its API docs');
  });

  it('every result has the full shape, a source and the checked date', () => {
    const { results, summary } = checkCaption({ caption: 'Hello #world @friend https://example.com' });
    expect(results.map((r) => r.network)).toEqual([...CAPTION_NETWORKS]);
    for (const r of results) {
      expect(Object.keys(r).sort()).toEqual(
        ['checked', 'hashtag_limit', 'hashtags', 'length', 'limit', 'mention_limit', 'mentions', 'network', 'notes', 'ok', 'source_url', 'unit'].sort(),
      );
      expect(r.source_url).toMatch(/^https:\/\//);
      expect(r.checked).toBe('2026-11-12');
      expect(r.hashtags).toBe(1);
      expect(r.mentions).toBe(1);
    }
    expect(summary).toBe('Within the published limits on 9 of 11 checked. No published limit for linkedin, facebook; length reported only.');
  });

  it('summary names the networks that are over', () => {
    expect(checkCaption({ caption: 'a'.repeat(301), networks: ['x', 'bluesky', 'linkedin'] }).summary).toBe(
      'Over a published limit on 2 of 3: x, bluesky. No published limit for linkedin; length reported only.',
    );
  });
});
