<?php

/**
 * Local mock of the Parse for Artisans SaaS API.
 *
 * Implements the endpoints in architecture/sdk-saas-contract.md well enough to
 * exercise the SDK's full parse roundtrip locally, before the real SaaS parse
 * endpoints exist. It does not actually parse: it returns a deterministic fake
 * Markdown document after a short simulated delay.
 *
 * Run it with the built-in PHP server (or `php artisan parse:mock`):
 *
 *   php -S 127.0.0.1:9321 vendor/parseforartisans/laravel/mock/server.php
 *
 * Then point the SDK at it: PARSE_BASE_URL=http://127.0.0.1:9321
 *
 * Env knobs:
 *   MOCK_DELAY  seconds a job stays "pending" before completing (default 3)
 *   MOCK_FAIL   extension that should fail asynchronously, e.g. "pdf" (optional)
 */
const SUPPORTED = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'csv', 'eml', 'msg'];
const PAGES_ALLOWED = ['pdf', 'ppt', 'pptx', 'xls', 'xlsx', 'csv'];

$delay = (int) (getenv('MOCK_DELAY') ?: 3);
$failExtension = getenv('MOCK_FAIL') ?: null;

$method = $_SERVER['REQUEST_METHOD'];
$path = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');

if (! authenticated()) {
    json(['error' => ['type' => 'invalid_api_key', 'message' => 'Missing or invalid API key.']], 401);
}

// GET /api/v1/ping
if ($method === 'GET' && $path === '/api/v1/ping') {
    json(['ok' => true, 'plan' => 'free']);
}

// POST /api/v1/parse
if ($method === 'POST' && $path === '/api/v1/parse') {
    handleSubmit($delay, $failExtension);
}

// GET /api/v1/parse/{id}/markdown
if ($method === 'GET' && preg_match('#^/api/v1/parse/([^/]+)/markdown$#', $path, $m)) {
    handleMarkdown($m[1]);
}

// GET /api/v1/parse/{id}
if ($method === 'GET' && preg_match('#^/api/v1/parse/([^/]+)$#', $path, $m)) {
    handleStatus($m[1]);
}

json(['error' => ['type' => 'invalid_request', 'message' => "No route for {$method} {$path}."]], 404);

// --- handlers ---------------------------------------------------------------

function handleSubmit(int $delay, ?string $failExtension): void
{
    $payload = json_decode($_POST['payload'] ?? '', true);

    if (! is_array($payload) || empty($payload['id']) || empty($payload['extension'])) {
        json(['error' => ['type' => 'invalid_request', 'message' => 'Malformed payload.']], 400);
    }

    $extension = strtolower($payload['extension']);
    $options = $payload['options'] ?? [];

    if (! in_array($extension, SUPPORTED, true)) {
        json(['error' => ['type' => 'unsupported_type', 'message' => "Extension '{$extension}' is not supported."]], 422);
    }

    if (($options['pages'] ?? null) !== null && ! in_array($extension, PAGES_ALLOWED, true)) {
        json(['error' => ['type' => 'unsupported_option', 'message' => "Option 'pages' is not valid for '{$extension}'."]], 422);
    }

    if (! isset($_FILES['file'])) {
        json(['error' => ['type' => 'invalid_request', 'message' => 'Missing file part.']], 400);
    }

    $id = $payload['id'];
    $size = (int) ($_FILES['file']['size'] ?? 0);
    $filename = $payload['filename'] ?? $_FILES['file']['name'] ?? "file.{$extension}";
    $willFail = $failExtension !== null && $extension === strtolower($failExtension);

    $markdown = '# '.basename($filename)."\n\n".
        "Parsed by the Parse for Artisans **mock** backend.\n\n".
        "- extension: `{$extension}`\n".
        "- bytes: {$size}\n".
        '- options: `'.json_encode($options)."`\n";

    $now = time();
    $meta = [
        'id' => $id,
        'extension' => $extension,
        'filename' => $filename,
        'page_count' => pageCountFor($extension),
        'created_at' => $now,
        'complete_after' => $now + $delay,
        'will_fail' => $willFail,
    ];

    store($id, $meta, $markdown);

    json(['id' => $id, 'status' => 'pending'], 202);
}

function handleStatus(string $id): void
{
    $meta = load($id);

    if ($meta === null) {
        json(['error' => ['type' => 'invalid_request', 'message' => 'Unknown id.']], 404);
    }

    $base = [
        'id' => $id,
        'status' => 'pending',
        'page_count' => null,
        'credits_used' => null,
        'started_at' => null,
        'completed_at' => null,
        'duration_ms' => null,
        'error' => null,
    ];

    if (time() < $meta['complete_after']) {
        json($base);
    }

    $startedAt = gmdate('Y-m-d\TH:i:s\Z', $meta['created_at']);
    $completedAt = gmdate('Y-m-d\TH:i:s\Z', $meta['complete_after']);

    if ($meta['will_fail']) {
        json(array_merge($base, [
            'status' => 'failed',
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'error' => ['type' => 'parse_error', 'message' => 'Mock failure (MOCK_FAIL).'],
        ]));
    }

    json(array_merge($base, [
        'status' => 'completed',
        'page_count' => $meta['page_count'],
        'credits_used' => $meta['page_count'],
        'started_at' => $startedAt,
        'completed_at' => $completedAt,
        'duration_ms' => max(1, ($meta['complete_after'] - $meta['created_at'])) * 1000,
    ]));
}

function handleMarkdown(string $id): void
{
    $meta = load($id);
    $markdown = markdownPath($id);

    if ($meta === null || ! is_file($markdown) || time() < $meta['complete_after'] || $meta['will_fail']) {
        json(['error' => ['type' => 'invalid_request', 'message' => 'Result not available.']], 404);
    }

    header('Content-Type: text/markdown');
    echo file_get_contents($markdown);
    exit;
}

// --- helpers ----------------------------------------------------------------

function authenticated(): bool
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    return str_starts_with($auth, 'Bearer pfa_');
}

function pageCountFor(string $extension): int
{
    return match ($extension) {
        'pdf' => 3,
        'pptx', 'ppt' => 5,
        'xlsx', 'xls', 'csv' => 2,
        default => 1,
    };
}

function storageDir(): string
{
    $dir = sys_get_temp_dir().'/parse-mock';

    if (! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function metaPath(string $id): string
{
    return storageDir().'/'.preg_replace('/[^a-z0-9\-]/i', '', $id).'.json';
}

function markdownPath(string $id): string
{
    return storageDir().'/'.preg_replace('/[^a-z0-9\-]/i', '', $id).'.md';
}

function store(string $id, array $meta, string $markdown): void
{
    file_put_contents(metaPath($id), json_encode($meta));
    file_put_contents(markdownPath($id), $markdown);
}

function load(string $id): ?array
{
    $path = metaPath($id);

    return is_file($path) ? json_decode(file_get_contents($path), true) : null;
}

function json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
