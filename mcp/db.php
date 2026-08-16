<?php
/**
 * Database layer for the FM26 MCP server.
 *
 * Holds configuration loading, the two connections (read-write for imports, read-only
 * for ad-hoc queries), the SQL guard used by the query tool, and the import routine.
 *
 * Two engines are supported behind one interface. MySQL/MariaDB is what the host runs;
 * SQLite is what the Python scripts and the selftest use, which keeps the server
 * verifiable anywhere without a database server. Table and column names are identical
 * in both, so the same import payload loads into either.
 *
 * The table and column whitelist below is the PHP mirror of TABLES in
 * scripts/import_json.py. Both importers must accept exactly the same payload shape;
 * changing one without the other lets the two drift apart.
 */

declare(strict_types=1);

if (PHP_VERSION_ID < 80000) {
    http_response_code(500);
    exit('This server requires PHP 8.0 or newer; this host runs PHP ' . PHP_VERSION . ".\n");
}
if (!extension_loaded('json')) {
    http_response_code(500);
    exit("The PHP extension json is required but not loaded.\n");
}

if (!function_exists('array_is_list')) {
    /**
     * Available from PHP 8.1. The minimum supported version is 8.0, so it is defined
     * here rather than avoided at every call site.
     */
    function array_is_list(array $array): bool
    {
        $expected = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expected++) {
                return false;
            }
        }

        return true;
    }
}

/** Thrown for problems that are safe to report back to the caller. */
class FmMcpError extends RuntimeException
{
    private int $rpcCodeValue;

    public function __construct(string $message, int $rpcCode = -32000)
    {
        parent::__construct($message);
        $this->rpcCodeValue = $rpcCode;
    }

    public function rpcCode(): int
    {
        return $this->rpcCodeValue;
    }
}

/**
 * Table -> ordered column whitelist. Mirror of scripts/import_json.py.
 */
function fm_import_tables(): array
{
    static $tables = [
        'teams' => ['id', 'name', 'club_type', 'notes'],
        'players' => [
            'id', 'name', 'date_of_birth', 'nationality', 'preferred_foot',
            'current_team_id', 'current_shirt_number', 'status', 'notes',
        ],
        'player_snapshots' => [
            'id', 'player_id', 'game_date', 'age_years', 'team_id', 'shirt_number',
            'position_text', 'condition_text', 'role_text', 'value_text',
            'value_min_eur', 'value_max_eur', 'wage_eur_month', 'contract_end',
            'height_cm', 'personality_text', 'reputation_text',
            'current_ability_stars', 'potential_ability_stars', 'source', 'notes',
        ],
        'player_attributes' => [
            'id', 'player_id', 'game_date', 'attribute_category',
            'attribute_name', 'value', 'source',
        ],
        'player_roles' => [
            'id', 'player_id', 'game_date', 'phase', 'position_text',
            'role_text', 'rating_stars', 'source',
        ],
        'player_traits' => ['id', 'player_id', 'game_date', 'trait_text', 'source'],
        'competitions' => ['id', 'name', 'season', 'notes'],
        'matches' => [
            'id', 'match_date', 'competition_id', 'season', 'home_team_id',
            'away_team_id', 'opponent', 'home_away', 'score_for',
            'score_against', 'xg_for', 'xg_against', 'result',
            'possession_pct', 'shots', 'shots_on_target', 'tactical_summary', 'source',
        ],
        'match_players' => [
            'id', 'match_id', 'player_id', 'team_id',
            'shirt_number_at_match', 'starter', 'minutes', 'rating',
            'condition', 'distance_km', 'xg', 'xa', 'goals', 'assists', 'source',
        ],
        'pass_map_nodes' => [
            'id', 'match_id', 'player_id', 'shirt_number_at_match',
            'avg_x', 'avg_y', 'passes_in', 'passes_out',
        ],
        'pass_map_links' => ['id', 'match_id', 'from_player_id', 'to_player_id', 'pass_count'],
        'match_team_stats' => [
            'id', 'match_id', 'team_id', 'stat_name',
            'stat_value', 'stat_unit', 'source',
        ],
        'tactical_observations' => [
            'id', 'match_id', 'player_id', 'category',
            'observation', 'confidence', 'source',
        ],
        'player_evaluations' => [
            'id', 'player_id', 'evaluation_game_date',
            'category', 'observation', 'confidence', 'source',
        ],
        'player_season_stats' => [
            'id', 'player_id', 'game_date', 'season',
            'competition_scope', 'matches', 'starts', 'sub_apps',
            'goals', 'assists', 'xg', 'avg_rating',
            'yellow_cards', 'red_cards', 'source',
        ],
        'league_standings' => [
            'id', 'game_date', 'competition_id', 'season', 'position',
            'team_name', 'played', 'won', 'drawn', 'lost', 'goals_for',
            'goals_against', 'goal_difference', 'points', 'source',
        ],
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
        'scout_reports' => [
            'id', 'player_id', 'scout_game_date', 'scout_name',
            'scouting_context', 'current_age', 'current_team_id',
            'current_position', 'current_value_text', 'recommendation',
            'report_text', 'source',
        ],
    ];

    return $tables;
}

