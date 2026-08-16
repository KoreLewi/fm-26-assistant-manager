<?php
/**
 * Error document that records the request before answering.
 *
 * A request that is refused by the web server never reaches a PHP route, so it leaves
 * no trace and cannot be told apart from one that never arrived. Wiring this file to
 * ErrorDocument closes that gap: anything the server rejects is still recorded, with
 * the status it was rejected with.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
require_once __DIR__ . '/oauth.php';

$status = (int) ($_SERVER['REDIRECT_STATUS'] ?? 404);
if ($status < 400 || $status > 599) {
    $status = 404;
}

fm_trace('refused', [
    'status' => $status,
    'query' => substr(preg_replace('/[A-Fa-f0-9]{32,}/', '<secret>', (string) ($_SERVER['QUERY_STRING'] ?? '')), 0, 120),
]);

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo $status === 403 ? "Forbidden\n" : "Not Found\n";
