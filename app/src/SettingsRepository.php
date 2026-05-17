<?php

class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTable();
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :key LIMIT 1');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch();

        if ($row === false) {
            return $default;
        }

        return (string) $row['setting_value'];
    }

    public function set(string $key, string $value): void
    {
        $sql = 'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
        ]);
    }

    private function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }
}
