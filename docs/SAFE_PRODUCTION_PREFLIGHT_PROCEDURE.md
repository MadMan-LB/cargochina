# Safe read-only production preflight procedure

Status: **PREPARED — NOT EXECUTED**. It has not been executed against production and is not authorization to execute it.

## Safety model

The production procedure deliberately does **not** use PHP, the application database bootstrap, the API, a browser session, or `backend/migrations/run.php`. It uses only the vendor `mysql` client, a static reviewed SQL batch, and a database account whose grants prohibit writes.

The safety controls are cumulative:

1. Management supplies the expected host, port, database name, server identity, maintenance window, and authorization text explicitly.
2. A DBA pre-provisions a dedicated account such as `clms_readonly_preflight` with only `SELECT` and `SHOW VIEW` on the one production schema. Account creation/grants are outside this read-only procedure and require separate DBA change control.
3. The client credential file contains only user/password/TLS settings—never host, port, or database—so it cannot redirect the command.
4. The PowerShell session clears application `DB_*`, `APP_ENV`, and translation variables. The target comes only from literal preflight parameters.
5. The declared target is printed without credentials and the operator must type the exact `host:port/database` string.
6. The identity-only connection checks `DATABASE()`, server hostname, port, current account, version, and read-only server flag. A mismatch exits before any business/schema scan query.
7. `SHOW GRANTS` must prove the account lacks `INSERT`, `UPDATE`, `DELETE`, `ALTER`, `CREATE`, `DROP`, `TRUNCATE`, `REPLACE`, `EXECUTE`, `FILE`, `SUPER`, `SYSTEM_USER`, `GRANT OPTION`, and `ALL PRIVILEGES`.
8. The static SQL is rejected locally if it contains a forbidden write/DDL keyword.
9. The scan starts a repeatable-read, consistent-snapshot, read-only transaction. Before and after counts are collected from the same snapshot and must match exactly.
10. Any nonzero client exit, missing expected incident schema, unexpected migration state, privilege concern, target mismatch, or before/after difference stops the process. No automatic repair follows.

Even if an unsafe statement were accidentally introduced, the dedicated account must make it fail. Server `read_only`/`super_read_only` is additional evidence, not a substitute for least-privilege grants.

## Required authorization text

The following exact form must be supplied before execution:

> I authorize one read-only CLMS production preflight against host `<PRODUCTION_HOST>`, port `<PORT>`, database `<PRODUCTION_DB>`, expected server identity `<SERVER_HOSTNAME_OR_APPROVED_CLUSTER_IDENTITIES>`, using dedicated account `<READ_ONLY_ACCOUNT>`, during `<UTC_START–UTC_END>`. No migration runner, application bootstrap, backup, INSERT, UPDATE, DELETE, ALTER, CREATE, DROP, TRUNCATE, REPLACE, or corrective action is authorized. Results may be written only to `<LOCAL_EVIDENCE_DIRECTORY>`.

Authorization must name the approver and timestamp. “Continue” or “check production” is not sufficient.

## Credential/account prerequisites

The DBA records `SHOW GRANTS` evidence when creating the account. The intended grant shape is equivalent to `SELECT, SHOW VIEW` on `<PRODUCTION_DB>.*` plus only the minimum connection usage. The actual account-creation command is intentionally omitted from this read-only runbook because it is a production write.

The credential file `<READ_ONLY_CREDENTIAL_FILE>` must be OS-readable only by the operator and resemble:

```ini
[client]
user=clms_readonly_preflight
password=<SECRET>
ssl-mode=VERIFY_IDENTITY
ssl-ca=<TRUSTED_CA_PATH>
```

It must not contain `host=`, `port=`, `database=`, `init-command=`, `local-infile=`, or plugin directives.

## Exact command envelope for later use

Run only after filling every placeholder and obtaining the authorization above. This block is prepared, not executed.

