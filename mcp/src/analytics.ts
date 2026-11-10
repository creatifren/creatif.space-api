import type { FetchLike } from './auth/introspection.js';

export interface ToolCallEvent {
  tool: string;
  client_name: string;
  ok: boolean;
  ms: number;
  /** Error code when ok is false. Never content. */
  error?: string;
}

export interface Analytics {
  toolCalled(distinctId: string, event: ToolCallEvent): void;
}

export const noopAnalytics: Analytics = { toolCalled: () => {} };

/** PostHog capture, fire-and-forget. Properties are metadata only — no captions, ids or payloads. */
export class PostHogAnalytics implements Analytics {
  constructor(
    private readonly key: string,
    private readonly host: string,
    private readonly fetchImpl: FetchLike = fetch,
  ) {}

  toolCalled(distinctId: string, event: ToolCallEvent): void {
    this.fetchImpl(`${this.host}/i/v0/e/`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        api_key: this.key,
        event: 'mcp_tool_called',
        distinct_id: distinctId,
        properties: { ...event, $lib: 'creatif-mcp' },
        timestamp: new Date().toISOString(),
      }),
      signal: AbortSignal.timeout(3_000),
    }).catch(() => {
      /* analytics never breaks a tool call */
    });
  }
}

export function analyticsFrom(key: string | null, host: string): Analytics {
  return key ? new PostHogAnalytics(key, host) : noopAnalytics;
}
