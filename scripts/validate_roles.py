#!/usr/bin/env python3
"""Validate the FM26 role reference (data/fm26_ai_system_prompt_v4.json).

Checks:
  1. Every position code in _selection_rules.position_codes exists in allowed_roles_index.
  2. Every descriptive 'positions' block matches allowed_roles_index for each code
     it applies to, in BOTH phases, character-for-character.
  3. No banned legacy role name appears anywhere in allowed_roles_index.
  4. No duplicate role strings within a single position/phase list.
"""

from pathlib import Path
import json
import re
import sys

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data" / "fm26_ai_system_prompt_v4.json"


def banned_names(prompt):
    """Extract the bare legacy role names from the banned list entries."""
    names = set()
    for entry in prompt["0_critical_fm26_changes"]["banned_legacy_role_names"]:
        head = entry.split("(")[0].split(" - ")[0]
        for part in re.split(r",", head):
            part = part.strip()
            if part:
                names.add(part)
    return names


def main() -> None:
    prompt = json.loads(DATA.read_text(encoding="utf-8"))["FM26_AI_SYSTEM_PROMPT"]
    section = prompt["2_pitch_positions_and_roles"]
    index = section["allowed_roles_index"]
    positions = section["positions"]
    codes = section["_selection_rules"]["position_codes"]

    failures = []

    # 1. every declared code is present in the index
    for code in codes:
        if code not in index:
            failures.append(f"position code {code} missing from allowed_roles_index")

    indexed = [k for k in index if not k.startswith("_")]
    for code in indexed:
        if code not in codes:
            failures.append(f"allowed_roles_index has {code}, not in position_codes")

    # 2. descriptive blocks must match the index exactly
    phase_key = {
        "in_possession": "in_possession_roles",
        "out_of_possession": "out_of_possession_roles",
    }
    for block_name, block in positions.items():
        for code in block["applies_to"]:
            if code not in index:
                failures.append(f"{block_name}.applies_to references unknown code {code}")
                continue
            for phase, desc_key in phase_key.items():
                described = [r["role"] for r in block[desc_key]]
                allowed = index[code][phase]
                if described != allowed:
                    failures.append(
                        f"{code}/{phase}: descriptive block '{block_name}' does not match "
                        f"allowed_roles_index\n     described: {described}\n     indexed:   {allowed}"
                    )

    # 3. no banned legacy names in the index
    banned = banned_names(prompt)
    for code in indexed:
        for phase in ("in_possession", "out_of_possession"):
            for role in index[code][phase]:
                if role in banned:
                    failures.append(f"{code}/{phase}: banned legacy role '{role}'")

    # 4. no duplicates inside a single list
    for code in indexed:
        for phase in ("in_possession", "out_of_possession"):
            roles = index[code][phase]
            dupes = {r for r in roles if roles.count(r) > 1}
            if dupes:
                failures.append(f"{code}/{phase}: duplicate role(s) {sorted(dupes)}")

    total = sum(
        len(index[c][p]) for c in indexed for p in ("in_possession", "out_of_possession")
    )
    if failures:
        print("FM26 role reference validation FAILED")
        for f in failures:
            print("  -", f)
        sys.exit(1)

    print(
        f"FM26 role reference OK - {len(indexed)} position codes, "
        f"{total} role slots, {len(banned)} banned legacy names enforced"
    )


if __name__ == "__main__":
    main()
