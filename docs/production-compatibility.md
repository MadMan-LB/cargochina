# Production compatibility revision — 2026-09-23

Scope: deployed runtime assumptions, MySQL 5.5 SQL paths, operational search/exports and migration portability. This is not a release-readiness or historical-data audit. No production data, settings or migration state were changed.

## Runtime baseline

| Component | Production evidence | Local verification / required production evidence |
|---|---|---|
| Database | User's production phpMyAdmin screenshot: `VERSION() = 5.5.62-log`, InnoDB file format `Antelope`, `innodb_large_prefix=OFF`. Treat 767 bytes as the index ceiling. | Local disposable QA: MariaDB 10.4.32. MySQL 5.5 is the compatibility target; local MariaDB success alone is insufficient. |
| Web server | Read-only HTTPS response on 2026-09-23: public `Server: nginx`. The PHP origin may be Apache, but its actual version is unverified. | Local Apache 2.4.58; isolated browser QA used PHP's local server. |
| PHP / PDO | Exact production PHP/PDO/mysqlnd version **unverified**. Runtime code uses PHP 8.1 features (`readonly`, `never`, `array_is_list`); Composer now declares `>=8.1` instead of a misleading `>=7.4`. | Local PHP 8.2.12, PDO mysqlnd 8.2.12. Confirm production PHP >=8.1 before deploying. |
| PHP extensions / limits | Production `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `zip`, `intl`, `openssl`, `curl`, `exif`, upload/post/memory limits **unverified**. | Local modules loaded. Owner-only `GET /api/v1/diagnostics/runtime-compatibility` now reports deployed versions and limits without secrets. |
| SQL mode / encoding / transactions | Production SQL mode, session/server charset/collation, default storage engine, isolation, timezone, PDO prepare mode and actual table engines **unverified**. | Local `NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION`, server `utf8mb4_general_ci`, InnoDB, `REPEATABLE-READ`; PDO DSN requests `utf8mb4`. The owner-only probe reports the deployed values. Migration runner now refuses non-InnoDB defaults or non-InnoDB core tables before DDL. |
| Browser | Staff screenshots show modern Chromium; deployed browser versions are **unverified**. | Headed local Chromium passed the representative UI checks below. Existing JS tests cover stale search responses, filter state and failed requests. |

The owner-only runtime probe is deliberately unavailable to Cherry/ordinary staff (403 verified). A production SuperAdmin must capture its output after deployment; no credentials or secret values belong in this report. Do not infer PHP/Apache/SQL mode from the nginx header or local development environment.

## P0/P1 findings and fixes

1. **P0/P1: audit idempotency lookups used `JSON_EXTRACT` without a legacy branch.** `OperationReplayService` affected expense creation, catalog creation and imports; `ShipmentWriteService` affected shipment-draft creation; procurement draft POST had the same trap. `AuditReplayLookupService` now narrows by audit type/key text and decodes JSON in PHP, accepting only an exact top-level key. The existing actor, payload-hash, lock and audit transaction checks remain. An explicit SQL `LIKE ... ESCAPE '!'` avoids SQL-mode assumptions about backslashes. Existing container create uses its previously added PHP fallback. Tests cover wildcard keys, malformed/nested/cross-entity audit records and shipment creation replay.
2. **P0: supplier-finance order validation used an unconditional JSON supplier predicate.** Supplier payment/expense creation and Balances order linking now pass the active PDO connection and supplier ID into the existing shared-carton legacy predicate. Numeric/string shared supplier IDs work without MySQL JSON functions; order ownership and financial validation remain server-side. Tested against the MySQL 5.5 rejecting adapter and integrated financial suite.
3. **P0 install / P1 migration: native `JSON` columns and JSON SQL appeared in legacy migrations.** The 11 affected SQL files now store JSON documents in `LONGTEXT`, retaining full content on MySQL 5.5. Migrations 062/065 apply sidebar JSON changes through `SidebarConfigMigrationService` in the PHP migration runner, preserving valid custom role pages and making reruns idempotent. These two raw SQL files alone no longer apply sidebar changes: use `backend/migrations/run.php`. A fresh users table keeps the full 255-character email unique key in MySQL's three-byte `utf8` encoding, fitting Antelope's 767-byte index limit. Existing production columns/data are not rewritten by these edits.
4. **P0 integrity assumption:** migration runner now stops before DDL if its default storage engine or an existing core table is not InnoDB. It reports the affected table names; it never silently converts operational tables or changes server settings.
5. **P1 diagnostic gap:** owner-only runtime probe exposes deployed PHP/PDO/database versions, SQL mode, charset, InnoDB/binlog values, extensions and upload limits. It never exposes credentials. Ordinary staff access returns 403.

Existing guarded JSON SQL remains only in `helpers.php`, `PackingListItemNumber.php`, and `UploadAccessService.php`; their MySQL 5.5 PHP branches were already covered by legacy search/media tests. The permanent compatibility test rejects new runtime JSON SQL outside that allowlist and new unsupported native JSON columns/functions in production migrations. The disabled demo seed migration 031 is excluded. Migrations 084/085 contain `CHECK(JSON_VALID(...))`: whether the exact production MySQL build parses these ignored checks still requires a real 5.5 migration trial. They must not be reported as proven by MariaDB tests.

Targeted construct scan found no runtime CTE/window/RETURNING/REGEXP-function/generated-column/functional-index/new ALTER variant in the relevant API/services. Date, NULL, decimal, duplicate-key, locking and isolation behavior is covered by the existing domain tests below, but the unknown production SQL mode and table engines limit live parity claims. No SQL mode, global InnoDB, binary-log or trigger security setting was weakened.

## Downstream coverage and evidence

| Path | Evidence |
|---|---|
| Page/read/search/filter/pagination/permission | 577 page-capability assertions; 118 legacy/native shared-carton searches including Unicode, numeric, repeated, escaped `%`/`_`, counts/pagination; filters/search/customer tests. Headed Chrome as Cherry opened Orders, draft orders, Receiving, Warehouse, Assignment, Containers, Customers and Suppliers. Partial Orders search, Warehouse filter, container code search and supplier search showed results without error. Draft customer/supplier/product autocomplete returned real suggestions. Product lookup is available inside the accessible draft page even though Cherry lacks the separate Products page grant. |
| Draft → Orders → Receiving → Warehouse → Assignment → Containers → Shipments | Focused order, receiving, warehouse, assignment, container and shipment suites passed in disposable schemas. Container and shipment suites require their own fixture order; running container after unrelated tests caused an expected literal-`%` fixture collision, and running shipment without container first lacked its roleless fixture. Fresh container → shipment rerun passed. No live production mutation was used. |
| Exports/imports/calculations | 21 dedicated item identifier assertions: both I.I.N and manual Item Number, duplicate manual values retained, XLSX/CSV and imports. Filtered export suite passed canonical actual CBM/weight, Unicode/formula safety, status variations and image delivery. A headed Chrome draft XLSX download completed with no JSON/error file. CSV/XLSX and import domain suites passed on the disposable database. |
| Permission/status/rollback/retries | Owner runtime probe denied to Cherry (real HTTP 403); owner/staff worker test passed. Existing assignment suite covers direct access, sequential/concurrent reservations, rollback and finalized protection. Shipment, order and import retry suites passed. Supplier payment/order link retained its existing authorization and transaction path. |
| UI interaction and visual | Nine focused JS suites passed: stale responses, Back/Forward, filters/pagination, import retries, export failures/double clicks. Headed Chromium at 1600×900 showed no horizontal overflow or error toast on the representative Orders/draft pages. |

Final focused run: **19/19 PHP suites passed** when their documented fixture ordering/isolation was respected (18 workflow/compatibility suites plus the owner runtime endpoint suite); **9/9 JS suites passed**. PHP syntax, Composer validation and `git diff --check` passed. This is not a run against the production MySQL 5.5 binary or the full application regression.

## Deployment and remaining limits

Deploy these **22 runtime files together** (plus this report):

- API: `backend/api/handlers/{balances,diagnostics,procurement-drafts}.php`.
- Services: `backend/services/{AuditReplayLookupService,OperationReplayService,ShipmentWriteService,SupplierPaymentService}.php`.
- Migration runner/helper: `backend/migrations/{run,SidebarConfigMigrationService}.php`.
- SQL migration files: `backend/migrations/{001_create_master_tables,002_create_orders,004_notifications_confirmations,008_suppliers_contact,009_item_capture,010_supplier_store_payments,013_tracking_push_log,026_products_customers_enhancements,052_supplier_payment_extensions,062_balance_sidebar_defaults,065_balances_deployment_hardening}.sql`.
- Dependency constraints: `composer.json`, `composer.lock`.

The three new QA files are `tests/production_compatibility_test.php`, `tests/migration_legacy_json_test.php` and `tests/runtime_compatibility_endpoint_test.php`; deploy them only with the test harness, not as public web endpoints. Already-applied SQL migrations are not automatically replayed by changing their files. No production migration was executed in this revision.

**Blocked production proof:** exact PHP/PDO/origin-server/extensions/SQL mode/table engines remain unknown until the owner runs the protected runtime probe on production. MySQL 5.5 SQL behavior was simulated with a rejecting adapter on MariaDB 10.4, not executed on a real 5.5 server. Trigger migrations 083/085 still require database-administrator privileges; production previously returned error 1419 with binary logging. Do not disable binary-log protection to bypass it. Migration 084/085 `CHECK(JSON_VALID(...))`, installed migration state, and Antelope indexes other than those explicitly tested need a production-style schema trial before compatibility can be marked PASS. A full backup/restore and ordinary release gates remain in `docs/final-stabilization.md`.
