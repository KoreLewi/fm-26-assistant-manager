# FM26 Assistant Manager

Persistent data layer for an FM26 save. The database is designed to preserve historical facts instead of relying on chat memory.

## Principle

**Screenshot / manual input → structured record → SQLite → SQL query → tactical analysis**

Raw facts are kept separately from interpretations. Historical records are not overwritten.

## Current initial dataset

The repository contains the first structured squad snapshot reconstructed from the 20 player screenshots supplied in the conversation.

- In-game date: **2025-12-22**
- Season: **2025/26**
- Players: **20**
- Attribute values: **714**
- Player-role records: **92**
- Primary club: **Valencia**

The 2025-12-22 game date is inferred from the screenshots: the displayed ages align with that date, and the Sergio Ramos screenshot shows 190 days remaining to 30/6/2026. If a later screenshot gives a more explicit in-game date, that date should replace the inference.

## What is stored

- `game_state`: current in-game date and season. This is separate from the real-world date.
- `teams`: clubs encountered in the save, including non-Valencia clubs.
- `players`: stable player identity (name, DOB, nationality, etc.).
- `player_snapshots`: age, club, position, value, wage, contract, height, personality, CA/PA stars and other time-dependent state.
- `player_attributes`: individual FM attributes at a specific in-game date.
- `player_roles`: role suitability shown on the player screen, kept as raw screenshot facts.
- `player_traits`: player traits when they are explicitly captured.
- `matches`: every match we explicitly record.
- `match_players`: per-match minutes, rating, distance, xG, xA, goals, assists and other visible statistics.
- `pass_map_nodes`: actual shirt-number-to-player mapping plus average map position.
- `pass_map_links`: player-to-player passing relationships from the pass map.
- `match_team_stats`: additional team statistics visible on screenshots.
- `tactical_observations`: match-specific tactical facts and conclusions, with confidence.
- `player_evaluations`: longer-term assessments of a player.
- `scout_reports`: scouted players, including players who never belonged to Valencia.

## FM26 tactical reference

`data/fm26_ai_system_prompt_v4.json` is the canonical definition of the FM26
phase-based system: the legal In Possession and Out of Possession role list for every
position code, the banned legacy role names, all preset tactical styles, and every team
instruction with every option.

It is the reference any tactical recommendation must be validated against. FM26 has no
Defend/Support/Attack duties - each outfield player receives exactly one IP role and one
OOP role, and a role is legal for a slot only if its exact string appears under that
position code and phase in `allowed_roles_index`.

Validate the reference for internal consistency:

```bash
python3 scripts/validate_roles.py
```

The check enforces that the descriptive `positions` blocks and the flat
`allowed_roles_index` agree character-for-character, that no banned legacy name leaks
into the index, and that no position/phase list contains duplicates.

## Critical data rules

1. **Never overwrite historical player snapshots.** A new in-game date creates a new snapshot.
2. **Never resolve a pass-map shirt number by guessing from another match.** For every match, the shirt number shown in that match is linked to the player who actually wore it in that match.
3. If something is inferred rather than directly visible, mark it as an inference and do not present it as a raw fact.
4. Role labels from screenshots are stored as source facts. Tactical recommendations must separately validate the legal FM26 IP/OOP role system.

## SQLite

The repository stores the **schema, structured source data and import/query tools**. The binary `fm26.sqlite3` file is generated locally and ignored by Git.

Initialize the database:

```bash
python3 scripts/init_db.py
```

Import the committed initial screenshot dataset:

```bash
python3 scripts/import_initial_snapshot.py
```

Verify the initial dataset:

```bash
python3 scripts/verify_db.py
```

Run a SQL query:

```bash
python3 scripts/query.py "SELECT * FROM players;"
```

Example queries are in `db/common_queries.sql`.

The initial source dataset is stored as a gzip-compressed, base64-encoded JSON file so the complete 714-attribute dataset can live in Git without an enormous formatted JSON blob. The helper decodes it transparently before import.

## Data workflow

1. Establish the save's current in-game date.
2. Load player screenshots as historical player/attribute snapshots.
3. Load matches and player match statistics.
4. Load pass-map nodes and links using the exact shirt numbers visible on that match's pass map.
5. Add tactical observations separately from raw statistics.
6. Add scout reports for external players.
7. When the save advances, create new snapshots instead of overwriting old ones.

This lets us answer questions such as:

- What was a player's attribute profile on a specific in-game date?
- How did an attribute change between two dates?
- What was Gayà's actual attacking/pass-map profile across matches?
- Which scouted players resemble a role/profile?
- Which players consistently performed well rather than only in one match?
- What tactical structure produced the observed passing relationships?
