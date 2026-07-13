# Production-critical audit and repair report

Audit date: 2026-07-11 (Asia/Beirut)  
Verification databases: `clms_codex_audit_20260711` and clean rehearsal database `clms_codex_fresh_verify_20260711`  
Production readiness status: **BLOCKED**. Checkpoint 10 records management-approved accounting A1–A13 and item-number I1–I6 rules and implements them prospectively in isolated databases. No separate accountant signoff is claimed. Authorized production preflight, historical conflict verification, backup/restore, live translation-provider verification, migration 072–077 deployment, smoke/financial signoff, monitoring, and final production downstream verification remain blocked/unexecuted.

## 1. Root causes found

| Status | Root cause and impact |
|---|---|
| VERIFIED | The environment loader allowed `.env` to overwrite process-level database settings. This defeated test isolation and caused the production migration incident recorded below. Process environment now has precedence in both database and runtime configuration. |
| VERIFIED | Search/filter implementations were duplicated and inconsistent: unsafe/variable LIKE behavior, mixed collations, browser-side full-dataset filtering, missing pagination state, and exports that did not always reuse active filters. Shared normalization, bounded pagination/sort helpers, UTF-8 search expressions, and server filtering now serve the affected paths. |
| VERIFIED | Customer management visibility and operational selection were conflated. Full records could be overexposed, including unattributed legacy rows, while operational forms needed directory-wide minimal selectors. Management and lookup policies are now separate. |
| VERIFIED | Supplier selectors returned fields beyond operational need and did not consistently search code/name/phone/store identifiers. The operational result is now deliberately minimal; management and finance details retain separate role gates. |
| VERIFIED | Bilingual behavior mixed hardcoded UI text, placeholder translations, and no durable failure/provenance state. A shared provider abstraction, cache/queue, manual correction, source provenance, and idempotent backfill path replace placeholder behavior. |
| VERIFIED | Item type was mostly free text (`copy_normal_goods`) and could silently imply normal goods. Stable type codes, localized labels, suggestions, explicit confirmation, history, and cross-workflow filters were added. |
| VERIFIED | Draft numbering was derived without sufficient serialization and accepted blank/manual corruption paths. Customer-row locking, historical sequence inspection, server enforcement, controlled override, and concurrency tests now protect allocation. |
| VERIFIED | Draft operational costs lacked a multi-line audited model and disappeared from downstream standard exports. Exact-decimal cost lines, history, soft deletion, conversion preservation, and informational export sections were added. |
| VERIFIED | Receiving assumed a latest/single receipt in several consumers, had retry races, and reversal did not consistently remove voided stock from every read model. Cumulative receipts, order-lock idempotency, active-receipt filtering, and atomic void/reversal are now shared behavior. |
| VERIFIED | Balance/profit calculations mixed binary floats with SQL DECIMAL values and had a legacy utf8/utf8mb4 collation failure. Ledger, reconciliation, order-balance, commission, expense, deposit, supplier-payment, and draft-total paths use BCMath-backed fixed decimals. |
| VERIFIED | Login was duplicated between page/API, sessions were not uniformly regenerated, a known seed credential remained usable, and no durable throttle existed. One authentication service, session rotation, rehashing, hashed identity/IP throttling, production seed rejection, and an offline rotation command now apply. |
| VERIFIED | Downstream exports omitted structured classification/cost context in some paths. Filtered list/detail exports now include classifications and informational operational costs without silently posting them. |

### Production migration incident

**VERIFIED:** During the initial isolation attempt, the environment precedence defect caused migrations 067 through 071, including 070, to execute against production database `clms`. These migrations perform schema/config/backfill work; no reset, reseed, record deletion, or historical financial rewrite was run. Production aggregates observed before the incident were 246 customers, 32 suppliers, 21 orders, 57 order items, and 12 receipts. All later writes and workflow tests used the two named isolated databases. Migrations 072-076 were not intentionally deployed to production by this audit. Treat 067-071 as already deployed, verify them during the backup/deployment window, and do not blindly roll them back.

## 2. Changed files

**VERIFIED:** The audit changed the following application-owned files (generated test output, logs, dependencies, and uploads excluded):

