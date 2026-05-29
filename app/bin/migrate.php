<?php

require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/MigrationRunner.php';

$config = Config::fromEnvironment();
date_default_timezone_set($config->get('APP_TIMEZONE', 'America/New_York'));

$attempts = max(1, (int) (getenv('SGR_MIGRATION_DB_ATTEMPTS') ?: 30));
$delaySeconds = max(1, (int) (getenv('SGR_MIGRATION_DB_DELAY_SECONDS') ?: 2));
$pdo = null;
$lastError = null;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
        $pdo = Database::connect($config);
        break;
    } catch (Throwable $e) {
        $lastError = $e;
        if ($attempt === $attempts) {
            break;
        }
        fwrite(STDERR, sprintf(
            "[%s] waiting for database before migrations (%d/%d): %s\n",
            date(DATE_ATOM),
            $attempt,
            $attempts,
            $e->getMessage()
        ));
        sleep($delaySeconds);
    }
}

if (!$pdo instanceof PDO) {
    throw new RuntimeException('Database unavailable for migrations: ' . ($lastError?->getMessage() ?? 'unknown error'));
}

$runner = new MigrationRunner($pdo, $config);
$result = $runner->run(__DIR__ . '/../migrations');

printf(
    "[%s] migrations complete; applied=%d; skipped=%d\n",
    date(DATE_ATOM),
    count($result['applied']),
    count($result['skipped'])
);

if ($result['applied'] !== []) {
    printf("Applied migrations: %s\n", implode(', ', $result['applied']));
}
