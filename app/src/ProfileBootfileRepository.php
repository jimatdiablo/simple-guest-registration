<?php

class ProfileBootfileRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS profile_bootfile_cache (
            sg INT NOT NULL,
            profile_name VARCHAR(128) NOT NULL,
            bootfile_filename VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (sg, profile_name),
            KEY idx_profile_bootfile_cache_sg (sg)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    public function upsertMappings(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_bootfile_cache (sg, profile_name, bootfile_filename)
             VALUES (:sg, :profile_name, :bootfile_filename)
             ON DUPLICATE KEY UPDATE
               bootfile_filename = VALUES(bootfile_filename),
               updated_at = CURRENT_TIMESTAMP'
        );

        $count = 0;
        foreach ($rows as $row) {
            $sg = (int) ($row['sg'] ?? 0);
            $profileName = trim((string) ($row['profile_name'] ?? ''));
            $bootfile = trim((string) ($row['bootfile_filename'] ?? ''));
            if ($sg <= 0 || $profileName === '' || $bootfile === '') {
                continue;
            }

            $stmt->execute([
                ':sg' => $sg,
                ':profile_name' => $profileName,
                ':bootfile_filename' => $bootfile,
            ]);
            $count++;
        }

        return $count;
    }

    public function findBootfileFilename(int $sg, string $profileName): ?string
    {
        if ($sg <= 0 || trim($profileName) === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT bootfile_filename
             FROM profile_bootfile_cache
             WHERE sg = :sg AND profile_name = :profile_name
             LIMIT 1'
        );
        $stmt->execute([
            ':sg' => $sg,
            ':profile_name' => trim($profileName),
        ]);

        $bootfile = $stmt->fetchColumn();
        if ($bootfile === false) {
            return null;
        }

        $value = trim((string) $bootfile);
        return $value === '' ? null : $value;
    }
}