- Environment/config: `.env`, `.env.example`, `backend/config/database.php`, `backend/config/runtime.php`, `backend/config/rbac.php`.
- Shared API/runtime: `backend/api/helpers.php`, `backend/api/index.php`, `includes/customer_visibility.php`, `includes/ui_translations.php`.
- API handlers: `auth.php`, `balances.php`, `confirm.php`, `containers.php`, `customers.php`, `draft-order-costs.php`, `draft-orders.php`, `expenses.php`, `financials.php`, `item-classifications.php`, `orders.php`, `products.php`, `receiving.php`, `suppliers.php`, `translate.php`, `translations.php`, `warehouse-stock.php` under `backend/api/handlers/`.
- Services: `AuthenticationService.php`, `DecimalMath.php`, `DraftOrderCostService.php`, `FinancialReconciliationService.php`, `ItemClassificationService.php`, `OrderCountryService.php`, `OrderExcelService.php`, `OrderReceiptWorkflowService.php`, `OrderReceivingService.php`, `ProductionReleasePreflightService.php`, `TranslationService.php` under `backend/services/`.
- Migrations: `033_phase1_foundation.sql`, restored `070_receipt_item_actual_dimensions.sql`, and new `072_translation_queue_and_provenance.sql`, `073_item_classification.sql`, `074_draft_order_costs.sql`, `075_receiving_idempotency.sql`, `076_auth_login_throttling.sql`, and Checkpoint 10 migration `077_approved_shipment_accounting_and_item_reservations.sql`; plus `backend/migrations/run.php`.
- Pages: `balances.php`, `confirm.php`, `containers.php`, `customer_portal.php`, `customers.php`, `financials.php`, `login.php`, `orders.php`, `procurement_drafts.php`, `products.php`, `receiving.php`, `suppliers.php`, `warehouse_stock.php`.
- Frontend: `frontend/js/balances.js`, `containers.js`, `customers.js`, `financials.js`, `orders.js`, `procurement_drafts.js`, `products.js`, `receiving.js`, `receiving_receive.js`, `suppliers.js`, `warehouse_stock.js`.
- Operations/scripts: `scripts/backfill_bilingual.php`, `scripts/dev_router.php`, `scripts/production_release_preflight.php`, `scripts/rotate_admin_password.php`, `scripts/ui_smoke_checks.mjs`, `run-tests.bat`.
- Tests: `tests/draft_order_builder_test.php`, `tests/financial_test.php`, `tests/production_critical_audit_test.php`, `tests/production_hardening_test.php`, `tests/production_release_preflight_test.php`, `tests/regression_receive_variance_test.php`.
- Audit handoff: `docs/ACCOUNTING_POLICY_DECISIONS_REQUIRED.md`, `docs/ITEM_NUMBER_CONFLICT_REMEDIATION.md`, `docs/ACCOUNTING_DECISION_PACKET.md`, `docs/MIGRATION_067_076_RECONCILIATION.md`, `docs/LEGACY_ITEM_NUMBER_DECISION_PACKET.md`, `docs/SAFE_PRODUCTION_PREFLIGHT_PROCEDURE.md`, `docs/BACKUP_AND_RESTORE_RUNBOOK.md`, `docs/LIVE_TRANSLATION_VERIFICATION_PLAN.md`, `docs/PRODUCTION_RELEASE_RUNBOOK.md`, `docs/CHECKPOINT_9_DECISION_AND_RELEASE_GATE.md`, and this report.

## 3. Database migrations and rollback guidance

| Migration | Status | Change | Reverse-order rollback |
|---|---|---|---|
| 072 | VERIFIED | Translation jobs, manual corrections, bilingual provenance registry | Drop `bilingual_text_registry`, `translation_manual_corrections`, then `translation_jobs` after exporting pending/manual data. |
| 073 | VERIFIED | Stable item types, classifications, history | Drop `item_classification_history`, `item_classifications`, then `item_types`; legacy `copy_normal_goods` remains. |
| 074 | VERIFIED | Cost types, order costs, cost history | Export cost/history data, then drop `draft_order_cost_history`, `draft_order_costs`, `draft_order_cost_types`. |
| 075 | VERIFIED | Nullable receiving operation key and unique retry index | Confirm no retry workflow depends on the key, then drop `uq_receiving_operation_id` and `receiving_operation_id`. |
| 076 | VERIFIED | Hashed login attempt state | Drop `auth_login_attempts`. |

