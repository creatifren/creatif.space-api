import { z } from 'zod';
import { defineTool, describeTool } from './define.js';
import { pageInput, pageOf } from './paging.js';

/**
 * Clients (v7): labels the user puts on their social channels. One channel
 * can carry several clients (a shared account), and a post belongs to every
 * client on its channels — so there is never a "which client is this post
 * for?" question. Approval links and monthly reports are made per client.
 */

const ulid = (what: string) =>
  z.string().trim().min(10).max(26).describe(`${what} id, as returned by the list tools`);

const READ_ONLY = { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;
const WRITE = { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false } as const;

interface V1Client {
  id: string;
  name: string;
  initials: string | null;
  color: string;
  approver_email: string | null;
  approver_name: string | null;
  report_email: boolean;
  accounts: { id: string; platform: string; username: string | null; shared: boolean }[];
  posts: number;
  waiting: number;
}

const shapeClient = (c: V1Client) => ({
  id: c.id,
  name: c.name,
  initials: c.initials,
  color: c.color,
  approver_email: c.approver_email,
  approver_name: c.approver_name,
  monthly_report_email: c.report_email,
  channels: c.accounts.map((a) => ({ channel_id: a.id, platform: a.platform, username: a.username, ...(a.shared ? { shared_with_other_clients: true } : {}) })),
  posts: c.posts,
  waiting_for_approval: c.waiting,
});

const clientFields = {
  color: z.string().regex(/^#[0-9A-Fa-f]{6}$/).optional().describe('Colour as #RRGGBB, e.g. "#3E6A8A"'),
  initials: z.string().trim().min(1).max(3).optional().describe('1–3 letters shown in the client’s circle; default = first letters of the name'),
  approver_email: z.string().trim().email().max(254).optional().describe('Who approves for this client; approval links for the client go to this address'),
  approver_name: z.string().trim().max(120).optional().describe('The approver’s name, e.g. "Made Arsana, GM"'),
  monthly_report_email: z.boolean().optional().describe('Email the monthly report to the approver (default true)'),
  channel_ids: z.array(ulid('Channel')).max(500).optional().describe('The client’s channels (ids from list_channels); replaces the current set'),
};

const body = (a: { name?: string; color?: string; initials?: string; approver_email?: string; approver_name?: string; monthly_report_email?: boolean; channel_ids?: string[] }) => ({
  ...(a.name !== undefined ? { name: a.name } : {}),
  ...(a.color !== undefined ? { color: a.color } : {}),
  ...(a.initials !== undefined ? { initials: a.initials } : {}),
  ...(a.approver_email !== undefined ? { approver_email: a.approver_email } : {}),
  ...(a.approver_name !== undefined ? { approver_name: a.approver_name } : {}),
  ...(a.monthly_report_email !== undefined ? { report_email: a.monthly_report_email } : {}),
  ...(a.channel_ids !== undefined ? { account_ids: a.channel_ids } : {}),
});

export const listClients = defineTool({
  name: 'list_clients',
  title: 'List clients',
  description: describeTool({
    does: 'Lists the user’s clients: name, colour, approver, their channels (a channel shared by several clients is marked), how many posts and how many wait for approval.',
    when: 'when the user talks about a client by name, before list_posts with client_id, before create_approval_link for a client, or to see which channels belong to which client.',
    example: 'list_clients {} → { count, total, next_cursor: null, clients: [{ id: "01JK…", name: "Hotel Ombak", approver_email: "made@…", channels: [{ channel_id, platform: "instagram", username: "@ombakgroup", shared_with_other_clients: true }], waiting_for_approval: 1 }] }',
    rules: 'Read-only. A post belongs to every client on its channels.',
    errors: [['upstream_unavailable', 'wait a minute and call again']],
  }),
  scope: 'read',
  input: { ...pageInput(200, 50, 'clients') },
  annotations: READ_ONLY,
  run: async (args, { v1 }) => {
    const { data } = await v1.get<{ data: V1Client[] }>('/client-labels');
    const { page, next_cursor, total } = pageOf('list_clients', {}, data, args.limit, args.cursor);
    return { count: page.length, total, clients: page.map(shapeClient), next_cursor };
  },
});

export const createClient = defineTool({
  name: 'create_client',
  title: 'Create a client',
  description: describeTool({
    does: 'Creates a client label, optionally with its approver and channels.',
    when: 'when the user names a new client to work for, e.g. "add Kopi Senja as a client, @kopisenja is theirs, Dewi approves".',
    example: 'create_client { "name": "Kopi Senja", "approver_email": "dewi@kopisenja.id", "channel_ids": ["01JD…"] } → { client: { id, name, channels } }',
    rules: 'Names are unique per user. Channel ids come from list_channels; an unknown id is ignored. Nothing is sent to the client.',
    errors: [
      ['invalid_input', 'fix what details.errors names (e.g. a client with that name already exists: use update_client)'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: { name: z.string().trim().min(1).max(80).describe('The client’s name, e.g. "Hotel Ombak"'), ...clientFields },
  annotations: { ...WRITE, idempotentHint: false },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{ data: V1Client }>('/client-labels', body(args));
    return { client: shapeClient(data) };
  },
});

export const updateClient = defineTool({
  name: 'update_client',
  title: 'Update a client',
  description: describeTool({
    does: 'Renames a client or changes its colour, initials, approver, monthly report email or channels.',
    when: 'when the user changes who approves for a client, which channels are theirs, or turns their monthly report email off.',
    example: 'update_client { "client_id": "01JK…", "approver_email": "gm@ombak.id", "channel_ids": ["01JD…", "01JE…"] } → { client }',
    rules: 'Only the fields given change. channel_ids replaces the client’s whole channel set.',
    errors: [
      ['invalid_input', 'fix what details.errors names'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: { client_id: ulid('Client'), name: z.string().trim().min(1).max(80).optional().describe('New name'), ...clientFields },
  annotations: WRITE,
  notFound: { what: 'That client', next: 'Call list_clients for the user’s client ids, then call again.' },
  run: async (args, { v1 }) => {
    const { client_id, ...rest } = args;
    const { data } = await v1.patch<{ data: V1Client }>(`/client-labels/${encodeURIComponent(client_id)}`, body(rest));
    return { client: shapeClient(data) };
  },
});

export const deleteClient = defineTool({
  name: 'delete_client',
  title: 'Remove a client',
  description: describeTool({
    does: 'Removes a client label; its channels, posts and approval links stay.',
    when: 'only when the user clearly asks to remove a client they no longer work for.',
    example: 'delete_client { "client_id": "01JK…" } → { removed: true }',
    rules: 'Confirm with the user first. It cannot be undone, but nothing else is deleted: the label just comes off the channels.',
    errors: [['scope_missing', 'reconnect and allow "draft"']],
  }),
  scope: 'draft',
  input: { client_id: ulid('Client') },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That client', next: 'It may already be removed: call list_clients to check.' },
  run: async (args, { v1 }) => {
    await v1.delete(`/client-labels/${encodeURIComponent(args.client_id)}`);
    return { removed: true, client_id: args.client_id };
  },
});

export const setChannelClients = defineTool({
  name: 'set_channel_clients',
  title: 'Set the clients of a channel',
  description: describeTool({
    does: 'Sets which clients one channel belongs to; a channel can belong to several clients.',
    when: 'when the user says a channel belongs to a client (or to more than one, e.g. a group account used by two hotels).',
    example: 'set_channel_clients { "channel_id": "01JD…", "client_ids": ["01JK…", "01JM…"] } → { channel_id, clients: [{ id, name }] }',
    rules: 'Replaces the channel’s whole client set; an empty list takes every client off it. Client ids come from list_clients.',
    errors: [
      ['invalid_input', 'a client id is not the user’s: take ids from list_clients'],
      ['scope_missing', 'reconnect and allow "draft"'],
    ],
  }),
  scope: 'draft',
  input: { channel_id: ulid('Channel'), client_ids: z.array(ulid('Client')).max(200).describe('Every client this channel belongs to') },
  annotations: WRITE,
  notFound: { what: 'That channel', next: 'Call list_channels for valid channel ids, then call again.' },
  run: async (args, { v1 }) => {
    const { data } = await v1.put<{ data: { account_id: string; clients: { id: string; name: string }[] } }>(
      `/social-accounts/${encodeURIComponent(args.channel_id)}/client-labels`,
      { client_ids: args.client_ids },
    );
    return { channel_id: data.account_id, clients: data.clients.map((c) => ({ id: c.id, name: c.name })) };
  },
});

export const clientTools = [listClients, createClient, updateClient, deleteClient, setChannelClients];
