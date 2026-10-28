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
$db = false;
$count = null;
$credentials = false;
$last = null;
try {
    $repo = new AttendanceRepository(null, 'reader');
    $repo->assertInstalled();
    $count = $repo->count();
    $db = true;
} catch (Throwable $_ignored) {
}
try {
    $credentials = env('MEKARI_CLIENT_ID') !== '' && env('MEKARI_CLIENT_SECRET') !== '' && env('MEKARI_IMPORT_TOKEN') !== '';
} catch (Throwable $_ignored) {
}
try {
    $logs = Mekari::logs();
    $last = $logs ? end($logs) : null;
} catch (Throwable $_ignored) {
}
?><!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Ringkasan Absensi</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main>
<header><div><div class="eyebrow">MKM / ABSENSI</div><h1>Ringkasan</h1><p>Mesin menyimpan absen di database. Windows menjalankan pengiriman ke Talenta sesuai jadwal.</p></div><span class="badge text-bg-<?php 
echo enabled('DRY_RUN') ? 'success' : 'warning';
?>"><?php 
echo enabled('DRY_RUN') ? 'MODE CEK' : 'PERIKSA SEBELUM KIRIM';
?></span></header>
<div class="row g-3 mb-4"><?php 
foreach ([['Database integrasi', $db ? 'Terhubung' : 'Belum siap', $db ? 'Penyimpanan aplikasi siap dibaca.' : 'Periksa koneksi dan migration.'], ['Data dari mesin', $count === null ? 'Belum tersedia' : $count . ' catatan', 'Tersimpan lokal; belum tentu terkirim ke Talenta.'], ['Akun Talenta', $credentials ? 'Sudah diisi' : 'Belum lengkap', 'Client ID, Client Secret, dan Import Token.'], ['Pengiriman terjadwal', enabled('TALENTA_SCHEDULER_ENABLED') ? 'Diaktifkan di aplikasi' : 'Belum aktif', '08.00, 12.00, 18.00 WIB. Task Windows perlu dipasang terpisah.']] as [$label, $value, $help]) {
    ?><div class="col-md-6 col-xl-3"><section class="status-card"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong><small><?php 
    echo e($help);
    ?></small></section></div><?php 
}
?></div>
<section class="panel"><h2>Mulai dari sini</h2><div class="row g-4"><div class="col-md-4"><h2>1. Data Absensi</h2><p class="muted">Lihat jam dan nomor kartu yang diterima dari mesin. Simulator tersedia untuk tes tanpa mesin.</p><a class="btn btn-primary" href="attendance.php">Lihat data masuk</a></div><div class="col-md-4"><h2>2. Cek di Talenta</h2><p class="muted">Tarik absensi yang sudah tercatat di Talenta untuk membandingkan hasilnya. Hanya membaca data.</p><a class="btn btn-outline-primary" href="talenta-attendance.php">Cek hasil di Talenta</a></div><div class="col-md-4"><h2>3. Pengaturan</h2><p class="muted">Isi Client ID, Client Secret, dan Import Token. Menyimpan akun tidak langsung mengirim absensi.</p><a class="btn btn-outline-primary" href="settings.php">Atur akun Talenta</a></div></div></section>
<section class="panel"><h2>Pengiriman terakhir</h2><?php 
if ($last) {
    ?><p>Status: <strong><?php 
    echo e($last['status'] ?? 'Belum diketahui');
    ?></strong> · <?php 
    echo e($last['at'] ?? '');
    ?> (UTC)</p><p class="muted mb-0">Diterima API belum berarti absensi sudah masuk. Cocokkan melalui Cek di Talenta.</p><?php 
} else {
    ?><p class="muted mb-0">Belum ada pengiriman API yang tercatat. Data dari mesin tetap dapat disimpan dan diperiksa.</p><?php 
}
?></section>
<section class="panel"><h2>Database existing: mulai dari pemeriksaan read-only</h2><p>Gunakan <code>.env.example</code> terbaru dengan <strong>INTEGRATION_STORAGE=legacy_readonly</strong> untuk membaca <code>mkm_ssmdb.tbl_rawdata</code>. Tidak perlu membuat tabel atau mengubah FlFlag selama audit.</p><details><summary class="text-primary">Langkah pemeriksaan</summary><div class="mt-3"><p>Pasang di folder baru, isi akun database SELECT-only pada .env aplikasi baru. Jangan menimpa aplikasi, receiver, atau BAT existing. <strong>Jangan jalankan migrate atau seed</strong> dalam mode audit.</p><pre>C:\xampp\php\php.exe bin\audit-runtime.php
+C:\xampp\php\php.exe bin\console.php inspect
+C:\xampp\php\php.exe bin\console.php legacy:preview 2026-10-25 2026-10-27 2120</pre><p>Mode ini hanya membaca source legacy. Pengolahan tersimpan dan kirim dari legacy masih memerlukan identitas event yang aman; tabel asli tidak memiliki primary key. Baris identik tidak dihapus atau digabung.</p><p>Untuk salinan PostgreSQL lokal, tersedia .env.legacy-pgsql.example dan SQL fixture lokal. Runtime saat audit belum memiliki pdo_pgsql; PostgreSQL belum diuji langsung.</p><p class="muted mb-0">Laporan: docs/DATABASE_AUDIT.md. Panduan terbaru: docs/LEGACY_COMPATIBILITY.md. Mode konfigurasi tbl_config sebelumnya tetap opt-in terpisah, bukan langkah audit production.</p></div></details></section><footer>MKM Attendance · PHP <?php 
echo e(PHP_VERSION);
?> · Pengelolaan dari localhost</footer></main></body></html>