/**
 * Insert order. Parents before children so the import works with foreign keys on.
 * Mirror of the `order` list in scripts/import_json.py.
 */
function fm_import_order(): array
{
    return [
        'teams', 'players', 'competitions',
        'player_snapshots', 'player_attributes', 'player_roles', 'player_traits',
        'tactics', 'tactic_slots', 'tactic_instructions', 'tactic_lineups',
        'matches', 'match_players', 'pass_map_nodes', 'pass_map_links',
        'match_team_stats', 'tactical_observations', 'player_evaluations',
        'player_season_stats', 'league_standings', 'scout_reports',
    ];
}

/**
 * Load and validate mcp/config.php. Overridable in tests via fm_config_set().
 */
function fm_config(): array
{
    static $config = null;

    $override = fm_config_set();
    if ($override !== null) {
        return $override;
    }
    if ($config !== null) {
        return $config;
    }

    // mcp/config.php holds the host's settings and is synchronised to the server with
    // the rest of the working copy. FM26_CONFIG points at a different file so a local
    // machine can work against its own database without editing the host's copy.
    $path = getenv('FM26_CONFIG') ?: __DIR__ . '/config.php';
    if (!is_file($path)) {
        throw new FmMcpError('Server is not configured: ' . basename($path) . ' is missing.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new FmMcpError('Server is not configured: mcp/config.php must return an array.');
    }

    return $config = fm_normalise_config($loaded);
}

/** Fill in defaults and reject a configuration that cannot work. */
function fm_normalise_config(array $config): array
{
    if (empty($config['secret']) || !is_string($config['secret'])) {
        throw new FmMcpError("Server is not configured: 'secret' is missing from mcp/config.php.");
    }
    if (strlen($config['secret']) < 32) {
        throw new FmMcpError('Server is not configured: the secret must be at least 32 characters.');
    }

    $config['driver'] = strtolower((string) ($config['driver'] ?? 'mysql'));
    if (!in_array($config['driver'], ['mysql', 'sqlite'], true)) {
        throw new FmMcpError("Server is not configured: 'driver' must be 'mysql' or 'sqlite'.");
    }

    if ($config['driver'] === 'sqlite') {
        if (empty($config['db_path']) || !is_string($config['db_path'])) {
            throw new FmMcpError("Server is not configured: 'db_path' is required for the sqlite driver.");
        }
        if (!extension_loaded('pdo_sqlite')) {
            throw new FmMcpError('The PHP extension pdo_sqlite is required but not loaded.');
        }
    } else {
        $mysql = $config['mysql'] ?? [];
        foreach (['database', 'username'] as $key) {
            if (empty($mysql[$key]) || !is_string($mysql[$key])) {
                throw new FmMcpError("Server is not configured: 'mysql.{$key}' is missing from mcp/config.php.");
            }
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new FmMcpError('The PHP extension pdo_mysql is required but not loaded.');
        }
        $config['mysql'] = [
            'host' => $mysql['host'] ?? 'localhost',
            'port' => (int) ($mysql['port'] ?? 3306),
            'socket' => $mysql['socket'] ?? null,
            'database' => $mysql['database'],
            'username' => $mysql['username'],
            'password' => (string) ($mysql['password'] ?? ''),
            'charset' => $mysql['charset'] ?? 'utf8mb4',
        ];
    }

    // Whether a request on the secret path must also carry a bearer token. The token
    // adds nothing on its own - the path is the credential - so this exists purely to
    // suit whichever handshake a given client insists on.
    $config['require_bearer'] = (bool) ($config['require_bearer'] ?? false);
    // Which career is loaded. The database holds exactly one at a time; the others
    // stay in the repository, unloaded.
    $config['active_save'] = (string) ($config['active_save'] ?? 'valencia-2025-26');
    if (!preg_match('/^[a-z0-9][a-z0-9._-]*$/i', $config['active_save'])) {
        throw new FmMcpError("Server is not configured: 'active_save' must be a directory name.");
    }
    $config['max_rows'] = isset($config['max_rows']) ? max(1, (int) $config['max_rows']) : 500;
    $config['log_file'] = $config['log_file'] ?? null;
    $config['repo_root'] = !empty($config['repo_root']) ? $config['repo_root'] : dirname(__DIR__);

    return $config;
}

/**
 * Install an in-memory configuration, bypassing config.php. Used by --selftest and by
 * the command line options of bootstrap.php.
 * Call with an array to set, with null to read the current override.
 */
function fm_config_set(?array $config = null): ?array
{
    static $override = null;
    if ($config !== null) {
        $override = fm_normalise_config($config);
    }

    return $override;
}

function fm_driver(): string
{
    return fm_config()['driver'];
}

/** Quote an identifier for the configured engine. */
function fm_ident(string $name): string
{
    if (fm_driver() === 'mysql') {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    return '"' . str_replace('"', '""', $name) . '"';
}

/**
 * Open a SQLite file.
 *
 * The URI form of the DSN (sqlite:file:...?mode=ro) is not available on every build --
 * PHP 8.0 with a stock pdo_sqlite rejects it -- and when it is rejected PDO can fall
 * back to treating the whole string as a filename and quietly open the wrong file. The
 * plain DSN plus PRAGMA query_only is portable and blocks writes at the same layer, so
 * that is what is used.
 */
function fm_sqlite_pdo(string $path, bool $readOnly = false): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, fm_pdo_options());
    if ($readOnly) {
        $pdo->exec('PRAGMA query_only = 1');
    }
    $pdo->exec('PRAGMA busy_timeout = 5000');

    return $pdo;
}

