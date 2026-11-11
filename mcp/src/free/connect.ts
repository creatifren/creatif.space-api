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
interface GuidesFile {
  help_url: string;
  steps: Record<Flow, string[]>;
  common: { requirements: Item[]; oauth_requirements: Item[]; common_errors: Item[]; oauth_common_errors: Item[] };
  networks: Record<ConnectNetwork, { name: string; flow: Flow; requirements: Item[]; common_errors: Item[] }>;
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
    requirements: text([...g.requirements, ...CONNECT_GUIDES.common.requirements, ...(oauth ? CONNECT_GUIDES.common.oauth_requirements : [])]),
    steps: CONNECT_GUIDES.steps[g.flow].map((s) => s.replace(/\{network\}/g, g.name)),
    // The OAuth return's reasons do not apply to Bluesky's handle + app password sign-in.
    common_errors: text([
      ...g.common_errors,
      ...(oauth ? CONNECT_GUIDES.common.oauth_common_errors : []),
      ...CONNECT_GUIDES.common.common_errors,
    ]),
    help_url: CONNECT_GUIDES.help_url,
  };
}
