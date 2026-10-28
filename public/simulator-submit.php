<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
ReceiverHttp::localOnly();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'path' => '/']);
session_start();
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        compat_throw(new DomainException('POST required.', 405));
    }
    if (!enabled('MACHINE_ALLOW_SIMULATOR') || !enabled('MACHINE_RECEIVER_ENABLED')) {
        compat_throw(new DomainException('Simulator atau receiver nonaktif.', 403));
    }
    if ((LegacyConfigMigration::active() || LegacyAttendanceRepository::active()) && env('INTEGRATION_STORAGE') !== 'legacy_app') {
        compat_throw(new DomainException('Trial penyimpanan tidak tersedia pada mode ini.', 409));
    }
    if (($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site' || !isset($_SESSION['csrf']) || !is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
        compat_throw(new DomainException('Session tidak valid; muat ulang simulator.', 403));
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
        compat_throw(new DomainException('Request terlalu besar.', 413));
    }
    $input = array_intersect_key($_POST, array_flip(['cardno', 'd', 'h', 'm']));
    $event = TrialSimulation::parse($input);
    if (env('INTEGRATION_STORAGE') === 'legacy_app') {
        $direction = $_POST['legacy_direction'] ?? null;
        if (!is_string($direction) || !preg_match('/\A[0-9]\z/', $direction)) {
            compat_throw(new InvalidArgumentException('Isi kode FcDirection legacy satu digit secara eksplisit.'));
        }
        $id = bin2hex(random_bytes(12));
        $audit = new ReceiverAudit();
        try {
            $stored = (new LegacyAttendanceRepository(Database::open('receiver')))->store($event, $input, $_SERVER['REMOTE_ADDR'], $direction);
        } catch (RuntimeException $e) {
            $audit->append(['request_id' => $id, 'status' => 'rejected', 'payload' => $input, 'reason' => 'Legacy store rejected; inspect tuple or storage configuration']);
            if (str_contains($e->getMessage(), 'Identical legacy tuple')) {
                compat_throw(new DomainException('Data identik sudah ada. Tidak disimpan ulang; periksa kemungkinan duplikat. Belum dikirim ke Talenta.', 409));
            }
            if (str_contains($e->getMessage(), 'Legacy source is read-only')) {
                compat_throw(new DomainException('Trial production belum diizinkan. Periksa LEGACY_LOCAL_WRITE dan LEGACY_PRODUCTION_RECEIVER_WRITE_CONFIRMED.', 409));
            }
            compat_throw($e);
        }
        try {
            $audit->append(['request_id' => $id, 'status' => 'stored', 'payload' => $input, 'event_hash' => $event['event_hash'], 'legacy_direction' => $direction]);
        } catch (Throwable $_ignored) {
            echo json_encode(['status' => 'stored_audit_warning', 'message' => 'Data tersimpan tetapi log audit gagal. Jangan mengulang otomatis.', 'talenta_sent' => false, 'parsed' => $event], JSON_THROW_ON_ERROR);
            exit;
        }
        $hrms = HrmsSdfExport::onReceive($id, $_SERVER['REMOTE_ADDR']);
        echo json_encode(['status' => 'stored', 'message' => 'BERHASIL: endpoint menyimpan absensi di tbl_rawdata. FlFlag=0; belum dikirim ke Talenta.', 'http' => 200, 'request_id' => $id, 'talenta_sent' => false, 'hrms' => $hrms, 'parsed' => $event, 'database' => ['table' => 'tbl_rawdata', 'FcCardNo' => '2120', 'FdDate' => substr($event['event_datetime'], 0, 10), 'FcTime' => substr($event['event_datetime'], 11, 8), 'FcTermNo' => $event['terminal_id'], 'FcDirection' => $direction, 'FlFlag' => 0]], JSON_THROW_ON_ERROR);
        exit;
    }
    $transport = $_SERVER;
    $transport['REQUEST_METHOD'] = 'GET';
    $transport['CONTENT_LENGTH'] = 0;
    $transport['QUERY_STRING'] = http_build_query($input);
    ReceiverHttp::validateTransport($transport, $input);
    $id = bin2hex(random_bytes(12));
    $repo = new AttendanceRepository(null, 'worker');
    $result = (new MachineReceiverService($repo, new MachineRequestParser(), new ReceiverAudit()))->receive($input, $_SERVER['REMOTE_ADDR'], $id);
    $result['request_id'] = $id;
    $result['talenta_sent'] = false;
    if ($result['http'] === 200) {
        try {
            $result['queue'] = TrialSimulation::prepare($repo, $event);
        } catch (Throwable $_ignored) {
            $result['queue'] = ['status' => 'waiting_validation', 'message' => 'Data tersimpan. Validasi/antrean belum siap; periksa Data Absensi. Tidak ada request Talenta.'];
        }
    }
    http_response_code($result['http']);
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof DomainException ? $error->getCode() : ($error instanceof InvalidArgumentException ? 422 : 503));
    echo json_encode(['status' => 'rejected', 'talenta_sent' => false, 'message' => $error instanceof DomainException || $error instanceof InvalidArgumentException ? $error->getMessage() : 'Penyimpanan simulasi tidak tersedia.'], JSON_THROW_ON_ERROR);
}
