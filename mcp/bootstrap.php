<?php
/**
 * Build the database on the host from the committed sources.
 *
 * The host has no Python, so this is the PHP equivalent of
 *   scripts/init_db.py + scripts/import_initial_snapshot.py + scripts/import_json.py.
 *
 * Sources, in this order:
 *   1. the schema for the configured engine (db/schema.mysql.sql or db/schema.sql)
 *   2. the career's *.json.gz.b64 snapshot (gzip-compressed base64 JSON)
 *   3. supplemental/*.json, which continue the snapshot's row numbering
 *   4. the dated files in the career directory, then anything nested,
 *      and last whatever import_json wrote since the last commit
 *
 * Foreign keys are deferred until the whole load is finished, so the committed files
 * can be replayed in plain filename order regardless of which file introduces a parent
 * row; the referential check afterwards has to come back clean or the rebuild is
 * aborted. The in-game clock is only ever moved forward, so a template file carrying a
 * placeholder date cannot rewind the save.
 *
 * CLI:  php mcp/bootstrap.php [--force] [--info] [--reset]
 *        --reset drops and reloads only the career; the fm_ tables are left alone.
 *       php mcp/bootstrap.php --sqlite=/path/to/fm26.sqlite3 [--force] [--save=<slug>]
 *         builds without mcp/config.php, for a local rebuild or in CI. --save is only
 *         needed when the repository holds more than one career.
 * HTTP: POST https://host/mcp/bootstrap.php?token=<secret>&confirm=rebuild
 *       GET  https://host/mcp/bootstrap.php?token=<secret>&info=1
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reference.php';
require_once __DIR__ . '/tactic.php';
require_once __DIR__ . '/oauth.php';

/**
 * The single career in the repository, for builds that were not told which to load.
 *
 * With one career there is nothing to choose; with several, choosing for the caller
 * would silently load the wrong one.
 */
function fm_only_save(string $root): string
{
    $directories = array_values(array_filter(
        glob($root . '/data/saves/*') ?: [],
        'is_dir'
    ));
    if (count($directories) === 1) {
        return basename($directories[0]);
    }
    if ($directories === []) {
        throw new FmMcpError('No career found under data/saves/.');
    }

    throw new FmMcpError(sprintf(
        'The repository holds %d careers (%s). Name one with --save=<slug>.',
        count($directories),
        implode(', ', array_map('basename', $directories))
    ));
}

/** Host facts needed to diagnose an install without shell access. */
function fm_host_report(): array
{
    $lines = [
        'PHP version        ' . PHP_VERSION,
        'SAPI               ' . PHP_SAPI,
        'pdo_mysql          ' . (extension_loaded('pdo_mysql') ? 'loaded' : 'MISSING'),
        'pdo_sqlite         ' . (extension_loaded('pdo_sqlite') ? 'loaded' : 'MISSING'),
        'zlib               ' . (extension_loaded('zlib') ? 'loaded' : 'MISSING'),
        'json               ' . (extension_loaded('json') ? 'loaded' : 'MISSING'),
        'document root      ' . ($_SERVER['DOCUMENT_ROOT'] ?? '(none)'),
        'repository root    ' . dirname(__DIR__),
        'open_basedir       ' . (ini_get('open_basedir') ?: '(not set)'),
    ];

    try {
        $config = fm_config();
        $lines[] = 'driver             ' . $config['driver'];

        if ($config['driver'] === 'mysql') {
            $lines[] = 'mysql host         ' . ($config['mysql']['socket'] ?: $config['mysql']['host'] . ':' . $config['mysql']['port']);
            $lines[] = 'mysql database     ' . $config['mysql']['database'];
            try {
                $pdo = fm_pdo_rw();
                $lines[] = 'connection         OK';
                $lines[] = 'server version     ' . $pdo->query('SELECT VERSION()')->fetchColumn();
                $tables = fm_table_names($pdo);
                $lines[] = 'tables present     ' . (count($tables) > 0 ? count($tables) . ' (' . implode(', ', array_slice($tables, 0, 5)) . '...)' : 'none - not built yet');
            } catch (Throwable $e) {
                $lines[] = 'connection         FAILED: ' . $e->getMessage();
            }
        } else {
            $dir = dirname($config['db_path']);
            $lines[] = 'db_path            ' . $config['db_path'];
            $lines[] = 'db directory       ' . (is_dir($dir)
                ? (is_writable($dir) ? 'exists, writable' : 'exists, NOT writable')
                : 'does not exist yet');
            $lines[] = 'database           ' . (is_file($config['db_path'])
                ? 'present (' . number_format((int) filesize($config['db_path'])) . ' bytes)'
                : 'not built yet');
        }
    } catch (Throwable $e) {
        $lines[] = 'config             ' . $e->getMessage();
    }

    return $lines;
}

