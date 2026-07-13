# Migration 067–077 code-only reconciliation

Status: **VERIFIED** as a code/documentation analysis; **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION** for actual production state. No production connection, migration, or rollback was performed.

## Interpretation limits

“Currently deployed application” below means the pre-release application version inferred from the repository history and the incident timeline. The production code version was not inspected because production access is prohibited. Compatibility must therefore be confirmed later by the authorized read-only schema scan and deployment manifest comparison.

Every successfully recorded migration also inserts its filename into `_migrations`. That tracking insert is separate from the migration-specific changes listed below.

## Executive reconciliation

| Migration | Exact change category | Historical data changed? | Safe before matching code? | Risk |
|---|---|---|---|---|
| 067 | Two new tables | No existing business row is updated. | Yes; old code ignores them. | LOW |
| 068 | Nullable customer column, index, FK, two new tables, and customer attribution backfill | **Yes:** `customers.created_by` is populated from the lowest numeric `user_id` among matching customer-create audit rows where currently null. This is not necessarily the earliest audit event. | Structurally yes; behavioral effect begins when visibility-aware code is deployed. | HIGH |
| 069 | 22 nullable procurement columns across three tables | No values are populated. | Yes; old code ignores nullable columns. DDL locking remains a deployment concern. | MEDIUM |
| 070 | Three nullable actual-dimension columns on receipt items | No values are populated or recalculated. | Yes; old code ignores them. | LOW–MEDIUM |
| 071 | One new customer-facing receipt-fee table | No existing business row is updated. | Yes; old code ignores it. | LOW |
| 072 | Three new translation queue/provenance tables | No existing description is changed. | Yes; old code ignores them. | LOW |
| 073 | Three classification tables, taxonomy seed, and legacy classification mapping into the new table | Existing `order_items` are unchanged; derived rows are inserted into `item_classifications`. | Yes for old code; new code will use the derived classifications and review queue. | MEDIUM |
| 074 | Three operational-cost tables and cost-type seed | No existing order or financial row is changed. | Yes; old code ignores them. | LOW–MEDIUM |
| 075 | Nullable receipt idempotency key plus unique index | Existing receipt values remain null. | Yes; old inserts omit the nullable key. | MEDIUM |
| 076 | One hashed login-throttle table | No existing user/session row is changed. | Yes; old auth ignores it. | LOW |
| 077 | Shipment ledger, canonical item-number reservation/reference tables, and guarded posting/idempotency columns/indexes | No historical financial row, item number, order amount, inventory value, or supplier balance is rewritten or backfilled. | Structurally additive; matching Checkpoint 10 code is required before use. | MEDIUM–HIGH |

## 067 — permission overrides and receiving import history

- **Exact changes:** creates `user_permission_overrides` with unique `(user_id,permission_key)`, user/grantor foreign keys and indexes; creates `receiving_excel_imports` with unique `preview_token`, file hash/status/counts, preview/result JSON, actor timestamps, indexes, and user foreign keys.
- **Nature:** additive. It is behavior-enabling only when matching code reads/writes the tables.
- **Historical records:** none changed.
- **Application dependencies:** `includes/permission_overrides.php`; SuperAdmin permission/visibility administration in `backend/api/handlers/users.php`; receiving preview/commit history in `backend/api/handlers/receiving.php`; training reset cleanup.
- **Compatibility:** the inferred old application does not reference these tables, so their presence is compatible. Table creation could briefly contend on metadata locks.
- **Rollback feasibility:** technically easy to drop, but destructive if overrides or import audit/history rows now exist.
- **Forward-fix:** retain the tables; verify constraints and row counts, then deploy matching authorization/import code. Do not roll back merely because the application has not yet been deployed.
- **Verification query:**

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = '<PRODUCTION_DB>'
  AND TABLE_NAME IN ('user_permission_overrides','receiving_excel_imports')
