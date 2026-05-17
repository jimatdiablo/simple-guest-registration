<?php

function api_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function api_require_post(): void
{
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        api_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    }
}

function api_get_config(): array
{
    $configFile = __DIR__ . '/config.php';
    if (!is_file($configFile)) {
        api_json(['ok' => false, 'error' => 'Missing config.php'], 500);
    }

    $config = require $configFile;
    if (!is_array($config)) {
        api_json(['ok' => false, 'error' => 'Invalid config.php'], 500);
    }

    return $config;
}

function api_require_basic_auth(array $config): void
{
    $expectedUser = (string) ($config['auth']['username'] ?? '');
    $expectedPass = (string) ($config['auth']['password'] ?? '');

    $user = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
    $pass = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

    if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
        header('WWW-Authenticate: Basic realm="Gunslinger API"');
        api_json(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function api_connect(array $config): PDO
{
    $db = $config['db'] ?? [];
    $host = (string) ($db['host'] ?? '127.0.0.1');
    $port = (int) ($db['port'] ?? 3306);
    $dbname = (string) ($db['dbname'] ?? ($db['database'] ?? 'userAccounts'));
    $user = (string) ($db['user'] ?? ($db['username'] ?? ''));
    $pass = (string) ($db['pass'] ?? ($db['password'] ?? ''));

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $host,
        $port,
        $dbname
    );

    try {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        api_json(['ok' => false, 'error' => 'Database connection failed', 'details' => $e->getMessage()], 500);
    }
}

function api_post_json_or_form(): array
{
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}

function api_parse_service_groups($raw, array $fallback): array
{
    if (!is_string($raw) || trim($raw) === '') {
        return $fallback;
    }

    $parts = array_filter(array_map('trim', explode(',', $raw)), static fn ($v) => $v !== '');
    return array_values(array_unique(array_map('intval', $parts)));
}
