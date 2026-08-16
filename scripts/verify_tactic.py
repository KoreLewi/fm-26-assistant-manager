#!/usr/bin/env python3
"""Verify the tactic loaded completely.

A tactic is only useful joined to the squad and to the role system, so the checks are
that every slot resolved to a position code and every line-up entry to a player. A
label that matches no single player is left unresolved by design, so an unresolved
entry means the squad was loaded after the tactic, or a name changed.
"""

from pathlib import Path
import sqlite3
import sys

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

EXPECTED_ROWS = {
    "tactics": 1,
    "tactic_slots": 11,
    "tactic_instructions": 29,
    "tactic_lineups": 22,
}

SPOT_CHECKS = [
    ("line-up entries left unresolved",
     "SELECT COUNT(*) FROM tactic_lineups WHERE player_id IS NULL", 0),
    ("slots without a position code",
     "SELECT COUNT(*) FROM tactic_slots WHERE position_code IS NULL", 0),
    ("slots carrying both phases",
     "SELECT COUNT(*) FROM tactic_slots WHERE ip_role IS NOT NULL AND oop_role IS NOT NULL", 11),
    # DCL and DCR are two sides of one position, so they share a code.
    ("the centre-back pair shares a position code",
     "SELECT COUNT(DISTINCT position_code) FROM tactic_slots WHERE slot IN ('DCL','DCR')", 1),
    ("every slot's position code exists in the reference",
     "SELECT COUNT(*) FROM tactic_slots s LEFT JOIN fm_positions p ON p.code = s.position_code"
     " WHERE p.code IS NULL", 0),
]


def main() -> None:
    failures = 0
    with sqlite3.connect(DB_PATH) as conn:
        for table, expected in EXPECTED_ROWS.items():
            actual = conn.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            if actual != expected:
                failures += 1
            print(f"{status} {table:<22} {actual:>3} (expected {expected})")

        for label, sql, expected in SPOT_CHECKS:
            actual = conn.execute(sql).fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            if actual != expected:
                failures += 1
            print(f"{status} {label:<48} {actual} (expected {expected})")

    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
