# Save isolation and the FM26 reference in the database — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate FM26 knowledge from save data so a career can be replaced wholesale, and load the knowledge and the tactic into the database so both can be queried and joined.

**Architecture:** One database holds both sides, distinguished by table name: `fm_` tables are generated from `data/reference/` and survive a save switch; unprefixed tables are generated from `data/saves/<active_save>/` and are dropped when the save is reset. Both sides are built from committed JSON by two mirrored importers — Python for local work, PHP for the host — whose output is compared row by row in CI.

**Tech Stack:** PHP 8.0 (no Composer, no framework), Python 3, MariaDB 10.6 on the host, SQLite locally and in the selftest, GitHub Actions.

**Spec:** `docs/superpowers/specs/2026-08-16-save-isolation-and-reference-in-database-design.md`

## Global Constraints

- **PHP 8.0 minimum.** `array_is_list` is polyfilled in `mcp/db.php`; no other function newer than 8.0. CI runs the `mcp` job on 8.0 and 8.2.
- **No Composer, no framework, no Node, no Python on the host.** Plain PHP files only.
- **Two engines, one payload.** `db/schema.sql` (SQLite) and `db/schema.mysql.sql` (MySQL) declare the same tables and columns; only types differ. Every new table is added to both.
- **Two importers, one whitelist.** `mcp/db.php`'s `fm_import_tables()` mirrors `TABLES` in `scripts/import_json.py`. Change both together.
- **Parity is the test.** `scripts/compare_databases.py <a> <b>` must report `Identical` for Python/SQLite vs PHP/SQLite vs PHP/MySQL, ignoring surrogate `id` columns.
- **No FTS.** Keyword search is `LIKE`; FTS5 shadow tables would break the parity comparison.
- **Secrets never committed.** `env`, `mcp/config.php`, `mcp/config.local.php` are gitignored. Never print the capability secret.
- **Never invent data.** A value that is not visible in a source is `NULL`. A lineup entry that does not resolve to exactly one player keeps `player_id` `NULL`.
- **No case-specific comments.** Code comments state the rule, never a particular player, match or date.
- **Local commands** run with `FM26_CONFIG=mcp/config.local.php` when they need a database connection; `php mcp/bootstrap.php --sqlite=<path>` needs no config at all.
- **Never run `bootstrap.php` against the host** during implementation. All work is local.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `mcp/reference.php` | Parse `data/reference/*.json` into rows for the seven `fm_` tables. Pure functions, no I/O beyond reading the files. |
| `mcp/tactic.php` | Parse one tactic JSON into rows for the four tactic tables. |
| `scripts/import_reference.py` | The Python mirror of `mcp/reference.php`, writing into SQLite. |
| `scripts/import_tactic.py` | The Python mirror of `mcp/tactic.php`. |
| `scripts/verify_reference.py` | Assert the reference loaded completely: exact row counts and spot checks. |
| `scripts/verify_tactic.py` | Assert the tactic loaded completely and that every line-up entry and slot resolved. |
| `data/saves/valencia-2025-26/known_role_conflicts.json` | The recorded role labels that disagree with the reference and are accepted for now. |

**Modified:**

| File | Change |
|---|---|
| `db/schema.sql`, `db/schema.mysql.sql` | Seven `fm_` tables, four tactic tables, the `attribute_category` CHECK |
| `mcp/bootstrap.php` | New layout, active save, reference and tactic import, `reset`, `pull` |
| `mcp/db.php` | Tactic tables in the whitelist, `fm_active_save()`, `fm_save_tables()` |
| `mcp/tools.php` | `reference` reads the database and gains `search`; `list_tables` reports scope |
| `mcp/server.php` | Selftest checks for the new behaviour |
| `mcp/config.example.php` | `active_save` |
| `scripts/import_json.py` | Tactic tables in `TABLES` and the order list |
| `scripts/import_initial_snapshot.py` | New snapshot path |
| `scripts/validate.py` | Attribute-category check |
| `scripts/validate_roles.py` | Conflict report against `fm_roles` |
| `.github/workflows/validate.yml` | New verification steps |
| `README.md`, `CLAUDE.md`, `mcp/README.md` | The boundary, the save switch, the search |

**Moved:** every file under `data/` except `import_template.json` — see Task 1.

---

## Task 1: Reorganise `data/` without changing what the build produces

**Files:**
- Move: `data/fm26_ai_system_prompt_v4.json` → `data/reference/`
- Move: `data/fm26_role_locale_hu.json` → `data/reference/`
- Move: `data/initial_valencia_snapshot_2025-12-22.json.gz.b64` → `data/saves/valencia-2025-26/`
- Move: `data/season_2025-26_matches_2026-01-07.json` → `data/saves/valencia-2025-26/`
- Move: `data/player_umar_sadiq_2026-01-07.json` → `data/saves/valencia-2025-26/`
- Move: `data/match_barcelona_away_2026-01-10.json` → `data/saves/valencia-2025-26/`
- Move: `data/supplemental/filip_ugrinic_2025-12-22.json` → `data/saves/valencia-2025-26/supplemental/`
- Move: `data/tactics/mestral.json` → `data/saves/valencia-2025-26/tactics/`
- Modify: `mcp/bootstrap.php` (`fm_source_files`, `fm_bootstrap`), `mcp/db.php` (config), `mcp/config.example.php`, `scripts/import_initial_snapshot.py`, `README.md`, `.github/workflows/validate.yml`

**Interfaces:**
- Produces: `fm_active_save(): string` in `mcp/db.php` — the configured save slug, defaulting to `valencia-2025-26`. `fm_save_dir(): string` — absolute path to `<repo_root>/data/saves/<slug>`. `fm_reference_dir(): string` — absolute path to `<repo_root>/data/reference`.

- [ ] **Step 1: Capture the current build as the thing not to change**

```bash
cd "$(git rev-parse --show-toplevel)"
rm -f /tmp/before.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/before.sqlite3 --force
python3 -c "
import sqlite3
c = sqlite3.connect('/tmp/before.sqlite3')
for t, in c.execute(\"SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name\"):
    print(t, c.execute(f'SELECT COUNT(*) FROM \\\"{t}\\\"').fetchone()[0])
"
```

Expected: 18 tables, `players 27`, `matches 24`, `player_attributes 750`, `player_roles 97`.

- [ ] **Step 2: Move the files with git so history follows**

```bash
mkdir -p data/reference data/saves/valencia-2025-26/supplemental data/saves/valencia-2025-26/tactics
git mv data/fm26_ai_system_prompt_v4.json data/reference/
git mv data/fm26_role_locale_hu.json data/reference/
git mv data/initial_valencia_snapshot_2025-12-22.json.gz.b64 data/saves/valencia-2025-26/
git mv data/season_2025-26_matches_2026-01-07.json data/saves/valencia-2025-26/
git mv data/player_umar_sadiq_2026-01-07.json data/saves/valencia-2025-26/
git mv data/match_barcelona_away_2026-01-10.json data/saves/valencia-2025-26/
git mv data/supplemental/filip_ugrinic_2025-12-22.json data/saves/valencia-2025-26/supplemental/
git mv data/tactics/mestral.json data/saves/valencia-2025-26/tactics/
rmdir data/supplemental data/tactics
```

- [ ] **Step 3: Run the build and watch it fail**

```bash
rm -f /tmp/after.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/after.sqlite3 --force
```

Expected: FAIL with `No source files found under .../data`.

- [ ] **Step 4: Add the active save to the configuration contract**

In `mcp/db.php`, inside `fm_normalise_config()`, immediately before the `max_rows` line:

```php
    // Which career is loaded. The database holds exactly one at a time; the others
    // stay in the repository, unloaded.
    $config['active_save'] = (string) ($config['active_save'] ?? 'valencia-2025-26');
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $config['active_save'])) {
        throw new FmMcpError("Server is not configured: 'active_save' must be a directory name.");
    }
```

At the end of `mcp/db.php`, after `fm_save_state()`:

```php
/** The slug of the career currently loaded. */
function fm_active_save(): string
{
    return fm_config()['active_save'];
}

/** Absolute path to the active career's source files. */
function fm_save_dir(): string
{
    return fm_config()['repo_root'] . '/data/saves/' . fm_active_save();
}

/** Absolute path to the FM26 reference documents, which every career shares. */
function fm_reference_dir(): string
{
    return fm_config()['repo_root'] . '/data/reference';
}
```

In `mcp/config.example.php`, before the `max_rows` entry:

```php
    // The career directory under data/saves/ that gets loaded. Switching careers means
    // changing this and rebuilding; the previous career stays in the repository.
    'active_save' => 'valencia-2025-26',
```

- [ ] **Step 5: Point the source scan at the save directory**

In `mcp/bootstrap.php`, replace the body of `fm_source_files()` entirely:

```php
/** Collect the active career's source files in the order they have to be replayed. */
function fm_source_files(): array
{
    $root = fm_save_dir();
    if (!is_dir($root)) {
        throw new FmMcpError("The save directory {$root} does not exist.");
    }

    $sources = glob($root . '/*.json.gz.b64') ?: [];

    // Supplemental files extend the initial snapshot and carry explicit row ids that
    // continue its numbering, so they have to be replayed directly after it. Only then
    // come the dated top-level imports, whose rows are auto-numbered and must land
    // above the ids already taken. Anything written since the last commit comes last.
    $groups = ['supplemental' => [], 'top' => [], 'nested' => [], 'incoming' => []];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
            continue;
        }
        $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
        if (str_starts_with($relative, 'supplemental/')) {
            $groups['supplemental'][] = $file->getPathname();
        } elseif (str_starts_with($relative, 'incoming/')) {
            $groups['incoming'][] = $file->getPathname();
        } elseif (!str_contains($relative, '/')) {
            $groups['top'][] = $file->getPathname();
        } else {
            $groups['nested'][] = $file->getPathname();
        }
    }
    foreach ($groups as &$group) {
        sort($group);
    }
    unset($group);

    return array_merge($sources, $groups['supplemental'], $groups['top'], $groups['nested'], $groups['incoming']);
}
```

In `fm_bootstrap()`, replace `$sources = fm_source_files($root);` with `$sources = fm_source_files();` and delete the now-unused `$root` assignment only if nothing else uses it — `$schemaPath` still does, so keep it.

- [ ] **Step 6: Run the build and confirm it produces exactly what it did before**

```bash
rm -f /tmp/after.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/after.sqlite3 --force
python3 scripts/compare_databases.py /tmp/before.sqlite3 /tmp/after.sqlite3
```

Expected: `Identical: /tmp/before.sqlite3 and /tmp/after.sqlite3`.

- [ ] **Step 7: Fix the Python path and the documented order**

In `scripts/import_initial_snapshot.py`, replace the `ARCHIVE` line:

```python
ARCHIVE = ROOT / "data" / "saves" / "valencia-2025-26" / "initial_valencia_snapshot_2025-12-22.json.gz.b64"
```

In `README.md`, replace the four `import_json.py` lines of the quick start with:

```bash
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
```

Apply the same four path changes to the `Build the same database with the Python importers` step in `.github/workflows/validate.yml`.

- [ ] **Step 8: Verify the Python path still matches**

```bash
rm -f fm26.sqlite3
python3 scripts/init_db.py
python3 scripts/import_initial_snapshot.py
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/after.sqlite3
python3 scripts/verify_db.py && python3 scripts/validate.py && python3 scripts/validate_roles.py
```

