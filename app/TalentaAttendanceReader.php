<?php

declare (strict_types=1);
final class TalentaAttendanceReader
{
    public const PATH = '/v2/talenta/v3/attendance/summary-report';
    public static function filters(array $input): array
    {
        $from = $input['from'] ?? date('Y-m-d');
        $to = $input['to'] ?? $from;
        if (!is_string($from) || !is_string($to)) {
            compat_throw(new InvalidArgumentException('Tanggal tidak valid.'));
        }
        AttendanceRepository::dateRange($from, $to);
        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 30) {
            compat_throw(new InvalidArgumentException('Pilih maksimal 31 hari per penarikan.'));
        }
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        $limit = filter_var($input['limit'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($page === false || $limit === false) {
            compat_throw(new InvalidArgumentException('Halaman harus positif; jumlah baris 1 sampai 100.'));
        }
        $users = $input['user_ids'] ?? '';
        $search = $input['search'] ?? '';
        if (!is_string($users) || strlen($users) > 400 || $users !== '' && !preg_match('/\A[1-9][0-9]*(?:,[1-9][0-9]*){0,19}\z/', $users)) {
            compat_throw(new InvalidArgumentException('User ID harus berupa ID Talenta positif, dipisahkan koma bila lebih dari satu (maksimal 20).'));
        }
        if (!is_string($search) || strlen($search) > 100 || preg_match('/[\x00-\x1f\x7f]/', $search) || $search !== '' && mb_strlen($search) < 3) {
            compat_throw(new InvalidArgumentException('Pencarian minimal 3 karakter, maksimal 100 byte.'));
        }
        return ['from' => $from, 'to' => $to, 'page' => $page, 'limit' => $limit, 'user_ids' => $users, 'search' => $search];
    }
    public static function target(array $filters): string
    {
        $f = self::filters($filters);
        $query = ['date' => $f['from'], 'end_date' => $f['to'], 'page' => $f['page'], 'limit' => $f['limit'], 'sort' => 'id_employee', 'order' => 'asc'];
        if ($f['user_ids'] !== '') {
            $query['user_ids'] = $f['user_ids'];
        }
        if ($f['search'] !== '') {
            $query['query'] = $f['search'];
        }
        return self::PATH . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    public static function errorHint(int $http, array $filters): string
    {
        if ($http === 422 && ($filters['user_ids'] ?? '') !== '') {
            return 'Filter Talenta User ID ditolak. User ID internal Talenta berbeda dari nomor karyawan/NIK/kartu. Kosongkan filter lanjutan User ID, lalu isi nomor karyawan atau nama pada Pencarian pegawai. Filter tidak diubah otomatis.';
        }
        return 'Filter laporan ditolak Mekari. Coba rentang satu hari tanpa filter lanjutan User ID; bila tetap gagal, gunakan Request ID untuk pemeriksaan Mekari. Ini halaman rekap absensi, bukan master karyawan.';
    }
    public static function verifyCollection(): void
    {
        $collection = jsonFile(ROOT . '/Talenta Data API V2 - MAG HMAC Backend Integration.postman_collection.json');
        $found = false;
        $walk = function (array $items) use (&$walk, &$found): void {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);
                }
                $request = $item['request'] ?? [];
                $url = $request['url'] ?? '';
                $url = is_array($url) ? $url['raw'] ?? '' : $url;
                if (($request['method'] ?? '') === 'GET' && parse_url($url, PHP_URL_PATH) === self::PATH) {
                    $found = true;
                }
            }
        };
        $walk($collection['item'] ?? []);
        if (!$found) {
            compat_throw(new RuntimeException('Endpoint GET attendance belum terverifikasi pada koleksi Postman.'));
        }
    }
    public static function normalize(array $body, string $company, array $filters): array
    {
        $data = $body['data'] ?? null;
        $rows = is_array($data) ? $data['summary_attendance_report'] ?? null : null;
        if (!is_array($rows) || !array_is_list($rows)) {
            compat_throw(new RuntimeException('Struktur respons attendance tidak sesuai koleksi Postman.'));
        }
        $safe = [];
        $fields = ['user_id', 'employee_id', 'full_name', 'schedule_date', 'shift_name', 'attendance_code', 'schedule_in', 'schedule_out', 'clock_in', 'clock_out', 'late_in', 'early_out', 'actual_working_hour', 'real_working_hour', 'holiday'];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                compat_throw(new RuntimeException('Baris attendance tidak valid.'));
            }
            if (!isset($row['company_id']) || !is_scalar($row['company_id']) || (string) $row['company_id'] !== $company) {
                compat_throw(new RuntimeException('Company ID respons berbeda dari konfigurasi. Periksa akses aplikasi Mekari.'));
            }
            $rawDate = $row['schedule_date'] ?? null;
            $parsed = is_string($rawDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $rawDate) : false;
            if (!$parsed || $parsed->format('Y-m-d') !== $rawDate) {
                compat_throw(new RuntimeException('Tanggal attendance respons tidak valid.'));
            }
            if ($rawDate < $filters['from'] || $rawDate > $filters['to']) {
                compat_throw(new RuntimeException('Respons berisi tanggal di luar filter. Data tidak ditampilkan.'));
            }
            $entry = [];
            foreach ($fields as $field) {
                $entry[$field] = isset($row[$field]) && is_scalar($row[$field]) && $row[$field] !== '' ? $row[$field] : null;
            }
            $safe[] = $entry;
        }
        $pagination = [];
        foreach (['current_page', 'last_page', 'per_page', 'total', 'from', 'to'] as $key) {
            $value = $data['pagination'][$key] ?? null;
            if (is_scalar($value) && ctype_digit((string) $value)) {
                $pagination[$key] = (int) $value;
            }
        }
        return ['rows' => $safe, 'pagination' => $pagination];
    }
    public function fetch(array $input): array
    {
        $filters = self::filters($input);
        self::verifyCollection();
        $id = env('MEKARI_CLIENT_ID');
        $secret = env('MEKARI_CLIENT_SECRET');
        if ($id === '' || $secret === '') {
            compat_throw(new RuntimeException('Client ID dan Client Secret belum siap. Periksa menu Mekari Credentials.'));
        }
        if (preg_match('/[\x00-\x1f\x7f"]/', $id . $secret)) {
            compat_throw(new RuntimeException('Format credential tidak valid.'));
        }
        if (rtrim(env('MEKARI_BASE_URL'), '/') !== 'https://api.mekari.com') {
            compat_throw(new RuntimeException('Host Mekari harus https://api.mekari.com.'));
        }
        if (!extension_loaded('curl')) {
            compat_throw(new RuntimeException('Extension cURL belum aktif.'));
        }
        $target = self::target($filters);
        $date = gmdate('D, d M Y H:i:s') . ' GMT';
        $body = '';
        $tooLarge = false;
        $requestId = '';
        $retryAfter = '';
        $curl = curl_init('https://api.mekari.com' . $target);
        $options = [CURLOPT_HTTPGET => true, CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, CURLOPT_HTTPHEADER => ['Date: ' . $date, 'Authorization: ' . Mekari::signRequest($date, $id, $secret, 'GET', $target), 'Accept: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }, CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$requestId, &$retryAfter): int {
            if (preg_match('/^x-request-id:\s*([a-zA-Z0-9_-]{1,100})\s*$/i', trim($line), $match)) {
                $requestId = $match[1];
            }
            if (preg_match('/^retry-after:\s*(\d{1,6})\s*$/i', trim($line), $match)) {
                $retryAfter = $match[1];
            }
            return strlen($line);
        }];
        $ca = env('MEKARI_CA_BUNDLE', ini_get('curl.cainfo') ?: '');
        if ($ca === '' && PHP_OS_FAMILY === 'Windows' && is_file('C:/xampp/apache/bin/curl-ca-bundle.crt')) {
            $ca = 'C:/xampp/apache/bin/curl-ca-bundle.crt';
        }
        if ($ca !== '') {
            if (!is_file($ca) || !is_readable($ca)) {
                compat_throw(new RuntimeException('CA bundle tidak tersedia. Periksa konfigurasi TLS server.'));
            }
            $options[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($curl, $options);
        $success = curl_exec($curl);
        $info = curl_getinfo($curl);
        $http = (int) $info['http_code'];
        $errno = curl_errno($curl);
        curl_close($curl);
        $audit = ['status' => $http >= 200 && $http < 300 ? 'read' : 'error', 'http' => $http, 'curl_errno' => $errno, 'dns_seconds' => $info['namelookup_time'], 'connect_seconds' => $info['connect_time'], 'total_seconds' => $info['total_time'], 'from' => $filters['from'], 'to' => $filters['to'], 'page' => $filters['page'], 'limit' => $filters['limit'], 'response_sha256' => hash('sha256', $body), 'request_id' => $requestId];
        try {
            (new ReceiverAudit(ROOT . '/storage/talenta-reads.jsonl'))->append($audit);
        } catch (Throwable $_ignored) {
        }
        if ($success === false) {
            if ($tooLarge) {
                compat_throw(new RuntimeException('Respons melebihi 4 MB. Kurangi jumlah baris atau rentang tanggal.'));
            }
            if ($errno === 60) {
                compat_throw(new RuntimeException('Sertifikat TLS Mekari belum dipercaya. Konfigurasikan MEKARI_CA_BUNDLE; verifikasi TLS tetap aktif.'));
            }
            if ($errno === 28) {
                compat_throw(new RuntimeException('Timeout menghubungi API Mekari. Belum ada respons HTTP; credential belum dapat diverifikasi. Periksa jaringan/proxy atau coba kembali.'));
            }
            compat_throw(new RuntimeException('Koneksi GET Mekari gagal (cURL ' . $errno . '). Periksa jaringan/proxy server.'));
        }
        if ($http < 200 || $http >= 300) {
            $hint = compat_match($http, [[[401], fn() => 'Periksa Client ID/Secret, izin HMAC dan waktu server (GMT).'], [[403], fn() => 'Periksa scope talenta:attendance, akses company dan hak aplikasi.'], [[429], fn() => 'Batas request tercapai.' . ($retryAfter !== '' ? ' Coba setelah ' . $retryAfter . ' detik.' : '')], [[404], fn() => 'Endpoint/fitur attendance belum tersedia untuk aplikasi ini.'], [[422], fn() => self::errorHint($http, $filters)], [null, fn() => 'Permintaan ditolak atau layanan belum tersedia. Periksa tanggal, filter dan izin aplikasi.']]);
            compat_throw(new RuntimeException('Mekari HTTP ' . $http . '. ' . $hint . ($requestId !== '' ? ' Request ID: ' . $requestId : '')));
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $_ignored) {
            compat_throw(new RuntimeException('Mekari mengembalikan respons non-JSON.'));
        }
        if (!is_array($decoded)) {
            compat_throw(new RuntimeException('Respons Mekari tidak valid.'));
        }
        return self::normalize($decoded, env('MEKARI_COMPANY_ID'), $filters) + ['filters' => $filters, 'http' => $http, 'fetched_at' => date('c'), 'request_id' => $requestId];
    }
}
