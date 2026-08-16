<?php
/**
 * OAuth 2.1 layer for the MCP endpoint.
 *
 * The capability URL is the real credential: whoever knows https://host/mcp/<secret>/
 * has full access, and that is checked on every request regardless of what happens
 * here. This layer exists because the client insists on it — claude.ai always runs the
 * OAuth discovery and dynamic client registration sequence against a custom connector
 * and refuses to connect when it 404s, with no way to declare that a server needs no
 * authorization. So the endpoint answers an unauthenticated request with 401 and a
 * WWW-Authenticate header, and serves the metadata, registration, authorization and
 * token endpoints the client then walks through.
 *
 * Nothing is stored. Client identifiers, authorization codes and tokens are payloads
 * signed with the capability secret, so they verify by recomputation. A rebuild of the
 * database does not invalidate a connector, and rotating the secret invalidates every
 * token at once, which is what rotation is for.
 *
 * Routes, all mapped from the document root by .htaccess:
 *   GET  /.well-known/oauth-protected-resource[/<path>]   RFC 9728 resource metadata
 *   GET  /.well-known/oauth-authorization-server[/<path>]  RFC 8414 server metadata
 *   POST /oauth/register                                   RFC 7591 registration
 *   GET  /oauth/authorize                                  authorization endpoint
 *   POST /oauth/token                                      token endpoint
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const FM_OAUTH_CODE_TTL = 600;            // 10 minutes
const FM_OAUTH_ACCESS_TTL = 2592000;      // 30 days
const FM_OAUTH_REFRESH_TTL = 31536000;    // 365 days

/* ------------------------------------------------------------ signed payloads */

function fm_base64url_encode(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function fm_base64url_decode(string $encoded): string
{
    $padded = strtr($encoded, '-_', '+/');
    $remainder = strlen($padded) % 4;
    if ($remainder !== 0) {
        $padded .= str_repeat('=', 4 - $remainder);
    }

    return (string) base64_decode($padded, true);
}

/** Sign a payload with the capability secret. */
function fm_oauth_sign(array $payload): string
{
    $body = fm_base64url_encode((string) json_encode($payload));
    $signature = hash_hmac('sha256', $body, fm_config()['secret'], true);

    return $body . '.' . fm_base64url_encode($signature);
}

/**
 * Verify a signed payload and return it, or null when the signature, the type or the
 * expiry does not hold.
 */
function fm_oauth_verify(string $token, string $expectedType): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return null;
    }
    [$body, $signature] = $parts;

    $expected = hash_hmac('sha256', $body, fm_config()['secret'], true);
    if (!hash_equals($expected, fm_base64url_decode($signature))) {
        return null;
    }

    $payload = json_decode(fm_base64url_decode($body), true);
    if (!is_array($payload) || ($payload['t'] ?? null) !== $expectedType) {
        return null;
    }
    if (isset($payload['exp']) && time() > (int) $payload['exp']) {
        return null;
    }

    return $payload;
}