Expected: `Identical`, then `FM26 database verification OK`, five `OK:` lines, `FM26 role reference OK`.

- [ ] **Step 9: Commit**

```bash
git add -A
git commit -m "Separate the reference documents from the career they describe

data/reference holds what FM26 is; data/saves/<slug> holds one career. The active
career is a configuration value, so switching to another one is a setting and a
rebuild rather than a migration. The build produces the same database as before,
compared row by row."
```

---

## Task 2: Normalise attribute categories and stop them drifting

**Files:**
- Modify: `data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json`
- Modify: `db/schema.sql`, `db/schema.mysql.sql`
- Modify: `scripts/validate.py`

**Interfaces:**
- Produces: `player_attributes.attribute_category` is one of `technical`, `mental`, `physical`, `goalkeeping`, enforced by a CHECK constraint in both schemas.

- [ ] **Step 1: Write the failing check**

In `scripts/validate.py`, add to the `CHECKS` dictionary, after `invalid_attributes`:

```python
    "attribute_category_casing": """
        SELECT DISTINCT attribute_category
        FROM player_attributes
        WHERE attribute_category NOT IN ('technical', 'mental', 'physical', 'goalkeeping')
    """,
```

- [ ] **Step 2: Run it and watch it fail**

```bash
python3 scripts/validate.py
```

Expected: `FAIL: attribute_category_casing` listing `('Mental',)`, `('Physical',)`, `('Technical',)`, and exit status 1.

- [ ] **Step 3: Normalise the source file**

```bash
python3 - <<'PY'
import json
from pathlib import Path

path = Path("data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json")
payload = json.loads(path.read_text(encoding="utf-8"))
for row in payload.get("player_attributes", []):
    if row.get("attribute_category"):
        row["attribute_category"] = row["attribute_category"].lower()
path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
PY
```

- [ ] **Step 4: Add the constraint to both schemas**

In `db/schema.sql`, replace the whole `player_attributes` definition with this — the
existing one plus the new constraint:

```sql
CREATE TABLE IF NOT EXISTS player_attributes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_id INTEGER NOT NULL,
    game_date TEXT NOT NULL,
    attribute_category TEXT NOT NULL,
    attribute_name TEXT NOT NULL,
    value INTEGER NOT NULL CHECK(value BETWEEN 1 AND 20),
    source TEXT,
    CHECK(attribute_category IN ('technical', 'mental', 'physical', 'goalkeeping')),
    FOREIGN KEY(player_id) REFERENCES players(id),
    UNIQUE(player_id, game_date, attribute_name)
);
```

In `db/schema.mysql.sql`, in `CREATE TABLE IF NOT EXISTS player_attributes`, after the `chk_attribute_value` constraint:

```sql
    CONSTRAINT chk_attribute_category CHECK (attribute_category IN ('technical','mental','physical','goalkeeping'))
```

- [ ] **Step 5: Rebuild and confirm the check passes**

```bash
rm -f fm26.sqlite3 /tmp/after.sqlite3
python3 scripts/init_db.py
python3 scripts/import_initial_snapshot.py
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
python3 scripts/validate.py
php mcp/bootstrap.php --sqlite=/tmp/after.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/after.sqlite3
```

Expected: six `OK:` lines including `OK: attribute_category_casing`, then `Identical`.

- [ ] **Step 6: Verify the constraint actually rejects a bad value**

```bash
python3 - <<'PY'
import sqlite3
c = sqlite3.connect("fm26.sqlite3")
try:
    c.execute("INSERT INTO player_attributes (player_id, game_date, attribute_category, attribute_name, value) "
              "VALUES (1, '2026-01-10', 'Technical', 'Passing', 10)")
    raise SystemExit("FAIL: the constraint did not reject a capitalised category")
except sqlite3.IntegrityError as error:
    print("rejected as expected:", error)
PY
```

Expected: `rejected as expected: CHECK constraint failed`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Enforce one spelling for attribute categories

The column was written three ways, so a GROUP BY over it returned wrong counts
without saying so. The values are normalised and a CHECK constraint in both schemas
stops them drifting again."
```

---

## Task 3: The `fm_` tables and the Python reference importer

**Files:**
- Modify: `db/schema.sql`, `db/schema.mysql.sql`
- Create: `scripts/import_reference.py`
- Create: `scripts/verify_reference.py`

**Interfaces:**
- Consumes: `data/reference/fm26_ai_system_prompt_v4.json`, `data/reference/fm26_role_locale_hu.json`
- Produces: `reference_rows(reference_dir: Path) -> dict[str, list[dict]]` in `scripts/import_reference.py`, keyed by table name, with these exact tables and row counts: `fm_positions` 14, `fm_roles` 107, `fm_banned_roles` 24, `fm_styles` 11, `fm_instructions` 31, `fm_role_locale` 209, `fm_reference` 339.

- [ ] **Step 1: Write the failing verifier**

Create `scripts/verify_reference.py`:

```python
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
    ("instruction groups are kept",
     "SELECT COUNT(DISTINCT group_name) FROM fm_instructions", 8),
    ("the changelog is readable as text",
     "SELECT COUNT(*) FROM fm_reference WHERE path LIKE '%_changelog%'", 7),
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
            print(f"{status} {label:<40} {actual} (expected {expected})")

    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
```

- [ ] **Step 2: Run it and watch it fail**

```bash
python3 scripts/verify_reference.py
```

Expected: FAIL with `sqlite3.OperationalError: no such table: fm_positions`.

- [ ] **Step 3: Add the seven tables to the SQLite schema**

Append to `db/schema.sql`:

```sql
-- FM26 knowledge. Generated from data/reference/. These tables describe the game, not
-- a career, so a save reset leaves them alone.

CREATE TABLE IF NOT EXISTS fm_positions (
    code TEXT PRIMARY KEY,
    description TEXT,
    screenshot_verified INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS fm_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    position_code TEXT NOT NULL,
    phase TEXT NOT NULL CHECK(phase IN ('IP', 'OOP')),
    role_name TEXT NOT NULL,
    UNIQUE(position_code, phase, role_name),
    FOREIGN KEY(position_code) REFERENCES fm_positions(code)
);

CREATE TABLE IF NOT EXISTS fm_banned_roles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    role_name TEXT NOT NULL UNIQUE,
    replacement TEXT,
    note TEXT
);

CREATE TABLE IF NOT EXISTS fm_styles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name_en TEXT NOT NULL UNIQUE,
    mentality_lean TEXT,
    philosophy TEXT,
    details TEXT
);

CREATE TABLE IF NOT EXISTS fm_instructions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    phase TEXT NOT NULL CHECK(phase IN ('in_possession', 'out_of_possession')),
    group_name TEXT NOT NULL,
    instruction_en TEXT NOT NULL,
    instruction_hu TEXT,
    options TEXT,
    note TEXT,
    UNIQUE(phase, group_name, instruction_en)
);

CREATE TABLE IF NOT EXISTS fm_role_locale (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    hu TEXT NOT NULL,
    en TEXT NOT NULL,
    UNIQUE(kind, hu, en)
);

CREATE TABLE IF NOT EXISTS fm_reference (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document TEXT NOT NULL,
    path TEXT NOT NULL,
    title TEXT,
    text TEXT NOT NULL,
    UNIQUE(document, path)
);

