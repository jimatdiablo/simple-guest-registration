<?php

class UserRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->ensureTable();
        $this->ensureRoleEnumSupportsStaff();
        $this->normalizeLegacyUserRole();
        $this->ensureAdminViewColumns();
        $this->ensureMasterAdmin();
    }

    public function getAdminViewModeForDevice(int $id, string $deviceClass): ?string
    {
        if ($id <= 0) {
            return null;
        }

        $column = $deviceClass === 'mobile' ? 'admin_view_mode_mobile' : 'admin_view_mode_desktop';
        $stmt = $this->pdo->prepare("SELECT {$column} AS view_mode FROM app_users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }

        $mode = trim((string) ($row['view_mode'] ?? ''));
        return in_array($mode, ['standard', 'compact'], true) ? $mode : null;
    }

    public function setAdminViewModeForDevice(int $id, string $deviceClass, string $mode): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid user id.');
        }
        if (!in_array($deviceClass, ['desktop', 'mobile'], true)) {
            throw new InvalidArgumentException('Invalid device class.');
        }
        if (!in_array($mode, ['standard', 'compact'], true)) {
            throw new InvalidArgumentException('Invalid view mode.');
        }

        $column = $deviceClass === 'mobile' ? 'admin_view_mode_mobile' : 'admin_view_mode_desktop';
        $stmt = $this->pdo->prepare("UPDATE app_users SET {$column} = :mode WHERE id = :id");
        $stmt->execute([
            ':mode' => $mode,
            ':id' => $id,
        ]);
    }

    public function authenticate(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, is_active FROM app_users WHERE username = :username LIMIT 1'
        );
        $stmt->execute([':username' => strtolower(trim($username))]);
        $row = $stmt->fetch();

        if ($row === false || (int) $row['is_active'] !== 1) {
            return null;
        }

        if (!password_verify($password, (string) $row['password_hash'])) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'role' => (string) $row['role'],
        ];
    }

    public function createUser(string $username, string $password, string $role): void
    {
        $normalized = strtolower(trim($username));
        if ($normalized === '' || $password === '') {
            throw new InvalidArgumentException('Username and password are required.');
        }

        if (!in_array($role, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:username, :password_hash, :role, 1)'
        );
        $stmt->execute([
            ':username' => $normalized,
            ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ':role' => $role,
        ]);
    }

    public function listUsers(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, role, is_active, created_at FROM app_users ORDER BY id ASC'
        );
        return $stmt->fetchAll();
    }

    public function updateUser(int $id, string $role, int $isActive, string $newPassword = ''): void
    {
        $stmt = $this->pdo->prepare('SELECT id, username, role FROM app_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new InvalidArgumentException('User not found.');
        }

        $isMaster = isset($this->masterAccounts()[(string) $row['username']]) || ((string) $row['role'] === 'master_admin');
        if ($isMaster) {
            throw new InvalidArgumentException('Master admin user cannot be modified from this form.');
        }

        if (!in_array($role, ['staff', 'admin'], true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }

        $isActive = $isActive === 1 ? 1 : 0;

        if (trim($newPassword) !== '') {
            $update = $this->pdo->prepare(
                'UPDATE app_users
                 SET role = :role, is_active = :is_active, password_hash = :password_hash
                 WHERE id = :id'
            );
            $update->execute([
                ':role' => $role,
                ':is_active' => $isActive,
                ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $id,
            ]);
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE app_users
             SET role = :role, is_active = :is_active
             WHERE id = :id'
        );
        $update->execute([
            ':role' => $role,
            ':is_active' => $isActive,
            ':id' => $id,
        ]);
    }

    public function deleteUser(int $id): void
    {
        $stmt = $this->pdo->prepare('SELECT id, username, role FROM app_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            throw new InvalidArgumentException('User not found.');
        }

        $isMaster = isset($this->masterAccounts()[(string) $row['username']]) || ((string) $row['role'] === 'master_admin');
        if ($isMaster) {
            throw new InvalidArgumentException('Master admin user cannot be deleted.');
        }

        $delete = $this->pdo->prepare('DELETE FROM app_users WHERE id = :id');
        $delete->execute([':id' => $id]);
    }

    public function changeOwnPassword(int $id, string $currentPassword, string $newPassword): void
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('Invalid user id.');
        }

        if (trim($currentPassword) === '' || trim($newPassword) === '') {
            throw new InvalidArgumentException('Current and new password are required.');
        }

        if (strlen($newPassword) < 8) {
            throw new InvalidArgumentException('New password must be at least 8 characters.');
        }

        $stmt = $this->pdo->prepare('SELECT id, password_hash, is_active FROM app_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false || (int) $row['is_active'] !== 1) {
            throw new InvalidArgumentException('User not found or inactive.');
        }

        if (!password_verify($currentPassword, (string) $row['password_hash'])) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }

        $update = $this->pdo->prepare('UPDATE app_users SET password_hash = :password_hash WHERE id = :id');
        $update->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':id' => $id,
        ]);
    }

    private function ensureTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS app_users (
            id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(64) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role ENUM('user', 'staff', 'admin', 'master_admin') NOT NULL DEFAULT 'staff',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            admin_view_mode_desktop ENUM('standard', 'compact') NULL DEFAULT NULL,
            admin_view_mode_mobile ENUM('standard', 'compact') NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $this->pdo->exec($sql);
    }

    private function ensureRoleEnumSupportsStaff(): void
    {
        $this->pdo->exec(
            "ALTER TABLE app_users MODIFY COLUMN role ENUM('user', 'staff', 'admin', 'master_admin') NOT NULL DEFAULT 'staff'"
        );
    }

    private function normalizeLegacyUserRole(): void
    {
        $this->pdo->exec("UPDATE app_users SET role = 'staff' WHERE role = 'user'");
    }

    private function ensureAdminViewColumns(): void
    {
        $columns = [];
        $stmt = $this->pdo->query('SHOW COLUMNS FROM app_users');
        foreach ($stmt->fetchAll() as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        if (!isset($columns['admin_view_mode_desktop'])) {
            $this->pdo->exec("ALTER TABLE app_users ADD COLUMN admin_view_mode_desktop ENUM('standard', 'compact') NULL DEFAULT NULL AFTER is_active");
        }
        if (!isset($columns['admin_view_mode_mobile'])) {
            $this->pdo->exec("ALTER TABLE app_users ADD COLUMN admin_view_mode_mobile ENUM('standard', 'compact') NULL DEFAULT NULL AFTER admin_view_mode_desktop");
        }
    }

    private function ensureMasterAdmin(): void
    {
        foreach ($this->masterAccounts() as $username => $password) {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $this->pdo->prepare('SELECT id, password_hash FROM app_users WHERE username = :username LIMIT 1');
            $stmt->execute([':username' => $username]);
            $row = $stmt->fetch();

            if ($row === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO app_users (username, password_hash, role, is_active) VALUES (:username, :password_hash, :role, 1)'
                );
                $insert->execute([
                    ':username' => $username,
                    ':password_hash' => $passwordHash,
                    ':role' => 'master_admin',
                ]);
                continue;
            }

            $existingHash = (string) $row['password_hash'];
            if (!password_verify($password, $existingHash)) {
                $update = $this->pdo->prepare(
                    'UPDATE app_users SET password_hash = :password_hash, role = :role, is_active = 1 WHERE username = :username'
                );
                $update->execute([
                    ':password_hash' => $passwordHash,
                    ':role' => 'master_admin',
                    ':username' => $username,
                ]);
            }
        }
    }

    private function masterAccounts(): array
    {
        $raw = trim((string) getenv('SGR_MASTER_ADMIN_SEEDS'));
        if ($raw === '') {
            return [];
        }

        $accounts = [];
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

            $accounts[$username] = $password;
        }

        return $accounts;
    }
}
