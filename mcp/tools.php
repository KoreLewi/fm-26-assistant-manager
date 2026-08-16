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

/**
 * The committed reference documents, keyed by name.
 *
 * A reference document is any JSON file under data/ that holds no importable table and
 * no game_state — it describes the game rather than recording the save, so it lives in
 * a file rather than in a table. Nothing has to be registered: a new document is picked
 * up as soon as it is committed.
 *
 * @return array<string,string> name => absolute path
 */
function fm_reference_documents(): array
{
    static $documents = null;
    if ($documents !== null) {
        return $documents;
    }

    $root = fm_config()['repo_root'] . '/data';
    if (!is_dir($root)) {
        return $documents = [];
    }

    $tables = fm_import_tables();
    $documents = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'json') {
            continue;
        }
        $payload = json_decode((string) file_get_contents($file->getPathname()), true);
        if (!is_array($payload)) {
            continue;
        }
        $keys = array_keys($payload);
        if (array_intersect($keys, array_keys($tables)) !== [] || in_array('game_state', $keys, true)) {
            continue;
        }

        $name = substr(str_replace([$root . '/', '\\'], ['', '/'], $file->getPathname()), 0, -5);
        $documents[$name] = $file->getPathname();
    }

    ksort($documents);

    return $documents;
}

/** Describe a JSON node without returning it: type, size, and the keys below it. */
function fm_reference_outline(mixed $node): array
{
    if (is_array($node) && $node !== [] && !array_is_list($node)) {
        $sections = [];
        foreach ($node as $key => $value) {
            $sections[] = [
                'key' => (string) $key,
                'type' => is_array($value) ? (array_is_list($value) ? 'list' : 'object') : get_debug_type($value),
                'size_chars' => strlen((string) json_encode($value)),
            ];
        }

        return ['type' => 'object', 'sections' => $sections];
    }

    if (is_array($node)) {
        return ['type' => 'list', 'length' => count($node)];
    }

    return ['type' => get_debug_type($node)];
}

/**
 * Read a reference document, optionally drilling into a dot-separated section path.
 */
function fm_reference_get(?string $document, ?string $section): array
{
    $documents = fm_reference_documents();

    if ($document === null || $document === '') {
        $catalogue = [];
        foreach ($documents as $name => $path) {
            $payload = json_decode((string) file_get_contents($path), true);
            $catalogue[] = [
                'document' => $name,
                'size_chars' => (int) filesize($path),
                'top_level' => array_keys(is_array($payload) ? $payload : []),
            ];
        }

        return [
            'documents' => $catalogue,
            'note' => 'Call this tool again with "document" set to one of these names, '
                . 'and "section" to drill into it with a dot-separated path.',
        ];
    }

    if (!isset($documents[$document])) {
        throw new FmMcpError(
            sprintf('Unknown document "%s". Available: %s', $document, implode(', ', array_keys($documents))),
            -32602
        );
    }

    $payload = json_decode((string) file_get_contents($documents[$document]), true);
    if (!is_array($payload)) {
        throw new FmMcpError("The document \"{$document}\" could not be read as JSON.");
    }

    $node = $payload;
    $walked = [];
    foreach (array_filter(explode('.', (string) $section), static fn ($p) => $p !== '') as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            throw new FmMcpError(
                sprintf(
                    'Section "%s" does not exist in "%s". Available at %s: %s',
                    $section,
                    $document,
                    $walked === [] ? 'the top level' : implode('.', $walked),
                    is_array($node) ? implode(', ', array_keys($node)) : '(not an object)'
                ),
                -32602
            );
        }
        $node = $node[$part];
        $walked[] = $part;
    }

    $encoded = (string) json_encode($node);
    if (strlen($encoded) > FM_REFERENCE_MAX_CHARS) {
        return [
            'document' => $document,
            'section' => implode('.', $walked) ?: null,
            'truncated' => true,
            'outline' => fm_reference_outline($node),
            'note' => sprintf(
                'This section is %d characters, above the %d character response limit. '
                . 'Request one of the keys listed in "outline" as a deeper "section" path.',
                strlen($encoded),
                FM_REFERENCE_MAX_CHARS
            ),
        ];
    }

    return [
        'document' => $document,
        'section' => implode('.', $walked) ?: null,
        'truncated' => false,
        'content' => $node,
    ];
}

function fm_tool_definitions(): array
{
    $importTables = fm_import_tables();
    $tableList = implode(', ', array_keys($importTables));
    $documentList = implode(', ', array_keys(fm_reference_documents()));

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
                . "objects, plus an optional \"game_state\" object with current_game_date, season and notes. "
                . "Writable tables: {$tableList}. Row keys must be real column names; an unknown column "
                . "aborts the whole import.\n\n"
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
                "Return the state of the save: the current in-game date and season, the club with the "
                . "largest squad and its squad size, the most recent player snapshot date, the most recent "
                . "match date, and the row count of every table.\n\n"
                . "Call this at the start of a session to establish which in-game date the data reflects "
                . "before answering anything time-dependent. It takes no arguments and never changes data.",
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
            'name' => 'reference',
            'title' => 'FM26 rules and tactic reference',
            'description' =>
                "Return the committed FM26 reference documents: the legal role system, the Hungarian "
                . "interface vocabulary, and the tactics in use. These describe how the game works and "
                . "are not in any database table, so the query tool cannot reach them.\n\n"
                . "Available documents: {$documentList}.\n\n"
                . "fm26_ai_system_prompt_v4 is the authority on legality. FM26 has no Defend/Support/"
                . "Attack duties: every outfield player gets exactly one In Possession and one Out of "
                . "Possession role, and a role is legal for a slot only if that exact string appears "
                . "under the position code and phase in allowed_roles_index. It also lists the banned "
                . "legacy role names, the preset tactical styles and every team instruction with its "
                . "options.\n\n"
                . "Call with no arguments for the catalogue. Then pass \"document\", and \"section\" as a "
                . "dot-separated path to drill in — for example section "
                . "\"FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles.allowed_roles_index\". A section "
                . "larger than the response limit comes back as an outline of its keys instead of its "
                . "content, so you can request a narrower path.\n\n"
                . "Consult this before recommending any role or instruction. Role labels read off a "
                . "screenshot are stored in player_roles as source facts and are not automatically legal "
                . "for a given slot; check them here.",
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'document' => [
                        'type' => 'string',
                        'description' => 'Document name from the catalogue. Omit to list what is available.',
                    ],
                    'section' => [
                        'type' => 'string',
                        'description' => 'Dot-separated path into the document, e.g. "A.B.C". Omit for the whole document.',
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
            $total = 0;
            foreach ($written as $key => $value) {
                if (is_int($value)) {
                    $total += $value;
                }
            }

            return fm_tool_result([
                'rows_written' => $written,
                'total_rows_written' => $total,
                'state' => fm_save_state(),
            ]);

        case 'save_state':
            return fm_tool_result(fm_save_state());

        case 'reference':
            $document = $arguments['document'] ?? null;
            $section = $arguments['section'] ?? null;
            if ($document !== null && !is_string($document)) {
                throw new FmMcpError('The "document" argument must be a string.', -32602);
            }
            if ($section !== null && !is_string($section)) {
                throw new FmMcpError('The "section" argument must be a string.', -32602);
            }

            return fm_tool_result(fm_reference_get($document, $section));

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
