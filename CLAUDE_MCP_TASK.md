# Task: build a PHP remote MCP server for this repository

Build a remote MCP server that lets Claude query and write this FM26 dataset directly,
replacing the current workflow (download repo → rebuild SQLite → commit JSON back with a
GitHub token pasted into every new conversation).

Read `README.md` and `db/schema.sql` first. Do not change the existing Python scripts.

## Hard constraints

- **PHP only.** The host has no Node and no Python. Assume PHP 8.x with `pdo_sqlite`
  and `json`. No Composer dependencies, no framework — plain PHP files.
- **Single public endpoint** over HTTPS. Claude connects from Anthropic's cloud, never
  from the user's machine, so the endpoint must be reachable from the public internet.
- **Transport: Streamable HTTP.** MCP over HTTP is JSON-RPC 2.0 in a POST body. Handle
  at minimum `initialize`, `tools/list`, `tools/call`, and reply to `notifications/*`
  with HTTP 202 and no body. Respond `application/json`; SSE is not required.
- **Auth: secret in the URL path.** Claude's custom-connector UI accepts a URL and
  optional OAuth client ID/secret, but has no field for arbitrary headers, so a bearer
  token cannot be configured. Use a capability URL:
  `https://<host>/mcp/<64-char-random-token>/`. Compare with `hash_equals`. Reject
  anything else with 404 (not 401 — do not confirm the path exists).
- **The SQLite file must live outside the web root** and must never be servable over
  HTTP. Path configurable in `config.php`, which is gitignored; ship `config.example.php`.

## Tools to expose

| Tool | Purpose |
|---|---|
| `query` | Run a read-only SQL SELECT and return rows as JSON |
| `list_tables` | Table names plus column definitions |
| `import_json` | Accept an import payload in the same shape as `data/import_template.json` and insert it |
| `save_state` | Current in-game date, season, club, counts per table |

Rules for `query`: reject anything that is not a single `SELECT`/`WITH` statement —
block `;` chaining, `PRAGMA`, `ATTACH`, and all writes. Open a **second, read-only PDO
connection** for this tool (`file:...?mode=ro` with `SQLITE_OPEN_READONLY`) rather than
relying on string matching alone. Cap returned rows (default 500) and say so in the
result when truncated.

`import_json` must reuse the exact table and column whitelist from
`scripts/import_json.py` so the two importers cannot drift. Wrap each import in a
transaction and roll back on any error. Return the number of rows written per table.

## Behaviour requirements

- Every tool description must be explicit enough that Claude picks the right tool
  without guessing — state what the tool returns and when to use it.
- Errors return JSON-RPC error objects, never HTML or a PHP stack trace. Set
  `display_errors=0`; log server-side instead.
- Do not log the URL path (it contains the secret). Include an nginx/Apache snippet in
  the README showing how to suppress access logging for that location.
- Include a `--selftest` CLI mode (`php mcp/server.php --selftest`) that runs
  initialize → tools/list → a sample query against a temporary database and prints
  pass/fail, so the host can be verified before wiring it to Claude.

## Deliverables

```
mcp/
  server.php            entry point, JSON-RPC dispatch, auth
  tools.php             tool definitions and handlers
  db.php                PDO helpers, read-only connection, import logic
  config.example.php    template: db path, secret token, row cap
  README.md             install steps, nginx + Apache config, token generation,
                        how to add the connector in Claude, how to rotate the secret
.gitignore              add mcp/config.php
```

## Bootstrapping the database

The server needs a populated SQLite file on the host. Since Python is unavailable there,
either commit a prebuilt `fm26.sqlite3` for upload, or add `mcp/bootstrap.php` that
builds the database by executing `db/schema.sql` and importing every `data/*.json` in
filename order. The `.b64` initial snapshot is gzip-compressed base64 JSON — decode with
`gzdecode(base64_decode(...))`.

## Verification before you call this done

1. `php mcp/server.php --selftest` passes.
2. `initialize` returns a valid `protocolVersion` and `serverInfo`.
3. `tools/list` returns all four tools with complete JSON schemas.
4. `query` with `SELECT name FROM players LIMIT 5` returns rows.
5. `query` with `DROP TABLE players` is rejected.
6. A wrong token in the URL returns 404.
7. `import_json` with a small payload inserts and is visible to a following `query`.

## Out of scope

Do not implement OAuth, multi-user support, or a web UI. Do not modify the Python
scripts, the schema, or any file under `data/`.
