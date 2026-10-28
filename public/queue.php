<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
if (LegacyAttendanceRepository::active()) {
    header('Cache-Control: no-store');
    require __DIR__ . '/legacy-view.php';
    exit;
}
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$notice = '';
$preview = null;
$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
try {
    if (!$id) {
        compat_throw(new InvalidArgumentException('Valid queue ID is required.'));
    }
    $service = new TalentaApiService(new AttendanceRepository(null, 'worker'));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pending = $_SESSION['queue_preview'] ?? null;
        unset($_SESSION['queue_preview']);
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? '')) || !$pending || $pending['id'] !== $id || $pending['expires'] < time() || !hash_equals($pending['nonce'], (string) ($_POST['nonce'] ?? ''))) {
            compat_throw(new RuntimeException('A fresh queue preview and explicit confirmation are required.'));
        }
        if (!hash_equals($pending['config'], hash('sha256', json_encode(config()) . json_encode(Mekari::contract()) . IntegrationSettings::fingerprint()))) {
            compat_throw(new RuntimeException('Configuration changed; review again.'));
        }
        $response = $service->submit($id, $pending['hash'], (string) ($_POST['confirmation'] ?? ''), isset($_POST['acknowledge']));
        $notice = $response['response'];
    } else {
        $preview = $service->preview($id);
        $_SESSION['queue_preview'] = ['id' => $id, 'expires' => time() + 600, 'nonce' => bin2hex(random_bytes(24)), 'hash' => hash('sha256', $preview['entry']['batch_hash'] . $preview['csv']), 'config' => hash('sha256', json_encode(config()) . json_encode(Mekari::contract()) . IntegrationSettings::fingerprint())];
        if (isset($_GET['download'])) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="talenta-review.csv"');
            echo $preview['csv'];
            exit;
        }
    }
} catch (Throwable $exception) {
    $error = $exception instanceof PDOException ? 'Local database storage unavailable.' : $exception->getMessage();
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Queue Review</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main><header><div><div class="eyebrow">TALENTA / MANUAL APPROVAL</div><h1>Queue #<?php 
echo e($id ?: '—');
?></h1></div><span class="badge text-bg-warning"><?php 
echo Mekari::ready() ? 'LIVE TRIAL ENABLED' : 'OUTBOUND LOCKED';
?></span></header>
<?php 
if ($error) {
    ?><div class="alert alert-danger"><?php 
    echo e($error);
    ?></div><?php 
}
if ($notice) {
    ?><div class="alert alert-info"><?php 
    echo e($notice);
    ?></div><?php 
}
?>
<p><a href="attendance.php#queue">Kembali ke antrean</a></p>
<?php 
if (!Mekari::ready()) {
    ?><div class="alert alert-warning">Kirim langsung belum aktif. Admin perlu memverifikasi profil CSV, NIK/user Talenta, kredensial, dan mengaktifkan izin pengiriman. Tombol manual tidak memerlukan scheduler Windows, tetapi tetap memerlukan semua validasi dan konfirmasi. Menyimpan simulasi tidak mengaktifkan izin ini.</div><?php 
}
if ($preview) {
    $pending = $_SESSION['queue_preview'];
    ?><section class="panel"><h2>Verified identity mapping</h2><dl><?php 
    foreach (['card_number' => 'Machine card', 'employee_nik' => 'Employee NIK', 'talenta_employee_id' => 'Talenta Employee ID', 'talenta_user_id' => 'Talenta User ID', 'badge_number' => 'Fingerprint badge'] as $key => $label) {
        ?><dt><?php 
        echo e($label);
        ?></dt><dd><?php 
        echo e($preview['group']['mapping'][$key]);
        ?></dd><?php 
    }
    ?></dl><p>Shift <?php 
    echo e($preview['group']['shift']);
    ?> · <?php 
    echo e($preview['group']['shift_date']);
    ?> · <?php 
    echo count($preview['events']);
    ?> events</p></section><section class="panel"><h2>Talenta import CSV preview</h2><pre><?php 
    echo e($preview['csv']);
    ?></pre><a class="btn btn-outline-primary" href="?id=<?php 
    echo e($id);
    ?>&amp;download=1">Download CSV only</a></section><section class="panel trial"><h2>Kirim manual ke Talenta</h2><p>Tidak perlu menunggu scheduler Windows. Periksa CSV dan identitas, lalu konfirmasi. Preview berlaku 10 menit; data diperiksa ulang sebelum dikirim.</p><form method="post"><input type="hidden" name="csrf" value="<?php 
    echo e($_SESSION['csrf']);
    ?>"><input type="hidden" name="nonce" value="<?php 
    echo e($pending['nonce']);
    ?>"><label class="form-label" for="confirmation">Type SEND <?php 
    echo e(env('ATTENDANCE_TEST_NIK'));
    ?></label><input class="form-control mb-3" id="confirmation" name="confirmation" required autocomplete="off"><label class="d-block mb-3"><input type="checkbox" name="acknowledge" required> Saya memastikan karyawan dan format CSV benar serta menyetujui pengiriman langsung.</label><button class="btn btn-danger" <?php 
    echo Mekari::ready() ? '' : 'disabled';
    ?>>Kirim sekarang ke Talenta</button></form></section><?php 
}
?></main></body></html>

