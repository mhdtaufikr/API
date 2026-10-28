-- Optional, run once by the local database administrator after the schema migration.
-- These are permission groups, NOT login users. No passwords or existing data are changed.
-- If a name already exists, stop and review it rather than blindly replacing roles.
CREATE ROLE mkm_attendance_reader NOLOGIN;
CREATE ROLE mkm_attendance_receiver NOLOGIN;
CREATE ROLE mkm_attendance_worker NOLOGIN;
CREATE ROLE mkm_attendance_settings NOLOGIN;
GRANT USAGE ON SCHEMA mkm_attendance TO mkm_attendance_reader, mkm_attendance_receiver, mkm_attendance_worker;
GRANT SELECT ON ALL TABLES IN SCHEMA mkm_attendance TO mkm_attendance_reader, mkm_attendance_worker;
GRANT SELECT ON mkm_attendance.schema_version, mkm_attendance.attendance_raw_events TO mkm_attendance_receiver;
GRANT INSERT ON mkm_attendance.attendance_raw_events TO mkm_attendance_receiver;
GRANT USAGE, SELECT ON SEQUENCE mkm_attendance.attendance_raw_events_id_seq TO mkm_attendance_receiver;
GRANT INSERT, UPDATE ON mkm_attendance.integration_queue TO mkm_attendance_worker;
GRANT INSERT ON mkm_attendance.queue_events TO mkm_attendance_worker;
GRANT USAGE, SELECT ON SEQUENCE mkm_attendance.integration_queue_id_seq TO mkm_attendance_worker;
GRANT INSERT,UPDATE ON mkm_attendance.attendances TO mkm_attendance_worker;
GRANT INSERT,DELETE ON mkm_attendance.attendance_sources TO mkm_attendance_worker;
GRANT INSERT ON mkm_attendance.attendance_processing_errors TO mkm_attendance_worker;
GRANT USAGE,SELECT ON SEQUENCE mkm_attendance.attendances_id_seq,mkm_attendance.attendance_processing_errors_id_seq TO mkm_attendance_worker;
GRANT USAGE ON SCHEMA mkm_attendance TO mkm_attendance_settings;
GRANT SELECT ON mkm_attendance.integration_settings TO mkm_attendance_reader,mkm_attendance_worker;
GRANT SELECT,INSERT,UPDATE ON mkm_attendance.integration_settings TO mkm_attendance_settings;
GRANT INSERT ON mkm_attendance.integration_settings_audit TO mkm_attendance_settings;
GRANT USAGE,SELECT ON SEQUENCE mkm_attendance.integration_settings_audit_id_seq TO mkm_attendance_settings;
-- Create company-owned LOGIN users separately, assign each to its appropriate group,
-- and configure DB_READER_*, DB_RECEIVER_*, DB_WORKER_* in .env.
-- No role here has ALTER, DROP, DELETE, or source/legacy database permissions.
