<?php
/**
 * Remote MCP server for the FM26 save database.
 *
 * Transport: Streamable HTTP. One JSON-RPC 2.0 request per POST body, answered with
 * application/json. SSE is not offered.
 *
 * Authentication: capability URL. The secret is a path segment
 * (https://host/mcp/<secret>/), because the connector UI has no field for a custom
 * header. Anything that does not match is answered with 404 so the endpoint's
 * existence is never confirmed.
 *
 * CLI:  php mcp/server.php --selftest
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/tools.php';
require_once __DIR__ . '/oauth.php';

const FM_MCP_SERVER_NAME = 'fm26-assistant-manager';
const FM_MCP_SERVER_VERSION = '1.0.0';
const FM_MCP_PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

/**
 * Append a message to the configured log file.
 * The request URI is never logged: it carries the secret.
 */
function fm_log(string $message): void
{
    try {
        $file = fm_config()['log_file'] ?? null;
    } catch (Throwable $e) {
        $file = null;
    }
    if (!$file) {
        // No log file configured: fall back to the host's PHP error log, so a failure
        // is never silent.
        error_log('fm26-mcp: ' . $message);

        return;
    }
    @file_put_contents(
        $file,
        sprintf("[%s] %s\n", gmdate('c'), $message),
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Turn a fatal error into a JSON-RPC error object.
 *
 * Without this a fatal leaves an empty 500 with no clue what happened, because
 * display_errors is off. The message goes to the log; the client is told only that the
 * server failed.
 */
function fm_register_fatal_handler(): void
{
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }

        fm_log(sprintf('Fatal: %s in %s:%d', $error['message'], $error['file'], $error['line']));

        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(fm_rpc_error(null, -32603, 'Internal server error.'));
    });
}

/** Read the capability token from the request, whichever way the host passes it. */
function fm_request_token(): string
{
    foreach (['MCP_PATH_TOKEN', 'REDIRECT_MCP_PATH_TOKEN', 'REDIRECT_REDIRECT_MCP_PATH_TOKEN'] as $key) {
        if (!empty($_SERVER[$key]) && is_string($_SERVER[$key])) {
            return $_SERVER[$key];
        }
    }

    // Fallbacks: PATH_INFO (nginx/fastcgi) and the raw request path.
    $candidates = [];
    if (!empty($_SERVER['PATH_INFO'])) {
        $candidates[] = $_SERVER['PATH_INFO'];
    }
    if (!empty($_SERVER['REQUEST_URI'])) {
        $candidates[] = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '';
    }

    foreach ($candidates as $path) {
        $segments = array_values(array_filter(explode('/', (string) $path), static fn ($s) => $s !== ''));
        if ($segments === []) {
            continue;
        }
        // Take the last segment that is not the script name.
        for ($i = count($segments) - 1; $i >= 0; $i--) {
            if (!str_ends_with($segments[$i], '.php')) {
                return $segments[$i];
            }
        }
    }

    return '';
}

/** Constant-time comparison of the presented token against the configured secret. */
function fm_auth_ok(string $token): bool
{
    if ($token === '') {
        return false;
    }
    try {
        $secret = fm_config()['secret'];
    } catch (Throwable $e) {
        fm_log('Configuration error during authentication: ' . $e->getMessage());

        return false;
    }

    return hash_equals($secret, $token);
}

/**
 * Ask for a bearer token, pointing at the resource metadata as RFC 9728 requires.
 *
 * The capability URL is what actually authorises a request; this exists because the
 * client refuses to speak to a server that does not start an OAuth flow.
 */
function fm_send_unauthorized(): void
{
    http_response_code(401);
    header(sprintf(
        'WWW-Authenticate: Bearer realm="fm26", resource_metadata="%s"',
        fm_oauth_resource_metadata_url()
    ));
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(fm_rpc_error(null, -32001, 'Authorization required.'));
}

/** Send a 404 that reveals nothing about the endpoint. */
function fm_send_not_found(): void
{
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo "Not Found\n";
}

