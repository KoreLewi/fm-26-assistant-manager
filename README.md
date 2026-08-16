# FM26 Assistant Manager

Persistent data layer for a Football Manager 2026 save, used by Claude acting as an
assistant manager. The repository is the **single source of truth**: nothing may be
recalled from chat memory or a previous save.

Two readers use this file. **"Using it"** is the manager's guide: adding the connector,
the loop while you play, what to ask, what to fix when something looks wrong.
**"Working through the MCP connector"** onwards is the assistant's operating manual: the
tools, the rules it must follow, and how the data is shaped. Everything below that is
how the thing is built.

## Principle

**Screenshot / manual input → structured JSON → database → SQL query → tactical analysis**

Raw facts are kept separately from interpretations. Historical records are never
overwritten. The database is *generated*, not stored — the committed JSON is the real
data. It is rebuilt from those files in under a second, on MySQL for the hosted MCP
server and on SQLite for local work.

## Using it

The assistant is a connector, not a chat window: it is added once and then available in
every conversation. Its URL contains a secret, so it is not written down here - it lives
in `mcp/config.php`, which is not in this repository. Treat that URL as a password.

**Add it once.** In claude.ai: Settings → Connectors → Add custom connector, paste the
URL, leave the OAuth fields empty. In Claude Code:

```bash
claude mcp add --transport http fm26 "<the URL from mcp/config.php>"
```

Set the four read-only tools to run without asking; leave `import_json` on approval, so
nothing is written into the save without you seeing it.

### The loop while you play

1. **Play.** When something worth keeping happens - a match, a new signing, a squad
   screen you want on record - take the screenshot.
2. **Paste it and say what it is.** "This is the Elche match", "Pepelu's attributes on
   7 January". The assistant reads the values off the screen and records them. It stores
   what is legible and leaves the rest empty rather than filling gaps with plausible
   numbers.
3. **Ask.** It answers from the database only. If the answer needs data that was never
   captured, it says so instead of estimating.
4. **Stop whenever.** It writes down where the work stopped, so the next conversation
   opens with it.

### What it can answer now

Questions that need the squad, the matches and the FM26 rule set at the same time:

- *"Who should play the DM slot in Mestral, and does the role suit him?"* - the tactic,
  the line-ups and the legal role list are all in the database, so this is one query.
- *"Which of my players are recorded with a role the game does not actually offer?"*
- *"How has Umar Sadiq's finishing changed since December?"* - dated rows, so trends are
  real rather than remembered.
- *"What does the reference say about the Half Back?"* - keyword search over every
  section of both reference documents.

### What it will not do

- **It will not guess.** A value that was not legible is stored as `NULL` and stays
  `NULL`. A role label read off a screen is kept as what the screen said, and is checked
  against the rule set separately.
- **It will not remember by itself.** Between conversations it knows only what was
  written into `session_log`. That is why every substantive step ends with a note.
- **It will not use another save.** One career is loaded at a time; nothing from a
  previous one is visible.

### Housekeeping

Data recorded through the connector lands on the host. Bring it back and commit it so
the repository stays the source of truth:

```bash
curl 'https://<host>/mcp/bootstrap.php?token=<secret>&pull=1' > /tmp/incoming.json
# review it, then save the payloads under data/saves/<slug>/ and commit
```

Rebuilding the hosted database is safe at any time - it is generated from the committed
files and from whatever the connector wrote since:

```bash
curl -X POST 'https://<host>/mcp/bootstrap.php?token=<secret>&confirm=rebuild&force=1'
```

### When something looks wrong

Ask the assistant for `save_state` first: it names the in-game date the data reflects,
and a stale date explains most surprising answers. Locally, these say whether the data
itself is sound:

```bash
python3 scripts/verify_db.py        # the initial dataset is intact
python3 scripts/validate.py         # integrity rules
python3 scripts/validate_roles.py   # recorded role labels vs the FM26 rule set
python3 scripts/verify_reference.py # the rule set loaded completely
python3 scripts/verify_tactic.py    # the tactic resolved to players and positions
```

## Working through the MCP connector

This is the operating manual for an assistant connected to
`https://www.fm.kplev.hu/mcp/<secret>/`. Six tools are available and they are the only
way in: nothing may be recalled from chat memory or from a previous save.

| Tool | Use it for |
|---|---|
| `save_state` | The current in-game date, season, club, squad size and row counts |
| `list_tables` | Table and column names when they are not already known |
| `query` | Every read: one SQL `SELECT`, rows back as JSON |
| `import_json` | Every write: rows keyed by table name, in one transaction |
| `reference` | The FM26 rules: legal roles per position, banned legacy names, tactical styles, team instructions, and the Hungarian interface vocabulary |
| `session_note` | Record what was done, decided or left open, so the next conversation continues from it |

### Order of work in a session

1. **`save_state` first.** It returns the briefing — what was last worked on, what
   comes next, and which questions are still open — along with the in-game date the
   data reflects. A connector has no memory between conversations, so this is the only
   thing that carries the thread across; read it before answering anything
   time-dependent, and continue from where it says the work stopped.
2. **`reference` before any tactical recommendation.** `fm26_ai_system_prompt_v4` is
   the authority on what is legal. Start with no arguments for the catalogue, then
   drill in with a dot path, for example
   `FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles.allowed_roles_index.ST`.
3. **`query` for facts.** Attributes beat star ratings when judging a player.
4. **`import_json` for anything new.** Every statistic supplied is stored; a new in-game
   date is a new row, never an edit to an old one.
