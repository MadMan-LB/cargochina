# Supplier and shipment-draft deletion — 2026-09-22

Scoped staff-reported fixes; no production deployment, database migration, broad audit, full regression or demo.

## Supplier deletion

The previous endpoint issued a raw DELETE. Reproduced MySQL error 1451 for an order-linked supplier in the disposable schema. Other existing foreign keys use CASCADE (payments/visits) or SET NULL (products, items, expenses): bypassing the restrictive key would destroy attribution/history. The production screenshot's exact request logs were unavailable; its specific failing supplier has not been diagnosed remotely.

Invariant: only unused suppliers may be hard-deleted. The endpoint now checks existing supplier columns and foreign keys, shared-carton JSON references, and supplier attachments inside the locked deletion transaction. Returns a readable 409 explaining the reference category; never clears links or cascades history to force deletion. Optional schema modules are discovered through MySQL 5.5-compatible metadata; JSON is decoded in PHP. Foreign-key conflicts also have a safe 409 fallback. Successful delete and audit commit together; failures roll back. Revision/authorization checks remain intact. Double-click suppression releases on success, cancellation and failure; successful list refresh preserves filters.

Downstream review: header/line orders, products, procurement drafts, saved item sets, supplier payments/interactions, expenses, draft costs (including historical rows), item-number reservations, shared cartons and design attachments remain linked. Their joins, calculations, filters, exports, numbering and stock/shipment history therefore keep their original supplier attribution. No status, money, receipt or shipment data is rewritten. Existing audit records intentionally remain after deleting an unused supplier.

## Shipment-draft deletion / bodyless API actions

The shared JS API helper declared JSON but omitted the body when no fields were supplied. The strict server parser rejected it before the shipment handler ran. All POST/PUT/DELETE helper calls now send `{}` when input is absent; explicit input is preserved. The server also accepts a zero-byte body as empty input for older cached clients. Explicit null, arrays, scalar values, whitespace-only and malformed JSON still fail. Required-field validation, permissions, CSRF/origin controls and finalized-container/draft protections remain in the existing handlers. GET behavior is unchanged.

## Verification

- `tests/supplier_deletion_test.php`: 49 checks, including reproduced FK failure; each direct supplier relationship; shared-only items; attachments; legacy SQL guard; stale/unauthorized requests; audit-failure rollback; concurrent deletion; one audit event; reopen after deletion.
- `tests/supplier_deletion_ui_test.cjs`: double click, revision forwarding, readable conflict, retry/cancel and filter-preserving refresh.
- `tests/api_empty_body_test.cjs http://127.0.0.1:8099/cargochina`: JS serialization plus actual HTTP parser/authorization boundary. Empty/object requests reach authentication; malformed/null/array/scalar bodies remain 400.
- Existing `tests/assignment_domain_test.php`: all 20 reported scenarios passed, including draft deletion restoring order eligibility, reservations/stock, finalized protection, concurrent assignment and batch rollback.
- PHP/JS syntax and `git diff --check` passed. Local MariaDB execution with a MySQL 5.5 JSON-rejecting adapter for the supplier guard; actual production MySQL execution remains unverified. No headed-browser run for this scoped fix.
- Tests used `clms_hardening_20260919`; removed after verification. Source business records untouched; no external messages sent.

## Deploy together

1. `backend/services/SupplierDeletionService.php` (new)
2. `backend/api/handlers/suppliers.php`
3. `backend/api/index.php`
4. `frontend/js/app.js`
5. `frontend/js/suppliers.js`

No SQL migration. Reload open pages after deploying. Verify an unused disposable supplier can be deleted, an in-use supplier shows its clear conflict, and an editable disposable shipment draft deletes and returns its orders to the correct eligible state. Never delete real business records just to test deployment.