**VERIFIED:** Checkpoint 9 ran clean migrations 001–076. Checkpoint 10 separately ran 001–077 on `clms_codex_checkpoint10_20260712` with `ALLOW_DEMO_SEED=0`; migration 077 created only additive prospective controls and performed no reset/backfill. Repeat-run evidence is recorded in the Checkpoint 10 report. The repaired 033 dependency works on an empty schema.

**BLOCKED:** Do not add a database uniqueness constraint to legacy item numbers yet. Production evidence contains seven globally duplicated item-number groups, eight duplicate `(customer_id,item_no)` groups, and one blank value. The application lock/validation is verified, but a DB constraint requires an approved legacy cleanup map and backup.

## 4. Impact map: pages, APIs, services, queries, tables, workflows

| Domain | Pages/forms | Endpoints/queries | Services/tables | Downstream workflows |
|---|---|---|---|---|
| Search and parties | customers, suppliers, orders, drafts, receiving, balances, financials | customer `/lookup`, scoped `/search`, supplier `/search`, paginated lists | shared search helpers; customers, suppliers, visibility exception tables | operational selection versus full-record management/export |
| Bilingual data | draft/product/order forms, confirmations, portal, reports | `/translate`, `/translations` | `TranslationService`; translation jobs/corrections/registry | safe save on outage, manual correction, backfill, localized display |
| Classification | products, drafts, orders, receiving, stock, financials | `/item-classifications`, filters on affected resources | `ItemClassificationService`; item types/classification/history | create/edit/copy/conversion/partial receipt/report/export |
| Numbering | draft builder and order conversion | draft create/update/copy/approve | `OrderItemNumberingService`; customers/orders/order_items | supplier grouping, historical continuation, concurrent creation |
| Costs | procurement drafts and order exports | `/draft-order-costs`, draft/standard export | `DraftOrderCostService`; cost types/cost/history | save/edit/delete, approval preservation, filtered/detail exports |
| Receiving/stock | receiving queue/form/history, warehouse stock, portal | receiving queue/import/receive/reversal/export | `OrderReceivingService`, receipt workflow; receipts/items/splits/fees | partial receipt, retry, stock aggregation, reversal, confirmation |
| Balances/finance | balances, financials, supplier/customer payments | balance overview/transactions/reconciliation; profit/balances | `DecimalMath`, `FinancialReconciliationService`; deposits, payments, ledgers, expenses | exact calculation, party balance, reconciliation, filtered reports |
| Security | login, protected pages, confirmation/portal | API router, auth, direct detail/export/action URLs | `AuthenticationService`, RBAC, visibility helpers, login attempts | session rotation, throttle, CSRF-origin boundary, least privilege |

## 5. Customer permission matrix (before/after)

| Role/scope | Before | After | Status/evidence |
|---|---|---|---|
| SuperAdmin, ChinaAdmin, LebanonAdmin | Full management and operational behavior was not consistently distinguished. | Full customer management/search/detail/export; full minimal operational selection. ChinaAdmin/SuperAdmin may import; write permissions remain role-gated. | VERIFIED by API role checks and browser pages. |
| ChinaEmployee | Ownership filtering varied, direct/API paths could diverge, and unattributed legacy rows could leak. | Management list/search/detail/export is own creator plus configured creator exceptions (or explicit all-customer exception); unattributed/other-owner direct detail is 404. Operational `/lookup` searches the directory but returns only id/code/name/shipping selectors. | VERIFIED with owned, hidden, unattributed, exception, direct-ID, and lookup tests. |
| WarehouseStaff, ContainersStaff, FieldStaff | Operational selection could inherit management restrictions or expose full records. | Operational minimal lookup is directory-wide for authorized workflows. Full management data remains visibility-scoped; customer write/edit/export capabilities are not inferred from selector access. | VERIFIED by adversarial role/API checks. |
| Unauthenticated/public | Inconsistent endpoint assumptions. | No customer directory access. Public confirmation/portal uses token-scoped order data only, active receipts only, and no-referrer/no-store/noindex headers. | VERIFIED by router/public-flow tests. |