function fm_mysql_dsn(array $mysql): string
{
    $dsn = 'mysql:';
    if (!empty($mysql['socket'])) {
        $dsn .= 'unix_socket=' . $mysql['socket'];
    } else {
        $dsn .= 'host=' . $mysql['host'] . ';port=' . $mysql['port'];
    }

    return $dsn . ';dbname=' . $mysql['database'] . ';charset=' . $mysql['charset'];
}

function fm_pdo_options(): array
{
    return [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
}

/** Read-write connection, used by import_json and bootstrap. */
function fm_pdo_rw(): PDO
{
    $config = fm_config();

    if ($config['driver'] === 'sqlite') {
        if (!is_file($config['db_path'])) {
            throw new FmMcpError('The database file does not exist yet. Run mcp/bootstrap.php on the host first.');
        }
        $pdo = fm_sqlite_pdo($config['db_path']);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    try {
        return new PDO(
            fm_mysql_dsn($config['mysql']),
            $config['mysql']['username'],
            $config['mysql']['password'],
            fm_pdo_options()
        );
    } catch (PDOException $e) {
        throw new FmMcpError('Cannot connect to the database: ' . $e->getMessage());
    }
}

/**
 * Read-only connection for the query tool.
 *
 * On SQLite the handle runs with query_only, on MySQL the session is put into a
 * read-only transaction. Either way the engine itself refuses every write on this
 * connection, whatever the statement text looked like, and the textual check in
 * fm_assert_readonly_sql is only the first line of defence.
 */
function fm_pdo_ro(): PDO
{
    $config = fm_config();

    if ($config['driver'] === 'sqlite') {
        if (!is_file($config['db_path'])) {
            throw new FmMcpError('The database file does not exist yet. Run mcp/bootstrap.php on the host first.');
        }
        return fm_sqlite_pdo($config['db_path'], true);
    }

    $pdo = fm_pdo_rw();
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->exec('START TRANSACTION READ ONLY');

    return $pdo;
}

/**
 * Remove comments, string literals and quoted identifiers from a statement so the
 * remaining text can be scanned for structure without literal content interfering.
 */
function fm_sql_skeleton(string $sql): string
{
    $out = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($ch === '-' && $next === '-') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $len : $end + 1;
            $out .= ' ';
            continue;
        }
        if ($ch === '#') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $len : $end + 1;
            $out .= ' ';
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            $out .= ' ';
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $i++;
            while ($i < $len) {
                if ($sql[$i] === '\\' && $ch !== '`') {
                    $i += 2;
                    continue;
                }
                if ($sql[$i] === $ch) {
                    if ($i + 1 < $len && $sql[$i + 1] === $ch) {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $i++;
            }
            $out .= ' ? ';
            continue;
        }
        if ($ch === '[') {
            $end = strpos($sql, ']', $i);
            $i = $end === false ? $len : $end + 1;
            $out .= ' ? ';
            continue;
        }

        $out .= $ch;
        $i++;
    }

    return $out;
}

/**
 * Reject anything that is not a single read-only SELECT/WITH statement.
 * Returns the statement with any trailing semicolon removed.
 */
function fm_assert_readonly_sql(string $sql): string
{
    $sql = trim($sql);
    if ($sql === '') {
        throw new FmMcpError('The sql argument is empty.', -32602);
    }

    $skeleton = fm_sql_skeleton($sql);

    // A single optional trailing semicolon is tolerated; anything else is chaining.
    $trimmedSkeleton = rtrim($skeleton);
    if (str_ends_with($trimmedSkeleton, ';')) {
        $trimmedSkeleton = rtrim(substr($trimmedSkeleton, 0, -1));
        $sql = rtrim($sql);
        $lastSemicolon = strrpos($sql, ';');
        if ($lastSemicolon !== false) {
            $sql = rtrim(substr($sql, 0, $lastSemicolon));
        }
    }
    if (str_contains($trimmedSkeleton, ';')) {
        throw new FmMcpError('Only one statement is allowed; remove the ";" chaining.', -32602);
    }

    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $trimmedSkeleton)) {
        throw new FmMcpError('Only SELECT (or WITH ... SELECT) statements are allowed.', -32602);
    }

    $forbidden = '/\b(PRAGMA|ATTACH|DETACH|INSERT|UPDATE|DELETE|REPLACE|DROP|CREATE|ALTER|GRANT|REVOKE'
        . '|TRUNCATE|RENAME|LOCK|UNLOCK|CALL|HANDLER|SET|OUTFILE|DUMPFILE|VACUUM|REINDEX|BEGIN|COMMIT'
        . '|ROLLBACK|SAVEPOINT|TRIGGER|load_extension|load_file|benchmark|sleep)\b/i';
    if (preg_match($forbidden, $trimmedSkeleton, $m)) {
        throw new FmMcpError(
            sprintf('"%s" is not allowed in a query; this tool is read-only.', strtoupper($m[1])),
            -32602
        );
    }

    return $sql;
}

