#!/usr/bin/env python3
"""Rebuild the FM26 role reference from screenshot-verified in-game role lists.

Only position codes with screenshot evidence are changed. WBR, WBL, MR, ML and AMC
are left untouched because no in-game list has been captured for them yet.
Both the descriptive 'positions' blocks and the flat 'allowed_roles_index' are
rewritten together so scripts/validate_roles.py stays green.
"""

from pathlib import Path
import json

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "data" / "reference" / "fm26_ai_system_prompt_v4.json"

# Verified in-game lists, in the exact order the game displays them.
VERIFIED = {
    "GK": {
        "in_possession": ["Goalkeeper", "Ball-Playing Goalkeeper", "No-Nonsense Goalkeeper"],
        "out_of_possession": ["Goalkeeper", "Sweeper Keeper", "Line-Holding Keeper"],
    },
    "DC": {
        "in_possession": [
            "Centre-Back", "Advanced Centre-Back", "Ball-Playing Centre-Back",
            "No-Nonsense Centre-Back", "Overlapping Centre-Back", "Wide Centre-Back",
        ],
        "out_of_possession": [
            "Centre-Back", "Stopping Centre-Back", "Covering Centre-Back",
            "Covering Wide Centre-Back", "Stopping Wide Centre-Back",
        ],
    },
    "DR": {
        "in_possession": [
            "Full-Back", "Wing-Back", "Inside Wing-Back",
            "Inside Full-Back", "Playmaking Wing-Back",
        ],
        "out_of_possession": ["Full-Back", "Pressing Full-Back", "Holding Full-Back"],
    },
    "DM": {
        "in_possession": ["Defensive Midfielder", "Deep-Lying Playmaker", "Half Back"],
        "out_of_possession": [
            "Defensive Midfielder", "Dropping Defensive Midfielder",
            "Screening Defensive Midfielder",
        ],
    },
    "MC": {
        "in_possession": [
            "Central Midfielder", "Attacking Midfielder", "Advanced Playmaker",
            "Wide Central Midfielder", "Channel Midfielder", "Midfield Playmaker",
        ],
        "out_of_possession": [
            "Central Midfielder", "Pressing Central Midfielder",
            "Screening Central Midfielder", "Wide Covering Central Midfielder",
        ],
    },
    "AMR": {
        "in_possession": [
            "Winger", "Inside Forward", "Playmaking Winger",
            "Wide Forward", "Inside Winger",
        ],
        "out_of_possession": [
            "Winger", "Tracking Winger", "Inside Outlet Winger", "Wide Outlet Winger",
        ],
    },
    "ST": {
        "in_possession": [
            "Deep-Lying Forward", "Centre Forward", "Target Forward",
            "Poacher", "Channel Forward", "False Nine",
        ],
        "out_of_possession": [
            "Centre Forward", "Tracking Centre Forward", "Central Outlet Centre Forward",
        ],
    },
}
VERIFIED["DL"] = VERIFIED["DR"]
VERIFIED["AML"] = VERIFIED["AMR"]

# Descriptions for roles that did not previously exist under that code.
NEW_TEXT = {
    "Goalkeeper|out_of_possession": {
        "does": "Standard goalkeeping - defends the goal without an explicit sweeping or line-holding bias.",
        "best_for": "Neutral default when neither sweeping nor strict line-holding is wanted.",
    },
    "Defensive Midfielder|out_of_possession": {
        "does": "Standard defensive-midfield work without an explicit screening, dropping or pressing bias.",
        "best_for": "Neutral default at the base of midfield.",
    },
    "Central Midfielder|out_of_possession": {
        "does": "Standard central-midfield defending without an explicit pressing, screening or wide-covering bias.",
        "best_for": "Neutral default in a midfield two or three.",
    },
    "Winger|out_of_possession": {
        "does": "Defends the wide areas, balancing higher support with tracking back as needed to help the full-back behind him.",
        "best_for": "Wide balance between a tracking winger and a permanently high outlet.",
    },
    "Centre Forward|out_of_possession": {
        "does": "Standard forward defending - neither dropping into the block nor held permanently high as an outlet.",
        "best_for": "Neutral default for the lone striker.",
    },
    "Half Back|in_possession": {
        "does": "Drops between the centre-backs in build-up to form a back three.",
        "best_for": "Splitting CBs and inviting fullbacks high.",
    },
    "Playmaking Winger|in_possession": {
        "does": "Cuts inside and creates - an expressive wide creator rather than a pure goal threat.",
        "best_for": "Creativity from wide; build-around-a-star wide systems.",
    },
}

