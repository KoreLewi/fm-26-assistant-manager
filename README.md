# FM26 Assistant Manager

Persistent data layer for a Football Manager 2026 save, used by Claude acting as an
assistant manager. The repository is the **single source of truth**: nothing may be
recalled from chat memory or a previous save.

## Principle

**Screenshot / manual input → structured JSON → database → SQL query → tactical analysis**

Raw facts are kept separately from interpretations. Historical records are never
overwritten. The database is *generated*, not stored — the committed JSON is the real
data. It is rebuilt from those files in under a second, on MySQL for the hosted MCP
server and on SQLite for local work.

## Working through the MCP connector

This is the operating manual for an assistant connected to
`https://fm.kplev.hu/mcp/<secret>/`. Five tools are available and they are the only way
in: nothing may be recalled from chat memory or from a previous save.

| Tool | Use it for |
|---|---|
| `save_state` | The current in-game date, season, club, squad size and row counts |
| `list_tables` | Table and column names when they are not already known |
| `query` | Every read: one SQL `SELECT`, rows back as JSON |
| `import_json` | Every write: rows keyed by table name, in one transaction |
| `reference` | The FM26 rules: legal roles per position, banned legacy names, tactical styles, team instructions, the Hungarian interface vocabulary, and the tactics in use |

### Order of work in a session

1. **`save_state` first.** It returns the in-game date the data reflects. Every
   time-dependent answer depends on it, and it is never assumed — the save advances
   with play.
2. **`reference` before any tactical recommendation.** `fm26_ai_system_prompt_v4` is
   the authority on what is legal. Start with no arguments for the catalogue, then
   drill in with a dot path, for example
   `FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles.allowed_roles_index.ST`.
3. **`query` for facts.** Attributes beat star ratings when judging a player.
4. **`import_json` for anything new.** Every statistic supplied is stored; a new in-game
   date is a new row, never an edit to an old one.

### Role legality, in short

FM26 has **no Defend/Support/Attack duties**. Each outfield player gets exactly one In
Possession role and one Out of Possession role. A role is legal for a slot only if that
exact string appears under the position code and phase in `allowed_roles_index`. Legacy
names (Mezzala, Trequartista, Anchor Man, Pressing Forward and the rest) are banned and
listed under `0_critical_fm26_changes.banned_legacy_role_names`.

Codes `GK, DC, DR, DL, DM, MC, AMR, AML, ST` are screenshot-verified; `WBR, WBL, MR, ML,
AMC` are unverified research and should be treated as provisional.

A role label read off a player screen is stored in `player_roles` as a **source fact**.
That is what the game displayed, not a licence to use it: validate any recommendation
against `allowed_roles_index` separately.

### Reading the data

Raw facts and interpretations live apart. `players`, `player_snapshots`,
`player_attributes`, `matches`, `match_players` and the pass-map tables hold what was
visible on screen. `tactical_observations`, `player_evaluations` and `scout_reports`
hold conclusions, each with a `confidence`. Never present an inference as a raw fact.

Player state is time-dependent: `players` holds stable identity, and everything that
changes with the save hangs off `player_snapshots` / `player_attributes` /
`player_roles` keyed by `game_date`. To read a player as of a date, take the latest row
at or before it — the tables keep every earlier row.

Shirt numbers belong to a match. `match_players.shirt_number_at_match` and
`pass_map_nodes.shirt_number_at_match` record who wore the number **in that match**;
never resolve one from another match.

### Writing data

`import_json` takes an object shaped like `data/import_template.json`: table name →
array of row objects, plus an optional `game_state`. Rows are written with
insert-or-replace on the primary key, so supplying an existing `id` overwrites that row.
Parent rows (`teams`, `players`, `competitions`) must already exist or be included in
the same payload — foreign keys are enforced, and the whole import rolls back if any
row fails. A value that is truncated or unreadable on the source screen is stored as
`NULL`, never guessed.

## Repository layout