/**
 * Run a read-only query and return the rows with their column names.
 */
function fm_run_query(string $sql, ?int $limit = null): array
{
    $config = fm_config();
    $cap = $limit === null ? $config['max_rows'] : max(1, min((int) $limit, $config['max_rows']));
    $statement = fm_assert_readonly_sql($sql);

    $pdo = fm_pdo_ro();
    try {
        $stmt = $pdo->query($statement);
    } catch (PDOException $e) {
        throw new FmMcpError('SQL error: ' . $e->getMessage(), -32602);
    }

    $rows = [];
    $truncated = false;
    while (($row = $stmt->fetch()) !== false) {
        if (count($rows) >= $cap) {
            $truncated = true;
            break;
        }
        $rows[] = $row;
    }

    $columns = [];
    for ($i = 0, $n = $stmt->columnCount(); $i < $n; $i++) {
        $meta = @$stmt->getColumnMeta($i);
        $columns[] = $meta['name'] ?? ('column_' . $i);
    }
    $stmt->closeCursor();

    if ($config['driver'] === 'mysql' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return [
        'columns' => $columns,
        'row_count' => count($rows),
        'truncated' => $truncated,
        'row_limit' => $cap,
        'rows' => $rows,
    ];
}

/** Every table name in the database, in alphabetical order. */
function fm_table_names(PDO $pdo): array
{
    if (fm_driver() === 'mysql') {
        $sql = 'SELECT table_name AS name FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\' ORDER BY table_name';
    } else {
        $sql = "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name";
    }

    $names = [];
    foreach ($pdo->query($sql) as $row) {
        // MySQL 8 returns TABLE_NAME, MariaDB returns table_name.
        $names[] = $row['name'] ?? $row['NAME'] ?? reset($row);
    }

    return $names;
}

/** Table names with their column definitions, read from the live schema. */
function fm_list_tables(): array
{
    $pdo = fm_pdo_ro();
    $driver = fm_driver();
    $importable = fm_import_tables();
    $tables = [];

    foreach (fm_table_names($pdo) as $name) {
        $columns = [];

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'SELECT column_name, column_type, is_nullable, column_default, column_key '
                . 'FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position'
            );
            $stmt->execute([$name]);
            foreach ($stmt->fetchAll() as $column) {
                $column = array_change_key_case($column, CASE_LOWER);
                $columns[] = [
                    'name' => $column['column_name'],
                    'type' => $column['column_type'],
                    'not_null' => strtoupper((string) $column['is_nullable']) === 'NO',
                    'default' => $column['column_default'],
                    'primary_key' => strtoupper((string) $column['column_key']) === 'PRI',
                ];
            }
        } else {
            foreach ($pdo->query('PRAGMA table_info(' . fm_ident($name) . ')') as $column) {
                $columns[] = [
                    'name' => $column['name'],
                    'type' => $column['type'],
                    'not_null' => (bool) $column['notnull'],
                    'default' => $column['dflt_value'],
                    'primary_key' => (bool) $column['pk'],
                ];
            }
        }

        $tables[] = [
            'table' => $name,
            'row_count' => (int) $pdo->query('SELECT COUNT(*) FROM ' . fm_ident($name))->fetchColumn(),
            'columns' => $columns,
            'importable' => array_key_exists($name, $importable),
        ];
    }

    if ($driver === 'mysql' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return $tables;
}

