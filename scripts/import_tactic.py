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


def _encode(node) -> str:
    """Encode a node the way mcp/tactic.php does, so the two rows compare equal."""
    return json.dumps(node, ensure_ascii=False, separators=(",", ":"))


def position_code(slot: str, known_codes) -> str:
    """A slot names a side as well as a position: DCL and DCR are both DC."""
    if slot in known_codes:
        return slot
    if len(slot) > 2 and slot[:-1] in known_codes:
        return slot[:-1]
    return None


def resolve_player(raw_label: str, players: list) -> int:
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


def tactic_rows(path: Path, players: list, known_codes) -> dict:
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
        "notes": _encode(payload["asymmetries"]) if payload.get("asymmetries") else None,
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
