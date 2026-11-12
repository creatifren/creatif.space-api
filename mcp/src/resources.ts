import type { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { ErrorCode, McpError, type ReadResourceResult } from '@modelcontextprotocol/sdk/types.js';
import type { CallerAuth } from './auth/middleware.js';
import { ToolError, toToolError } from './errors.js';
import { listChannels, listPosts } from './tools/m1.js';
import type { V1Client } from './v1.js';

/*
 * MCP resources: read-only views a client can attach as context, scope
 * `read`. Each is the same JSON its tool returns (list_channels, list_posts),
 * so an agent reads one shape either way. Errors are JSON-RPC errors whose
 * data is the usual {code, message, next_step, details} envelope.
 */

export const RESOURCES = [
  {
    name: 'channels',
    uri: 'creatif://channels',
    title: 'Connected channels',
    description: 'The social channels connected to this creatif.space workspace: id, platform, username, status, and whether each needs reconnecting.',
  },
  {
    name: 'schedule-next-7-days',
    uri: 'creatif://schedule/next-7-days',
    title: 'Scheduled posts, next 7 days',
    description: 'Posts scheduled to go out in the next 7 days (up to 100), soonest first, with their channels and approval state.',
  },
] as const;

const DAY = 86_400_000;

function fail(e: unknown, resource: string): never {
  const err = toToolError(e);
  const envelope = { code: err.code, message: err.message, next_step: err.nextStep, details: { resource, ...err.details } };
  throw new McpError(ErrorCode.InvalidRequest, `${err.code}: ${err.message} Next step: ${err.nextStep}`, envelope);
}

export function registerResources(server: McpServer, auth: CallerAuth, v1: V1Client, now: () => number = Date.now): void {
  const read = async (uri: URL, name: string, load: (ctx: { v1: V1Client; auth: CallerAuth }) => Promise<unknown>): Promise<ReadResourceResult> => {
    try {
      if (!auth.scopes.includes('read')) {
        throw new ToolError(
          'scope_missing',
          'Reading this resource needs the "read" permission, which this connection was not granted.',
          'Ask the user to reconnect the creatif.space connector and allow "read".',
          { required: 'read', granted: auth.scopes },
        );
      }
      const data = await load({ v1: v1.forTool(`resource.${name}`, auth.extra.clientName), auth });
      return { contents: [{ uri: uri.href, mimeType: 'application/json', text: JSON.stringify(data, null, 2) }] };
    } catch (e) {
      fail(e, uri.href);
    }
  };

  const [channels, schedule] = RESOURCES;
  server.registerResource(channels.name, channels.uri, { title: channels.title, description: channels.description, mimeType: 'application/json' }, (uri) =>
    read(uri, 'channels', (ctx) => listChannels.run({ limit: 100 }, ctx)),
  );
  server.registerResource(schedule.name, schedule.uri, { title: schedule.title, description: schedule.description, mimeType: 'application/json' }, (uri) =>
    read(uri, 'schedule', async (ctx) => {
      const from = new Date(now());
      const to = new Date(now() + 7 * DAY);
      const out = (await listPosts.run({ status: 'scheduled', from: from.toISOString(), to: to.toISOString(), limit: 100 }, ctx)) as { posts: { scheduled_at: string | null }[] };
      const posts = [...out.posts].sort((a, b) => (a.scheduled_at ?? '').localeCompare(b.scheduled_at ?? ''));
      return { from: from.toISOString(), to: to.toISOString(), count: posts.length, posts };
    }),
  );
}
