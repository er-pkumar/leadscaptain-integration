<?php

declare(strict_types=1);

/**
 * Mock Leadscaptain API for local development without an API key.
 *
 * Serves GET /api/v1/leads in the documented shape
 * ({"data": [...], "pagination": {page, limit, total, total_pages}}) and
 * GET /api/v1/leads/count. Leads are generated from their id, so every run
 * returns the same data. Failures are switched on with MOCK_* env vars
 * (see README.md next to this file).
 *
 * Run: php -S 0.0.0.0:8081 router.php
 */

const STATE_FILE = '/tmp/leadscaptain-mock-state.json';

$config = [
    'api_key' => env_string('MOCK_API_KEY', 'local-dev-key'),
    'total' => max(0, env_int('MOCK_TOTAL_LEADS', 1234)),
    'default_limit' => max(1, env_int('MOCK_DEFAULT_LIMIT', 20)),
    'max_limit' => max(1, env_int('MOCK_MAX_LIMIT', 100)),
    'latency_ms' => max(0, env_int('MOCK_LATENCY_MS', 0)),
    'fail_pages' => env_ints('MOCK_FAIL_PAGES'),
    'flaky_pages' => env_ints('MOCK_FLAKY_PAGES'),
    'initializing_requests' => max(0, env_int('MOCK_503_FIRST_REQUESTS', 0)),
    'rate_limit' => max(0, env_int('MOCK_RATE_LIMIT', 0)),
    'rate_window' => max(1, env_int('MOCK_RATE_WINDOW', 60)),
    'hide_pagination' => env_bool('MOCK_HIDE_PAGINATION'),
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

// ---- Mock control routes (no auth) ----------------------------------------------
if ($path === '/health') {
    respond(200, ['status' => 'ok']);
}

if ($path === '/__mock/reset' && $method === 'POST') {
    with_state(static fn (array $state): array => [null, fresh_state()]);
    respond(200, ['status' => 'reset']);
}

if ($path === '/__mock/state' && $method === 'GET') {
    respond(200, ['config' => array_diff_key($config, ['api_key' => true]), 'state' => with_state(static fn (array $state): array => [$state, $state])]);
}

// ---- API routes -----------------------------------------------------------------
if (! in_array($path, ['/api/v1/leads', '/api/v1/leads/count'], true)) {
    respond(404, ['message' => 'Not Found']);
}

if ($method !== 'GET') {
    respond(405, ['message' => 'Method Not Allowed'], ['Allow' => 'GET']);
}

if (! authorised($config['api_key'])) {
    respond(401, ['message' => 'Missing or invalid API token']);
}

if ($config['latency_ms'] > 0) {
    usleep($config['latency_ms'] * 1000);
}

$page = query_int('page', 1);
$limit = query_int('limit', $config['default_limit']);

if ($page === null || $page < 1 || $limit === null || $limit < 1) {
    respond(422, ['message' => 'page and limit must be positive integers']);
}

$limit = min($limit, $config['max_limit']);

// Server-wide conditions, decided under one lock so concurrent workers agree.
[$status, $retryAfter] = with_state(static function (array $state) use ($config, $page, $path): array {
    $state['requests']++;
    $now = microtime(true);

    if ($state['requests'] <= $config['initializing_requests']) {
        return [[503, null], $state];
    }

    if ($config['rate_limit'] > 0) {
        $state['hits'] = array_values(array_filter($state['hits'], static fn (float $at): bool => $at > $now - $config['rate_window']));

        if (count($state['hits']) >= $config['rate_limit']) {
            return [[429, max(1, (int) ceil($state['hits'][0] + $config['rate_window'] - $now))], $state];
        }

        $state['hits'][] = $now;
    }

    if ($path === '/api/v1/leads' && in_array($page, $config['flaky_pages'], true) && ! in_array($page, $state['flaky_failed'], true)) {
        $state['flaky_failed'][] = $page;

        return [[500, null], $state];
    }

    return [[200, null], $state];
});

if ($status === 503) {
    respond(503, ['message' => 'Database is initializing']);
}

if ($status === 429) {
    respond(429, ['message' => 'Too Many Requests'], ['Retry-After' => (string) $retryAfter]);
}

if ($status === 500 || ($path === '/api/v1/leads' && in_array($page, $config['fail_pages'], true))) {
    respond(500, ['message' => 'Internal Server Error']);
}

if ($path === '/api/v1/leads/count') {
    respond(200, ['count' => $config['total']]);
}

$first = ($page - 1) * $limit + 1;
$last = min($config['total'], $page * $limit);
$body = ['data' => $first <= $last ? array_map('lead', range($first, $last)) : []];

if (! $config['hide_pagination']) {
    $body['pagination'] = [
        'page' => $page,
        'limit' => $limit,
        'total' => $config['total'],
        'total_pages' => (int) ceil($config['total'] / $limit),
    ];
}

respond(200, $body);

// ---- Helpers --------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function lead(int $id): array
{
    $pick = static fn (array $values, int $salt): mixed => $values[($id * 31 + $salt) % count($values)];

    $first = $pick(['John', 'Priya', 'Maria', 'Ahmed', 'Chen', 'Olga', 'Diego', 'Aisha', 'Liam', 'Yuki'], 1);
    $last = $pick(['Doe', 'Sharma', 'Garcia', 'Khan', 'Wei', 'Petrova', 'Silva', 'Bello', 'Murphy', 'Tanaka'], 7);

    return [
        'id' => $id,
        'first_name' => $first,
        'last_name' => $last,
        'email' => strtolower("{$first}.{$last}.{$id}@example.com"),
        'position_title' => $pick(['Senior Developer', 'CTO', 'Product Manager', 'Data Engineer', 'Head of Sales', 'Marketing Lead'], 3),
        'company_name' => $pick(['ABC Technologies', 'Globex', 'Initech', 'Umbrella Corp', 'Stark Industries', 'Wayne Enterprises'], 5),
        'country_code' => $pick(['IN', 'US', 'GB', 'DE', 'RO', 'BR', 'JP', 'NG'], 11),
        'industry_name' => $pick(['Technology', 'Finance', 'Healthcare', 'Retail', 'Manufacturing'], 13),
        'email_status' => $pick(['verified', 'unverified', 'catch_all'], 17),
    ];
}

function authorised(string $apiKey): bool
{
    $header = $_SERVER['HTTP_X_API_KEY'] ?? null;
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (is_string($header) && hash_equals($apiKey, $header)) {
        return true;
    }

    return str_starts_with($authorization, 'Bearer ') && hash_equals($apiKey, substr($authorization, 7));
}

/**
 * @param  array<string, mixed>  $body
 * @param  array<string, string>  $headers
 */
function respond(int $status, array $body, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json');

    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }

    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Runs $fn with the shared state under an exclusive lock.
 * $fn returns [result, newState].
 *
 * @param  callable(array<string, mixed>): array{0: mixed, 1: array<string, mixed>}  $fn
 */
function with_state(callable $fn): mixed
{
    $handle = fopen(STATE_FILE, 'c+');

    if ($handle === false) {
        respond(500, ['message' => 'Mock state file is not writable']);
    }

    flock($handle, LOCK_EX);

    $decoded = json_decode((string) stream_get_contents($handle), true);
    $state = (is_array($decoded) ? $decoded : []) + fresh_state();

    [$result, $state] = $fn($state);

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, (string) json_encode($state));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

/**
 * @return array{requests: int, hits: list<float>, flaky_failed: list<int>}
 */
function fresh_state(): array
{
    return ['requests' => 0, 'hits' => [], 'flaky_failed' => []];
}

function query_int(string $name, int $default): ?int
{
    $value = $_GET[$name] ?? null;

    if ($value === null || $value === '') {
        return $default;
    }

    return is_string($value) && ctype_digit($value) ? (int) $value : null;
}

function env_string(string $name, string $default): string
{
    $value = getenv($name);

    return $value === false || $value === '' ? $default : $value;
}

function env_int(string $name, int $default): int
{
    $value = getenv($name);

    return $value !== false && is_numeric($value) ? (int) $value : $default;
}

function env_bool(string $name): bool
{
    return in_array(strtolower((string) getenv($name)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return list<int>
 */
function env_ints(string $name): array
{
    $parts = array_filter(array_map('trim', explode(',', (string) getenv($name))), 'ctype_digit');

    return array_values(array_map('intval', $parts));
}