Before/after policy distinction: full management record, minimal operational selection, sensitive financial data, and export are separate backend decisions. Frontend hiding is not used as authorization.

## 6. Supplier-search verification

**VERIFIED:** Operational supplier lookup searches partial name, code, phone, and store ID with bounded results and returns only `id`, `code`, `name`, `phone`, `store_id`. Address, factory details, payment accounts, commissions, notes, and interactions do not leak through selectors. Management roles are ChinaAdmin, ChinaEmployee, LebanonAdmin, FieldStaff, SuperAdmin; finance details require ChinaAdmin, ChinaEmployee, LebanonAdmin, or SuperAdmin. Tests covered English/Chinese text, partial values, similar entries, missing fields, and use from drafts/orders/receiving/balances.

## 7. English/Chinese parity

**VERIFIED:** The same PHP/JS services and records drive both languages. The browser pass exercised Chinese navigation/parity after English workflows, and centralized translations cover new filters, pagination, classifications, costs, receiving, balances, errors, and confirmations. No separate financial or permission logic exists by language.

## 8. Translation architecture and failure behavior

**VERIFIED:** `TranslationService` supports `disabled`, LibreTranslate-compatible, and generic JSON providers configured by environment variables. It detects source direction, preserves original text, caches by source hash/language pair, records provenance, gives manual corrections precedence, applies timeouts, and queues failure/pending work without fabricating `[EN]`/`[ZH]` text. Critical order/financial saves do not fail solely because the provider is unavailable.

**IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** No live translation provider/key was configured in the isolated environment, so provider network success, quotas, and production latency were not verified. Disabled-provider queueing, retry state, cache behavior, and manual correction were verified.

## 9. Existing-data translation/backfill strategy

**VERIFIED:** `php scripts/backfill_bilingual.php --dry-run --batch-size=... --after-id=...` is repeatable, bounded, resumable, and does not overwrite a populated/manual target. Apply only after reviewing dry-run counts and configuring a provider. Retain queue/manual tables during rollback until pending and corrected values are exported.

## 10. Item classification behavior

**VERIFIED:** Stable codes include normal, replica/copy, cosmetics, branded, food, dangerous, and unclassified. English/Chinese labels are data-driven. Suggestions inspect descriptions, brand/category context, supplier context where present, and legacy values. Unknown/low-confidence input remains `unclassified` and returns 422 until the user explicitly confirms/corrects it; it is never silently normal. Product/order-item changes write history. Product imports may enter the unclassified review queue and must be confirmed before normal operational save paths.

## 11. Concrete item-number sequence

**VERIFIED:** For prefix `CUST`, supplier 1 receives `CUST-1-1`, `CUST-1-2`; supplier 2 receives `CUST-2-1`, `CUST-2-2`; returning to supplier 1 produces `CUST-1-3`. The next saved draft continues the historical final sequence for the same prefix/supplier rather than restarting. Removal/reordering does not renumber existing rows. Manual override is SuperAdmin-only and seeds subsequent automatic allocation when valid.

## 12. Concurrency and duplicate-number protection

**VERIFIED:** Two actual PHP worker processes created drafts concurrently. Customer-row `SELECT ... FOR UPDATE` serialization produced two unique consecutive numbers. Server checks reject blank/corrupt numbers and check active/history scope. **BLOCKED:** the DB uniqueness constraint remains deferred because of the documented legacy conflicts.

## 13. Draft-order operational costs

**VERIFIED:** Multiple lines support configured type, bilingual description, amount/currency, eight-decimal exchange rate, four-decimal base amount, supplier/provider, payer, allocation-method metadata, notes, users/timestamps, soft deletion, and create/update/delete history. `12.3456 RMB × 0.14000000` rounds half-up to `1.7284 USD`. Zero, negative, missing, invalid currency/rate/type values are rejected. Draft and confirmed-order CSV/XLSX exports preserve the lines and label them informational.

