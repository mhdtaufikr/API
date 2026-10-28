CREATE TABLE integration_settings (
    provider VARCHAR(30) PRIMARY KEY,
    client_id_encrypted TEXT NULL,
    client_secret_encrypted TEXT NULL,
    import_token_encrypted TEXT NULL,
    revision BIGINT NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE integration_settings_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(30) NOT NULL,
    action VARCHAR(30) NOT NULL,
    changed_fields JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