/** Split a schema file into statements, ignoring semicolons inside strings and comments. */
function fm_split_statements(string $sql): array
{
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $i = 0;

    while ($i < $len) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if (($ch === '-' && $next === '-') || $ch === '#') {
            $end = strpos($sql, "\n", $i);
            $i = $end === false ? $len : $end + 1;
            continue;
        }
        if ($ch === '/' && $next === '*') {
            $end = strpos($sql, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            continue;
        }
        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $current .= $ch;
            $i++;
            while ($i < $len) {
                $current .= $sql[$i];
                if ($sql[$i] === $ch) {
                    $i++;
                    break;
                }
                $i++;
            }
            continue;
        }
        if ($ch === ';') {
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $ch;
        $i++;
    }

    if (trim($current) !== '') {
        $statements[] = trim($current);
    }

    return $statements;
}

/** Drop every table in the configured MySQL database. */
function fm_mysql_drop_all(PDO $pdo): int
{
    $tables = fm_table_names($pdo);
    if ($tables === []) {
        return 0;
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($tables as $table) {
        $pdo->exec('DROP TABLE IF EXISTS ' . fm_ident($table));
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

    return count($tables);
}

/**
 * Referential integrity check. MySQL has no equivalent of PRAGMA foreign_key_check, so
 * every declared foreign key is verified with a left join against its parent.
 *
 * @return string[] human-readable descriptions of the violations found
 */
function fm_check_foreign_keys(PDO $pdo): array
{
    if (fm_driver() === 'sqlite') {
        $violations = $pdo->query('PRAGMA foreign_key_check')->fetchAll();

        return array_map(
            static fn ($row) => sprintf('%s -> %s', $row['table'] ?? '?', $row['parent'] ?? '?'),
            $violations
        );
    }

    $constraints = $pdo->query(
        'SELECT k.constraint_name, k.table_name, k.column_name,
                k.referenced_table_name, k.referenced_column_name
           FROM information_schema.key_column_usage k
          WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
          ORDER BY k.constraint_name, k.ordinal_position'
    )->fetchAll();

    $problems = [];
    foreach ($constraints as $row) {
        $row = array_change_key_case($row, CASE_LOWER);
        $child = fm_ident($row['table_name']);
        $childColumn = fm_ident($row['column_name']);
        $parent = fm_ident($row['referenced_table_name']);
        $parentColumn = fm_ident($row['referenced_column_name']);

        $orphans = (int) $pdo->query(
            "SELECT COUNT(*) FROM {$child} c LEFT JOIN {$parent} p ON c.{$childColumn} = p.{$parentColumn} "
            . "WHERE c.{$childColumn} IS NOT NULL AND p.{$parentColumn} IS NULL"
        )->fetchColumn();

        if ($orphans > 0) {
            $problems[] = sprintf(
                '%s.%s -> %s.%s: %d orphaned row(s)',
                $row['table_name'],
                $row['column_name'],
                $row['referenced_table_name'],
                $row['referenced_column_name'],
                $orphans
            );
        }
    }

    return $problems;
}

/** Collect the active career's source files in the order they have to be replayed. */
function fm_source_files(): array
{
    $root = fm_save_dir();
    if (!is_dir($root)) {
        // A career the connector will fill from nothing has no files yet. The directory
        // is created so there is somewhere to write them.
        if (!mkdir($root, 0750, true) && !is_dir($root)) {
            throw new FmMcpError("Cannot create the save directory {$root}.");
        }
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
        } elseif (str_starts_with($relative, 'tactics/')) {
            continue;
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

/**
 * @return array{lines: string[], ok: bool}
 */
function fm_bootstrap(bool $force, bool $resetOnly = false): array
{
    if (!extension_loaded('zlib')) {
        throw new FmMcpError('The zlib extension is required to decode the committed snapshot.');
    }

    $lines = [];
    $config = fm_config();
    $root = $config['repo_root'];
    $driver = $config['driver'];

    $schemaPath = $root . ($driver === 'mysql' ? '/db/schema.mysql.sql' : '/db/schema.sql');
    if (!is_file($schemaPath)) {
        throw new FmMcpError(basename($schemaPath) . " not found under {$root}/db.");
    }

    if ($driver === 'sqlite') {
        $dbPath = $config['db_path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new FmMcpError("Cannot create the database directory {$dir}.");
        }
        if (!is_writable($dir)) {
            throw new FmMcpError("The database directory {$dir} is not writable by PHP.");
        }

        // DOCUMENT_ROOT only describes what a request can reach, so the check belongs to
        // the web SAPI; on the command line the variable may hold anything.
        $webRoot = PHP_SAPI === 'cli' ? null : (realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: null);
        $dbReal = realpath($dir);
        if ($webRoot !== null && $dbReal !== false && str_starts_with($dbReal . '/', $webRoot . '/')) {
            throw new FmMcpError(
                "Refusing to build: {$dbReal} is inside the web root {$webRoot}. "
                . 'Move db_path outside the document root.'
            );
        }

        if (is_file($dbPath)) {
            if (!$force) {
                throw new FmMcpError(
                    "The database already exists at {$dbPath}. Pass --force (CLI) or &force=1 (HTTP) to rebuild it."
                );
            }
            $backup = $dbPath . '.' . gmdate('Ymd-His') . '.bak';
            if (!rename($dbPath, $backup)) {
                throw new FmMcpError("Cannot move the existing database aside to {$backup}.");
            }
            $lines[] = "Existing database moved to {$backup}";
        }

        $pdo = fm_sqlite_pdo($dbPath);
        foreach (fm_split_statements((string) file_get_contents($schemaPath)) as $statement) {
            $pdo->exec($statement);
        }
        // schema.sql turns foreign keys on; defer them until every source file is loaded.
        $pdo->exec('PRAGMA foreign_keys = OFF');
    } else {
        $pdo = fm_pdo_rw();
        // A reset drops only the career; a rebuild drops everything, which is safe
        // because both sides are generated from committed files.
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
    }
    $lines[] = 'Schema created from db/' . basename($schemaPath);

    // The reference describes the game, so it loads for every career - and a reset
    // leaves it exactly as it was.
    if ($resetOnly) {
        $lines[] = 'Reference left untouched';
    } else {
        foreach (fm_reference_import($pdo) as $table => $count) {
            $lines[] = sprintf('  reference %-52s %5d rows', $table, $count);
        }
    }

    $sources = fm_source_files();
    if ($sources === []) {
        $lines[] = sprintf(
            'Career "%s" starts empty: no files under data/saves/%s. The connector fills it.',
            fm_active_save(),
            fm_active_save()
        );
    }

    $pdo->beginTransaction();
    try {
        foreach ($sources as $file) {
            $raw = (string) file_get_contents($file);
            if (str_ends_with($file, '.gz.b64')) {
                $decoded = base64_decode(trim($raw), true);
                if ($decoded === false) {
                    throw new FmMcpError('Cannot base64-decode ' . basename($file));
                }
                $raw = gzdecode($decoded);
                if ($raw === false) {
                    throw new FmMcpError('Cannot gzip-decode ' . basename($file));
                }
            }

            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                $lines[] = sprintf('  skipped  %-52s (not a JSON object)', basename($file));
                continue;
            }

            // A save directory also holds notes about the career - decisions, open
            // questions - which are JSON but carry nothing importable. Naming them as
            // skipped keeps the log honest about what actually loaded.
            $importable = array_intersect(
                array_keys($payload),
                array_merge(array_keys(fm_import_tables()), ['game_state'])
            );
            if ($importable === []) {
                $lines[] = sprintf('  skipped  %-52s (not an import payload)', basename($file));
                continue;
            }

            $written = fm_import_payload($pdo, $payload, true);
            $rows = 0;
            foreach ($written as $value) {
                if (is_int($value)) {
                    $rows += $value;
                }
            }
            $lines[] = sprintf('  imported %-52s %5d rows', basename($file), $rows);
        }
        // Tactics load last: their line-ups resolve against the squad, which the files
        // above have just populated.
        foreach (glob(fm_save_dir() . '/tactics/*.json') ?: [] as $tacticFile) {
            $written = fm_tactic_import($pdo, $tacticFile);
            $lines[] = sprintf('  tactic   %-52s %5d rows', basename($tacticFile), array_sum($written));
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e instanceof FmMcpError
            ? $e
            : new FmMcpError('Bootstrap failed and was rolled back: ' . $e->getMessage());
    }

    $violations = fm_check_foreign_keys($pdo);
    if ($violations !== []) {
        throw new FmMcpError(
            'Referential check failed; the committed data is inconsistent: ' . implode('; ', $violations)
        );
    }
    $lines[] = 'Referential check clean';

    if ($driver === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('VACUUM');
        @chmod($config['db_path'], 0640);
    } else {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    $state = fm_save_state();
    $lines[] = sprintf(
        'Save state: %s, season %s, club %s',
        $state['current_game_date'] ?? 'unknown',
        $state['season'] ?? 'unknown',
        $state['club'] ?? 'unknown'
    );
    foreach ($state['row_counts'] as $table => $count) {
        $lines[] = sprintf('  %-24s %6d', $table, $count);
    }
    $lines[] = 'Database ready: ' . ($driver === 'mysql'
        ? $config['mysql']['database'] . ' on ' . $config['mysql']['host']
        : $config['db_path']);

    return ['lines' => $lines, 'ok' => true];
}

/* -------------------------------------------------------------------- entry */

if (PHP_SAPI === 'cli') {
    $force = in_array('--force', $argv ?? [], true);
    $resetOnly = in_array('--reset', $argv ?? [], true);

    // --sqlite builds without config.php, for a local rebuild or in CI. It still has to
    // know which career to load: --save names it, and when the repository holds exactly
    // one there is nothing to choose.
    $requestedSave = null;
    foreach ($argv ?? [] as $argument) {
        if (str_starts_with($argument, '--save=')) {
            $requestedSave = substr($argument, 7);
        }
    }
    try {
        foreach ($argv ?? [] as $argument) {
            if (!str_starts_with($argument, '--sqlite=')) {
                continue;
            }
            fm_config_set([
                'driver' => 'sqlite',
                'db_path' => substr($argument, 9),
                'secret' => str_repeat('0', 64),
                'active_save' => $requestedSave ?? fm_only_save(dirname(__DIR__)),
                'max_rows' => 500,
                'log_file' => null,
                'repo_root' => dirname(__DIR__),
            ]);
        }
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }

    if (in_array('--info', $argv ?? [], true)) {
        echo implode("\n", fm_host_report()), "\n";
        exit(0);
    }

    try {
        $result = fm_bootstrap($force, $resetOnly);
        echo implode("\n", $result['lines']), "\n";
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

// Web mode: same capability token as the MCP endpoint, plus an explicit confirmation
// because a rebuild replaces the live database.
$token = (string) ($_GET['token'] ?? '');
try {
    $secret = fm_config()['secret'];
} catch (Throwable $e) {
    $secret = '';
}

if ($secret === '' || $token === '' || !hash_equals($secret, $token)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not Found\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (($_GET['info'] ?? '') === '1') {
    echo implode("\n", fm_host_report()), "\n";
    exit;
}

// The host's own logs, which say whether a request reached the web server at all.
if (($_GET['logs'] ?? '') !== '') {
    $needle = (string) $_GET['logs'];
    $home = dirname((string) ($_SERVER['DOCUMENT_ROOT'] ?? '/home'));
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'fm.kplev.hu');

    // HTTPS traffic is logged separately from port 80, and the file naming differs by
    // panel and server, so every plausible location is collected rather than guessed.
    // Named directly as well as globbed: a log directory is often unreadable while the
    // files inside it can still be opened by name.
    $candidates = [
        $home . '/logs/php.error.log',
        '/usr/local/apache/domlogs/' . $host,
        '/usr/local/apache/domlogs/' . $host . '-ssl_log',
        '/usr/local/apache/domlogs/' . basename($home) . '/' . $host,
        '/usr/local/apache/domlogs/' . basename($home) . '/' . $host . '-ssl_log',
        $home . '/access-logs/' . $host,
        $home . '/access-logs/' . $host . '-ssl_log',
    ];
    foreach ([
        $home . '/access-logs/*',
        $home . '/logs/*' . $host . '*',
        '/usr/local/apache/domlogs/*' . $host . '*',
        '/usr/local/apache/domlogs/' . basename($home) . '/*',
        '/var/log/apache2/*access*',
    ] as $pattern) {
        foreach (glob($pattern) ?: [] as $match) {
            $candidates[] = $match;
        }
    }
    $candidates = array_values(array_unique($candidates));

    foreach ($candidates as $candidate) {
        echo str_pad($candidate, 60), is_readable($candidate) ? 'readable' : 'not readable', "\n";
    }
    echo str_repeat('-', 72), "\n";

    foreach ($candidates as $candidate) {
        if (!is_readable($candidate)) {
            continue;
        }
        $lines = @file($candidate, FILE_IGNORE_NEW_LINES) ?: [];
        if ($needle !== '1') {
            $lines = array_values(array_filter($lines, static fn ($l) => stripos($l, $needle) !== false));
        }
        echo "\n== ", $candidate, " (", count($lines), " matching lines) ==\n";
        echo implode("\n", array_slice($lines, -80)), "\n";
    }
    exit;
}

// The files written by import_json since the last commit. The sync only runs from the
// working copy to the host, so they have to be fetched back deliberately.
if (($_GET['pull'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $files = glob(fm_save_dir() . '/incoming/*.json') ?: [];
    sort($files);
    $bundle = ['save' => fm_active_save(), 'file_count' => count($files), 'files' => []];
    foreach ($files as $file) {
        $bundle['files'][] = [
            'name' => basename($file),
            'payload' => json_decode((string) file_get_contents($file), true),
        ];
    }
    echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

// The request trail, for working out where a client's handshake stops.
if (isset($_GET['trace'])) {
    $file = fm_trace_file();
    if ($file === null) {
        echo "Tracing is off. Set 'trace' => true in mcp/config.php.\n";
        exit;
    }
    if ($_GET['trace'] === 'clear') {
        @unlink($file);
        echo "Trace cleared.\n";
        exit;
    }
    if (!is_file($file)) {
        echo "No requests traced yet ({$file}).\n";
        exit;
    }
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    echo implode("\n", array_slice($lines, -200)), "\n";
    exit;
}

$confirm = (string) ($_GET['confirm'] ?? '');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !in_array($confirm, ['rebuild', 'reset'], true)) {
    http_response_code(400);
    echo "POST with &confirm=rebuild to build the database. Add &force=1 to replace an existing one.\n";
    echo "POST with &confirm=reset to reload the career and leave the FM26 reference alone.\n";
    echo "GET with &info=1 to see the host report.\n";
    echo "GET with &pull=1 to fetch what import_json wrote since the last commit.\n";
    exit;
}

try {
    $result = fm_bootstrap(($_GET['force'] ?? '') === '1', $confirm === 'reset');
    echo implode("\n", $result['lines']), "\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo 'ERROR: ' . $e->getMessage(), "\n";
}