function fm_send_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function fm_rpc_error(mixed $id, int $code, string $message): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => $code, 'message' => $message],
    ];
}

function fm_rpc_result(mixed $id, mixed $result): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => $result,
    ];
}

/** Negotiate the protocol version against what the client asked for. */
function fm_negotiate_protocol(mixed $requested): string
{
    if (is_string($requested) && in_array($requested, FM_MCP_PROTOCOL_VERSIONS, true)) {
        return $requested;
    }

    return FM_MCP_PROTOCOL_VERSIONS[0];
}

/**
 * Handle one JSON-RPC message.
 * Returns null for notifications, which carry no response.
 */
function fm_handle_message(array $message): ?array
{
    $id = $message['id'] ?? null;
    $isNotification = !array_key_exists('id', $message);
    $method = $message['method'] ?? null;
    $params = $message['params'] ?? [];
    if (!is_array($params)) {
        $params = [];
    }

    if (!is_string($method)) {
        return $isNotification ? null : fm_rpc_error($id, -32600, 'Invalid Request: "method" is missing.');
    }

    if (str_starts_with($method, 'notifications/')) {
        return null;
    }

    try {
        switch ($method) {
            case 'initialize':
                $result = [
                    'protocolVersion' => fm_negotiate_protocol($params['protocolVersion'] ?? null),
                    'capabilities' => [
                        'tools' => ['listChanged' => false],
                    ],
                    'serverInfo' => [
                        'name' => FM_MCP_SERVER_NAME,
                        'title' => 'FM26 Assistant Manager',
                        'version' => FM_MCP_SERVER_VERSION,
                    ],
                    'instructions' =>
                        'Football Manager 2026 save database. Call save_state first to learn the current '
                        . 'in-game date, reference before recommending any role or instruction, '
                        . 'list_tables when column names are uncertain, query for every read, and '
                        . 'import_json to record new data. Never answer from memory: this database is '
                        . 'the only source of truth for the save. FM26 has no Defend/Support/Attack '
                        . 'duties - each outfield player has one In Possession and one Out of Possession '
                        . 'role, and a role is legal for a slot only if it appears under that position '
                        . 'code and phase in the reference. Attribute numbers beat star ratings. Player '
                        . 'state is dated: a new in-game date is a new row, never an edit to an old one.',
                ];

                return $isNotification ? null : fm_rpc_result($id, $result);

            case 'ping':
                // An empty PHP array encodes as [], and the result has to be an object.
                return $isNotification ? null : fm_rpc_result($id, new stdClass());

            case 'tools/list':
                return $isNotification ? null : fm_rpc_result($id, ['tools' => fm_tool_definitions()]);

            case 'tools/call':
                $name = $params['name'] ?? null;
                if (!is_string($name)) {
                    return fm_rpc_error($id, -32602, 'Invalid params: "name" is required.');
                }
                $arguments = $params['arguments'] ?? [];
                if (!is_array($arguments)) {
                    return fm_rpc_error($id, -32602, 'Invalid params: "arguments" must be an object.');
                }
                try {
                    $result = fm_call_tool($name, $arguments);
                } catch (FmMcpError $e) {
                    // Tool failures come back as a tool result rather than a transport
                    // error so the caller can read the reason and retry with a fix.
                    $result = [
                        'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                        'isError' => true,
                    ];
                }

                return $isNotification ? null : fm_rpc_result($id, $result);

            default:
                return $isNotification ? null : fm_rpc_error($id, -32601, "Method not found: {$method}");
        }
    } catch (FmMcpError $e) {
        fm_log('Tool error: ' . $e->getMessage());

        return $isNotification ? null : fm_rpc_error($id, $e->rpcCode(), $e->getMessage());
    } catch (Throwable $e) {
        fm_log(sprintf('Internal error in %s: %s in %s:%d', $method, $e->getMessage(), $e->getFile(), $e->getLine()));

        return $isNotification ? null : fm_rpc_error($id, -32603, 'Internal server error.');
    }
}

