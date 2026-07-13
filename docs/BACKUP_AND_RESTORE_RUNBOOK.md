# Production backup and restore-rehearsal gate

Status: **PREPARED — NOT EXECUTED**. No production backup has been created. Production deployment remains **BLOCKED** until a matched database/uploads backup is restored and verified in isolation.

## Required scope

The recovery set is one timestamped unit containing:

1. Full CLMS database: schema, data, views, triggers, routines, and events.
2. Complete deployed uploads directory, including item/receipt/design/payment-document images and generated user-uploaded documents.
3. A release manifest: application release hash/package checksum, deployed path, PHP/MySQL versions, enabled extensions, migration list, and non-secret configuration key names.
4. Protected configuration backup through the organization’s secret manager. Do not put plaintext `.env`, DB passwords, API keys, or credential files in the ordinary artifact directory.
5. SHA-256 for every artifact and manifest.

Database and uploads must share the same UTC backup ID. Put the application in maintenance/write-free mode or use an infrastructure snapshot that guarantees the DB and filesystem represent the same business point in time.

## Artifact naming

Use UTC and an approved release ID:

```text
clms_<UTC_YYYYMMDDTHHMMSSZ>_<RELEASE_ID>_database.sql
clms_<UTC_YYYYMMDDTHHMMSSZ>_<RELEASE_ID>_uploads.tar.gz
clms_<UTC_YYYYMMDDTHHMMSSZ>_<RELEASE_ID>_release_manifest.json
clms_<UTC_YYYYMMDDTHHMMSSZ>_<RELEASE_ID>_checksums.sha256
```

Store artifacts outside the web root on encrypted, access-controlled storage. Copy them to the approved off-host backup location before declaring the gate satisfied.

## Preconditions

- Written backup authorization and named operator/reviewer.
- Explicit production host/port/database and dedicated backup account.
- Confirmed dump tool matching the server vendor/version (`mysqldump` for MySQL or `mariadb-dump` for MariaDB).
- Credential file with no hidden host/database override.
- Enough local/off-host space for at least twice the expected uncompressed database plus uploads.
- Management-approved `RTO_TARGET_MINUTES` and `RPO_TARGET_MINUTES`.
- Inventory of table engines. If any actively written business table is non-transactional, maintenance mode plus a consistent infrastructure snapshot or an approved global read-lock procedure is mandatory; `--single-transaction` alone is insufficient.

## Pre-deployment backup commands

Prepared PowerShell command; do not run until authorized and placeholders are filled:

```powershell
$ErrorActionPreference = 'Stop'
$DumpTool = '<ABSOLUTE_PATH_TO_MYSQLDUMP_OR_MARIADB_DUMP>'
$MySql = '<ABSOLUTE_PATH_TO_VENDOR_MYSQL_CLIENT>'
$CredentialFile = '<ABSOLUTE_PATH_TO_BACKUP_CREDENTIAL_FILE>'
$ProductionHost = '<PRODUCTION_HOST>'
$ProductionPort = <PORT_INTEGER>
$ProductionDb = '<PRODUCTION_DB>'
$UploadsPath = '<ABSOLUTE_PRODUCTION_UPLOADS_DIRECTORY>'
$BackupDir = '<ABSOLUTE_ENCRYPTED_BACKUP_DIRECTORY>'
$ReleaseId = '<APPROVED_RELEASE_ID>'
$Utc = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$Prefix = "clms_${Utc}_${ReleaseId}"
$DbArtifact = Join-Path $BackupDir "${Prefix}_database.sql"
$UploadsArtifact = Join-Path $BackupDir "${Prefix}_uploads.tar.gz"
$ManifestArtifact = Join-Path $BackupDir "${Prefix}_release_manifest.json"
$ChecksumArtifact = Join-Path $BackupDir "${Prefix}_checksums.sha256"

if (-not (Test-Path -LiteralPath $BackupDir -PathType Container)) { throw 'backup directory missing' }
if (-not (Test-Path -LiteralPath $UploadsPath -PathType Container)) { throw 'uploads directory missing' }
if (Select-String -LiteralPath $CredentialFile -Pattern '^\s*(host|port|database|init-command|local-infile)\s*=' -Quiet) { throw 'credential file may redirect the target' }

# Maintenance/write-free mode must already be independently verified here.
& $DumpTool "--defaults-extra-file=$CredentialFile" `
  "--host=$ProductionHost" "--port=$ProductionPort" "--protocol=TCP" `
  '--ssl-mode=VERIFY_IDENTITY' '--single-transaction' '--quick' `
  '--routines' '--triggers' '--events' '--hex-blob' `
  '--default-character-set=utf8mb4' '--set-gtid-purged=OFF' `
  "--result-file=$DbArtifact" $ProductionDb
if ($LASTEXITCODE -ne 0) { throw 'database dump failed' }

