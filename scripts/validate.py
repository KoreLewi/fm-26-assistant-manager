#!/usr/bin/env python3
"""Validate important integrity rules in the FM26 database."""

from pathlib import Path
import sqlite3

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

CHECKS = {
    "foreign_keys": "PRAGMA foreign_key_check",
    "duplicate_match_shirt_numbers": """
        SELECT match_id, shirt_number_at_match, COUNT(*) AS c
        FROM match_players
        WHERE shirt_number_at_match IS NOT NULL
        GROUP BY match_id, shirt_number_at_match
        HAVING COUNT(*) > 1
    """,
    "duplicate_pass_map_shirt_numbers": """
        SELECT match_id, shirt_number_at_match, COUNT(*) AS c
        FROM pass_map_nodes
        GROUP BY match_id, shirt_number_at_match
        HAVING COUNT(*) > 1
    """,
    "invalid_attributes": """
        SELECT id, player_id, game_date, attribute_name, value
        FROM player_attributes
        WHERE value < 1 OR value > 20
    """,
    "missing_pass_map_players": """
        SELECT pm.match_id, pm.shirt_number_at_match, pm.player_id
        FROM pass_map_nodes pm
        LEFT JOIN match_players mp
          ON mp.match_id = pm.match_id AND mp.player_id = pm.player_id
        WHERE mp.id IS NULL
    """,
}


def main() -> None:
    with sqlite3.connect(DB_PATH) as conn:
        failed = False
        for name, sql in CHECKS.items():
            rows = conn.execute(sql).fetchall()
            if rows:
                failed = True
                print(f"FAIL: {name}")
                for row in rows:
                    print("  ", row)
            else:
                print(f"OK: {name}")
    raise SystemExit(1 if failed else 0)


if __name__ == "__main__":
    main()
