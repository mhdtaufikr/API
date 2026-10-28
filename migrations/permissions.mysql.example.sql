-- Optional MariaDB role groups for the local integration database. No passwords or LOGIN users.
CREATE ROLE mkm_attendance_reader;
CREATE ROLE mkm_attendance_receiver;
CREATE ROLE mkm_attendance_worker;
CREATE ROLE mkm_attendance_settings;
GRANT SELECT ON mkm_attendance_local.* TO mkm_attendance_reader;
GRANT SELECT ON mkm_attendance_local.schema_version TO mkm_attendance_receiver;
GRANT SELECT,INSERT ON mkm_attendance_local.attendance_raw_events TO mkm_attendance_receiver;
GRANT SELECT ON mkm_attendance_local.* TO mkm_attendance_worker;
GRANT INSERT,UPDATE ON mkm_attendance_local.integration_queue TO mkm_attendance_worker;
GRANT INSERT ON mkm_attendance_local.queue_events TO mkm_attendance_worker;
GRANT INSERT,UPDATE ON mkm_attendance_local.attendances TO mkm_attendance_worker;
GRANT INSERT,DELETE ON mkm_attendance_local.attendance_sources TO mkm_attendance_worker;
GRANT INSERT ON mkm_attendance_local.attendance_processing_errors TO mkm_attendance_worker;
GRANT SELECT,INSERT,UPDATE ON mkm_attendance_local.integration_settings TO mkm_attendance_settings;
GRANT INSERT ON mkm_attendance_local.integration_settings_audit TO mkm_attendance_settings;
-- Assign distinct existing company-owned login users to these groups with a default role,
-- then put their credentials in DB_READER_*, DB_RECEIVER_*, DB_WORKER_*.
-- The development fallback DB_USERNAME must never be used for external deployment.
