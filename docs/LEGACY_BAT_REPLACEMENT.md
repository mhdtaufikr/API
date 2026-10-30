# Replace the legacy kirimkeTalenta.bat action without renaming the scheduled entry point

The inspected legacy BAT contains:

```bat
php -f "C:\xampp\htdocs\API\save2csv.php"
```

The new project includes **kirimkeTalenta.bat** at its root. When deployed at the same path already used by Task Scheduler, the existing action can continue pointing to that filename. The BAT now calls `bin/run-talenta.ps1`, which selects PHP 7.4+ and runs the native `talenta:scheduler` command.

```text
Existing Task Scheduler action
 C:\xampp\htdocs\API\kirimkeTalenta.bat
                   ↓
             bin\run-talenta.ps1
                   ↓
   PHP 7.4+ bin\console.php talenta:scheduler
                   ↓
   approved event batches at 08:00 / 12:00 / 18:00 WIB
```

No existing production BAT, scheduler action, protected supplier PHP, receiver or scheduled task was changed during implementation. The old BAT in Downloads was read only. The new BAT was created inside this project. OS task registration/activation remains a deployment action.

## Deployment compatibility

- Keep the same BAT filename and absolute path in the existing Task Scheduler action. Existing 08:00/12:00/18:00 triggers can remain. If the actual task uses another command directly, inspect that action before replacing files; this wrapper does not silently rewrite task configuration.
- Deploy the complete new application and its private configuration/storage beside the BAT. Copying the BAT alone without `bin`, `app`, configuration and migrations is not sufficient.
- The wrapper locates the project relative to its own path, so Task Scheduler's working directory does not have to be changed.
- Runtime selection: an explicit `MKM_TALENTA_PHP_EXE` environment variable, otherwise the verified production candidate `C:\xampp\php\php.exe`, then supported PHP on PATH and `C:\php\php.exe`. PHP below 7.4 is rejected; no existing global PHP installation is upgraded or modified.
- The production XAMPP runtime is preferred because its Mekari network/CA path was verified. `MKM_TALENTA_PHP_EXE` remains the explicit override for a separately validated runtime. Verify the scheduled account's database/network/CA permissions before changing it.
- Existing source data/mapping/profile verification, `TALENTA_SCHEDULER_ENABLED`, signed approval and live-write gates still apply. The wrapper does not enable export or reset any safety flags.
- Overlapping/duplicate invocations are handled by the scheduler and event-level reservations. Import outcomes still require reconciliation; Task Scheduler Last Run Result 0 alone is not proof that Talenta imported attendance.

## Running locally

```bat
kirimkeTalenta.bat
kirimkeTalenta.bat --preview
```

Normal mode follows the scheduler windows. Current configuration is disabled, so a normal test returns status `disabled` and sends nothing. Preview can be run outside the scheduled window, but needs a verified test-employee mapping to list candidates. It never sends attendance.

Output is written to both the task console and `storage/scheduler-console.log`, with timestamps, selected runtime and the native process exit code. The BAT propagates this code to Task Scheduler, contains no credentials, and never prompts or pauses. Credentials remain in the existing encrypted settings/.env mechanism.

The root BAT and PowerShell scripts are blocked from HTTP download. Existing application access rules and the stateless receiver stay in place.

See [TALENTA_SCHEDULER.md](TALENTA_SCHEDULER.md) for profile approval, activation and reconciliation. Test/cut over the new application at the reviewed event boundary before replacing the legacy production implementation; the wrapper itself does not migrate supplier database contents or choose a cutover point.