```powershell
powershell.exe -NoProfile -NonInteractive

$ErrorActionPreference = 'Stop'
$MySql = '<ABSOLUTE_PATH_TO_VENDOR_MYSQL_CLIENT>'
$CredentialFile = '<ABSOLUTE_PATH_TO_READ_ONLY_CREDENTIAL_FILE>'
$EvidenceDir = '<ABSOLUTE_LOCAL_EVIDENCE_DIRECTORY>'
$ExpectedHost = '<PRODUCTION_HOST>'
$ExpectedPort = <PORT_INTEGER>
$ExpectedDb = '<PRODUCTION_DB>'
$ApprovedServerIdentities = @('<SERVER_HOSTNAME_1>')
$ExpectedAccount = '<READ_ONLY_ACCOUNT>'
$ExpectedConfirmation = "$ExpectedHost`:$ExpectedPort/$ExpectedDb"

if (-not (Test-Path -LiteralPath $MySql -PathType Leaf)) { throw 'mysql client missing' }
if (-not (Test-Path -LiteralPath $CredentialFile -PathType Leaf)) { throw 'credential file missing' }
if (-not (Test-Path -LiteralPath $EvidenceDir -PathType Container)) { throw 'evidence directory missing' }
if ($ExpectedHost -match '[<>\s]' -or $ExpectedDb -notmatch '^[A-Za-z0-9_]+$' -or $ExpectedPort -lt 1 -or $ExpectedPort -gt 65535) { throw 'invalid explicit target' }
if (Select-String -LiteralPath $CredentialFile -Pattern '^\s*(host|port|database|init-command|local-infile)\s*=' -Quiet) { throw 'credential file may redirect or initialize the session' }

Get-ChildItem Env: | Where-Object { $_.Name -match '^(DB_|APP_ENV$|TRANSLATION_)' } | ForEach-Object { Remove-Item "Env:$($_.Name)" }

Write-Host "READ-ONLY TARGET: $ExpectedConfirmation"
$Typed = Read-Host 'Type the exact target shown above to continue'
if (-not [string]::Equals($Typed,$ExpectedConfirmation,[StringComparison]::Ordinal)) { throw 'human target confirmation mismatch' }

$CommonArgs = @(
  "--defaults-extra-file=$CredentialFile",
  "--host=$ExpectedHost",
  "--port=$ExpectedPort",
  "--database=$ExpectedDb",
  '--protocol=TCP',
  '--ssl-mode=VERIFY_IDENTITY',
  '--batch',
  '--raw',
  '--skip-column-names'
)

$IdentitySql = "SELECT CONCAT_WS('|',DATABASE(),@@hostname,@@port,CURRENT_USER(),@@version,@@read_only);"
$Identity = & $MySql @CommonArgs "--execute=$IdentitySql"
if ($LASTEXITCODE -ne 0 -or @($Identity).Count -ne 1) { throw 'identity query failed' }
$IdentityParts = ([string]$Identity).Split('|')
if ($IdentityParts.Count -ne 6) { throw 'identity response shape invalid' }
if (-not [string]::Equals($IdentityParts[0],$ExpectedDb,[StringComparison]::Ordinal)) { throw 'database identity mismatch' }
if ($ApprovedServerIdentities -notcontains $IdentityParts[1]) { throw 'server identity mismatch' }
if ([int]$IdentityParts[2] -ne $ExpectedPort) { throw 'server port mismatch' }
if ($IdentityParts[3] -notmatch "^$([regex]::Escape($ExpectedAccount))@") { throw 'database account mismatch' }
Write-Host "SERVER-CONFIRMED TARGET: $($IdentityParts[1]):$($IdentityParts[2])/$($IdentityParts[0]); account=$($IdentityParts[3]); version=$($IdentityParts[4]); server_read_only=$($IdentityParts[5])"

$Grants = & $MySql @CommonArgs '--execute=SHOW GRANTS FOR CURRENT_USER;'
if ($LASTEXITCODE -ne 0) { throw 'SHOW GRANTS failed' }
$GrantText = $Grants -join "`n"
$ForbiddenGrant = '(?i)\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE|EXECUTE|FILE|SUPER|SYSTEM_USER)\b|ALL PRIVILEGES|GRANT OPTION'
if ($GrantText -match $ForbiddenGrant) { throw 'account has forbidden privileges' }
if ($GrantText -notmatch '(?i)\bSELECT\b') { throw 'account lacks SELECT' }