function fm_handle_http(): void
{
    fm_register_fatal_handler();

    // The path secret comes first: without it the endpoint does not exist at all.
    if (!fm_auth_ok(fm_request_token())) {
        fm_trace('mcp-unknown-path');
        fm_send_not_found();

        return;
    }

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method !== 'OPTIONS' && !fm_oauth_bearer_valid()) {
        fm_trace('mcp-401');
        fm_send_unauthorized();

        return;
    }
    fm_trace('mcp');

    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: POST, OPTIONS');

        return;
    }
    if ($method !== 'POST') {
        // GET would open an SSE stream in the full transport; this server is
        // request/response only.
        http_response_code(405);
        header('Allow: POST, OPTIONS');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(fm_rpc_error(null, -32600, 'Only POST is supported by this endpoint.'));

        return;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim((string) $raw) === '') {
        fm_send_json(fm_rpc_error(null, -32700, 'Parse error: empty request body.'), 400);

        return;
    }

    try {
        $decoded = json_decode((string) $raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        fm_send_json(fm_rpc_error(null, -32700, 'Parse error: the body is not valid JSON.'), 400);

        return;
    }

    if (!is_array($decoded)) {
        fm_send_json(fm_rpc_error(null, -32600, 'Invalid Request: expected a JSON object or array.'), 400);

        return;
    }

    // Batch: a JSON array of messages.
    $isBatch = array_is_list($decoded);
    $messages = $isBatch ? $decoded : [$decoded];
    if ($messages === []) {
        fm_send_json(fm_rpc_error(null, -32600, 'Invalid Request: empty batch.'), 400);

        return;
    }

    $responses = [];
    foreach ($messages as $message) {
        if (!is_array($message)) {
            $responses[] = fm_rpc_error(null, -32600, 'Invalid Request: batch entries must be objects.');
            continue;
        }
        $response = fm_handle_message($message);
        if ($response !== null) {
            $responses[] = $response;
        }
    }

    if ($responses === []) {
        // Notifications and responses only: acknowledge without a body.
        http_response_code(202);

        return;
    }

    fm_send_json($isBatch ? $responses : $responses[0]);
}

/* ------------------------------------------------------------------ selftest */

