<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
set_time_limit(180);
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$preview = null;
$error = '';
$response = null;
try {
    if (env('INTEGRATION_STORAGE') !== 'legacy_app') {
        compat_throw(new RuntimeException('Pengiriman manual tidak tersedia pada mode ini.'));
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pending = $_SESSION['legacy_send'] ?? null;
        unset($_SESSION['legacy_send']);
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf']) || !$pending || $pending['expires'] < time() || !hash_equals($pending['nonce'], (string) ($_POST['nonce'] ?? ''))) {
            compat_throw(new RuntimeException('Preview kedaluwarsa. Buka ulang dari daftar data.'));
        }
        $service = new LegacyManualSend(new LegacyAttendanceRepository(Database::open('worker')));
        $response = $service->send($pending['scope'], $pending['hash']);
    } else {
        $scope = [];
        foreach (['from', 'to', 'card', 'selection'] as $key) {
            if (!is_string($_GET[$key] ?? null)) {
                compat_throw(new InvalidArgumentException('Filter pilihan tidak lengkap.'));
            }
            $scope[] = $_GET[$key];
        }
        $preview = (new LegacyManualSend(new LegacyAttendanceRepository(Database::open('reader'))))->preview(...$scope);
        $_SESSION['legacy_send'] = ['scope' => $scope, 'hash' => $preview['hash'], 'nonce' => bin2hex(random_bytes(24)), 'expires' => time() + 600];
    }
} catch (Throwable $e) {
    $error = $e instanceof PDOException ? 'Penyimpanan data tidak tersedia; tidak ada pengiriman ulang otomatis.' : $e->getMessage();
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Kirim Absensi Pilihan</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main><header><div><h1>Kirim absensi pilihan</h1><p>Review satu item atau seluruh item FlFlag=0 pada filter. Tidak perlu menunggu scheduler Windows.</p></div><a href="attendance.php" class="btn btn-outline-secondary">Kembali ke data</a></header>
<?php 
if ($error) {
    ?><div class="alert alert-danger"><?php 
    echo e($error);
    ?></div><?php 
}
?>
<section class="panel"><h2>Profil Import Token: <?php 
echo e(Mekari::contract()['attendance_machine_profile']['name'] ?? 'Belum terverifikasi');
?></h2><p>Tanggal kolom 1: <code>YYYY-MM-DD</code> · Jam kolom 2: <code>HH:MM:SS</code> · Barcode kolom 3. Multiple Row, header baris 1, data mulai baris 2, delimiter koma, klasifikasi Range.</p><p class="muted mb-0">Profil ini menggantikan CSV API dua kolom yang tidak cocok dengan token Attendance MKM. Item yang pernah dicoba tetap ditahan dari resend otomatis.</p></section>
<?php 
if ($response) {
    ?><section class="panel"><h2>Hasil request Talenta</h2><p>Status: <?php 
    echo e($response['status']);
    ?> · HTTP <?php 
    echo e($response['http']);
    ?></p><p><?php 
    echo e($response['response']);
    ?></p><?php 
    foreach ($response['reference'] ?? [] as $referenceKey => $referenceValue) {
        ?><p><?php 
        echo e($referenceKey);
        ?>: <code><?php 
        echo e($referenceValue);
        ?></code></p><?php 
    }
    ?><p>Periksa Inbox Talenta akun pengimpor untuk hasil proses. Jangan resend hanya karena rekap belum berubah.</p><p>FlFlag: <?php 
    echo $response['flag_updated'] ? 'berhasil diperbarui menjadi 1' : 'tidak diubah';
    ?>. HTTP diterima API belum berarti proses import selesai; cek hasil di Talenta.</p><a class="btn btn-primary" href="talenta-attendance.php">Cek di Talenta</a></section><?php 
}
if ($preview) {
    ?><section class="panel"><h2><?php 
    echo count($preview['rows']);
    ?> item dipilih</h2><div class="table-responsive"><table class="table"><thead><tr><th>Kartu</th><th>Tanggal</th><th>Jam</th><th>Terminal</th><th>FcDirection mentah</th></tr></thead><tbody><?php 
    foreach ($preview['rows'] as $row) {
        ?><tr><?php 
        foreach (['FcCardNo', 'FdDate', 'FcTime', 'FcTermNo', 'FcDirection'] as $field) {
            ?><td><?php 
            echo e($row[$field]);
            ?></td><?php 
        }
        ?></tr><?php 
    }
    ?></tbody></table></div><p class="muted mt-3">Kode 1 tidak diasumsikan masuk/pulang. Seluruh pilihan ditahan jika satu item ambigu atau tidak valid; tidak mengirim sebagian tanpa konfirmasi baru.</p></section><section class="panel"><h2>Preview CSV Talenta</h2><p class="muted">Profil token: <strong><?php 
    echo e(Mekari::contract()['attendance_machine_profile']['name'] ?? 'Belum terverifikasi');
    ?></strong>. Date kolom 1, Time kolom 2, barcode kolom 3. Request yang pernah dicoba tidak dikirim ulang otomatis.</p><?php 
    foreach ($preview['issues'] as $issue) {
        ?><div class="alert alert-warning"><?php 
        echo e($issue);
        ?></div><?php 
    }
    ?><pre><?php 
    echo e($preview['csv'] ?: 'CSV belum siap. Lengkapi mapping kartu dan profil import terverifikasi.');
    ?></pre><?php 
    if (!$preview['issues']) {
        ?><h2>Kirim data pilihan</h2><form method="post"><input type="hidden" name="csrf" value="<?php 
        echo e($_SESSION['csrf']);
        ?>"><input type="hidden" name="nonce" value="<?php 
        echo e($_SESSION['legacy_send']['nonce']);
        ?>"><p>Klik tombol berikut untuk mengirim <?=count($preview['rows'])?> item sesuai preview. Tidak perlu mengetik konfirmasi.</p><button class="btn btn-danger" <?php 
        echo $preview['issues'] ? 'disabled' : '';
        ?>>Kirim sekarang ke Talenta</button></form><?php 
    } else {
        ?><p class="muted">Selesaikan masalah validasi di atas. Pengiriman hanya tersedia jika semua item memiliki identitas yang benar dan izin live aktif.</p><?php 
    }
    ?></section><?php 
}
?></main></body></html>

