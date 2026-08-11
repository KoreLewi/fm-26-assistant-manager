# FM26 Assistant Manager

Persistent data layer for an FM26 save. The database is designed to preserve historical facts instead of relying on chat memory.

## Principle

**Screenshot / manual input → structured record → SQLite → SQL query → tactical analysis**

Raw facts are kept separately from interpretations. Historical records are not overwritten.

## What is stored

- `game_state`: the current in-game date and season. This is separate from the real-world date.
- `teams`: clubs encountered in the save, including non-Valencia clubs.
- `players`: stable player identity (name, DOB, nationality, foot, etc.).
- `player_snapshots`: age, club, shirt number, position, value and other time-dependent player state.
- `player_attributes`: 1–20 FM attributes at a specific in-game date.
- `matches`: every match we explicitly record.
- `match_players`: per-match minutes, rating, distance, xG, xA, goals, assists and other visible statistics.
- `pass_map_nodes`: actual shirt-number-to-player mapping plus average map position.
- `pass_map_links`: player-to-player passing relationships from the pass map.
- `match_team_stats`: additional team statistics visible on screenshots.
- `tactical_observations`: match-specific tactical facts and conclusions, with confidence.
- `player_evaluations`: longer-term assessments of a player.
- `scout_reports`: scouted players, including players who never belonged to Valencia.

## Critical data rule

Never resolve a pass-map shirt number by guessing from another match. For every match, the shirt number shown in that match is linked to the player who actually wore it in that match.

If something is inferred rather than directly visible, it must be marked with a non-confirmed confidence value and should not be presented as a fact.

## SQLite

The repository stores the **schema and import/query tools**, not a binary SQLite database. The local `fm26.sqlite3` file is generated from `db/schema.sql` and is ignored by Git.

Initialize:

```bash
python3 scripts/init_db.py
```

Run a SQL query:

```bash
python3 scripts/query.py "SELECT * FROM players;"
```

Import structured JSON:

```bash
python3 scripts/import_json.py data/import_template.json
```

The JSON importer is deliberately explicit. It is intended for data extracted from screenshots by the assistant and then reviewed before insertion.

## Data workflow

1. Establish the save's current in-game date.
2. Load the initial player screenshots as historical player/attribute snapshots.
3. Load matches and player match statistics.
4. Load pass-map nodes and links using the exact shirt numbers visible on that match's pass map.
5. Add tactical observations separately from raw statistics.
6. Add scout reports for external players.
7. When the save advances, create new snapshots instead of overwriting old ones.

This lets us answer questions such as:

- What was a player's attribute profile at the start of the season?
- How did it change by December?
- What was Gaya's actual attacking/pass-map profile across matches?
- Which players in the scouting database resemble that role and profile?
- Which players consistently performed well rather than only in one match?
- What tactical structure produced the observed passing relationships?
