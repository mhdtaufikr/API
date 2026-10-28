<?php

declare (strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
try {
    $command = $argv[1] ?? '';
    if(in_array($command,['hrms:preview','hrms:export','hrms:baseline'],true)){
        if($command==='hrms:baseline'&&($argv[2]??'')!=='--confirm=BASELINE_EXISTING_HRMS')throw new RuntimeException('Reviewed baseline requires --confirm=BASELINE_EXISTING_HRMS; existing source rows will be considered already exported.');
        if($command!=='hrms:baseline'&&count($argv)!==2)throw new InvalidArgumentException('HRMS command accepts no arguments.');
        echo json_encode(HrmsSdfExport::run($command==='hrms:preview',$command==='hrms:baseline'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }
    if(in_array($command,['config:install','config:seed','config:check'],true)){
        if(env('INTEGRATION_STORAGE')!=='legacy_app')throw new RuntimeException('Use INTEGRATION_STORAGE=legacy_app for approved tbl_config setup.');
        if(count($argv)!==2)throw new InvalidArgumentException('Config commands accept no extra arguments.');
        if($command==='config:install'){foreach(LegacyConfigMigration::run() as $message)echo $message.PHP_EOL;exit;}
        $service=new IntegrationSettings(Database::open($command==='config:check'?'reader':'settings'),env('APP_KEY'));$existing=$service->row();
        if($command==='config:seed'){
            if(env('APP_KEY')===''&&$existing&&array_filter(array_intersect_key($existing,array_flip(array_values(IntegrationSettings::FIELDS)))))throw new RuntimeException('Restore existing APP_KEY; encrypted credentials were not overwritten.');
            $key=CredentialCipher::ensureKey();$created=(new IntegrationSettings(Database::open('settings'),$key))->seed();echo $created?'Seeded empty tbl_config profile. Enter credentials through settings.':'Profile already exists; values preserved.';echo PHP_EOL;exit;
        }
        if(!$existing)throw new RuntimeException('Run config:seed first.');
        $values=$service->values();echo json_encode(['driver'=>Database::driver(),'database'=>env('DB_DATABASE'),'config_table'=>LegacyConfigMigration::table(),'profile'=>'talenta','decryption'=>'ok','credentials_configured'=>array_map(fn($value)=>$value!=='',$values??[]),'dry_run'=>enabled('DRY_RUN'),'scheduler_enabled'=>enabled('TALENTA_SCHEDULER_ENABLED')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;exit;
    }
    if ($command === 'talenta:scheduler') {
        $options = array_slice($argv, 2);
        if (array_diff($options, ['--preview'])) {
            compat_throw(new InvalidArgumentException('Use talenta:scheduler [--preview].'));
        }
        if (LegacyAttendanceRepository::active() && env('INTEGRATION_STORAGE') !== 'legacy_app') {
            compat_throw(new RuntimeException('Talenta scheduler is unavailable in read-only/local legacy modes.'));
        }
        $scheduler = env('INTEGRATION_STORAGE') === 'legacy_app'
            ? new LegacyTalentaScheduledExport()
            : new TalentaScheduledExport();
        $result = $scheduler->run(in_array('--preview', $options, true));
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($result['errors'] ? 1 : 0);
    }
    if (LegacyAttendanceRepository::active() && !in_array($command, ['inspect', 'legacy:preview'], true)) {
        compat_throw(new RuntimeException('Legacy compatibility mode permits inspect and legacy:preview only. No migration, seed, processing write or Talenta submission.'));
    }
    if ($command === 'legacy:preview') {
        if (count($argv) !== 5) {
            compat_throw(new InvalidArgumentException('Use legacy:preview YYYY-MM-DD YYYY-MM-DD CARD'));
        }
        $repo = new LegacyAttendanceRepository(Database::open('reader'));
        echo json_encode($repo->rows($argv[2], $argv[3], $argv[4]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }
    if ($command === 'talenta:scheduler-approve') {
        if (count($argv) !== 3 || !str_starts_with($argv[2], '--confirm=')) {
            compat_throw(new InvalidArgumentException('Use talenta:scheduler-approve --confirm="SCHEDULED SEND <NIK>" after reviewing the profile.'));
        }
        $profile = TalentaScheduledExport::approve(new AttendanceRepository(null, 'reader'), substr($argv[2], 10));
        echo json_encode($profile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($command === 'migrate') {
        if (LegacyConfigMigration::active()) {
            foreach (LegacyConfigMigration::run() as $message) {
                echo $message . PHP_EOL;
            }
            exit(0);
        }
        try {
            Database::open();
        } catch (PDOException $error) {
            if (Database::driver() !== 'mysql' || ($error->errorInfo[1] ?? null) !== 1049) {
                compat_throw($error);
            }
            require __DIR__ . '/setup-mysql.php';
        }
        foreach (IntegrationMigrations::run(in_array('--separate-schema', $argv, true)) as $message) {
            echo $message . PHP_EOL;
        }
    } elseif (in_array($command, ['seed', 'key:generate'], true)) {
        $pdo = Database::open('settings');
        $service = new IntegrationSettings($pdo, env('APP_KEY'));
        $existing = $service->row();
        if (env('APP_KEY') === '' && $existing && array_filter(array_intersect_key($existing, array_flip(array_values(IntegrationSettings::FIELDS))))) {
            compat_throw(new RuntimeException('Restore the original APP_KEY for existing encrypted credentials. A replacement key was not generated.'));
        }
        $key = CredentialCipher::ensureKey();
        if ($command === 'seed') {
            $created = (new IntegrationSettings($pdo, $key))->seed();
            echo $created ? "Seeded empty Mekari settings. Enter credentials in the settings menu.\n" : "Settings seed already exists; credentials preserved.\n";
            if (!LegacyConfigMigration::active()) {
                $engine = new AttendanceEngineRepository(Database::open());
                echo 'Attendance status masters added: ' . $engine->seedStatuses() . ". Existing status definitions preserved.\n";
            } else {
                echo "tbl_config initialized only. Attendance tables unchanged.\n";
            }
        } else {
            echo "APP_KEY ready in .env; existing keys are never rotated. Keep a secure backup.\n";
        }
    } elseif ($command === 'attendance:process-logs') {
        $options = [];
        foreach (array_slice($argv, 2) as $arg) {
            if ($arg === '--dry-run') {
                if (isset($options['dry-run'])) {
                    compat_throw(new InvalidArgumentException('Repeated dry-run option.'));
                }
                $options['dry-run'] = true;
                continue;
            }
            if (!preg_match('/^--(date|from|to|employee)=(.+)$/', $arg, $match) || isset($options[$match[1]])) {
                compat_throw(new InvalidArgumentException('Use --date=YYYY-MM-DD OR --from=... --to=..., optional --employee=NIK and --dry-run.'));
            }
            $options[$match[1]] = $match[2];
        }
        if (isset($options['date']) && (isset($options['from']) || isset($options['to']))) {
            compat_throw(new InvalidArgumentException('Choose a single date or a date range.'));
        }
        if (isset($options['from']) !== isset($options['to'])) {
            compat_throw(new InvalidArgumentException('Provide both from and to.'));
        }
        $from = $options['date'] ?? $options['from'] ?? date('Y-m-d');
        $to = $options['date'] ?? $options['to'] ?? $from;
        $dry = isset($options['dry-run']);
        $engine = new AttendanceEngineRepository(Database::open($dry ? 'reader' : 'worker'));
        $summary = (new AttendanceProcessingCommand($engine, new AttendanceRepository(null, 'reader'), new AttendanceProcessor()))->run($from, $to, $options['employee'] ?? null, $dry);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        exit($summary['processing_errors'] ? 1 : 0);
    } elseif (in_array($command, ['shift-policy', 'shift-assignment', 'work-calendar', 'attendance-status', 'attendance-request', 'attendance-period-lock', 'attendance-protect'], true)) {
        (new AttendanceEngineRepository(Database::open()))->configure($command, jsonFile($argv[2] ?? ''));
        echo "Attendance configuration saved. Raw machine logs unchanged; no Talenta request.\n";
    } elseif (in_array($command, ['shift', 'terminal', 'employee-map'], true)) {
        if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
            compat_throw(new RuntimeException('Development configuration is restricted to local PostgreSQL.'));
        }
        (new AttendanceRepository(null, 'base'))->configure($command, jsonFile($argv[2] ?? ''));
        echo "Integration configuration saved. No Talenta request made.\n";
    } elseif ($command === 'reconcile-queue') {
        if (!in_array(env('DB_HOST'), ['127.0.0.1', 'localhost', '::1'], true)) {
            compat_throw(new RuntimeException('Development reconciliation requires local PostgreSQL.'));
        }
        $id = filter_var($argv[2] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $outcome = $argv[3] ?? '';
        if (!$id || !in_array($outcome, ['success', 'failed'], true)) {
            compat_throw(new InvalidArgumentException('Use reconcile-queue <id> success|failed <Talenta verification reference>.'));
        }
        (new AttendanceRepository(null, 'worker'))->reconcile($id, $outcome === 'success', $argv[4] ?? '');
        echo "Manual verification recorded. Event reservations remain protected.\n";
    } elseif ($command === 'prepare-queue') {
        $from = $argv[2] ?? date('Y-m-d');
        $to = $argv[3] ?? $from;
        $repo = new AttendanceRepository(null, 'worker');
        $repo->assertInstalled();
        $groups = (new AttendanceProcessor())->process($repo->snapshot($from, $to), $from, $to, env('ATTENDANCE_TEST_NIK'));
        $count = 0;
        foreach ($groups as $group) {
            if ($repo->enqueue($group)) {
                $count++;
            }
        }
        echo 'Created ' . $count . " review entries. No Talenta request made.\n";
    } elseif ($command === 'inspect') {
        $pdo = Database::connect();
        $columns = Database::columns($pdo);
        $pdo->rollBack();
        saveJson(ROOT . '/storage/schema.json', $columns);
        echo json_encode($columns, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    } elseif ($command === 'map') {
        // The operator selects actual identifiers from inspection output; no default table names.
        $map = jsonFile($argv[2] ?? '');
        $pdo = Database::connect();
        $columns = Database::columns($pdo);
        $pdo->rollBack();
        $names = array_column(array_filter($columns, fn($c) => $c['table_schema'] === ($map['schema'] ?? null) && $c['table_name'] === ($map['table'] ?? null)), 'column_name');
        foreach (['nik', 'timestamp', 'direction'] as $key) {
            if (!in_array($map[$key] ?? null, $names, true)) {
                compat_throw(new RuntimeException('Mapping must reference inspected columns.'));
            }
        }
        if (empty($map['directions']) || array_diff(array_values($map['directions']), ['IN', 'OUT'])) {
            compat_throw(new RuntimeException('Provide documented raw device direction values mapped to IN/OUT.'));
        }
        saveJson(ROOT . '/config/source.json', $map);
        echo "Schema-validated source mapping saved.\n";
    } elseif ($command === 'postman') {
        $collection = jsonFile($argv[2] ?? '');
        $matches = [];
        $walk = function (array $items) use (&$walk, &$matches): void {
            foreach ($items as $item) {
                if (isset($item['item'])) {
                    $walk($item['item']);
                }
                $r = $item['request'] ?? [];
                $url = $r['url'] ?? '';
                $url = is_array($url) ? $url['raw'] ?? implode('/', $url['path'] ?? []) : $url;
                if (($r['method'] ?? '') === 'POST' && str_contains($url, ltrim(Mekari::PATH, '/'))) {
                    $matches[] = $r;
                }
            }
        };
        $walk($collection['item'] ?? []);
        if (count($matches) !== 1) {
            compat_throw(new RuntimeException('Expected exactly one matching fingerprint POST request.'));
        }
        saveJson(ROOT . '/storage/postman.json', $collection);
        saveJson(ROOT . '/storage/postman-request.json', $matches[0]);
        $summary = $matches[0];
        foreach ($summary['body']['formdata'] ?? [] as $i => $field) {
            if (isset($field['value'])) {
                $summary['body']['formdata'][$i]['value'] = '[redacted]';
            }
        }
        unset($summary['header'], $summary['auth']);
        echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
        echo "Evidence saved. Review body, CSV specification, permission talenta:attendance and token placement before verifying a contract.\n";
    } elseif ($command === 'verify-contract') {
        $contract = jsonFile($argv[2] ?? '');
        $request = jsonFile(ROOT . '/storage/postman-request.json');
        if (($request['body']['mode'] ?? '') !== 'formdata') {
            compat_throw(new RuntimeException('Collection is not multipart; adapter must be updated to the actual contract.'));
        }
        $actual = [];
        $fileFields = [];
        foreach ($request['body']['formdata'] as $field) {
            if (empty($field['disabled'])) {
                $actual[] = $field['key'];
                if (($field['type'] ?? '') === 'file') {
                    $fileFields[] = $field['key'];
                }
            }
        }
        $mapped = array_keys($contract['fields'] ?? []);
        sort($actual);
        sort($mapped);
        if ($actual !== $mapped) {
            compat_throw(new RuntimeException('Multipart keys differ from Postman.'));
        }
        if (count($fileFields) !== 1 || ($contract['fields'][$fileFields[0]] ?? '') !== 'csv') {
            compat_throw(new RuntimeException('CSV must map to the Postman file field.'));
        }
        foreach ($contract['fields'] as $name => $source) {
            if ($source === 'csv' && !in_array($name, $fileFields, true)) {
                compat_throw(new RuntimeException('CSV mapped to a text field.'));
            }
        }
        if (!in_array('import_token', $contract['fields'], true)) {
            compat_throw(new RuntimeException('Import token placement must be verified; non-body token requires adapter update.'));
        }
        if (empty($contract['csv_columns']) || empty($contract['csv_spec_reference'])) {
            compat_throw(new RuntimeException('Document the official CSV specification and explicit column mappings.'));
        }
        $contract['collection_sha256'] = hash_file('sha256', ROOT . '/storage/postman.json');
        $contract['method'] = 'POST';
        $contract['path'] = Mekari::PATH;
        $contract['mode'] = 'formdata';
        saveJson(ROOT . '/config/contract.json', $contract);
        Mekari::contract();
        echo "Contract saved; API Trial still requires .env gates and per-request browser confirmation.\n";
    } else {
        echo "Commands: inspect | migrate [--separate-schema] | seed | key:generate | shift <file.json> | terminal <file.json> | employee-map <file.json> | prepare-queue <from> <to> | reconcile-queue <id> success|failed <reference> | map <mapping.json> | postman <collection.json> | verify-contract <reviewed-contract.json>\nAttendance: attendance:process-logs --date=YYYY-MM-DD OR --from=... --to=... [--employee=NIK] [--dry-run]\nAttendance configuration: shift-policy | shift-assignment | work-calendar | attendance-status | attendance-request | attendance-period-lock | attendance-protect <file.json>\n";
    }
} catch (Throwable $error) {
    if($error instanceof PDOException){
        $state=(string)$error->getCode();$code=(int)($error->errorInfo[1]??0);
        $hints=[1045=>'Login ditolak: periksa DB_USERNAME/DB_PASSWORD dan akun berdasarkan host.',1049=>'Database tidak ditemukan: periksa DB_DATABASE.',2002=>'Server tidak terjangkau: periksa DB_HOST/DB_PORT/service.',2003=>'Server tidak terjangkau: periksa DB_HOST/DB_PORT/service.',1044=>'Akun tidak memiliki akses ke database ini.',1142=>'Izin operasi tabel ditolak; periksa grants akun.',1146=>'Tabel belum tersedia; inspect koneksi dahulu sebelum config:install.',1054=>'Nama kolom tidak cocok dengan schema sumber.'];
        $message='Database operation failed [SQLSTATE '.preg_replace('/[^A-Z0-9]/i','',$state).', driver '.$code.']. '.($hints[$code]??'Periksa konfigurasi, schema dan izin akun.');
    }else $message=$error->getMessage();
    fwrite(STDERR,'Operation failed: '.$message.PHP_EOL);
    exit(1);
}