**BLOCKED:** No approved rule defines whether these costs (or customer-facing receiving fees) post to customer balances, supplier balances, landed cost, item cost, profitability, inventory valuation, or financial statements. Allocation method is stored but not executed. This deliberately prevents double-posting or invented accounting.

## 14. Draft-to-order conversion

**VERIFIED:** Draft create/edit/import, supplier/customer assignment, bilingual values, confirmed classification, item numbers, shared cartons, costs, totals, status checks, and export were exercised. Cost rows remain attached to the same order record through approval and appear in the standard order export. Duplicate submissions and concurrent numbering are guarded transactionally.

## 15. Receiving and inventory

**VERIFIED:** Partial receipts aggregate cumulatively per item; variance is based on cumulative declared versus received values. Retrying the same operation key returns the original result, and two concurrent workers created exactly one receipt. Stock, reconciliation, and portal queries exclude voided receipts. Customer-decline reset/reversal locks the order, voids active receipts atomically, and removes them from inventory views. Classification filters flow through receiving, warehouse stock, and exports.

## 16. Customer and supplier balances

**VERIFIED:** Customer receivable, deposits/ledger reductions, supplier invoiced/paid/settlement values, filtered overviews, transaction history, and party lookups use exact four-decimal strings. Legacy utf8 and utf8mb4 party search now coexists. Example customer order `0.3000` less deposit `0.1000` reconciles to `0.2000`. Supplier invoice `0.2100` less paid `0.1000` reconciles to `0.1100`.

**BLOCKED:** Supplier liability currently follows explicit `supplier_payments.invoice_amount`/settlement records. Whether receiving or draft operational costs should create/alter liability requires an approved posting rule.

## 17. Financial reconciliation examples

**VERIFIED:** BCMath is mandatory; absence fails explicitly instead of falling back to binary floats. `3.0000 × 0.1000 = 0.3000`; half-up `12.3456 × 0.1400 = 1.7284`; `-1.23455` rounds to `-1.2346`. The read-only order reconciliation traces customer item sell value, deposits/ledger entries, supplier invoice/payment records, active receipt quantities/CBM/weight, and reports unmatched sources without posting changes.

## 18. Reports and exports

**VERIFIED:** Orders list/detail CSV/XLSX, draft CSV/XLSX, receiving queue export, warehouse stock export, financial/balance export paths, and classification-filtered exports were exercised. Active status/date/customer/supplier/item-type filters are reused by server exports. Standard order list/detail exports now include operational-cost summaries/lines labeled informational; receipt fees remain separately labeled. Voided receipts are excluded.

## 19. Tests added or extended

**VERIFIED:** Added/extended regression coverage for mixed bilingual search bounds, customer ownership/exception/unattributed visibility, translation outage/manual precedence, classification uncertainty, supplier-group numbering, true two-process numbering, cost decimal/history/soft delete, draft/confirmed cost exports, exact DecimalMath/financial reconciliation, authentication seed rejection/throttling, cumulative partial receiving, reversal, and true concurrent receiving idempotency. Browser smoke now requires explicit credentials, supports system Chrome, empty containers, and Chinese parity.

## 20. Exact test commands and results

All database commands below were run with `DB_NAME=clms_codex_audit_20260711`, `APP_ENV=testing`, and the translation provider disabled unless noted.

