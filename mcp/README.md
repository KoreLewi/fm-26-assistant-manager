# FM26 remote MCP server

A remote MCP server in plain PHP that lets any Claude conversation read and write the
FM26 save database directly. No Node, no Python, no Composer, no framework — PHP 8.0 or
newer with `pdo_mysql` and `json` is the whole requirement (`zlib` as well, but only for
`bootstrap.php`, which decodes the committed snapshot). The server refuses to start on
an older PHP or without those extensions, with a plain message saying which is missing.

```
mcp/
  server.php          entry point: authentication, JSON-RPC dispatch, --selftest
  tools.php           the four tool definitions and their handlers
  db.php              connections, the read-only guard, the SQL guard, the importer
  bootstrap.php       builds the database on the host from db/ and data/
  config.example.php  configuration template
  config.php          the host's configuration — gitignored
  config.local.php    a local machine's configuration — gitignored, see FM26_CONFIG
  .htaccess           URL rewrite and include protection
```

## How it works

| Concern | Decision |
|---|---|
| Database | MySQL/MariaDB on the host (`db/schema.mysql.sql`). SQLite is supported by the same code and is what the Python scripts and the selftest use. |
| Transport | Streamable HTTP: one JSON-RPC 2.0 message per POST, answered as `application/json`. SSE is not offered; `GET` returns 405. |
| Authentication | Capability URL — the secret is a path segment: `https://host/mcp/<secret>/`. Compared with `hash_equals`. |
| Wrong secret | HTTP 404, not 401, so the endpoint's existence is never confirmed. |
| Reads | A separate connection put into `START TRANSACTION READ ONLY` (SQLite: `PRAGMA query_only`). The engine refuses every write on it, including DDL. |
| Writes | Only through `import_json`, in one transaction, rolled back completely on any error. |
| Credentials | In `config.php`, which is not in git and is denied over HTTP. |

The secret sits in the URL because Claude's custom-connector form takes a URL and
optional OAuth client credentials, with no field for an arbitrary header — a bearer
token cannot be configured there. Treat the URL exactly as you would a password: it
grants full read and write access to the save.

## Tools

| Tool | Returns |
|---|---|
| `query` | Rows of one read-only `SELECT`, with column names and a truncation flag |
| `list_tables` | Every table with its columns, row count, and whether `import_json` writes to it |
| `import_json` | Rows written per table, plus the resulting save state |
| `save_state` | In-game date, season, club, squad size, latest snapshot and match dates, row counts |
| `reference` | The committed FM26 reference documents: the legal role system, the Hungarian interface vocabulary, the tactics in use |

`query` accepts a single `SELECT` (optionally starting with `WITH`). Statement chaining
with `;`, `PRAGMA`, `SET`, and every write statement are refused before execution, and
the read-only transaction is the backstop if a statement ever slips past the text check.
Results are capped at `max_rows` (default 500); a truncated result says so.

`condition` is a MySQL keyword, so a query reading that column of `match_players` needs
it backquoted: ``SELECT `condition` FROM match_players``.

`reference` reads files, not tables. Reference documents describe how FM26 works rather
than recording the save, so they stay as JSON under `data/` — the catalogue is built by
scanning that directory, and a new document becomes available as soon as it is
committed, with nothing to register.

## Install

### 1. Get the files onto the host

The document root of `fm.kplev.hu` is the working copy, synchronised automatically.
Nothing is uploaded by hand.

`config.php` is part of that sync, which is why it is the **host's** configuration.
A local machine that needs to work against its own database points `FM26_CONFIG` at a
separate file rather than editing it:

```bash
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --info
```

### 2. Generate a secret

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

### 3. Write the configuration

Copy `config.example.php` to `config.php` and fill in the database credentials and the
secret:

```php
return [
    'driver' => 'mysql',
    'mysql' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'database' => 'kplev_football_manager',
        'username' => 'kplev_football_manager',
        'password' => '...',
        'charset'  => 'utf8mb4',
    ],
    'secret'   => '<the 64 hex characters from step 2>',
    'max_rows' => 500,
];
```

### 4. Check the host

Confirm what the host actually offers — PHP version, `pdo_mysql`, `zlib`, and whether
the database connection works:

```bash
curl 'https://fm.kplev.hu/mcp/bootstrap.php?token=<secret>&info=1'
```

### 5. Build the database

```bash
curl -X POST 'https://fm.kplev.hu/mcp/bootstrap.php?token=<secret>&confirm=rebuild'
# add &force=1 to rebuild over an existing database (every table is dropped first)
```

With shell access the same thing runs as `php mcp/bootstrap.php [--force]`.

