<?php

class GuestRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureGuestSelfServiceColumns();
        $this->ensureGuestSelfServiceRequestTable();
        $this->ensureGuestSelfServiceAccessEventTable();
        $this->ensureCaptivePortalApiLogTable();
    }

    private function tableColumns(string $table): array
    {
        $columns = [];
        $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $table);
        foreach ($stmt->fetchAll() as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        return $columns;
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $stmt = $this->pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = :key_name');
        $stmt->execute([':key_name' => $indexName]);
        $row = $stmt->fetch();
        return $row !== false;
    }

    private function ensureGuestSelfServiceColumns(): void
    {
        $columns = $this->tableColumns('guests');

        if (!isset($columns['guest_access_id'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_id VARCHAR(32) NULL AFTER notes');
        }
        if (!$this->indexExists('guests', 'idx_guests_guest_access_id')) {
            $this->pdo->exec('CREATE UNIQUE INDEX idx_guests_guest_access_id ON guests (guest_access_id)');
        }
        if (!isset($columns['guest_access_code_hash'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_code_hash VARCHAR(255) NULL AFTER guest_access_id');
        }
        if (!isset($columns['guest_access_code_lookup'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_code_lookup CHAR(64) NULL AFTER guest_access_code_hash');
        }
        if (!$this->indexExists('guests', 'idx_guests_guest_access_code_lookup')) {
            $this->pdo->exec('CREATE UNIQUE INDEX idx_guests_guest_access_code_lookup ON guests (guest_access_code_lookup)');
        }
        if (!isset($columns['guest_access_code_expires_at'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_code_expires_at DATETIME NULL AFTER guest_access_code_hash');
        }
        if (!isset($columns['guest_access_failed_attempts'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_failed_attempts INT NOT NULL DEFAULT 0 AFTER guest_access_code_expires_at');
        }
        if (!isset($columns['guest_access_locked_until'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_locked_until DATETIME NULL AFTER guest_access_failed_attempts');
        }
        if (!isset($columns['guest_access_last_attempt_at'])) {
            $this->pdo->exec('ALTER TABLE guests ADD COLUMN guest_access_last_attempt_at DATETIME NULL AFTER guest_access_locked_until');
        }
    }

    private function ensureGuestSelfServiceRequestTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS guest_self_service_requests (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guest_id INT NOT NULL,
            guest_name VARCHAR(255) NOT NULL,
            unit VARCHAR(64) NOT NULL,
            sg INT NOT NULL,
            request_type ENUM('early_checkout', 'extend_departure') NOT NULL,
            current_departure_date DATE NOT NULL,
            requested_departure_date DATE NOT NULL,
            reason TEXT NULL,
            status ENUM('pending', 'approved', 'denied', 'auto_approved') NOT NULL DEFAULT 'pending',
            decision_note TEXT NULL,
            requested_by_access_id VARCHAR(32) NOT NULL,
            decided_by VARCHAR(128) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            INDEX idx_guest_self_service_requests_status_created (status, created_at),
            INDEX idx_guest_self_service_requests_guest_id (guest_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureGuestSelfServiceAccessEventTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS guest_self_service_access_events (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guest_access_id VARCHAR(32) NOT NULL,
            ip_address VARCHAR(64) NOT NULL,
            event_type ENUM('login_success', 'login_failure', 'lockout', 'unlock') NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_guest_access_events_access_time (guest_access_id, created_at),
            INDEX idx_guest_access_events_ip_time (ip_address, created_at),
            INDEX idx_guest_access_events_type_time (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureReprovisionLogTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS guest_reprovision_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guest_id INT NOT NULL,
            guest_name VARCHAR(255) NOT NULL,
            unit VARCHAR(64) NOT NULL,
            sg INT NOT NULL,
            modem_mac VARCHAR(64) NOT NULL,
            profile_applied VARCHAR(128) NOT NULL,
            status VARCHAR(32) NOT NULL,
            details TEXT NULL,
            actor VARCHAR(128) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_guest_reprovision_log_guest_id (guest_id),
            INDEX idx_guest_reprovision_log_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureVacantProfileLogTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS guest_vacant_profile_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            sg INT NOT NULL,
            unit VARCHAR(64) NOT NULL,
            modem_mac VARCHAR(64) NOT NULL,
            old_profile VARCHAR(128) NOT NULL,
            target_profile VARCHAR(128) NOT NULL,
            status VARCHAR(32) NOT NULL,
            details TEXT NULL,
            actor VARCHAR(128) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_guest_vacant_profile_log_created_at (created_at),
            INDEX idx_guest_vacant_profile_log_sg_unit (sg, unit)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureModemScopedReservationLogTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS guest_modem_scoped_reservation_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            guest_id INT NOT NULL,
            guest_name VARCHAR(255) NOT NULL,
            unit VARCHAR(64) NOT NULL,
            sg INT NOT NULL,
            modem_mac VARCHAR(64) NOT NULL,
            client_ip VARCHAR(64) NULL,
            status VARCHAR(32) NOT NULL,
            details TEXT NULL,
            actor VARCHAR(128) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_guest_modem_scoped_log_guest_id (guest_id),
            INDEX idx_guest_modem_scoped_log_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureCaptivePortalApiLogTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS captive_portal_api_log (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            client_ip VARCHAR(64) NOT NULL,
            host_header VARCHAR(255) NOT NULL,
            user_agent VARCHAR(512) NOT NULL,
            request_method VARCHAR(16) NOT NULL,
            request_uri VARCHAR(512) NOT NULL,
            response_json TEXT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_captive_portal_api_log_created_at (created_at),
            INDEX idx_captive_portal_api_log_client_ip (client_ip, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    public function create(array $data): int
    {
        $sql = "INSERT INTO guests (
            guest_name, phone, unit, sg, modem_mac,
            arrival_date, departure_date, profile_applied,
            dhcp_ip, submission_status, notes
        ) VALUES (
            :guest_name, :phone, :unit, :sg, :modem_mac,
            :arrival_date, :departure_date, :profile_applied,
            :dhcp_ip, :submission_status, :notes
        )";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM guests');
        return (int) $stmt->fetchColumn();
    }

    public function clearAll(): int
    {
        $count = $this->countAll();
        $stmt = $this->pdo->prepare('DELETE FROM guests');
        $stmt->execute();
        return $count;
    }

    public function backupAndClearAll(): array
    {
        $this->ensureModemScopedReservationLogTable();
        $this->ensureCaptivePortalApiLogTable();

        $timestamp = date('Ymd_His');
        $tables = [
            'modems',
            'guests',
            'guest_self_service_requests',
            'guest_self_service_access_events',
            'guest_reprovision_log',
            'guest_vacant_profile_log',
            'guest_modem_scoped_reservation_log',
            'captive_portal_api_log',
        ];

        $countsByTable = [];
        foreach ($tables as $table) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $countsByTable[$table] = (int) $stmt->fetchColumn();
        }

        $backupTables = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($tables as $table) {
                $rowCount = (int) ($countsByTable[$table] ?? 0);
                if ($rowCount > 0) {
                    $backupTable = $table . '_backup_' . $timestamp;
                    $this->pdo->exec("CREATE TABLE `{$backupTable}` LIKE `{$table}`");
                    $this->pdo->exec("INSERT INTO `{$backupTable}` SELECT * FROM `{$table}`");
                    $backupTables[$table] = $backupTable;
                }

                $this->pdo->exec("DELETE FROM `{$table}`");
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            // Backward-compatible keys
            'backup_table' => (string) ($backupTables['guests'] ?? ('guests_backup_' . $timestamp)),
            'cleared_rows' => (int) ($countsByTable['guests'] ?? 0),
            // Extended details
            'backup_tables' => $backupTables,
            'cleared_counts' => $countsByTable,
        ];
    }

    public function backupAndFactoryResetPreservingMasterAdmins(): array
    {
        $this->ensureModemScopedReservationLogTable();
        $this->ensureCaptivePortalApiLogTable();

        $timestamp = date('Ymd_His');
        $clearTables = [
            'modems',
            'guests',
            'guest_self_service_requests',
            'guest_self_service_access_events',
            'guest_reprovision_log',
            'guest_vacant_profile_log',
            'guest_modem_scoped_reservation_log',
            'captive_portal_api_log',
            'profile_bootfile_cache',
            'app_settings',
        ];

        $countsByTable = [];
        foreach ($clearTables as $table) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$table}`");
            $countsByTable[$table] = (int) $stmt->fetchColumn();
        }

        $totalUsersStmt = $this->pdo->query('SELECT COUNT(*) FROM app_users');
        $countsByTable['app_users_total'] = (int) $totalUsersStmt->fetchColumn();

        $masterUsersStmt = $this->pdo->query("SELECT COUNT(*) FROM app_users WHERE role = 'master_admin'");
        $countsByTable['app_users_master_admin'] = (int) $masterUsersStmt->fetchColumn();
        $countsByTable['app_users_non_master_admin'] = max(
            0,
            (int) $countsByTable['app_users_total'] - (int) $countsByTable['app_users_master_admin']
        );

        $backupTables = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($clearTables as $table) {
                $rowCount = (int) ($countsByTable[$table] ?? 0);
                if ($rowCount > 0) {
                    $backupTable = $table . '_backup_' . $timestamp;
                    $this->pdo->exec("CREATE TABLE `{$backupTable}` LIKE `{$table}`");
                    $this->pdo->exec("INSERT INTO `{$backupTable}` SELECT * FROM `{$table}`");
                    $backupTables[$table] = $backupTable;
                }

                $this->pdo->exec("DELETE FROM `{$table}`");
            }

            if ((int) ($countsByTable['app_users_total'] ?? 0) > 0) {
                $backupTable = 'app_users_backup_' . $timestamp;
                $this->pdo->exec("CREATE TABLE `{$backupTable}` LIKE `app_users`");
                $this->pdo->exec("INSERT INTO `{$backupTable}` SELECT * FROM `app_users`");
                $backupTables['app_users'] = $backupTable;
            }

            $this->pdo->exec("DELETE FROM app_users WHERE role <> 'master_admin' OR role IS NULL");
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [
            'cleared_rows' => (int) ($countsByTable['guests'] ?? 0),
            'backup_tables' => $backupTables,
            'cleared_counts' => $countsByTable,
            'preserved_master_admin_users' => (int) ($countsByTable['app_users_master_admin'] ?? 0),
        ];
    }

    public function addReprovisionLog(array $data): int
    {
        $this->ensureReprovisionLogTable();

        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_reprovision_log (
                guest_id, guest_name, unit, sg, modem_mac, profile_applied, status, details, actor
            ) VALUES (
                :guest_id, :guest_name, :unit, :sg, :modem_mac, :profile_applied, :status, :details, :actor
            )'
        );

        $stmt->execute([
            ':guest_id' => (int) ($data['guest_id'] ?? 0),
            ':guest_name' => (string) ($data['guest_name'] ?? ''),
            ':unit' => (string) ($data['unit'] ?? ''),
            ':sg' => (int) ($data['sg'] ?? 0),
            ':modem_mac' => strtolower((string) ($data['modem_mac'] ?? '')),
            ':profile_applied' => (string) ($data['profile_applied'] ?? ''),
            ':status' => (string) ($data['status'] ?? 'submitted'),
            ':details' => (string) ($data['details'] ?? ''),
            ':actor' => (string) ($data['actor'] ?? 'admin'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listReprovisionLogs(int $limit = 200): array
    {
        $this->ensureReprovisionLogTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, guest_id, guest_name, unit, sg, modem_mac, profile_applied, status, details, actor, created_at
             FROM guest_reprovision_log
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function addVacantProfileLog(array $data): int
    {
        $this->ensureVacantProfileLogTable();

        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_vacant_profile_log (
                sg, unit, modem_mac, old_profile, target_profile, status, details, actor
            ) VALUES (
                :sg, :unit, :modem_mac, :old_profile, :target_profile, :status, :details, :actor
            )'
        );

        $stmt->execute([
            ':sg' => (int) ($data['sg'] ?? 0),
            ':unit' => (string) ($data['unit'] ?? ''),
            ':modem_mac' => strtolower((string) ($data['modem_mac'] ?? '')),
            ':old_profile' => (string) ($data['old_profile'] ?? ''),
            ':target_profile' => (string) ($data['target_profile'] ?? ''),
            ':status' => (string) ($data['status'] ?? 'unknown'),
            ':details' => (string) ($data['details'] ?? ''),
            ':actor' => (string) ($data['actor'] ?? 'admin'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listVacantProfileLogs(int $limit = 200): array
    {
        $this->ensureVacantProfileLogTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, sg, unit, modem_mac, old_profile, target_profile, status, details, actor, created_at
             FROM guest_vacant_profile_log
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listGuestSelfServiceAccessEvents(int $limit = 200): array
    {
        $this->ensureGuestSelfServiceAccessEventTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, guest_access_id, ip_address, event_type, created_at
             FROM guest_self_service_access_events
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':max', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function addModemScopedReservationLog(array $data): int
    {
        $this->ensureModemScopedReservationLogTable();

        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_modem_scoped_reservation_log (
                guest_id, guest_name, unit, sg, modem_mac, client_ip, status, details, actor
            ) VALUES (
                :guest_id, :guest_name, :unit, :sg, :modem_mac, :client_ip, :status, :details, :actor
            )'
        );

        $stmt->execute([
            ':guest_id' => (int) ($data['guest_id'] ?? 0),
            ':guest_name' => (string) ($data['guest_name'] ?? ''),
            ':unit' => (string) ($data['unit'] ?? ''),
            ':sg' => (int) ($data['sg'] ?? 0),
            ':modem_mac' => strtolower((string) ($data['modem_mac'] ?? '')),
            ':client_ip' => (string) ($data['client_ip'] ?? ''),
            ':status' => (string) ($data['status'] ?? 'unknown'),
            ':details' => (string) ($data['details'] ?? ''),
            ':actor' => (string) ($data['actor'] ?? 'guest'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listModemScopedReservationLogs(int $limit = 200): array
    {
        $this->ensureModemScopedReservationLogTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, guest_id, guest_name, unit, sg, modem_mac, client_ip, status, details, actor, created_at
             FROM guest_modem_scoped_reservation_log
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':max', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function addCaptivePortalApiLog(array $data): int
    {
        $this->ensureCaptivePortalApiLogTable();

        $stmt = $this->pdo->prepare(
            'INSERT INTO captive_portal_api_log (
                client_ip, host_header, user_agent, request_method, request_uri, response_json
            ) VALUES (
                :client_ip, :host_header, :user_agent, :request_method, :request_uri, :response_json
            )'
        );

        $stmt->execute([
            ':client_ip' => (string) ($data['client_ip'] ?? ''),
            ':host_header' => substr((string) ($data['host_header'] ?? ''), 0, 255),
            ':user_agent' => substr((string) ($data['user_agent'] ?? ''), 0, 512),
            ':request_method' => substr((string) ($data['request_method'] ?? ''), 0, 16),
            ':request_uri' => substr((string) ($data['request_uri'] ?? ''), 0, 512),
            ':response_json' => (string) ($data['response_json'] ?? ''),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function listCaptivePortalApiLogs(int $limit = 200): array
    {
        $this->ensureCaptivePortalApiLogTable();

        $stmt = $this->pdo->prepare(
            'SELECT id, client_ip, host_header, user_agent, request_method, request_uri, response_json, created_at
             FROM captive_portal_api_log
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':max', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function clearOperationalLog(string $logKey): int
    {
        $tablesByKey = [
            'reprovision' => 'guest_reprovision_log',
            'vacant_profile' => 'guest_vacant_profile_log',
            'modem_scoped' => 'guest_modem_scoped_reservation_log',
            'guest_access' => 'guest_self_service_access_events',
            'captive_portal' => 'captive_portal_api_log',
        ];

        $ensureByKey = [
            'reprovision' => fn () => $this->ensureReprovisionLogTable(),
            'vacant_profile' => fn () => $this->ensureVacantProfileLogTable(),
            'modem_scoped' => fn () => $this->ensureModemScopedReservationLogTable(),
            'guest_access' => fn () => $this->ensureGuestSelfServiceAccessEventTable(),
            'captive_portal' => fn () => $this->ensureCaptivePortalApiLogTable(),
        ];

        if (!isset($tablesByKey[$logKey], $ensureByKey[$logKey])) {
            throw new InvalidArgumentException('Unknown log key.');
        }

        $ensureByKey[$logKey]();
        $table = $tablesByKey[$logKey];
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $count = (int) $stmt->fetchColumn();
        $this->pdo->exec("DELETE FROM `{$table}`");

        return $count;
    }

    public function reportHistory(int $limit = 200): array
    {
        $stmt = $this->pdo->prepare(
                        "SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, dhcp_ip, submission_status, notes, created_at,
                                        CASE
                                            WHEN submission_status <> 'checked_out' AND CURDATE() BETWEEN arrival_date AND departure_date THEN 'active'
                                            ELSE 'historical'
                                        END AS row_scope,
                    DATEDIFF(departure_date, arrival_date) + 1 AS stay_days
             FROM guests
                         WHERE (submission_status <> 'checked_out' AND CURDATE() BETWEEN arrival_date AND departure_date)
                                OR submission_status = 'checked_out'
                                OR departure_date < CURDATE()
                         ORDER BY row_scope ASC, unit ASC, arrival_date DESC, created_at DESC
             LIMIT :max"
        );
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function registrationListByLot(int $limit = 500): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, dhcp_ip, submission_status, notes, created_at,
                    DATEDIFF(departure_date, arrival_date) + 1 AS stay_days
             FROM guests
                         WHERE submission_status <> 'checked_out'
                             AND CURDATE() BETWEEN arrival_date AND departure_date
             ORDER BY unit ASC, arrival_date ASC, created_at DESC
             LIMIT :max"
        );
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function activeRegistrationsByServiceGroups(array $serviceGroups, int $limit = 1000): array
    {
        if ($serviceGroups === []) {
            return [];
        }

        $limit = max(1, min(5000, $limit));
        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $sql = "SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, dhcp_ip, submission_status, notes, created_at
                FROM guests
                WHERE submission_status <> 'checked_out'
                  AND CURDATE() BETWEEN arrival_date AND departure_date
                  AND sg IN ($placeholders)
                ORDER BY sg ASC, unit ASC, created_at DESC
                LIMIT {$limit}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);

        return $stmt->fetchAll();
    }

    public function upcomingRegistrationListByLot(int $limit = 500, ?string $arrivalOnOrBefore = null): array
    {
        $sql =
            "SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, dhcp_ip, submission_status, notes, created_at,
                    DATEDIFF(departure_date, arrival_date) + 1 AS stay_days
             FROM guests
             WHERE submission_status <> 'checked_out'
               AND arrival_date > CURDATE()";

        if ($arrivalOnOrBefore !== null && $arrivalOnOrBefore !== '') {
            $sql .= ' AND arrival_date <= :arrival_cutoff';
        }

        $sql .= ' ORDER BY arrival_date ASC, unit ASC, created_at DESC LIMIT :max';

        $stmt = $this->pdo->prepare($sql);
        if ($arrivalOnOrBefore !== null && $arrivalOnOrBefore !== '') {
            $stmt->bindValue(':arrival_cutoff', $arrivalOnOrBefore, PDO::PARAM_STR);
        }
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, dhcp_ip, submission_status, notes, created_at, updated_at
             FROM guests
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

        public function listDueAutoCheckout(string $todayDate, string $checkoutTime, string $currentTime, int $limit = 200): array
        {
                $limit = max(1, min(1000, $limit));

                $stmt = $this->pdo->prepare(
                        'SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied, submission_status, notes
                         FROM guests
                         WHERE submission_status <> "checked_out"
                             AND (
                                 departure_date < :today_date
                                 OR (departure_date = :today_date AND :current_time >= :checkout_time)
                             )
                         ORDER BY departure_date ASC, id ASC
                         LIMIT :max'
                );
                $stmt->bindValue(':today_date', $todayDate, PDO::PARAM_STR);
                $stmt->bindValue(':checkout_time', $checkoutTime, PDO::PARAM_STR);
                $stmt->bindValue(':current_time', $currentTime, PDO::PARAM_STR);
                $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
                $stmt->execute();

                return $stmt->fetchAll();
        }

    public function hasUnitDateConflict(string $unit, string $arrivalDate, string $departureDate, ?int $excludeId = null, ?int $serviceGroup = null): bool
    {
        $sql = 'SELECT COUNT(*)
                FROM guests
                WHERE unit = :unit
                                    AND submission_status <> "checked_out"
                  AND arrival_date <= :departure_date
                  AND departure_date >= :arrival_date';

        $params = [
            ':unit' => $unit,
            ':arrival_date' => $arrivalDate,
            ':departure_date' => $departureDate,
        ];

        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }

        if ($serviceGroup !== null) {
            $sql .= ' AND sg = :sg';
            $params[':sg'] = $serviceGroup;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    public function occupiedUnitKeysForDateRange(string $arrivalDate, string $departureDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT sg, unit
             FROM guests
             WHERE submission_status <> "checked_out"
               AND arrival_date <= :departure_date
               AND departure_date >= :arrival_date'
        );
        $stmt->execute([
            ':arrival_date' => $arrivalDate,
            ':departure_date' => $departureDate,
        ]);

        $keys = [];
        foreach ($stmt->fetchAll() as $row) {
            $sg = (int) ($row['sg'] ?? 0);
            $unit = (string) ($row['unit'] ?? '');
            if ($unit === '') {
                continue;
            }
            $keys[] = $sg . '|' . $unit;
        }

        return $keys;
    }

    public function occupiedUnitsForDateRange(string $arrivalDate, string $departureDate): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT unit
             FROM guests
                         WHERE submission_status <> "checked_out"
                             AND arrival_date <= :departure_date
                             AND departure_date >= :arrival_date'
        );
        $stmt->execute([
            ':arrival_date' => $arrivalDate,
            ':departure_date' => $departureDate,
        ]);

        return array_map(static fn ($row) => (string) $row['unit'], $stmt->fetchAll());
    }

    public function updateById(int $id, array $data): void
    {
        $sql = 'UPDATE guests SET
                    guest_name = :guest_name,
                    phone = :phone,
                    unit = :unit,
                    sg = :sg,
                    modem_mac = :modem_mac,
                    arrival_date = :arrival_date,
                    departure_date = :departure_date,
                    profile_applied = :profile_applied,
                    submission_status = :submission_status,
                    notes = :notes
                WHERE id = :id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':guest_name' => $data['guest_name'],
            ':phone' => $data['phone'],
            ':unit' => $data['unit'],
            ':sg' => $data['sg'],
            ':modem_mac' => $data['modem_mac'],
            ':arrival_date' => $data['arrival_date'],
            ':departure_date' => $data['departure_date'],
            ':profile_applied' => $data['profile_applied'],
            ':submission_status' => $data['submission_status'] ?? 'submitted',
            ':notes' => $data['notes'],
        ]);
    }

    public function setGuestAccessCredentials(int $guestId, string $accessId, string $codeHash, string $codeLookup, ?string $expiresAt): void
    {
        if ($guestId <= 0) {
            throw new InvalidArgumentException('Invalid guest id.');
        }
        if (trim($accessId) === '' || trim($codeHash) === '' || trim($codeLookup) === '') {
            throw new InvalidArgumentException('Guest access credentials are required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE guests
             SET guest_access_id = :access_id,
                 guest_access_code_hash = :code_hash,
                 guest_access_code_lookup = :code_lookup,
                 guest_access_code_expires_at = :expires_at,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([
            ':access_id' => $accessId,
            ':code_hash' => $codeHash,
            ':code_lookup' => strtolower(trim($codeLookup)),
            ':expires_at' => $expiresAt,
            ':id' => $guestId,
        ]);

        $this->resetGuestAccessAttempts($guestId);
    }

    public function isGuestAccessIdAvailable(string $accessId): bool
    {
        $normalized = strtoupper(trim($accessId));
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM guests WHERE guest_access_id = :access_id');
        $stmt->execute([':access_id' => $normalized]);

        return ((int) $stmt->fetchColumn()) === 0;
    }

    public function isGuestAccessCodeLookupAvailable(string $codeLookup): bool
    {
        $normalized = strtolower(trim($codeLookup));
        if ($normalized === '') {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM guests WHERE guest_access_code_lookup = :code_lookup');
        $stmt->execute([':code_lookup' => $normalized]);

        return ((int) $stmt->fetchColumn()) === 0;
    }

    public function findByAccessId(string $accessId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied,
                    dhcp_ip, submission_status, notes, guest_access_id, guest_access_code_hash, guest_access_code_expires_at,
                          guest_access_code_lookup,
                    guest_access_failed_attempts, guest_access_locked_until, guest_access_last_attempt_at,
                    created_at, updated_at
             FROM guests
             WHERE guest_access_id = :access_id
             LIMIT 1'
        );
        $stmt->execute([':access_id' => strtoupper(trim($accessId))]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function findByAccessCodeLookup(string $codeLookup): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_name, phone, unit, sg, modem_mac, arrival_date, departure_date, profile_applied,
                    dhcp_ip, submission_status, notes, guest_access_id, guest_access_code_hash, guest_access_code_expires_at,
                    guest_access_code_lookup,
                    guest_access_failed_attempts, guest_access_locked_until, guest_access_last_attempt_at,
                    created_at, updated_at
             FROM guests
             WHERE guest_access_code_lookup = :code_lookup
             LIMIT 1'
        );
        $stmt->execute([':code_lookup' => strtolower(trim($codeLookup))]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function verifyGuestAccess(string $accessId, string $code): ?array
    {
        $guest = $this->findByAccessId($accessId);
        if ($guest === null) {
            return null;
        }

        $codeHash = (string) ($guest['guest_access_code_hash'] ?? '');
        if ($codeHash === '' || !password_verify($code, $codeHash)) {
            return null;
        }

        $expiresAt = trim((string) ($guest['guest_access_code_expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            return null;
        }

        return $guest;
    }

    public function verifyGuestAccessByCodeLookup(string $codeLookup, string $code): ?array
    {
        $guest = $this->findByAccessCodeLookup($codeLookup);
        if ($guest === null) {
            return null;
        }

        $codeHash = (string) ($guest['guest_access_code_hash'] ?? '');
        if ($codeHash === '' || !password_verify($code, $codeHash)) {
            return null;
        }

        $expiresAt = trim((string) ($guest['guest_access_code_expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            return null;
        }

        return $guest;
    }

    public function resetGuestAccessAttempts(int $guestId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE guests
             SET guest_access_failed_attempts = 0,
                 guest_access_locked_until = NULL,
                 guest_access_last_attempt_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $stmt->execute([':id' => $guestId]);
    }

    public function registerGuestAccessFailure(int $guestId, int $maxAttempts, int $lockoutMinutes): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_access_failed_attempts
             FROM guests
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $guestId]);
        $row = $stmt->fetch();

        if ($row === false) {
            return ['attempts' => 0, 'locked' => false, 'locked_until' => null];
        }

        $attempts = (int) ($row['guest_access_failed_attempts'] ?? 0) + 1;
        $locked = $maxAttempts > 0 && $attempts >= $maxAttempts;
        $lockedUntil = $locked ? date('Y-m-d H:i:s', strtotime('+' . max(1, $lockoutMinutes) . ' minutes')) : null;

        $update = $this->pdo->prepare(
            'UPDATE guests
             SET guest_access_failed_attempts = :attempts,
                 guest_access_last_attempt_at = NOW(),
                 guest_access_locked_until = :locked_until,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );
        $update->execute([
            ':attempts' => $attempts,
            ':locked_until' => $lockedUntil,
            ':id' => $guestId,
        ]);

        return [
            'attempts' => $attempts,
            'locked' => $locked,
            'locked_until' => $lockedUntil,
        ];
    }

    public function recordGuestAccessEvent(string $accessId, string $ipAddress, string $eventType): void
    {
        if (!in_array($eventType, ['login_success', 'login_failure', 'lockout', 'unlock'], true)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_self_service_access_events (guest_access_id, ip_address, event_type)
             VALUES (:access_id, :ip_address, :event_type)'
        );
        $stmt->execute([
            ':access_id' => strtoupper(trim($accessId)) === '' ? 'UNKNOWN' : strtoupper(trim($accessId)),
            ':ip_address' => trim($ipAddress) === '' ? 'unknown' : trim($ipAddress),
            ':event_type' => $eventType,
        ]);
    }

    public function countRecentAccessFailuresByIp(string $ipAddress, int $windowMinutes): int
    {
        $minutes = max(1, $windowMinutes);
        $cutoff = date('Y-m-d H:i:s', time() - ($minutes * 60));
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM guest_self_service_access_events
             WHERE ip_address = :ip_address
               AND event_type = "login_failure"
               AND created_at >= :cutoff'
        );
        $stmt->bindValue(':ip_address', trim($ipAddress) === '' ? 'unknown' : trim($ipAddress), PDO::PARAM_STR);
        $stmt->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function createSelfServiceRequest(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO guest_self_service_requests (
                guest_id, guest_name, unit, sg,
                request_type, current_departure_date, requested_departure_date,
                reason, status, requested_by_access_id
            ) VALUES (
                :guest_id, :guest_name, :unit, :sg,
                :request_type, :current_departure_date, :requested_departure_date,
                :reason, :status, :requested_by_access_id
            )'
        );

        $stmt->execute([
            ':guest_id' => (int) ($data['guest_id'] ?? 0),
            ':guest_name' => (string) ($data['guest_name'] ?? ''),
            ':unit' => (string) ($data['unit'] ?? ''),
            ':sg' => (int) ($data['sg'] ?? 0),
            ':request_type' => (string) ($data['request_type'] ?? 'extend_departure'),
            ':current_departure_date' => (string) ($data['current_departure_date'] ?? ''),
            ':requested_departure_date' => (string) ($data['requested_departure_date'] ?? ''),
            ':reason' => trim((string) ($data['reason'] ?? '')),
            ':status' => (string) ($data['status'] ?? 'pending'),
            ':requested_by_access_id' => (string) ($data['requested_by_access_id'] ?? ''),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function hasPendingSelfServiceRequest(int $guestId, string $requestType): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
             FROM guest_self_service_requests
             WHERE guest_id = :guest_id
               AND request_type = :request_type
               AND status = "pending"'
        );
        $stmt->execute([
            ':guest_id' => $guestId,
            ':request_type' => $requestType,
        ]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    public function listSelfServiceRequests(int $limit = 200): array
    {
        return $this->listSelfServiceRequestsFiltered([], $limit);
    }

    private function buildSelfServiceRequestFilter(array $filters): array
    {
        $where = [];
        $params = [];

        $status = trim((string) ($filters['status'] ?? 'all'));
        if (in_array($status, ['pending', 'approved', 'denied', 'auto_approved'], true)) {
            $where[] = 'status = :status';
            $params[':status'] = $status;
        }

        $requestType = trim((string) ($filters['request_type'] ?? 'all'));
        if (in_array($requestType, ['early_checkout', 'extend_departure'], true)) {
            $where[] = 'request_type = :request_type';
            $params[':request_type'] = $requestType;
        }

        $sg = (int) ($filters['sg'] ?? 0);
        if ($sg > 0) {
            $where[] = 'sg = :sg';
            $params[':sg'] = $sg;
        }

        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $where[] = 'DATE(created_at) >= :date_from';
            $params[':date_from'] = $dateFrom;
        }

        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $where[] = 'DATE(created_at) <= :date_to';
            $params[':date_to'] = $dateTo;
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(guest_name LIKE :search OR unit LIKE :search OR requested_by_access_id LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        return [$where, $params];
    }

    public function countSelfServiceRequestsFiltered(array $filters): int
    {
        [$where, $params] = $this->buildSelfServiceRequestFilter($filters);

        $sql = 'SELECT COUNT(*) FROM guest_self_service_requests';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':sg') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function getSelfServiceQueueMetrics(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT created_at
             FROM guest_self_service_requests
             WHERE status = "pending"
             ORDER BY created_at ASC, id ASC'
        );
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $pendingCount = count($rows);
        if ($pendingCount === 0) {
            return [
                'open_requests' => 0,
                'oldest_pending_age_minutes' => null,
                'median_pending_age_minutes' => null,
            ];
        }

        $now = time();
        $ages = [];
        foreach ($rows as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $createdTs = strtotime($createdAt);
            if ($createdTs === false) {
                continue;
            }

            $ages[] = max(0, (int) floor(($now - $createdTs) / 60));
        }

        if ($ages === []) {
            return [
                'open_requests' => $pendingCount,
                'oldest_pending_age_minutes' => null,
                'median_pending_age_minutes' => null,
            ];
        }

        sort($ages);
        $ageCount = count($ages);
        $oldestAge = $ages[$ageCount - 1];
        $middle = (int) floor($ageCount / 2);
        if (($ageCount % 2) === 1) {
            $medianAge = $ages[$middle];
        } else {
            $medianAge = (int) floor(($ages[$middle - 1] + $ages[$middle]) / 2);
        }

        return [
            'open_requests' => $pendingCount,
            'oldest_pending_age_minutes' => $oldestAge,
            'median_pending_age_minutes' => $medianAge,
        ];
    }

    public function listSelfServiceRequestsFiltered(array $filters, int $limit = 200, int $offset = 0): array
    {
        [$where, $params] = $this->buildSelfServiceRequestFilter($filters);

        $orderKey = trim((string) ($filters['sort'] ?? 'created_desc'));
        $orderMap = [
            'created_desc' => 'created_at DESC, id DESC',
            'created_asc' => 'created_at ASC, id ASC',
            'requested_desc' => 'requested_departure_date DESC, id DESC',
            'requested_asc' => 'requested_departure_date ASC, id ASC',
            'age_desc' => 'created_at ASC, id ASC',
            'age_asc' => 'created_at DESC, id DESC',
        ];
        $orderBy = $orderMap[$orderKey] ?? $orderMap['created_desc'];

        $sql = 'SELECT id, guest_id, guest_name, unit, sg, request_type, current_departure_date,
                       requested_departure_date, reason, status, decision_note, requested_by_access_id,
                       decided_by, created_at, decided_at
                FROM guest_self_service_requests';

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY ' . $orderBy . ' LIMIT :max OFFSET :offset';

        $stmt = $this->pdo->prepare(
            $sql
        );

        foreach ($params as $key => $value) {
            if ($key === ':sg') {
                $stmt->bindValue($key, (int) $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, (string) $value, PDO::PARAM_STR);
            }
        }

        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function listSelfServiceRequestsForGuest(int $guestId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_id, guest_name, unit, sg, request_type, current_departure_date,
                    requested_departure_date, reason, status, decision_note, requested_by_access_id,
                    decided_by, created_at, decided_at
             FROM guest_self_service_requests
             WHERE guest_id = :guest_id
             ORDER BY created_at DESC, id DESC
             LIMIT :max'
        );
        $stmt->bindValue(':guest_id', $guestId, PDO::PARAM_INT);
        $stmt->bindValue(':max', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function findSelfServiceRequestById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, guest_id, guest_name, unit, sg, request_type, current_departure_date,
                    requested_departure_date, reason, status, decision_note, requested_by_access_id,
                    decided_by, created_at, decided_at
             FROM guest_self_service_requests
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    public function decideSelfServiceRequest(int $id, string $status, string $decisionNote, string $decidedBy): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE guest_self_service_requests
             SET status = :status,
                 decision_note = :decision_note,
                 decided_by = :decided_by,
                 decided_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':decision_note' => $decisionNote,
            ':decided_by' => $decidedBy,
            ':id' => $id,
        ]);
    }

    public function closeUnitConflictsEarly(string $unit, string $newArrivalDate, string $newDepartureDate, string $actor, ?int $serviceGroup = null): int
    {
        $newDepartureForExisting = date('Y-m-d', strtotime($newArrivalDate . ' -1 day'));
        $sql =
            'SELECT id, arrival_date, departure_date, notes
             FROM guests
             WHERE unit = :unit
             AND submission_status <> "checked_out"
               AND arrival_date <= :new_departure_date
               AND departure_date >= :new_arrival_date';

        $params = [
            ':unit' => $unit,
            ':new_arrival_date' => $newArrivalDate,
            ':new_departure_date' => $newDepartureDate,
        ];
        if ($serviceGroup !== null) {
            $sql .= ' AND sg = :sg';
            $params[':sg'] = $serviceGroup;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll();
        $updated = 0;

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $arrival = (string) $row['arrival_date'];
            $effectiveDeparture = $newDepartureForExisting < $arrival ? $arrival : $newDepartureForExisting;

            $notePrefix = sprintf(
                '[Admin Override] Checkout adjusted by %s to %s.',
                $actor,
                $effectiveDeparture
            );
            $existingNotes = trim((string) ($row['notes'] ?? ''));
            $combinedNotes = $existingNotes === '' ? $notePrefix : ($existingNotes . ' | ' . $notePrefix);

            $update = $this->pdo->prepare(
                'UPDATE guests
                 SET departure_date = :departure_date,
                     notes = :notes,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $update->execute([
                ':departure_date' => $effectiveDeparture,
                ':notes' => $combinedNotes,
                ':id' => $id,
            ]);
            $updated += $update->rowCount();
        }

        return $updated;
    }

    public function remapUnitsFromModemsByMac(array $serviceGroups): int
    {
        if ($serviceGroups === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($serviceGroups), '?'));
        $sql = "UPDATE guests g
                JOIN modems m ON LOWER(g.modem_mac) = LOWER(m.mac)
                SET g.unit = m.unit,
                    g.sg = m.sg,
                    g.updated_at = CURRENT_TIMESTAMP
                WHERE m.sg IN ($placeholders)
                  AND g.unit <> m.unit";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($serviceGroups);
        return $stmt->rowCount();
    }
}
