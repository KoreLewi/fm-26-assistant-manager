#!/usr/bin/env python3
"""Create the local FM26 SQLite database from db/schema.sql."""

from pathlib import Path
import sqlite3

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"
SCHEMA_PATH = ROOT / "db" / "schema.sql"


def main() -> None:
    schema = SCHEMA_PATH.read_text(encoding="utf-8")
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("PRAGMA foreign_keys = ON")
        conn.executescript(schema)
        conn.commit()
    print(f"Initialized {DB_PATH}")


if __name__ == "__main__":
    main()
