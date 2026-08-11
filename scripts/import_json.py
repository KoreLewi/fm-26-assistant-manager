#!/usr/bin/env python3
"""Import reviewed structured FM26 data into SQLite.

Usage:
    python3 scripts/init_db.py
    python3 scripts/import_json.py data/initial_valencia_snapshot_2025-12-22.json

The importer stores raw screenshot facts separately from tactical interpretation.
Historical rows are keyed by in-game date and are never inferred from shirt numbers.
"""

from pathlib import Path
import json
import sqlite3
import sys

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

TABLES = {
    "teams": ["id", "name", "club_type", "notes"],
    "players": ["id", "name", "date_of_birth", "nationality", "preferred_foot",
                "current_team_id", "current_shirt_number", "status", "notes"],
    "player_snapshots": [
        "id", "player_id", "game_date", "age_years", "team_id", "shirt_number",
        "position_text", "condition_text", "role_text", "value_text",
        "value_min_eur", "value_max_eur", "wage_eur_month", "contract_end",
        "height_cm", "personality_text", "reputation_text",
        "current_ability_stars", "potential_ability_stars", "source", "notes"
    ],
    "player_attributes": ["id", "player_id", "game_date", "attribute_category",
                           "attribute_name", "value", "source"],
    "player_roles": ["id", "player_id", "game_date", "phase", "position_text",
                     "role_text", "rating_stars", "source"],
    "player_traits": ["id", "player_id", "game_date", "trait_text", "source"],
    "competitions": ["id", "name", "season", "notes"],
    "matches": ["id", "match_date", "competition_id", "season", "home_team_id",
                "away_team_id", "opponent", "home_away", "score_for",
                "score_against", "xg_for", "xg_against", "result",
                "possession_pct", "shots", "shots_on_target", "tactical_summary", "source"],
    "match_players": ["id", "match_id", "player_id", "team_id",
                      "shirt_number_at_match", "starter", "minutes", "rating",
                      "condition", "distance_km", "xg", "xa", "goals", "assists", "source"],
    "pass_map_nodes": ["id", "match_id", "player_id", "shirt_number_at_match",
                       "avg_x", "avg_y", "passes_in", "passes_out"],
    "pass_map_links": ["id", "match_id", "from_player_id", "to_player_id", "pass_count"],
    "match_team_stats": ["id", "match_id", "team_id", "stat_name",
                         "stat_value", "stat_unit", "source"],
    "tactical_observations": ["id", "match_id", "player_id", "category",
                              "observation", "confidence", "source"],
    "player_evaluations": ["id", "player_id", "evaluation_game_date",
                           "category", "observation", "confidence", "source"],
    "scout_reports": ["id", "player_id", "scout_game_date", "scout_name",
                      "scouting_context", "current_age", "current_team_id",
                      "current_position", "current_value_text", "recommendation",
                      "report_text", "source"],
}


def insert_rows(conn: sqlite3.Connection, table: str, rows: list[dict]) -> None:
    if not rows:
        return
    columns = TABLES[table]
    placeholders = ",".join("?" for _ in columns)
    quoted = ",".join(f'"{c}"' for c in columns)
    sql = f'INSERT OR REPLACE INTO "{table}" ({quoted}) VALUES ({placeholders})'
    for row in rows:
        conn.execute(sql, [row.get(c) for c in columns])


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 scripts/import_json.py data/file.json")

    input_path = Path(sys.argv[1])
    payload = json.loads(input_path.read_text(encoding="utf-8"))

    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("PRAGMA foreign_keys = ON")

        if payload.get("game_state"):
            gs = payload["game_state"]
            conn.execute(
                """INSERT OR REPLACE INTO game_state
                   (id, current_game_date, season, notes)
                   VALUES (1, ?, ?, ?)""",
                (gs["current_game_date"], gs.get("season"), gs.get("notes")),
            )

        order = [
            "teams", "players", "competitions",
            "player_snapshots", "player_attributes", "player_roles", "player_traits",
            "matches", "match_players", "pass_map_nodes", "pass_map_links",
            "match_team_stats", "tactical_observations", "player_evaluations",
            "scout_reports",
        ]
        for table in order:
            insert_rows(conn, table, payload.get(table, []))
        conn.commit()

    print(f"Imported {input_path}")


if __name__ == "__main__":
    main()
