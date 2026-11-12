import { z } from 'zod';
import { readFreeJson } from './data.js';

/** The 9 networks the API connects (App\Enums\SocialPlatform, validated in SocialAccountController::authUrl). */
export const CONNECT_NETWORKS = ['instagram', 'facebook', 'x', 'tiktok', 'linkedin', 'threads', 'youtube', 'pinterest', 'bluesky'] as const;
export type ConnectNetwork = (typeof CONNECT_NETWORKS)[number];

type Flow = 'oauth' | 'page_picker' | 'bluesky';
interface Item {
  text: string;
  /** file:line in the API repo, for copy checks. Never sent to callers. */
  evidence: string[];
}
/** What account the network needs. "confirmed" items cite the code; "check in app" ones are not proven by it. */
type AccountItem = { text: string; status: 'confirmed'; evidence: string[] } | { text: string; status: 'check in app' };
interface GuidesFile {
  help_url: string;
  steps: Record<Flow, string[]>;
  common: { requirements: Item[]; oauth_requirements: Item[]; common_errors: Item[]; oauth_common_errors: Item[] };
  networks: Record<ConnectNetwork, { name: string; flow: Flow; account_type: AccountItem[]; requirements: Item[]; common_errors: Item[]; notes: Item[] }>;
}

export const CONNECT_GUIDES = readFreeJson<GuidesFile>('connect-guides.json');

export const connectInput = {
  network: z.enum(CONNECT_NETWORKS).describe('The network to connect.'),
};

export function connectGuide({ network }: { network: ConnectNetwork }) {
  const g = CONNECT_GUIDES.networks[network];
  const text = (items: Item[]) => items.map((i) => i.text.replace(/\{network\}/g, g.name));
  const oauth = g.flow !== 'bluesky';
  return {
    network,
    /** The account you need. status "check in app": the code does not prove it, so check on the connect screen. */
    account_type: g.account_type.map(({ text, status }) => ({ text, status })),
    requirements: text([...g.requirements, ...CONNECT_GUIDES.common.requirements, ...(oauth ? CONNECT_GUIDES.common.oauth_requirements : [])]),
    steps: CONNECT_GUIDES.steps[g.flow].map((s) => s.replace(/\{network\}/g, g.name)),
    // The OAuth return's reasons do not apply to Bluesky's handle + app password sign-in.
    common_errors: text([
      ...g.common_errors,
      ...(oauth ? CONNECT_GUIDES.common.oauth_common_errors : []),
      ...CONNECT_GUIDES.common.common_errors,
    ]),
    /** Good to know, not a connect error (e.g. a network that apps cannot delete posts from). */
    notes: text(g.notes),
    help_url: CONNECT_GUIDES.help_url,
  };
}