CREATE INDEX IF NOT EXISTS idx_fm_roles_position ON fm_roles(position_code, phase);
CREATE INDEX IF NOT EXISTS idx_fm_reference_document ON fm_reference(document);
```

- [ ] **Step 4: Add the same tables to the MySQL schema**

Append to `db/schema.mysql.sql`:

```sql
CREATE TABLE IF NOT EXISTS fm_positions (
    code VARCHAR(8) NOT NULL PRIMARY KEY,
    description TEXT,
    screenshot_verified TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_roles (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    position_code VARCHAR(8) NOT NULL,
    phase VARCHAR(4) NOT NULL,
    role_name VARCHAR(128) NOT NULL,
    UNIQUE KEY uq_fm_roles (position_code, phase, role_name),
    KEY idx_fm_roles_position (position_code, phase),
    CONSTRAINT fk_fm_roles_position FOREIGN KEY (position_code) REFERENCES fm_positions(code),
    CONSTRAINT chk_fm_roles_phase CHECK (phase IN ('IP','OOP'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_banned_roles (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(191) NOT NULL,
    replacement TEXT,
    note TEXT,
    UNIQUE KEY uq_fm_banned_roles (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_styles (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name_en VARCHAR(191) NOT NULL,
    mentality_lean VARCHAR(64),
    philosophy TEXT,
    details LONGTEXT,
    UNIQUE KEY uq_fm_styles (name_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_instructions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    phase VARCHAR(20) NOT NULL,
    group_name VARCHAR(64) NOT NULL,
    instruction_en VARCHAR(128) NOT NULL,
    instruction_hu VARCHAR(191),
    options TEXT,
    note TEXT,
    UNIQUE KEY uq_fm_instructions (phase, group_name, instruction_en),
    CONSTRAINT chk_fm_instructions_phase CHECK (phase IN ('in_possession','out_of_possession'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_role_locale (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind VARCHAR(32) NOT NULL,
    hu VARCHAR(191) NOT NULL,
    en VARCHAR(191) NOT NULL,
    UNIQUE KEY uq_fm_role_locale (kind, hu, en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fm_reference (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    document VARCHAR(64) NOT NULL,
    path VARCHAR(191) NOT NULL,
    title VARCHAR(191),
    text LONGTEXT NOT NULL,
    UNIQUE KEY uq_fm_reference (document, path),
    KEY idx_fm_reference_document (document)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 5: Write the Python reference importer**

Create `scripts/import_reference.py`:

```python
#!/usr/bin/env python3
"""Load the FM26 reference documents into the fm_ tables.

The documents describe the game rather than a career, so these tables are shared by
every save and are not touched when a save is reset. The parsing rules are mirrored in
mcp/reference.php; the two must produce identical rows.

Usage:
    python3 scripts/import_reference.py
"""

from pathlib import Path
import json
import sqlite3

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"
REFERENCE_DIR = ROOT / "data" / "reference"

SYSTEM_PROMPT = "fm26_ai_system_prompt_v4"
ROLE_LOCALE = "fm26_role_locale_hu"


def _load(name: str, reference_dir: Path) -> dict:
    return json.loads((reference_dir / f"{name}.json").read_text(encoding="utf-8"))


def _positions_and_roles(prompt: dict) -> tuple[list[dict], list[dict]]:
    section = prompt["2_pitch_positions_and_roles"]
    index = {k: v for k, v in section["allowed_roles_index"].items() if isinstance(v, dict)}
    verified = set(prompt["_changelog"]["4.1"]["verified_codes"])

    # A description covers the codes it names in applies_to: one entry describes both
    # full-back sides, both wings, and so on, so the label has to be spread across them.
    labels = {}
    for key, body in (section.get("positions") or {}).items():
        for code in (body.get("applies_to") or key.split("_")):
            labels[code] = body.get("label")

    positions = []
    roles = []
    for code, entry in index.items():
        positions.append({
            "code": code,
            "description": labels.get(code),
            "screenshot_verified": 1 if code in verified else 0,
        })
        for phase, key in (("IP", "in_possession"), ("OOP", "out_of_possession")):
            for role_name in entry.get(key, []):
                roles.append({"position_code": code, "phase": phase, "role_name": role_name})
    return positions, roles


def _banned_roles(prompt: dict) -> list[dict]:
    """Split the banned-name entries into one row per name.

    An entry is either "Name (use Replacement)" or a comma-separated list closing with
    a note that applies to all of them.
    """
    rows = []
    seen = set()
    for entry in prompt["0_critical_fm26_changes"]["banned_legacy_role_names"]:
        head, _, tail = entry.partition("(")
        note = tail.rstrip(")").strip() or None
        replacement = None
        if note and note.lower().startswith("use "):
            replacement, note = note[4:].strip(), None
        for name in head.split(","):
            name = name.split(" - ")[0].strip()
            if not name or name in seen:
                continue
            seen.add(name)
            rows.append({"role_name": name, "replacement": replacement, "note": note})
    return rows


def _styles(prompt: dict) -> list[dict]:
    rows = []
    styles = prompt["5_tactical_styles_and_team_instructions"]["preset_tactical_styles"]
    for name_en, body in styles.items():
        body = body if isinstance(body, dict) else {}
        rows.append({
            "name_en": name_en,
            "mentality_lean": body.get("mentality_lean"),
            "philosophy": body.get("philosophy"),
            "details": json.dumps(body, ensure_ascii=False),
        })
    return rows


def _instructions(prompt: dict) -> list[dict]:
    rows = []
    groups = prompt["5_tactical_styles_and_team_instructions"]["team_instructions"]
    for phase, phase_groups in groups.items():
        if not isinstance(phase_groups, dict):
            continue
        for group_name, items in phase_groups.items():
            if not isinstance(items, dict):
                continue
            for instruction_en, body in items.items():
                options = json.dumps(body, ensure_ascii=False) if isinstance(body, (dict, list)) else str(body)
                rows.append({
                    "phase": phase,
                    "group_name": group_name,
                    "instruction_en": instruction_en,
                    "instruction_hu": body.get("hu") if isinstance(body, dict) else None,
                    "options": options,
                    "note": None,
                })
    return rows


def _role_locale(locale: dict) -> list[dict]:
    """Every Hungarian-to-English pair, tagged with what kind of term it is."""
    rows = []
    seen = set()

    def walk(node, kind):
        if isinstance(node, dict):
            for key, value in node.items():
                if isinstance(value, str):
                    triple = (kind, str(key), value)
                    if triple not in seen:
                        seen.add(triple)
                        rows.append({"kind": kind, "hu": str(key), "en": value})
                else:
                    walk(value, kind)
        elif isinstance(node, list):
            for value in node:
                walk(value, kind)

    for kind, key in (
        ("position", "position_codes"),
        ("phase", "phase_labels"),
        ("trait", "role_trait_labels"),
        ("instruction", "team_instruction_labels"),
        ("role", "observed_role_lists"),
    ):
        walk(locale.get(key, {}), kind)
    return rows


def _reference_sections(document: str, payload: dict) -> list[dict]:
    """One row per container node, addressed by a dot path, holding it as text."""
    rows = []

    def walk(node, path):
        if isinstance(node, (dict, list)):
            rows.append({
                "document": document,
                "path": ".".join(path),
                "title": path[-1],
                "text": json.dumps(node, ensure_ascii=False),
            })
        if isinstance(node, dict):
            for key, value in node.items():
                walk(value, path + [str(key)])

    walk(payload, [document])
    return rows


def reference_rows(reference_dir: Path = REFERENCE_DIR) -> dict[str, list[dict]]:
    prompt_document = _load(SYSTEM_PROMPT, reference_dir)
    prompt = prompt_document["FM26_AI_SYSTEM_PROMPT"]
    locale = _load(ROLE_LOCALE, reference_dir)

    positions, roles = _positions_and_roles(prompt)
    sections = _reference_sections(SYSTEM_PROMPT, prompt_document)
    sections += _reference_sections(ROLE_LOCALE, locale)

    return {
        "fm_positions": positions,
        "fm_roles": roles,
        "fm_banned_roles": _banned_roles(prompt),
        "fm_styles": _styles(prompt),
        "fm_instructions": _instructions(prompt),
        "fm_role_locale": _role_locale(locale),
        "fm_reference": sections,
    }


def main() -> None:
    rows_by_table = reference_rows()
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("PRAGMA foreign_keys = ON")
        for table in ("fm_reference", "fm_role_locale", "fm_instructions",
                      "fm_styles", "fm_banned_roles", "fm_roles", "fm_positions"):
            conn.execute(f'DELETE FROM "{table}"')
        for table, rows in rows_by_table.items():
            if not rows:
                continue
            columns = list(rows[0])
            placeholders = ",".join("?" for _ in columns)
            quoted = ",".join(f'"{c}"' for c in columns)
            conn.executemany(
                f'INSERT OR REPLACE INTO "{table}" ({quoted}) VALUES ({placeholders})',
                [[row[c] for c in columns] for row in rows],
            )
            print(f"{table:<18} {len(rows):>4} rows")
        conn.commit()


if __name__ == "__main__":
    main()
```

- [ ] **Step 6: Build and run the verifier**

```bash
rm -f fm26.sqlite3
python3 scripts/init_db.py
python3 scripts/import_reference.py
python3 scripts/verify_reference.py
```

Expected: every line `OK`, exit status 0. If a count differs, the parsing rule is wrong — fix the rule, not the expected number, unless the reference document itself changed.

- [ ] **Step 7: Confirm the reference is searchable**

```bash
python3 scripts/query.py "SELECT path FROM fm_reference WHERE text LIKE '%Poacher%' ORDER BY path LIMIT 5;"
python3 scripts/query.py "SELECT role_name FROM fm_roles WHERE position_code='ST' AND phase='OOP' ORDER BY role_name;"
```

Expected: paths inside `2_pitch_positions_and_roles`, then `Central Outlet Centre Forward`, `Centre Forward`, `Tracking Centre Forward`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Load the FM26 reference into tables of its own

The role system, the banned legacy names, the preset styles, the team instructions and
the Hungarian vocabulary become queryable, and every section of both documents is kept
as addressable text so prose and changelog can be searched by keyword. The counts are
asserted, because a parsing mistake would otherwise show up as a quietly missing row."
```

---

## Task 4: The PHP reference importer, matching the Python one row for row

**Files:**
- Create: `mcp/reference.php`
- Modify: `mcp/bootstrap.php`

**Interfaces:**
- Consumes: `fm_reference_dir()` from Task 1.
- Produces: `fm_reference_rows(): array` in `mcp/reference.php` — the same seven keys and the same rows as `reference_rows()` in `scripts/import_reference.py`.

- [ ] **Step 1: Write the failing comparison**

```bash
rm -f /tmp/php_ref.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/php_ref.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/php_ref.sqlite3
```

Expected: FAIL listing the seven `fm_` tables as `reference=N candidate=0`.

- [ ] **Step 2: Write `mcp/reference.php`**

Create the file with the same seven builders, mirroring the Python rules exactly:

```php
<?php
/**
 * Parse the FM26 reference documents into rows for the fm_ tables.
 *
 * The documents describe the game rather than a career, so these tables are shared by
 * every save and survive a save reset. The parsing rules are mirrored in
 * scripts/import_reference.py; the two must produce identical rows, which CI checks by
 * comparing the databases they build.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FM_REFERENCE_SYSTEM_PROMPT = 'fm26_ai_system_prompt_v4';
const FM_REFERENCE_ROLE_LOCALE = 'fm26_role_locale_hu';

function fm_reference_load(string $name): array
{
    $path = fm_reference_dir() . '/' . $name . '.json';
    if (!is_file($path)) {
        throw new FmMcpError("Reference document not found: {$path}");
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        throw new FmMcpError("Reference document is not valid JSON: {$path}");
    }

    return $decoded;
}

/** @return array{0: array, 1: array} positions and roles */
function fm_reference_positions_and_roles(array $prompt): array
{
    $section = $prompt['2_pitch_positions_and_roles'];
    $verified = array_flip($prompt['_changelog']['4.1']['verified_codes']);

    // A description covers the codes it names in applies_to: one entry describes both
    // full-back sides, both wings, and so on, so the label has to be spread across them.
    $labels = [];
    foreach ($section['positions'] ?? [] as $key => $body) {
        foreach ($body['applies_to'] ?? explode('_', (string) $key) as $code) {
            $labels[$code] = $body['label'] ?? null;
        }
    }

    $positions = [];
    $roles = [];
    foreach ($section['allowed_roles_index'] as $code => $entry) {
        if (!is_array($entry) || !isset($entry['in_possession']) && !isset($entry['out_of_possession'])) {
            continue;
        }
        $positions[] = [
            'code' => (string) $code,
            'description' => $labels[$code] ?? null,
            'screenshot_verified' => isset($verified[$code]) ? 1 : 0,
        ];
        foreach (['IP' => 'in_possession', 'OOP' => 'out_of_possession'] as $phase => $key) {
            foreach ($entry[$key] ?? [] as $roleName) {
                $roles[] = ['position_code' => (string) $code, 'phase' => $phase, 'role_name' => $roleName];
            }
        }
    }

    return [$positions, $roles];
}

/**
 * One row per banned name. An entry is either "Name (use Replacement)" or a
 * comma-separated list closing with a note that applies to all of them.
 */
function fm_reference_banned_roles(array $prompt): array
{
    $rows = [];
    $seen = [];
    foreach ($prompt['0_critical_fm26_changes']['banned_legacy_role_names'] as $entry) {
        $parts = explode('(', $entry, 2);
        $head = $parts[0];
        $note = isset($parts[1]) ? trim(rtrim($parts[1], ')')) : '';
        $replacement = null;
        if ($note !== '' && stripos($note, 'use ') === 0) {
            $replacement = trim(substr($note, 4));
            $note = '';
        }
        foreach (explode(',', $head) as $name) {
            $name = trim(explode(' - ', $name)[0]);
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $rows[] = ['role_name' => $name, 'replacement' => $replacement, 'note' => $note ?: null];
        }
    }

    return $rows;
}

function fm_reference_styles(array $prompt): array
{
    $rows = [];
    foreach ($prompt['5_tactical_styles_and_team_instructions']['preset_tactical_styles'] as $nameEn => $body) {
        $body = is_array($body) ? $body : [];
        $rows[] = [
            'name_en' => (string) $nameEn,
            'mentality_lean' => $body['mentality_lean'] ?? null,
            'philosophy' => $body['philosophy'] ?? null,
            'details' => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    return $rows;
}

function fm_reference_instructions(array $prompt): array
{
    $rows = [];
    foreach ($prompt['5_tactical_styles_and_team_instructions']['team_instructions'] as $phase => $groups) {
        if (!is_array($groups)) {
            continue;
        }
        foreach ($groups as $groupName => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $instructionEn => $body) {
                $rows[] = [
                    'phase' => (string) $phase,
                    'group_name' => (string) $groupName,
                    'instruction_en' => (string) $instructionEn,
                    'instruction_hu' => is_array($body) ? ($body['hu'] ?? null) : null,
                    'options' => is_array($body)
                        ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : (string) $body,
                    'note' => null,
                ];
            }
        }
    }

    return $rows;
}

function fm_reference_role_locale(array $locale): array
{
    $rows = [];
    $seen = [];

    $walk = function ($node, string $kind) use (&$walk, &$rows, &$seen): void {
        if (is_array($node)) {
            foreach ($node as $key => $value) {
                if (is_string($value)) {
                    $triple = $kind . "\0" . $key . "\0" . $value;
                    if (!isset($seen[$triple])) {
                        $seen[$triple] = true;
                        $rows[] = ['kind' => $kind, 'hu' => (string) $key, 'en' => $value];
                    }
                } else {
                    $walk($value, $kind);
                }
            }
        }
    };

    foreach ([
        'position' => 'position_codes',
        'phase' => 'phase_labels',
        'trait' => 'role_trait_labels',
        'instruction' => 'team_instruction_labels',
        'role' => 'observed_role_lists',
    ] as $kind => $key) {
        $walk($locale[$key] ?? [], $kind);
    }

    return $rows;
}

/** One row per container node, addressed by a dot path, holding it as text. */
function fm_reference_sections(string $document, array $payload): array
{
    $rows = [];

    $walk = function ($node, array $path) use (&$walk, &$rows, $document): void {
        if (is_array($node)) {
            $rows[] = [
                'document' => $document,
                'path' => implode('.', $path),
                'title' => $path[count($path) - 1],
                'text' => json_encode($node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if (!array_is_list($node)) {
                foreach ($node as $key => $value) {
                    $walk($value, array_merge($path, [(string) $key]));
                }
            }
        }
    };

    $walk($payload, [$document]);

    return $rows;
}

/** @return array<string, array<int, array<string, mixed>>> table name => rows */
function fm_reference_rows(): array
{
    $promptDocument = fm_reference_load(FM_REFERENCE_SYSTEM_PROMPT);
    $prompt = $promptDocument['FM26_AI_SYSTEM_PROMPT'];
    $locale = fm_reference_load(FM_REFERENCE_ROLE_LOCALE);

    [$positions, $roles] = fm_reference_positions_and_roles($prompt);

    return [
        'fm_positions' => $positions,
        'fm_roles' => $roles,
        'fm_banned_roles' => fm_reference_banned_roles($prompt),
        'fm_styles' => fm_reference_styles($prompt),
        'fm_instructions' => fm_reference_instructions($prompt),
        'fm_role_locale' => fm_reference_role_locale($locale),
        'fm_reference' => array_merge(
            fm_reference_sections(FM_REFERENCE_SYSTEM_PROMPT, $promptDocument),
            fm_reference_sections(FM_REFERENCE_ROLE_LOCALE, $locale)
        ),
    ];
}

/** Replace the contents of the fm_ tables from the reference documents. */
function fm_reference_import(PDO $pdo): array
{
    $written = [];
    foreach (['fm_reference', 'fm_role_locale', 'fm_instructions', 'fm_styles',
              'fm_banned_roles', 'fm_roles', 'fm_positions'] as $table) {
        $pdo->exec('DELETE FROM ' . fm_ident($table));
    }

    $replace = fm_driver() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
    foreach (fm_reference_rows() as $table => $rows) {
        if ($rows === []) {
            continue;
        }
        $columns = array_keys($rows[0]);
        $quoted = implode(',', array_map('fm_ident', $columns));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare($replace . ' ' . fm_ident($table) . " ({$quoted}) VALUES ({$placeholders})");
        foreach ($rows as $row) {
            $stmt->execute(array_values($row));
        }
        $written[$table] = count($rows);
    }

    return $written;
}
```

- [ ] **Step 3: Call it from the bootstrap**

In `mcp/bootstrap.php`, add after the existing `require_once`:

```php
require_once __DIR__ . '/reference.php';
```

In `fm_bootstrap()`, immediately after the line that appends `'Schema created from db/' . basename($schemaPath)`:

```php
    // The reference describes the game, so it loads for every career.
    foreach (fm_reference_import($pdo) as $table => $count) {
        $lines[] = sprintf('  reference %-52s %5d rows', $table, $count);
    }
```

- [ ] **Step 4: Run the comparison and confirm both sides match**

```bash
rm -f /tmp/php_ref.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/php_ref.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/php_ref.sqlite3
```

Expected: `Identical`. If a `fm_` table differs, the two parsers disagree; fix the PHP to match the Python, which the verifier already pins to the documents.

- [ ] **Step 5: Confirm the same against MySQL**

```bash
mysql -u root -e "DROP DATABASE IF EXISTS kplev_football_manager; CREATE DATABASE kplev_football_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --force
python3 scripts/compare_databases.py fm26.sqlite3 "mysql://root@127.0.0.1:3306/kplev_football_manager"
```

Expected: `Identical`.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Mirror the reference importer in PHP

The host has no Python, so the reference has to be parsed there too. The two parsers
are held together the only way that works: CI builds the database both ways and
compares it row by row."
```

---

## Task 5: Tactic tables and the Python tactic importer

**Files:**
- Modify: `db/schema.sql`, `db/schema.mysql.sql`
- Create: `scripts/import_tactic.py`
- Modify: `scripts/import_json.py`
- Create: `scripts/verify_tactic.py`

**Interfaces:**
- Consumes: `data/saves/<slug>/tactics/*.json`
- Produces: `tactic_rows(path: Path, players: list[dict], known_codes: set[str]) -> dict[str, list[dict]]` in `scripts/import_tactic.py`, producing `tactics` 1, `tactic_slots` 11, `tactic_instructions` 29, `tactic_lineups` 22 rows for the committed tactic, all 22 lineup rows resolved to a `player_id`.

- [ ] **Step 1: Write the failing verifier**

Create `scripts/verify_tactic.py`:

```python
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
    ("the two sides of a centre-back pair share a position code",
     "SELECT COUNT(DISTINCT position_code) FROM tactic_slots WHERE slot IN ('DCL','DCR')", 1),
]


def main() -> None:
    failures = 0
    with sqlite3.connect(DB_PATH) as conn:
        for table, expected in EXPECTED_ROWS.items():
            actual = conn.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            failures += actual != expected
            print(f"{status} {table:<22} {actual:>3} (expected {expected})")

        for label, sql, expected in SPOT_CHECKS:
            actual = conn.execute(sql).fetchone()[0]
            status = "OK  " if actual == expected else "FAIL"
            failures += actual != expected
            print(f"{status} {label:<50} {actual} (expected {expected})")

    sys.exit(1 if failures else 0)


if __name__ == "__main__":
    main()
```

- [ ] **Step 1b: Run it and watch it fail**

```bash
python3 scripts/verify_tactic.py
```

Expected: FAIL with `sqlite3.OperationalError: no such table: tactics`.

- [ ] **Step 2: Add the four tables to the SQLite schema**

Append to `db/schema.sql`:

```sql
-- The tactic in use. Career data: dated like every other historical record, so a
-- changed tactic is a new row rather than an edit.

CREATE TABLE IF NOT EXISTS tactics (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    game_date TEXT NOT NULL,
    style_en TEXT,
    style_hu TEXT,
    mentality_en TEXT,
    mentality_hu TEXT,
    shape_ip TEXT,
    shape_oop TEXT,
    in_game_slot TEXT,
    source TEXT,
    notes TEXT,
    UNIQUE(name, game_date)
);

CREATE TABLE IF NOT EXISTS tactic_slots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tactic_id INTEGER NOT NULL,
    slot TEXT NOT NULL,
    position_code TEXT,
    ui_label TEXT,
    ip_role TEXT,
    oop_role TEXT,
    UNIQUE(tactic_id, slot),
    FOREIGN KEY(tactic_id) REFERENCES tactics(id)
);

CREATE TABLE IF NOT EXISTS tactic_instructions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tactic_id INTEGER NOT NULL,
    phase TEXT NOT NULL,
    group_name TEXT NOT NULL,
    instruction TEXT NOT NULL,
    value_en TEXT,
    value_hu TEXT,
    source TEXT,
    UNIQUE(tactic_id, phase, group_name, instruction),
    FOREIGN KEY(tactic_id) REFERENCES tactics(id)
);

CREATE TABLE IF NOT EXISTS tactic_lineups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tactic_id INTEGER NOT NULL,
    label TEXT NOT NULL,
    slot TEXT NOT NULL,
    player_id INTEGER,
    raw_label TEXT NOT NULL,
    UNIQUE(tactic_id, label, slot),
    FOREIGN KEY(tactic_id) REFERENCES tactics(id),
    FOREIGN KEY(player_id) REFERENCES players(id)
);

CREATE INDEX IF NOT EXISTS idx_tactic_slots_tactic ON tactic_slots(tactic_id);
CREATE INDEX IF NOT EXISTS idx_tactic_lineups_player ON tactic_lineups(player_id);
```

- [ ] **Step 3: Add the same four to the MySQL schema**

Append to `db/schema.mysql.sql`:

```sql
CREATE TABLE IF NOT EXISTS tactics (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(191) NOT NULL,
    game_date VARCHAR(32) NOT NULL,
    style_en VARCHAR(128),
    style_hu VARCHAR(128),
    mentality_en VARCHAR(64),
    mentality_hu VARCHAR(64),
    shape_ip TEXT,
    shape_oop TEXT,
    in_game_slot VARCHAR(128),
    source TEXT,
    notes TEXT,
    UNIQUE KEY uq_tactics (name, game_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tactic_slots (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tactic_id INT NOT NULL,
    slot VARCHAR(8) NOT NULL,
    position_code VARCHAR(8),
    ui_label VARCHAR(32),
    ip_role VARCHAR(128),
    oop_role VARCHAR(128),
    UNIQUE KEY uq_tactic_slots (tactic_id, slot),
    KEY idx_tactic_slots_tactic (tactic_id),
    CONSTRAINT fk_tactic_slots_tactic FOREIGN KEY (tactic_id) REFERENCES tactics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tactic_instructions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tactic_id INT NOT NULL,
    phase VARCHAR(20) NOT NULL,
    group_name VARCHAR(64) NOT NULL,
    instruction VARCHAR(128) NOT NULL,
    value_en VARCHAR(191),
    value_hu VARCHAR(191),
    source TEXT,
    UNIQUE KEY uq_tactic_instructions (tactic_id, phase, group_name, instruction),
    CONSTRAINT fk_tactic_instructions_tactic FOREIGN KEY (tactic_id) REFERENCES tactics(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tactic_lineups (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tactic_id INT NOT NULL,
    label VARCHAR(64) NOT NULL,
    slot VARCHAR(8) NOT NULL,
    player_id INT,
    raw_label VARCHAR(191) NOT NULL,
    UNIQUE KEY uq_tactic_lineups (tactic_id, label, slot),
    KEY idx_tactic_lineups_player (player_id),
    CONSTRAINT fk_tactic_lineups_tactic FOREIGN KEY (tactic_id) REFERENCES tactics(id),
    CONSTRAINT fk_tactic_lineups_player FOREIGN KEY (player_id) REFERENCES players(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Add the tactic tables to the import whitelist**

In `scripts/import_json.py`, add to `TABLES`:

```python
    "tactics": ["id", "name", "game_date", "style_en", "style_hu", "mentality_en",
                "mentality_hu", "shape_ip", "shape_oop", "in_game_slot", "source", "notes"],
    "tactic_slots": ["id", "tactic_id", "slot", "position_code", "ui_label",
                     "ip_role", "oop_role"],
    "tactic_instructions": ["id", "tactic_id", "phase", "group_name", "instruction",
                            "value_en", "value_hu", "source"],
    "tactic_lineups": ["id", "tactic_id", "label", "slot", "player_id", "raw_label"],
```

And insert into the `order` list in `main()`, between `"player_traits"` and `"matches"`:

```python
            "tactics", "tactic_slots", "tactic_instructions", "tactic_lineups",
```

- [ ] **Step 5: Write the tactic importer**

Create `scripts/import_tactic.py`:

```python
#!/usr/bin/env python3
"""Load a tactic file into the tactic tables.

A tactic belongs to a career, is dated like every other historical record, and is
joined to the squad through its observed line-ups. The parsing rules are mirrored in
mcp/tactic.php.

Usage:
    python3 scripts/import_tactic.py data/saves/<slug>/tactics/<file>.json
"""

from pathlib import Path
import json
import re
import sqlite3
import sys

ROOT = Path(__file__).resolve().parents[1]
DB_PATH = ROOT / "fm26.sqlite3"

LABEL = re.compile(r"^(?P<name>.*?)\s*\((?P<shirt>\d+)\)\s*$")


def position_code(slot: str, known_codes: set[str]) -> str | None:
    """A slot names a side as well as a position: DCL and DCR are both DC."""
    if slot in known_codes:
        return slot
    if len(slot) > 2 and slot[:-1] in known_codes:
        return slot[:-1]
    return None


def resolve_player(raw_label: str, players: list[dict]) -> int | None:
    """Match a line-up label to exactly one player, or to nobody.

    The shirt number decides when it identifies one player; otherwise the name has to.
    An ambiguous label is left unresolved rather than guessed.
    """
    match = LABEL.match(raw_label)
    name = match.group("name").strip() if match else raw_label.strip()
    shirt = int(match.group("shirt")) if match else None

    if shirt is not None:
        hits = [p for p in players if p["current_shirt_number"] == shirt]
        if len(hits) == 1:
            return hits[0]["id"]

    lowered = name.lower()
    hits = [p for p in players if lowered in p["name"].lower()]
    return hits[0]["id"] if len(hits) == 1 else None


def tactic_rows(path: Path, players: list[dict], known_codes: set[str]) -> dict:
    payload = json.loads(path.read_text(encoding="utf-8"))
    meta = payload.get("_meta", {})
    style = payload.get("style", {})
    shape = payload.get("shape", {})

    tactic = {
        "name": meta.get("name") or path.stem,
        "game_date": meta.get("game_date"),
        "style_en": style.get("tactical_style_en"),
        "style_hu": style.get("tactical_style_hu"),
        "mentality_en": style.get("mentality_en"),
        "mentality_hu": style.get("mentality_hu"),
        "shape_ip": shape.get("in_possession"),
        "shape_oop": shape.get("out_of_possession"),
        "in_game_slot": meta.get("in_game_tactic_slot"),
        "source": meta.get("source"),
        "notes": json.dumps(payload.get("asymmetries"), ensure_ascii=False)
                 if payload.get("asymmetries") else None,
    }

    slots = [{
        "slot": slot["slot"],
        "position_code": position_code(slot["slot"], known_codes),
        "ui_label": slot.get("ui_label"),
        "ip_role": (slot.get("in_possession") or {}).get("en"),
        "oop_role": (slot.get("out_of_possession") or {}).get("en"),
    } for slot in payload.get("slots", [])]

    instructions = []
    for phase, groups in (payload.get("team_instructions") or {}).items():
        if not isinstance(groups, dict):
            continue
        for group_name, items in groups.items():
            if not isinstance(items, dict):
                continue
            for instruction, value in items.items():
                instructions.append({
                    "phase": phase,
                    "group_name": group_name,
                    "instruction": instruction,
                    "value_en": value.get("en") if isinstance(value, dict) else str(value),
                    "value_hu": value.get("hu") if isinstance(value, dict) else None,
                    "source": meta.get("source"),
                })

    lineups = []
    for lineup in payload.get("observed_lineups", []):
        for slot, raw_label in (lineup.get("players") or {}).items():
            lineups.append({
                "label": lineup.get("label"),
                "slot": slot,
                "player_id": resolve_player(raw_label, players),
                "raw_label": raw_label,
            })

    return {
        "tactics": [tactic],
        "tactic_slots": slots,
        "tactic_instructions": instructions,
        "tactic_lineups": lineups,
    }


def main() -> None:
    if len(sys.argv) != 2:
        raise SystemExit("Usage: python3 scripts/import_tactic.py <tactic.json>")

    with sqlite3.connect(DB_PATH) as conn:
        conn.row_factory = sqlite3.Row
        conn.execute("PRAGMA foreign_keys = ON")
        players = [dict(r) for r in conn.execute(
            "SELECT id, name, current_shirt_number FROM players")]
        known_codes = {r[0] for r in conn.execute("SELECT code FROM fm_positions")}

        rows = tactic_rows(Path(sys.argv[1]), players, known_codes)

        tactic = rows["tactics"][0]
        columns = list(tactic)
        conn.execute(
            f'INSERT OR REPLACE INTO tactics ({",".join(columns)}) '
            f'VALUES ({",".join("?" for _ in columns)})',
            [tactic[c] for c in columns],
        )
        tactic_id = conn.execute(
            "SELECT id FROM tactics WHERE name = ? AND game_date = ?",
            (tactic["name"], tactic["game_date"]),
        ).fetchone()[0]

        for table in ("tactic_slots", "tactic_instructions", "tactic_lineups"):
            for row in rows[table]:
                row = {"tactic_id": tactic_id, **row}
                columns = list(row)
                conn.execute(
                    f'INSERT OR REPLACE INTO "{table}" ({",".join(columns)}) '
                    f'VALUES ({",".join("?" for _ in columns)})',
                    [row[c] for c in columns],
                )
            print(f"{table:<20} {len(rows[table]):>3} rows")
        conn.commit()


if __name__ == "__main__":
    main()
```

- [ ] **Step 6: Rebuild and load the tactic**

```bash
rm -f fm26.sqlite3
python3 scripts/init_db.py
python3 scripts/import_reference.py
python3 scripts/import_initial_snapshot.py
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
python3 scripts/import_tactic.py data/saves/valencia-2025-26/tactics/mestral.json
```

Expected: `tactic_slots 11 rows`, `tactic_instructions 29 rows`, `tactic_lineups 22 rows`.

Then run the verifier:

```bash
python3 scripts/verify_tactic.py
```

Expected: every line `OK`.

- [ ] **Step 7: Verify the join the tactic exists for**

```bash
python3 scripts/query.py "
SELECT s.slot, s.ip_role, s.oop_role, p.name
  FROM tactic_slots s
  LEFT JOIN tactic_lineups l ON l.tactic_id = s.tactic_id AND l.slot = s.slot AND l.label = 'line-up A'
  LEFT JOIN players p ON p.id = l.player_id
 ORDER BY s.id;"
python3 scripts/query.py "SELECT COUNT(*) AS unresolved FROM tactic_lineups WHERE player_id IS NULL;"
```

Expected: eleven rows each naming a player, and `unresolved 0`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Move the tactic from a file into dated tables

The tactic is career data, so it belongs with the career and not with the documents
that describe the game. In tables it can be joined to the squad that plays it and to
the role system that constrains it, and a change of tactic becomes a new dated row
instead of a file that overwrites its own history."
```

---

## Task 6: The PHP tactic importer

**Files:**
- Create: `mcp/tactic.php`
- Modify: `mcp/bootstrap.php`, `mcp/db.php`

**Interfaces:**
- Consumes: `fm_save_dir()`, `fm_reference_import()`
- Produces: `fm_tactic_import(PDO $pdo, string $path): array` in `mcp/tactic.php`, returning rows written per table.

- [ ] **Step 1: Write the failing comparison**

```bash
rm -f /tmp/php_ref.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/php_ref.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/php_ref.sqlite3
```

Expected: FAIL listing `tactics`, `tactic_slots`, `tactic_instructions`, `tactic_lineups` as `reference=N candidate=0`.

- [ ] **Step 2: Add the tactic tables to the PHP whitelist**

In `mcp/db.php`, add to the `fm_import_tables()` array:

```php
        'tactics' => [
            'id', 'name', 'game_date', 'style_en', 'style_hu', 'mentality_en',
            'mentality_hu', 'shape_ip', 'shape_oop', 'in_game_slot', 'source', 'notes',
        ],
        'tactic_slots' => ['id', 'tactic_id', 'slot', 'position_code', 'ui_label', 'ip_role', 'oop_role'],
        'tactic_instructions' => [
            'id', 'tactic_id', 'phase', 'group_name', 'instruction',
            'value_en', 'value_hu', 'source',
        ],
        'tactic_lineups' => ['id', 'tactic_id', 'label', 'slot', 'player_id', 'raw_label'],
```

And in `fm_import_order()`, between `'player_traits'` and `'matches'`:

```php
        'tactics', 'tactic_slots', 'tactic_instructions', 'tactic_lineups',
```

- [ ] **Step 3: Write `mcp/tactic.php`**

```php
<?php
/**
 * Parse a tactic file into rows for the tactic tables.
 *
 * A tactic belongs to a career and is dated like every other historical record. The
 * parsing rules are mirrored in scripts/import_tactic.py.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** A slot names a side as well as a position: DCL and DCR are both DC. */
function fm_tactic_position_code(string $slot, array $knownCodes): ?string
{
    if (in_array($slot, $knownCodes, true)) {
        return $slot;
    }
    $trimmed = substr($slot, 0, -1);
    if (strlen($slot) > 2 && in_array($trimmed, $knownCodes, true)) {
        return $trimmed;
    }

    return null;
}

/**
 * Match a line-up label to exactly one player, or to nobody. The shirt number decides
 * when it identifies one player; otherwise the name has to. An ambiguous label is left
 * unresolved rather than guessed.
 */
function fm_tactic_resolve_player(string $rawLabel, array $players): ?int
{
    $name = trim($rawLabel);
    $shirt = null;
    if (preg_match('/^(.*?)\s*\((\d+)\)\s*$/u', $rawLabel, $m)) {
        $name = trim($m[1]);
        $shirt = (int) $m[2];
    }

    if ($shirt !== null) {
        $hits = array_values(array_filter(
            $players,
            static fn ($p) => (int) $p['current_shirt_number'] === $shirt
        ));
        if (count($hits) === 1) {
            return (int) $hits[0]['id'];
        }
    }

    $lowered = mb_strtolower($name);
    $hits = array_values(array_filter(
        $players,
        static fn ($p) => str_contains(mb_strtolower((string) $p['name']), $lowered)
    ));

    return count($hits) === 1 ? (int) $hits[0]['id'] : null;
}

/** @return array<string, array<int, array<string, mixed>>> */
function fm_tactic_rows(string $path, array $players, array $knownCodes): array
{
    $payload = json_decode((string) file_get_contents($path), true);
    if (!is_array($payload)) {
        throw new FmMcpError('The tactic file is not valid JSON: ' . basename($path));
    }

    $meta = $payload['_meta'] ?? [];
    $style = $payload['style'] ?? [];
    $shape = $payload['shape'] ?? [];

    $tactic = [
        'name' => $meta['name'] ?? pathinfo($path, PATHINFO_FILENAME),
        'game_date' => $meta['game_date'] ?? null,
        'style_en' => $style['tactical_style_en'] ?? null,
        'style_hu' => $style['tactical_style_hu'] ?? null,
        'mentality_en' => $style['mentality_en'] ?? null,
        'mentality_hu' => $style['mentality_hu'] ?? null,
        'shape_ip' => $shape['in_possession'] ?? null,
        'shape_oop' => $shape['out_of_possession'] ?? null,
        'in_game_slot' => $meta['in_game_tactic_slot'] ?? null,
        'source' => $meta['source'] ?? null,
        'notes' => !empty($payload['asymmetries'])
            ? json_encode($payload['asymmetries'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null,
    ];

    $slots = [];
    foreach ($payload['slots'] ?? [] as $slot) {
        $slots[] = [
            'slot' => $slot['slot'],
            'position_code' => fm_tactic_position_code((string) $slot['slot'], $knownCodes),
            'ui_label' => $slot['ui_label'] ?? null,
            'ip_role' => $slot['in_possession']['en'] ?? null,
            'oop_role' => $slot['out_of_possession']['en'] ?? null,
        ];
    }

    $instructions = [];
    foreach ($payload['team_instructions'] ?? [] as $phase => $groups) {
        if (!is_array($groups)) {
            continue;
        }
        foreach ($groups as $groupName => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $instruction => $value) {
                $instructions[] = [
                    'phase' => (string) $phase,
                    'group_name' => (string) $groupName,
                    'instruction' => (string) $instruction,
                    'value_en' => is_array($value) ? ($value['en'] ?? null) : (string) $value,
                    'value_hu' => is_array($value) ? ($value['hu'] ?? null) : null,
                    'source' => $meta['source'] ?? null,
                ];
            }
        }
    }

    $lineups = [];
    foreach ($payload['observed_lineups'] ?? [] as $lineup) {
        foreach ($lineup['players'] ?? [] as $slot => $rawLabel) {
            $lineups[] = [
                'label' => $lineup['label'] ?? null,
                'slot' => (string) $slot,
                'player_id' => fm_tactic_resolve_player((string) $rawLabel, $players),
                'raw_label' => (string) $rawLabel,
            ];
        }
    }

    return [
        'tactics' => [$tactic],
        'tactic_slots' => $slots,
        'tactic_instructions' => $instructions,
        'tactic_lineups' => $lineups,
    ];
}

/** Load one tactic file. Returns rows written per table. */
function fm_tactic_import(PDO $pdo, string $path): array
{
    $players = $pdo->query('SELECT id, name, current_shirt_number FROM players')->fetchAll();
    $knownCodes = array_column($pdo->query('SELECT code FROM fm_positions')->fetchAll(), 'code');

    $rows = fm_tactic_rows($path, $players, $knownCodes);
    $replace = fm_driver() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';

    $tactic = $rows['tactics'][0];
    $columns = array_keys($tactic);
    $stmt = $pdo->prepare(
        $replace . ' ' . fm_ident('tactics') . ' (' . implode(',', array_map('fm_ident', $columns))
        . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
    );
    $stmt->execute(array_values($tactic));

    $lookup = $pdo->prepare('SELECT id FROM tactics WHERE name = ? AND game_date = ?');
    $lookup->execute([$tactic['name'], $tactic['game_date']]);
    $tacticId = (int) $lookup->fetchColumn();

    $written = ['tactics' => 1];
    foreach (['tactic_slots', 'tactic_instructions', 'tactic_lineups'] as $table) {
        foreach ($rows[$table] as $row) {
            $row = array_merge(['tactic_id' => $tacticId], $row);
            $columns = array_keys($row);
            $stmt = $pdo->prepare(
                $replace . ' ' . fm_ident($table) . ' (' . implode(',', array_map('fm_ident', $columns))
                . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
            );
            $stmt->execute(array_values($row));
        }
        $written[$table] = count($rows[$table]);
    }

    return $written;
}
```

- [ ] **Step 4: Load tactics during the bootstrap**

In `mcp/bootstrap.php`, add the require:

```php
require_once __DIR__ . '/tactic.php';
```

In `fm_source_files()`, exclude the tactics directory from the generic scan by adding this branch before the `nested` fallback:

```php
        } elseif (str_starts_with($relative, 'tactics/')) {
            continue;
```

In `fm_bootstrap()`, after the loop that imports the source files and before `$pdo->commit();`:

```php
        // Tactics load last: their line-ups resolve against the squad, which the files
        // above have just populated.
        foreach (glob(fm_save_dir() . '/tactics/*.json') ?: [] as $tacticFile) {
            $written = fm_tactic_import($pdo, $tacticFile);
            $lines[] = sprintf(
                '  tactic   %-52s %5d rows',
                basename($tacticFile),
                array_sum($written)
            );
        }
```

- [ ] **Step 5: Compare both builds**

```bash
rm -f /tmp/php_ref.sqlite3
php mcp/bootstrap.php --sqlite=/tmp/php_ref.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/php_ref.sqlite3
mysql -u root -e "DROP DATABASE IF EXISTS kplev_football_manager; CREATE DATABASE kplev_football_manager CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --force
python3 scripts/compare_databases.py fm26.sqlite3 "mysql://root@127.0.0.1:3306/kplev_football_manager"
```

Expected: `Identical` twice.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "Mirror the tactic importer in PHP

Tactics load after the squad, because their line-ups resolve against it. A label that
matches no single player stays unresolved rather than being guessed at."
```

---

## Task 7: Correct the renamed role and report the labels that disagree

**Files:**
- Modify: `data/saves/valencia-2025-26/initial_valencia_snapshot_2025-12-22.json.gz.b64`
- Create: `data/saves/valencia-2025-26/known_role_conflicts.json`
- Modify: `scripts/validate_roles.py`

**Interfaces:**
- Produces: `scripts/validate_roles.py` exits non-zero when a recorded role label is absent from `fm_roles` and absent from `known_role_conflicts.json`.

- [ ] **Step 1: Write the failing report**

Append to `scripts/validate_roles.py`, inside `main()` before its final success print:

```python
    conflicts_path = ROOT / "data" / "saves" / "valencia-2025-26" / "known_role_conflicts.json"
    accepted = {}
    if conflicts_path.is_file():
        accepted = json.loads(conflicts_path.read_text(encoding="utf-8"))

    with sqlite3.connect(DB_PATH) as conn:
        recorded = conn.execute("""
            SELECT r.role_text, r.phase, COUNT(*) AS rows
            FROM player_roles r
            LEFT JOIN fm_roles f ON f.role_name = r.role_text AND f.phase = r.phase
            WHERE f.id IS NULL
            GROUP BY r.role_text, r.phase
            ORDER BY r.role_text
        """).fetchall()

    unexplained = []
    for role_text, phase, rows in recorded:
        note = accepted.get(role_text)
        if note:
            print(f"KNOWN CONFLICT: {role_text} ({phase}, {rows} rows) - {note}")
        else:
            unexplained.append(f"{role_text} ({phase}, {rows} rows)")

    if unexplained:
        print("\nRecorded role labels that the reference does not contain and that are")
        print("not recorded as known conflicts:")
        for entry in unexplained:
            print("  ", entry)
        print("\nEither the label is a transcription error and belongs fixed at the source,")
        print("or the reference is wrong and the game wins. Record the decision in")
        print(f"{conflicts_path.relative_to(ROOT)}.")
        raise SystemExit(1)
```

Add `import json` and `import sqlite3` at the top of the file if they are not already imported, and make sure `ROOT` and `DB_PATH` are defined the way the other scripts define them.

- [ ] **Step 2: Run it and watch it fail**

```bash
python3 scripts/validate_roles.py
```

Expected: exit status 1, listing `Box-to-Box Midfielder`, `Box-to-Box Playmaker`, `Holding Midfielder` and `Wide Playmaker`.

- [ ] **Step 3: Apply the documented rename at the source**

The v4.1 changelog records `Wide Playmaker` as renamed to `Playmaking Winger` for AMR/AML, so the recorded label is the old name for a role that still exists.

Both rows are inside the compressed initial snapshot, so it has to be decoded, changed
and re-encoded. Write this to a file and run it, rather than pasting it into a shell
heredoc, because it contains one of its own:

```python
# scripts/tmp_rename_role.py - delete after running
import base64
import gzip
import json
from pathlib import Path

path = Path("data/saves/valencia-2025-26/initial_valencia_snapshot_2025-12-22.json.gz.b64")
payload = json.loads(gzip.decompress(base64.b64decode(path.read_text().strip())))

changed = 0
for row in payload.get("player_roles", []):
    if row.get("role_text") == "Wide Playmaker":
        row["role_text"] = "Playmaking Winger"
        changed += 1
assert changed == 2, f"expected the two pre-rename rows, found {changed}"

encoded = base64.b64encode(gzip.compress(json.dumps(payload, ensure_ascii=False).encode("utf-8")))
path.write_text(encoded.decode("ascii") + "\n", encoding="ascii")
print(f"{changed} rows renamed")
```

```bash
python3 scripts/tmp_rename_role.py && rm scripts/tmp_rename_role.py
```

The rename can collide: `UNIQUE(player_id, game_date, phase, position_text, role_text)`
merges a row into an existing one if that player already had `Playmaking Winger`
recorded for the same date and position. After the rebuild in Step 5, check the count —
97 rows means neither player had both names, 95 means both did and the merge is
correct. Record whichever happened in the commit message.

- [ ] **Step 4: Record the unresolved disagreement**

Create `data/saves/valencia-2025-26/known_role_conflicts.json`:

```json
{
  "Box-to-Box Midfielder": "Recorded for every defensive midfielder from a capture whose source screen was not noted. The reference's DM list has Defensive Midfielder, Deep-Lying Playmaker and Half Back instead. Unresolved until a player report for a defensive midfielder is captured.",
  "Box-to-Box Playmaker": "Recorded for every defensive midfielder from a capture whose source screen was not noted. The reference's DM list has Defensive Midfielder, Deep-Lying Playmaker and Half Back instead. Unresolved until a player report for a defensive midfielder is captured.",
  "Holding Midfielder": "Recorded for defensive midfielders from a capture whose source screen was not noted, and absent from both the allowed and the banned lists. Unresolved until a player report for a defensive midfielder is captured."
}
```

- [ ] **Step 5: Rebuild and confirm the report is clean but honest**

```bash
rm -f fm26.sqlite3
python3 scripts/init_db.py
python3 scripts/import_reference.py
python3 scripts/import_initial_snapshot.py
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
python3 scripts/import_tactic.py data/saves/valencia-2025-26/tactics/mestral.json
python3 scripts/validate_roles.py
```

Expected: exit status 0, three `KNOWN CONFLICT:` lines, and no `Wide Playmaker`.

- [ ] **Step 6: Confirm a new bad label still fails the build**

```bash
python3 - <<'PY'
import sqlite3
c = sqlite3.connect("fm26.sqlite3")
c.execute("INSERT INTO player_roles (player_id, game_date, phase, role_text, source) "
          "VALUES (1, '2026-01-10', 'IP', 'Mezzala', 'validation probe')")
c.commit()
PY
python3 scripts/validate_roles.py; echo "exit: $?"
python3 -c "
import sqlite3
c = sqlite3.connect('fm26.sqlite3')
c.execute(\"DELETE FROM player_roles WHERE source = 'validation probe'\"); c.commit()"
```

Expected: the probe run prints `Mezzala` under the unexplained heading and exits 1.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Reconcile recorded role labels with the reference

One label was the pre-rename name of a role that still exists and is corrected at the
source. Three others disagree with the reference in a way the data cannot settle: they
stay as recorded facts, are listed as known conflicts, and are printed on every run so
the disagreement is visible. A label that is neither legal nor recorded as a known
conflict now fails the build."
```

---

## Task 8: The `reference` tool reads the database, and gains keyword search

**Files:**
- Modify: `mcp/tools.php`, `mcp/server.php`

**Interfaces:**
- Consumes: `fm_reference` and `fm_roles` from Task 3.
- Produces: `reference` accepts `{document?, section?, search?}`; `list_tables` rows gain `"scope": "reference"|"save"`.

- [ ] **Step 1: Write the failing selftest checks**

In `mcp/server.php`, inside `fm_selftest()` before the unknown-method check, add:

```php
        // The reference now comes from the database, so the selftest database needs it.
        fm_reference_import(fm_pdo_rw());

        $searchCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 20,
            'method' => 'tools/call',
            'params' => ['name' => 'reference', 'arguments' => ['search' => 'Poacher']],
        ]);
        $search = json_decode($searchCall['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'reference search finds a section by keyword',
            ($search['match_count'] ?? 0) > 0
                && str_contains($search['matches'][0]['path'] ?? '', 'allowed_roles_index')
        );

        $sectionCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 21,
            'method' => 'tools/call',
            'params' => [
                'name' => 'reference',
                'arguments' => [
                    'document' => 'fm26_ai_system_prompt_v4',
                    'section' => 'FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles.allowed_roles_index.ST',
                ],
            ],
        ]);
        $section = json_decode($sectionCall['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'reference reads a section out of the database',
            ($section['source'] ?? '') === 'database'
                && isset($section['content']['in_possession'])
        );

        $scopeCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 22,
            'method' => 'tools/call',
            'params' => ['name' => 'list_tables', 'arguments' => []],
        ]);
        $scoped = json_decode($scopeCall['result']['content'][0]['text'] ?? '{}', true);
        $scopes = [];
        foreach ($scoped['tables'] ?? [] as $entry) {
            $scopes[$entry['table']] = $entry['scope'] ?? null;
        }
        $check(
            'list_tables says which side of the boundary a table is on',
            ($scopes['fm_roles'] ?? '') === 'reference' && ($scopes['players'] ?? '') === 'save'
        );
```

Add `require_once __DIR__ . '/reference.php';` to `mcp/server.php` alongside the other requires.

- [ ] **Step 2: Run the selftest and watch the new checks fail**

```bash
php mcp/server.php --selftest
```

Expected: the three new lines report FAIL; the rest still PASS.

- [ ] **Step 3: Rewrite the reference reader against the database**

In `mcp/tools.php`, replace `fm_reference_documents()`, `fm_reference_get()` and `fm_reference_outline()` with:

```php
/** The reference documents present in the database. */
function fm_reference_documents(): array
{
    $pdo = fm_pdo_ro();
    $rows = $pdo->query('SELECT document, COUNT(*) AS sections FROM fm_reference GROUP BY document ORDER BY document')
        ->fetchAll();
    if (fm_driver() === 'mysql' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return $rows;
}

/**
 * Read a reference document, drill into a dot-separated section path, or search the
 * whole reference for a keyword.
 */
function fm_reference_get(?string $document, ?string $section, ?string $search): array
{
    $pdo = fm_pdo_ro();

    try {
        if ($search !== null && $search !== '') {
            $stmt = $pdo->prepare(
                'SELECT document, path, title, text FROM fm_reference
                  WHERE text LIKE ? ORDER BY LENGTH(path), path LIMIT 25'
            );
            $stmt->execute(['%' . $search . '%']);
            $matches = [];
            foreach ($stmt->fetchAll() as $row) {
                $position = stripos($row['text'], $search);
                $matches[] = [
                    'document' => $row['document'],
                    'path' => $row['path'],
                    'title' => $row['title'],
                    'excerpt' => mb_substr($row['text'], max(0, $position - 60), 240),
                ];
            }

            return [
                'search' => $search,
                'match_count' => count($matches),
                'matches' => $matches,
                'note' => 'Request one of these paths as "section" to read it in full.',
            ];
        }

        if ($document === null || $document === '') {
            return [
                'documents' => fm_reference_documents(),
                'note' => 'Call again with "document" and a dot-separated "section" path, '
                    . 'or with "search" to find a section by keyword.',
            ];
        }

        $path = $section !== null && $section !== '' ? $section : $document;
        $stmt = $pdo->prepare('SELECT text FROM fm_reference WHERE document = ? AND path = ?');
        $stmt->execute([$document, $path]);
        $text = $stmt->fetchColumn();

        if ($text === false) {
            $stmt = $pdo->prepare(
                'SELECT path FROM fm_reference WHERE document = ? ORDER BY LENGTH(path), path LIMIT 40'
            );
            $stmt->execute([$document]);
            throw new FmMcpError(
                sprintf(
                    'No section "%s" in "%s". Available paths include: %s',
                    $path,
                    $document,
                    implode(', ', array_column($stmt->fetchAll(), 'path'))
                ),
                -32602
            );
        }

        $decoded = json_decode((string) $text, true);
        $encoded = (string) $text;
        if (strlen($encoded) > FM_REFERENCE_MAX_CHARS) {
            $stmt = $pdo->prepare(
                'SELECT path, title, LENGTH(text) AS size_chars FROM fm_reference
                  WHERE document = ? AND path LIKE ? AND path <> ? ORDER BY path'
            );
            $stmt->execute([$document, $path . '.%', $path]);

            return [
                'document' => $document,
                'section' => $path,
                'source' => 'database',
                'truncated' => true,
                'sections' => $stmt->fetchAll(),
                'note' => sprintf(
                    'This section is %d characters, above the %d character response limit. '
                    . 'Request one of the listed paths instead.',
                    strlen($encoded),
                    FM_REFERENCE_MAX_CHARS
                ),
            ];
        }

        return [
            'document' => $document,
            'section' => $path,
            'source' => 'database',
            'truncated' => false,
            'content' => $decoded,
        ];
    } finally {
        if (fm_driver() === 'mysql' && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
```

- [ ] **Step 4: Update the tool definition and the handler**

In `fm_tool_definitions()`, replace the `reference` `inputSchema` properties with:

```php
                'properties' => [
                    'document' => [
                        'type' => 'string',
                        'description' => 'Document name from the catalogue. Omit to list what is available.',
                    ],
                    'section' => [
                        'type' => 'string',
                        'description' => 'Dot-separated path into the document, e.g. "A.B.C". Omit for the whole document.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' => 'Keyword to find across the whole reference. Returns paths with excerpts.',
                    ],
                ],
```

Replace the `$documentList` line at the top of `fm_tool_definitions()` with a query-free constant, because the catalogue now needs a database connection that `tools/list` should not depend on:

```php
    $documentList = 'fm26_ai_system_prompt_v4, fm26_role_locale_hu';
```

In `fm_call_tool()`, replace the `reference` case body's final line:

```php
            return fm_tool_result(fm_reference_get($document, $section, $arguments['search'] ?? null));
```

- [ ] **Step 5: Add the scope to `list_tables`**

In `mcp/db.php`, in `fm_list_tables()`, add to the array pushed onto `$tables`:

```php
            'scope' => str_starts_with($name, 'fm_') ? 'reference' : 'save',
```

- [ ] **Step 6: Run the selftest and confirm everything passes**

```bash
php mcp/server.php --selftest
```

Expected: every line PASS, including the three new checks.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "Serve the reference out of the database and make it searchable

The rules and the career now answer to the same interface, so a role can be checked
against the position that allows it in one query. A keyword search returns section
paths with excerpts, and list_tables says which side of the boundary each table is on."
```

---

## Task 9: Writes through the connector survive a rebuild

**Files:**
- Modify: `mcp/tools.php`, `mcp/bootstrap.php`, `mcp/server.php`

**Interfaces:**
- Consumes: `fm_save_dir()` from Task 1.
- Produces: `fm_persist_import(array $payload): string` in `mcp/tools.php`, returning the relative path it wrote. `bootstrap.php?token=<secret>&pull=1` returns the accumulated files as one JSON document.

- [ ] **Step 1: Write the failing selftest check**

In `mcp/server.php`, inside `fm_selftest()`, after the existing `import_json` check:

```php
        $incomingDir = fm_save_dir() . '/incoming';
        $before = count(glob($incomingDir . '/*.json') ?: []);
        fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 23,
            'method' => 'tools/call',
            'params' => [
                'name' => 'import_json',
                'arguments' => ['payload' => ['teams' => [['id' => 2, 'name' => 'Persisted FC']]]],
            ],
        ]);
        $after = glob($incomingDir . '/*.json') ?: [];
        $persisted = count($after) === $before + 1;
        if ($persisted) {
            $written = json_decode((string) file_get_contents(end($after)), true);
            $persisted = ($written['teams'][0]['name'] ?? '') === 'Persisted FC';
        }
        $check('an import is also written to the save directory', $persisted);
```

The selftest sets `repo_root` to a temporary directory so this writes nowhere near the
repository. Change the `fm_config_set()` call inside `fm_selftest()` to:

```php
        'repo_root' => $dir,
        'active_save' => 'selftest',
```

and create the directories it now needs, right after `mkdir($dir, 0700, true)`:

```php
    mkdir($dir . '/data/saves/selftest/incoming', 0700, true);
    mkdir($dir . '/db', 0700, true);
    copy(dirname(__DIR__) . '/db/schema.sql', $dir . '/db/schema.sql');
```

and change the schema path used by the selftest from `dirname(__DIR__) . '/db/schema.sql'` to `$dir . '/db/schema.sql'`.

- [ ] **Step 2: Run the selftest and watch it fail**

```bash
php mcp/server.php --selftest
```

Expected: `an import is also written to the save directory   FAIL`.

- [ ] **Step 3: Persist every import**

In `mcp/tools.php`, add:

```php
/**
 * Write an import payload into the active save directory.
 *
 * The database is generated from the committed files, so anything written only to the
 * database is lost the next time it is rebuilt. Keeping a copy alongside the sources
 * keeps that promise true for data that arrives through the connector.
 *
 * @return string the path written, relative to the repository root
 */
function fm_persist_import(array $payload): string
{
    $directory = fm_save_dir() . '/incoming';
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new FmMcpError("Cannot create the import directory {$directory}.");
    }

    $stamp = gmdate('Ymd-His');
    $sequence = 1;
    do {
        $path = sprintf('%s/%s-%02d.json', $directory, $stamp, $sequence);
        $sequence++;
    } while (file_exists($path) && $sequence < 100);

    $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($path, $encoded . "\n", LOCK_EX) === false) {
        throw new FmMcpError("Cannot write the import copy to {$path}.");
    }

    return ltrim(str_replace(fm_config()['repo_root'], '', $path), '/');
}
```

In `fm_call_tool()`'s `import_json` case, after `$written = fm_import_transactional($payload);`:

```php
            $persistedAs = fm_persist_import($payload);
```

and add to the returned array:

```php
                'persisted_as' => $persistedAs,
```

- [ ] **Step 4: Run the selftest and confirm it passes**

```bash
php mcp/server.php --selftest
```

Expected: every line PASS.

- [ ] **Step 5: Add the retrieval endpoint**

In `mcp/bootstrap.php`, in the web section after the `trace` handler:

```php
// The files written by import_json since the last commit. The sync only runs from the
// working copy to the host, so they have to be fetched deliberately.
if (($_GET['pull'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $files = glob(fm_save_dir() . '/incoming/*.json') ?: [];
    sort($files);
    $bundle = ['save' => fm_active_save(), 'files' => []];
    foreach ($files as $file) {
        $bundle['files'][] = [
            'name' => basename($file),
            'payload' => json_decode((string) file_get_contents($file), true),
        ];
    }
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}
```

- [ ] **Step 6: Verify the endpoint locally**

```bash
mkdir -p data/saves/valencia-2025-26/incoming
cat > data/saves/valencia-2025-26/incoming/20260816-120000-01.json <<'JSON'
{"tactical_observations": [{"category": "pull probe", "observation": "written by the probe", "confidence": "confirmed", "source": "probe"}]}
JSON
FM26_CONFIG=mcp/config.local.php php -r '
$_GET = ["pull" => "1"];
$_SERVER["REQUEST_METHOD"] = "GET";
require "mcp/db.php";
$files = glob(fm_save_dir()."/incoming/*.json");
echo count($files), " file(s): ", implode(", ", array_map("basename", $files)), PHP_EOL;'
rm -f data/saves/valencia-2025-26/incoming/20260816-120000-01.json
```

Expected: `1 file(s): 20260816-120000-01.json`.

- [ ] **Step 7: Ignore the incoming directory's contents until they are committed deliberately**

Add to `.gitignore`:

```
# Written on the host by import_json; retrieved with bootstrap.php?pull=1 and committed
# deliberately into the save directory.
data/saves/*/incoming/
```

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Keep connector writes alongside the sources they belong with

The database is generated, so data written only into it disappears at the next
rebuild - which is exactly what happened during testing. Every import now also lands
as a file in the active save, the rebuild replays those files last, and a retrieval
endpoint brings them back for committing, since the sync only runs one way."
```

---

## Task 10: Reset a save without touching the reference, and wire up CI and docs

**Files:**
- Modify: `mcp/bootstrap.php`, `mcp/db.php`, `.github/workflows/validate.yml`, `README.md`, `CLAUDE.md`, `mcp/README.md`

**Interfaces:**
- Produces: `fm_save_tables(PDO $pdo): array` in `mcp/db.php` — table names not starting with `fm_`. `bootstrap.php --reset` and `?confirm=reset` drop and rebuild only those.

- [ ] **Step 1: Write the failing check**

Create the check as a shell probe to be reused in CI:

```bash
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --force >/dev/null
BEFORE=$(FM26_CONFIG=mcp/config.local.php php -r '
require "mcp/db.php";
$pdo = fm_pdo_rw();
$n = 0;
foreach (fm_table_names($pdo) as $t) {
    if (str_starts_with($t, "fm_")) {
        $n += (int) $pdo->query("SELECT COUNT(*) FROM " . fm_ident($t))->fetchColumn();
    }
}
echo $n;')
echo "reference rows before: $BEFORE"
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --reset
```

Expected: FAIL — `--reset` is not recognised, the usage message appears.

- [ ] **Step 2: Add the table-scope helper**

In `mcp/db.php`, after `fm_table_names()`:

```php
/** The tables that belong to the career rather than to the FM26 reference. */
function fm_save_tables(PDO $pdo): array
{
    return array_values(array_filter(
        fm_table_names($pdo),
        static fn (string $name): bool => !str_starts_with($name, 'fm_')
    ));
}
```

- [ ] **Step 3: Implement the reset**

In `mcp/bootstrap.php`, add a `$resetOnly` parameter to `fm_bootstrap()`:

```php
function fm_bootstrap(bool $force, bool $resetOnly = false): array
```

Inside the MySQL branch, replace the drop-everything block with:

```php
        $pdo = fm_pdo_rw();
        $existing = $resetOnly ? fm_save_tables($pdo) : fm_table_names($pdo);
        if ($existing !== [] && !$force && !$resetOnly) {
            throw new FmMcpError(sprintf(
                'The database %s already holds %d table(s). Pass --force (CLI) or &force=1 (HTTP) to rebuild it.',
                $config['mysql']['database'],
                count($existing)
            ));
        }
        if ($existing !== []) {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($existing as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . fm_ident($table));
            }
            $lines[] = sprintf(
                'Dropped %d %s table(s)',
                count($existing),
                $resetOnly ? 'career' : 'existing'
            );
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (fm_split_statements((string) file_get_contents($schemaPath)) as $statement) {
            $pdo->exec($statement);
        }
```

Guard the reference import so a reset leaves it alone:

```php
    if (!$resetOnly) {
        foreach (fm_reference_import($pdo) as $table => $count) {
            $lines[] = sprintf('  reference %-52s %5d rows', $table, $count);
        }
    } else {
        $lines[] = 'Reference left untouched';
    }
```

Add the CLI flag next to `--force`:

```php
    $resetOnly = in_array('--reset', $argv ?? [], true);
```

and pass it: `fm_bootstrap($force, $resetOnly)`. Add the HTTP branch beside `confirm=rebuild`:

```php
$confirm = $_GET['confirm'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($confirm, ['rebuild', 'reset'], true)) {
```

and call `fm_bootstrap(($_GET['force'] ?? '') === '1', $confirm === 'reset')`.

- [ ] **Step 4: Run the reset and confirm the reference survived**

```bash
FM26_CONFIG=mcp/config.local.php php mcp/bootstrap.php --reset | tail -5
AFTER=$(FM26_CONFIG=mcp/config.local.php php -r '
require "mcp/db.php";
$pdo = fm_pdo_rw();
$n = 0;
foreach (fm_table_names($pdo) as $t) {
    if (str_starts_with($t, "fm_")) {
        $n += (int) $pdo->query("SELECT COUNT(*) FROM " . fm_ident($t))->fetchColumn();
    }
}
echo $n;')
echo "reference rows after reset: $AFTER"
python3 scripts/compare_databases.py fm26.sqlite3 "mysql://root@127.0.0.1:3306/kplev_football_manager"
```

Expected: `Reference left untouched`, the two counts equal (735), and `Identical`.

- [ ] **Step 5: Add the CI steps**

In `.github/workflows/validate.yml`, in the `mcp` job, after `Build the database on MySQL`:

```yaml
      - name: Verify the reference loaded completely
        run: |
          python3 scripts/init_db.py
          python3 scripts/import_reference.py
          python3 scripts/verify_reference.py

      - name: A save reset leaves the reference alone
        env:
          FM26_CONFIG: mcp/config.ci.php
        run: |
          before=$(php -r '
            require "mcp/db.php";
            $pdo = fm_pdo_rw(); $n = 0;
            foreach (fm_table_names($pdo) as $t) {
              if (str_starts_with($t, "fm_")) { $n += (int) $pdo->query("SELECT COUNT(*) FROM " . fm_ident($t))->fetchColumn(); }
            }
            echo $n;')
          php mcp/bootstrap.php --reset
          after=$(php -r '
            require "mcp/db.php";
            $pdo = fm_pdo_rw(); $n = 0;
            foreach (fm_table_names($pdo) as $t) {
              if (str_starts_with($t, "fm_")) { $n += (int) $pdo->query("SELECT COUNT(*) FROM " . fm_ident($t))->fetchColumn(); }
            }
            echo $n;')
          echo "reference rows: $before -> $after"
          test "$before" = "$after"
          test "$before" -gt 0
          php -r '
            require "mcp/db.php";
            $pdo = fm_pdo_ro();
            $players = (int) $pdo->query("SELECT COUNT(*) FROM players")->fetchColumn();
            if ($players !== 27) { fwrite(STDERR, "the career did not reload: $players players\n"); exit(1); }
            echo "career reloaded: $players players\n";'
```

Add the tactic and role steps after the Python build step:

```yaml
      - name: Load the tactic and check the recorded roles
        run: |
          python3 scripts/import_reference.py
          python3 scripts/import_tactic.py data/saves/valencia-2025-26/tactics/mestral.json
          python3 scripts/verify_tactic.py
          python3 scripts/validate_roles.py
          python3 scripts/verify_reference.py
```

- [ ] **Step 6: Update the documentation**

In `README.md`, in "Repository layout", replace the `data/` block with the layout from the spec, and add after the "Deployment" section:

```markdown
## Switching careers

The database holds exactly one career. The others stay in the repository, unloaded.

1. Put the new career's files in `data/saves/<slug>/`.
2. Set `'active_save' => '<slug>'` in `mcp/config.php`.
3. Rebuild: `POST https://fm.kplev.hu/mcp/bootstrap.php?token=<secret>&confirm=rebuild&force=1`.

`&confirm=reset` drops and reloads only the career tables and leaves the `fm_` tables —
the FM26 rules — untouched. Nothing in the MCP connector can switch or delete a career;
that needs the capability token.
```

In `CLAUDE.md`, add to the hard rules:

```markdown
9. **`fm_` tables are the FM26 rules, unprefixed tables are the career.** A save reset
   drops exactly the unprefixed ones. Never add career data to an `fm_` table, and never
   put a rule that is true of the game into a career table.
```

In `mcp/README.md`, add `search` to the `reference` row of the tools table and document
`&confirm=reset` and `&pull=1` beside the existing bootstrap parameters.

- [ ] **Step 7: Run everything one last time**

```bash
php mcp/server.php --selftest
rm -f fm26.sqlite3 /tmp/check.sqlite3
python3 scripts/init_db.py
python3 scripts/import_reference.py
python3 scripts/import_initial_snapshot.py
python3 scripts/import_json.py data/saves/valencia-2025-26/supplemental/filip_ugrinic_2025-12-22.json
python3 scripts/import_json.py data/saves/valencia-2025-26/season_2025-26_matches_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/player_umar_sadiq_2026-01-07.json
python3 scripts/import_json.py data/saves/valencia-2025-26/match_barcelona_away_2026-01-10.json
python3 scripts/import_tactic.py data/saves/valencia-2025-26/tactics/mestral.json
php mcp/bootstrap.php --sqlite=/tmp/check.sqlite3 --force
python3 scripts/compare_databases.py fm26.sqlite3 /tmp/check.sqlite3
python3 scripts/verify_db.py
python3 scripts/validate.py
python3 scripts/validate_roles.py
python3 scripts/verify_reference.py
python3 scripts/verify_tactic.py
```

Expected: selftest all pass, `Identical`, `FM26 database verification OK`, six `OK:` lines, three `KNOWN CONFLICT:` lines with exit 0, and every reference and tactic count `OK`.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "Reset a career without disturbing the rules that outlive it

A reset drops exactly the unprefixed tables and reloads the career; the fm_ tables are
neither dropped nor rewritten, and CI counts their rows on both sides of a reset to
prove it. Switching careers is a directory, a setting and a rebuild."
```

---

## Deployment

Nothing in this plan touches the host. After the work is merged, the sync carries the
files up; the host then needs one rebuild so the new tables exist:

```bash
curl -X POST 'https://fm.kplev.hu/mcp/bootstrap.php?token=<secret>&confirm=rebuild&force=1'
```

Verify against the live endpoint afterwards: `tools/list` shows five tools, `reference`
with `search` returns matches, and a `query` joining `player_roles` to `fm_roles`
returns the three known conflicts and nothing else.

## Deferred

- **The defensive midfielder role list.** One screenshot of a player report settles
  whether the reference or the recorded labels are wrong. Until it exists, the
  disagreement stays visible in `known_role_conflicts.json` and in every validation run.
- **Out-of-possession roles per player.** No source records them today; only the tactic
  assigns an OOP role per slot. Recording them needs new captures, not new code.
