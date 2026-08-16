# FM26 Assistant Manager

Persistent data layer for a Football Manager 2026 save, used by Claude acting as an
assistant manager. The repository is the **single source of truth**: nothing may be
recalled from chat memory or a previous save.

## Principle

**Screenshot / manual input → structured JSON → SQLite → SQL query → tactical analysis**

Raw facts are kept separately from interpretations. Historical records are never
overwritten. The SQLite file is *generated*, not stored — the committed JSON is the
real data.

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
  schema.sql                       Full table definitions
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
.github/workflows/validate.yml     CI: build, import, verify, validate on every push
```

## Quick start

```bash
python3 scripts/init_db.py
python3 scripts/import_initial_snapshot.py
for f in data/*.json; do python3 scripts/import_json.py "$f"; done
python3 scripts/verify_db.py
python3 scripts/validate.py
python3 scripts/validate_roles.py
python3 scripts/query.py "SELECT name FROM players ORDER BY name;"
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

## Roadmap

- **MCP server** (`mcp/`, planned): a small PHP + SQLite remote MCP server so any Claude
  conversation can query and write this data directly, without pasting a GitHub token
  each session. Build brief: `CLAUDE_MCP_TASK.md`.
- **Multi-save support** (planned): a `saves` table and a `save_id` on every row so the
  same engine serves any club, not just this Valencia save.
