import { afterEach, describe, expect, it } from 'vitest';
import { post } from './fixtures.js';
import { payload, startApp, TOKEN_ALL, TOKEN_READ, USER } from './helpers.js';

/*
 * request_media_upload / confirm_media_upload over a stateful fake of the V1
 * file routes, which answers the way FileController does: presign (format,
 * quota), complete (arrived? real bytes? owner?), show, and the draft/update
 * routes that only take ready files of the caller's workspace.
 */

const OTHER = '01JOTHEROTHEROTHEROTHEROTH';
const TOKEN_OTHER = `stub:${OTHER}:read,draft:Claude`;
const IG = '01JACCTACCTACCTACCTACCTIG1';
const POST = '01JPOSTPOSTPOSTPOSTPOSTPO1';
const BILLING = 'https://creatif.space/settings?section=subscription';
const FILES = 'https://creatif.space/files';
const ACCEPTED = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'video/mp4', 'video/quicktime', 'application/pdf'];
const REFUSED_FORMAT = 'This format is not supported. Convert it to JPEG, PNG, WebP, GIF, SVG, PDF, MP4 or MOV and upload that instead.';

interface FakeFile {
  id: string;
  owner: string;
  name: string;
  mime_type: string;
  size_bytes: number;
  status: 'pending' | 'ready' | 'failed';
  /** What the PUT stored: the real format of the bytes, or nothing yet. */
  stored?: string;
}

type Body = Record<string, unknown>;

function fakeLibrary(opts: { freeUsed?: number; tier?: 'free' | 'pro' | 'max'; imageCap?: number } = {}) {
  const files = new Map<string, FakeFile>();
  const used = new Map<string, number>([[USER, opts.freeUsed ?? 0]]);
  let seq = 0;
  let currentPost = post(POST, { status: 'draft', scheduled_at: null, media: [{ url: 'https://r2.test/u/a/old.jpg' }] });

  const owner = (auth: string | null) => (auth ?? '').replace('Bearer stub:', '').split(':')[0]!;
  const fileOut = (f: FakeFile) => ({ ...f, url: `https://r2.test/u/${f.owner}/${f.id}.jpg?sig=1`, owner: undefined, stored: undefined, width: null, height: null, is_kept: false });
  const readyOwned = (ids: string[], who: string) => ids.every((id) => files.get(id)?.owner === who && files.get(id)?.status === 'ready');
  const notReady = { status: 422, body: { message: 'One or more files are not ready files in your library.', errors: { file_ids: ['One or more files are not ready files in your library.'] } } };

  const handle = (key: string, call: { body: unknown; auth: string | null }): { status?: number; body?: unknown } | undefined => {
    const who = owner(call.auth);
    const body = (call.body ?? {}) as Body;

    if (key === 'POST /files/presign') {
      const mime = String(body.mime_type);
      const size = Number(body.size_bytes);
      if (!ACCEPTED.includes(mime)) return { status: 422, body: { message: REFUSED_FORMAT, errors: { mime_type: [REFUSED_FORMAT] } } };
      if (opts.imageCap !== undefined && mime.startsWith('image/') && size > opts.imageCap) {
        return { status: 422, body: { message: 'This file is larger than your plan allows for this kind of file (10 MB).', reason: 'file_too_large', feature: 'file_size', max_bytes: opts.imageCap, errors: { size_bytes: ['x'] } } };
      }
      if (opts.tier === 'max') {
        return { status: 402, body: { message: 'Your Keep storage is full. Contact us for more storage.', reason: 'quota_exceeded', feature: 'keep_quota', next_step: 'contact_sales', files_url: FILES } };
      }
      if ((used.get(who) ?? 0) + size > 1024 ** 3) {
        return { status: 402, body: { message: 'Your media storage is full (1 GB on Free). Upgrade to Pro for more.', reason: 'quota_exceeded', feature: 'media_quota', next_step: 'upgrade_pro', billing_url: BILLING, files_url: FILES } };
      }
      const id = `01JFILE${String(++seq).padStart(19, '0')}`;
      files.set(id, { id, owner: who, name: String(body.name), mime_type: mime, size_bytes: size, status: 'pending' });
      used.set(who, (used.get(who) ?? 0) + size);
      return { status: 201, body: { data: { id, upload_url: `https://r2.test/u/${who}/${id}.jpg?X-Amz-Signature=s`, headers: { 'Content-Type': mime }, expires_at: '2026-11-11T10:15:00Z' } } };
    }

    let m = /^POST \/files\/([^/]+)\/complete$/.exec(key);
    if (m) {
      const f = files.get(m[1]!);
      if (!f || f.owner !== who) return { status: 404, body: { message: 'Not Found' } };
      if (f.status !== 'pending') return { status: 409, body: { message: 'Conflict' } };
      if (!f.stored) return { status: 422, body: { message: 'The upload never arrived — try again.', errors: { file: ['The upload never arrived — try again.'] } } };
      if (f.stored !== f.mime_type) {
        f.status = 'failed';
        return { status: 422, body: { message: 'This file is not a supported format. Use JPEG, PNG, WebP, GIF, MP4 or MOV.', reason: 'unsupported_format', errors: { file: ['x'] } } };
      }
      f.status = 'ready';
      return { body: { data: fileOut(f) } };
    }

    m = /^GET \/files\/([^/]+)$/.exec(key);
    if (m) {
      const f = files.get(m[1]!);
      return !f || f.owner !== who ? { status: 404, body: { message: 'Not Found' } } : { body: { data: fileOut(f) } };
    }

    if (key === 'POST /social-posts') {
      const ids = (body.file_ids as string[] | undefined) ?? [];
      if (!readyOwned(ids, who)) return notReady;
      return { status: 201, body: { data: post(POST, { status: 'draft', scheduled_at: null, media: ids.map((id) => ({ url: `https://r2.test/u/${who}/${id}.jpg` })) }) } };
    }

    if (key === `GET /social-posts/${POST}`) return { body: { data: currentPost } };
    if (key === `PATCH /social-posts/${POST}`) {
      const ids = (body.file_ids as string[] | undefined) ?? [];
      if (!readyOwned(ids, who)) return notReady;
      const media = [...((body.media as { url: string }[] | undefined) ?? []), ...ids.map((id) => ({ url: `https://r2.test/u/${who}/${id}.jpg` }))];
      currentPost = { ...currentPost, media };
      return { body: { data: currentPost } };
    }
    return undefined;
  };

  // mockV1 looks routes up by "METHOD /path"; a Proxy answers any path.
  const routes = new Proxy({} as Record<string, (call: { body: unknown; auth: string | null }) => { status?: number; body?: unknown }>, {
    get: (_t, key: string) => {
      return (call: { body: unknown; auth: string | null }) => handle(key, call) ?? { status: 404, body: { message: 'Not Found' } };
    },
  });

  /** The caller's PUT to the presigned URL: `as` is the real format of the bytes. */
  const put = (id: string, as?: string) => {
    const f = files.get(id)!;
    f.stored = as ?? f.mime_type;
  };

  return { routes, files, put };
}

