param([string]$BaseUrl = 'http://127.0.0.1:8088')
$ErrorActionPreference = 'Stop'
$testCount = 0
function Assert-Http($Condition, [string]$Label) {
    if (-not $Condition) { throw "FAIL: $Label" }
    $script:testCount++
    Write-Output "PASS: $Label"
}
function Request-Receiver([string]$Query, [string]$Method = 'GET') {
    try {
        $reply = Invoke-WebRequest "$BaseUrl/API/index.php?$Query" -Method $Method -UseBasicParsing -TimeoutSec 15
        return @{ Code = [int]$reply.StatusCode; Body = $reply.Content; Id = $reply.Headers['X-Receiver-Request-Id']; Result = $reply.Headers['X-Receiver-Result'] }
    } catch {
        if (-not $_.Exception.Response) { throw }
        return @{ Code = [int]$_.Exception.Response.StatusCode; Body = ''; Id = $_.Exception.Response.Headers['X-Receiver-Request-Id']; Result = $_.Exception.Response.Headers['X-Receiver-Result'] }
    }
}
$sample = 'cardno=0040002T21202026102509403610&d=2026%2F10%2F25&h=09%3A40&m=004'
$first = Request-Receiver $sample
Assert-Http ($first.Code -eq 200 -and $first.Body -eq 'OK') 'Actual sample GET is acknowledged only after database processing'
$record1 = Invoke-RestMethod "$BaseUrl/receiver-inspector.php?request_id=$($first.Id)"
$duplicate = Request-Receiver $sample
$record2 = Invoke-RestMethod "$BaseUrl/receiver-inspector.php?request_id=$($duplicate.Id)"
Assert-Http ($duplicate.Code -eq 200 -and $duplicate.Result -eq 'duplicate' -and $record1.record.id -eq $record2.record.id) 'Repeated HTTP GET returns the same committed database record'
Assert-Http ($record2.record.raw_message -eq '0040002T21202026102509403610' -and $record2.parsed.event_datetime -eq '2026-10-25T09:40:36+07:00') 'Actual stored raw message and parsed timestamp are visible in local inspector'
$different = Request-Receiver ($sample.Replace('0040002T', '0050002T').Replace('m=004', 'm=005'))
$record3 = Invoke-RestMethod "$BaseUrl/receiver-inspector.php?request_id=$($different.Id)"
Assert-Http ($different.Code -eq 200 -and $record3.record.terminal_id -eq '005') 'Another simulated terminal is identified and stored independently'
$second = Request-Receiver 'cardno=0040002T21202026102517000020&d=2026%2F10%2F25&h=17%3A00&m=004'
Assert-Http ($second.Code -eq 200) 'Second distinct simulated attendance event is stored'
Assert-Http ((Request-Receiver 'cardno=broken&d=2026%2F10%2F25&h=09%3A40&m=004').Code -eq 422) 'Malformed message produces HTTP 422'
Assert-Http ((Request-Receiver ($sample.Replace('m=004','m=999'))).Code -eq 422) 'Inconsistent terminal produces HTTP 422'
Assert-Http ((Request-Receiver $sample 'POST').Code -eq 405) 'POST ingestion remains disabled'
Assert-Http ((Request-Receiver ($sample + '&m=004')).Code -eq 422) 'Duplicate query key is rejected'
Assert-Http ((Request-Receiver ('cardno=' + ('x' * 1100))).Code -eq 413) 'Oversized request is rejected'
$dashboard = Invoke-WebRequest "$BaseUrl/" -UseBasicParsing -TimeoutSec 15
Assert-Http ($dashboard.Content.Contains('Machine transactions') -and $dashboard.Content.Contains('manual_review') -and $dashboard.Content.Contains('Outbound locked')) 'Dashboard displays actual transactions, review holds and locked outbound status'
Write-Output "$testCount HTTP checks passed. Sample events remain only in the local integration database; no Talenta request was sent."