The build runs `db/schema.mysql.sql`, then replays every committed source file: the
`*.json.gz.b64` snapshot first, then `data/supplemental/`, then the dated files in
`data/`. Foreign keys are deferred for the duration of the load and every declared
foreign key is verified afterwards, so filename order cannot break the rebuild. The
in-game clock is only ever moved forward, so a template file carrying a placeholder date
cannot rewind the save.

### 6. Verify

```bash
php mcp/server.php --selftest
```

The selftest builds a temporary SQLite database from `db/schema.sql` — no database
server needed, and it never touches the configured one — and checks initialize,
notifications, `tools/list`, a query with truncation, rejection of writes and chained
statements, the read-only connection, `list_tables`, an import that is visible to the
next query, a failing import that rolls back, unknown-column rejection, `save_state`,
token comparison, and the unknown-method error.

Then check the live endpoint:

```bash
URL="https://fm.kplev.hu/mcp/<secret>/"
curl -s -X POST "$URL" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{}}}'
curl -s -X POST "$URL" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
curl -s -X POST "$URL" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"query","arguments":{"sql":"SELECT name FROM players LIMIT 5"}}}'
curl -s -X POST "$URL" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"query","arguments":{"sql":"DROP TABLE players"}}}'
# expected: isError true

curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  "https://fm.kplev.hu/mcp/0000000000000000000000000000000000000000000000000000000000000000/"
# expected: 404
```

### 7. Add the connector in Claude

Settings → Connectors → Add custom connector. Paste
`https://fm.kplev.hu/mcp/<secret>/` as the URL and leave the OAuth fields empty. The
four tools appear once the connector reports as connected.

## Web server configuration

### Apache / LiteSpeed

`mcp/.htaccess` ships with the rewrite and the include protection, so on cPanel hosting
nothing further is needed. The equivalent as a vhost block, plus access-log suppression,
which `.htaccess` cannot do:

```apache
<Directory /home/kplev/fm.kplev.hu/mcp>
    Options -Indexes
    RewriteEngine On
    RewriteBase /mcp/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^([A-Za-z0-9_.~-]{32,200})/?$ server.php [E=MCP_PATH_TOKEN:$1,QSA,L]
</Directory>

# The request path contains the secret, so it must not reach the access log.
SetEnvIf Request_URI "^/mcp/" fm_mcp_secret
CustomLog /home/kplev/logs/fm.kplev.hu-access.log combined env=!fm_mcp_secret
```

### nginx

```nginx
location ~ ^/mcp/(?<mcp_token>[A-Za-z0-9_.~-]{32,200})/?$ {
    access_log off;                      # the path carries the secret
    fastcgi_pass  unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /home/kplev/fm.kplev.hu/mcp/server.php;
    fastcgi_param MCP_PATH_TOKEN  $mcp_token;
    include       fastcgi_params;
}

location = /mcp/bootstrap.php {
    access_log off;                      # the token is passed as a query parameter
    fastcgi_pass  unix:/run/php/php8.2-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /home/kplev/fm.kplev.hu/mcp/bootstrap.php;
    include       fastcgi_params;
}

location ~ ^/mcp/(config|config\.local|config\.example|db|tools)\.php$ { return 404; }
location ~ ^/(\.git|\.github|data|db|scripts)/ { return 404; }
```

If the rewrite is unavailable for any reason, the endpoint still answers at
`https://fm.kplev.hu/mcp/server.php/<secret>`: the token is read from the request path
when `MCP_PATH_TOKEN` is not set.

## Rotating the secret

1. Generate a new one: `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`
2. Replace `secret` in `mcp/config.php` and let the sync carry it to the host.
3. Update the connector URL in Claude.

The old URL stops working the moment the host has the new file; nothing else changes.
Rotate whenever the URL may have been seen by anyone else — a shared screenshot, a
pasted link, a proxy log.

## Errors

Protocol-level problems (bad JSON, missing `method`, unknown method) come back as
JSON-RPC error objects. Failures inside a tool — invalid SQL, a rejected statement, a
foreign key violation during an import — come back as a tool result with
`isError: true` and the reason in the text, which is what lets Claude read the message
and retry with a correction. Neither path ever emits HTML or a PHP stack trace:
`display_errors` is off and internal errors are written to `log_file` instead.

## Two engines, one payload

`db/schema.sql` (SQLite) and `db/schema.mysql.sql` (MySQL) declare the same tables and
columns; only the types differ, and only where MySQL requires it — `TEXT` becomes
`VARCHAR` where a column takes part in a key, `AUTOINCREMENT` becomes `AUTO_INCREMENT`,
`REAL` becomes `DOUBLE`. The same import payload therefore loads into either, and CI
builds the database both ways and compares them row by row.

## Deliberate omissions

No OAuth, no multi-user support, no web UI. The Python scripts, `db/schema.sql` and
everything under `data/` are untouched — `db.php` mirrors the table and column
whitelist of `scripts/import_json.py`, so changing one importer means changing both.
