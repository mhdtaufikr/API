# Tarik Absensi Talenta

Open the new **Tarik Absensi Talenta** sidebar entry:

- XAMPP: http://localhost/API/public/talenta-attendance.php
- PHP development server: http://127.0.0.1:8088/talenta-attendance.php

The page reads Attendance Summary Report using the Client ID and Client Secret already stored by the Mekari Credentials menu. It defaults to the current Asia/Jakarta date. Choose a date range (up to 31 days), page size (up to 100), optional Talenta User IDs, or an employee search of at least three characters. Blank User IDs request all employees permitted by the credential's company scope. Employee ID returned by Talenta is displayed as-is, without assuming it is the local NIK.

The workspace official Postman collection supplies the GET endpoint and response schema:

```text
GET https://api.mekari.com/v2/talenta/v3/attendance/summary-report
date, end_date, page, limit, sort=id_employee, order=asc
optional: user_ids, query
```

HMAC signs the exact GET request path including its RFC3986-encoded query. Import Token is only needed for the separate fingerprint import, not this read operation. The existing POST/import signature remains unchanged.

The page displays employee identity/name, attendance date, shift, scheduled clocks, actual clocks, attendance code and available late/early/work metrics directly from the response. Missing remote clocks remain missing; the view does not invent absences or calculate substitute timestamps. A report row may be a scheduled employee with no recorded punch, so total report rows are not a count of actual taps.

Pagination constructs requests to the same fixed endpoint; it never follows remote pagination URLs. Opening the menu does not call Mekari: a local CSRF-protected form submits the explicit pull request. Credential values and Authorization headers never appear in HTML. Localhost-only access, no-store responses, verified TLS, no redirects and bounded responses/timeouts remain in place.

No migration is needed for this menu. It does not create local machine events, attendance records or integration queue entries. The read does not require enabling DRY_RUN=false or ALLOW_PRODUCTION_WRITE. It does not invoke the attendance import endpoint. Read audit entries in private `storage/talenta-reads.jsonl` retain HTTP status, timing, date/page metadata, response digest and request ID, without remote employee rows or credential plaintext.

Optional `MEKARI_CA_BUNDLE` specifies a trusted server CA file. On the verified Windows XAMPP installation, the existing Apache CA bundle is used when the PHP runtime has no configured curl.cainfo. Certificate verification is never disabled.

## Validation

```powershell
C:\php\php.exe tests/talenta-attendance-reader.php
C:\php\php.exe tests/talenta-attendance-http.php
```

21 offline reader checks and 10 local HTTP checks passed. They cover query validation, GET/POST HMAC compatibility, response/company/date validation, HTML escaping, whitelisted columns, pagination, CSRF, credential secrecy and absence of automatic requests on initial page load.

Live verification through Apache XAMPP on 27 October 2026 returned HTTP 200: 381 summary rows for that date, with page 1 and page 2 verified at 25 rows per page (16 pages). Earlier CLI attempts timed out/failed to connect while Apache succeeded; use the XAMPP link for the verified local runtime. CLI metadata-only diagnostics are available via `php bin/check-talenta-read.php`, which makes one live GET and never dumps employee rows or credentials.

27 October 2026 filter repair: live GET without user_ids returned HTTP 200. Sending employee/card 2120 as internal user_ids reproduced HTTP 422 (Statement Invalid). Searching query=2120 with blank user_ids returned one row HTTP 200, verified in browser. User ID filter moved under advanced options; 422 message now distinguishes identifiers without exposing remote bodies. One transient browser timeout occurred before a successful retry; no attendance write. 23 reader unit checks passed.