- `cmd /c run-tests.bat` — VERIFIED: all suites passed; notable totals: upload 4, search 4, draft builder 20, numbering 4, production audit 9, users 4, lifecycle 4, financial 11, expenses 4, consolidation 4, item capture 5, suppliers 3, phase 2 integration 4, hardening 6, receiving 9.
- `php tests/draft_order_builder_test.php` — VERIFIED: 20/20, including two-process numbering and draft-to-confirmed cost export.
- `php tests/financial_test.php` — VERIFIED: 11/11.
- `php tests/regression_receive_variance_test.php` — VERIFIED: 9/9, including concurrent retry.
- Repository lint loop using `rg --files -g '*.php'` and `php -l` — VERIFIED: 996 PHP files, zero syntax failures (dependencies included).
- JavaScript loop using `rg --files frontend scripts -g '*.js' -g '*.mjs'` and `node --check` — VERIFIED: 38 files, zero syntax failures.
- `node scripts/ui_smoke_checks.mjs` with isolated URL, explicit credentials, and system Chrome — VERIFIED: 23 page/workflow checks plus final smoke pass.
- `php backend/migrations/run.php` against `clms_codex_fresh_verify_20260711`, `ALLOW_DEMO_SEED=0` — VERIFIED: 76 migrations recorded through 076; demo seed absent; repeat run idempotent.
- Direct authenticated HTTP pagination assertions with `limit=1` — VERIFIED for products, receiving, containers, balance overview, balance transactions, financial profit, and orders.
- `php tests/production_release_preflight_test.php` — VERIFIED: read-only blocker coverage and unchanged order/item/cost/receipt row counts.
- `php scripts/production_release_preflight.php --expect-db=clms_codex_fresh_verify_20260711` — VERIFIED: seven checks verified, clean item-number/classification state verified, and the intended seed-password/accounting/backup/provider gates prevented a false production-ready result.

## 21. Manual/end-to-end scenarios

**VERIFIED:** Browser/API/database scenarios covered login; English and Chinese page parity; customer/supplier operational search; scoped customer management/direct denial; draft creation/import/edit; classification confirmation rejection/acceptance; item-number continuation/concurrency; cost add/export/conversion; order filtering/export; receiving and partial retry; stock; financial/balance pages and exact reconciliation; containers/assignment; confirmation; admin, buyer, warehouse dashboards; and token-scoped customer portal.

**BLOCKED:** A single scenario that posts operational costs/receiving fees through customer balance, supplier liability, landed cost, profitability, and inventory valuation cannot be executed until the accounting decisions in sections 13 and 16 are supplied.

## 22. Security issues found and corrected

**VERIFIED:** Corrected process-environment precedence, customer IDOR/list/export visibility, unattributed-customer leakage, supplier selector overexposure, backend role enforcement for lifecycle/finance/warehouse actions, exact-origin CORS (including port/scheme), Sec-Fetch cross-site rejection, hardened session cookie settings, session ID rotation, duplicated auth behavior, password rehash, production seed-password rejection, hashed login throttling, public token response leakage, receipt-void leakage, debug helper files, and development-router access to dotfiles/sensitive directories. Confirmation/portal responses add no-store, no-referrer, and noindex protections. SQL sort/direction/filter inputs are allowlisted and values are parameterized.

## 23. Remaining risks or unverified areas

| Status | Precise remaining item |
|---|---|
| BLOCKED | Accounting/posting treatment for operational costs and receiving fees, including payer semantics, allocation execution, landed cost, profit, ledgers, cancellation/reversal, and inventory valuation. |
| BLOCKED | Legacy item-number cleanup/constraint: seven global duplicate groups, eight customer-scoped duplicate groups, one blank. |
| IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION | Live translation provider credentials, network access, latency, quotas, and translated output quality. |
| IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION | Production deployment itself, verified backup/restore rehearsal, production migration 072–077, and production load/performance at real concurrency. |
| IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION | Production PHP must have BCMath enabled; local XAMPP verification passed. |
| BLOCKED | No source-control metadata exists in this workspace, so a native git diff/commit/rollback artifact cannot be produced. The changed-file inventory above is the handoff record. |

## 24. Deployment and rollback checklist

