<?php
/**
 * MCP server configuration template.
 *
 * Copy to mcp/config.php and fill in. config.php is gitignored: it holds the capability
 * token that grants full read and write access to the save, and the database password.
 *
 * A local machine that needs its own database sets FM26_CONFIG to a different file
 * (mcp/config.local.php) instead of editing the host's copy.
 */

declare(strict_types=1);

return [
    // 'mysql' for the hosted database, 'sqlite' for a local file.
    'driver' => 'mysql',

    // Used when driver is 'mysql'.
    'mysql' => [
        'host' => 'localhost',
        'port' => 3306,
        'socket' => null,          // set instead of host/port if the host uses a socket
        'database' => 'DATABASE_NAME',
        'username' => 'DATABASE_USER',
        'password' => 'DATABASE_PASSWORD',
        'charset' => 'utf8mb4',
    ],

    // Used when driver is 'sqlite'. Must be outside the web root: no HTTP request may
    // ever be able to fetch the raw file.
    'db_path' => '/home/USER/fm26-data/fm26.sqlite3',

    // Capability token that forms the URL path: https://host/mcp/<secret>/
    // Generate with:  php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
    // Must be at least 32 characters.
    'secret' => 'REPLACE-WITH-64-HEX-CHARACTERS',

    // Demand a bearer token in addition to the secret path. The token adds nothing on
    // its own, since the path is the credential; set it only if a client refuses to
    // start its OAuth flow without a 401 first.
    'require_bearer' => false,

    // The career directory under data/saves/ that gets loaded. Switching careers means
    // changing this and rebuilding; the previous career stays in the repository.
    'active_save' => 'valencia-2025-26',

    // Maximum number of rows the query tool returns before truncating.
    'max_rows' => 500,

    // Absolute path to the server-side error log, outside the web root. null disables
    // file logging.
    'log_file' => null,

    // Write a line per request to a trail readable at
    // bootstrap.php?token=<secret>&trace=1. Useful while a client's handshake is
    // failing; true keeps it in the system temporary directory, or give a path.
    'trace' => false,

    // Repository root used by bootstrap.php to locate db/ and data/.
    // Defaults to the parent directory of mcp/ when null.
    'repo_root' => null,
];