ORDER BY TABLE_NAME;
```

- **Risk level:** LOW before use; MEDIUM after data is stored because rollback loses permission/import history.

## 068 — customer attribution and visibility exceptions

- **Exact schema changes:** adds nullable `customers.created_by INT UNSIGNED`; adds `idx_customers_created_by`; adds FK `fk_customers_created_by` to `users(id)` with `ON DELETE SET NULL`; creates `customer_visibility_exceptions`; creates `customer_visibility_allowed_creators` with user/creator foreign keys.
- **Exact data change:** for customers where `created_by IS NULL`, sets it to `MIN(audit_log.user_id)` for `audit_log` rows with the same customer entity ID, `entity_type='customer'`, `action='create'`, and non-null user. `MIN(user_id)` means the lowest numeric user ID, not the chronologically earliest create audit row.
- **Nature:** additive schema plus behavior-relevant historical metadata backfill.
- **Historical records:** customer attribution metadata changed; names, addresses, orders, balances, and financial values did not.
- **Application dependencies:** `includes/customer_visibility.php`; customer list/search/detail/export scoping in `backend/api/handlers/customers.php`; visibility administration in `backend/api/handlers/users.php`; any caller using customer visibility clauses.
- **Compatibility:** old code can continue inserting customers because the column is nullable. With the new code, attribution controls management visibility; unattributed rows are intentionally not management-visible to restricted users. If more than one user has a matching create audit, choosing the lowest user ID can differ from the earliest event and assign visibility to the wrong creator. The later read-only reconciliation must report multi-user histories and compare stored attribution to chronological audit evidence; matching the migration's `MIN(user_id)` alone proves execution consistency, not business correctness.
- **Rollback feasibility:** dropping the tables is feasible only if no visibility exceptions matter. Dropping `created_by` destroys the recovered attribution. Reversing the UPDATE cannot reconstruct which values were null before migration without a pre-migration snapshot.
- **Forward-fix:** retain schema and attribution; identify anomalies with read-only comparison queries; correct only through a separately approved, audited data plan. Do not destructive-rollback.
- **Verification queries:**

```sql
SELECT COUNT(*) AS customers_total,
       SUM(created_by IS NULL) AS unattributed,
       SUM(created_by IS NOT NULL) AS attributed
FROM customers;

SELECT COUNT(*) AS attribution_mismatches
FROM customers c
JOIN (
  SELECT entity_id, MIN(user_id) AS expected_user_id
  FROM audit_log
  WHERE entity_type='customer' AND action='create' AND user_id IS NOT NULL
  GROUP BY entity_id
) a ON a.entity_id=c.id
WHERE c.created_by IS NOT NULL AND c.created_by<>a.expected_user_id;

SELECT COUNT(*) AS customers_with_multiple_create_users
FROM (
  SELECT entity_id
  FROM audit_log
  WHERE entity_type='customer' AND action='create' AND user_id IS NOT NULL
  GROUP BY entity_id
  HAVING COUNT(DISTINCT user_id)>1
) ambiguous;

SELECT COUNT(*) AS chronological_attribution_mismatches
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
```

- **Risk level:** HIGH because this is the only incident migration that updates existing business-table rows and its value later drives authorization behavior.

## 069 — optional procurement commercial fields

- **Exact changes:**
  - `order_items`: adds nullable `materials TEXT`, `height DECIMAL(12,4)`, `width DECIMAL(12,4)`, `length DECIMAL(12,4)`, `brand VARCHAR(150)`, `express_number VARCHAR(150)`.
  - `order_template_items`: adds nullable `materials`, `height`, `width`, `length`, `brand`, `what_brand VARCHAR(150)`, `copy_normal_goods VARCHAR(60)`, `code VARCHAR(100)`, `express_number`, `size VARCHAR(150)`.
  - `procurement_draft_items`: adds nullable `materials`, `height`, `width`, `length`, `brand`, `express_number`.
  - Every ALTER is guarded by table/column existence checks.
- **Nature:** additive; behavior-enabling for procurement capture/import/export/templates.
- **Historical records:** no backfill; existing rows receive null/default storage semantics only.
- **Application dependencies:** draft/order/procurement/template handlers, receiving import, and order Excel exports that read/write materials, dimensions, brands, codes, sizes, and express numbers.
- **Compatibility:** old queries using explicit columns remain compatible. `SELECT *` gains fields but associative consumers normally ignore them. Repeated ALTER statements could lock large tables while executing.
- **Rollback feasibility:** dropping still-null columns is feasible; after deployment it would destroy newly captured procurement metadata and break matching code.
- **Forward-fix:** keep the nullable fields, verify types, and deploy matching code. Never drop after users begin capturing values unless data is exported and code is first made backward compatible.
- **Verification query:**

```sql
SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='<PRODUCTION_DB>'
  AND (
    (TABLE_NAME='order_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','express_number')) OR
    (TABLE_NAME='order_template_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','what_brand','copy_normal_goods','code','express_number','size')) OR
    (TABLE_NAME='procurement_draft_items' AND COLUMN_NAME IN ('materials','height','width','length','brand','express_number'))
  )