$ScanSql = Get-Content -LiteralPath '<PEER_REVIEWED_SQL_FILE_CREATED_FROM_THE_EXACT_BATCH_BELOW>' -Raw
$ForbiddenSql = '(?i)\b(INSERT|UPDATE|DELETE|ALTER|CREATE|DROP|TRUNCATE|REPLACE|CALL|LOAD|HANDLER|DO)\b|INTO\s+(OUTFILE|DUMPFILE)|LOCK\s+TABLES|UNLOCK\s+TABLES'
if ($ScanSql -match $ForbiddenSql) { throw 'scan SQL contains a forbidden operation' }

$Utc = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
$EvidenceFile = Join-Path $EvidenceDir "clms_readonly_preflight_$Utc.tsv"
$SqlHash = (Get-FileHash -LiteralPath '<PEER_REVIEWED_SQL_FILE_CREATED_FROM_THE_EXACT_BATCH_BELOW>' -Algorithm SHA256).Hash
"target=$ExpectedConfirmation`nserver_identity=$($IdentityParts[1])`naccount=$($IdentityParts[3])`nsql_sha256=$SqlHash`nstarted_utc=$Utc" | Set-Content -LiteralPath "$EvidenceFile.manifest.txt"

$ScanSql | & $MySql @CommonArgs 1> $EvidenceFile
if ($LASTEXITCODE -ne 0) { throw 'scan failed; no repair is authorized' }

# Offline parser compares every BEFORE_COUNT row to its AFTER_COUNT twin and
# fails on any missing/changed key. Human reviewers then inspect the static scan.
Write-Host "READ-ONLY EVIDENCE: $EvidenceFile"
```

Important: `--defaults-extra-file` must remain the first MySQL option. The SQL file is created from the exact batch below and its SHA-256 is peer-reviewed before the window. It is not generated from application code or production data.

## Every SQL statement used

The identity and grant statements are shown in the command envelope. The exact business/schema scan batch is:

```sql
SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ;
START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY;

SELECT 'BEFORE_COUNT','customers',COUNT(*) FROM customers;
SELECT 'BEFORE_COUNT','suppliers',COUNT(*) FROM suppliers;
SELECT 'BEFORE_COUNT','orders',COUNT(*) FROM orders;
SELECT 'BEFORE_COUNT','order_items',COUNT(*) FROM order_items;
SELECT 'BEFORE_COUNT','warehouse_receipts',COUNT(*) FROM warehouse_receipts;
SELECT 'BEFORE_COUNT','warehouse_receipt_items',COUNT(*) FROM warehouse_receipt_items;
SELECT 'BEFORE_COUNT','warehouse_receipt_fees',COUNT(*) FROM warehouse_receipt_fees;
SELECT 'BEFORE_COUNT','customer_deposits',COUNT(*) FROM customer_deposits;
SELECT 'BEFORE_COUNT','supplier_payments',COUNT(*) FROM supplier_payments;
SELECT 'BEFORE_COUNT','balance_transactions',COUNT(*) FROM balance_transactions;
SELECT 'BEFORE_COUNT','expenses',COUNT(*) FROM expenses;
SELECT 'BEFORE_COUNT','audit_log',COUNT(*) FROM audit_log;
SELECT 'BEFORE_COUNT','user_permission_overrides',COUNT(*) FROM user_permission_overrides;
SELECT 'BEFORE_COUNT','receiving_excel_imports',COUNT(*) FROM receiving_excel_imports;
SELECT 'BEFORE_COUNT','customer_visibility_exceptions',COUNT(*) FROM customer_visibility_exceptions;
SELECT 'BEFORE_COUNT','customer_visibility_allowed_creators',COUNT(*) FROM customer_visibility_allowed_creators;
SELECT 'BEFORE_COUNT','shipment_financial_entries',COUNT(*) FROM shipment_financial_entries;
SELECT 'BEFORE_COUNT','item_number_reservations',COUNT(*) FROM item_number_reservations;
SELECT 'BEFORE_COUNT','item_number_references',COUNT(*) FROM item_number_references;