1. **BLOCKED:** Obtain written accounting decisions for cost/fee posting and legacy-number cleanup. Do not enable posting/allocation or a uniqueness constraint before that approval.
2. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Create a verified production database backup and uploads backup; perform a restore rehearsal in staging.
3. **VERIFIED:** Confirm production already contains migrations 067-071 from the recorded incident and inspect their schema/data prechecks. Do not reapply or blindly reverse them.
4. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Deploy code in a maintenance window; verify PHP BCMath, PDO MySQL, mbstring, fileinfo, zip/XML requirements, and web-server rewrite/security rules.
5. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Set `APP_ENV=production`; retain process-variable precedence; keep `ALLOW_DEMO_SEED=0`; configure provider URL/key only through protected environment variables.
6. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Rotate the admin credential offline with `CLMS_ADMIN_PASSWORD=... php scripts/rotate_admin_password.php`; never use the historical seed password.
7. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** In a separately authorized maintenance window, run `php backend/migrations/run.php`; verify only 072–077 are newly recorded, migration 031 remains absent unless explicitly intended, and migration 077 performed no historical posting, numbering backfill, or reset.
8. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Run bilingual backfill in dry-run/batches, review counts, then resume controlled writes; never overwrite manual corrections.
9. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Run authenticated English/Chinese smoke, adversarial permission checks, exact reconciliation examples, filtered exports, partial receiving, retry, and reversal in staging/production-safe fixtures.
10. **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Monitor PHP/application/performance logs, login throttle, translation queue, receipt idempotency conflicts, and reconciliation mismatches.
11. Roll back code as one release unit if smoke fails. Schema rollback 077→072 is a last resort and only before proving no ledger/reservation/auth/translation/classification/cost/receipt evidence is required; otherwise reconcile forward. Restore the verified backup for unexpected data mutation; do not manually edit historical balances.

### Checkpoint 8 continuation — executable release preflight

**VERIFIED:** `scripts/production_release_preflight.php` converts release risks into a read-only JSON gate. It requires an exact `--expect-db` match before opening a connection, refuses a production-named database unless `--allow-production-read` is explicit, runs inside `START TRANSACTION READ ONLY`, and rolls back. Checkpoint 10 extends it to migration 077, shipment-ledger/idempotency schema, and item-number registry schema. It still separately gates seed-password rotation, historical conflicts, classification review, translation provider, and backup/restore evidence.

Run against an isolated target:

`DB_NAME=clms_codex_audit_20260711 php scripts/production_release_preflight.php --expect-db=clms_codex_audit_20260711`

The backup gate requires `CLMS_BACKUP_FILE`, its independently recorded `CLMS_BACKUP_SHA256`, `CLMS_BACKUP_VERIFIED_AT`, and `CLMS_RESTORE_VERIFIED_AT`. No credentials or full backup path are emitted. A mismatched database exits before connecting. Current audit-clone evidence is intentionally `BLOCKED`: its test data has customer-scoped item-number duplicates, existing records in the classification review queue, provider-disabled translation jobs, no approved accounting policy, and no supplied backup/restore artifact.

The legacy cleanup and database-enforcement design is recorded in `docs/ITEM_NUMBER_CONFLICT_REMEDIATION.md`. Because `customer_id` and `item_no` currently live in different tables, a simple order-item index cannot enforce the established customer scope; an approved registry or denormalization migration is required after conflicts reach zero.

**VERIFIED:** `tests/production_release_preflight_test.php` proves the service emits every required check and leaves order, item, cost, and receipt row counts unchanged.

**VERIFIED:** The expanded `run-tests.bat`, now including the preflight regression, passed every suite after this continuation. The clean rehearsal database separately verified legacy-number and classification readiness while correctly blocking its historical seed password, unapproved accounting policy, missing backup evidence, and disabled provider.

### Checkpoint 9 continuation — controlled decision and release preparation

**VERIFIED (documentation/code review only):** The unresolved accounting questions are now A1–A13 management decisions with current behavior, options and risks, proposed recommendation, realistic calculations, historical treatment, and explicit effects on draft totals, confirmed orders, receiving, inventory/landed cost, supplier/customer balances, profitability, and reports. No proposal was implemented as an accounting rule.

**VERIFIED (code/document review only):** Migrations 067–076 were reconciled individually. Migration 070 added only nullable `DECIMAL(12,4)` `actual_height`, `actual_width`, and `actual_length` columns to `warehouse_receipt_items`; it contains no data update or financial/stock behavior. Migration 068 remains the incident migration requiring the highest scrutiny because it backfilled existing `customers.created_by` values using the lowest matching numeric audit `user_id`, which is not necessarily the earliest create event. The safest position is forward reconciliation with ambiguity/chronology checks, not deletion/reapplication or blind destructive rollback.

**VERIFIED (procedure design only):** The legacy-number packet supplies Q1–Q9 read-only scans, point-in-time inspected counts, active/history/canceled treatment options, audit-history requirements, registry-based enforcement, rollback, and six management questions. No item number or uniqueness constraint was changed.