let app: Awaited<ReturnType<typeof startApp>> | undefined;
afterEach(async () => {
  await app?.close();
  app = undefined;
});

async function setup(lib = fakeLibrary()) {
  app = await startApp({ routes: lib.routes });
  return { lib, client: await app.connect(TOKEN_ALL), connect: app.connect };
}

const JPEG = { filename: 'launch.jpg', mime: 'image/jpeg', size_bytes: 482_113 };

describe('request_media_upload → PUT → confirm_media_upload → draft_post', () => {
  it('uploads and attaches a file (happy path)', async () => {
    const { lib, client } = await setup();

    const req = await client.callTool({ name: 'request_media_upload', arguments: JPEG });
    expect(req.isError).toBeFalsy();
    const ticket = payload(req);
    expect(ticket).toMatchObject({
      file_id: expect.stringMatching(/^01JFILE/),
      upload: { method: 'PUT', url: expect.stringContaining('X-Amz-Signature'), headers: { 'Content-Type': 'image/jpeg' }, size_bytes: 482_113 },
      expires_at: '2026-11-11T10:15:00Z',
    });
    expect(ticket.next_step).toMatch(/confirm_media_upload/);
    const presign = app!.v1.calls[0]!;
    expect(presign).toMatchObject({ method: 'POST', path: '/files/presign', body: { name: 'launch.jpg', mime_type: 'image/jpeg', size_bytes: 482_113 } });
    expect(presign.headers.get('x-mcp-tool')).toBe('request_media_upload');

    lib.put(ticket.file_id);

    const conf = await client.callTool({ name: 'confirm_media_upload', arguments: { file_id: ticket.file_id } });
    expect(conf.isError).toBeFalsy();
    const { file } = payload(conf);
    expect(file).toMatchObject({ id: ticket.file_id, status: 'ready', mime_type: 'image/jpeg', size_bytes: 482_113 });
    expect(file.owner).toBeUndefined();

    const draft = await client.callTool({ name: 'draft_post', arguments: { caption: 'Launch', channel_ids: [IG], file_ids: [file.id] } });
    expect(draft.isError).toBeFalsy();
    expect(app!.v1.calls.at(-1)!.body).toMatchObject({ file_ids: [file.id], draft: true });
    expect(payload(draft).post.media).toEqual([`https://r2.test/u/${USER}/${file.id}.jpg`]);
  });

  it('confirms again without error: an already-ready file comes back as it is', async () => {
    const { lib, client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    lib.put(file_id);
    await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } });

    const again = await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } });
    expect(again.isError).toBeFalsy();
    expect(payload(again)).toMatchObject({ file: { id: file_id, status: 'ready' }, next_step: expect.stringMatching(/Already confirmed/) });
  });

  it('needs the draft scope and calls nothing without it', async () => {
    app = await startApp({ routes: fakeLibrary().routes });
    const client = await app.connect(TOKEN_READ);
    for (const [name, args] of [['request_media_upload', JPEG], ['confirm_media_upload', { file_id: '01JFILE0000000000000000001' }]] as const) {
      const res = await client.callTool({ name, arguments: args });
      expect(payload(res).error).toMatchObject({ code: 'scope_missing', details: { required: 'draft' } });
    }
    expect(app.v1.calls).toHaveLength(0);
  });
});

