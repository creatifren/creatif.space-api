import { z } from 'zod';
import { readFreeJson } from './data.js';

export const NICHES = ['general', 'restaurants', 'real_estate', 'clinics', 'ecommerce', 'hotels', 'fitness'] as const;
export type Niche = (typeof NICHES)[number];
export type Tone = 'friendly' | 'formal';

interface Copy {
  subject: string;
  message: string;
  follow_up_message: string;
}
interface NicheCopy {
  checklist: string[];
  friendly: Copy;
  formal: Copy;
}
type TemplatesFile = Record<Niche, NicheCopy | { use: Niche }>;

export const APPROVAL_TEMPLATES = readFreeJson<TemplatesFile>('approval-templates.json');

export const MESSAGE_MAX = 900;

export const approvalInput = {
  client_name: z.string().trim().min(1).max(80).describe('Who approves, e.g. "Maya" or "Bistro Lumen".'),
  niche: z.enum(NICHES).optional().describe('The client\'s business type. Default: general.'),
  posts_count: z.number().int().min(1).max(500).describe('How many posts need approval.'),
  period: z.string().trim().min(1).max(60).describe('What the posts cover, e.g. "next week" or "December".'),
  deadline: z.string().trim().min(1).max(60).describe('When you need the answer, e.g. "Thursday 5pm".'),
  tone: z.enum(['friendly', 'formal']).optional().describe('Default: friendly.'),
};

export function resolveNiche(niche: Niche): NicheCopy {
  let entry = APPROVAL_TEMPLATES[niche];
  for (let hops = 0; 'use' in entry; hops += 1) {
    if (hops > 5) throw new Error(`approval-templates.json: "use" loop at ${niche}`);
    entry = APPROVAL_TEMPLATES[entry.use];
  }
  return entry;
}

const fill = (template: string, vars: Record<string, string>): string =>
  template.replace(/\{(\w+)\}/g, (m, k: string) => vars[k] ?? m);

export function approvalTemplate(args: {
  client_name: string;
  niche?: Niche;
  posts_count: number;
  period: string;
  deadline: string;
  tone?: Tone;
}) {
  const copy = resolveNiche(args.niche ?? 'general');
  const tone = copy[args.tone ?? 'friendly'];
  const vars = {
    client_name: args.client_name,
    posts: args.posts_count === 1 ? '1 post' : `${args.posts_count} posts`,
    period: args.period,
    deadline: args.deadline,
  };
  const message = fill(tone.message, vars);
  return {
    subject: fill(tone.subject, vars),
    message: message.length > MESSAGE_MAX ? `${message.slice(0, MESSAGE_MAX - 1)}…` : message,
    checklist: [...copy.checklist],
    follow_up_message: fill(tone.follow_up_message, vars),
  };
}