**VERIFIED (procedure design only):** A pure vendor-client production preflight now lists every SQL statement and requires a dedicated `SELECT, SHOW VIEW` account, literal host/database/server identity, exact signed authorization, offline SQL denylist/hash review, read-only consistent snapshot, before/after counts, and fail-closed handling. It invokes no PHP, application bootstrap, API, or migration runner. It has not been authorized or executed.

**VERIFIED (procedure design only):** The backup/recovery runbook covers database/uploads/release artifacts, UTC naming, SHA-256, consistency and integrity checks, isolated restore, row/financial/file/workflow reconciliation, evidence, management-set RTO/RPO, triggers, and exact rollback order. The translation plan uses only isolated synthetic text and tests live provider success, cache/manual precedence, source change, failures, privacy, and non-blocking business workflows. The release runbook orders every gate and labels production reads, writes, reversibility, and high-risk actions.

**BLOCKED:** No production connection, preflight, backup, restore, provider test, migration, deployment, item-number edit, or accounting behavior occurred in Checkpoint 9. Exact management answers, exact read-only authorization, live evidence, and all downstream signoffs remain required. `docs/CHECKPOINT_9_DECISION_AND_RELEASE_GATE.md` is the controlling handoff summary.

## Final downstream impact review

**VERIFIED:** Code, API, UI, query, permission, calculation, active-receipt inventory, translation failure, classification, numbering, report/export, and regression-test consumers named in the impact map were traced after the final application changes. The final full suite, browser parity pass, fresh migration rehearsal, filtered export checks, pagination assertions, and concurrent worker tests all passed in isolation. Checkpoint 9 then traced the downstream policy, migration, backup/recovery, provider, release, financial-signoff, monitoring, and rollback gates without changing application or production state.

**BLOCKED:** Overall production readiness is intentionally not declared while the accounting treatment, legacy uniqueness cleanup, external provider verification, backup, and production deployment checks remain unresolved. No accounting behavior was guessed to make the status appear stronger than the evidence.

## 21. Checkpoint 10 — approved-rule implementation update (2026-07-12)

**VERIFIED:** Management supplied controlling rules A1–A13 and I1–I6. The former unsigned-decision blockers in sections 11, 13, 16, and 18 are superseded by `ACCOUNTING_DECISION_PACKET.md` and `LEGACY_ITEM_NUMBER_DECISION_PACKET.md`. This update records management approval only and does not claim accountant signoff.

**IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** Migration 077, `ShipmentAccountingService`, and downstream handlers now create a paired provisional customer charge/shipment expense at draft save, update it in place, finalize it once at approval, lock the draft rate, keep supplier liability isolated, exclude shipment expenses from inventory value, avoid partial-receipt reposting, and preserve linked reversal/restoration generations. Customer balances include only finalized shipment charges; pending amounts are shown separately. Profit reporting exposes gross and net shipment results without item allocation or double subtraction.

**IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION:** `ItemNumberReservationService` and migration 077 preserve `<customer-prefix>-<supplier-sequence>-<item-sequence>`, normalize controlled variants, reserve issued numbers in a canonical customer-scoped registry, and use transactions, customer locks, creation retry keys, and unique constraints. Normal forms are read-only. Historical numbers are neither backfilled nor rewritten; canceled/archived/deleted reservations remain durable.

**VERIFIED:** All Checkpoint 10 database work used isolated databases. No production access, credentials, preflight, backup, migration, deployment, record change, or reset occurred. The complete rule-by-rule evidence, exact commands/results, reconciliation examples, changed-file inventory, browser evidence, downstream trace, and updated release gate are maintained in `CHECKPOINT_10_IMPLEMENTATION_AND_VERIFICATION.md`.

**BLOCKED:** Production readiness remains blocked until separately authorized production preflight, backup/restore rehearsal, translation-provider verification, deployment, migrations 072–077, smoke testing, financial/accountant signoff where required, monitoring, and final production downstream verification are completed. The planned fresh-data reset is also blocked pending separate explicit authorization, verified backup, confirmed scope, and a dedicated reset procedure.
