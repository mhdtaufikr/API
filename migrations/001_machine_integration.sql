-- Dedicated integration schema only. Never run against the legacy MariaDB database.
CREATE SCHEMA mkm_attendance;
CREATE TABLE mkm_attendance.schema_version (
    version INTEGER PRIMARY KEY,
    checksum VARCHAR(64) NOT NULL,
    installed_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE mkm_attendance.attendance_raw_events (
    id BIGSERIAL PRIMARY KEY,
    card_number VARCHAR(50) NOT NULL,
    terminal_id VARCHAR(20) NOT NULL,
    direction_code VARCHAR(10) NOT NULL,
    event_datetime TIMESTAMPTZ NOT NULL,
    raw_message TEXT NOT NULL,
    protocol_profile VARCHAR(30) NOT NULL,
    request_payload JSONB NOT NULL,
    source_ip INET NOT NULL,
    received_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_hash VARCHAR(64) NOT NULL UNIQUE
);
CREATE INDEX attendance_events_datetime_idx ON mkm_attendance.attendance_raw_events (event_datetime, card_number);
CREATE TABLE mkm_attendance.shifts (
    code VARCHAR(30) PRIMARY KEY,
    timezone VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    weekdays JSONB NOT NULL,
    in_before_minutes INTEGER NOT NULL CHECK (in_before_minutes BETWEEN 0 AND 240),
    in_after_minutes INTEGER NOT NULL CHECK (in_after_minutes BETWEEN 0 AND 240),
    out_before_minutes INTEGER NOT NULL CHECK (out_before_minutes BETWEEN 0 AND 240),
    out_after_minutes INTEGER NOT NULL CHECK (out_after_minutes BETWEEN 0 AND 240)
);
CREATE TABLE mkm_attendance.terminals (
    terminal_id VARCHAR(20) PRIMARY KEY,
    direction_map JSONB NOT NULL DEFAULT '{}'
);
CREATE TABLE mkm_attendance.employee_mappings (
    card_number VARCHAR(50) PRIMARY KEY,
    employee_nik VARCHAR(50) NOT NULL UNIQUE,
    employee_name VARCHAR(150) NOT NULL,
    talenta_employee_id VARCHAR(50),
    talenta_user_id VARCHAR(50),
    badge_number VARCHAR(50),
    shift_code VARCHAR(30) REFERENCES mkm_attendance.shifts(code),
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE mkm_attendance.integration_queue (
    id BIGSERIAL PRIMARY KEY,
    batch_hash VARCHAR(64) NOT NULL UNIQUE,
    card_number VARCHAR(50) NOT NULL,
    employee_nik VARCHAR(50) NOT NULL,
    shift_date DATE NOT NULL,
    snapshot JSONB NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending_review'
        CHECK (status IN ('pending_review','reserved','submitted_pending_reconciliation','rejected','unknown','confirmed','confirmed_failed')),
    response JSONB,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE mkm_attendance.queue_events (
    queue_id BIGINT NOT NULL REFERENCES mkm_attendance.integration_queue(id),
    raw_event_id BIGINT NOT NULL UNIQUE REFERENCES mkm_attendance.attendance_raw_events(id),
    PRIMARY KEY (queue_id, raw_event_id)
);
