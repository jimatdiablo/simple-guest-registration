<?php

class ModemRepository
{
    private array $excludedUnits;

    public function __construct(private PDO $pdo, array $excludedUnits = ['stock'])
    {
        $this->excludedUnits = array_values(array_unique(array_map('strtolower', array_filter(array_map('trim', $excludedUnits), static fn ($v) => $v !== ''))));
        $this->ensureLeaseColumns();
    }

    private function tableColumns(string $table): array
    {
        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    private function ensureLeaseColumns(): void
    {
        $columns = $this->tableColumns('modems');
        if (!isset($columns['lease_active'])) {
            $this->pdo->exec('ALTER TABLE modems ADD COLUMN lease_active TINYINT(1) NULL AFTER mac');
        }
        if (!isset($columns['lease_ip'])) {
            $this->pdo->exec('ALTER TABLE modems ADD COLUMN lease_ip VARCHAR(45) NULL AFTER lease_active');
        }
        if (!isset($columns['lease_error'])) {
            $this->pdo->exec('ALTER TABLE modems ADD COLUMN lease_error VARCHAR(255) NULL AFTER lease_ip');
        }
        if (!isset($columns['lease_checked_at'])) {
            $this->pdo->exec('ALTER TABLE modems ADD COLUMN lease_checked_at DATETIME NULL AFTER lease_error');
        }
    }

    public function listUnits(array $serviceGroups): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT DISTINCT unit FROM modems WHERE sg IN ($placeholders) AND unit <> '' $excludeClause ORDER BY unit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return array_map(static fn ($row) => $row['unit'], $stmt->fetchAll());
    }

    public function listUnitsByServiceGroup(array $serviceGroups): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT DISTINCT sg, unit FROM modems WHERE sg IN ($placeholders) AND unit <> '' $excludeClause ORDER BY sg ASC, unit ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $sg = (string) ((int) ($row['sg'] ?? 0));
            $unit = (string) ($row['unit'] ?? '');
            if ($unit === '') {
                continue;
            }