/**
 * Import one payload in the shape of data/import_template.json.
 *
 * @param bool $monotonicGameState When true the in-game clock is only moved forward.
 *                                 Used by bootstrap so replaying every committed file
 *                                 in filename order cannot rewind the save date.
 * @return array<string,int|string> rows written per table
 */
function fm_import_payload(PDO $pdo, array $payload, bool $monotonicGameState = false): array
{
    $tables = fm_import_tables();
    $written = [];
    $replace = fm_driver() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';

    $unknown = array_diff(array_keys($payload), array_keys($tables), ['game_state']);

    $gameState = $payload['game_state'] ?? null;
    if (is_array($gameState) && !empty($gameState['current_game_date'])) {
        $apply = true;
        if ($monotonicGameState) {
            $current = $pdo->query('SELECT current_game_date FROM game_state WHERE id = 1')->fetchColumn();
            $apply = $current === false || $current === null
                || (string) $gameState['current_game_date'] >= (string) $current;
        }
        if ($apply) {
            $stmt = $pdo->prepare(
                $replace . ' game_state (' . fm_ident('id') . ', ' . fm_ident('current_game_date')
                . ', ' . fm_ident('season') . ', ' . fm_ident('notes') . ') VALUES (1, ?, ?, ?)'
            );
            $stmt->execute([
                (string) $gameState['current_game_date'],
                $gameState['season'] ?? null,
                $gameState['notes'] ?? null,
            ]);
            $written['game_state'] = 1;
        }
    }

    foreach (fm_import_order() as $table) {
        $rows = $payload[$table] ?? [];
        if (!is_array($rows) || $rows === []) {
            continue;
        }

        $columns = $tables[$table];
        $quoted = implode(',', array_map('fm_ident', $columns));
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $stmt = $pdo->prepare($replace . ' ' . fm_ident($table) . " ({$quoted}) VALUES ({$placeholders})");

        $n = 0;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new FmMcpError("Row {$index} of \"{$table}\" is not an object.", -32602);
            }
            $extra = array_diff(array_keys($row), $columns);
            if ($extra !== []) {
                throw new FmMcpError(
                    sprintf(
                        'Unknown column(s) %s in "%s". Allowed: %s',
                        implode(', ', $extra),
                        $table,
                        implode(', ', $columns)
                    ),
                    -32602
                );
            }

            $values = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                if (is_bool($value)) {
                    $value = $value ? 1 : 0;
                } elseif (is_array($value)) {
                    throw new FmMcpError("Column \"{$column}\" of \"{$table}\" must be a scalar or null.", -32602);
                }
                $values[] = $value;
            }
            $stmt->execute($values);
            $n++;
        }
        $written[$table] = $n;
    }

    if ($unknown !== []) {
        $written['_ignored_keys'] = implode(', ', $unknown);
    }

    return $written;
}

