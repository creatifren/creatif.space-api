import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool } from './define.js';
import { shapeFile, type V1File } from './media.js';

/*
 * Media housekeeping over the routes the Files screen uses — every quota and
 * retention rule stays in Laravel (MediaQuota, MediaRetention):
 * get_storage_usage → GET /files/usage + GET /files (is_kept, auto_delete_at),
 * keep_file → POST /files/{id}/keep, unkeep_file → DELETE /files/{id}/keep.
 */

type Usage =
  | { tier: 'free'; used_bytes: number; quota_bytes: number }
  | { tier: string; keep_used_bytes: number; keep_quota_bytes: number; library_used_bytes?: number };

interface ListedFile {
  id: string;
  name: string;
  size_bytes: number;
  status: string;
  is_kept?: boolean;
  auto_delete_at?: string | null;
}

/** At most this many pages of 60 are read for the kept / auto-delete split. */
export const MAX_PAGES = 5;
const PER_PAGE = 60;

const fileId = z.string().trim().min(10).max(26).describe('The file id, from list_files or confirm_media_upload');

const pct = (used: number, quota: number) => (quota > 0 ? Math.round((used / quota) * 1000) / 10 : null);

export const getStorageUsage = defineTool({
  name: 'get_storage_usage',
  title: 'Get media storage usage',
  description: describeTool({
    does: 'Shows how much of the plan’s media storage is used against its quota, and which library files are kept versus due for automatic deletion (with the soonest dates).',
    when: 'before a big upload, when an upload failed with storage_full, or when the user asks what will be deleted and when. To keep a file from being deleted use keep_file; to list or search files use list_files.',
    example: 'get_storage_usage {} → { tier, quota: { used_bytes, quota_bytes, percent_used, counts, library_used_bytes }, files: { kept: { count, bytes }, auto_delete: { count, bytes, next_at, soonest: [...] }, no_auto_delete: {...}, complete } }',
    rules: 'Plans without Keep: 1 GB for everything in the library; files used by a post are removed once it publishes, files on no post 24 h after upload. Plans with Keep have a Keep quota: kept files plus media on drafts and scheduled posts; other post media is removed 30 days after publishing. quota.used_bytes is what counts toward the quota (quota.counts says what that is); quota.library_used_bytes is the whole library, Trash included. Large libraries: only the newest 300 files are split (files.complete false).',
    errors: [
      ['unauthorized', 'ask the user to reconnect the creatif.space connector'],
      ['scope_missing', 'reconnect with "read" allowed'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'read',
  input: {},
  annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  run: async (_args, { v1 }) => {
    const { data: usage } = await v1.get<{ data: Usage }>('/files/usage');

    const files: ListedFile[] = [];
    let complete = false;
    for (let page = 1; page <= MAX_PAGES; page++) {
      const res = await v1.get<{ data: ListedFile[]; meta?: { last_page?: number } }>('/files', { per_page: PER_PAGE, page });
      files.push(...res.data);
      if (res.data.length < PER_PAGE || (res.meta?.last_page !== undefined && page >= res.meta.last_page)) {
        complete = true;
        break;
      }
    }

    const ready = files.filter((f) => f.status === 'ready');
    const kept = ready.filter((f) => f.is_kept);
    const dying = ready.filter((f) => !f.is_kept && f.auto_delete_at).sort((a, b) => a.auto_delete_at!.localeCompare(b.auto_delete_at!));
    const staying = ready.filter((f) => !f.is_kept && !f.auto_delete_at);
    const sum = (list: ListedFile[]) => ({ count: list.length, bytes: list.reduce((n, f) => n + (f.size_bytes ?? 0), 0) });

    const free = 'used_bytes' in usage;
    const used = free ? usage.used_bytes : usage.keep_used_bytes;
    const quota = free ? usage.quota_bytes : usage.keep_quota_bytes;
    const percent = pct(used, quota);

    const steps: string[] = [];
    if (percent !== null && percent >= 90) {
      steps.push(
        free
          ? 'Storage is nearly full: suggest deleting files in creatif.space → Files and emptying the Trash.'
          : 'Keep quota is nearly full: suggest unkeep_file on files no longer needed.',
      );
    }
    if (dying.length) {
      steps.push(
        free
          ? 'Files are due for automatic deletion (see files.auto_delete.soonest). If the plan has no Keep, tell the user to download what they need.'
          : 'Files are due for automatic deletion (see files.auto_delete.soonest); keep_file stops that for a file.',
      );
    }

    return {
      tier: usage.tier,
      quota: {
        used_bytes: used,
        quota_bytes: quota,
        percent_used: percent,
        counts: free ? 'everything in the library, Trash included' : 'kept files plus media on drafts and scheduled posts',
        // B14: used_bytes is what counts toward this quota; the whole library is separate on paid plans.
        library_used_bytes: free ? usage.used_bytes : (usage.library_used_bytes ?? null),
      },
      files: {
        kept: sum(kept),
        auto_delete: {
          ...sum(dying),
          next_at: dying[0]?.auto_delete_at ?? null,
          soonest: dying.slice(0, 5).map((f) => ({ id: f.id, name: f.name, size_bytes: f.size_bytes, auto_delete_at: f.auto_delete_at })),
        },
        no_auto_delete: sum(staying),
        scanned: files.length,
        complete,
      },
      ...(steps.length ? { next_step: steps.join(' ') } : {}),
    };
  },
});

const notReady = (file_id: string) =>
  new ToolError(
    'file_not_ready',
    'Only a ready file can be kept; this one is still uploading or was refused.',
    'If you uploaded it with request_media_upload, call confirm_media_upload first, then keep_file again. Otherwise check list_files for its status.',
    { file_id, reason: 'not_ready' },
  );

export const keepFile = defineTool({
  name: 'keep_file',
  title: 'Keep a file',
  description: describeTool({
    does: 'Marks a library file Keep, so automatic deletion never removes it. Counts toward the plan’s Keep quota.',
    when: 'when the user wants a file to stay after the post using it publishes (get_storage_usage shows what is due for deletion). To let it go again use unkeep_file. Safe to call again on a kept file.',
    example: 'keep_file { "file_id": "01JD…" } → { file: { id, name, is_kept: true, auto_delete_at: null }, next_step }',
    rules: 'Not included on every plan. The Keep quota is set by the plan and shared with media on drafts and scheduled posts.',
    errors: [
      ['plan_required', 'Keep is not included on this plan: tell the user it is not available on their plan, do not retry'],
      ['storage_full', 'the Keep quota is full: unkeep_file other files; do not retry before that'],
      ['file_not_ready', 'confirm_media_upload first, or check list_files'],
      ['not_found', 'take the id from list_files'],
      ['forbidden', 'the user’s role (viewer) cannot change files'],
      ['scope_missing', 'reconnect with "draft" allowed'],
    ],
  }),
  scope: 'draft',
  input: { file_id: fileId },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That file', next: 'Take the id from list_files (or confirm_media_upload) and call again.' },
  run: async ({ file_id }, { v1 }) => {
    try {
      const { data } = await v1.post<{ data: V1File }>(`/files/${encodeURIComponent(file_id)}/keep`, {});
      return { file: shapeFile(data), next_step: 'Kept: automatic deletion will not remove it. unkeep_file lets it go again.' };
    } catch (e) {
      if (e instanceof V1Error && e.status === 409) throw notReady(file_id);
      throw e;
    }
  },
});

export const unkeepFile = defineTool({
  name: 'unkeep_file',
  title: 'Un-keep a file',
  description: describeTool({
    does: 'Removes Keep from a library file, freeing Keep quota; the file goes back under the automatic-deletion rules (it is not deleted now).',
    when: 'to free Keep quota (after storage_full) or when the user no longer needs a file kept. Check the returned auto_delete_at and tell the user when it will be removed. To keep it again use keep_file.',
    example: 'unkeep_file { "file_id": "01JD…" } → { file: { id, name, is_kept: false, auto_delete_at: "2026-12-12T09:00:00Z" | null }, next_step }',
    errors: [
      ['not_found', 'take the id from list_files'],
      ['forbidden', 'the user’s role (viewer) cannot change files'],
      ['scope_missing', 'reconnect with "draft" allowed'],
      ['upstream_unavailable', 'wait a minute and call again'],
    ],
  }),
  scope: 'draft',
  input: { file_id: fileId },
  annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That file', next: 'Take the id from list_files and call again.' },
  run: async ({ file_id }, { v1 }) => {
    const { data } = await v1.delete<{ data: V1File }>(`/files/${encodeURIComponent(file_id)}/keep`);
    const file = shapeFile(data);
    return {
      file,
      next_step: file.auto_delete_at
        ? `No longer kept. Tell the user it will be deleted automatically at ${file.auto_delete_at} unless they keep it again (keep_file).`
        : 'No longer kept. It has no deletion date now (it is on a draft or scheduled post, in a Space, or not post media).',
    };
  },
});

export const storageTools = [getStorageUsage, keepFile, unkeepFile];
