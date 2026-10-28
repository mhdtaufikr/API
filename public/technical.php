<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
if (LegacyAttendanceRepository::active()) {
    ReceiverHttp::localOnly();
    header('Cache-Control: no-store');
    require __DIR__ . '/legacy-view.php';
    exit;
}
ReceiverHttp::localOnly();
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$message = '';
$error = '';
$rows = [];
$result = ['events' => [], 'issues' => []];
$schema = [];
$csv = '';
$dbStatus = 'Not connected';
$mode = $_POST['mode'] ?? 'sample';
$from = $_POST['from'] ?? date('Y-m-d');
$to = $_POST['to'] ?? $from;
if (!in_array($mode, ['sample', 'preview', 'trial'], true)) {
    $mode = 'sample';
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            compat_throw(new RuntimeException('Session expired; reload and try again.'));
        }
        if (($_POST['action'] ?? '') === 'send' || $mode === 'trial') {
            unset($_SESSION['pending']);
            compat_throw(new RuntimeException('Use the reviewed integration queue. Explicit employee/card/badge mapping and shift validation are required.'));
        } else {
            unset($_SESSION['pending']);
            foreach ([$from, $to] as $date) {
                $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                    compat_throw(new RuntimeException('Use valid dates.'));
                }
            }
            if ($to < $from || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 31) {
                compat_throw(new RuntimeException('Select up to 31 days in chronological order.'));
            }
            if ($mode === 'sample') {
                $rows = Attendance::sample();
                $nik = 'DEMO-001';
            } else {
                $rows = Database::rows($from, $to);
                $nik = env('ATTENDANCE_TEST_NIK');
                $dbStatus = 'Connected · read-only';
            }
            $result = Attendance::validate($rows, $nik);
            $csv = Attendance::csv($result['events']);
            if (($_POST['action'] ?? '') === 'download') {
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="attendance-preview.csv"');
                echo $csv;
                exit;
            }
        }
    } else {
        $rows = Attendance::sample();
        $result = Attendance::validate($rows, 'DEMO-001');
        $csv = Attendance::csv($result['events']);
    }
} catch (Throwable $exception) {
    $error = $exception instanceof PDOException ? 'Database connection or read failed. Check service, credentials and read permissions locally.' : $exception->getMessage();
}
try {
    $logs = Mekari::logs();
} catch (Throwable $_ignored) {
    $logs = [];
    $error = 'Integration ledger unavailable. API Trial must remain disabled.';
}
try {
    $schema = jsonFile(ROOT . '/storage/schema.json');
} catch (Throwable $_ignored) {
}
$ready = Mekari::ready();
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>MKM | Attendance Integration</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head>
<body><?php 
require ROOT . '/app/sidebar.php';
?>
<main><header><div><div class="eyebrow">HR OPERATIONS / INTEGRATIONS</div><h1>Attendance Integration</h1><p>Review local attendance and control every import to Mekari Talenta.</p></div><span class="badge text-bg-<?php 
echo $ready ? 'warning' : 'success';
?>"><?php 
echo $ready ? 'API TRIAL ENABLED' : 'SAFE PREVIEW';
?></span></header>
<?php 
if ($error) {
    ?><div class="alert alert-danger" role="alert"><?php 
    echo e($error);
    ?></div><?php 
}
if ($message) {
    ?><div class="alert alert-info" role="status"><?php 
    echo e($message);
    ?></div><?php 
}
require ROOT . '/app/machine-dashboard.php';
?>
<div class="row g-3 mb-4">
<?php 
foreach ([['Database driver', Database::driver(), extension_loaded('pdo_' . Database::driver()) ? 'PDO driver available' : 'PDO driver unavailable'], ['Mekari configuration', env('MEKARI_CLIENT_ID') && env('MEKARI_CLIENT_SECRET') && env('MEKARI_IMPORT_TOKEN') ? 'Credentials configured' : 'Credentials incomplete', 'Required scope: talenta:attendance'], ['Employee NIK', env('ATTENDANCE_TEST_NIK') ?: 'Not configured', 'Single employee restriction'], ['Integration status', $ready ? 'Awaiting confirmation' : 'Outbound locked', 'DRY_RUN=' . env('DRY_RUN') . ' · WRITE=' . env('ALLOW_PRODUCTION_WRITE')]] as [$label, $value, $detail]) {
    ?><div class="col-md-6 col-xl-3"><section class="status-card"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong><small><?php 
    echo e($detail);
    ?></small></section></div><?php 
}
?></div>
<section class="panel"><h2>Attendance workspace</h2><p class="muted">Sample data is synthetic. Database Preview never sends data. API Trial requires a verified request contract and explicit confirmation.</p>
<form method="post" class="row g-3 align-items-end"><input type="hidden" name="csrf" value="<?php 
echo e($_SESSION['csrf']);
?>"><div class="col-md-4"><label for="mode" class="form-label">Processing mode</label><select name="mode" id="mode" class="form-select"><?php 
foreach (['sample' => '1 · Sample Data', 'preview' => '2 · Source Database Preview', 'trial' => '3 · API Trial — use queue above'] as $key => $label) {
    ?><option value="<?php 
    echo $key;
    ?>" <?php 
    echo $mode === $key ? 'selected' : '';
    ?> <?php 
    echo $key === 'trial' ? 'disabled' : '';
    ?>><?php 
    echo e($label);
    ?></option><?php 
}
?></select></div><div class="col-md-2"><label for="from" class="form-label">From</label><input id="from" type="date" name="from" value="<?php 
echo e($from);
?>" class="form-control" required></div><div class="col-md-2"><label for="to" class="form-label">Through</label><input id="to" type="date" name="to" value="<?php 
echo e($to);
?>" class="form-control" required></div><div class="col-md-4 d-flex gap-2"><button class="btn btn-primary" name="action" value="preview">Build preview</button><button class="btn btn-outline-secondary" name="action" value="download">Download CSV</button></div></form></section>
<div class="row g-4"><div class="col-xl-7"><section class="panel"><div class="section-heading"><h2>Attendance records</h2><span class="badge text-bg-light"><?php 
echo count($rows);
?> records · <?php 
echo e($mode);
?></span></div><div class="table-responsive"><table class="table"><thead><tr><th>NIK</th><th>Source timestamp</th><th>Direction</th></tr></thead><tbody><?php 
foreach ($rows as $row) {
    ?><tr><td><?php 
    echo e($row['nik']);
    ?></td><td><?php 
    echo e($row['timestamp']);
    ?></td><td><?php 
    echo e($row['direction']);
    ?></td></tr><?php 
}
if (!$rows) {
    ?><tr><td colspan="3" class="muted">No records loaded for this selection.</td></tr><?php 
}
?></tbody></table></div></section></div>
<div class="col-xl-5"><section class="panel"><h2>Clock-in / Clock-out preview</h2><p class="muted">Directions come from explicit source mapping. No shift or first/last tap inference.</p><?php 
foreach ($result['events'] as $event) {
    ?><div class="event"><span class="badge <?php 
    echo $event['direction'] === 'IN' ? 'text-bg-success' : 'text-bg-primary';
    ?>"><?php 
    echo $event['direction'] === 'IN' ? 'Clock in' : 'Clock out';
    ?></span><span><?php 
    echo e($event['timestamp']);
    ?></span></div><?php 
}
if (!$result['events']) {
    ?><p class="muted">No validated events.</p><?php 
}
foreach ($result['issues'] as $issue) {
    ?><div class="validation">Row <?php 
    echo e($issue['row']);
    ?> · <?php 
    echo e($issue['reason']);
    ?></div><?php 
}
?></section></div></div>
<section class="panel"><h2>CSV preview</h2><p class="muted">Canonical review CSV only. Talenta import CSV is reviewed through the mapped employee queue above.</p><pre><?php 
echo e($csv ?: 'No CSV generated.');
?></pre></section>
<section class="panel" id="api-logs"><h2>API response logs</h2><p class="muted">Reservations prevent overlap across batches. HTTP acceptance requires reconciliation in Talenta. Raw responses are hashed to protect sensitive data.</p><div class="table-responsive"><table class="table"><thead><tr><th>UTC</th><th>Events</th><th>Status</th><th>Response</th></tr></thead><tbody><?php 
foreach (array_reverse($logs) as $log) {
    ?><tr><td><?php 
    echo e($log['at']);
    ?></td><td><?php 
    echo e($log['count']);
    ?></td><td><?php 
    echo e($log['status']);
    ?></td><td><?php 
    echo e($log['response']);
    ?></td></tr><?php 
}
if (!$logs) {
    ?><tr><td colspan="4" class="muted">No API requests have been made.</td></tr><?php 
}
?></tbody></table></div></section>
<section class="panel" id="database-schema"><h2>Database schema inspection</h2><p class="muted">Run <code>php bin/console.php inspect</code> locally after configuring .env. Only metadata is inspected; the source database is never altered.</p><div class="table-responsive"><table class="table"><thead><tr><th>Schema</th><th>Table</th><th>Column</th><th>Type</th></tr></thead><tbody><?php 
foreach ($schema as $col) {
    ?><tr><td><?php 
    echo e($col['table_schema']);
    ?></td><td><?php 
    echo e($col['table_name']);
    ?></td><td><?php 
    echo e($col['column_name']);
    ?></td><td><?php 
    echo e($col['data_type']);
    ?></td></tr><?php 
}
?></tbody></table></div></section>
<footer>MKM Attendance Integration · PHP <?php 
echo e(PHP_VERSION);
?> · <?php 
echo PHP_VERSION_ID >= 70400 ? 'Runtime supported' : 'PHP 7.4+ required for API Trial';
?> · Local operator access</footer></main></body></html>

