import { readFileSync } from 'node:fs';
import type { Express } from 'express';

/*
 * The connector's icon, served from mcp.creatif.space itself: the rocket mark
 * (marketing's public/brand/icon-only-light.svg, never the old orange logo or
 * a cropped disc). public/icon.png (512) and public/favicon.ico (48/32/16)
 * were rendered once from public/icon.svg and committed; nothing renders at
 * runtime. Read once at start; served with a long cache.
 */
const PUBLIC = new URL('../public/', import.meta.url);

export const ICON_ORIGIN = 'https://mcp.creatif.space';
export const ICON_PNG_URL = `${ICON_ORIGIN}/icon.png`;
export const ICON_SVG_URL = `${ICON_ORIGIN}/icon.svg`;

/** serverInfo.icons (MCP 2025-11-25 Implementation.icons). */
export const SERVER_ICONS = [
  { src: ICON_PNG_URL, mimeType: 'image/png', sizes: ['512x512'] },
  { src: ICON_SVG_URL, mimeType: 'image/svg+xml', sizes: ['any'] },
];

const ASSETS = [
  { path: '/favicon.ico', file: 'favicon.ico', type: 'image/x-icon' },
  { path: '/icon.png', file: 'icon.png', type: 'image/png' },
  { path: '/icon.svg', file: 'icon.svg', type: 'image/svg+xml' },
] as const;

export function registerIcons(app: Express): void {
  for (const a of ASSETS) {
    const body = readFileSync(new URL(a.file, PUBLIC));
    app.get(a.path, (_req, res) => {
      res.setHeader('Cache-Control', 'public, max-age=31536000, immutable');
      res.setHeader('Access-Control-Allow-Origin', '*');
      res.type(a.type).send(body);
    });
  }
}
