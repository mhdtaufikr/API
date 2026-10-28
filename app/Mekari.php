<?php

declare (strict_types=1);
final class Mekari
{
    public const PATH = '/v2/talenta/v2/attendance/import-fingerprint';
    private const PACKAGED_CONTRACT_SHA256 = '3a547c6fb4acf32decc07032ace6d999db379b95f0bd40f9cfd981ccb6677239';
    public static function authorization(string $date, string $id, string $secret): string
    {
        return self::signRequest($date, $id, $secret, 'POST', self::PATH);
    }
    public static function signRequest(string $date, string $id, string $secret, string $method, string $target): string
    {
        $signature = base64_encode(hash_hmac('sha256', "date: {$date}\n" . $method . ' ' . $target . ' HTTP/1.1', $secret, true));
        return 'hmac username="' . $id . '", algorithm="hmac-sha256", headers="date request-line", signature="' . $signature . '"';
    }
    public static function contract(): array
    {
        return self::loadContract(
            ROOT . '/config/contract.json',
            ROOT . '/storage/postman.json',
            ROOT . '/config/talenta-attendance-contract.json'
        );
    }
    private static function loadContract(string $runtimeContract, string $collection, string $packaged): array
    {
        if (is_file($runtimeContract) || is_file($collection)) {
            if (!is_file($runtimeContract) || !is_file($collection)) {
                compat_throw(new RuntimeException('Runtime contract and Postman evidence must be deployed together.'));
            }
            $contract = jsonFile($runtimeContract);
            if (!hash_equals($contract['collection_sha256'] ?? '', hash_file('sha256', $collection))) {
                compat_throw(new RuntimeException('Postman evidence is missing or changed.'));
            }
        } else {
            if (!is_file($packaged)) {
                compat_throw(new RuntimeException('Packaged Attendance MKM contract is missing or changed.'));
            }
            $contract = jsonFile($packaged);
            $fingerprint = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            if (!hash_equals(self::PACKAGED_CONTRACT_SHA256, $fingerprint)) {
                compat_throw(new RuntimeException('Packaged Attendance MKM contract is missing or changed.'));
            }
        }
        if (($contract['method'] ?? '') !== 'POST' || ($contract['path'] ?? '') !== self::PATH || ($contract['mode'] ?? '') !== 'formdata') {
            compat_throw(new RuntimeException('Only verified multipart fingerprint imports are supported.'));
        }
        if (empty($contract['csv_columns']) || empty($contract['fields'])) {
            compat_throw(new RuntimeException('Complete the verified CSV and multipart contract.'));
        }
        return $contract;
    }
    public static function ready(bool $reviewedLegacy = false): bool
    {
        if (LegacyAttendanceRepository::active() && !($reviewedLegacy && env('INTEGRATION_STORAGE') === 'legacy_app' && enabled('TALENTA_LEGACY_RAW_CONFIRMED'))) {
            return false;
        }
        try {
            self::contract();
        } catch (Throwable $_ignored) {
            return false;
        }
        return PHP_VERSION_ID >= 70400 && extension_loaded('curl') && enabled('TALENTA_CSV_PROFILE_CONFIRMED') && strtolower(env('DRY_RUN')) === 'false' && enabled('ALLOW_PRODUCTION_WRITE') && ($reviewedLegacy||env('ATTENDANCE_TEST_NIK') !== '') && env('TALENTA_USER_ID') !== '' && env('MEKARI_CLIENT_ID') !== '' && env('MEKARI_CLIENT_SECRET') !== '' && env('MEKARI_IMPORT_TOKEN') !== '' && env('MEKARI_BASE_URL') === 'https://api.mekari.com';
    }
    public static function importCsv(array $events, array $contract): string
    {
        $f = fopen('php://temp', 'r+');
        compat_csv_row($f, array_keys($contract['csv_columns']));
        $seen = [];
        foreach ($events as $event) {
            $time = new DateTimeImmutable($event['timestamp']);
            $row = [];
            foreach ($contract['csv_columns'] as $rule) {
                $row[] = compat_match($rule['source'] ?? '', [[['nik'], fn() => $event['nik']], [['user_id'], fn() => env('TALENTA_USER_ID')], [['badge'], fn() => $event['badge_number'] ?? compat_throw(new RuntimeException('Missing explicit fingerprint badge mapping.'))], [['date'], fn() => $time->format($rule['format'] ?? 'Y-m-d')], [['time'], fn() => self::checktime($time, $rule['format'] ?? 'H:i:s')], [['timestamp'], fn() => $event['timestamp']], [['checktime'], fn() => self::checktime($time, $rule['format'] ?? 'd/m/Y H:i')], [['direction'], fn() => $rule['values'][$event['direction']] ?? compat_throw(new RuntimeException('Missing direction contract.'))], [['raw_direction'], fn() => $event['raw_direction'] ?? compat_throw(new RuntimeException('Missing raw legacy direction.'))], [['terminal'], fn() => $event['terminal_id'] ?? compat_throw(new RuntimeException('Missing terminal.'))], [['flag'], fn() => (string) ($event['source_flag'] ?? compat_throw(new RuntimeException('Missing original source flag.')))], [['literal'], fn() => (string) ($rule['value'] ?? '')], [null, fn() => compat_throw(new RuntimeException('Unsupported CSV mapping.'))]]);
            }
            $key = json_encode($row, JSON_THROW_ON_ERROR);
            if (isset($seen[$key])) {
                compat_throw(new RuntimeException('Events collapse to identical CSV rows; verify timestamp precision and profile.'));
            }
            $seen[$key] = true;
            compat_csv_row($f, $row);
        }
        rewind($f);
        $csv = stream_get_contents($f);
        fclose($f);
        if (strlen($csv) > 5 * 1024 * 1024) {
            compat_throw(new RuntimeException('CSV exceeds the Postman 5 MB limit.'));
        }
        return $csv;
    }
    private static function checktime(DateTimeImmutable $time, string $format): string
    {
        if (!str_contains($format, 's') && $time->format('s.u') !== '00.000000') {
            compat_throw(new RuntimeException('CSV profile would discard timestamp precision. Confirm a seconds-capable Talenta profile.'));
        }
        return $time->format($format);
    }
    public static function logs(): array
    {
        $f = fopen(ROOT . '/storage/ledger.lock', 'c+');
        if (!$f || !flock($f, LOCK_SH)) {
            compat_throw(new RuntimeException('Cannot read integration ledger.'));
        }
        try {
            return is_file(ROOT . '/storage/ledger.json') ? jsonFile(ROOT . '/storage/ledger.json') : [];
        } finally {
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
    private static function writeLedger($f, array $ledger): void
    {
        $json = json_encode($ledger, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $temp = ROOT . '/storage/ledger.' . bin2hex(random_bytes(8)) . '.tmp';
        $out = fopen($temp, 'xb');
        if (!$out) {
            compat_throw(new RuntimeException('Cannot create integration ledger.'));
        }
        try {
            if (fwrite($out, $json) !== strlen($json) || !fflush($out) || function_exists('fsync') && !fsync($out)) {
                compat_throw(new RuntimeException('Cannot persist integration ledger.'));
            }
        } finally {
            fclose($out);
        }
        if (!rename($temp, ROOT . '/storage/ledger.json')) {
            compat_throw(new RuntimeException('Cannot commit integration ledger.'));
        }
    }
    public static function eventKey(array $event): string
    {
        return hash('sha256', env('MEKARI_COMPANY_ID') . '|' . ($event['employee_user_id']??env('TALENTA_USER_ID')) . '|' . (new DateTimeImmutable($event['timestamp']))->format('U.u'));
    }
    public static function responseReference(string $body): array
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            return [];
        }
        $safe = [];
        foreach (['request_id', 'task_id', 'job_id', 'import_id'] as $key) {
            $value = $json[$key] ?? (is_array($json['data'] ?? null) ? $json['data'][$key] ?? null : null);
            if (is_scalar($value) && preg_match('/\A[a-zA-Z0-9_-]{1,120}\z/', (string) $value)) {
                $safe[$key] = (string) $value;
            }
        }
        return $safe;
    }
    public static function multipartCsv(string $csv, ?string &$temporary)
    {
        if (class_exists('CURLStringFile')) return new CURLStringFile($csv, 'attendance.csv', 'text/csv');
        $temporary = tempnam(ROOT.'/storage', 'csv-upload-');
        if ($temporary === false || file_put_contents($temporary, $csv, LOCK_EX) !== strlen($csv)) {
            throw new RuntimeException('Cannot prepare private CSV upload.');
        }
        return new CURLFile($temporary, 'text/csv', 'attendance.csv');
    }
    public static function submit(array $events, bool $reviewedLegacy = false): array
    {
        if (!self::ready($reviewedLegacy) || !$events) {
            compat_throw(new RuntimeException('API Trial is locked or has no valid events.'));
        }
        foreach ($events as $event) {
            if($reviewedLegacy){
                if(($event['identity_verified']??false)!==true||($event['identity_company']??'')!==env('MEKARI_COMPANY_ID')||empty($event['employee_user_id'])||empty($event['badge_number']))throw new RuntimeException('Identitas tujuan karyawan belum diverifikasi.');
            }elseif ($event['nik'] !== env('ATTENDANCE_TEST_NIK')) {
                compat_throw(new RuntimeException('Single NIK restriction failed.'));
            }
        }
        $contract = self::contract();
        $csv = self::importCsv($events, $contract);
        $keys = array_map([self::class, 'eventKey'], $events);
        $f = fopen(ROOT . '/storage/ledger.lock', 'c+');
        if (!$f || !flock($f, LOCK_EX)) {
            compat_throw(new RuntimeException('Cannot lock integration ledger.'));
        }
        $temp = null;
        try {
            $ledger = is_file(ROOT . '/storage/ledger.json') ? jsonFile(ROOT . '/storage/ledger.json') : [];
            foreach ($ledger as $entry) {
                if (array_intersect($keys, $entry['event_keys'])) {
                    compat_throw(new RuntimeException('Previously attempted events blocked. Reconcile with Talenta before any manual retry.'));
                }
            }
            $fields = [];
            foreach ($contract['fields'] as $name => $source) {
                switch ($source) {
                    case 'csv': $fields[$name] = self::multipartCsv($csv, $temp); break;
                    case 'import_token': $fields[$name] = env('MEKARI_IMPORT_TOKEN'); break;
                    case 'company_id': $fields[$name] = env('MEKARI_COMPANY_ID'); break;
                    case 'user_id': $fields[$name] = env('TALENTA_USER_ID'); break;
                    default: throw new RuntimeException('Unsupported multipart mapping.');
                }
            }
            $id = bin2hex(random_bytes(12));
            $entry = ['id' => $id, 'at' => gmdate('c'), 'event_keys' => $keys, 'count' => count($events), 'status' => 'reserved', 'http' => null, 'response' => 'Pending / outcome unknown', 'profile_name' => $contract['attendance_machine_profile']['name'] ?? null, 'csv_sha256' => hash('sha256', $csv), 'contract_sha256' => hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR))];
            $ledger[] = $entry;
            self::writeLedger($f, $ledger);
            $date = gmdate('D, d M Y H:i:s') . ' GMT';
            foreach (['MEKARI_CLIENT_ID', 'MEKARI_CLIENT_SECRET', 'MEKARI_IMPORT_TOKEN'] as $key) {
                if (preg_match('/[\r\n"]/', env($key))) {
                    compat_throw(new RuntimeException('Invalid credential characters.'));
                }
            }
            $curl = curl_init('https://api.mekari.com' . self::PATH);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['Date: ' . $date, 'Authorization: ' . self::authorization($date, env('MEKARI_CLIENT_ID'), env('MEKARI_CLIENT_SECRET')), 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
            $ca = env('MEKARI_CA_BUNDLE', ini_get('curl.cainfo') ?: '');
            if ($ca === '' && PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) {
                $ca = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
            }
            if ($ca !== '') {
                curl_setopt($curl, CURLOPT_CAINFO, $ca);
            }
            $response = curl_exec($curl);
            $code = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            $entry['http'] = $code;
            $entry['reference'] = is_string($response) ? self::responseReference($response) : [];
            $entry['status'] = $response === false ? 'unknown' : ($code >= 200 && $code < 300 ? 'submitted_pending_reconciliation' : 'rejected');
            if (is_string($response)) {
                $decoded = json_decode($response, true);
                if (is_array($decoded) && (($decoded['success'] ?? null) === false || in_array(strtolower((string) ($decoded['status'] ?? '')), ['error', 'failed', 'failure'], true) || !empty($decoded['errors']))) {
                    $entry['status'] = 'rejected';
                }
            }
            // Preserve diagnostics encrypted, never expose arbitrary remote text/credential echoes.
            if ($reviewedLegacy && is_string($response) && strlen($response) <= 4 * 1024 * 1024) {
                try {
                    $entry['response_encrypted'] = (new CredentialCipher(env('APP_KEY')))->encrypt($response, 'MEKARI_IMPORT_RESPONSE');
                } catch (Throwable $_ignored) {
                    $entry['diagnostic_warning'] = 'Encrypted response retention failed; retain reservation and reconcile using request references.';
                }
            }
            $entry['response'] = $response === false ? 'Transport failure; remote outcome unknown.' : 'HTTP ' . $code . '; response SHA256 ' . hash('sha256', $response) . '; reconcile import in Talenta.';
            $ledger[array_key_last($ledger)] = $entry;
            self::writeLedger($f, $ledger);
            return $entry;
        } finally {
            if ($temp !== null && is_file($temp)) unlink($temp);
            flock($f, LOCK_UN);
            fclose($f);
        }
    }
}
