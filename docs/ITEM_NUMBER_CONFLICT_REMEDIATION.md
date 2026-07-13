# Legacy item-number conflict remediation

Status: **BLOCKED** until a verified backup and business-approved cleanup map exist.

## Established scope

The application enforces item-number uniqueness per customer, not globally. Draft allocation locks the customer row and checks all of that customer's historical `order_items`. A database index cannot enforce this relationship directly because `customer_id` is stored on `orders`, while `item_no` is stored on `order_items`.

A future database-level enforcement migration therefore needs one approved schema strategy:

- add and maintain `customer_id` on `order_items`, backfill it from `orders`, verify it on every write, and add `UNIQUE(customer_id,item_no)`; or
- add a dedicated item-number registry with `UNIQUE(customer_id,item_no)` and require every order-item write/copy/restore path to reserve through it transactionally.

No strategy should be deployed until legacy conflicts are zero and every write path is covered. An order-local unique index would be insufficient and would create a false sense of protection.

## Evidence separation

- Production snapshot evidence recorded before isolated testing: seven globally duplicated groups, eight customer-scoped duplicate groups, and one blank item number.
- Current isolated audit database: test fixtures have changed the distribution. Its preflight count must not replace the production snapshot evidence.
- Production must be scanned only during an explicitly authorized read-only maintenance/preflight window after a verified backup.

## Required cleanup map

For every blank or customer-scoped duplicate, record:

- customer ID, order ID, order-item ID, current item number, shipping prefix, supplier ID, order status, created date;
- whether the value is a structured established number or legacy free text;
- which record retains the number;
- the approved replacement for every conflicting record;
- business owner, approver, approval date, reason, and customer-facing impact;
- linked exports, receipts, stock rows, financial references, notifications, and portal documents that must retain historical traceability.

## Safe execution sequence

1. Create and restore-test the production backup.
2. Export the conflict scan and freeze draft numbering writes for the maintenance window.
3. Approve the cleanup map; never automatically renumber historical records based only on row order.
4. Apply replacements in one controlled transaction with an audit-history record for every old/new value.
5. Re-run the application uniqueness check and the release preflight; both blank and customer-scoped duplicate counts must be zero.
6. Deploy the approved registry or denormalized-customer migration.
7. Run two-process numbering, edit/copy/restore/conversion tests and verify historical exports still reference the intended items.
8. Unfreeze writes and monitor duplicate-key errors.

Until these steps pass, application-level customer locking remains the verified protection for new draft numbers and database-level legacy enforcement remains `BLOCKED`.
