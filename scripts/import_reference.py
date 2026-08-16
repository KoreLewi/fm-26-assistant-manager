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

# Deleted parent last, so a foreign key never dangles mid-way.
DELETE_ORDER = ("fm_reference", "fm_role_locale", "fm_instructions",
                "fm_styles", "fm_banned_roles", "fm_roles", "fm_positions")


def _load(name: str, reference_dir: Path) -> dict:
    return json.loads((reference_dir / f"{name}.json").read_text(encoding="utf-8"))


def _positions_and_roles(prompt: dict) -> tuple:
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


def _banned_roles(prompt: dict) -> list:
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


def _styles(prompt: dict) -> list:
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


def _instructions(prompt: dict) -> list:
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


def _role_locale(locale: dict) -> list:
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


def _reference_sections(document: str, payload: dict) -> list:
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


def reference_rows(reference_dir: Path = REFERENCE_DIR) -> dict:
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
        for table in DELETE_ORDER:
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
