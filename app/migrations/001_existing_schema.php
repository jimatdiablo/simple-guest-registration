<?php

return static function (MigrationRunner $m): void {
    $m->exec("CREATE TABLE IF NOT EXISTS modems (
        id INT(20) NOT NULL,
        first VARCHAR(30) NOT NULL,
        last VARCHAR(30) NOT NULL,
        account VARCHAR(30) NOT NULL,
        stNum VARCHAR(8) DEFAULT NULL,
        street VARCHAR(30) NOT NULL,
        unit VARCHAR(30) NOT NULL,
        city VARCHAR(40) NOT NULL,
        state VARCHAR(2) NOT NULL,
        zip VARCHAR(9) NOT NULL,
        phone VARCHAR(10) NOT NULL,
        phone2 VARCHAR(10) NOT NULL,
        node VARCHAR(40) NOT NULL,
        sg INT(9) NOT NULL DEFAULT 1,
        profile VARCHAR(48) NOT NULL DEFAULT 'Disabled',
        status INT(3) NOT NULL,
        mac VARCHAR(17) NOT NULL,
        lease_active TINYINT(1) DEFAULT NULL,
        lease_ip VARCHAR(45) DEFAULT NULL,
        lease_error VARCHAR(255) DEFAULT NULL,
        lease_checked_at DATETIME DEFAULT NULL,
        mtamac VARCHAR(17) DEFAULT NULL,
        mtafile VARCHAR(128) DEFAULT NULL,
        username1 VARCHAR(25) DEFAULT NULL,
        displayname1 VARCHAR(25) DEFAULT NULL,
        login1 VARCHAR(25) DEFAULT NULL,
        pass1 VARCHAR(25) DEFAULT NULL,
        username2 VARCHAR(25) DEFAULT NULL,
        displayname2 VARCHAR(25) DEFAULT NULL,
        login2 VARCHAR(25) DEFAULT NULL,
        pass2 VARCHAR(25) DEFAULT NULL,
        oldprofile VARCHAR(48) DEFAULT NULL,
        notes TEXT NOT NULL,
        vdate TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_modems_mac (mac),
        KEY idx_modems_sg_unit (sg, unit),
        KEY idx_modems_unit (unit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS customers LIKE modems");

    $m->exec("CREATE TABLE IF NOT EXISTS guests (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        guest_name VARCHAR(120) NOT NULL,
        phone VARCHAR(25) NOT NULL,
        unit VARCHAR(30) NOT NULL,
        sg INT(9) NOT NULL,
        modem_mac VARCHAR(17) NOT NULL,
        arrival_date DATE NOT NULL,
        departure_date DATE NOT NULL,
        profile_applied VARCHAR(48) NOT NULL,
        dhcp_ip VARCHAR(45) DEFAULT NULL,
        submission_status VARCHAR(32) NOT NULL DEFAULT 'submitted',
        notes TEXT NULL,
        guest_access_id VARCHAR(32) DEFAULT NULL,
        guest_access_code_hash VARCHAR(255) DEFAULT NULL,
        guest_access_code_lookup CHAR(64) DEFAULT NULL,
        guest_access_code_expires_at DATETIME DEFAULT NULL,
        guest_access_failed_attempts INT NOT NULL DEFAULT 0,
        guest_access_locked_until DATETIME DEFAULT NULL,
        guest_access_last_attempt_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_guests_guest_access_id (guest_access_id),
        UNIQUE KEY idx_guests_guest_access_code_lookup (guest_access_code_lookup),
        KEY idx_guests_unit_created (unit, created_at),
        KEY idx_guests_dates (arrival_date, departure_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS guest_self_service_requests (
        id INT NOT NULL AUTO_INCREMENT,
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
        PRIMARY KEY (id),
        KEY idx_guest_self_service_requests_status_created (status, created_at),
        KEY idx_guest_self_service_requests_guest_id (guest_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS guest_self_service_access_events (
        id INT NOT NULL AUTO_INCREMENT,
        guest_access_id VARCHAR(32) NOT NULL,
        ip_address VARCHAR(64) NOT NULL,
        event_type ENUM('login_success', 'login_failure', 'lockout', 'unlock') NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_guest_access_events_access_time (guest_access_id, created_at),
        KEY idx_guest_access_events_ip_time (ip_address, created_at),
        KEY idx_guest_access_events_type_time (event_type, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS profile_bootfile_cache (
        sg INT NOT NULL,
        profile_name VARCHAR(128) NOT NULL,
        bootfile_filename VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (sg, profile_name),
        KEY idx_profile_bootfile_cache_sg (sg)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(100) NOT NULL,
        setting_value VARCHAR(255) NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS app_users (
        id INT NOT NULL AUTO_INCREMENT,
        username VARCHAR(64) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user', 'staff', 'admin', 'master_admin') NOT NULL DEFAULT 'staff',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        admin_view_mode_desktop ENUM('standard', 'compact') NULL DEFAULT NULL,
        admin_view_mode_mobile ENUM('standard', 'compact') NULL DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS guest_reprovision_log (
        id INT NOT NULL AUTO_INCREMENT,
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
        PRIMARY KEY (id),
        KEY idx_guest_reprovision_log_guest_id (guest_id),
        KEY idx_guest_reprovision_log_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS guest_vacant_profile_log (
        id INT NOT NULL AUTO_INCREMENT,
        sg INT NOT NULL,
        unit VARCHAR(64) NOT NULL,
        modem_mac VARCHAR(64) NOT NULL,
        old_profile VARCHAR(128) NOT NULL,
        target_profile VARCHAR(128) NOT NULL,
        status VARCHAR(32) NOT NULL,
        details TEXT NULL,
        actor VARCHAR(128) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_guest_vacant_profile_log_created_at (created_at),
        KEY idx_guest_vacant_profile_log_sg_unit (sg, unit)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS guest_modem_scoped_reservation_log (
        id INT NOT NULL AUTO_INCREMENT,
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
        PRIMARY KEY (id),
        KEY idx_guest_modem_scoped_log_guest_id (guest_id),
        KEY idx_guest_modem_scoped_log_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->exec("CREATE TABLE IF NOT EXISTS captive_portal_api_log (
        id INT NOT NULL AUTO_INCREMENT,
        client_ip VARCHAR(64) NOT NULL,
        host_header VARCHAR(255) NOT NULL,
        user_agent VARCHAR(512) NOT NULL,
        request_method VARCHAR(16) NOT NULL,
        request_uri VARCHAR(512) NOT NULL,
        response_json TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_captive_portal_api_log_created_at (created_at),
        KEY idx_captive_portal_api_log_client_ip (client_ip, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $m->addColumnIfMissing('modems', 'lease_active', 'lease_active TINYINT(1) NULL AFTER mac');
    $m->addColumnIfMissing('modems', 'lease_ip', 'lease_ip VARCHAR(45) NULL AFTER lease_active');
    $m->addColumnIfMissing('modems', 'lease_error', 'lease_error VARCHAR(255) NULL AFTER lease_ip');
    $m->addColumnIfMissing('modems', 'lease_checked_at', 'lease_checked_at DATETIME NULL AFTER lease_error');

    $m->addColumnIfMissing('guests', 'guest_access_id', 'guest_access_id VARCHAR(32) NULL AFTER notes');
    $m->addColumnIfMissing('guests', 'guest_access_code_hash', 'guest_access_code_hash VARCHAR(255) NULL AFTER guest_access_id');
    $m->addColumnIfMissing('guests', 'guest_access_code_lookup', 'guest_access_code_lookup CHAR(64) NULL AFTER guest_access_code_hash');
    $m->addColumnIfMissing('guests', 'guest_access_code_expires_at', 'guest_access_code_expires_at DATETIME NULL AFTER guest_access_code_lookup');
    $m->addColumnIfMissing('guests', 'guest_access_failed_attempts', 'guest_access_failed_attempts INT NOT NULL DEFAULT 0 AFTER guest_access_code_expires_at');
    $m->addColumnIfMissing('guests', 'guest_access_locked_until', 'guest_access_locked_until DATETIME NULL AFTER guest_access_failed_attempts');
    $m->addColumnIfMissing('guests', 'guest_access_last_attempt_at', 'guest_access_last_attempt_at DATETIME NULL AFTER guest_access_locked_until');

    $m->createIndexIfMissing('guests', 'idx_guests_guest_access_id', 'UNIQUE INDEX idx_guests_guest_access_id ON guests (guest_access_id)');
    $m->createIndexIfMissing('guests', 'idx_guests_guest_access_code_lookup', 'UNIQUE INDEX idx_guests_guest_access_code_lookup ON guests (guest_access_code_lookup)');
    $m->createIndexIfMissing('guests', 'idx_guests_unit_created', 'INDEX idx_guests_unit_created ON guests (unit, created_at)');
    $m->createIndexIfMissing('guests', 'idx_guests_dates', 'INDEX idx_guests_dates ON guests (arrival_date, departure_date)');

    $m->addColumnIfMissing('app_users', 'admin_view_mode_desktop', "admin_view_mode_desktop ENUM('standard', 'compact') NULL DEFAULT NULL AFTER is_active");
    $m->addColumnIfMissing('app_users', 'admin_view_mode_mobile', "admin_view_mode_mobile ENUM('standard', 'compact') NULL DEFAULT NULL AFTER admin_view_mode_desktop");
    $m->exec("ALTER TABLE app_users MODIFY COLUMN role ENUM('user', 'staff', 'admin', 'master_admin') NOT NULL DEFAULT 'staff'");
    $m->exec("UPDATE app_users SET role = 'staff' WHERE role = 'user'");
};
