import type { FetchLike } from './auth/introspection.js';

/** Which kind of client made the call, from the OAuth client name (or an API key). */
export type ClientSurface = 'claude' | 'chatgpt' | 'cursor' | 'vscode' | 'api_key' | 'other';

export function clientSurface(clientName: string, tokenType: string): ClientSurface {
  if (tokenType === 'api_key') return 'api_key';
  const n = clientName.toLowerCase();
  if (n.includes('claude') || n.includes('anthropic')) return 'claude';
  if (n.includes('chatgpt') || n.includes('openai')) return 'chatgpt';
  if (n.includes('cursor')) return 'cursor';
  if (n.includes('vs code') || n.includes('vscode') || n.includes('visual studio code') || n.includes('copilot')) return 'vscode';
  return 'other';
}

/**
 * mcp_tool_called: metadata only — never arguments, captions, ids or any
 * text the user wrote. `ms` and `error` are the earlier names of
 * duration_ms and code, kept so existing dashboards keep working.
 */
export interface ToolCallEvent {
  tool: string;
  client_surface: ClientSurface;
  client_name: string;
  ok: boolean;
  /** "ok", or the error code the tool answered (scope_missing, rate_limited, …). */
  code: string;
  duration_ms: number;
  ms: number;
  /** Error code when ok is false. Never content. */
  error?: string;
}

export interface Analytics {
  toolCalled(distinctId: string, event: ToolCallEvent): void;
}

/** free_tool_called: which tool, which network. No caption text, no user data. */
export interface FreeToolEvent {
  tool: string;
  network: string | null;
}

export interface FreeAnalytics {
  freeToolCalled(event: FreeToolEvent): void;
}

/** /free has no user: every event shares this id and makes no person profile. */
export const FREE_DISTINCT_ID = 'mcp-free-tools';

export const noopAnalytics: Analytics & FreeAnalytics = { toolCalled: () => {}, freeToolCalled: () => {} };

/** PostHog capture, fire-and-forget. Properties are metadata only — no captions, ids or payloads. */
export class PostHogAnalytics implements Analytics, FreeAnalytics {
  constructor(
    private readonly key: string,
    private readonly host: string,
    private readonly fetchImpl: FetchLike = fetch,
  ) {}

  toolCalled(distinctId: string, event: ToolCallEvent): void {
    this.capture('mcp_tool_called', distinctId, { ...event, $lib: 'creatif-mcp' });
  }

  freeToolCalled(event: FreeToolEvent): void {
    this.capture('free_tool_called', FREE_DISTINCT_ID, {
      tool: event.tool,
      network: event.network,
      $lib: 'creatif-mcp',
      $process_person_profile: false,
    });
  }

  private capture(name: string, distinctId: string, properties: Record<string, unknown>): void {
    this.fetchImpl(`${this.host}/i/v0/e/`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        api_key: this.key,
        event: name,
        distinct_id: distinctId,
        properties,
        timestamp: new Date().toISOString(),
      }),
      signal: AbortSignal.timeout(3_000),
    }).catch(() => {
      /* analytics never breaks a tool call */
    });
  }
}

export function analyticsFrom(key: string | null, host: string): Analytics & FreeAnalytics {
  return key ? new PostHogAnalytics(key, host) : noopAnalytics;
}
