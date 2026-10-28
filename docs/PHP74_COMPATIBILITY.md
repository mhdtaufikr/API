# PHP 7.4 transitional compatibility — 27 October 2026

The same application source now supports PHP 7.4 and PHP 8.2+. Tested directly with PHP 7.4.33 NTS x64 from the official PHP Windows archive in an isolated project-local runtime; installed/global XAMPP PHP was not changed. Runtime files under .runtime-php74 are testing tools, not application deployment dependencies.

## What changed

- Explicit property declarations/constructor assignments replace promoted properties.
- `match` uses a strict, lazy compatibility dispatcher; PHP 8 first-class callable syntax becomes callable arrays. Unused catch variables, mixed/never types and nullsafe access were backported.
- app/compat.php supplies missing string/list helpers and corrects DateTime error-count differences: valid PHP 7.4 dates are not rejected merely because getLastErrors returns a zero-count array.
- CSV writer produces the same quoted CRLF bytes with PHP 7.4's five-argument fputcsv. Working Attendance MKM six-column format, original seconds, mapped badge, HMAC canonical string and multipart user_id/token/file are unchanged.
- PHP 7.4 uses CURLFile from a private temporary CSV; newer PHP can use CURLStringFile. Temporary upload file is removed in finally. TLS verification remains enabled.
- Runtime guards, simulator messaging, BAT runtime selection and optional local launcher accept PHP >=7.4. No automatic version-dependent DB fallback.
- Root index forwards machine query fields to public/API/index.php without redirect/session, preserving GET /API/?cardno=... if deployed under htdocs/API. Non-machine browser visits still redirect to the localhost-only dashboard. Root Apache file permission allows that index only; receiver independently enforces exact REMOTE_ADDR allowlist.

## Verified checks

- Every application, public entry, bin script and test PHP file passes actual PHP 7.4 syntax lint, including root index.php.
- PHP 7.4: offline CSV/HMAC 13 checks; machine/parser/processing 39; pure shift engine 38; four-table DB 9; profile setup 5; attendance reader 23; manual selection 10; scheduler policy 23; card-2120 restriction 5; response-reference whitelist.
- PHP 7.4 and PHP 8.2 compatibility suite: strict/lazy matching, helpers, AES-GCM, original machine event `0040002T17992026102607204910` -> 07:20:49 Jakarta, CSV embedded quoting/CRLF and exact successful Attendance MKM output.
- Actual HTTP multipart upload from both PHP versions to a loopback-only fixture preserves CSV SHA-256, filename attendance.csv and content type text/csv. Contains test data only; no Talenta request.
- PHP 7.4 loopback server on 127.0.0.1:8074: home, attendance, settings, simulator, Talenta report pages returned HTTP 200; no API import invoked.
- Current Apache PHP 8.2 regression routes returned HTTP 200, malformed root /API/?cardno=... correctly returned 422 from receiver instead of redirecting to dashboard.
- DB tests preserve real credentials/data via rollback. No production machine, DB, Windows task, or legacy supplier source changed.

Source backup before conversion: storage/php74-source-before.zip. Parser tooling used only during development; no Composer or external parser dependency is needed by the delivered application. PHP 7.4 runtime archive: https://downloads.php.net/~windows/releases/archives/php-7.4.33-nts-Win32-vc15-x64.zip

## Trial installation on existing Windows server

1. Keep the existing application/BAT/task/database backup. Deploy this code in a separate folder first. Do not delete the old app merely because PHP lint passes.
2. Use the intended PHP 7.4 executable in Apache and CLI; required extensions: PDO with selected DB driver, curl, openssl, mbstring. Verify `php -v`, `php -m`, and actual Apache runtime separately. Source change alone does not configure Apache PHP.
3. Provision/inspect the four approved tables in the trial database, preserving exact names/case. Do not run cleanup/reset tools against server databases. Production PostgreSQL credentials/schema are separate deployment configuration and still need real staging tests; local MySQL success does not prove production readiness.
4. Configure .env and original APP_KEY with retained encrypted tbl_config credentials; use the verified Attendance MKM token/profile and card mapping. Do not copy stale backup files or old ledger over current active state. For continuing the same integration, preserve ledger and database together.
5. Before physical receiver trial set MACHINE_RECEIVER_ENABLED=true, exact MACHINE_ALLOWED_IPS (observed 172.17.211.245; verify every actual machine separately) and explicit LEGACY_DIRECTION_MAP after validating the protocol-to-one-character legacy conversion. Simulator's manual direction input does not configure the physical receiver. Do not infer IN/OUT from all legacy values being 1.
6. Current legacy store write guard is local/isolated-only and rejects DB_DATABASE=mkm_ssmdb. This was retained deliberately; production write/cutover must be enabled through a separately reviewed adapter/config change after identifying the actual target. PHP 7.4 compatibility does not silently lift source DB protections.
7. Machine URL under folder API is `http://<server>/API/?cardno=...&d=...&h=...&m=...`. Apache AllowOverride must honor .htaccess. Test first with a controlled request, then verify actual row timestamp (including seconds), duplicate handling and response expected by the physical device. The observed old HTTP 200/113-byte log does not establish the required acknowledgement body.
8. Dashboard/config remain localhost-only. Use a browser on the server for setup; do not remove access protection to expose credentials UI to the LAN.
9. Manual single-card trial has explicit confirmation. Scheduler is separate: four-table legacy scheduler is not fully connected by this PHP version backport. Do not replace the legacy Windows task/BAT expecting automatic operation based on this runtime change alone.
10. Roll back by restoring the previous app path/Apache routing/task configuration and compatible DB/config backup. Reconcile any remote attempts before resend; PHP rollback does not undo Talenta data.

This delivers PHP 7.4 runtime compatibility, not a declaration that the existing production receiver/database/scheduler can be overwritten without cutover validation. Upgrade PHP later using the same source/config after focused regression checks.

## Re-running local verification

The optional isolated executable can be used directly:

```bat
.runtime-php74\runtime\php.exe tests\php74-compat.php
.runtime-php74\runtime\php.exe tests\run.php
.runtime-php74\runtime\php.exe tests\machine.php
.runtime-php74\runtime\php.exe tests\four-table-local.php
```

For tests/multipart-local-client.php, the loopback fixture must first run on port 8075; it accepts only fixed OFFLINE-COMPAT-TEST payloads. This fixture lives in the ignored .runtime-php74 directory and is not part of production deployment. Never run DB fixture suites on production.
