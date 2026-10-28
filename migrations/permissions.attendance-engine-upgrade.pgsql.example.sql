-- Optional grants for already-existing restricted roles, AFTER migration 003.
-- Review role names before applying; this file is not run automatically.
GRANT SELECT ON mkm_attendance.attendance_shift_assignments,mkm_attendance.attendance_work_calendar,
 mkm_attendance.attendance_statuses,mkm_attendance.attendance_requests,mkm_attendance.attendance_period_locks,
 mkm_attendance.attendances,mkm_attendance.attendance_sources,mkm_attendance.attendance_processing_errors
 TO mkm_attendance_reader,mkm_attendance_worker;
GRANT INSERT,UPDATE ON mkm_attendance.attendances TO mkm_attendance_worker;
GRANT INSERT,DELETE ON mkm_attendance.attendance_sources TO mkm_attendance_worker;
GRANT INSERT ON mkm_attendance.attendance_processing_errors TO mkm_attendance_worker;
GRANT USAGE,SELECT ON SEQUENCE mkm_attendance.attendances_id_seq,mkm_attendance.attendance_processing_errors_id_seq TO mkm_attendance_worker;
