import { ToolError } from '../errors.js';
import { CONNECT_GUIDES, connectGuide, connectInput, type ConnectNetwork } from '../free/connect.js';
import { defineTool, describeTool } from './define.js';
import { disconnectedDuplicates } from './m1.js';

/*
 * get_connect_link: the creatif.space app page that starts connecting a
 * network. Only that app URL is returned — never the platform's OAuth URL
 * (POST /social-accounts/auth-url signs a state for the caller; it stays in
 * the app). Reads GET /social-accounts (already connected?) and GET /credits
 * (room on the plan) so the model can say what will happen first.
 */

interface Account {
  id: string;
  platform: string;
  username: string | null;
  status: string;
  reconnect_required?: boolean;
}

interface Limits {
  connected_accounts?: { limit: number | null; used: number };
  urls?: { billing?: string };
}

/** {app}/social/connect?network=… — the connect screen, the same one the app's Channels → Connect opens. */
export function connectUrl(network: ConnectNetwork): string {
  const url = new URL(CONNECT_GUIDES.help_url);
  url.search = '';
  url.searchParams.set('network', network);
  return url.toString();
}

const lcFirst = (s: string) => s.charAt(0).toLowerCase() + s.slice(1);
const noStop = (s: string) => s.replace(/[.\s]+$/, '');

/**
 * One line on the account a network needs, from the same data as /free's
 * connect_guide: what the code confirms, then what to check on the connect
 * screen. So the AI can say it before the user clicks.
 */
export function accountNeeded(network: ConnectNetwork): string {
  const g = CONNECT_GUIDES.networks[network];
  const confirmed = g.account_type.filter((a) => a.status === 'confirmed').map((a) => noStop(a.text));
  const check = g.account_type.filter((a) => a.status === 'check in app').map((a) => lcFirst(noStop(a.text)));
  const head = confirmed.length
    ? `For ${g.name} you need ${confirmed.map(lcFirst).join('; ')}.`
    : `For ${g.name} no particular account type is known to be required.`;
  return check.length ? `${head} Check on the connect screen: ${check.join('; ')}.` : head;
}

export const getConnectLink = defineTool({
  name: 'get_connect_link',
  title: 'Get a link to connect a channel',
  description: describeTool({
    does: 'Returns the creatif.space link that starts connecting (or reconnecting) a network, with the steps the user will see. The link holds no token: the user signs in themselves.',
    when: 'when list_channels shows a channel with reconnect_required, or the user wants to add a network. Tell the user account_needed (the account type the network needs) before they click, then send them the url; you cannot connect a channel for them. Then call list_channels to check it shows as connected.',
    example: 'get_connect_link { "network": "instagram" } → { network, url: "https://creatif.space/social/connect?network=instagram", account_needed: "For Instagram you need …", account_type: [...], steps: [...], requirements: [...], existing: [...], room: { used, limit }, next_step }',
    rules: 'Plans limit how many channels can be connected; reconnecting one that is already listed does not need room. existing leaves out a disconnected copy of an account that is also connected, as list_channels does.',
    errors: [
      ['plan_required', 'the plan’s channel limit is reached: the user can disconnect a channel in creatif.space; do not retry'],
      ['invalid_input', 'use one of the networks in the input schema'],
      ['scope_missing', 'reconnect and allow "read"'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: connectInput,
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async ({ network }, { v1 }) => {
    const [accounts, credits] = await Promise.all([
      v1.get<{ data: Account[] }>('/social-accounts'),
      v1.get<{ data: { limits?: Limits } }>('/credits'),
    ]);
    const dupes = disconnectedDuplicates(accounts.data as unknown as Record<string, unknown>[]);
    const existing = accounts.data
      .filter((a) => a.platform === network && !dupes.has(a.id))
      .map((a) => ({
        id: a.id,
        username: a.username,
        status: a.status,
        reconnect_required: a.reconnect_required === true || a.status !== 'connected',
      }));
    const room = credits.data.limits?.connected_accounts;
    const billing = credits.data.limits?.urls?.billing;
    const full = room?.limit != null && room.used >= room.limit;
    const reconnecting = existing.some((a) => a.reconnect_required);

    if (full && !reconnecting) {
      throw new ToolError(
        'plan_required',
        `The plan allows ${room!.limit} connected channel${room!.limit === 1 ? '' : 's'}, and ${room!.used} ${room!.used === 1 ? 'is' : 'are'} connected.`,
        `Tell the user they can disconnect a channel in creatif.space → Channels, or upgrade${billing ? `: ${billing}` : ' in creatif.space → Settings → Subscription'}. Do not retry before that.`,
        { reason: 'plan_limit', feature: 'connected_accounts', limit: room!.limit, used: room!.used, ...(billing ? { billing_url: billing } : {}) },
      );
    }

    const guide = connectGuide({ network });
    const name = CONNECT_GUIDES.networks[network].name;
    return {
      network,
      url: connectUrl(network),
      account_needed: accountNeeded(network),
      account_type: guide.account_type,
      steps: guide.steps,
      requirements: guide.requirements,
      common_errors: guide.common_errors,
      existing,
      ...(room ? { room: { used: room.used, limit: room.limit } } : {}),
      next_step:
        'Before the user clicks, tell them account_needed in your own words. ' +
        (reconnecting
          ? `Send the user this link to reconnect ${name}: they sign in on ${name} again and the channel comes back. `
          : `Send the user this link to connect ${name}. `) +
        'They must open it themselves, signed in to creatif.space. Afterwards call list_channels to check it shows as connected.',
    };
  },
});

export const connectTools = [getConnectLink];