/** Import a payload inside a transaction, rolling back on any error. */
function fm_import_transactional(array $payload, bool $monotonicGameState = false, ?PDO $pdo = null): array
{
    $pdo ??= fm_pdo_rw();
    $pdo->beginTransaction();
    try {
        $written = fm_import_payload($pdo, $payload, $monotonicGameState);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($e instanceof FmMcpError) {
            throw $e;
        }
        throw new FmMcpError('Import failed and was rolled back: ' . $e->getMessage(), -32602);
    }

    return $written;
}

/** Current save state: in-game date, season, club and per-table row counts. */
function fm_save_state(): array
{
    $pdo = fm_pdo_ro();

    $gameState = $pdo->query('SELECT current_game_date, season, notes FROM game_state WHERE id = 1')->fetch();
    if (!is_array($gameState)) {
        $gameState = [];
    }

    $counts = [];
    foreach (fm_table_names($pdo) as $name) {
        $counts[$name] = (int) $pdo->query('SELECT COUNT(*) FROM ' . fm_ident($name))->fetchColumn();
    }

    $club = $pdo->query(
        'SELECT t.name AS club, COUNT(*) AS squad_size
           FROM players p JOIN teams t ON t.id = p.current_team_id
          GROUP BY t.id, t.name ORDER BY squad_size DESC LIMIT 1'
    )->fetch();

    $latestSnapshot = $pdo->query('SELECT MAX(game_date) FROM player_snapshots')->fetchColumn();
    $latestMatch = $pdo->query('SELECT MAX(match_date) FROM matches')->fetchColumn();

    if (fm_driver() === 'mysql' && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    return [
        'current_game_date' => $gameState['current_game_date'] ?? null,
        'season' => $gameState['season'] ?? null,
        'game_state_notes' => $gameState['notes'] ?? null,
        'club' => is_array($club) ? ($club['club'] ?? null) : null,
        'squad_size' => is_array($club) && isset($club['squad_size']) ? (int) $club['squad_size'] : null,
        'latest_player_snapshot_date' => $latestSnapshot ?: null,
        'latest_match_date' => $latestMatch ?: null,
        'row_counts' => $counts,
    ];
}

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
