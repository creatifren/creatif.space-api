import { z } from 'zod';
import { COUNTERS, hashtags, links, mentions, utf8Bytes, type Unit } from './counting.js';
import { readFreeJson } from './data.js';

export const CAPTION_NETWORKS = [
  'instagram',
  'tiktok',
  'linkedin',
  'facebook',
  'youtube_title',
  'youtube_description',
  'x',
  'threads',
  'pinterest_title',
  'pinterest_description',
  'bluesky',
] as const;
export type CaptionNetwork = (typeof CAPTION_NETWORKS)[number];

interface Rule {
  limit: number | null;
  unit: Unit;
  hashtag_limit: number | null;
  mention_limit: number | null;
  byte_limit?: number;
  unique_link_limit?: number;
  notes: string[];
  source_url: string;
}

interface RulesFile {
  checked: string;
  networks: Record<CaptionNetwork, Rule>;
}

export const CAPTION_RULES = readFreeJson<RulesFile>('caption-rules.json');

export const checkCaptionInput = {
  caption: z.string().max(20_000).describe('The caption to check, exactly as it will be posted.'),
  networks: z
    .array(z.enum(CAPTION_NETWORKS))
    .min(1)
    .optional()
    .describe('Which limits to check against. Default: all of them.'),
};

export interface NetworkCheck {
  network: CaptionNetwork;
  length: number;
  limit: number | null;
  unit: Unit;
  ok: boolean | null;
  hashtags: number;
  hashtag_limit: number | null;
  mentions: number;
  mention_limit: number | null;
  notes: string[];
  source_url: string;
  checked: string;
}

export function checkOne(caption: string, network: CaptionNetwork): NetworkCheck {
  const rule = CAPTION_RULES.networks[network];
  const length = COUNTERS[rule.unit](caption);
  const tags = hashtags(caption).length;
  const ats = mentions(caption).length;
  const notes = [...rule.notes];
  const failures: boolean[] = [];

  if (rule.limit !== null) {
    failures.push(length > rule.limit);
    if (length > rule.limit) notes.push(`Over by ${length - rule.limit} (${rule.unit.replace(/_/g, ' ')}).`);
  }
  if (rule.hashtag_limit !== null) {
    failures.push(tags > rule.hashtag_limit);
    if (tags > rule.hashtag_limit) notes.push(`${tags} hashtags; the limit is ${rule.hashtag_limit}.`);
  }
  if (rule.mention_limit !== null) {
    failures.push(ats > rule.mention_limit);
    if (ats > rule.mention_limit) notes.push(`${ats} mentions; the limit is ${rule.mention_limit}.`);
  }
  if (rule.byte_limit !== undefined) {
    const bytes = utf8Bytes(caption);
    failures.push(bytes > rule.byte_limit);
    notes.push(`${bytes} UTF-8 bytes of ${rule.byte_limit}.`);
  }
  if (rule.unique_link_limit !== undefined) {
    const unique = new Set(links(caption).map((l) => l.toLowerCase())).size;
    failures.push(unique > rule.unique_link_limit);
    notes.push(`${unique} unique links of ${rule.unique_link_limit}.`);
  }

  return {
    network,
    length,
    limit: rule.limit,
    unit: rule.unit,
    // No stated limit at all: we report the length and do not judge it.
    ok: failures.length === 0 ? null : !failures.includes(true),
    hashtags: tags,
    hashtag_limit: rule.hashtag_limit,
    mentions: ats,
    mention_limit: rule.mention_limit,
    notes,
    source_url: rule.source_url,
    checked: CAPTION_RULES.checked,
  };
}

export function checkCaption(args: { caption: string; networks?: CaptionNetwork[] }) {
  const networks = [...new Set(args.networks ?? CAPTION_NETWORKS)];
  const results = networks.map((n) => checkOne(args.caption, n));
  const over = results.filter((r) => r.ok === false).map((r) => r.network);
  const fine = results.filter((r) => r.ok === true).length;
  const unknown = results.filter((r) => r.ok === null).map((r) => r.network);

  const parts = [
    over.length === 0
      ? `Within the published limits on ${fine} of ${results.length} checked.`
      : `Over a published limit on ${over.length} of ${results.length}: ${over.join(', ')}.`,
  ];
  if (unknown.length > 0) parts.push(`No published limit for ${unknown.join(', ')}; length reported only.`);

  return { summary: parts.join(' '), results };
}