5. **`session_note` to close the step.** Data recorded, a conclusion reached, a decision
   taken, a question left open — a step that is not written down did not happen as far
   as tomorrow is concerned.

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
  import_template.json              Empty shape of an import file
  reference/                        FM26 knowledge - loaded for every career
    fm26_ai_system_prompt_v4.json   Role + instruction reference (v4.1)
    fm26_role_locale_hu.json        Hungarian UI <-> English role names
  saves/
    valencia-2025-26/               One directory per career
      initial_valencia_snapshot_*.b64   First squad snapshot (gzip+base64 JSON)
      season_2025-26_matches_*.json     Fixtures, match stats, season stats, table
      player_umar_sadiq_*.json          Single-player import example
      match_barcelona_away_*.json       Match + pass map import example
      supplemental/                     One-off player additions
      tactics/mestral.json              Current tactic (shape, roles, instructions)
      known_role_conflicts.json         Recorded labels the reference disagrees with
      incoming/                         Written by import_json on the host
db/
  schema.sql                       Full table definitions (SQLite, local builds)
  schema.mysql.sql                 The same tables for MySQL/MariaDB (the hosted build)
  common_queries.sql               Example queries
scripts/
  init_db.py                       Create the database from schema.sql
  import_reference.py              Load data/reference/ into the fm_ tables
  import_initial_snapshot.py       Load the committed initial snapshot
  import_json.py <file>            Load any import JSON
  import_tactic.py <file>          Load a tactic into the tactic tables
  query.py "<SQL>"                 Run a query
  verify_db.py                     Structural sanity check
  verify_reference.py              The reference loaded completely
  verify_tactic.py                 The tactic loaded and resolved
  validate.py                      Data integrity rules
  validate_roles.py                Role-reference consistency and recorded-label conflicts
  rebuild_roles_from_ingame.py     Rebuild the role reference from observed lists
  compare_databases.py             Compare two builds of the database row by row
mcp/                               Remote MCP server (PHP) - see mcp/README.md
  server.php                       Entry point: auth, JSON-RPC dispatch, --selftest
  oauth.php                        The OAuth 2.1 layer the connector requires
  tools.php                        query / list_tables / import_json / save_state / reference
  reference.php                    Parse data/reference/ into the fm_ tables
  tactic.php                       Parse a tactic file into the tactic tables
  db.php                           Connections, read-only guard, SQL guard, importer
  bootstrap.php                    Build, rebuild or reset the database on the host
.github/workflows/validate.yml     CI: build three ways, compare, verify, validate
```

## Quick start

```bash
python3 scripts/init_db.py
python3 scripts/import_reference.py          # the FM26 rules, shared by every career
python3 scripts/import_initial_snapshot.py

# Foreign keys are enforced during a Python import, so files run oldest-first and a
# file that introduces parent rows (teams, competitions, players) runs before the files
# that reference them. Append new imports to the end of this list in in-game date order.
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json

# The tactic loads last: its line-ups resolve against the squad.
python3 scripts/import_tactic.py data/saves/valencia-2025-26/tactics/mestral.json

python3 scripts/verify_db.py
python3 scripts/verify_reference.py
python3 scripts/verify_tactic.py
python3 scripts/validate.py
python3 scripts/validate_roles.py
python3 scripts/query.py "SELECT name FROM players ORDER BY name;"
```

`mcp/bootstrap.php` rebuilds the same database from the same files without Python and
without an explicit file list — it defers foreign keys for the load and checks them
afterwards, so it is order-independent:

```bash
php mcp/bootstrap.php --sqlite=fm26.sqlite3 --force
# Add --save=<slug> once the repository holds more than one career.
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

`data/reference/fm26_ai_system_prompt_v4.json` (v4.1) is the canonical definition of the FM26
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

`data/reference/fm26_role_locale_hu.json` maps the Hungarian in-game labels to those English role
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
from a downloaded copy of the repository. It exposes six tools — `query`, `list_tables`,
`import_json`, `save_state`, `reference`, `session_note` — over Streamable HTTP,
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

## Two sides of the database

Table names mark the boundary. `fm_` tables hold FM26 knowledge - the legal roles per
position and phase, the banned legacy names, the preset styles, the team instructions,
the Hungarian vocabulary, and every section of both reference documents as searchable
text. Everything else is the career: the squad, the matches, the tactic, the
observations.

That makes the two questions joinable in one query - is the role recorded for this
player legal for his position, does the tactic assign a role the game does not offer -
and it makes a career removable in one operation.

## Switching careers

The database holds exactly one career. The others stay in the repository, unloaded.

1. Put the new career's files in `data/saves/<slug>/`.
2. Set `'active_save' => '<slug>'` in `mcp/config.php`.
3. Rebuild: `POST https://fm.kplev.hu/mcp/bootstrap.php?token=<secret>&confirm=rebuild&force=1`

`&confirm=reset` drops and reloads only the career tables and leaves the `fm_` tables
untouched. Nothing in the MCP connector can switch, reset or delete a career; that
needs the capability token.

## Roadmap

- **Out-of-possession roles per player** (blocked on captures): the tactic records an
  OOP role per slot, but no source records what each player is suited to out of
  possession. Recording them needs new screenshots, not new code.
- **The defensive midfielder role list** (blocked on one capture): three recorded labels
  disagree with the reference. See `data/saves/valencia-2025-26/known_role_conflicts.json`.
