#!/usr/bin/env python3
"""Verify the initial FM26 database snapshot without blocking future additions."""

from pathlib import Path
import sqlite3

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

with sqlite3.connect(DB_PATH) as conn:
    game_date = conn.execute("SELECT current_game_date FROM game_state WHERE id=1").fetchone()[0]
    if game_date < "2025-12-22":
        raise SystemExit(f"Game date {game_date} is earlier than the initial snapshot 2025-12-22")

    players = conn.execute("SELECT COUNT(*) FROM players").fetchone()[0]
    attrs = conn.execute("SELECT COUNT(*) FROM player_attributes WHERE game_date='2025-12-22'").fetchone()[0]
    roles = conn.execute("SELECT COUNT(*) FROM player_roles WHERE game_date='2025-12-22'").fetchone()[0]

    if players < 20:
        raise SystemExit(f"Expected at least 20 players, got {players}")
    if attrs < 714:
        raise SystemExit(f"Expected at least 714 initial attribute rows, got {attrs}")
    if roles < 92:
        raise SystemExit(f"Expected at least 92 initial role rows, got {roles}")

print("FM26 database verification OK")