SELECT 'MIGRATION',name,applied_at
FROM _migrations
WHERE name IN (
 '067_permission_overrides_receiving_imports.sql',
 '068_customer_visibility_exceptions.sql',
 '069_order_item_procurement_fields.sql',
 '070_receipt_item_actual_dimensions.sql',
 '071_warehouse_receipt_customer_fees.sql',
 '072_translation_queue_and_provenance.sql',
 '073_item_classification.sql',
 '074_draft_order_costs.sql',
 '075_receiving_idempotency.sql',
 '076_auth_login_throttling.sql',
 '077_approved_shipment_accounting_and_item_reservations.sql'
)
ORDER BY name;

SELECT 'TABLE',TABLE_NAME,ENGINE,TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN (
   'user_permission_overrides','receiving_excel_imports',
   'customer_visibility_exceptions','customer_visibility_allowed_creators',
   'warehouse_receipt_fees','translation_jobs','translation_manual_corrections',
   'bilingual_text_registry','item_types','item_classifications',
   'item_classification_history','draft_order_cost_types','draft_order_costs',
   'draft_order_cost_history','auth_login_attempts','shipment_financial_entries',
   'item_number_reservations','item_number_references'
  )
ORDER BY TABLE_NAME;

SELECT 'COLUMN',TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND (
   (TABLE_NAME='customers' AND COLUMN_NAME='created_by') OR
   (TABLE_NAME='order_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','express_number')) OR
   (TABLE_NAME='order_template_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','what_brand','copy_normal_goods','code','express_number','size')) OR
   (TABLE_NAME='procurement_draft_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','express_number')) OR
   (TABLE_NAME='warehouse_receipt_items' AND COLUMN_NAME IN ('actual_height','actual_width','actual_length')) OR
   (TABLE_NAME='warehouse_receipts' AND COLUMN_NAME='receiving_operation_id') OR
   (TABLE_NAME='orders' AND COLUMN_NAME IN ('creation_idempotency_key','lock_version')) OR
   (TABLE_NAME='draft_order_costs' AND COLUMN_NAME IN ('creation_idempotency_key','lock_version','posting_status','finalized_at','rate_locked_at')) OR
   (TABLE_NAME='warehouse_receipt_fees' AND COLUMN_NAME='posting_status')
  )
ORDER BY TABLE_NAME,ORDINAL_POSITION;

SELECT 'INDEX',TABLE_NAME,INDEX_NAME,NON_UNIQUE,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_in_index
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME='customers' AND INDEX_NAME='idx_customers_created_by')
       OR (TABLE_NAME='warehouse_receipts' AND INDEX_NAME='uq_receiving_operation_id')
       OR (TABLE_NAME='orders' AND INDEX_NAME='uq_orders_creation_idempotency')
       OR (TABLE_NAME='draft_order_costs' AND INDEX_NAME='uq_draft_cost_creation_key')
       OR (TABLE_NAME='shipment_financial_entries' AND INDEX_NAME IN ('uq_shipment_financial_idempotency','uq_shipment_financial_source_generation'))
       OR (TABLE_NAME='item_number_reservations' AND INDEX_NAME='uq_item_number_reservation'))
GROUP BY TABLE_NAME,INDEX_NAME,NON_UNIQUE
ORDER BY TABLE_NAME,INDEX_NAME;

SELECT 'CUSTOMER_ATTRIBUTION',COUNT(*),SUM(created_by IS NULL),SUM(created_by IS NOT NULL)
FROM customers;

SELECT 'CUSTOMER_ATTRIBUTION_MISMATCHES',COUNT(*)
FROM customers c
JOIN (
 SELECT entity_id,MIN(user_id) AS expected_user_id
 FROM audit_log
 WHERE entity_type='customer' AND action='create' AND user_id IS NOT NULL
 GROUP BY entity_id
) a ON a.entity_id=c.id
WHERE c.created_by IS NOT NULL AND c.created_by<>a.expected_user_id;

SELECT 'CUSTOMER_ATTRIBUTION_MULTIPLE_CREATE_USERS',COUNT(*)
FROM (
 SELECT entity_id
 FROM audit_log
 WHERE entity_type='customer' AND action='create' AND user_id IS NOT NULL
 GROUP BY entity_id
 HAVING COUNT(DISTINCT user_id)>1
) ambiguous;

