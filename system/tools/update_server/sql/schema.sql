CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    display_name VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    system_username VARCHAR(190) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL DEFAULT NULL,
    UNIQUE KEY uniq_system_username (system_username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_login_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_user_id BIGINT UNSIGNED NOT NULL,
    system_username VARCHAR(190) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_admin_expires (admin_user_id, expires_at),
    KEY idx_system_expires (system_username, expires_at),
    CONSTRAINT fk_admin_login_tokens_user FOREIGN KEY (admin_user_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_operation_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    operation_type VARCHAR(64) NOT NULL,
    target_ref VARCHAR(190) NOT NULL,
    status VARCHAR(24) NOT NULL,
    requested_by_admin_id BIGINT UNSIGNED DEFAULT NULL,
    requested_by_label VARCHAR(190) NOT NULL,
    input_json LONGTEXT NOT NULL,
    result_json LONGTEXT NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_operation_created (operation_type, created_at),
    KEY idx_status_started (status, started_at),
    CONSTRAINT fk_admin_operation_runs_user FOREIGN KEY (requested_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_uuid CHAR(36) NOT NULL UNIQUE,
    installation_name VARCHAR(190) NOT NULL,
    base_url VARCHAR(255) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    metis_version VARCHAR(32) NOT NULL DEFAULT '',
    php_version VARCHAR(32) NOT NULL DEFAULT '',
    machine_uuid CHAR(36) NOT NULL,
    server_fingerprint CHAR(64) NOT NULL,
    public_key_sha256 CHAR(64) NOT NULL UNIQUE,
    public_key_pem MEDIUMTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    module_inventory_json LONGTEXT NOT NULL,
    registered_ip VARCHAR(64) NOT NULL DEFAULT '',
    last_seen_ip VARCHAR(64) NOT NULL DEFAULT '',
    contact_name VARCHAR(190) NOT NULL DEFAULT '',
    contact_email VARCHAR(190) NOT NULL DEFAULT '',
    contact_phone VARCHAR(64) NOT NULL DEFAULT '',
    admin_notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_seen_at DATETIME NULL DEFAULT NULL,
    KEY idx_channel_status (channel, status),
    KEY idx_machine_uuid (machine_uuid),
    KEY idx_server_fingerprint (server_fingerprint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS installation_module_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_uuid CHAR(36) NOT NULL,
    module_id VARCHAR(100) NOT NULL,
    notes VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_install_module (installation_uuid, module_id),
    KEY idx_module_install (module_id, installation_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_catalog_settings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(100) NOT NULL UNIQUE,
    is_custom TINYINT(1) NOT NULL DEFAULT 0,
    admin_notes TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_is_custom (is_custom, module_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tag_name VARCHAR(64) NOT NULL UNIQUE,
    version VARCHAR(32) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    package_type VARCHAR(24) NOT NULL,
    notes TEXT NOT NULL,
    archive_path VARCHAR(255) NOT NULL,
    archive_sha256 CHAR(64) NOT NULL,
    minimum_php VARCHAR(16) NOT NULL DEFAULT '8.1',
    manifest_json LONGTEXT NOT NULL,
    published_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_version_channel (version, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS core_delta_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    release_id BIGINT UNSIGNED NOT NULL,
    from_version VARCHAR(32) NOT NULL,
    archive_path VARCHAR(255) NOT NULL,
    archive_sha256 CHAR(64) NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_release_from (release_id, from_version),
    CONSTRAINT fk_core_delta_release FOREIGN KEY (release_id) REFERENCES core_releases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_releases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    module_id VARCHAR(100) NOT NULL,
    version VARCHAR(32) NOT NULL,
    minimum_metis VARCHAR(32) NOT NULL,
    channel VARCHAR(32) NOT NULL DEFAULT 'stable',
    visibility VARCHAR(24) NOT NULL DEFAULT 'public',
    installation_uuid CHAR(36) DEFAULT NULL,
    package_type VARCHAR(24) NOT NULL,
    archive_path VARCHAR(255) NOT NULL,
    archive_sha256 CHAR(64) NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    published_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_release (module_id, version, visibility, installation_uuid),
    KEY idx_module_channel (module_id, channel),
    KEY idx_installation_scope (installation_uuid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS module_delta_packages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    module_release_id BIGINT UNSIGNED NOT NULL,
    from_version VARCHAR(32) NOT NULL,
    archive_path VARCHAR(255) NOT NULL,
    archive_sha256 CHAR(64) NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_module_delta (module_release_id, from_version),
    CONSTRAINT fk_module_delta_release FOREIGN KEY (module_release_id) REFERENCES module_releases(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS artifact_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    token CHAR(64) NOT NULL UNIQUE,
    installation_uuid CHAR(36) NOT NULL,
    artifact_kind VARCHAR(24) NOT NULL,
    artifact_table_name VARCHAR(32) NOT NULL,
    artifact_row_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_installation_kind (installation_uuid, artifact_kind),
    KEY idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS request_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    installation_uuid CHAR(36) DEFAULT NULL,
    action_type VARCHAR(64) NOT NULL,
    status_code SMALLINT UNSIGNED NOT NULL DEFAULT 200,
    details_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_installation_action (installation_uuid, action_type),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    target_type VARCHAR(32) NOT NULL,
    target_ref VARCHAR(190) NOT NULL,
    verification_profile VARCHAR(64) NOT NULL,
    status VARCHAR(24) NOT NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NOT NULL,
    summary_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_target (target_type, target_ref),
    KEY idx_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