function fm_selftest(): int
{
    $failures = 0;
    $check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
        if (!$ok) {
            $failures++;
        }
        printf("%-58s %s%s\n", $label, $ok ? 'PASS' : 'FAIL', $detail !== '' ? '  (' . $detail . ')' : '');
    };

    $dir = sys_get_temp_dir() . '/fm26-mcp-selftest-' . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create the temporary directory {$dir}\n");

        return 1;
    }
    $dbPath = $dir . '/fm26.sqlite3';
    $secret = bin2hex(random_bytes(32));

    // The selftest runs on SQLite so it needs no database server: the protocol
    // dispatch, the SQL guard and the importer are the same code on either engine.
    fm_config_set([
        'driver' => 'sqlite',
        'db_path' => $dbPath,
        'secret' => $secret,
        'max_rows' => 3,
        'log_file' => null,
        'repo_root' => dirname(__DIR__),
    ]);

    try {
        // A minimal database rather than the committed data set, so the selftest
        // stays valid however the repository content changes.
        $pdo = fm_sqlite_pdo($dbPath);
        $schema = dirname(__DIR__) . '/db/schema.sql';
        if (!is_file($schema)) {
            fwrite(STDERR, "db/schema.sql not found next to mcp/\n");

            return 1;
        }
        $pdo->exec((string) file_get_contents($schema));
        unset($pdo);

        $seed = [
            'game_state' => ['current_game_date' => '2026-01-10', 'season' => '2025/26', 'notes' => 'selftest'],
            'teams' => [['id' => 1, 'name' => 'Selftest FC', 'club_type' => 'club', 'notes' => null]],
            'players' => [
                ['id' => 1, 'name' => 'Player One', 'current_team_id' => 1],
                ['id' => 2, 'name' => 'Player Two', 'current_team_id' => 1],
                ['id' => 3, 'name' => 'Player Three', 'current_team_id' => 1],
                ['id' => 4, 'name' => 'Player Four', 'current_team_id' => 1],
                ['id' => 5, 'name' => 'Player Five', 'current_team_id' => 1],
            ],
        ];
        fm_import_transactional($seed);

        // 1. initialize
        $init = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass()],
        ]);
        $check(
            'initialize returns protocolVersion and serverInfo',
            isset($init['result']['protocolVersion'], $init['result']['serverInfo']['name'])
                && $init['result']['protocolVersion'] === '2025-06-18'
                && $init['result']['serverInfo']['name'] === FM_MCP_SERVER_NAME
        );

        // 2. notifications carry no response
        $check(
            'notifications/initialized produces no response',
            fm_handle_message(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']) === null
        );

        // 3. tools/list
        $list = fm_handle_message(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_column($list['result']['tools'] ?? [], 'name');
        sort($names);
        $schemasOk = true;
        foreach ($list['result']['tools'] ?? [] as $tool) {
            if (empty($tool['description']) || empty($tool['inputSchema']['type'])) {
                $schemasOk = false;
            }
        }
        $check(
            'tools/list returns every tool with a schema',
            $names === ['import_json', 'list_tables', 'query', 'reference', 'save_state'] && $schemasOk,
            implode(',', $names)
        );

        // 4. query returns rows
        $call = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'query', 'arguments' => ['sql' => 'SELECT name FROM players ORDER BY id']],
        ]);
        $payload = json_decode($call['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'query returns rows and reports truncation at the row cap',
            ($call['result']['isError'] ?? true) === false
                && ($payload['row_count'] ?? 0) === 3
                && ($payload['truncated'] ?? false) === true
                && ($payload['rows'][0]['name'] ?? '') === 'Player One'
        );

        // 5. writes and chaining are rejected
        $rejects = [
            'DROP TABLE players',
            'SELECT 1; DROP TABLE players',
            'PRAGMA table_info(players)',
            "ATTACH DATABASE '/tmp/x.db' AS x",
            'DELETE FROM players',
            "SELECT 1 -- ;\n; DROP TABLE players",
        ];
        $allRejected = true;
        $leaked = '';
        foreach ($rejects as $sql) {
            $r = fm_handle_message([
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/call',
                'params' => ['name' => 'query', 'arguments' => ['sql' => $sql]],
            ]);
            if (($r['result']['isError'] ?? false) !== true) {
                $allRejected = false;
                $leaked = $sql;
            }
        }
        $check('write, PRAGMA, ATTACH and chained statements rejected', $allRejected, $leaked);

        // 6. the read-only connection refuses writes even directly
        $roBlocked = false;
        try {
            fm_pdo_ro()->exec('CREATE TABLE selftest_should_not_exist (a)');
        } catch (Throwable $e) {
            $roBlocked = true;
        }
        $check('read-only connection refuses a direct write', $roBlocked);

        // 7. list_tables
        $tablesCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/call',
            'params' => ['name' => 'list_tables', 'arguments' => []],
        ]);
        $tables = json_decode($tablesCall['result']['content'][0]['text'] ?? '{}', true);
        $tableNames = array_column($tables['tables'] ?? [], 'table');
        $check(
            'list_tables lists the schema with columns',
            in_array('players', $tableNames, true)
                && in_array('player_attributes', $tableNames, true)
                && count($tables['tables'][0]['columns'] ?? []) > 0
        );

        // 8. import_json inserts and the insert is visible to a following query
        $importCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => [
                'name' => 'import_json',
                'arguments' => [
                    'payload' => [
                        'players' => [['id' => 99, 'name' => 'Imported Player', 'current_team_id' => 1]],
                        'player_attributes' => [[
                            'player_id' => 99, 'game_date' => '2026-01-10',
                            'attribute_category' => 'technical', 'attribute_name' => 'Passing', 'value' => 15,
                        ]],
                    ],
                ],
            ],
        ]);
        $imported = json_decode($importCall['result']['content'][0]['text'] ?? '{}', true);
        $verify = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/call',
            'params' => [
                'name' => 'query',
                'arguments' => ['sql' => 'SELECT name FROM players WHERE id = 99'],
            ],
        ]);
        $verifyPayload = json_decode($verify['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'import_json writes and the rows are visible to query',
            ($imported['rows_written']['players'] ?? 0) === 1
                && ($imported['rows_written']['player_attributes'] ?? 0) === 1
                && ($verifyPayload['rows'][0]['name'] ?? '') === 'Imported Player'
        );

        // 9. a bad import rolls back completely
        $before = (int) fm_pdo_ro()->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $badImport = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/call',
            'params' => [
                'name' => 'import_json',
                'arguments' => [
                    'payload' => [
                        'players' => [
                            ['id' => 100, 'name' => 'Should Roll Back', 'current_team_id' => 1],
                            ['id' => 101, 'name' => 'Bad Row', 'current_team_id' => 999999],
                        ],
                    ],
                ],
            ],
        ]);
        $after = (int) fm_pdo_ro()->query('SELECT COUNT(*) FROM players')->fetchColumn();
        $check(
            'a failing import is rolled back completely',
            ($badImport['result']['isError'] ?? false) === true && $before === $after
        );

        // 10. unknown columns are refused
        $unknownColumn = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 9,
            'method' => 'tools/call',
            'params' => [
                'name' => 'import_json',
                'arguments' => ['payload' => ['players' => [['id' => 102, 'name' => 'X', 'made_up' => 1]]]],
            ],
        ]);
        $check('unknown columns are refused', ($unknownColumn['result']['isError'] ?? false) === true);

        // 11. save_state
        $stateCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 10,
            'method' => 'tools/call',
            'params' => ['name' => 'save_state', 'arguments' => []],
        ]);
        $state = json_decode($stateCall['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'save_state reports the in-game date, club and row counts',
            ($state['current_game_date'] ?? '') === '2026-01-10'
                && ($state['club'] ?? '') === 'Selftest FC'
                && ($state['row_counts']['players'] ?? 0) === 6
        );

        // 12. token comparison
        $check(
            'the configured token authenticates and others do not',
            fm_auth_ok($secret)
                && !fm_auth_ok('')
                && !fm_auth_ok(substr($secret, 0, -1) . 'x')
                && !fm_auth_ok(str_repeat('a', 64))
        );

        // 13. the reference catalogue and a drill-down into the role index
        $catalogueCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 11,
            'method' => 'tools/call',
            'params' => ['name' => 'reference', 'arguments' => []],
        ]);
        $catalogue = json_decode($catalogueCall['result']['content'][0]['text'] ?? '{}', true);
        $documentNames = array_column($catalogue['documents'] ?? [], 'document');

        $rolesCall = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 12,
            'method' => 'tools/call',
            'params' => [
                'name' => 'reference',
                'arguments' => [
                    'document' => 'fm26_ai_system_prompt_v4',
                    'section' => 'FM26_AI_SYSTEM_PROMPT.2_pitch_positions_and_roles.allowed_roles_index',
                ],
            ],
        ]);
        $roles = json_decode($rolesCall['result']['content'][0]['text'] ?? '{}', true);
        $check(
            'reference lists the documents and returns the role index',
            in_array('fm26_ai_system_prompt_v4', $documentNames, true)
                && in_array('tactics/mestral', $documentNames, true)
                && ($roles['truncated'] ?? true) === false
                && isset($roles['content']['GK'], $roles['content']['ST'])
        );

        // 14. an unknown reference path explains what is available
        $badSection = fm_handle_message([
            'jsonrpc' => '2.0',
            'id' => 13,
            'method' => 'tools/call',
            'params' => [
                'name' => 'reference',
                'arguments' => ['document' => 'fm26_ai_system_prompt_v4', 'section' => 'nope.nope'],
            ],
        ]);
        $check(
            'an unknown reference section is refused with the available keys',
            ($badSection['result']['isError'] ?? false) === true
                && str_contains($badSection['result']['content'][0]['text'] ?? '', 'FM26_AI_SYSTEM_PROMPT')
        );

        // 15. the OAuth layer: metadata documents and signed payloads
        $resourceMetadata = fm_oauth_protected_resource_metadata();
        $serverMetadata = fm_oauth_authorization_server_metadata();
        $check(
            'the OAuth metadata documents carry the required fields',
            !empty($resourceMetadata['resource'])
                && !empty($resourceMetadata['authorization_servers'][0])
                && !empty($serverMetadata['issuer'])
                && !empty($serverMetadata['authorization_endpoint'])
                && !empty($serverMetadata['token_endpoint'])
                && !empty($serverMetadata['registration_endpoint'])
                && $serverMetadata['code_challenge_methods_supported'] === ['S256']
                && in_array('refresh_token', $serverMetadata['grant_types_supported'], true)
        );

        $issued = fm_oauth_sign(['t' => 'at', 'exp' => time() + 60]);
        $expired = fm_oauth_sign(['t' => 'at', 'exp' => time() - 1]);
        $tampered = substr($issued, 0, -2) . (str_ends_with($issued, 'aa') ? 'bb' : 'aa');
        $check(
            'signed tokens verify, and tampered, expired or mistyped ones do not',
            fm_oauth_verify($issued, 'at') !== null
                && fm_oauth_verify($issued, 'rt') === null
                && fm_oauth_verify($expired, 'at') === null
                && fm_oauth_verify($tampered, 'at') === null
                && fm_oauth_verify('nonsense', 'at') === null
        );

        // 16. a token signed with a different secret is refused
        $foreign = fm_oauth_sign(['t' => 'at', 'exp' => time() + 60]);
        $realSecret = fm_config()['secret'];
        fm_config_set(array_merge(fm_config(), ['secret' => bin2hex(random_bytes(32))]));
        $refused = fm_oauth_verify($foreign, 'at') === null;
        fm_config_set(array_merge(fm_config(), ['secret' => $realSecret]));
        $check('a token signed with another secret is refused', $refused);

        // 17. bearer detection reads the header the host forwards
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . fm_oauth_sign(['t' => 'at', 'exp' => time() + 60]);
        $accepted = fm_oauth_bearer_valid();
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer not-a-token';
        $rejected = !fm_oauth_bearer_valid();
        unset($_SERVER['HTTP_AUTHORIZATION']);
        $check('a bearer header is read and validated', $accepted && $rejected);

        // 18. unknown method
        $unknown = fm_handle_message(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'no/such/method']);
        $check('an unknown method returns JSON-RPC -32601', ($unknown['error']['code'] ?? 0) === -32601);
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    echo "\n", $failures === 0
        ? "selftest: all checks passed\n"
        : "selftest: {$failures} check(s) failed\n";

    return $failures === 0 ? 0 : 1;
}

/* -------------------------------------------------------------------- entry */

if (PHP_SAPI === 'cli') {
    $argument = $argv[1] ?? '';
    if ($argument === '--selftest') {
        exit(fm_selftest());
    }
    if ($argument === '--token') {
        // A token for testing the live endpoint by hand, without walking the whole
        // browser flow. It is worth nothing without the secret path.
        try {
            $now = time();
            echo fm_oauth_sign(['t' => 'at', 'iat' => $now, 'exp' => $now + FM_OAUTH_ACCESS_TTL]), PHP_EOL;
            exit(0);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage() . "\n");
            exit(1);
        }
    }
    fwrite(STDERR, "Usage: php mcp/server.php --selftest | --token\n");
    exit(2);
}

fm_handle_http();
