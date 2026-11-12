# Publishing to the official MCP Registry (Rizal only)

Publishing is a public act. **Only Rizal runs these commands**; no session,
script or workflow runs them for him. Nothing in this file has been executed.

Two entries, both in the `io.github.creatifren/*` namespace (GitHub account
`creatifren`):

| File | Registry name | Remote | Auth | Entry version |
| --- | --- | --- | --- | --- |
| `mcp/server.json` | `io.github.creatifren/creatif-space` | `https://mcp.creatif.space/mcp` | OAuth (`api.creatif.space`) | `1.2.2` |
| `mcp/free-tools/server.json` | `io.github.creatifren/creatif-space-tools` | `https://mcp.creatif.space/free` | none | `1.1.0` |

The registry refuses a version it already has: bump `version` in the file
before publishing it again.

## 1. Get `mcp-publisher` 1.8.8 (the version CI validates with)

From the repo root, on a Mac or Linux machine:

```bash
VERSION=v1.8.8
OS=$(uname -s | tr '[:upper:]' '[:lower:]')
ARCH=$(uname -m | sed 's/x86_64/amd64/;s/aarch64/arm64/')
curl -fsSL -o mcp-publisher.tar.gz "https://github.com/modelcontextprotocol/registry/releases/download/${VERSION}/mcp-publisher_${OS}_${ARCH}.tar.gz"
tar xzf mcp-publisher.tar.gz mcp-publisher && rm mcp-publisher.tar.gz
./mcp-publisher --version
```

## 2. Check both files (no login needed, publishes nothing)

```bash
./mcp-publisher validate mcp/server.json
./mcp-publisher validate mcp/free-tools/server.json
```

Both must print `✅ server.json is valid`.

## 3. Sign in with GitHub (device login, as `creatifren`)

```bash
./mcp-publisher login github
```

It prints a one-time code and `https://github.com/login/device`. Open the
link, sign in as **creatifren**, enter the code and authorize. The terminal
then says you are logged in.

## 4. Publish both entries

```bash
./mcp-publisher publish mcp/server.json
./mcp-publisher publish mcp/free-tools/server.json
```

## 5. Check what the registry now shows

```bash
curl -fsS "https://registry.modelcontextprotocol.io/v0/servers?search=creatif" | jq '.servers[] | {name: .server.name, version: .server.version, latest: ._meta["io.modelcontextprotocol.registry/official"].isLatest}'
```

Expect `creatif-space` at `1.2.2` and `creatif-space-tools` at `1.1.0`, both
`latest: true`.

## 6. Sign out on a shared machine

```bash
./mcp-publisher logout
```

## The other way: the repo workflow

`.github/workflows/mcp-registry.yml` (manual, `workflow_dispatch` only,
GitHub OIDC, no device login) publishes **`mcp/server.json` only**, not the
free-tools entry. Running it is the same public act, so it is also Rizal's to
start.