/** True when the request carries an access token this server issued. */
function fm_oauth_bearer_valid(): bool
{
    $header = '';
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION', 'REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        if (!empty($_SERVER[$key])) {
            $header = (string) $_SERVER[$key];
            break;
        }
    }
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() ?: [] as $name => $value) {
            if (strcasecmp($name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return false;
    }

    return fm_oauth_verify(trim($m[1]), 'at') !== null;
}

/* ---------------------------------------------------------------- addressing */

function fm_oauth_origin(): string
{
    $scheme = 'https';
    if (empty($_SERVER['HTTPS']) && ($_SERVER['SERVER_PORT'] ?? '443') === '80') {
        $scheme = 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    return $scheme . '://' . $host;
}

/** The canonical resource identifier of the MCP endpoint, secret included. */
function fm_oauth_resource(): string
{
    return fm_oauth_origin() . '/mcp/' . fm_config()['secret'];
}

/** The metadata URL a 401 points at, per RFC 9728. */
function fm_oauth_resource_metadata_url(): string
{
    return fm_oauth_origin() . '/.well-known/oauth-protected-resource/mcp/' . fm_config()['secret'];
}

/* -------------------------------------------------------------- HTTP helpers */

function fm_oauth_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function fm_oauth_error(string $error, string $description, int $status = 400): void
{
    fm_oauth_json(['error' => $error, 'error_description' => $description], $status);
}

/* -------------------------------------------------------------- the endpoints */

function fm_oauth_protected_resource_metadata(): array
{
    return [
        'resource' => fm_oauth_resource(),
        'authorization_servers' => [fm_oauth_origin()],
        'scopes_supported' => ['mcp'],
        'bearer_methods_supported' => ['header'],
        'resource_documentation' => fm_oauth_origin() . '/mcp/',
    ];
}

function fm_oauth_authorization_server_metadata(): array
{
    $origin = fm_oauth_origin();

    return [
        'issuer' => $origin,
        'authorization_endpoint' => $origin . '/oauth/authorize',
        'token_endpoint' => $origin . '/oauth/token',
        'registration_endpoint' => $origin . '/oauth/register',
        'scopes_supported' => ['mcp'],
        'response_types_supported' => ['code'],
        'response_modes_supported' => ['query'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'code_challenge_methods_supported' => ['S256'],
        'service_documentation' => $origin . '/mcp/',
    ];
}

/**
 * RFC 7591 registration. The client identifier is a signed record of what was
 * registered, so the authorization endpoint can validate a redirect URI later without
 * anything having been stored.
 */
function fm_oauth_register(): void
{
    $raw = (string) file_get_contents('php://input');
    $request = json_decode($raw, true);
    if (!is_array($request)) {
        fm_oauth_error('invalid_client_metadata', 'The registration request must be a JSON object.');

        return;
    }

    $redirectUris = $request['redirect_uris'] ?? [];
    if (!is_array($redirectUris) || $redirectUris === []) {
        fm_oauth_error('invalid_redirect_uri', 'At least one redirect_uri is required.');

        return;
    }
    foreach ($redirectUris as $uri) {
        if (!is_string($uri) || !preg_match('#^https://|^http://(localhost|127\.0\.0\.1)([:/]|$)#i', $uri)) {
            fm_oauth_error('invalid_redirect_uri', 'Redirect URIs must use https, or http on localhost.');

            return;
        }
    }

    // Register the subset that is supported rather than rejecting the request over a
    // grant type or an authentication method that was merely asked for.
    $grantTypes = array_values(array_intersect(
        is_array($request['grant_types'] ?? null) ? $request['grant_types'] : ['authorization_code'],
        ['authorization_code', 'refresh_token']
    ));
    if ($grantTypes === []) {
        $grantTypes = ['authorization_code'];
    }

    $issuedAt = time();
    $clientId = fm_oauth_sign([
        't' => 'client',
        'iat' => $issuedAt,
        'ru' => array_values($redirectUris),
    ]);

    fm_oauth_json([
        'client_id' => $clientId,
        'client_id_issued_at' => $issuedAt,
        'redirect_uris' => array_values($redirectUris),
        'grant_types' => $grantTypes,
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp',
        'client_name' => is_string($request['client_name'] ?? null) ? $request['client_name'] : 'MCP client',
    ], 201);
}

/**
 * Authorization endpoint.
 *
 * There is no consent screen and no login: the capability URL already establishes who
 * may connect, and a code minted here is worthless without it, because every MCP
 * request still has to arrive on the secret path. So the endpoint validates the
 * request and redirects straight back.
 */
function fm_oauth_authorize(): void
{
    $clientId = (string) ($_GET['client_id'] ?? '');
    $redirectUri = (string) ($_GET['redirect_uri'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    $challenge = (string) ($_GET['code_challenge'] ?? '');
    $challengeMethod = strtoupper((string) ($_GET['code_challenge_method'] ?? 'S256'));

    if (($_GET['response_type'] ?? '') !== 'code') {
        fm_oauth_error('unsupported_response_type', 'Only the authorization code flow is supported.');

        return;
    }

    $client = fm_oauth_verify($clientId, 'client');
    if ($client === null) {
        fm_oauth_error('invalid_client', 'Unknown or expired client_id. Register again.', 401);

        return;
    }
    if ($redirectUri === '' || !in_array($redirectUri, $client['ru'] ?? [], true)) {
        // Never redirect to an address that was not registered.
        fm_oauth_error('invalid_request', 'The redirect_uri does not match the registered client.');

        return;
    }
    if ($challenge === '' || $challengeMethod !== 'S256') {
        fm_oauth_error('invalid_request', 'PKCE with code_challenge_method=S256 is required.');

        return;
    }

    $code = fm_oauth_sign([
        't' => 'code',
        'exp' => time() + FM_OAUTH_CODE_TTL,
        'cc' => $challenge,
        'ru' => $redirectUri,
        'ci' => $clientId,
    ]);

    $separator = str_contains($redirectUri, '?') ? '&' : '?';
    $location = $redirectUri . $separator . http_build_query(
        $state === '' ? ['code' => $code] : ['code' => $code, 'state' => $state]
    );

    // 303 so the callback is fetched with GET; a 307 would preserve the method.
    http_response_code(303);
    header('Location: ' . $location);
    header('Cache-Control: no-store');
}

/** Token endpoint: authorization_code with PKCE, and refresh_token. */
function fm_oauth_token(): void
{
    $grantType = (string) ($_POST['grant_type'] ?? '');

    if ($grantType === 'authorization_code') {
        $code = fm_oauth_verify((string) ($_POST['code'] ?? ''), 'code');
        if ($code === null) {
            fm_oauth_error('invalid_grant', 'The authorization code is invalid or has expired.');

            return;
        }

        $verifier = (string) ($_POST['code_verifier'] ?? '');
        $computed = fm_base64url_encode(hash('sha256', $verifier, true));
        if ($verifier === '' || !hash_equals((string) $code['cc'], $computed)) {
            fm_oauth_error('invalid_grant', 'The code_verifier does not match the code_challenge.');

            return;
        }

        $redirectUri = (string) ($_POST['redirect_uri'] ?? '');
        if ($redirectUri !== '' && !hash_equals((string) $code['ru'], $redirectUri)) {
            fm_oauth_error('invalid_grant', 'The redirect_uri does not match the authorization request.');

            return;
        }
    } elseif ($grantType === 'refresh_token') {
        if (fm_oauth_verify((string) ($_POST['refresh_token'] ?? ''), 'rt') === null) {
            fm_oauth_error('invalid_grant', 'The refresh token is invalid or has expired.');

            return;
        }
    } else {
        fm_oauth_error('unsupported_grant_type', 'Supported grants: authorization_code, refresh_token.');

        return;
    }

    $now = time();
    fm_oauth_json([
        'access_token' => fm_oauth_sign(['t' => 'at', 'iat' => $now, 'exp' => $now + FM_OAUTH_ACCESS_TTL]),
        'token_type' => 'Bearer',
        'expires_in' => FM_OAUTH_ACCESS_TTL,
        'refresh_token' => fm_oauth_sign(['t' => 'rt', 'iat' => $now, 'exp' => $now + FM_OAUTH_REFRESH_TTL]),
        'scope' => 'mcp',
    ]);
}

/* -------------------------------------------------------------------- routing */

/** Work out which endpoint was asked for, from the rewrite or from the raw path. */
function fm_oauth_route(): string
{
    foreach (['FM_OAUTH_ROUTE', 'REDIRECT_FM_OAUTH_ROUTE', 'REDIRECT_REDIRECT_FM_OAUTH_ROUTE'] as $key) {
        if (!empty($_SERVER[$key])) {
            return (string) $_SERVER[$key];
        }
    }

    $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
    if (str_contains($path, '/.well-known/oauth-protected-resource')) {
        return 'protected-resource';
    }
    if (
        str_contains($path, '/.well-known/oauth-authorization-server')
        || str_contains($path, '/.well-known/openid-configuration')
    ) {
        return 'authorization-server';
    }
    foreach (['register', 'authorize', 'token'] as $endpoint) {
        if (str_ends_with($path, '/oauth/' . $endpoint)) {
            return $endpoint;
        }
    }

    return '';
}

// Included by server.php for the helpers alone; only a direct request routes.
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) !== basename(__FILE__)) {
    return;
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

try {
    switch (fm_oauth_route()) {
        case 'protected-resource':
            fm_oauth_json(fm_oauth_protected_resource_metadata());
            break;

        case 'authorization-server':
            fm_oauth_json(fm_oauth_authorization_server_metadata());
            break;

        case 'register':
            fm_oauth_register();
            break;

        case 'authorize':
            fm_oauth_authorize();
            break;

        case 'token':
            fm_oauth_token();
            break;

        default:
            http_response_code(404);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'not_found']);
    }
} catch (Throwable $e) {
    error_log('fm26-mcp oauth: ' . $e->getMessage());
    fm_oauth_error('server_error', 'The authorization server failed to handle the request.', 500);
}