tar.exe -czf $UploadsArtifact -C (Split-Path -Parent $UploadsPath) (Split-Path -Leaf $UploadsPath)
if ($LASTEXITCODE -ne 0) { throw 'uploads archive failed' }

# The manifest is prepared from approved deployment metadata and must not contain secrets.
Copy-Item -LiteralPath '<PREPARED_RELEASE_MANIFEST_JSON>' -Destination $ManifestArtifact

$Artifacts = @($DbArtifact,$UploadsArtifact,$ManifestArtifact)
$Checksums = foreach ($Artifact in $Artifacts) {
  $Hash = (Get-FileHash -LiteralPath $Artifact -Algorithm SHA256).Hash.ToLowerInvariant()
  "$Hash  $([IO.Path]::GetFileName($Artifact))"
}
$Checksums | Set-Content -LiteralPath $ChecksumArtifact -Encoding ascii
```

If the vendor dump tool does not support `--set-gtid-purged`, remove it only after recording the vendor/version and replacement behavior. Do not silently ignore unknown-option errors.

## Immediate integrity checks

```powershell
foreach ($Artifact in @($DbArtifact,$UploadsArtifact,$ManifestArtifact,$ChecksumArtifact)) {
  if (-not (Test-Path -LiteralPath $Artifact -PathType Leaf)) { throw "missing artifact: $Artifact" }
  if ((Get-Item -LiteralPath $Artifact).Length -le 0) { throw "empty artifact: $Artifact" }
}

if (-not (Select-String -LiteralPath $DbArtifact -Pattern '(Dump completed|Dump completed on)' -Quiet)) {
  throw 'dump completion marker missing'
}

tar.exe -tzf $UploadsArtifact 1> (Join-Path $BackupDir "${Prefix}_uploads_file_list.txt")
if ($LASTEXITCODE -ne 0) { throw 'uploads archive listing failed' }

