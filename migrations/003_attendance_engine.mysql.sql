ALTER TABLE shifts
 ADD COLUMN late_grace_minutes INT NULL,
 ADD COLUMN early_leave_grace_minutes INT NULL,
 ADD COLUMN duplicate_tap_seconds INT NULL,
 ADD COLUMN window_fallback_enabled BOOLEAN NOT NULL DEFAULT FALSE,
 ADD COLUMN minute_rounding VARCHAR(10) NULL,
 ADD COLUMN grace_mode VARCHAR(15) NULL;
CREATE TABLE attendance_shift_assignments (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, assignment_key CHAR(64) NOT NULL UNIQUE,
 employee_nik VARCHAR(50) NOT NULL, shift_code VARCHAR(30) NULL,
 from_date DATE NOT NULL, to_date DATE NOT NULL, priority INT NOT NULL, source VARCHAR(100) NOT NULL,
 FOREIGN KEY (shift_code) REFERENCES shifts(code), INDEX roster_employee_dates (employee_nik,from_date,to_date)
) ENGINE=InnoDB;
CREATE TABLE attendance_work_calendar (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, rule_key CHAR(64) NOT NULL UNIQUE,
 employee_nik VARCHAR(50) NULL, from_date DATE NOT NULL, to_date DATE NOT NULL,
 day_type VARCHAR(20) NOT NULL, priority INT NOT NULL, note VARCHAR(250) NULL
) ENGINE=InnoDB;
CREATE TABLE attendance_statuses (
 code VARCHAR(40) PRIMARY KEY, condition_key VARCHAR(40) NOT NULL UNIQUE,
 label VARCHAR(100) NOT NULL, priority INT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE attendance_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(100) NOT NULL UNIQUE,
 employee_nik VARCHAR(50) NOT NULL, attendance_date DATE NOT NULL, state VARCHAR(20) NOT NULL,
 approved_values JSON NOT NULL, approved_at TIMESTAMP NULL,
 INDEX request_employee_date (employee_nik,attendance_date)
) ENGINE=InnoDB;
CREATE TABLE attendance_period_locks (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, lock_key CHAR(64) NOT NULL UNIQUE,
 employee_nik VARCHAR(50) NULL, from_date DATE NOT NULL, to_date DATE NOT NULL,
 locked BOOLEAN NOT NULL DEFAULT TRUE, reason VARCHAR(250) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE attendances (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_nik VARCHAR(50) NOT NULL, attendance_date DATE NOT NULL,
 shift_code VARCHAR(30) NULL, scheduled_clock_in DATETIME(6) NULL, scheduled_clock_out DATETIME(6) NULL,
 actual_clock_in DATETIME(6) NULL, actual_clock_out DATETIME(6) NULL,
 late_minutes INT NULL, early_leave_minutes INT NULL, work_minutes INT NULL, raw_log_count INT NOT NULL,
 raw_clock_in_status VARCHAR(40) NULL, raw_clock_out_status VARCHAR(40) NULL, raw_attendance_status VARCHAR(40) NULL,
 raw_conditions JSON NOT NULL, final_attendance_status VARCHAR(40) NULL, final_status_source VARCHAR(40) NOT NULL,
 input_hash CHAR(64) NOT NULL, calculated_payload JSON NOT NULL,
 hr_manually_corrected BOOLEAN NOT NULL DEFAULT FALSE, is_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
 approved_request_id BIGINT UNSIGNED NULL, protection_reason VARCHAR(250) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE attendance_employee_date (employee_nik,attendance_date), FOREIGN KEY (approved_request_id) REFERENCES attendance_requests(id)
) ENGINE=InnoDB;
CREATE TABLE attendance_sources (
 attendance_id BIGINT UNSIGNED NOT NULL, raw_event_id BIGINT UNSIGNED NOT NULL UNIQUE, classification VARCHAR(40) NOT NULL,
 PRIMARY KEY (attendance_id,raw_event_id), FOREIGN KEY (attendance_id) REFERENCES attendances(id),
 FOREIGN KEY (raw_event_id) REFERENCES attendance_raw_events(id)
) ENGINE=InnoDB;
CREATE TABLE attendance_processing_errors (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, employee_nik VARCHAR(50) NOT NULL, attendance_date DATE NOT NULL,
 error_class VARCHAR(100) NOT NULL, message VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
