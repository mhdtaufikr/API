<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
set_time_limit(40);
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$filters = TalentaAttendanceReader::filters([]);
$result = null;
$error = '';
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            compat_throw(new InvalidArgumentException('Sesi berakhir. Muat ulang halaman sebelum menarik data.'));
        }
        $filters = TalentaAttendanceReader::filters($_POST);
        $result = (new TalentaAttendanceReader())->fetch($filters);
    }
} catch (Throwable $exception) {
    $error = $exception instanceof PDOException ? 'Konfigurasi database credential belum siap.' : $exception->getMessage();
}
$configured = env('MEKARI_CLIENT_ID') !== '' && env('MEKARI_CLIENT_SECRET') !== '';
$rows = $result['rows'] ?? [];
$pagination = $result['pagination'] ?? [];
$page = $pagination['current_page'] ?? $filters['page'];
$hasPrevious = $result && $page > 1;
$hasNext = $result && (isset($pagination['last_page']) ? $page < $pagination['last_page'] : count($rows) === $filters['limit']);
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Tarik Absensi Talenta</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main>
<header><div><div class="eyebrow">MEKARI TALENTA / ATTENDANCE REPORT</div><h1>Tarik Absensi Talenta</h1><p>Cari rekap absensi berdasarkan nomor karyawan atau nama. Halaman ini bukan daftar master karyawan.</p></div><span class="badge text-bg-primary">GET · HMAC</span></header>
<?php 
if ($error) {
    ?><div class="alert alert-danger" role="alert"><?php 
    echo e($error);
    ?></div><?php 
}
if (!$configured) {
    ?><div class="alert alert-warning">Isi Client ID dan Client Secret melalui <a href="settings.php">Mekari Credentials</a> terlebih dahulu.</div><?php 
}
?>
<div class="row g-3 mb-4"><?php 
foreach ([['Credential', $configured ? 'Siap' : 'Belum lengkap'], ['Company ID', env('MEKARI_COMPANY_ID')], ['Respons API', $result ? 'HTTP ' . $result['http'] : ($error ? 'Gagal · lihat pesan' : 'Belum ditarik')], ['Baris / total', $result ? count($rows) . ' / ' . ($pagination['total'] ?? '—') : '—']] as [$label, $value]) {
    ?><div class="col-md-3"><section class="status-card"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong></section></div><?php 
}
?></div>
<section class="panel"><h2>Filter penarikan</h2><form method="post" class="row g-3 align-items-end"><input type="hidden" name="csrf" value="<?php 
echo e($_SESSION['csrf']);
?>"><input type="hidden" name="page" value="1"><div class="col-md-3"><label for="from" class="form-label">Dari tanggal</label><input id="from" name="from" type="date" class="form-control" value="<?php 
echo e($filters['from']);
?>" required></div><div class="col-md-3"><label for="to" class="form-label">Sampai tanggal</label><input id="to" name="to" type="date" class="form-control" value="<?php 
echo e($filters['to']);
?>" required></div><div class="col-md-2"><label for="limit" class="form-label">Baris per halaman</label><select id="limit" name="limit" class="form-select"><?php 
foreach ([10, 25, 50, 100] as $limit) {
    ?><option value="<?php 
    echo $limit;
    ?>" <?php 
    echo $filters['limit'] === $limit ? 'selected' : '';
    ?>><?php 
    echo $limit;
    ?></option><?php 
}
?></select></div><div class="col-md-8"><label for="search" class="form-label">Pencarian nomor karyawan / nama (minimal 3 karakter)</label><input id="search" name="search" class="form-control" value="<?php 
echo e($filters['search']);
?>" maxlength="100"></div><div class="col-md-4"><button class="btn btn-primary w-100" <?php 
echo $configured ? '' : 'disabled';
?>>Tarik data absensi</button></div><div class="col-12"><p class="muted mb-2">Contoh: masukkan <strong>2120</strong> pada Pencarian pegawai untuk mencari nomor karyawan tersebut. Kosongkan untuk semua pegawai yang diizinkan.</p><details <?php 
echo $filters['user_ids'] !== '' ? 'open' : '';
?>><summary>Filter lanjutan: Talenta User ID internal</summary><label for="user_ids" class="form-label mt-2">ID internal Talenta, bukan nomor karyawan/NIK/kartu</label><input id="user_ids" name="user_ids" class="form-control" value="<?php 
echo e($filters['user_ids']);
?>" maxlength="400" placeholder="Opsional — kosongkan jika tidak tahu ID internal"><p class="muted mt-2 mb-0">Filter ini biasanya tidak perlu diisi. Nilainya berbeda dari Employee ID yang tercetak di data karyawan.</p></details></div></form><p class="muted mt-3 mb-0">Maksimal 31 hari per penarikan. Data mengikuti scope dan izin company aplikasi Mekari. Import Token digunakan untuk import fingerprint; pembacaan ini memakai Client ID dan Client Secret.</p></section>
<section class="panel"><div class="section-heading"><h2>Rekap absensi</h2><span class="badge text-bg-light">Halaman <?php 
echo e($page);
echo isset($pagination['last_page']) ? ' / ' . e($pagination['last_page']) : '';
?></span></div><?php 
if ($result) {
    ?><p class="muted">Ditarik <?php 
    echo e($result['fetched_at']);
    ?> · <?php 
    echo e($filters['from']);
    ?> sampai <?php 
    echo e($filters['to']);
    echo $result['request_id'] !== '' ? ' · Request ' . e($result['request_id']) : '';
    ?></p><?php 
}
?>
<div class="table-responsive"><table class="table"><thead><tr><th>Tanggal</th><th>Employee ID / User ID</th><th>Nama pegawai</th><th>Shift</th><th>Jadwal IN / OUT</th><th>Clock IN / OUT</th><th>Status</th><th>Late / Early (min)</th><th>Actual work (min)</th></tr></thead><tbody><?php 
foreach ($rows as $row) {
    ?><tr><td><?php 
    echo e($row['schedule_date'] ?? '—');
    ?></td><td><?php 
    echo e($row['employee_id'] ?? '—');
    ?><br><small><?php 
    echo e($row['user_id'] ?? '—');
    ?></small></td><td><?php 
    echo e($row['full_name'] ?? '—');
    ?></td><td><?php 
    echo e($row['shift_name'] ?? '—');
    ?></td><td><?php 
    echo e($row['schedule_in'] ?? '—');
    ?><br><?php 
    echo e($row['schedule_out'] ?? '—');
    ?></td><td><strong><?php 
    echo e($row['clock_in'] ?? '—');
    ?></strong><br><?php 
    echo e($row['clock_out'] ?? '—');
    ?></td><td><?php 
    echo e($row['attendance_code'] ?? '—');
    echo !empty($row['holiday']) ? ' · Holiday' : '';
    ?></td><td><?php 
    echo e($row['late_in'] ?? '—');
    ?> / <?php 
    echo e($row['early_out'] ?? '—');
    ?></td><td><?php 
    echo e($row['actual_working_hour'] ?? '—');
    ?></td></tr><?php 
}
if (!$rows) {
    ?><tr><td colspan="9" class="muted"><?php 
    echo $result ? 'Tidak ada absensi pada halaman/filter ini.' : 'Pilih tanggal lalu klik Tarik data absensi.';
    ?></td></tr><?php 
}
?></tbody></table></div>
<?php 
if ($hasPrevious || $hasNext) {
    ?><div class="d-flex justify-content-between mt-3"><?php 
    foreach ([[$hasPrevious, max(1, $page - 1), '← Sebelumnya'], [$hasNext, $page + 1, 'Berikutnya →']] as [$enabled, $next, $label]) {
        ?><form method="post"><input type="hidden" name="csrf" value="<?php 
        echo e($_SESSION['csrf']);
        ?>"><?php 
        foreach ($filters as $key => $value) {
            ?><input type="hidden" name="<?php 
            echo e($key);
            ?>" value="<?php 
            echo e($key === 'page' ? $next : $value);
            ?>"><?php 
        }
        ?><button class="btn btn-outline-primary" <?php 
        echo $enabled ? '' : 'disabled';
        ?>><?php 
        echo e($label);
        ?></button></form><?php 
    }
    ?></div><?php 
}
?></section>
<footer>Attendance Summary Report · <?php 
echo e(TalentaAttendanceReader::PATH);
?> · Data ditampilkan dari Talenta; raw log mesin dan antrean lokal tetap terpisah.</footer></main></body></html>