describe('over quota', () => {
  it('Free 1 GB full → storage_full with the free-up-space and upgrade links', async () => {
    const { client } = await setup(fakeLibrary({ freeUsed: 1024 ** 3 - 100 }));
    const res = await client.callTool({ name: 'request_media_upload', arguments: JPEG });
    expect(res.isError).toBe(true);
    const { error } = payload(res);
    expect(error).toMatchObject({
      code: 'storage_full',
      message: 'Your media storage is full (1 GB on Free). Upgrade to Pro for more.',
      details: { feature: 'media_quota', next_step: 'upgrade_pro', billing_url: BILLING, files_url: FILES },
    });
    expect(error.next_step).toContain(FILES);
    expect(error.next_step).toContain('emptying the Trash');
    expect(error.next_step).toContain(`upgrade to Pro: ${BILLING}`);
    expect(error.next_step).toMatch(/Do not retry/);
  });

  it('Max Keep quota full → storage_full that says to contact creatif.space, no upgrade link', async () => {
    const { client } = await setup(fakeLibrary({ tier: 'max' }));
    const { error } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    expect(error.code).toBe('storage_full');
    expect(error.next_step).toContain('un-Keep');
    expect(error.next_step).toContain('contact creatif.space');
    expect(error.details.billing_url).toBeUndefined();
  });

  it('a file over the per-file cap → file_too_large with the limit', async () => {
    const { client } = await setup(fakeLibrary({ imageCap: 10 * 1024 * 1024 }));
    const { error } = payload(await client.callTool({ name: 'request_media_upload', arguments: { ...JPEG, size_bytes: 10 * 1024 * 1024 + 1 } }));
    expect(error).toMatchObject({ code: 'file_too_large', details: { max_bytes: 10 * 1024 * 1024 } });
    expect(error.next_step).toMatch(/compress/);
  });

  it('the internal upload limit → upload_limit, not a generic rate limit', async () => {
    app = await startApp({ routes: { 'POST /files/presign': () => ({ status: 429, body: { message: 'Upload limit reached, try again tomorrow', reason: 'upload_limit' } }) } });
    const client = await app.connect(TOKEN_ALL);
    const { error } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    expect(error).toMatchObject({ code: 'upload_limit', message: 'Upload limit reached, try again tomorrow' });
    expect(error.next_step).toMatch(/Do not retry today/);
  });
});

describe('wrong format', () => {
  it('a refused mime at request → unsupported_format with what to convert to', async () => {
    const { client } = await setup();
    const { error } = payload(await client.callTool({ name: 'request_media_upload', arguments: { filename: 'a.zip', mime: 'application/zip', size_bytes: 10 } }));
    expect(error).toMatchObject({ code: 'unsupported_format', message: REFUSED_FORMAT });
    expect(error.next_step).toMatch(/Convert the file to JPEG, PNG, WebP or GIF/);
  });

  it('bytes that are not the declared format → unsupported_format at confirm, and the id is dead', async () => {
    const { lib, client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    lib.put(file_id, 'application/x-msdownload');

    const { error } = payload(await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } }));
    expect(error.code).toBe('unsupported_format');
    expect(lib.files.get(file_id)!.status).toBe('failed');

    const again = payload(await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } }));
    expect(again.error).toMatchObject({ code: 'invalid_input', next_step: expect.stringMatching(/request_media_upload/) });
  });

  it('confirm before the PUT → upload_incomplete, and confirms once the bytes arrive', async () => {
    const { lib, client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));

    const early = payload(await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } }));
    expect(early.error).toMatchObject({ code: 'upload_incomplete', message: 'The upload never arrived — try again.' });
    expect(early.error.next_step).toMatch(/PUT the file bytes/);

    lib.put(file_id);
    const ok = await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } });
    expect(ok.isError).toBeFalsy();
  });
});