ORDER BY TABLE_NAME,ORDINAL_POSITION;
```

- **Risk level:** MEDIUM because of production DDL/metadata locking and later data-loss risk, not because existing values were altered.

## 070 — actual receipt-item dimensions (incident focus)

- **Precisely what reached production:** three guarded ALTER operations added nullable `actual_height`, `actual_width`, and `actual_length`, each `DECIMAL(12,4)`, to `warehouse_receipt_items` if that table existed and the column did not.
- **What did not happen:** no INSERT, UPDATE, backfill, dimension derivation, receipt recalculation, stock update, variance change, balance posting, fee posting, or financial rewrite. Existing receipt-item rows retained all prior values and received null in the new columns.
- **Nature:** additive schema only.
- **Historical records:** no business value changed.
- **Application dependencies:** `OrderReceivingService` and `ReceivingExcelImportService` persist actual dimensions; receiving/receipt/warehouse UI displays them; `warehouse-stock.php` uses them for received-item detail; `OrderExcelService` exports them.
- **Compatibility:** the inferred old application ignores the nullable columns and continues to insert receipt items. Before matching code, the only operational effects are possible metadata/table locking during ALTER, a small row-size/schema increase, and schema drift between production and other environments. After matching code, dimensions may be populated and used in displays/derived physical detail.
- **Rollback feasibility:** dropping all three columns is technically simple only while every value is null and no code expects them. Once actual values are captured, rollback irreversibly loses warehouse evidence and breaks matching code.
- **Forward-fix:** keep the columns; verify their exact types/nullability and non-null counts; deploy matching code after normal backup/rehearsal. Do not reverse them solely to make migration history look sequential.
- **Verification queries:**

```sql
SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='<PRODUCTION_DB>'
  AND TABLE_NAME='warehouse_receipt_items'
  AND COLUMN_NAME IN ('actual_height','actual_width','actual_length')
ORDER BY COLUMN_NAME;

SELECT COUNT(*) AS receipt_items,
       SUM(actual_height IS NOT NULL) AS with_actual_height,
       SUM(actual_width IS NOT NULL) AS with_actual_width,
       SUM(actual_length IS NOT NULL) AS with_actual_length
FROM warehouse_receipt_items;
```

- **Risk level:** LOW for stored historical data; MEDIUM for the already-past DDL locking event and any future destructive rollback.

## 071 — customer-facing receipt fees

- **Exact changes:** creates `warehouse_receipt_fees` with receipt/order/user foreign keys, `fee_label`, `DECIMAL(12,4) amount`, currency, notes, timestamps, and receipt/order indexes.
- **Nature:** additive and behavior-enabling.
- **Historical records:** none changed; no fee rows are backfilled.
- **Application dependencies:** receiving handler/service stores fees; order exports read them; financial reconciliation reports them as unposted; release preflight counts them.
- **Compatibility:** old application ignores the table. Matching code requires it when fees are supplied.
- **Rollback feasibility:** dropping an empty table is feasible; after use it destroys fee evidence and can make previously issued exports unreproducible.
- **Forward-fix:** retain it. Checkpoint 10 management rules A4/A5 now post new receipt fees through migration 077's paired finalized customer-charge/shipment-expense ledger entries. Existing fee history remains unchanged.
- **Verification query:**

```sql
SELECT COUNT(*) AS fee_rows,
       COUNT(DISTINCT receipt_id) AS receipts_with_fees,
       COUNT(DISTINCT order_id) AS orders_with_fees
