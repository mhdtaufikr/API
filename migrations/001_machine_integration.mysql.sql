-- New local integration database only. DATETIME values are stored in UTC.
CREATE TABLE schema_version (
    version INT PRIMARY KEY,
    checksum VARCHAR(64) NOT NULL,
    installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE attendance_raw_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_number VARCHAR(50) NOT NULL,
    terminal_id VARCHAR(20) NOT NULL,
    direction_code VARCHAR(10) NOT NULL,
    event_datetime DATETIME(6) NOT NULL,
    raw_message TEXT NOT NULL,
    protocol_profile VARCHAR(30) NOT NULL,
    request_payload JSON NOT NULL,
    source_ip VARCHAR(45) NOT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    event_hash CHAR(64) NOT NULL UNIQUE,
    INDEX attendance_events_datetime_idx (event_datetime,card_number)
) ENGINE=InnoDB;
CREATE TABLE shifts (
    code VARCHAR(30) PRIMARY KEY,
    timezone VARCHAR(50) NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    weekdays JSON NOT NULL,
    in_before_minutes INT NOT NULL CHECK (in_before_minutes BETWEEN 0 AND 240),
    in_after_minutes INT NOT NULL CHECK (in_after_minutes BETWEEN 0 AND 240),
    out_before_minutes INT NOT NULL CHECK (out_before_minutes BETWEEN 0 AND 240),
    out_after_minutes INT NOT NULL CHECK (out_after_minutes BETWEEN 0 AND 240)
) ENGINE=InnoDB;
CREATE TABLE terminals (
    terminal_id VARCHAR(20) PRIMARY KEY,
    direction_map JSON NOT NULL
) ENGINE=InnoDB;
CREATE TABLE employee_mappings (
    card_number VARCHAR(50) PRIMARY KEY,
    employee_nik VARCHAR(50) NOT NULL UNIQUE,
    employee_name VARCHAR(150) NOT NULL,
    talenta_employee_id VARCHAR(50),
    talenta_user_id VARCHAR(50),
    badge_number VARCHAR(50),
    shift_code VARCHAR(30),
    verified BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (shift_code) REFERENCES shifts(code)
) ENGINE=InnoDB;
CREATE TABLE integration_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_hash CHAR(64) NOT NULL UNIQUE,
    card_number VARCHAR(50) NOT NULL,
    employee_nik VARCHAR(50) NOT NULL,
    shift_date DATE NOT NULL,
    snapshot JSON NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'pending_review'
        CHECK (status IN ('pending_review','reserved','submitted_pending_reconciliation','rejected','unknown','confirmed','confirmed_failed')),
    response JSON,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE queue_events (
    queue_id BIGINT UNSIGNED NOT NULL,
    raw_event_id BIGINT UNSIGNED NOT NULL UNIQUE,
    PRIMARY KEY (queue_id,raw_event_id),
    FOREIGN KEY (queue_id) REFERENCES integration_queue(id),
    FOREIGN KEY (raw_event_id) REFERENCES attendance_raw_events(id)
) ENGINE=InnoDB;
