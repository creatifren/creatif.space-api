import { z } from 'zod';
import { ToolError } from '../errors.js';
import { V1Error } from '../v1.js';
import { defineTool, describeTool } from './define.js';

/*
 * Media upload in two steps, over the same presign/complete the app uses —
 * every limit, quota and format rule stays in Laravel (FileController):
 * request_media_upload → POST /files/presign, the caller PUTs the bytes,
 * confirm_media_upload → POST /files/{id}/complete.
 */

export interface V1File {
  id: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  width?: number | null;
  height?: number | null;
  status: string;
  url?: string;
  is_kept?: boolean;
  auto_delete_at?: string | null;
  created_at?: string;
}

export const shapeFile = (f: V1File) => ({
  id: f.id,
  name: f.name,
  mime_type: f.mime_type,
  size_bytes: f.size_bytes,
  width: f.width ?? null,
  height: f.height ?? null,
  status: f.status,
  url: f.url,
  is_kept: f.is_kept,
  auto_delete_at: f.auto_delete_at ?? null,
});

const fileId = z.string().trim().min(10).max(26).describe('The file_id request_media_upload returned');

export const requestMediaUpload = defineTool({
  name: 'request_media_upload',
  title: 'Request a media upload URL',
  description: describeTool({
    does: "Step 1 of 2 to put a NEW image or video into the user’s creatif.space library: returns a file_id and a presigned upload_url (valid about 15 minutes).",
    when: "when you have the file’s bytes (generated, or given to you) and no public https URL for it. If the file is already in the library, use list_files; if it is at a public https URL, pass that URL to draft_post media_urls. Then: (1) HTTP PUT the exact bytes to upload_url with the Content-Type header given — the size must equal size_bytes; (2) call confirm_media_upload with the file_id; (3) pass that id in draft_post or update_post file_ids.",
    example: "request_media_upload { \"filename\": \"launch.jpg\", \"mime\": \"image/jpeg\", \"size_bytes\": 482113 } → { file_id, upload: { method: \"PUT\", url, headers: { \"Content-Type\": \"image/jpeg\" } }, expires_at }",
    rules: "Accepted: JPEG, PNG, WebP, GIF, MP4, MOV (also SVG and PDF for the library). The workspace’s plan limits apply (total storage and per-file size are set by the plan, from 1 GB in total).",
    errors: [
      ["storage_full", "tell the user to free up space (link given); do not retry"],
      ["file_too_large", "make the file smaller and request again"],
      ["unsupported_format", "convert to a format above"],
      ["upload_limit", "stop for today"],
      ["not_found", "the folder_id is unknown: call again without it"],
      ["scope_missing", "reconnect with \"draft\" allowed"],
    ],
  }),
  scope: 'draft',
  input: {
    filename: z.string().trim().min(1).max(200).describe('File name to show in the library, e.g. "launch.jpg"'),
    mime: z.string().trim().min(3).max(100).describe('MIME type of the bytes, e.g. "image/jpeg", "image/png", "video/mp4"'),
    size_bytes: z.number().int().min(1).describe('Exact size of the file in bytes — the PUT must send exactly this many'),
    folder_id: z.string().trim().min(10).max(26).optional().describe('Library folder to put it in (optional; default the main folder)'),
  },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false },
  notFound: { what: 'That folder', next: 'Call again without folder_id (the file goes to the main folder).' },
  run: async (args, { v1 }) => {
    const { data } = await v1.post<{
      data: { id: string; upload_url: string; headers?: Record<string, string>; expires_at: string };
    }>('/files/presign', {
      name: args.filename,
      mime_type: args.mime.toLowerCase(),
      size_bytes: args.size_bytes,
      ...(args.folder_id ? { folder_id: args.folder_id } : {}),
    });
    return {
      file_id: data.id,
      upload: {
        method: 'PUT',
        url: data.upload_url,
        headers: data.headers ?? { 'Content-Type': args.mime.toLowerCase() },
        size_bytes: args.size_bytes,
      },
      expires_at: data.expires_at,
      next_step:
        'PUT the file bytes to upload.url with exactly these headers before expires_at, then call confirm_media_upload with this file_id. ' +
        'The file cannot be attached to a post until it is confirmed.',
    };
  },
});

export const confirmMediaUpload = defineTool({
  name: 'confirm_media_upload',
  title: 'Confirm a media upload',
  description: describeTool({
    does: "Step 2 of 2: after you PUT the bytes to the upload_url from request_media_upload, checks the stored file and returns it ready to attach.",
    when: "right after the PUT has answered 200, with the file_id from request_media_upload. creatif.space checks the file’s real size against the plan’s limits and its first bytes against the format (a renamed file is refused). Pass the returned id in draft_post or update_post file_ids. Safe to call again: an already confirmed file is returned as it is.",
    example: "confirm_media_upload { \"file_id\": \"01JD…\" } → { file: { id, name, mime_type, size_bytes, status: \"ready\", url } }",
    errors: [
      ["upload_incomplete", "the PUT has not arrived; PUT the bytes (or request a new URL if it expired) and call again"],
      ["unsupported_format", "the bytes are not the declared format and the upload was deleted; convert and start over with request_media_upload"],
      ["file_too_large / storage_full", "as in request_media_upload (smaller file, or free up space)"],
      ["invalid_input", "the upload was refused or abandoned; start over with request_media_upload"],
      ["not_found", "the id is not an upload in this workspace; request a new upload"],
    ],
  }),
  scope: 'draft',
  input: { file_id: fileId },
  annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false },
  notFound: { what: 'That upload', next: 'Start over: call request_media_upload for a new file_id and upload URL.' },
  run: async ({ file_id }, { v1 }) => {
    const path = `/files/${encodeURIComponent(file_id)}`;
    try {
      const { data } = await v1.post<{ data: V1File }>(`${path}/complete`, {});
      return { file: shapeFile(data), next_step: 'Ready. Pass file.id in draft_post or update_post file_ids.' };
    } catch (e) {
      // 409: not pending any more — already confirmed, or refused earlier.
      if (!(e instanceof V1Error) || e.status !== 409) throw e;
    }
    const { data } = await v1.get<{ data: V1File }>(path);
    if (data.status === 'ready') {
      return { file: shapeFile(data), next_step: 'Already confirmed. Pass file.id in draft_post or update_post file_ids.' };
    }
    throw new ToolError(
      'invalid_input',
      `This upload cannot be confirmed: it is "${data.status}" (refused or abandoned).`,
      'Start over with request_media_upload for a new file_id and upload URL.',
      { file_id, status: data.status },
    );
  },
});

export const mediaTools = [requestMediaUpload, confirmMediaUpload];