FROM warehouse_receipt_fees;
```

- **Risk level:** LOW before use; MEDIUM after fee data exists.

## 072 — translation queue, manual corrections, and provenance

- **Exact changes:** creates `translation_jobs` with unique source/language direction and retry index; `translation_manual_corrections` with unique source/language direction and optional correcting user; `bilingual_text_registry` with unique entity/field, original and EN/ZH texts/states, source hash, error, and pending/hash indexes.
- **Nature:** additive and behavior-enabling.
- **Historical records:** no existing business text is changed or backfilled.
- **Application dependencies:** `TranslationService`, translation endpoints, and `scripts/backfill_bilingual.php`.
- **Compatibility:** old application ignores all three tables. New service gracefully queues failures and prioritizes manual corrections.
- **Rollback feasibility:** destructive after use because it removes pending jobs, manual corrections, and provenance. Cached legacy `translations` rows alone do not replace that evidence.
- **Forward-fix:** retain tables, configure/verify provider in isolation, and run backfill only after dry-run approval.
- **Verification query:**

```sql
SELECT 'translation_jobs' AS source, status AS category, COUNT(*) AS row_count
FROM translation_jobs GROUP BY status
UNION ALL
SELECT 'manual_corrections','all',COUNT(*) FROM translation_manual_corrections
UNION ALL
SELECT 'bilingual_registry',CONCAT(en_state,'/',zh_state),COUNT(*)
FROM bilingual_text_registry GROUP BY en_state,zh_state;
```

- **Risk level:** LOW before use; HIGH data-governance loss if rolled back after manual corrections exist.

## 073 — structured item classification

- **Exact schema/data changes:** creates and seeds eight stable types (`normal`, `replica`, `cosmetics`, `branded`, `food`, `dangerous`, `other`, `unclassified`); creates entity classification and history tables; inserts derived `order_item` classifications from nonblank legacy `copy_normal_goods`. Legacy `Normal`, `Copy`, and `Dangerous` become confirmed mappings with confidence 1.0000; other nonblank values become unconfirmed `unclassified`. `INSERT IGNORE` preserves any pre-existing classification row.
- **Nature:** additive schema plus derived historical rows in a new table; behavior-changing when new filters/validation use it.
- **Historical records:** `order_items.copy_normal_goods` and item IDs remain unchanged. New classification assertions are created from them.
- **Application dependencies:** `ItemClassificationService`; product/draft/order/receiving/warehouse/financial filters; item-classification endpoint and UI.
- **Compatibility:** old code ignores the tables. New code will expose migrated classifications and require confirmation of uncertain items.
- **Rollback feasibility:** dropping tables restores old-code behavior but destroys manual confirmations and classification history. It does not undo legacy fields because those were never changed.
- **Forward-fix:** retain; read-only count mappings by legacy value/type; review unclassified/unconfirmed rows; never auto-confirm unknown items as normal.
- **Verification query:**

```sql
SELECT entity_type,item_type_code,is_confirmed,COUNT(*) AS row_count
FROM item_classifications
GROUP BY entity_type,item_type_code,is_confirmed
ORDER BY entity_type,item_type_code,is_confirmed;
```

- **Risk level:** MEDIUM because future operational filtering/customs behavior depends on classification correctness.

## 074 — audited draft operational costs

- **Exact changes:** creates/seeds eight cost types; creates `draft_order_costs` with four-decimal amount/base amount, eight-decimal exchange rate, payer/allocation/accounting fields, soft deletion, actor timestamps and FKs; creates append-only-style cost history storage.
- **Nature:** additive and behavior-enabling.
- **Historical records:** none changed; no old order cost is inferred.
- **Application dependencies:** `DraftOrderCostService`, draft/order handlers and exports, financial reconciliation, and preflight.
- **Compatibility:** old code ignores tables. Checkpoint 10 code stores new costs as `shipment_expense_customer_charge`; migration 077 preserves pre-existing rows as `legacy_unposted` and does not reinterpret them.
- **Rollback feasibility:** destructive after cost/history rows exist and would make order exports/audits incomplete.
- **Forward-fix:** retain it. A1–A13 are management-approved and implemented prospectively by migration 077 and the centralized posting service; do not back-post historical informational rows.
- **Verification query:**

```sql
SELECT accounting_treatment,is_deleted,currency,base_currency,COUNT(*) AS rows_count,
       CAST(SUM(amount) AS CHAR) AS amount_total,
       CAST(SUM(base_amount) AS CHAR) AS base_total
FROM draft_order_costs
GROUP BY accounting_treatment,is_deleted,currency,base_currency;
```

- **Risk level:** LOW–MEDIUM for storage; accounting remains blocked by design.

## 075 — receiving idempotency key

- **Exact changes:** adds nullable `warehouse_receipts.receiving_operation_id VARCHAR(64)` and unique index `uq_receiving_operation_id`, each guarded by information-schema checks.
- **Nature:** additive schema and behavior-enabling constraint.
- **Historical records:** existing rows remain null; MySQL permits multiple nulls in a unique index.
- **Application dependencies:** `OrderReceivingService` validates and locks/rechecks this key; concurrent retry regressions; preflight.
- **Compatibility:** old code omits the nullable field and remains compatible. Index creation may lock or scan the receipt table.
- **Rollback feasibility:** dropping the column/index destroys retry identity and allows future duplicate receipts if new code remains deployed.
- **Forward-fix:** retain, verify no duplicate non-null keys, then deploy matching service. Never drop before reverting matching code and confirming no retry relies on stored keys.
- **Verification query:**

```sql
SELECT receiving_operation_id,COUNT(*) AS row_count
FROM warehouse_receipts
WHERE receiving_operation_id IS NOT NULL AND receiving_operation_id<>''
GROUP BY receiving_operation_id
HAVING COUNT(*)>1;
```

- **Risk level:** MEDIUM due index-build locking and critical retry semantics after deployment.

## 076 — authentication login throttling

- **Exact changes:** creates `auth_login_attempts`, keyed only by a SHA-256 identity/IP composite, with failure counts/times, block expiry, and block/last-attempt indexes.
- **Nature:** additive and security-behavior enabling.
- **Historical records:** none changed; no plaintext email or IP is stored.
- **Application dependencies:** shared `AuthenticationService` used by page/API login; production hardening tests; preflight.
- **Compatibility:** old authentication ignores the table. New service detects its presence and uses it for durable throttling.
- **Rollback feasibility:** dropping it loses throttle state but not users/passwords. Removing it while new code remains makes throttling unavailable.
- **Forward-fix:** retain, deploy shared auth, rotate seed credentials, and monitor aggregate throttle counts without exposing hashes.
- **Verification query:**

```sql
SELECT COUNT(*) AS throttle_rows,
       SUM(blocked_until>NOW()) AS currently_blocked,
       MIN(first_attempt_at) AS oldest_first_attempt,
       MAX(last_attempt_at) AS latest_attempt