describe('unconfirmed uploads in posts', () => {
  it('draft_post with an unconfirmed file id → file_not_ready that points at confirm_media_upload', async () => {
    const { lib, client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    lib.put(file_id); // uploaded, but never confirmed

    const res = await client.callTool({ name: 'draft_post', arguments: { caption: 'Too soon', channel_ids: [IG], file_ids: [file_id] } });
    expect(res.isError).toBe(true);
    const { error } = payload(res);
    expect(error.code).toBe('file_not_ready');
    expect(error.next_step).toMatch(/call confirm_media_upload first/);
  });

  it('update_post with an unconfirmed file id → file_not_ready', async () => {
    const { client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));

    const { error } = payload(await client.callTool({ name: 'update_post', arguments: { post_id: POST, add_file_ids: [file_id] } }));
    expect(error.code).toBe('file_not_ready');
  });

  it('update_post add_file_ids keeps the current media; file_ids replaces it', async () => {
    const { lib, client } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    lib.put(file_id);
    await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } });

    const added = await client.callTool({ name: 'update_post', arguments: { post_id: POST, add_file_ids: [file_id] } });
    expect(added.isError).toBeFalsy();
    expect(app!.v1.calls.at(-1)!.body).toEqual({ media: [{ url: 'https://r2.test/u/a/old.jpg' }], file_ids: [file_id] });
    expect(payload(added).post.media).toHaveLength(2);

    const replaced = await client.callTool({ name: 'update_post', arguments: { post_id: POST, file_ids: [file_id] } });
    expect(app!.v1.calls.at(-1)!.body).toEqual({ media: [], file_ids: [file_id] });
    expect(payload(replaced).post.media).toEqual([`https://r2.test/u/${USER}/${file_id}.jpg`]);
  });

  it('update_post refuses file_ids and add_file_ids together before calling V1', async () => {
    const { client } = await setup();
    const { error } = payload(await client.callTool({ name: 'update_post', arguments: { post_id: POST, file_ids: ['01JFILE0000000000000000001'], add_file_ids: ['01JFILE0000000000000000002'] } }));
    expect(error.code).toBe('invalid_input');
    expect(app!.v1.calls).toHaveLength(0);
  });
});

describe('another workspace', () => {
  it('another workspace’s file id → not_found on confirm, and file_not_ready in draft_post', async () => {
    const { lib, client, connect } = await setup();
    const { file_id } = payload(await client.callTool({ name: 'request_media_upload', arguments: JPEG }));
    lib.put(file_id);

    const stranger = await connect(TOKEN_OTHER);
    const res = await stranger.callTool({ name: 'confirm_media_upload', arguments: { file_id } });
    expect(payload(res).error).toMatchObject({ code: 'not_found', message: 'That upload was not found in this workspace.' });
    expect(lib.files.get(file_id)!.status).toBe('pending');

    await client.callTool({ name: 'confirm_media_upload', arguments: { file_id } });
    const draft = await stranger.callTool({ name: 'draft_post', arguments: { caption: 'Not mine', channel_ids: [IG], file_ids: [file_id] } });
    expect(payload(draft).error.code).toBe('file_not_ready');
  });
});

describe('descriptions', () => {
  it('say when to use the tool, give an example and the next step for each error', async () => {
    const { client } = await setup();
    const { tools } = await client.listTools();
    const desc = (name: string) => tools.find((t) => t.name === name)!.description!;

    for (const name of ['request_media_upload', 'confirm_media_upload']) {
      expect(desc(name)).toMatch(/Example: /);
      expect(desc(name)).toMatch(/Errors and what to do/);
      expect(desc(name)).toContain('storage_full');
    }
    expect(desc('request_media_upload')).toMatch(/Use it when/);
    expect(desc('request_media_upload')).toMatch(/upgrade/);
    expect(desc('request_media_upload')).toMatch(/free up space/);
    expect(desc('draft_post')).toContain('confirm_media_upload');
    expect(desc('update_post')).toContain('add_file_ids');
  });
});