```
data/
  fm26_ai_system_prompt_v4.json    FM26 role + instruction reference (v4.1)
  fm26_role_locale_hu.json         Hungarian UI <-> English role names
  import_template.json             Empty shape of an import file
  initial_valencia_snapshot_*.b64  First squad snapshot (gzip+base64 JSON)
  season_2025-26_matches_*.json    Fixtures, match stats, season stats, league table
  player_umar_sadiq_*.json         Single-player import example
  match_barcelona_away_*.json      Match + pass map import example
  tactics/mestral.json             Current tactic (shape, roles, instructions)
  supplemental/                    One-off player additions
db/
  schema.sql                       Full table definitions (SQLite, local builds)
  schema.mysql.sql                 The same tables for MySQL/MariaDB (the hosted build)
  common_queries.sql               Example queries
scripts/
  init_db.py                       Create the database from schema.sql
  import_initial_snapshot.py       Load the committed initial snapshot
  import_json.py <file>            Load any import JSON
  query.py "<SQL>"                 Run a query
  verify_db.py                     Structural sanity check
  validate.py                      Data integrity rules
  validate_roles.py                Role-reference consistency check
  rebuild_roles_from_ingame.py     Rebuild the role reference from observed lists
  compare_databases.py             Compare two builds of the database row by row
mcp/                               Remote MCP server (PHP) — see mcp/README.md
  server.php                       Entry point: auth, JSON-RPC dispatch, --selftest
  oauth.php                        The OAuth 2.1 layer the connector requires
  tools.php                        query / list_tables / import_json / save_state / reference
  db.php                           Connections, read-only guard, SQL guard, importer
  bootstrap.php                    Build the database on the host from db/ and data/
.github/workflows/validate.yml     CI: build, import, verify, validate on every push
```

## Quick start

```bash
python3 scripts/init_db.py
python3 scripts/import_initial_snapshot.py

# Foreign keys are enforced during a Python import, so files run oldest-first and a
# file that introduces parent rows (teams, competitions, players) runs before the files
# that reference them. Append new imports to the end of this list in in-game date order.
python3 scripts/import_json.py data/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/match_barcelona_away_2026-01-10.json

python3 scripts/verify_db.py
python3 scripts/validate.py
python3 scripts/validate_roles.py
python3 scripts/query.py "SELECT name FROM players ORDER BY name;"
```

`mcp/bootstrap.php` rebuilds the same database from the same files without Python and
without an explicit file list — it defers foreign keys for the load and checks them
afterwards, so it is order-independent:

```bash
php mcp/bootstrap.php --sqlite=fm26.sqlite3 --force
```

The binary `fm26.sqlite3` is in `.gitignore` and is rebuilt from JSON in well under a
second, so it is always safe to delete and regenerate.

## Current save state

- Club: **Valencia**, First Division, 2025/26
- Last recorded in-game date: **2026-01-10**
- Matches recorded: 24 (17 league + cups + friendlies)
- Players: 27
- Active tactic: **Mestral** — 4-1-2-2-1, Control Possession style, Positive mentality

The in-game date advances continuously with play and is **not** fixed; it is read from
the most recent import, never assumed.

## What is stored

| Table | Contents |
|---|---|
| `game_state` | Current in-game date and season (separate from the real-world date) |
| `teams` | Every club encountered in the save |
| `players` | Stable player identity |
| `player_snapshots` | Time-dependent state: age, value, wage, contract, CA/PA stars |
| `player_attributes` | Individual FM attributes at a specific in-game date |
| `player_roles` | Role suitability shown on the player screen |
| `player_traits` | Player traits when explicitly captured |
| `matches` | Every recorded match, with xG when visible |
| `match_players` | Per-match minutes, rating, distance, xG, xA, goals, assists |
| `player_season_stats` | Season aggregates: matches, goals, assists, xG, average rating, cards |
| `league_standings` | League table snapshots |
| `pass_map_nodes` | Shirt number to player mapping plus average pitch position |
| `pass_map_links` | Player-to-player passing relationships |
| `match_team_stats` | Additional team statistics from screenshots |
| `tactical_observations` | Tactical facts and conclusions, with confidence |
| `player_evaluations` | Longer-term assessments |
| `scout_reports` | Scouted players, including players never at the club |

