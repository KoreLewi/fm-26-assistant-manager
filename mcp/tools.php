<?php
/**
 * Tool definitions and handlers for the FM26 MCP server.
 *
 * Descriptions are written for a model choosing between tools without seeing the
 * repository: each one states what comes back and when to reach for it.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Maximum characters of JSON a single reference response returns before it drills down. */
const FM_REFERENCE_MAX_CHARS = 60000;

/** The reference documents present in the database. */
function fm_reference_documents(): array
{
    $pdo = fm_pdo_ro();
    $rows = $pdo->query(
        'SELECT document, COUNT(*) AS sections FROM fm_reference GROUP BY document ORDER BY document'
    )->fetchAll();
    if (fm_driver() === 'mysql' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return $rows;
}

/**
 * Read a reference document, drill into a section path, or search for a keyword.
 *
 * A path is the dot-joined chain of keys from the document name down. Keys are used
 * verbatim, so one containing a dot still addresses correctly: the stored path is
 * matched as a whole rather than split apart.
 */
function fm_reference_get(?string $document, ?string $section, ?string $search): array
{
    $pdo = fm_pdo_ro();

    try {
        if ($search !== null && $search !== '') {
            // Smallest match first: every ancestor of a hit also contains the term,
            // so the narrowest node is the one that actually answers the question.
            $stmt = $pdo->prepare(
                'SELECT document, path, title, text FROM fm_reference
                  WHERE text LIKE ? ORDER BY LENGTH(text), path LIMIT 25'
            );
            $stmt->execute(['%' . $search . '%']);
            $matches = [];
            foreach ($stmt->fetchAll() as $row) {
                $position = stripos($row['text'], $search);
                $matches[] = [
                    'document' => $row['document'],
                    'path' => $row['path'],
                    'title' => $row['title'],
                    'excerpt' => mb_substr($row['text'], max(0, (int) $position - 60), 240),
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
                'note' => 'Call again with "document" and a "section" path, or with "search" '
                    . 'to find a section by keyword. A path starts with the document name.',
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
            $available = array_column($stmt->fetchAll(), 'path');
            throw new FmMcpError(
                $available === []
                    ? sprintf('No document "%s" in the reference.', $document)
                    : sprintf(
                        'No section "%s" in "%s". Available paths include: %s',
                        $path,
                        $document,
                        implode(', ', $available)
                    ),
                -32602
            );
        }

        $encoded = (string) $text;
        if (strlen($encoded) > FM_REFERENCE_MAX_CHARS) {
            $stmt = $pdo->prepare(
                'SELECT path, title, LENGTH(text) AS size_chars FROM fm_reference
                  WHERE document = ? AND path LIKE ? AND path <> ? ORDER BY LENGTH(path), path'
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
            'content' => json_decode($encoded, true),
        ];
    } finally {
        if (fm_driver() === 'mysql' && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

/**
 * Record one line in the session log.
 *
 * The timestamp and the in-game date come from the server, not from the caller: a model
 * cannot know the real-world time, and a briefing that says "yesterday" has to be right.
 */
function fm_record_session_note(
    string $kind,
    string $headline,
    ?string $detail,
    ?string $nextStep,
    ?int $resolves
): array {
    $pdo = fm_pdo_rw();
    $gameDate = $pdo->query('SELECT current_game_date FROM game_state WHERE id = 1')->fetchColumn();

    $row = [
        'recorded_at' => gmdate('c'),
        'game_date' => $gameDate !== false ? $gameDate : null,
        'kind' => $kind,
        'headline' => $headline,
        'detail' => $detail,
        'next_step' => $nextStep,
        'source' => 'session note',
    ];

    $pdo->beginTransaction();
    try {
        $columns = array_keys($row);
        $stmt = $pdo->prepare(
            'INSERT INTO ' . fm_ident('session_log') . ' (' . implode(',', array_map('fm_ident', $columns))
            . ') VALUES (' . implode(',', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($row));
        $row['id'] = (int) $pdo->lastInsertId();

        $resolved = null;
        if ($resolves !== null) {
            $lookup = $pdo->prepare(
                "SELECT id FROM session_log WHERE id = ? AND kind = 'question' AND resolved_at IS NULL"
            );
            $lookup->execute([$resolves]);
            if ($lookup->fetchColumn() === false) {
                throw new FmMcpError(
                    sprintf('There is no open question with id %d to resolve.', $resolves),
                    -32602
                );
            }
            $close = $pdo->prepare(
                'UPDATE ' . fm_ident('session_log') . ' SET resolved_at = ?, resolved_by = ? WHERE id = ?'
            );
            $close->execute([$row['recorded_at'], $row['id'], $resolves]);
            $resolved = $resolves;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e instanceof FmMcpError
            ? $e
            : new FmMcpError('The note was not recorded: ' . $e->getMessage(), -32602);
    }

    // Persisted like any other write, so a rebuild does not forget the thread.
    $persistedAs = fm_persist_import(['session_log' => [$row]]);

    return [
        'recorded' => $row,
        'resolved_question' => $resolved,
        'persisted_as' => $persistedAs,
        'briefing' => fm_briefing(fm_pdo_ro()),
    ];
}

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

function fm_tool_definitions(): array
{
    $importTables = fm_import_tables();
    $tableList = implode(', ', array_keys($importTables));
    // Named rather than queried: describing the tools must not depend on the
    // database being reachable.
    $documentList = 'fm26_ai_system_prompt_v4, fm26_role_locale_hu';

    return [
        [
            'name' => 'query',
            'title' => 'Run a read-only SQL query',
            'description' =>
                "Run one read-only SQL SELECT against the FM26 save database and return the matching rows "
                . "as JSON, together with the column names and a flag telling you whether the result was "
                . "truncated at the row limit.\n\n"
                . "Use this for every question about the save: squad lists, attributes, match results, "
                . "per-match player statistics, pass maps, season aggregates, league tables, scout reports "
                . "and tactical observations. Call list_tables first if the column names are not known.\n\n"
                . "Only a single SELECT (optionally starting with WITH) is accepted. Statement chaining with "
                . "';', PRAGMA, ATTACH and every write statement are rejected, and the connection itself is "
                . "opened read-only, so this tool can never change data. Use import_json to write.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sql' => [
                        'type' => 'string',
                        'description' => 'A single SELECT or WITH ... SELECT statement. No trailing statements.',
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'minimum' => 1,
                        'description' =>
                            'Maximum rows to return. Capped by the server row limit (default 500). '
                            . 'Omit to use the server limit.',
                    ],
                ],
                'required' => ['sql'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'Run a read-only SQL query',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ],
        [
            'name' => 'list_tables',
            'title' => 'List tables and columns',
            'description' =>
                "Return every table in the FM26 database with its column definitions (name, declared type, "
                . "NOT NULL, default, primary key), its current row count, and whether import_json can write "
                . "to it.\n\n"
                . "Use this before writing a query when the exact table or column name is uncertain, and "
                . "before building an import_json payload. It takes no arguments and never changes data.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'List tables and columns',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ],
        [
            'name' => 'import_json',
            'title' => 'Import structured save data',
            'description' =>
                "Write structured FM26 data into the database and return the number of rows written per "
                . "table. This is the only way to add data.\n\n"
                . "The payload is an object whose keys are table names and whose values are arrays of row "
                . "objects. Writable tables: {$tableList}. Row keys must be real column names; an unknown "
                . "column aborts the whole import.\n\n"
                . "The save's clock follows the data. Recording anything dated - a match, a snapshot, a "
                . "set of attributes - moves current_game_date forward to the latest date in the payload, "
                . "and the result reports it as game_date_advanced_to. It never moves backwards, so "
                . "capturing an old screen late does not rewind the save. Send a \"game_state\" object "
                . "with current_game_date, season and notes only to state the date outright, which "
                . "overrides what the rows imply.\n\n"
                . "Rows are written with INSERT OR REPLACE keyed on the primary key, so supplying an "
                . "existing id overwrites that row. Historical rows (player_snapshots, player_attributes, "
                . "player_roles) are keyed by in-game date: record a new date as a new row instead of "
                . "editing an old one. Parent rows must exist or be included in the same payload, because "
                . "foreign keys are enforced. The whole import runs in one transaction and is rolled back "
                . "completely if any row fails.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'payload' => [
                        'type' => 'object',
                        'description' =>
                            'Import object in the shape of data/import_template.json: table name -> array '
                            . 'of row objects, plus an optional "game_state" object.',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['payload'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'Import structured save data',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ],
        [
            'name' => 'save_state',
            'title' => 'Current save state',
            'description' =>
                "Return everything needed to pick the work up: a briefing of what was last worked "
                . "on, what comes next and which questions are still open; the gaps worth closing with "
                . "the next screenshot; and the state of the save itself - current in-game date, season, "
                . "club, squad size, latest snapshot and match dates, and the row count of every "
                . "table.\n\n"
                . "Call this first in every conversation. This connector keeps no memory between them, "
                . "so the briefing is the only thing carrying the thread across, and the in-game date it "
                . "reports is what every time-dependent answer depends on.\n\n"
                . "Tell the manager what the gaps say when they matter: they are ordered by how much a "
                . "single screenshot closes, so the first one is the capture worth making next. It takes "
                . "no arguments and never changes data.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => new stdClass(),
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'Current save state',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ],
        [
            'name' => 'session_note',
            'title' => 'Record where the work stands',
            'description' =>
                "Write one line into the session log and return it, together with the refreshed "
                . "briefing.\n\n"
                . "A connector has no memory between conversations. Every chat starts blank, and "
                . "nothing said in this one reaches the next unless it is written here. Record a "
                . "note after any substantive step - data recorded, a conclusion reached, a "
                . "decision taken, a question left open - so the next conversation can pick the "
                . "thread up from save_state.\n\n"
                . "kind is one of: \"progress\" for what was done, \"decision\" for a choice made "
                . "and the reasoning behind it, \"question\" for something unresolved that should "
                . "keep surfacing until it is answered. A question stays in the briefing until a "
                . "later note names its id in \"resolves\".\n\n"
                . "headline is one sentence in the past tense. Put the reasoning in detail, and "
                . "what should happen next in next_step. The timestamp and the in-game date are "
                . "filled in by the server, so never write them into the text.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'kind' => [
                        'type' => 'string',
                        'enum' => ['progress', 'decision', 'question'],
                        'description' => 'progress = what was done, decision = a choice made, question = unresolved.',
                    ],
                    'headline' => [
                        'type' => 'string',
                        'description' => 'One sentence, past tense, describing what happened.',
                    ],
                    'detail' => [
                        'type' => 'string',
                        'description' => 'The reasoning or the specifics. Optional.',
                    ],
                    'next_step' => [
                        'type' => 'string',
                        'description' => 'What the next conversation should pick up. Optional.',
                    ],
                    'resolves' => [
                        'type' => 'integer',
                        'description' =>
                            'The id of an open question this note answers. It stops appearing '
                            . 'in the briefing once resolved.',
                    ],
                ],
                'required' => ['kind', 'headline'],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'Record where the work stands',
                'readOnlyHint' => false,
                'destructiveHint' => false,
                'idempotentHint' => false,
                'openWorldHint' => false,
            ],
        ],
        [
            'name' => 'reference',
            'title' => 'FM26 rules and tactic reference',
            'description' =>
                "Read the FM26 rules: which roles the game offers for each position and phase, the "
                . "legacy names it no longer has, the preset tactical styles, every team instruction "
                . "with its options, and the Hungarian interface vocabulary.\n\n"
                . "Documents: {$documentList}. Both are also loaded into tables - fm_positions, "
                . "fm_roles, fm_banned_roles, fm_styles, fm_instructions, fm_role_locale - so a "
                . "legality question is a query, not a reading exercise: joining player_roles or "
                . "tactic_slots to fm_roles says outright which recorded role the game does not "
                . "offer for that position. Use this tool for the prose the tables cannot hold: what "
                . "a role actually does, why the system changed, what is still unverified.\n\n"
                . "FM26 has no Defend/Support/Attack duties: every outfield player gets exactly one "
                . "In Possession and one Out of Possession role, and a role is legal for a slot only "
                . "if that exact string appears under that position code and phase.\n\n"
                . "Three ways to call it. With no arguments it lists the documents. With \"search\" "
                . "it finds every section containing a keyword, narrowest match first, and returns "
                . "the paths with excerpts. With \"document\" and \"section\" it returns one section "
                . "in full - a section path starts with the document name, for example "
                . "\"fm26_ai_system_prompt_v4.FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles."
                . "allowed_roles_index.ST\". A section too large to return comes back as the list of "
                . "paths beneath it instead.\n\n"
                . "Consult this before recommending any role or instruction. A role label read off a "
                . "screenshot is stored in player_roles as what the screen said, which is not the "
                . "same as being legal for the slot.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'document' => [
                        'type' => 'string',
                        'description' => 'Document name from the catalogue. Omit to list what is available.',
                    ],
                    'section' => [
                        'type' => 'string',
                        'description' =>
                            'Dot-joined path into the document, starting with the document name, '
                            . 'e.g. "fm26_ai_system_prompt_v4.FM26_AI_SYSTEM_PROMPT.0_critical_fm26_changes". '
                            . 'Omit for the whole document.',
                    ],
                    'search' => [
                        'type' => 'string',
                        'description' =>
                            'Keyword to find across the whole reference. Returns matching paths '
                            . 'with an excerpt; read one in full by passing it as "section".',
                    ],
                ],
                'additionalProperties' => false,
            ],
            'annotations' => [
                'title' => 'FM26 rules and tactic reference',
                'readOnlyHint' => true,
                'destructiveHint' => false,
                'idempotentHint' => true,
                'openWorldHint' => false,
            ],
        ],
    ];
}

/**
 * Execute a tool. Returns the MCP tools/call result object.
 */
function fm_call_tool(string $name, array $arguments): array
{
    switch ($name) {
        case 'query':
            $sql = $arguments['sql'] ?? null;
            if (!is_string($sql)) {
                throw new FmMcpError('The "sql" argument is required and must be a string.', -32602);
            }
            $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : null;
            $result = fm_run_query($sql, $limit);
            if ($result['truncated']) {
                $result['note'] = sprintf(
                    'Result truncated at %d rows. Narrow the query or use LIMIT/OFFSET to page through it.',
                    $result['row_limit']
                );
            }

            return fm_tool_result($result);

        case 'list_tables':
            return fm_tool_result(['tables' => fm_list_tables()]);

        case 'import_json':
            $payload = $arguments['payload'] ?? null;
            if (!is_array($payload)) {
                throw new FmMcpError('The "payload" argument is required and must be an object.', -32602);
            }
            $written = fm_import_transactional($payload);
            $persistedAs = fm_persist_import($payload);
            $total = 0;
            foreach ($written as $key => $value) {
                if (is_int($value)) {
                    $total += $value;
                }
            }

            $advancedTo = $written['_game_date_advanced_to'] ?? null;
            unset($written['_game_date_advanced_to']);

            return fm_tool_result([
                'rows_written' => $written,
                'total_rows_written' => $total,
                'game_date_advanced_to' => $advancedTo,
                'persisted_as' => $persistedAs,
                'state' => fm_save_state(),
            ]);

        case 'save_state':
            return fm_tool_result(fm_save_state());

        case 'session_note':
            $kind = $arguments['kind'] ?? null;
            $headline = $arguments['headline'] ?? null;
            if (!is_string($kind) || !in_array($kind, ['progress', 'decision', 'question'], true)) {
                throw new FmMcpError('"kind" must be one of: progress, decision, question.', -32602);
            }
            if (!is_string($headline) || trim($headline) === '') {
                throw new FmMcpError('"headline" is required and must be a non-empty string.', -32602);
            }

            return fm_tool_result(fm_record_session_note(
                $kind,
                trim($headline),
                $arguments['detail'] ?? null,
                $arguments['next_step'] ?? null,
                isset($arguments['resolves']) ? (int) $arguments['resolves'] : null
            ));

        case 'reference':
            $document = $arguments['document'] ?? null;
            $section = $arguments['section'] ?? null;
            if ($document !== null && !is_string($document)) {
                throw new FmMcpError('The "document" argument must be a string.', -32602);
            }
            if ($section !== null && !is_string($section)) {
                throw new FmMcpError('The "section" argument must be a string.', -32602);
            }

            $search = $arguments['search'] ?? null;
            if ($search !== null && !is_string($search)) {
                throw new FmMcpError('The "search" argument must be a string.', -32602);
            }

            return fm_tool_result(fm_reference_get($document, $section, $search));

        default:
            throw new FmMcpError("Unknown tool: {$name}", -32602);
    }
}

/** Wrap a data structure in an MCP tool result. */
function fm_tool_result(array $data): array
{
    return [
        'content' => [[
            'type' => 'text',
            'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]],
        'isError' => false,
    ];
}