# Recalculate and compare each SHA-256 to the checksum manifest.
$Recorded = Get-Content -LiteralPath $ChecksumArtifact
foreach ($Line in $Recorded) {
  if ($Line -notmatch '^([a-f0-9]{64})  (.+)$') { throw 'invalid checksum manifest line' }
  $Path = Join-Path $BackupDir $Matches[2]
  $Actual = (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
  if (-not [string]::Equals($Actual,$Matches[1],[StringComparison]::Ordinal)) { throw "checksum mismatch: $Path" }
}
```

These checks detect truncation/corruption but do not prove restorability. Only the isolated restore rehearsal satisfies the gate.

## Isolated restore rehearsal

The restore host/database must be isolated, non-routable from production users, and named explicitly. Never restore a dump over production for rehearsal.

```powershell
$RestoreHost = '<ISOLATED_RESTORE_HOST>'
$RestorePort = <RESTORE_PORT_INTEGER>
$RestoreDb = "clms_restore_$Utc"
$RestoreCredentialFile = '<ABSOLUTE_PATH_TO_ISOLATED_RESTORE_ADMIN_CREDENTIAL_FILE>'
$RestoreUploadsRoot = '<ABSOLUTE_ISOLATED_RESTORE_UPLOADS_ROOT>'

if ($RestoreHost -eq $ProductionHost) { throw 'restore host must not be production' }
if ($RestoreDb -eq $ProductionDb) { throw 'restore database name must not be production name' }

& $MySql "--defaults-extra-file=$RestoreCredentialFile" "--host=$RestoreHost" "--port=$RestorePort" `
  '--protocol=TCP' '--ssl-mode=VERIFY_IDENTITY' `
  "--execute=CREATE DATABASE ``$RestoreDb`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if ($LASTEXITCODE -ne 0) { throw 'isolated restore database creation failed' }

$RestoreArgs = @(
  "`"--defaults-extra-file=$RestoreCredentialFile`"",
  "--host=$RestoreHost",
  "--port=$RestorePort",
  "--database=$RestoreDb",
  '--protocol=TCP',
  '--ssl-mode=VERIFY_IDENTITY'
)
$RestoreStdout = Join-Path $BackupDir "${Prefix}_restore_stdout.log"
$RestoreStderr = Join-Path $BackupDir "${Prefix}_restore_stderr.log"
$RestoreProcess = Start-Process -FilePath $MySql -ArgumentList $RestoreArgs -Wait -PassThru -NoNewWindow `
  -RedirectStandardInput $DbArtifact -RedirectStandardOutput $RestoreStdout -RedirectStandardError $RestoreStderr
if ($RestoreProcess.ExitCode -ne 0) { throw 'database restore failed; inspect redacted isolated restore stderr' }

New-Item -ItemType Directory -Path $RestoreUploadsRoot -ErrorAction Stop | Out-Null
tar.exe -xzf $UploadsArtifact -C $RestoreUploadsRoot
if ($LASTEXITCODE -ne 0) { throw 'uploads restore failed' }
```

`CREATE DATABASE` above is an isolated-environment write and must be directed to the verified restore host. The dump must not contain a `USE <PRODUCTION_DB>` or `CREATE DATABASE <PRODUCTION_DB>` statement; the backup command intentionally omits `--databases`.

## Restore verification

Record and compare production-backup evidence versus restored evidence for:

- counts: customers, suppliers, orders, order_items, receipts, receipt items, receipt fees, customer deposits, supplier payments, balance transactions, expenses, notifications, audit log, classifications, costs, and translation provenance tables where present;
- currency totals: customer deposits, supplier paid/invoiced/settlement amounts, balance transactions by party/direction/type, expenses, receipt fees, operational costs;
- orphan checks for order items, receipts/items, fees, deposits/payments, cost rows, classification entity references;
- `_migrations` exact names/timestamps and information-schema fingerprints;
- active versus voided receipt counts and cumulative received quantities;
- customer and supplier balance reconciliation for every currency, plus a reviewed sample of open, paid, overpaid, partially received, voided, canceled, and multi-supplier orders;
- files: archive entry count, restored file count, total bytes, and SHA-256 for a risk-based sample including recent item, receipt, design, QR/payment, and document uploads;
- authenticated isolated UI smoke in English and Chinese, filtered exports, portal token scoping, and permission-denied roles.

Critical financial comparison uses exact DECIMAL strings, never float tolerances. A difference is a failed rehearsal until explained and signed.

## Evidence record

The backup gate requires:

- authorization and operators/reviewers;
- production and restore targets (no secrets);
- UTC start/end and duration for dump, upload archive, transfer, DB restore, file restore, and verification;
- tool versions and command transcript with secrets redacted;
- artifact names, sizes, SHA-256, off-host storage confirmation, retention/expiry;
- table-engine inventory and consistency method;
- before/restore row-count and financial-total comparison;
- orphan/reconciliation output;
- restored-upload file/count/hash evidence;
- smoke-test result;
- measured recovery duration and approved RTO/RPO comparison;
- final signed result: `VERIFIED` or `BLOCKED`.

## Recovery-time expectations

Management must set `RTO_TARGET_MINUTES` and `RPO_TARGET_MINUTES`; this document does not invent them. The rehearsal measures:

```text
Measured recovery time = decision/freeze + artifact retrieval + DB restore
                       + uploads restore + application/config restore
                       + reconciliation/smoke + reopen approval
```

The gate passes only when measured recovery time is within the approved RTO with documented headroom. The backup timestamp and write-free cutoff must be within the approved RPO. A planning estimate without a measured rehearsal is insufficient.

## Rollback triggers

Enter maintenance mode and begin rollback decision immediately for any of:

- backup/checksum/restore rehearsal not verified;
- target or migration-state mismatch;
- migration error or unexpected partial schema;
- authentication/permission bypass or broad lockout;
- changed customer/supplier balances, financial totals, receipt/stock counts, or reconciliation mismatches;
- duplicate receiving operations or active voided stock;
- broken draft/order/receiving workflow or filtered export;
- sustained application errors/performance beyond approved thresholds;
- any unexplained production data mutation.

A translation-provider outage alone is not a rollback trigger if the verified non-blocking queue behavior operates and no critical transaction is blocked.

## Exact rollback order

1. Keep/enter maintenance mode; stop web writes, workers, cron, and integration pushes.
2. Record failure evidence and take a separately authorized failure-state snapshot if safe; do not overwrite the pre-release backup.
3. Revert application files/config to the prior release package first. Migrations 072–077 are designed to be additive and the prior application should ignore them. Migration 077 tables become audit-critical after use and must not be dropped merely to match old code.
4. Verify the prior application against the additive schema. Prefer leaving additive tables/columns in place to a destructive schema rollback.
5. If no business data corruption occurred, reopen only after prior-version smoke/reconciliation succeeds.
6. If data corruption occurred, keep all services stopped, preserve the failed database under an incident name, create a clean production database, and restore the verified database artifact. Never import over a live partially corrupted schema.
7. Restore the matched uploads artifact if files changed or the database was restored to the backup point. Database and files must use the same backup ID.
8. Restore the prior protected configuration/release manifest, clear only approved caches, and restart dependencies in controlled order.
9. Run exact row-count, financial, receipt/inventory, permission, English/Chinese, and export verification before reopening.
10. Reopen traffic only with incident commander, technical owner, and financial owner sign-off; continue enhanced monitoring.

Schema rollback 077→072 is a last resort and requires table-by-table proof that no required throttle, translation, classification, cost-history, receiving-operation, shipment-ledger, or issued-number reservation data will be lost. Once migration 077 is used, ledger and number reservations require forward reconciliation. Migrations 067–071 are not part of the normal release rollback because they already reached production; reconcile them forward unless a demonstrated incompatibility requires a separately approved incident plan.
