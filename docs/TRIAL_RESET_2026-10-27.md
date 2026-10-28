# User-authorized local trial reset — 27 October 2026, 14:54 WIB

Target verified: local mysql mkm_attendance_local only. Production mkm_ssmdb was not connected and Talenta records were not deleted or modified. No import request made.

Backup before clearing: `storage/backups/trial-clear-20261027-145448-e8a39a4a`. Contains SQL dump, four-table snapshot, original .env/APP_KEY, contract and identity mapping, previous ledger and trial logs, SHA-256 manifest. Files flushed/fsynced and byte-compared. Restore was not executed. Keep private; contains encrypted credentials and key backup.

Deleted local trial records from tbl_rawdata/tbl_absen/tbl_log; tbl_config untouched (one credential profile retained). Archived then cleared outbound ledger and receiver/dispatch trial logs. Scheduler remains disabled. Preserved current corrected Attendance MKM profile, credentials, and application .env. Trial generation changed so old preview confirmations cannot apply to new trial state. Existing PHP session files for other XAMPP apps were not removed.

Final DB: exactly tbl_rawdata=0, tbl_absen=0, tbl_log=0, tbl_config=1. Ledger=0. Four-table tests passed; data list shows 0 rows and simulator is accessible. Prior remote import outcomes remain unresolved: clearing LOCAL ledger authorizes a fresh local trial but does not undo previous submissions remotely. Check timestamps/employee carefully before explicit final send.
