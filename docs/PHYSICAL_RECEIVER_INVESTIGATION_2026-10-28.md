# Physical receiver investigation — 28 October 2026

## Scope and evidence boundary

This investigation used the captured terminal 003/004 requests and responses supplied by the operator, the `API-1` source, and isolated tests. It did not redirect a terminal, change Apache/MySQL, write production data, modify `FlFlag`, or call Talenta.

The locally available `C:\xampp\htdocs\API\index.php` and `public\API\index.php` are byte-identical to `API-1`; the original SourceGuardian-protected legacy receiver is not present at that path. Therefore its exact successful HTTP body, content type, encoding, and newline remain unverified. Keep the default `OK` contract provisional until a successful legacy exchange is captured at the server/network boundary.

## Confirmed request lifecycle

1. Root `index.php` routes requests containing `cardno`, `d`, `h`, or `m` directly to `public/API/index.php` without a redirect.
2. `ReceiverHttp::validateTransport()` checks the enable flag, source IP, GET method, size, keys, duplicates, and four-parameter shape.
3. `MachineRequestParser::parse()` decodes terminal, four-digit card, 14-digit event timestamp, and two-digit protocol direction from `cardno`; it cross-checks `d`, `h`, and `m`.
4. `LegacyAttendanceRepository::store()` applies production gates, maps the protocol direction, takes a cooperative lock, checks the exact legacy tuple, and inserts `FdDate`, `FcTime`, `FcCardNo`, `FcDirection`, `FcTermNo`, `FlFlag=0`.
5. Only after a committed insert or confirmed existing tuple does the endpoint return the configured acknowledgment.

## Findings and patches

### HTTP 422 — confirmed parser incompatibility

- Evidence: terminal 004 sent embedded `09:40:58` with `h=09:41`. The prior parser required exact `H:i`, so it returned `GET time does not match embedded hour and minute` and the service mapped this to 422.
- Later physical evidence showed that terminals update `h` to their current send time on every retry while `cardno` retains the original event timestamp. Therefore `h` is validated only as an `HH:MM` transport field; it is not cross-checked against the event instant. Date and terminal still match exactly, and the embedded timestamp remains subject to calendar/future validation.
- Risk: `h` no longer corroborates the event timestamp. The immutable embedded timestamp, exact terminal/date checks, duplicate fingerprint and future-time bound remain authoritative.
- Test: captured terminal 004/003 samples and multi-minute retries parse while preserving the original embedded seconds; malformed `h` remains rejected.

### HTTP 503 — confirmed configuration-gate path, exact live trigger not logged

- Code evidence: any repository exception becomes HTTP 503. A physical write to `mkm_ssmdb` requires `INTEGRATION_STORAGE=legacy_app`, `LEGACY_LOCAL_WRITE=true`, `MACHINE_RECEIVER_ENABLED=true`, `LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED=true`, a permitted source, and a one-character mapping for the protocol direction. `LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED` is simulator-only. `ALLOW_PRODUCTION_WRITE` and `DRY_RUN` control Talenta/flag writes, not receiver insertion.
- The prior physical gate explicitly rejected `MACHINE_ALLOWED_IPS=*`, even though transport validation accepted it. An empty `LEGACY_DIRECTION_MAP` also blocks insertion. Either condition, a missing physical-confirmation flag, DB/schema/permission failure, lock timeout, or audit failure can produce 503.
- Fix: transport and production persistence now use the same wildcard-aware source check. The separate physical-confirmation and verified-direction gates remain mandatory.
- Evidence boundary: the supplied Apache status log does not include the receiver audit reason, so the exact terminal 003 runtime trigger is not confirmed. Inspect the matching private `storage/receiver.jsonl` on the production trial folder before deployment.
- Risk: `*` permits any source reaching this endpoint. Prefer explicit addresses; if operationally retaining `*`, restrict the endpoint to the attendance-machine network using the host firewall/Apache and do not treat PHP wildcard filtering as authentication.
- Tests: enabled wildcard + physical confirmation + `10 -> 1` is allowed; missing confirmation or mapping is blocked; disabled receiver returns 503 before persistence.

