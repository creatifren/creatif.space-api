import { z } from 'zod';
import { ToolError } from '../errors.js';

/*
 * One way to page every list_* tool: `limit` + `cursor` in, `next_cursor`
 * out (null when there is no more). The cursor is opaque to the model: it
 * holds the tool, the position and a fingerprint of the filters, so a cursor
 * from another list, or used with different filters, is refused instead of
 * silently skipping or repeating items.
 */

export const pageInput = (max: number, def: number, what: string) => ({
  limit: z.number().int().min(1).max(max).default(def).describe(`At most this many ${what} per page (1–${max}, default ${def})`),
  cursor: z.string().trim().min(1).max(500).optional().describe('next_cursor from the previous page of this same list (same filters); omit for the first page'),
});

interface CursorState {
  /** Position: items skipped (offset) or the API page, depending on the tool. */
  o: number;
  /** Page size the list started with; kept for page-based APIs. */
  n?: number;
}

const fingerprint = (filters: Record<string, unknown>) =>
  JSON.stringify(Object.keys(filters).sort().filter((k) => filters[k] !== undefined).map((k) => [k, filters[k]]));

export function encodeCursor(tool: string, filters: Record<string, unknown>, state: CursorState): string {
  return Buffer.from(JSON.stringify({ t: tool, f: fingerprint(filters), ...state })).toString('base64url');
}

/** The position a cursor points at; none → the first page. */
export function decodeCursor(tool: string, filters: Record<string, unknown>, cursor: string | undefined): CursorState {
  if (!cursor) return { o: 0 };
  let parsed: unknown;
  try {
    parsed = JSON.parse(Buffer.from(cursor, 'base64url').toString('utf8'));
  } catch {
    parsed = null;
  }
  const c = parsed as { t?: unknown; f?: unknown; o?: unknown; n?: unknown } | null;
  if (!c || c.t !== tool || typeof c.o !== 'number' || !Number.isInteger(c.o) || c.o < 0) {
    throw new ToolError('invalid_input', 'That cursor does not belong to this list.', `Call ${tool} again without cursor to start from the first page; pass next_cursor only to the same tool.`, { field: 'cursor', reason: 'bad_cursor' });
  }
  if (c.f !== fingerprint(filters)) {
    throw new ToolError('invalid_input', 'That cursor was made for different filters.', `Call ${tool} with the same filters as the page that gave you the cursor, or start again without cursor.`, { field: 'cursor', reason: 'filters_changed' });
  }
  return { o: c.o, ...(typeof c.n === 'number' ? { n: c.n } : {}) };
}

/** Page a list the API returns whole. */
export function pageOf<T>(tool: string, filters: Record<string, unknown>, items: T[], limit: number, cursor: string | undefined) {
  const { o } = decodeCursor(tool, filters, cursor);
  const page = items.slice(o, o + limit);
  const next = o + limit < items.length ? encodeCursor(tool, filters, { o: o + limit }) : null;
  return { page, next_cursor: next, total: items.length };
}
