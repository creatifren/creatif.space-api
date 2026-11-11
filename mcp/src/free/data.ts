import { readFileSync } from 'node:fs';

/* The copy and limits live in mcp/free-tools/*.json so Growth can edit them
   without touching code. src/free and dist/free are both two levels below
   mcp/, so the same relative path works from source and from the build. */
export function readFreeJson<T>(name: string): T {
  return JSON.parse(readFileSync(new URL(`../../free-tools/${name}`, import.meta.url), 'utf8')) as T;
}