### Direction mapping — evidence-supported but requires operator sign-off

- Both captured payloads end in protocol code `10`; the rows later written by the restored legacy receiver have `FcDirection=1` for the matching card/terminal/times. This supports `{"10":"1"}` for these events.
- The code does not hardcode that inference. Production still requires an explicit reviewed `LEGACY_DIRECTION_MAP`. Other protocol codes must be captured and reconciled before mapping.

### Duplicate replay — fixed

- The prior repository detected an exact existing tuple but threw, causing 503 and continued terminal retries.
- It now returns `duplicate=true` without inserting a second row. The endpoint may acknowledge only after confirming that the tuple already exists.
- Risk: the legacy table has no occurrence ID. Two genuine taps with the same date, second, card, direction, and terminal are indistinguishable and collapse to one tuple. Existing duplicate rows are not deleted or merged.

### Acknowledgment contract — guarded, still provisional

- Current default: HTTP 200, `Content-Type: text/plain; charset=utf-8`, body `OK`, no trailing newline for stored and duplicate events; failures use `ERROR` with their non-200 status.
- The response contract is now validated before persistence, so an invalid custom contract cannot store an event and then downgrade its acknowledgment to 503.
- Exact legacy success bytes remain an open evidence item. Capture them before physical cutover; then populate private `config/receiver-response.json` and rerun HTTP tests.

## Required production configuration review

Do not copy credentials into source control. The reviewed server `.env` needs these non-secret controls in addition to restricted DB receiver credentials:

```ini
INTEGRATION_STORAGE=legacy_app
DB_HOST=127.0.0.1
DB_DATABASE=mkm_ssmdb
LEGACY_LOCAL_WRITE=true
MACHINE_RECEIVER_ENABLED=true
MACHINE_ALLOWED_IPS=<reviewed exact list, or * only with network boundary approval>
LEGACY_PRODUCTION_PHYSICAL_RECEIVER_CONFIRMED=true
LEGACY_DIRECTION_MAP={"10":"1"}
MACHINE_ALLOW_SIMULATOR=false
LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED=false
TALENTA_SCHEDULER_ENABLED=false
DRY_RUN=true
ALLOW_PRODUCTION_WRITE=false
```

The final four values keep simulator-only and Talenta/`FlFlag` behavior disabled. Enabling the physical receiver must not implicitly enable outbound submission.

## Safe deployment and rollback

1. Keep the operational legacy `/API/` unchanged. Deploy this patch to a separate versioned folder.
2. Capture a known successful legacy response including status, raw body bytes, content type, encoding, and newline. Configure and test the new contract offline.
3. With a database fixture or restored non-production copy, run PHP lint, `tests/machine.php`, `tests/physical-receiver-regression.php`, and `tests/legacy-schema.php`. Verify first delivery inserts one `FlFlag=0` row and replay inserts none.
4. Perform read-only production schema/engine/permission inspection. Do not migrate or reset `mkm_ssmdb`.
5. Run a controlled shadow/UAT window without changing terminal routing. Reconcile captured legacy requests against new parser/mapping results.
6. Approve a cutoff time. Stop routing new requests to the legacy writer, then switch only the receiver route during an attended window. Never run both writers for the same terminal traffic.
7. Verify terminal 003 and 004: one committed row per event, preserved seconds/terminal/card/direction, `FlFlag=0`, HTTP 200, expected body, and successful replay acknowledgment.
8. Roll back immediately by restoring the previous route/folder if any validation fails. Do not delete rows created before rollback; reconcile by exact tuple to prevent replay duplicates.

Open production evidence: exact legacy acknowledgment, complete direction-code mapping, production `tbl_rawdata` engine, DB receiver privileges, and the matching private receiver audit entries for the observed 422/503 window.
