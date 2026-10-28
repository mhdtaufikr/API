# Attendance shift processing engine

## Confirmed target and scope

The request referenced Laravel 12/PostgreSQL models, but inspection found a PHP Native project with local XAMPP MySQL/MariaDB, no Artisan, no roster/calendar/status services, and no HR attendance records. The user explicitly confirmed continuing in this project with MySQL. The implementation extends the existing `AttendanceProcessor`, `shifts`, employee mappings, raw-event repository and integration queue; it does not create another application or a second shift master.

Migration 003 was applied only to the configured local `mkm_attendance_local` database. No production migration was run and no Talenta API request was made. PostgreSQL migration SQL is provided for later validation but is not live-verified.

## Installation and configuration

Use PHP 8.2+ (locally `C:\php\php.exe`, PHP 8.4). Normal deployment commands now include migration 003 and idempotent status seeding:

```powershell
C:\php\php.exe bin/console.php migrate
C:\php\php.exe bin/console.php seed
```

Existing machine rows, credentials and integration data are preserved. Migration 003 extends the existing shift master with explicit attendance policies. It adds only missing engine structures: assignment roster, work calendar, status master, approved-request snapshots, period locks, derived attendance records, source relationships and processing errors. MySQL migrations refuse unowned/partial objects instead of overwriting them; existing migration 001/002 checksums are unchanged.

Seed creates initial status data only when absent, preserving existing HR codes, labels and priorities. It does not seed employee rosters, actual shifts, real holidays, grace periods or fake attendance. Review the examples before configuring:

```powershell
C:\php\php.exe bin/console.php shift reviewed-shift.json
C:\php\php.exe bin/console.php shift-policy reviewed-policy.json
C:\php\php.exe bin/console.php employee-map reviewed-employee-map.json
C:\php\php.exe bin/console.php shift-assignment reviewed-roster.json
C:\php\php.exe bin/console.php work-calendar reviewed-calendar.json
C:\php\php.exe bin/console.php attendance-status reviewed-status.json
```

Templates are under `config/`. They are examples, not MKM business rules. A shift needs explicit `late_grace_minutes`, `early_leave_grace_minutes`, `duplicate_tap_seconds`, `window_fallback_enabled`, `minute_rounding` (`floor`/`ceil`), and `grace_mode` (`threshold`/`deduct`). Missing grace/rounding policies hold punched attendance for review. A null duplicate interval only permits exact-instant deduplication; no business tap interval is invented. Window fallback starts disabled.

## Shift and working-date resolution

For each explicitly mapped NIK and attendance date:

1. Find roster assignments covering that date. The highest configured integer priority wins.
2. Conflicting shift codes at the same top priority require manual review. Matching top-priority assignments for the same shift are consistent.
3. If there is no roster entry, use the mapping's existing shift code as a fallback. A roster explicitly assigning no shift does not fall back silently.
4. Read times and policies from the existing shift master. The engine requires Asia/Jakarta.
5. Resolve calendar rules applicable to the employee or company. Highest configured calendar priority wins; conflicting top-priority day types require review. Without an explicit calendar override, use the shift's configured weekdays. Without either shift or calendar evidence, the working date is unresolved.

Calendar semantics are `working`, `day_off`, and `holiday`. The engine does not download or invent an Indonesian holiday calendar. Company versus employee rules need explicit priorities; employee-specific rules do not silently override higher-priority company rules.

An end time at or before the start time belongs to the following day. Attendance date stays the shift start date. Candidate plans include adjacent dates, allowing consecutive overnight shifts and early entry windows before midnight without borrowing another shift's logs.

## IN/OUT selection

- Explicit terminal direction mappings take precedence. No meaning is assumed for raw code 10.
- Reliable IN logs must be inside the configured entry window; reliable OUT logs must be inside the exit window.
- Unknown directions can use time windows only if the shift explicitly enables fallback.
- A log matching multiple shift/role windows is ambiguous and is not assigned to either role/shift. Reliable direction can resolve an entry-versus-exit overlap when exactly one valid directional interpretation remains.
- Duplicate/repeated taps are clustered using the configured interval. Keep the earliest IN and latest OUT representative; excluded source IDs remain traceable.
- Choose the earliest remaining valid IN and latest remaining valid OUT within the assigned shift. Additional valid candidates remain in source evidence.
- One event cannot serve as both clocks. OUT must be later than a distinct IN.
- An isolated unknown exit-window event is held for review. An isolated reliably mapped OUT is sufficient direction evidence, but the attendance remains INCOMPLETE with a missing IN.
- Invalid/future timestamps and out-of-window directions require review. Actual machine instants are retained in Asia/Jakarta; no synchronization time replaces them.

Before the exit window closes, working attendance is provisional and is never automatically marked ABSENT. Unknown/missing shift assignments are not converted into a guessed schedule.

## Metrics and status

Records contain attendance date, shift/source, scheduled clocks, actual clocks, late/early/work minutes, raw count, raw clock statuses, raw attendance status, all raw condition codes, final status/source, source IDs/classifications and review reasons.

Minutes use the shift's explicit rounding mode. Grace modes:

- `threshold`: suppress lateness/early leave within grace; beyond grace retain the full scheduled difference.
- `deduct`: suppress within grace; beyond grace subtract the configured grace before rounding.

Work minutes are gross elapsed IN-to-OUT minutes. No break, overtime or payroll deduction is invented. Missing-punch work duration stays null; closed absent/non-working days with no events have zero recorded work minutes. A partial punch can still retain its individual late/early condition.

