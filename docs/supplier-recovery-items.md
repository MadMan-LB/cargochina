# Supplier recovery and Items & Orders

2026-09-24 — local implementation; production deployment is separate.

## QA inventory

- Suppliers: search, Items & Orders, delete confirmation/cancel, delete and reload exclusion for unused and linked suppliers.
- Detail: order items vs catalog switch, repeated manual numbers plus I.I.N, header/item/shared-carton supplier relationships, search/reset, pagination, authorized order link, close/reopen. Visually inspect populated and empty states at 1600×900.
- Recovery: search/type filter, actor/time/reference, restore confirmation, reload and original supplier identity. No supplier permanent-delete control.
- Negative cases: stale responses when switching suppliers/views, failed request/retry, literal special characters, denied read/restore, archived supplier rejected by new-item selection/import and direct writes.
- Downstream: preserve existing orders, items, financial references and audit; active supplier lists/selectors exclude archived records; historical joins keep supplier names and cargo/financial totals.

## Business behavior

Delete is now recoverable for both unused and linked suppliers. It records actor, timestamp and optional API reason on the original row and in audit. It never cascades, unlinks orders or erases payments. Supplier IDs/codes remain reserved. Restoration clears deletion fields transactionally on that same identity, validates the current revision and current permissions, and records audit. Permanent supplier deletion is disabled, including for SuperAdmin.

Archived suppliers disappear from active supplier lists and supplier/product autocomplete. New or edited item associations and CSV imports cannot silently reuse/reactivate them. Restore first. Existing receiving, stock, assignments, container totals, order exports, financial reports, payments and supplier history deliberately retain their relationships: deleting a supplier master is not deleting its cargo or cancelling liabilities. Existing catalog products remain historical records.

Items & Orders is paginated and uses canonical item-level supplier (falling back to order header) plus matching shared-carton contents. It preserves duplicate manual Item Numbers and shows I.I.N separately. Shared cartons show whole-row quantity/cartons and separately label matching contents. Product catalog is a separate view. Order rows obey customer visibility and order-read access; catalog requires product-read access. No financial fields or unrelated shared-carton contents are returned. Order links are offered only with Orders page access.

## Downstream trace

Migration 089 → SupplierLifecycleService → supplier delete/update/list/search/import → product selectors and ProductWriteService/import → normal orders/OrderWriteService/saved item sets → Draft an Order sections/products/shared contents/import → legacy procurement validation. RecycleBinService and its API/UI include supplier recovery with separate restore and supplier-write grants. Audit remains immutable. Historical order/item/receipt/warehouse/container/export/accounting joins are intentionally not filtered by supplier deletion; their business records are still active.

The screenshot alone cannot prove why the new production supplier was reported as linked. The previous hard-delete dependency guard is no longer the delete path. Both linked and unused cases receive the same recoverable behavior.

## Deployment

Production currently has confirmed missing migration 087 columns (see production-compatibility.md). Apply docs/production-repair-087.sql if still missing before using the existing Recycle Bin/calendar deployment. Apply docs/production-supplier-089.sql before these feature files. The latter is a schema-qualified copy of backend/migrations/089_supplier_recovery.sql for the actual `clms` database, to avoid phpMyAdmin switching its selected schema. Both scripts are additive/rerunnable; do not disable foreign keys or weaken server security. Follow the normal backup/maintenance procedure for ALTER TABLE. No trigger or SUPER privilege is required.

Before 089, existing supplier reads remain available and delete returns an explicit migration-required error. Migration 089 reserves no new business identity and copies/deletes no business rows.

Minimal feature runtime files:

- backend/services/SupplierLifecycleService.php
- backend/services/SupplierItemsService.php
- backend/services/RecycleBinService.php
- backend/services/OrderWriteService.php
- backend/services/ProductWriteService.php
- backend/api/handlers/suppliers.php
- backend/api/handlers/products.php
- backend/api/handlers/orders.php
- backend/api/handlers/draft-orders.php
- backend/api/handlers/procurement-drafts.php
- backend/api/handlers/recycle-bin.php
- backend/api/handlers/users.php
- includes/sidebar_permissions.php
- suppliers.php
- recycle_bin.php
- frontend/js/suppliers.js
- frontend/js/supplier-items.js
- frontend/js/recycle-bin.js

Use the normal release process to keep the migration file with the release. Test fixtures are never deployment files. Verification results follow below.

## Verification results

- 56 supplier deletion checks: unused and every supported linked-reference fixture, audit rollback, concurrent deletion, active-list/search exclusion, current permissions, purge prohibition, recovery and stale-version rejection.
- 32 supplier items/downstream checks: header/item/shared links, repeated manual numbers and I.I.N, shared-content identifier search without leaking other suppliers' contents, Unicode/literal wildcard search, pagination, permission/customer scope, MySQL 5.5 rejecting adapter, normal API creation of a fresh unused supplier followed by successful deletion. Actual receiving before archive → unchanged warehouse projection after archive; historical order lookup, CSV and XLSX still work. Direct product/procurement creation and supplier CSV import reject archived suppliers with the intended explanation.
- Recycle/calendar 76 checks pass with migration 089 present. Existing orders-domain suite passes. Page capabilities 598 assertions and production SQL compatibility 187 checks pass. Three JS suites pass (supplier delete, supplier items, recycle/calendar); 17 PHP files and three browser scripts pass syntax checks; diff whitespace check passes.
- Canonical 089 rerun covered by deletion tests. Production-qualified 089 also applied twice to a disposable schema while the connection selected information_schema, matching the phpMyAdmin issue; successful both times.
- Headed Chromium 1600×900: supplier search → Items & Orders → repeated identifier filter → literal wildcard empty result → Chinese catalog search; cancellation; linked supplier delete → reload exclusion → Recycle Bin actor/time/type/search → restore → reload supplier → linked order popup with original items/totals. Populated detail and bin layouts inspected; no page JavaScript errors. All mutations were local/disposable, with external delivery disabled.

## Known limitations, kept separate

- Production is not deployed/verified from this workspace. MySQL 5.5 behavior uses the existing rejecting PDO adapter on local MariaDB; no live 5.5 execution is claimed. Production PHP/site version uncertainty remains documented in production-compatibility.md.
- Existing draft_order_builder_test.php: 27 pass, 1 failure (`manual draft item numbers persist, drive the next value, and create audit history`: “Manual change audit is incomplete”). The same focused test fails against the unchanged HEAD draft handler in a temporary baseline probe. Supplier changes did not introduce it; no unrelated audit/numbering rewrite was made. Do not report a fully green application regression.
- Shared-carton matching reuses the existing streamed PHP JSON fallback on MySQL 5.5. It avoids unsupported JSON SQL and N+1 requests, but large legacy shared-carton datasets retain the existing scan cost. No new index or JSON schema redesign is introduced.
- Deletion reason is optional in the API; the existing one-confirmation Delete UI leaves it empty. Products/catalog are retained as history, not cascaded into the bin. Supplier restoration requires Recycle Bin access, supplier management/read, supplier write and the separate Restore permission; viewing does not grant write privileges.
