# Save isolation and the FM26 reference in the database

Design, 2026-08-16.

## Why

Two boundaries decide whether this repository stays usable across more than one
Football Manager career.

**A save must be deletable.** The current save is Valencia 2025/26. Starting a career
with another club has to remove every player profile, snapshot, evaluation, match and
date belonging to Valencia, while the FM26 rules — which describe the game, not the
save — survive untouched. Today nothing in the schema marks which side a table is on;
the separation exists only in the heads of the people reading it.

**The rules should be queryable.** The FM26 role system lives in JSON files that the
database never sees. A client connected over MCP can read them through the `reference`
tool, but it cannot join them: "is the role recorded for this player legal for his
position?" takes an SQL query on one side and a document read on the other, and the two
never meet. That gap is not theoretical — see "The role-label conflict" below, where the
database and the reference contradict each other in ten rows and nothing catches it.

A third, smaller boundary is broken outright: `data/tactics/mestral.json` is save data
(this save's tactic) filed with the reference documents, so it cannot be joined to the
players it assigns and has no dated history.

## Goals

1. A save can be switched or removed as one mechanical, auditable operation, with the
   FM26 rules provably untouched.
2. The FM26 rules are in the database: structured where a machine can check them,
   full text where a person or a model needs to read or search them.
3. The tactic is save data in the database, dated like everything else.
4. Data written through the MCP connector survives a rebuild.
5. The existing guarantees hold: the database stays generated, the committed JSON stays
   the source of truth, and the Python and PHP importers keep producing identical
   databases.

## Non-goals

- Querying two saves side by side. Exactly one save is loaded at a time; the others stay
  in git, unloaded. This is a decision, not a limitation to work around later.
- Full-text search engines. Keyword search is `LIKE` over a text column (see "Search").
- Switching saves from the MCP connector. Deliberately impossible — see "Safety".

## Decisions

| Question | Decision |
|---|---|
| Multiple saves at once | No. One active save in the database; the rest archived in git. |
| Marking the boundary | Table name prefix: `fm_` is FM26 knowledge, everything else is the save. |
| Reference shape | Structured tables **and** a full-text table, both generated from the JSON. |
| Source of truth | Unchanged: the committed JSON. The database is generated. |
| MCP writes | Written to the database and to a JSON file on the host, retrievable for committing. |
| Who may switch saves | Only someone with the capability token, through `bootstrap.php`. Never a tool. |

## Layout

```
data/
  import_template.json                  the shape of an import payload
  reference/                            FM26 knowledge - loaded for every save
    fm26_ai_system_prompt_v4.json
    fm26_role_locale_hu.json
  saves/
    valencia-2025-26/                   one directory per career
      initial_valencia_snapshot_2025-12-22.json.gz.b64
      season_2025-26_matches_2026-01-07.json
      player_umar_sadiq_2026-01-07.json
      match_barcelona_away_2026-01-10.json
      supplemental/
        filip_ugrinic_2025-12-22.json
      tactics/
        mestral.json
      incoming/                         written by import_json on the host
```

`mcp/config.php` names the active save:

```php
'active_save' => 'valencia-2025-26',
```

Switching careers is: create `data/saves/<new-slug>/`, change `active_save`, let the
sync carry both to the host, then rebuild. The old career stays in git in full.

The setting lives in `config.php` rather than in the database because the host's copy of
`config.php` is overwritten by the one-way sync from the working copy. A value the web
process wrote there would be silently reverted on the next upload.

## The two sides of the schema

### FM26 knowledge — `fm_` tables

Generated from `data/reference/*.json`. Never dropped by a save switch.

| Table | Columns | Rows today |
|---|---|---|
| `fm_positions` | `code` PK, `description`, `screenshot_verified` | 14 |
| `fm_roles` | `id`, `position_code`, `phase` (`IP`/`OOP`), `role_name`; unique on the three | 107 |
| `fm_banned_roles` | `id`, `role_name` unique, `replacement`, `note` | 24 |
| `fm_styles` | `id`, `name_en` unique, `name_hu`, `description` | 11 |
| `fm_instructions` | `id`, `phase`, `instruction_en`, `instruction_hu`, `options`, `note`; unique on phase+instruction_en | ~40 |
| `fm_role_locale` | `id`, `kind` (`role`/`position`/`instruction`/`trait`), `hu`, `en`; unique on the three | ~200 |
| `fm_reference` | `id`, `document`, `path`, `title`, `text`; unique on document+path | ~300 |

`fm_positions.screenshot_verified` carries the distinction the reference already makes:
`GK, DC, DR, DL, DM, MC, AMR, AML, ST` are verified against the game; `WBR, WBL, MR, ML,
AMC` are unverified research and must be treated as provisional.

`fm_reference` holds every node of every reference document as text, addressed by the
same dot path the `reference` tool already uses, so prose, rationale and changelog are
readable and searchable without leaving SQL.

### The save — unprefixed tables

The seventeen existing tables, plus four for the tactic:

| Table | Columns |
|---|---|
| `tactics` | `id`, `name`, `game_date`, `style_en`, `style_hu`, `mentality_en`, `mentality_hu`, `shape_ip`, `shape_oop`, `in_game_slot`, `source`, `notes`; unique on `(name, game_date)` |
| `tactic_slots` | `id`, `tactic_id`, `slot`, `position_code`, `ui_label`, `ip_role`, `oop_role`; unique on `(tactic_id, slot)` |
| `tactic_instructions` | `id`, `tactic_id`, `phase`, `instruction`, `value`, `source`; unique on `(tactic_id, phase, instruction)` |
| `tactic_lineups` | `id`, `tactic_id`, `label`, `slot`, `player_id`, `raw_label`; unique on `(tactic_id, label, slot)` |

Dated like every other historical record: a changed tactic is a new `tactics` row, never
an edit. `tactic_lineups.raw_label` keeps the text as written in the source
(`"Agirrezabala (25)"`); `player_id` is filled only when the name resolves
unambiguously against `players`, and stays `NULL` otherwise. A lineup entry is never
resolved by guessing, in keeping with the existing rule about shirt numbers.

This makes the previously impossible query ordinary:

```sql
SELECT s.slot, s.ip_role, s.oop_role, p.name,
       CASE WHEN f.role_name IS NULL THEN 'NOT IN THE REFERENCE' ELSE 'legal' END AS ip_legality
  FROM tactic_slots s
  JOIN tactics t          ON t.id = s.tactic_id
  LEFT JOIN tactic_lineups l ON l.tactic_id = t.id AND l.slot = s.slot AND l.label = 'line-up A'
  LEFT JOIN players p     ON p.id = l.player_id
  LEFT JOIN fm_roles f    ON f.role_name = s.ip_role AND f.phase = 'IP'
                         AND f.position_code = s.position_code
 WHERE t.game_date = (SELECT MAX(game_date) FROM tactics);
```

## Search

`fm_reference.text` is searched with `LIKE '%term%'`. No FULLTEXT index and no FTS5:
SQLite's FTS5 creates shadow tables that would appear in `list_tables` and break the
row-by-row MySQL/SQLite comparison in CI, and the whole reference is 66 KB, where a
scan costs nothing. The `reference` tool gains a `search` argument that returns matching
`(document, path, title)` rows with a text excerpt, so a model can find a section by
keyword and then read it by path.

## Attribute categories

`player_attributes.attribute_category` is written three ways today: `mental`/`Mental`,
`technical`/`Technical`, `physical`/`Physical`, plus `goalkeeping`. A `GROUP BY` on that
column silently returns wrong counts. Thirty-two rows use the capitalised form.

Fix: normalise the source JSON to lower case, and add
`CHECK (attribute_category IN ('technical','mental','physical','goalkeeping'))` to both
schemas so it cannot drift again.

## The role-label conflict

Ten `player_roles` rows carry labels that do not appear in any allowed list of the
reference. They are two different problems.

**A documented rename.** The v4.1 changelog states: *"Renamed Wide Playmaker to
Playmaking Winger (AMR/AML)."* Two rows (Danjuma, Diego López) still say `Wide
Playmaker` while two others (Dani Raba, Luis Rioja) already say `Playmaking Winger` —
one role under two names, captured at different times. The source JSON is corrected to
`Playmaking Winger`. No collision: different players.

**An unresolved disagreement.** All three defensive midfielders (Santamaria, Guido
Rodríguez, Pepelu) have `Box-to-Box Midfielder`, `Box-to-Box Playmaker` and `Holding
Midfielder` recorded, none of which is in the reference's DM list — which in turn
contains `Half Back`, recorded for nobody. Either the capture used pre-v4.1 names, or
the reference's DM list is incomplete. The data cannot settle it: those rows carry
`source: "conversation screenshot"`, while every row with a precisely named source uses
legal names throughout.

These rows stay as recorded source facts. `validate_roles.py` is extended to compare
`player_roles` against `fm_roles` and **report** conflicts rather than fail on them,
because a conflict may mean the reference is wrong — the project's own rule is that
where the research and the game disagree, the game wins. Resolving it needs one
screenshot of a defensive midfielder's player report; until then the conflict is
visible in every build instead of invisible in none.

## Writes through the connector

`import_json` currently writes to the database only, so a rebuild discards it. This was
observed directly: a row written during testing vanished on the next rebuild.

Each call now also writes its payload to
`data/saves/<active>/incoming/<UTC timestamp>-<n>.json` on the host, and `bootstrap`
loads that directory after the committed files. A rebuild therefore preserves everything
written since the last commit, and the JSON remains the source of truth.

The sync is one-way, so those files must be fetched back deliberately:
`bootstrap.php?token=<secret>&pull=1` returns them as one JSON document for committing
to git. The tool result of `import_json` names the file it wrote, so nothing accumulates
unnoticed.

## Safety

`bootstrap.php` is the only way to build, rebuild or reset, and it is reachable only
with the capability token. No MCP tool creates, drops or switches a save, and none is
planned: a model that can delete a career is a model that will eventually delete one.

Resetting the save (`&confirm=reset`) drops and recreates exactly the unprefixed tables,
then reloads the active save from files; the `fm_` tables are neither dropped nor
rewritten, and their row counts are checked afterwards to prove it. A full rebuild
(`&confirm=rebuild`) regenerates both sides, which is safe precisely because both sides
are generated from committed files.

## Verification

The existing three-way parity check stays: Python on SQLite, PHP on SQLite, PHP on
MySQL, compared row by row. Added to CI:

1. **Reference load** — `fm_roles` has 107 rows across 14 position codes, `fm_banned_roles`
   24, `fm_styles` 11; `fm_reference` covers every top-level section of both documents.
2. **Save reset keeps the reference** — count the `fm_` rows, reset, count again, assert
   equality, and assert the save tables were repopulated.
3. **Tactic load** — 11 slots, both phases populated, instructions present, both observed
   lineups resolved to players.
4. **Attribute categories** — exactly the four lower-case values, enforced by the CHECK.
5. **Role legality** — the conflict report runs and lists the known DM disagreement; the
   build fails only if a *new* unexplained label appears.
6. **Search** — a keyword query returns the expected section path.

`mcp/server.php --selftest` gains checks for the `reference` tool reading from the
database and for its `search` argument.

## What changes

| File | Change |
|---|---|
| `db/schema.sql`, `db/schema.mysql.sql` | 7 `fm_` tables, 4 tactic tables, the CHECK constraint |
| `mcp/db.php` | import whitelist gains the tactic tables; identifier handling unchanged |
| `mcp/bootstrap.php` | new layout, active save, reference import, reset, `pull` |
| `mcp/tools.php` | `reference` reads the database, gains `search`; `list_tables` reports scope |
| `scripts/import_json.py` | tactic tables in `TABLES` and in the order list |
| `scripts/init_db.py` | unchanged behaviour, new schema |
| `scripts/import_reference.py` | new: build the `fm_` tables from `data/reference/` |
| `scripts/validate_roles.py` | compare recorded labels against `fm_roles`, report conflicts |
| `scripts/compare_databases.py` | unchanged; it discovers tables |
| `data/` | reorganised as above |
| `.github/workflows/validate.yml` | the six checks above |
| `README.md`, `CLAUDE.md`, `mcp/README.md` | the boundary, the save switch, the search |

## Risks

**The reorganisation moves every data file.** The importers, the CI job and both READMEs
reference those paths. Mitigation: the move and the path changes land in one commit, and
the three-way parity check must produce byte-identical content to the current build
before anything else is added.

**The reference import is new code with no prior output to compare against.** Mitigation:
the counts asserted in CI come from the reference document itself, so a parsing mistake
that drops or duplicates entries fails the build.

**A save switch is destructive by definition.** Mitigation: it requires the token, an
explicit confirmation parameter, and it is impossible from the connector. The archived
save remains in git, so a switch is reversible by changing one setting back.

## Open question

One screenshot of a defensive midfielder's player report (Pepelu, Guido Rodríguez or
Santamaria), showing the role list, settles whether the reference's DM list or the
recorded labels are wrong. Whichever the game shows, wins.