SELECT 'CUSTOMER_ATTRIBUTION_CHRONOLOGICAL_MISMATCHES',COUNT(*)
FROM customers c
JOIN audit_log first_create
 ON first_create.id=(
  SELECT a2.id
  FROM audit_log a2
  WHERE a2.entity_type='customer' AND a2.action='create'
    AND a2.user_id IS NOT NULL AND a2.entity_id=c.id
  ORDER BY a2.created_at,a2.id
  LIMIT 1
 )
WHERE c.created_by IS NOT NULL AND c.created_by<>first_create.user_id;

SELECT 'ACTUAL_DIMENSIONS',COUNT(*),
       SUM(actual_height IS NOT NULL),SUM(actual_width IS NOT NULL),SUM(actual_length IS NOT NULL)
FROM warehouse_receipt_items;

SELECT 'RECEIPT_FEES',COUNT(*),COUNT(DISTINCT receipt_id),COUNT(DISTINCT order_id),
       COALESCE(CAST(SUM(amount) AS CHAR),'0')
FROM warehouse_receipt_fees;

SELECT 'ITEM_BLANKS',COUNT(*)
FROM order_items
WHERE TRIM(COALESCE(item_no,''))='';

SELECT 'ITEM_CUSTOMER_DUPLICATE_GROUPS',COUNT(*)
FROM (
 SELECT o.customer_id,LOWER(TRIM(oi.item_no)) AS normalized_item_no
 FROM order_items oi JOIN orders o ON o.id=oi.order_id
 WHERE TRIM(COALESCE(oi.item_no,''))<>''
 GROUP BY o.customer_id,LOWER(TRIM(oi.item_no))
 HAVING COUNT(*)>1
) duplicate_groups;

SELECT 'ITEM_GLOBAL_DUPLICATE_GROUPS',COUNT(*)
FROM (
 SELECT LOWER(TRIM(item_no)) AS normalized_item_no
 FROM order_items
 WHERE TRIM(COALESCE(item_no,''))<>''
 GROUP BY LOWER(TRIM(item_no))
 HAVING COUNT(*)>1
) duplicate_groups;

SELECT 'SHIPMENT_LEDGER_STATE',posting_state,entry_role,entry_kind,COUNT(*),
       COALESCE(CAST(SUM(base_amount) AS CHAR),'0')
FROM shipment_financial_entries
GROUP BY posting_state,entry_role,entry_kind;

SELECT 'ITEM_RESERVATIONS',COUNT(*),COUNT(DISTINCT customer_id),
       COUNT(DISTINCT normalized_item_no)
FROM item_number_reservations;

SELECT 'ITEM_RESERVATION_ORPHANS',COUNT(*)
FROM item_number_references r
LEFT JOIN item_number_reservations n ON n.id=r.reservation_id
WHERE n.id IS NULL;

SELECT 'ITEM_CONFLICT_BY_STATUS',o.status,COUNT(*)
FROM order_items oi JOIN orders o ON o.id=oi.order_id
WHERE EXISTS (
 SELECT 1 FROM order_items oi2 JOIN orders o2 ON o2.id=oi2.order_id
 WHERE o2.customer_id=o.customer_id AND oi2.id<>oi.id
   AND LOWER(TRIM(oi2.item_no))=LOWER(TRIM(oi.item_no))
)
GROUP BY o.status
ORDER BY o.status;

SELECT 'SHARED_CARTON_ROWS',COUNT(*)
FROM order_items
WHERE COALESCE(shared_carton_enabled,0)=1
  AND shared_carton_contents IS NOT NULL;

SELECT 'FINANCIAL_TOTAL','customer_deposits',currency,COUNT(*),CAST(COALESCE(SUM(amount),0) AS CHAR)
FROM customer_deposits GROUP BY currency;
SELECT 'FINANCIAL_TOTAL','supplier_payments',currency,COUNT(*),CAST(COALESCE(SUM(amount),0) AS CHAR)
FROM supplier_payments GROUP BY currency;
SELECT 'FINANCIAL_TOTAL','balance_transactions',currency,COUNT(*),CAST(COALESCE(SUM(amount),0) AS CHAR)
FROM balance_transactions GROUP BY currency;
SELECT 'FINANCIAL_TOTAL','expenses',currency,COUNT(*),CAST(COALESCE(SUM(amount),0) AS CHAR)
FROM expenses GROUP BY currency;