            if (!isset($grouped[$sg])) {
                $grouped[$sg] = [];
            }
            $grouped[$sg][] = $unit;
        }

        return $grouped;
    }

    public function findByUnitAndServiceGroup(string $unit, int $serviceGroup): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM modems WHERE unit = :unit AND sg = :sg ORDER BY id DESC LIMIT 1');
        $stmt->execute([
            ':unit' => $unit,
            ':sg' => $serviceGroup,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByUnitAndGroups(string $unit, array $serviceGroups): ?array
    {
        if ($serviceGroups === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $sql = "SELECT * FROM modems WHERE unit = ? AND sg IN ($placeholders) ORDER BY id DESC LIMIT 1";
        $params = array_merge([$unit], $serviceGroups);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByMacAndGroups(string $mac, array $serviceGroups): ?array
    {
        if ($serviceGroups === []) {
            return null;
        }

        $normalizedMac = strtolower(trim($mac));
        if ($normalizedMac === '') {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $sql = "SELECT * FROM modems WHERE LOWER(mac) = ? AND sg IN ($placeholders) ORDER BY id DESC LIMIT 1";
        $params = array_merge([$normalizedMac], $serviceGroups);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function lotMacMapByServiceGroups(array $serviceGroups): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT sg, unit, mac
                FROM modems
                WHERE sg IN ($placeholders)
                  AND unit <> ''
                  $excludeClause
                  AND mac <> ''
                ORDER BY sg ASC, unit ASC, id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $sg = (int) ($row['sg'] ?? 0);
            $unit = trim((string) ($row['unit'] ?? ''));
            $mac = strtolower(trim((string) ($row['mac'] ?? '')));
            if ($sg <= 0 || $unit === '' || $mac === '') {
                continue;
            }

            $map[$sg . '|' . $unit] = $mac;
        }

        return $map;
    }

    public function replaceByServiceGroups(array $rows, array $serviceGroups): void
    {
        if ($serviceGroups === [] || $rows === []) {
            return;
        }

        $this->pdo->beginTransaction();
        try {
            $in = implode(',', array_fill(0, count($serviceGroups), '?'));
            $delete = $this->pdo->prepare("DELETE FROM modems WHERE sg IN ($in)");
            $delete->execute($serviceGroups);

            $sql = "INSERT INTO modems (
                id, first, last, account, stNum, street, unit, city, state, zip,
                phone, phone2, node, sg, profile, status, mac, mtamac, mtafile,
                username1, displayname1, login1, pass1, username2, displayname2,
                login2, pass2, oldprofile, notes, vdate
            ) VALUES (
                :id, :first, :last, :account, :stNum, :street, :unit, :city, :state, :zip,
                :phone, :phone2, :node, :sg, :profile, :status, :mac, :mtamac, :mtafile,
                :username1, :displayname1, :login1, :pass1, :username2, :displayname2,
                :login2, :pass2, :oldprofile, :notes, :vdate
            )";
            $insert = $this->pdo->prepare($sql);

            foreach ($rows as $row) {
                $unitRaw = strtolower(trim((string) ($row['unit'] ?? '')));
                if ($unitRaw !== '' && $this->excludedUnits !== [] && in_array($unitRaw, $this->excludedUnits, true)) {
                    continue;
                }

                $insert->execute([
                    ':id' => (int) ($row['id'] ?? 0),
                    ':first' => (string) ($row['first'] ?? ''),
                    ':last' => (string) ($row['last'] ?? ''),
                    ':account' => (string) ($row['account'] ?? ''),
                    ':stNum' => $row['stNum'] ?? null,
                    ':street' => (string) ($row['street'] ?? ''),
                    ':unit' => (string) ($row['unit'] ?? ''),
                    ':city' => (string) ($row['city'] ?? ''),
                    ':state' => (string) ($row['state'] ?? ''),
                    ':zip' => (string) ($row['zip'] ?? ''),
                    ':phone' => (string) ($row['phone'] ?? ''),
                    ':phone2' => (string) ($row['phone2'] ?? ''),
                    ':node' => (string) ($row['node'] ?? ''),
                    ':sg' => (int) ($row['sg'] ?? 0),
                    ':profile' => (string) ($row['profile'] ?? 'Disabled'),
                    ':status' => (int) ($row['status'] ?? 0),
                    ':mac' => strtolower((string) ($row['mac'] ?? '')),
                    ':mtamac' => $row['mtamac'] ?? null,
                    ':mtafile' => $row['mtafile'] ?? null,
                    ':username1' => $row['username1'] ?? null,
                    ':displayname1' => $row['displayname1'] ?? null,
                    ':login1' => $row['login1'] ?? null,
                    ':pass1' => $row['pass1'] ?? null,
                    ':username2' => $row['username2'] ?? null,
                    ':displayname2' => $row['displayname2'] ?? null,
                    ':login2' => $row['login2'] ?? null,
                    ':pass2' => $row['pass2'] ?? null,
                    ':oldprofile' => $row['oldprofile'] ?? null,
                    ':notes' => (string) ($row['notes'] ?? 'Notes'),
                    ':vdate' => (string) ($row['vdate'] ?? date('Y-m-d H:i:s')),
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function buildExcludeUnitClause(): string
    {
        if ($this->excludedUnits === []) {
            return '';
        }

        $quoted = array_map(fn ($v) => $this->pdo->quote($v), $this->excludedUnits);
        return 'AND LOWER(unit) NOT IN (' . implode(',', $quoted) . ')';
    }

    public function setLeaseStatusByMac(string $mac, bool $isActive, ?string $ip, ?string $error): void
    {
        $normalizedMac = strtolower(trim($mac));
        if ($normalizedMac === '') {
            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE modems
             SET lease_active = :lease_active,
                 lease_ip = :lease_ip,
                 lease_error = :lease_error,
                 lease_checked_at = NOW()
             WHERE LOWER(mac) = :mac'
        );
        $stmt->execute([
            ':lease_active' => $isActive ? 1 : 0,
            ':lease_ip' => $isActive ? trim((string) $ip) : null,
            ':lease_error' => $isActive ? null : trim((string) $error),
            ':mac' => $normalizedMac,
        ]);
    }

    public function countOfflineLeaseByServiceGroups(array $serviceGroups): int
    {
        if ($serviceGroups === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT COUNT(*) FROM modems WHERE sg IN ($placeholders) AND unit <> '' $excludeClause AND lease_active = 0";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return (int) $stmt->fetchColumn();
    }

    public function listOfflineLeaseByServiceGroups(array $serviceGroups, int $limit = 200): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $limit = max(1, min(1000, $limit));
        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT sg, unit, mac, profile, lease_ip, lease_error, lease_checked_at
                FROM modems
                WHERE sg IN ($placeholders)
                  AND unit <> ''
                  $excludeClause
                                    AND lease_active = 0
                ORDER BY sg ASC, unit ASC, mac ASC
                LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return $stmt->fetchAll();
    }

    public function countUncheckedLeaseByServiceGroups(array $serviceGroups): int
    {
        if ($serviceGroups === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT COUNT(*) FROM modems WHERE sg IN ($placeholders) AND unit <> '' $excludeClause AND lease_active IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return (int) $stmt->fetchColumn();
    }

    public function listLeaseCheckCandidatesByServiceGroups(array $serviceGroups, int $limit = 1000): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $limit = max(1, min(5000, $limit));
        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $excludeClause = $this->buildExcludeUnitClause();
        $sql = "SELECT sg, unit, mac
                FROM modems
                WHERE sg IN ($placeholders)
                  AND unit <> ''
                  $excludeClause
                  AND mac <> ''
                ORDER BY sg ASC, unit ASC, mac ASC
                LIMIT {$limit}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return $stmt->fetchAll();
    }
}
