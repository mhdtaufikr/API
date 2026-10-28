<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
if (LegacyAttendanceRepository::active() && env('INTEGRATION_STORAGE') !== 'legacy_app') {
    http_response_code(403);
    exit('Legacy compatibility mode is read-only. Configure credentials in .env; no config table is created or updated.');
}
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://cdn.jsdelivr.net; script-src 'none'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$error = '';
$row = null;
$service = null;
$notice = $_SESSION['settings_notice'] ?? '';
unset($_SESSION['settings_notice']);
try {
    $service = new IntegrationSettings(Database::open($_SERVER['REQUEST_METHOD'] === 'POST' ? 'settings' : 'reader'), env('APP_KEY'));
    $row = $service->row();
    if (!$row) {
        compat_throw(new RuntimeException('Run php bin/console.php seed to initialize Mekari settings.'));
    }
    $service->values();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            compat_throw(new InvalidArgumentException('Session expired. Reload before saving.'));
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
            compat_throw(new InvalidArgumentException('Settings request exceeds the allowed size.'));
        }
        $revision = filter_var($_POST['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($revision === false || $revision === null) {
            compat_throw(new InvalidArgumentException('Invalid settings revision.'));
        }
        $clear = $_POST['clear'] ?? [];
        if (!is_array($clear)) {
            compat_throw(new InvalidArgumentException('Invalid clear selection.'));
        }
        $service->save(array_intersect_key($_POST, IntegrationSettings::FIELDS), $clear, $revision);
        unset($_SESSION['queue_preview'], $_SESSION['pending']);
        $_SESSION['settings_notice'] = 'Credentials saved securely. No API request was sent; live submission settings are unchanged.';
        header('Location: settings.php', true, 303);
        exit;
    }
} catch (PDOException $_ignored) {
    $error = 'Credential storage is not ready. Run php bin/console.php migrate, then php bin/console.php seed.';
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}
$keyReady = true;
try {
    new CredentialCipher(env('APP_KEY'));
} catch (Throwable $_ignored) {
    $keyReady = false;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MKM | Mekari Credentials</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous"><link href="app.css" rel="stylesheet"><link href="sidebar.css" rel="stylesheet"></head><body><?php 
require ROOT . '/app/sidebar.php';
?><main>
<header><div><div class="eyebrow">CONFIGURATION / MEKARI TALENTA</div><h1>Pengaturan Talenta</h1><p>Isi akun penghubung ke Talenta. Menyimpan pengaturan tidak langsung mengirim absensi.</p></div><span class="badge text-bg-success">TERSIMPAN TERENKRIPSI</span></header>
<?php 
if ($error) {
    ?><div class="alert alert-danger" role="alert"><?php 
    echo e($error);
    ?></div><?php 
}
if ($notice) {
    ?><div class="alert alert-success" role="status"><?php 
    echo e($notice);
    ?></div><?php 
}
?>
<div class="row g-3 mb-4"><?php 
foreach ([['API host', env('MEKARI_BASE_URL')], ['Company ID', env('MEKARI_COMPANY_ID')], ['Database', Database::driver()], ['Live submission', enabled('DRY_RUN') || !enabled('ALLOW_PRODUCTION_WRITE') ? 'Disabled' : 'Requires all trial checks']] as [$label, $value]) {
    ?><div class="col-md-3"><section class="status-card"><div class="label"><?php 
    echo e($label);
    ?></div><strong><?php 
    echo e($value);
    ?></strong></section></div><?php 
}
?></div>
<section class="panel credentials-panel"><h2>Akun penghubung Talenta</h2><p class="muted">Nilai yang sudah disimpan tidak ditampilkan. Kosongkan kolom untuk mempertahankan nilai lama. Isi hanya jika ingin menggantinya.</p>
<?php 
if (!$keyReady) {
    ?><div class="alert alert-warning">Encryption key is not ready. Run <code>php bin/console.php seed</code>. If encrypted data already exists, restore the original APP_KEY.</div><?php 
}
?>
<form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?php 
echo e($_SESSION['csrf']);
?>"><input type="hidden" name="revision" value="<?php 
echo e($row['revision'] ?? 0);
?>">
<?php 
foreach (['MEKARI_CLIENT_ID' => ['Client ID (Mekari service)', 'Application Client ID issued by Mekari Developer Center.'], 'MEKARI_CLIENT_SECRET' => ['Client Secret', 'HMAC signing secret for the same Mekari application.'], 'MEKARI_IMPORT_TOKEN' => ['Attendance Import Token', 'Issued by Mekari/Talenta for fingerprint imports. This application cannot generate a Mekari token.']] as $key => [$label, $help]) {
    $configured = !empty($row[IntegrationSettings::FIELDS[$key]]);
    ?><div class="credential-field"><div class="section-heading"><label for="<?php 
    echo e($key);
    ?>" class="form-label"><?php 
    echo e($label);
    ?></label><span class="badge <?php 
    echo $configured ? 'text-bg-success' : 'text-bg-light';
    ?>"><?php 
    echo $configured ? 'Sudah diisi' : 'Belum diisi';
    ?></span></div><input class="form-control" id="<?php 
    echo e($key);
    ?>" name="<?php 
    echo e($key);
    ?>" type="password" value="" autocomplete="new-password" maxlength="4096" spellcheck="false" placeholder="<?php 
    echo $configured ? 'Isi hanya untuk mengganti nilai lama' : 'Masukkan nilai';
    ?>" <?php 
    echo $row && $keyReady ? '' : 'disabled';
    ?>><p class="muted mt-2 mb-2"><?php 
    echo e($help);
    ?></p><?php 
    if ($configured) {
        ?><label class="credential-clear"><input type="checkbox" name="clear[<?php 
        echo e($key);
        ?>]" value="1"> Hapus nilai tersimpan: <?php 
        echo e($label);
        ?></label><?php 
    }
    ?></div><?php 
}
?>
<div class="settings-footer"><div class="muted">Required scope: <code>talenta:attendance</code><br>Encrypted with AES-256-GCM. APP_KEY stays in .env.</div><button class="btn btn-primary" <?php 
echo $row && $keyReady ? '' : 'disabled';
?>>Simpan pengaturan</button></div></form></section>
<section class="panel"><h2>Penyimpanan pengaturan</h2><p class="muted">Client ID, Client Secret, dan Import Token disimpan terenkripsi di database integrasi. Koneksi database dan APP_KEY ada di .env. Backup APP_KEY bersama database agar pengaturan dapat dibaca kembali.</p><p class="mb-0">Storage revision <?php 
echo e($row['revision'] ?? '—');
?> · Last updated <?php 
echo e($row['updated_at'] ?? 'Not initialized');
?></p></section>
</main></body></html>

