#!/usr/bin/env python3
"""Run a read-only SQL query against the local FM26 database."""

from pathlib import Path
import sqlite3
import sys

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit('Usage: python3 scripts/query.py "SELECT ..."')

    sql = sys.argv[1].strip()
    if not sql.lower().startswith(("select", "with", "pragma", "explain")):
        raise SystemExit("query.py is read-only; use SELECT/WITH/PRAGMA/EXPLAIN")

    with sqlite3.connect(DB_PATH) as conn:
        conn.row_factory = sqlite3.Row
        rows = conn.execute(sql).fetchall()

    if not rows:
        print("No rows.")
        return

    columns = rows[0].keys()
    print(" | ".join(columns))
    print("-+-".join("-" * len(c) for c in columns))
    for row in rows:
        print(" | ".join("" if row[c] is None else str(row[c]) for c in columns))


if __name__ == "__main__":
    main()
