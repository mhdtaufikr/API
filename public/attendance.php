<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
if (LegacyAttendanceRepository::active()) {
    header('Cache-Control: no-store');
    require __DIR__ . '/legacy-view.php';
    exit;
}
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$rows = [];
$queue = [];
$waiting = [];
$error = '';
try {
    $repo = new AttendanceRepository(null, 'reader');
    $repo->assertInstalled();
    $rows = $repo->recent();
    $queue = $repo->queue();
    $claimed = $repo->claimedEvents(array_column($rows, 'id'));
    $waiting = array_values(array_filter($rows, fn($row) => $row['card_number'] === '2120' && !in_array((int) $row['id'], $claimed, true)));
} catch (Throwable $_ignored) {
    $error = 'Data belum bisa dibaca. Periksa koneksi database integrasi dan migration aplikasi.';
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Data Absensi</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main><header><div><div class="eyebrow">MKM / ABSENSI</div><h1>Data Absensi</h1><p>100 catatan terakhir yang diterima aplikasi dari mesin. Tersimpan di sini belum berarti sudah masuk Talenta.</p></div><a class="btn btn-outline-primary" href="machine-simulator.php">Tes dengan simulator</a></header>
<?php 
if ($error) {
    ?><div class="alert alert-warning"><?php 
    echo e($error);
    ?></div><?php 
}
?>
<section class="panel"><h2>Data masuk dari mesin</h2><div class="table-responsive"><table class="table"><thead><tr><th>Tanggal</th><th>Jam (WIB)</th><th>Nomor kartu</th><th>Mesin</th><th>Kode arah mesin</th><th>Status</th></tr></thead><tbody><?php 
foreach ($rows as $row) {
    $when = (new DateTimeImmutable($row['event_datetime']))->setTimezone(new DateTimeZone('Asia/Jakarta'));
    ?><tr><td><?php 
    echo e($when->format('d/m/Y'));
    ?></td><td><?php 
    echo e($when->format('H:i:s'));
    ?></td><td><?php 
    echo e($row['card_number']);
    ?></td><td><?php 
    echo e($row['terminal_id']);
    ?></td><td><?php 
    echo e($row['direction_code']);
    ?></td><td>Tersimpan lokal</td></tr><?php 
}
if (!$rows) {
    ?><tr><td colspan="6">Belum ada data masuk. Gunakan simulator untuk percobaan.</td></tr><?php 
}
?></tbody></table></div><p class="muted mt-3 mb-0">Kode arah mengikuti mesin. Jam masuk/pulang ditentukan setelah pemetaan arah dan shift diverifikasi.</p></section>
<section class="panel" id="queue"><h2>Antrean kirim ke Talenta</h2><p class="muted">Data simulasi yang lolos validasi tampil di sini. Klik Review &amp; kirim manual untuk memeriksa CSV dan konfirmasi pengiriman tanpa menunggu scheduler Windows. Data yang belum punya mapping/shift/arah valid tetap tersimpan lokal dan belum menjadi antrean siap kirim.</p><div class="table-responsive"><table class="table"><thead><tr><th>Antrean</th><th>NIK</th><th>Tanggal shift</th><th>Status</th><th>Tindakan</th></tr></thead><tbody><?php 
foreach ($queue as $item) {
    ?><tr><td>#<?php 
    echo e($item['id']);
    ?></td><td><?php 
    echo e($item['employee_nik']);
    ?></td><td><?php 
    echo e($item['shift_date']);
    ?></td><td><?php 
    echo e(['pending_review' => 'Menunggu review', 'submitted_pending_reconciliation' => 'Terkirim ke API; cek hasil di Talenta', 'confirmed' => 'Terkonfirmasi', 'rejected' => 'Ditolak API', 'unknown' => 'Hasil belum diketahui; jangan kirim ulang', 'reserved' => 'Sedang / pernah diproses; perlu rekonsiliasi'][$item['status']] ?? $item['status']);
    ?></td><td><?php 
    if ($item['status'] === 'pending_review') {
        ?><a class="btn btn-outline-primary btn-sm" href="queue.php?id=<?php 
        echo e($item['id']);
        ?>">Review &amp; kirim manual</a><?php 
    } else {
        ?><span class="muted">Cek hasil; tidak dikirim ulang otomatis</span><?php 
    }
    ?></td></tr><?php 
}
foreach ($waiting as $raw) {
    ?><tr><td>Tap #<?php 
    echo e($raw['id']);
    ?></td><td>Kartu 2120</td><td><?php 
    echo e((new DateTimeImmutable($raw['event_datetime']))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('d/m/Y H:i:s'));
    ?></td><td>Menunggu validasi — belum menjadi batch siap kirim</td><td><button class="btn btn-outline-secondary btn-sm" disabled>Belum siap dikirim</button></td></tr><?php 
}
if (!$queue && !$waiting) {
    ?><tr><td colspan="5">Belum ada antrean siap review. Data masuk belum otomatis siap dikirim: lengkapi pemetaan karyawan, shift, arah mesin, serta validasi absensi.</td></tr><?php 
}
?></tbody></table></div></section><section class="panel"><h2>Langkah berikutnya</h2><p class="muted">Lihat absensi yang sudah tercatat melalui Cek di Talenta. Membuka halaman ini tidak mengirim data.</p><a class="btn btn-primary" href="talenta-attendance.php">Cek di Talenta</a><details class="mt-4"><summary class="text-primary">Alat teknis untuk admin / IT</summary><p class="muted mt-3">Pemetaan karyawan, perhitungan shift, antrean kirim, CSV, dan log tersedia di halaman teknis.</p><a href="technical.php" class="btn btn-outline-secondary">Buka detail teknis</a></details></section></main></body></html>

