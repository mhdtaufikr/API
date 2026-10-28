CREATE TABLE mkm_attendance.integration_settings (
    provider VARCHAR(30) PRIMARY KEY,
    client_id_encrypted TEXT,
    client_secret_encrypted TEXT,
    import_token_encrypted TEXT,
    revision BIGINT NOT NULL DEFAULT 1,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE mkm_attendance.integration_settings_audit (
    id BIGSERIAL PRIMARY KEY,
    provider VARCHAR(30) NOT NULL,
    action VARCHAR(30) NOT NULL,
    changed_fields JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
