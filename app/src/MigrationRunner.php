<?php

class MigrationRunner
{
    public function __construct(private PDO $pdo, private Config $config)
    {
    }

    public function run(string $migrationDir): array
    {
        $this->ensureMigrationsTable();

        $files = glob(rtrim($migrationDir, '/\\') . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false) {
            $files = [];
        }
        sort($files, SORT_STRING);

        $applied = [];
        $skipped = [];

        foreach ($files as $file) {
            $version = basename($file, '.php');
            if ($this->migrationApplied($version)) {
                $skipped[] = $version;
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new RuntimeException("Migration {$version} must return a callable.");
            }

            $migration($this);
            $this->recordMigration($version);
            $applied[] = $version;
        }

        $this->seedDefaults();

        return [
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute([
            ':table' => $table,
            ':column' => $column,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name'
        );
        $stmt->execute([
            ':table' => $table,
            ':index_name' => $index,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function addColumnIfMissing(string $table, string $column, string $definition): void
    {
        if (!$this->columnExists($table, $column)) {
            $this->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }

    public function createIndexIfMissing(string $table, string $index, string $definition): void
    {
        if (!$this->indexExists($table, $index)) {
            $this->exec("CREATE {$definition}");
        }
    }

    public function seedSettingIfMissing(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (:key, :value)'
        );
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(128) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    private function migrationApplied(string $version): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :version LIMIT 1');
        $stmt->execute([':version' => $version]);
        return $stmt->fetchColumn() !== false;
    }

    private function recordMigration(string $version): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:version)');
        $stmt->execute([':version' => $version]);
    }

    private function seedDefaults(): void
    {
        if (!$this->tableExists('app_settings')) {
            return;
        }

        $defaultProfile = $this->config->get('DEFAULT_SERVICE_PROFILE', '10baseservice');
        $this->seedSettingIfMissing('default_service_profile', $defaultProfile);
        $this->seedSettingIfMissing('global_checkout_time', '11:00');
        $this->seedSettingIfMissing('color_preset', 'forest');
        $this->seedSettingIfMissing('guest_self_service_enabled', '0');
        $this->seedSettingIfMissing('guest_self_service_allow_early_checkout', '1');
        $this->seedSettingIfMissing('guest_self_service_allow_extension', '1');
        $this->seedSettingIfMissing('guest_self_service_require_reason', '0');
        $this->seedSettingIfMissing('guest_self_service_approval_mode', 'manual');
        $this->seedSettingIfMissing('guest_self_service_max_extension_days', '7');
        $this->seedSettingIfMissing('guest_self_service_max_failed_attempts', '5');
        $this->seedSettingIfMissing('guest_self_service_lockout_minutes', '15');
        $this->seedSettingIfMissing('guest_self_service_ip_window_minutes', '10');
        $this->seedSettingIfMissing('guest_self_service_ip_failure_threshold', '20');
        $this->seedSettingIfMissing('guest_self_service_auth_mode', 'id_and_code');

        foreach ($this->config->serviceGroups() as $sg) {
            $fallbackProfile = str_pad((string) $sg, 2, '0', STR_PAD_LEFT) . 'baseservice';
            $vacantProfile = str_pad((string) $sg, 2, '0', STR_PAD_LEFT) . 'vacant';
            $this->seedSettingIfMissing('default_service_profile_sg_' . $sg, $fallbackProfile);
            $this->seedSettingIfMissing('vacant_profile_sg_' . $sg, $vacantProfile);
        }

        $this->seedMasterAdmins();
    }

    private function seedMasterAdmins(): void
    {
        if (!$this->tableExists('app_users')) {
            return;
        }

        $raw = trim($this->config->get('SGR_MASTER_ADMIN_SEEDS', ''));
        if ($raw === '') {
            return;
        }

        foreach (explode(',', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '' || !str_contains($entry, ':')) {
                continue;
            }

            [$username, $password] = array_map('trim', explode(':', $entry, 2));
            $username = strtolower($username);
            if ($username === '' || $password === '') {
                continue;
            }

            $stmt = $this->pdo->prepare('SELECT id, password_hash FROM app_users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $hash = password_hash($password, PASSWORD_DEFAULT);

            if ($row === false) {
                $insert = $this->pdo->prepare(
                    "INSERT INTO app_users (username, password_hash, role, is_active)
                     VALUES (:username, :password_hash, 'master_admin', 1)"
                );
                $insert->execute([
                    ':username' => $username,
                    ':password_hash' => $hash,
                ]);
                continue;
            }

            if (!password_verify($password, (string) ($row['password_hash'] ?? ''))) {
                $update = $this->pdo->prepare(
                    "UPDATE app_users
                     SET password_hash = :password_hash, role = 'master_admin', is_active = 1
                     WHERE username = :username"
                );
                $update->execute([
                    ':username' => $username,
                    ':password_hash' => $hash,
                ]);
            }
        }
    }
}
