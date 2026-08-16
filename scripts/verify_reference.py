#!/usr/bin/env python3
"""Verify the FM26 reference loaded into the database completely.

The reference is parsed out of JSON into tables, so a parsing mistake shows up as a
missing or duplicated row rather than as an error. These counts come from the reference
documents themselves; if a document grows, they are expected to change with it.
"""

from pathlib import Path
import sqlite3
import sys

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

EXPECTED_ROWS = {
    "fm_positions": 14,
    "fm_roles": 107,
    "fm_banned_roles": 24,
    "fm_styles": 11,
    "fm_instructions": 31,
    "fm_role_locale": 209,
    "fm_reference": 339,
}

SPOT_CHECKS = [
    ("a striker's in-possession roles",
     "SELECT COUNT(*) FROM fm_roles WHERE position_code = 'ST' AND phase = 'IP'", 6),
    ("a striker's out-of-possession roles",
     "SELECT COUNT(*) FROM fm_roles WHERE position_code = 'ST' AND phase = 'OOP'", 3),
    ("screenshot-verified position codes",
     "SELECT COUNT(*) FROM fm_positions WHERE screenshot_verified = 1", 9),
    ("a banned legacy name is recorded",
     "SELECT COUNT(*) FROM fm_banned_roles WHERE role_name = 'Mezzala'", 1),
    # Both phases have an "Overview" group, so the pair is what identifies one.
    ("instruction groups are kept",
     "SELECT COUNT(*) FROM (SELECT DISTINCT phase, group_name FROM fm_instructions)", 8),
    # The changelog's own entry, the version below it, and its four list-valued
    # children; the prose "basis" is a string rather than a container.
    ("the changelog is readable as text",
     "SELECT COUNT(*) FROM fm_reference WHERE path LIKE '%_changelog%'", 6),
    # One description covers several codes through applies_to; both full-back sides
    # must end up with it, which a naive key lookup would miss.
    ("both full-back sides carry a description",
     "SELECT COUNT(*) FROM fm_positions WHERE code IN ('DR','DL') AND description = 'Full-Back'", 2),
    ("every position code carries a description",
     "SELECT COUNT(*) FROM fm_positions WHERE description IS NULL", 0),
]


def main() -> None:
    failures = 0
    with sqlite3.connect(DB_PATH) as conn:
        for table, expected in EXPECTED_ROWS.items():
            actual = conn.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            if actual != expected:
                failures += 1
            print(f"{status} {table:<18} {actual:>4} (expected {expected})")

        for label, sql, expected in SPOT_CHECKS:
            actual = conn.execute(sql).fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            if actual != expected:
                failures += 1
            print(f"{status} {label:<44} {actual} (expected {expected})")

    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
