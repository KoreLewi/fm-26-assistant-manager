# CLAUDE.md

Working notes for this repository. `README.md` describes the data model and the FM26
domain rules; `mcp/README.md` describes the MCP server. This file covers how to work
here.

## What this is

A Football Manager 2026 save kept as structured data. The committed JSON seeds the
database and can rebuild it from nothing; everything recorded since the connector went
live is written through it into the hosted database and stays there. Data is not
brought back into this repository. Two importers build the same
database from the same payload:

- **Python** (`scripts/*.py`) → SQLite. The local path, and the contract of record.
- **PHP** (`mcp/`) → MySQL on the host, SQLite when asked. The hosted path, so a Claude
  conversation can query and write through an MCP connector.

`fm.kplev.hu` serves the MCP endpoint. This directory **is** its document root: the
working copy is synchronised to the server automatically, so every file added here is a
file on a public web server until `.htaccess` says otherwise.

## Hard rules

1. **Do not upload anything by hand.** The sync is automatic. No FTP client, no manual
   file copy.
2. **`mcp/db.php` mirrors the table and column whitelist of `scripts/import_json.py`,
   and `db/schema.mysql.sql` mirrors `db/schema.sql`.** Changing one side without the
   other lets the builds drift. The `mcp` CI job builds all three ways and compares
   them row by row with `scripts/compare_databases.py`.
3. **Never commit a secret.** `env` (FTP credentials), `mcp/config.php` (database
   password and capability token) and `mcp/config.local.php` are gitignored. The MCP URL
   is a password: it grants full read and write access.
4. **`mcp/config.php` is the host's configuration**, because the sync carries it to the
   server. To work against a local database, point `FM26_CONFIG` at another file rather
   than editing it:
   `FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --info`
5. **Never log the MCP request path** — it carries the secret. That is why access
   logging is switched off for `/mcp/` in the server configuration.
6. **The OAuth layer exists for the client, not for security.** `mcp/oauth.php` serves
   the whole discovery and registration flow, and `require_bearer` decides whether a
   token is compulsory. It is **false**: claude.ai never follows a 401 challenge — it
   stops after one request and reports a registration failure — but connects at once
   when the endpoint answers. The capability URL is what grants access either way, and
   every OAuth artefact is a payload signed with that secret rather than stored state,
   so a database rebuild keeps the connector working and rotating the secret cuts every
   token.
7. **`fm_` tables are the FM26 rules, unprefixed tables are the career.** A save reset
   drops exactly the unprefixed ones. Never put career data in an `fm_` table, and never
   put a rule that is true of the game into a career table.
8. **Tracing is a debugging aid, not a default.** `trace` writes a line per request,
   readable at `bootstrap.php?token=<secret>&trace=1`. Leave it off.
9. **Never commit data the connector recorded.** The repository seeds the database and
   holds the code; once the connector is live, what it records stays in the hosted
   database, which the host backs up daily. Copying it back here creates a second copy
   that immediately starts drifting.
10. **The connector has no memory.** Every conversation starts blank; `session_log` is
   the only thread across them. `save_state` returns it as a briefing and `session_note`
   writes to it, so a step nobody wrote down is a step the next conversation cannot see.
11. The FM26 data rules in `README.md` ("Critical data rules") are not negotiable:
   historical snapshots are never overwritten, unreadable values are stored as `NULL`
   rather than guessed, and inferences are marked as inferences.

## Verify before calling anything done

```bash
php mcp/server.php --selftest                          # protocol, tools, SQL guard, imports, OAuth
php mcp/server.php --token                             # a bearer token for testing by hand

# Rebuild locally and prove the three builds agree.
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --info    # host and connection report
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --force   # rebuild on MySQL
php mcp/bootstrap.php --sqlite=/tmp/check.sqlite3 --force        # rebuild on SQLite
#   add --save=<slug> when more than one career is in the repository
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/check.sqlite3

python3 scripts/verify_db.py
python3 scripts/verify_reference.py
python3 scripts/verify_tactic.py
python3 scripts/validate.py
python3 scripts/validate_roles.py
```

Against the live endpoint, `initialize` and `tools/list` must answer, a wrong token in
the URL must return 404, and `DROP TABLE players` through the `query` tool must be
refused. `mcp/README.md` has the curl commands.

## Layout

```
data/reference/      the FM26 rules, shared by every career
data/saves/<slug>/   one career; the active one is named in mcp/config.php
db/schema.sql        SQLite schema (Python path)
db/schema.mysql.sql  MySQL schema (hosted path) — same tables, engine-specific types
scripts/             Python importers, validators, and the build comparison
mcp/                 PHP MCP server (server, oauth, tools, reference, tactic, db, bootstrap)
.htaccess            blocks HTTP access to everything except mcp/
env                  FTP credentials — gitignored
```