FROM auth_login_attempts;
```

- **Risk level:** LOW schema risk; MEDIUM security risk if matching code deploys without it.

## 077 — approved shipment accounting and item-number reservations

- **Exact changes:** creates `shipment_financial_entries` with unique retry/source-generation identities and linked reversals; creates canonical `item_number_reservations` and many-reference `item_number_references`; adds guarded order creation-key/lock-version columns and a unique creation-key index; adds guarded draft-cost creation-key, lock-version, posting-state, finalization, and rate-lock fields/indexes; adds receipt-fee posting state.
- **Nature:** additive and prospective. It enables the management-approved A1–A13 and I1–I6 code paths.
- **Historical records:** no existing cost is posted, no receipt fee is back-posted, no supplier balance is changed, no item cost/inventory value is changed, no legacy item number is normalized or renumbered, and no reset/backfill occurs. Existing source rows retain `legacy_unposted` through the explicit defaults used when the columns are added.
- **Application dependencies:** `ShipmentAccountingService`, `ItemNumberReservationService`, draft-cost/order/approval/receiving/cancel/restore handlers, balance/profit/reconciliation queries, read-only item-number forms, exports, and production preflight.
- **Compatibility:** prior code ignores new tables and nullable keys. Checkpoint 10 code requires migration 077 before financial posting or canonical reservation. Because the ledger's foreign keys deliberately restrict physical deletion, application deletion must remain soft/void plus reversal.
- **Rollback feasibility:** only before matching code is activated and only after proving the new tables contain no required posting/reservation evidence. Once numbers are issued, dropping reservations could enable reuse. Once ledger rows exist, dropping them destroys audit history. Prefer forward fixes.
- **Verification query:**

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA='<PRODUCTION_DB>'
  AND TABLE_NAME IN ('shipment_financial_entries','item_number_reservations','item_number_references')
ORDER BY TABLE_NAME;

SELECT INDEX_NAME,NON_UNIQUE
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA='<PRODUCTION_DB>'
  AND ((TABLE_NAME='shipment_financial_entries' AND INDEX_NAME IN
       ('uq_shipment_financial_idempotency','uq_shipment_financial_source_generation'))
    OR (TABLE_NAME='item_number_reservations' AND INDEX_NAME='uq_item_number_reservation'))
GROUP BY INDEX_NAME,NON_UNIQUE;
```

- **Risk level:** MEDIUM–HIGH because ledger and issued-number history become durable audit records after activation, even though the migration itself performs no historical rewrite.

## Risks specifically created by 067–071 already being in production

1. Production schema is ahead of its normal release sequence; environment comparisons can misdiagnose drift unless the incident is documented.
2. Migration 068 has already changed customer attribution metadata using the lowest matching numeric audit `user_id`, not necessarily the earliest create event. A destructive rollback cannot reliably reconstruct pre-migration nulls, and ambiguous/misattributed values could alter future visibility behavior.
3. Migrations 069 and 070 may have caused transient ALTER/metadata locks when executed; that risk is historical, not an ongoing recalculation.
4. Nullable new columns/tables are compatible with the inferred old application, but their presence must not be mistaken for evidence that matching features are deployed or verified.
5. Dropping 070/071 later could lose actual dimensions/fee evidence if any matching or manually deployed code has begun writing them.
6. `_migrations` should be reconciled forward. Removing tracking rows or re-running migrations to “clean up” history is unsafe.

## Safest reconciliation position

Keep 067–071 in place, verify them read-only, document the incident, and deploy forward after backup/restore rehearsal. Use a destructive rollback only for a demonstrated incompatibility and only with proof that affected new tables/columns contain no required data. Migration 068 requires a forward-fix approach even if attribution anomalies are discovered.