FORMATION_NOTE = {
    "DC": "Overlapping Centre-Back, Wide Centre-Back, Covering Wide Centre-Back and Stopping Wide Centre-Back were not offered in the captured back-four setup and are assumed to be back-three only.",
    "DR_DL": "The captured list was scrolled and ended at Playmaking Wing-Back; further roles may exist below. Treat this list as verified-but-possibly-incomplete.",
}


def collect_known(prompt):
    """Index every role description already present anywhere in the document."""
    known = {}
    for block in prompt["2_pitch_positions_and_roles"]["positions"].values():
        for key in ("in_possession_roles", "out_of_possession_roles"):
            for entry in block[key]:
                known.setdefault(entry["role"], entry)
    # renamed roles keep their original text
    known["Line-Holding Keeper"] = known["Line-Holding Goalkeeper"]
    known["Half Back"] = known["Half-Back"]
    known["Playmaking Winger"] = known["Wide Playmaker"]
    return known


def entry_for(role, phase, known):
    override = NEW_TEXT.get(f"{role}|{phase}")
    if override:
        return {"role": role, "new": True, **override}
    src = known[role]
    return {"role": role, "new": src.get("new", False),
            "does": src["does"], "best_for": src["best_for"]}


def main() -> None:
    doc = json.loads(DATA.read_text(encoding="utf-8"))
    prompt = doc["FM26_AI_SYSTEM_PROMPT"]
    section = prompt["2_pitch_positions_and_roles"]
    known = collect_known(prompt)

    block_for_code = {
        "GK": "GK", "DC": "DC", "DR": "DR_DL", "DL": "DR_DL",
        "DM": "DM", "MC": "MC", "AMR": "AMR_AML", "AML": "AMR_AML", "ST": "ST",
    }

    done = set()
    for code, phases in VERIFIED.items():
        section["allowed_roles_index"][code] = {
            "in_possession": list(phases["in_possession"]),
            "out_of_possession": list(phases["out_of_possession"]),
        }
        block_name = block_for_code[code]
        if block_name in done:
            continue
        done.add(block_name)
        block = section["positions"][block_name]
        block["in_possession_roles"] = [
            entry_for(r, "in_possession", known) for r in phases["in_possession"]
        ]
        block["out_of_possession_roles"] = [
            entry_for(r, "out_of_possession", known) for r in phases["out_of_possession"]
        ]
        block["verified_in_game"] = True
        if block_name in FORMATION_NOTE:
            block["capture_note"] = FORMATION_NOTE[block_name]

    for block_name in ("WBR_WBL", "MR_ML", "AMC"):
        section["positions"][block_name]["verified_in_game"] = False

    # Fix references to roles that no longer exist.
    prompt["0_critical_fm26_changes"]["banned_legacy_role_names"] = [
        "Mezzala (use Channel Midfielder)",
        "Segundo Volante (no direct equivalent - use Deep-Lying Playmaker IP + Dropping Defensive Midfielder OOP, or move the surging runner to MC)",
        "Enganche (use Advanced Playmaker or Midfield Playmaker)",
        "Trequartista (use Advanced Playmaker or Midfield Playmaker)",
        "Carrilero (use Wide Covering Central Midfielder)",
        "Pressing Forward (use Centre Forward / Channel Forward IP + Tracking Centre Forward OOP)",
        "Anchor Man, Regista, Raumdeuter, Complete Forward, Complete Wing-Back, Libero, Advanced Forward, Defensive Forward, Roaming Playmaker, Shadow Striker, Inverted Winger, Box-to-Box Midfielder, Box-to-Box Playmaker, Half-Space Winger, Half-Space Forward, Wide Playmaker, Pressing Defensive Midfielder, Splitting Outlet Centre Forward - not present in the observed FM26 lists; never output these.",
    ]
    prompt["4_decision_matrix"]["example_legal_pairings"] = [
        "GK: Ball-Playing Goalkeeper (IP) + Sweeper Keeper (OOP) - possession side with a high line.",
        "DC: Ball-Playing Centre-Back (IP) + Stopping Centre-Back (OOP) - front-foot ball-player.",
        "DC: No-Nonsense Centre-Back (IP) + Covering Centre-Back (OOP) - the spare man alongside a stopper.",
        "DR/DL: Wing-Back (IP) + Holding Full-Back (OOP) - width in attack, discipline out of possession.",
        "DM: Deep-Lying Playmaker (IP) + Screening Defensive Midfielder (OOP) - deep tempo-setter that holds its lane.",
        "MC: Channel Midfielder (IP) + Pressing Central Midfielder (OOP) - Mezzala-style runner that presses.",
        "MC: Central Midfielder (IP) + Screening Central Midfielder (OOP) - the balancing partner behind it.",
        "AML/AMR: Inside Forward (IP) + Tracking Winger (OOP) - inverted goal threat that still defends the flank.",
        "ST: Centre Forward (IP) + Tracking Centre Forward (OOP) - focal point that compresses space when pressing.",
    ]

    prompt["version"] = "4.1"
    prompt["last_role_research_update"] = "2026-01 in-game verification"
    prompt["_changelog"] = {
        "4.1": {
            "basis": "Screenshot verification of the tactic role editor in an actual FM26 save (Valencia, in-game date 2026-01-06, 4-1-2-2-1). Where the game and v4.0 disagreed, the game won.",
            "verified_codes": ["GK", "DC", "DR", "DL", "DM", "MC", "AMR", "AML", "ST"],
            "unverified_codes": ["WBR", "WBL", "MR", "ML", "AMC"],
            "changes": [
                "Added the base out-of-possession role that the game offers for every position: Goalkeeper, Defensive Midfielder, Central Midfielder, Winger, Centre Forward.",
                "Renamed Line-Holding Goalkeeper to Line-Holding Keeper.",
                "Renamed Half-Back to Half Back.",
                "Renamed Wide Playmaker to Playmaking Winger (AMR/AML).",
                "DR/DL in possession: added the wing-back family the game offers on a full-back slot - Wing-Back, Inside Wing-Back, Playmaking Wing-Back. Removed Inverted Full-Back (not observed).",
                "MC in possession: added Attacking Midfielder, Advanced Playmaker and Wide Central Midfielder; removed Box-to-Box Midfielder and Box-to-Box Playmaker (not offered).",
                "DM in possession: removed Box-to-Box Playmaker (not offered).",
                "DM out of possession: removed Pressing Defensive Midfielder and Wide Covering Defensive Midfielder (not offered).",
                "DC out of possession: removed No-Nonsense Centre-Back (not offered out of possession).",
                "AMR/AML in possession: removed Half-Space Winger (not offered).",
                "ST in possession: removed Half-Space Forward and Second Striker (not offered).",
                "ST out of possession: removed Splitting Outlet Centre Forward (not offered).",
                "Reordered every verified list to the order the game displays.",
                "Rewrote example_legal_pairings and banned_legacy_role_names so they no longer reference removed roles.",
            ],
            "open_questions": [
                "DR/DL in possession: the captured list was scrolled and may continue past Playmaking Wing-Back.",
                "Pressing Trap: the game shows a directional value ('Befelé csapdáz' / Trap Inside). The option list in 5_tactical_styles_and_team_instructions still says Balanced/Active and is NOT yet corrected - a screenshot of that dropdown is needed.",
                "WBR/WBL, MR/ML and AMC have no in-game evidence at all and are still the unverified v4.0 lists.",
            ],
        }
    }

    DATA.write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print("rebuilt", DATA.name, "-> version", prompt["version"])


if __name__ == "__main__":
    main()
