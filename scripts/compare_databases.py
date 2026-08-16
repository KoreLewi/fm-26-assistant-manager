#!/usr/bin/env python3
"""Compare two FM26 databases row by row and report any difference.

    python3 scripts/compare_databases.py <reference> <candidate>

Each argument is either a path to a SQLite file or a DSN of the form
``mysql://user:password@host:port/database``. MySQL is read through the ``mysql``
command line client, so no driver has to be installed.

The database is built by two importers (``scripts/import_json.py`` in Python and
``mcp/db.php`` in PHP) against two schema files (SQLite and MySQL). They are only
equivalent for as long as something checks; this is that check.

Surrogate ``id`` columns are left out of the comparison: they are assigned by the
engine in insertion order and carry no information. Numbers are normalised so that
``7.0`` and ``7`` compare equal across engines, and every other value is compared as
text.
"""

from __future__ import annotations

import re
import sqlite3
import subprocess
import sys
from urllib.parse import unquote, urlparse

MYSQL_DSN = re.compile(r"^mysql://", re.IGNORECASE)


class Database:
    """Minimal read interface shared by the SQLite and MySQL backends."""

    def tables(self) -> list[str]:
        raise NotImplementedError

    def columns(self, table: str) -> list[str]:
        raise NotImplementedError

    def rows(self, table: str, columns: list[str]) -> list[tuple[str, ...]]:
        raise NotImplementedError


class SqliteDatabase(Database):
    def __init__(self, path: str) -> None:
        self.label = path
        self.connection = sqlite3.connect(path)

    def tables(self) -> list[str]:
        return [
            row[0]
            for row in self.connection.execute(
                "SELECT name FROM sqlite_master "
                "WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )
        ]

    def columns(self, table: str) -> list[str]:
        return [row[1] for row in self.connection.execute(f'PRAGMA table_info("{table}")')]

    def rows(self, table: str, columns: list[str]) -> list[tuple[str, ...]]:
        select = ",".join(f'"{column}"' for column in columns)
        return [
            tuple(normalise(value) for value in row)
            for row in self.connection.execute(f'SELECT {select} FROM "{table}"')
        ]


class MysqlDatabase(Database):
    def __init__(self, dsn: str) -> None:
        parsed = urlparse(dsn)
        self.label = dsn
        self.database = parsed.path.lstrip("/")
        self.base = [
            "mysql",
            f"--host={parsed.hostname or '127.0.0.1'}",
            f"--port={parsed.port or 3306}",
            f"--user={unquote(parsed.username or 'root')}",
            "--default-character-set=utf8mb4",
            "--batch",
            "--raw",
            "--skip-column-names",
            self.database,
        ]
        if parsed.password:
            self.base.insert(1, f"--password={unquote(parsed.password)}")

    def run(self, sql: str) -> list[list[str]]:
        result = subprocess.run(self.base + ["-e", sql], capture_output=True, text=True)
        if result.returncode:
            raise SystemExit(f"mysql failed: {result.stderr.strip()}")
        return [line.split("\t") for line in result.stdout.splitlines()]

    def tables(self) -> list[str]:
        return [
            row[0]
            for row in self.run(
                "SELECT table_name FROM information_schema.tables "
                "WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' "
                "ORDER BY table_name"
            )
        ]

    def columns(self, table: str) -> list[str]:
        return [
            row[0]
            for row in self.run(
                "SELECT column_name FROM information_schema.columns "
                f"WHERE table_schema = DATABASE() AND table_name = '{table}' "
                "ORDER BY ordinal_position"
            )
        ]

    def rows(self, table: str, columns: list[str]) -> list[tuple[str, ...]]:
        select = ",".join(f"`{column}`" for column in columns)
        return [
            tuple(normalise(value) for value in row)
            for row in self.run(f"SELECT {select} FROM `{table}`")
        ]


def normalise(value: object) -> str:
    """Reduce a cell to a comparable string across engines."""
    if value is None or value == "NULL":
        return "\x00NULL"
    text = str(value)
    try:
        number = float(text)
    except ValueError:
        return text
    if number == int(number):
        return str(int(number))
    return f"{number:.6f}"


def open_database(argument: str) -> Database:
    return MysqlDatabase(argument) if MYSQL_DSN.match(argument) else SqliteDatabase(argument)


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("Usage: python3 scripts/compare_databases.py <reference> <candidate>")

    reference = open_database(sys.argv[1])
    candidate = open_database(sys.argv[2])

    reference_tables = reference.tables()
    candidate_tables = candidate.tables()
    failures = 0

    missing = sorted(set(reference_tables) - set(candidate_tables))
    extra = sorted(set(candidate_tables) - set(reference_tables))
    if missing:
        failures += 1
        print(f"Missing in the candidate: {', '.join(missing)}")
    if extra:
        failures += 1
        print(f"Only in the candidate: {', '.join(extra)}")

    for table in reference_tables:
        if table not in candidate_tables:
            continue

        columns = [column for column in reference.columns(table) if column != "id"]
        candidate_columns = [column for column in candidate.columns(table) if column != "id"]
        if columns != candidate_columns:
            failures += 1
            print(f"DIFF {table}: column list differs")
            print(f"  reference: {columns}")
            print(f"  candidate: {candidate_columns}")
            continue

        left = sorted(reference.rows(table, columns))
        right = sorted(candidate.rows(table, columns))
        if left == right:
            print(f"OK   {table:<24} {len(left):>5} rows")
            continue

        failures += 1
        print(f"DIFF {table}: reference={len(left)} candidate={len(right)}")
        for row in sorted(set(left) - set(right))[:5]:
            print("  reference only:", str(row)[:200])
        for row in sorted(set(right) - set(left))[:5]:
            print("  candidate only:", str(row)[:200])

    if failures:
        raise SystemExit(f"{failures} difference(s) between {reference.label} and {candidate.label}")
    print(f"\nIdentical: {reference.label} and {candidate.label}")


if __name__ == "__main__":
    main()