SELECT 'ORPHAN','order_items_without_order',COUNT(*)
FROM order_items oi LEFT JOIN orders o ON o.id=oi.order_id WHERE o.id IS NULL;
SELECT 'ORPHAN','receipt_items_without_receipt_or_item',COUNT(*)
FROM warehouse_receipt_items wri
LEFT JOIN warehouse_receipts wr ON wr.id=wri.receipt_id
LEFT JOIN order_items oi ON oi.id=wri.order_item_id
WHERE wr.id IS NULL OR oi.id IS NULL;
SELECT 'ORPHAN','receipt_fees_without_receipt_or_order',COUNT(*)
FROM warehouse_receipt_fees f
LEFT JOIN warehouse_receipts wr ON wr.id=f.receipt_id
LEFT JOIN orders o ON o.id=f.order_id
WHERE wr.id IS NULL OR o.id IS NULL;

SELECT 'AFTER_COUNT','customers',COUNT(*) FROM customers;
SELECT 'AFTER_COUNT','suppliers',COUNT(*) FROM suppliers;
SELECT 'AFTER_COUNT','orders',COUNT(*) FROM orders;
SELECT 'AFTER_COUNT','order_items',COUNT(*) FROM order_items;
SELECT 'AFTER_COUNT','warehouse_receipts',COUNT(*) FROM warehouse_receipts;
SELECT 'AFTER_COUNT','warehouse_receipt_items',COUNT(*) FROM warehouse_receipt_items;
SELECT 'AFTER_COUNT','warehouse_receipt_fees',COUNT(*) FROM warehouse_receipt_fees;
SELECT 'AFTER_COUNT','customer_deposits',COUNT(*) FROM customer_deposits;
SELECT 'AFTER_COUNT','supplier_payments',COUNT(*) FROM supplier_payments;
SELECT 'AFTER_COUNT','balance_transactions',COUNT(*) FROM balance_transactions;
SELECT 'AFTER_COUNT','expenses',COUNT(*) FROM expenses;
SELECT 'AFTER_COUNT','audit_log',COUNT(*) FROM audit_log;
SELECT 'AFTER_COUNT','user_permission_overrides',COUNT(*) FROM user_permission_overrides;
SELECT 'AFTER_COUNT','receiving_excel_imports',COUNT(*) FROM receiving_excel_imports;
SELECT 'AFTER_COUNT','customer_visibility_exceptions',COUNT(*) FROM customer_visibility_exceptions;
SELECT 'AFTER_COUNT','customer_visibility_allowed_creators',COUNT(*) FROM customer_visibility_allowed_creators;
SELECT 'AFTER_COUNT','shipment_financial_entries',COUNT(*) FROM shipment_financial_entries;
SELECT 'AFTER_COUNT','item_number_reservations',COUNT(*) FROM item_number_reservations;
SELECT 'AFTER_COUNT','item_number_references',COUNT(*) FROM item_number_references;

ROLLBACK;
```

## Proof the listed statements are read-only

- `SELECT` and `SHOW GRANTS` read data/metadata only.
- `SET SESSION TRANSACTION ISOLATION LEVEL` changes only the client session.
- `START TRANSACTION ... READ ONLY` explicitly prohibits transactional writes and establishes a consistent snapshot.
- `ROLLBACK` ends the read-only transaction; it does not change committed data.
- There is no `PREPARE`, stored routine call, dynamic SQL, application include, migration runner, output-to-server-file statement, table lock, or local infile.
- The account’s grants independently prohibit all data/DDL writes.

If any expected incident table/column is missing, the static batch may fail. That failure is a safety stop and schema-reconciliation result; it is not permission to modify production or run migrations.

## Required evidence

Retain authorization, literal parameters, credential-file permission proof (not its contents), mysql binary version/hash, identity response, redacted grants, SQL SHA-256, UTC start/end, client exit code, raw TSV, offline before/after comparison, reviewer names, and a statement that no application/migration command ran. Do not retain the credential secret.