Statuses are looked up by semantic condition in `attendance_statuses`; codes, labels and final priority come from that master. PRESENT, LATE, EARLY_LEAVE, INCOMPLETE, ABSENT, DAY_OFF, HOLIDAY, WORKED_DAY_OFF and MANUAL_REVIEW are initial seed data. Simultaneous conditions are retained; the configured priority chooses the raw final code. Priority conflicts or missing master entries require review instead of a fabricated status. Existing status codes cannot be reassigned to another semantic condition by the configuration command.

Approved requests preserve raw calculation separately while determining final values/source. Confirmed/manual/approved records are protected from subsequent automatic processing. Status labels/priorities can be updated through `attendance-status`; repeated seeds do not overwrite them.

## Persistence and HR protection

`attendances` is unique on `(employee_nik, attendance_date)`. A stable input hash makes unchanged reprocessing a skip. Each write uses a transaction and a per-employee/date database advisory lock. Source IDs are exclusively linked in `attendance_sources`; an attempted second ownership claim rolls back the entire derived write. Ambiguous candidate IDs stay in calculated evidence and are not exclusively assigned until resolved.

The processor never updates or deletes `attendance_raw_events`. Derived source-link replacement only affects `attendance_sources` for that unprotected attendance record.

Skip automatic writes for locked periods, confirmed records, HR manual corrections and records already controlled by an approved request. Locked dates are not populated with new attendance rows. When a new approved snapshot is applied to an unprotected record, final overrides are validated and raw results remain in the calculated payload. Multiple approved requests for a date require review; the engine does not guess which approval wins.

Trusted CLI configuration supports:

```powershell
C:\php\php.exe bin/console.php attendance-request reviewed-approved-request.json
C:\php\php.exe bin/console.php attendance-period-lock reviewed-period-lock.json
C:\php\php.exe bin/console.php attendance-protect reviewed-HR-correction.json
```

The HR correction JSON supplies `employee_nik`, `attendance_date`, `reason`, and a `values` object containing approved actual clock/minute/status fields. Corrections mark `hr_manually_corrected` and `final_status_source=hr_manual`; confirmed/locked records cannot be corrected through this importer. Approved request snapshots and manual imports are not an HR approval workflow and must originate from authorized verified HR instructions.

## Processing commands

Native equivalents of the requested Artisan commands:

```powershell
C:\php\php.exe bin/console.php attendance:process-logs --date=2026-10-27
C:\php\php.exe bin/console.php attendance:process-logs --from=2026-10-01 --to=2026-10-31
C:\php\php.exe bin/console.php attendance:process-logs --date=2026-10-27 --employee=2131
C:\php\php.exe bin/console.php attendance:process-logs --date=2026-10-27 --dry-run
```

The command processes mapped employees only, in bounded date ranges. It reports employees, unique raw logs, created/updated attendance, skips with reasons and individual errors. In dry-run mode created/updated counts are projected (`would_created`/`would_updated`); no database records, error rows or source links are written. Dry-run errors may be logged to a private local file. Errors in one employee/date are logged and processing continues. Exit status is nonzero if processing errors occurred.

Results distinguish calculated status from the stored authoritative final status, so a skipped HR correction is not reported as having been overwritten.

## Integration queue and dashboard

The dashboard exposes **Processed Attendance** with schedule, actual punches, metrics, final/source status and raw-log details. Unmapped cards remain visible in the raw review sections without invented employee records.

Queue preparation now requires current, unprotected persisted attendance with the matching calculation hash. Process attendance first, then use the existing explicit test-employee queue action. Approved/manual/locked/confirmed attendance requires separate HR review before export; raw-event export does not bypass HR corrections. Queue preview rechecks the persisted result. Receiver, processing command and seed do not invoke Talenta or enable its existing live gates.

## Validation and files

```powershell
C:\php\php.exe tests/attendance-engine.php
C:\php\php.exe tests/attendance-engine-db.php
```

38 calculation checks and 18 actual MySQL persistence checks passed. They cover the requested normal/night, duplicate, missing IN/OUT, late/early, absent/holiday, missing shift, idempotency, HR protection, consecutive nights and wrong-shift cases, plus fallback ambiguity, grace modes, calendar/status priorities, source ownership, approved requests, locks, confirmed records, batch continuation and dry-run immutability. Database fixtures roll back and a raw-table digest verifies no source mutation. Existing receiver, database/queue, credential and HTTP checks also pass. Credential HTTP mutation checks are skipped when real credentials are already configured.

Primary modified files: `app/AttendanceProcessor.php`, `AttendanceRepository.php`, `IntegrationMigrations.php`, `TalentaApiService.php`, `bootstrap.php`, `machine-dashboard.php`, `sidebar.php`, `bin/console.php`, existing machine/database tests and optional permission templates. Added: `AttendanceShiftResolver.php`, `WorkCalendarService.php`, `AttendanceStatusService.php`, `AttendanceEngineRepository.php`, `AttendanceProcessingCommand.php`, `processed-attendance.php`, migration 003 for both drivers, engine test suites and reviewed-configuration templates.

## Business data still required

- Real card/NIK mappings, employment-effective periods and HR-authorized roster priorities.
- Actual shifts, entry/exit tolerances, grace/rounding/dedup policies and verified terminal direction meanings.
- Approved company/employee working calendar and holiday rules; the sample calendar file is not a real holiday declaration.
- Authoritative HR requests, corrections and locked/confirmed periods from the actual HR source; this native project had no preexisting Laravel models/workflow to import them from.
- Break/payroll/overtime rules if net payable work time is needed; only gross attendance duration is calculated now.
- Live PostgreSQL validation if production will use PostgreSQL; no production database was migrated or tested here.

Do not process broad historical ranges until the employee roster/calendar applicability is reviewed. Do not enable Talenta submissions merely because processing tests pass.
