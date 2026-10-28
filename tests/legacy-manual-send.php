<?php

declare (strict_types=1);
putenv('DRY_RUN=true');
// Never enable live dispatch during unit checks.
require __DIR__ . '/../app/bootstrap.php';
$count = 0;
function sendCheck(bool $ok, string $name): void
{
    global $count;
    if (!$ok) {
        compat_throw(new RuntimeException($name));
    }
    $count++;
    echo 'PASS: ' . $name . PHP_EOL;
}
function sendRefuses(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (RuntimeException|InvalidArgumentException $_ignored) {
        sendCheck(true, $name);
        return;
    }
    compat_throw(new LogicException($name));
}
$row = ['FdDate' => '2026-10-27', 'FcTime' => '07:20:00', 'FcCardNo' => '2120', 'FcDirection' => '1', 'FcTermNo' => '004', 'FlFlag' => 0, 'identity_ambiguous' => false, 'value_group_hash' => str_repeat('a', 64)];
$other = $row;
$other['FcTime'] = '08:00:00';
$other['value_group_hash'] = str_repeat('b', 64);
$sent = $row;
$sent['FlFlag'] = 1;
$sent['value_group_hash'] = str_repeat('c', 64);
sendCheck(count(LegacyManualSend::select([$row, $other, $sent], 'all')) === 2, 'All means only pending items on current filter');
sendCheck(LegacyManualSend::select([$row, $other], $row['value_group_hash']) === [$row], 'Individual selection contains exactly matching item');
sendRefuses(fn() => LegacyManualSend::select([$sent], $sent['value_group_hash']), 'Already sent item is refused');
$ambiguous = $row;
$ambiguous['identity_ambiguous'] = true;
sendRefuses(fn() => LegacyManualSend::select([$ambiguous, $other], 'all'), 'One ambiguous item holds entire requested batch');
$wrong = $row;
$wrong['FcCardNo'] = '9999';
sendCheck(count(LegacyManualSend::select([$wrong], 'all'))===1, 'All employee cards may be selected; identity verified before dispatch');
sendRefuses(fn() => LegacyManualSend::select([], 'all'), 'Empty selection cannot submit');
sendRefuses(fn() => LegacyManualSend::select([$row], 'bad'), 'Malformed selection rejected');
$event = ['nik' => 'TEST', 'timestamp' => '2026-10-27T07:20:00+07:00', 'badge_number' => 'EXPLICIT-BADGE', 'direction' => 'UNKNOWN', 'raw_direction' => '1', 'terminal_id' => '004', 'source_flag' => 0];
$csv = Mekari::importCsv([$event], ['csv_columns' => ['Direction' => ['source' => 'raw_direction'], 'Terminal_Number' => ['source' => 'terminal'], 'Flag' => ['source' => 'flag']]]);
sendCheck(str_contains($csv, "1,004,0\r\n"), 'Explicit raw profile preserves code 1, terminal and source flag without IN/OUT inference');
sendRefuses(fn() => Mekari::importCsv([$event], ['csv_columns' => ['Direction' => ['source' => 'direction', 'values' => ['IN' => '1', 'OUT' => '2']]]]), 'Unknown raw direction cannot satisfy an IN/OUT contract');
sendCheck(!Mekari::ready(true), 'Current configuration still blocks live manual sending');
echo $count . " checks passed. No database mutation or Talenta request.\n";