## FM26 tactical reference

`data/fm26_ai_system_prompt_v4.json` (v4.1) is the canonical definition of the FM26
phase-based system: the legal In Possession and Out of Possession role list for every
position code, banned legacy role names, preset tactical styles, and every team
instruction with its options.

FM26 has **no** Defend/Support/Attack duties. Each outfield player receives exactly one
IP role and one OOP role, and a role is legal for a slot only if its exact string
appears under that position code and phase in `allowed_roles_index`.

v4.1 was rebuilt from in-game screenshots, so where the original research and the game
disagreed, the game won. Codes `GK, DC, DR, DL, DM, MC, AMR, AML, ST` are
screenshot-verified; `WBR, WBL, MR, ML, AMC` are **not** and remain unverified research.
The changelog and open questions live inside the JSON under `_changelog`.

`data/fm26_role_locale_hu.json` maps the Hungarian in-game labels to those English role
names, and records the raw observed lists as source facts.

## Critical data rules

0. **Every statistic supplied is stored.** Any player, match, season or league data
   handed over must be written into the database. If no table can hold it, extend the
   schema (and `scripts/import_json.py`) rather than dropping the data. Values that are
   truncated or unreadable on the source screen are stored as `NULL`, never guessed.
1. **Never overwrite historical player snapshots.** A new in-game date creates a new snapshot.
2. **Never resolve a pass-map shirt number by guessing from another match.** For every
   match, the shirt number shown in that match belongs to the player who wore it then.
3. If something is inferred rather than directly visible, mark it as an inference and do
   not present it as a raw fact.
4. Role labels from screenshots are stored as source facts. Tactical recommendations must
   separately validate against the legal FM26 IP/OOP role system.
5. **Attribute numbers beat star ratings** when evaluating a player.
6. Data only enters analysis if it is present in this repository.

## Data workflow

1. Establish the save's current in-game date.
2. Load player screenshots as historical player/attribute snapshots.
3. Load matches and player match statistics.
4. Load pass-map nodes and links using the shirt numbers visible on that match's pass map.
5. Add tactical observations separately from raw statistics.
6. Add scout reports for external players.
7. When the save advances, create new snapshots instead of overwriting old ones.

## Remote MCP server

`mcp/` is a plain-PHP remote MCP server hosted at `fm.kplev.hu`, so a Claude
conversation reads and writes this data directly instead of rebuilding the database
from a downloaded copy of the repository. It exposes five tools — `query`,
`list_tables`, `import_json`, `save_state`, `reference` — over Streamable HTTP,
authenticated by a secret in the URL path, wrapped in the OAuth 2.1 handshake the
connector requires. How to use them is described under "Working through the MCP
connector" above.

The hosted database is **MySQL** (`db/schema.mysql.sql`), built on the host by
`mcp/bootstrap.php` from the same committed JSON. Reads run inside a read-only
transaction, so the database server itself refuses any write that comes through the
`query` tool.

Install steps, web server configuration and secret rotation: `mcp/README.md`.
Build brief: `CLAUDE_MCP_TASK.md`.

The Python scripts remain the local path and stay authoritative for the import
contract: `mcp/db.php` mirrors the table and column whitelist of
`scripts/import_json.py`, and both importers must be changed together. CI builds the
database three ways — Python on SQLite, PHP on SQLite, PHP on MySQL — and
`scripts/compare_databases.py` fails the build if any of them differ.

## Deployment

The document root of `fm.kplev.hu` is this working copy and is synchronised
automatically; nothing is uploaded by hand. `.htaccess` refuses HTTP access to `data/`,
`db/`, `scripts/`, every source file and every dotfile, so only `mcp/` answers
requests, and `mcp/config.php` — which holds the database password and the capability
token — is denied as well.

## Roadmap

- **Multi-save support** (planned): a `saves` table and a `save_id` on every row so the
  same engine serves any club, not just this Valencia save.
