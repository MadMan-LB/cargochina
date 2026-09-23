# Recycle Bin and Operational Calendar

Implemented and locally verified, 2026-09-23. Local disposable verification only; no production deployment or external sends. Earlier uncommitted compatibility work is preserved.

## Scope and delete-action classification

| Existing action | Treatment |
|---|---|
| Legacy procurement draft DELETE | New recoverable deletion for draft/cancelled, never converted. Header and items remain in place. |
| Shipment draft DELETE | New recoverable deletion for mutable drafts in open containers. Release memberships and order reservations atomically; clear container association. Restore an empty, unassigned draft, never steal cargo from another draft. Prior associations remain in audit. |
| Modern Draft an Order / orders | No header delete endpoint exists; do not invent a new state transition. Item replacement remains part of draft editing. |
| Containers | No delete endpoint exists; retain existing transport/finalization state machine. |
| Receipts | Existing void/reversal, not recycle-bin deletion. |
| Draft-order costs | Existing `is_deleted` archive plus accounting reversal/history. Not restored by this new generic bin. |
| Suppliers / customers | Existing dependency-protected hard deletion of unused records remains outside supported bin types. Deleted drafts deliberately retain supplier dependencies. |
| Products, HS tax reference rates, design attachments / shipment documents | Existing deletion behavior remains outside this bin. No generic restore of reference/attachment data is claimed. |
| Expenses | Existing expense deletion is not integrated into this bin. Posted shipment accounting/balance history is not a purge target. |
| User roles, visibility exceptions, item-set child replacement, assignment removal | Relationship/configuration edits, not independently recoverable business records. |
| Training reset / retention maintenance | Explicit administrative maintenance, outside staff recycle-bin workflows. |
| Finalized shipments, financial postings, immutable audit | Never supported purge/restore targets. |

Scope is intentionally explicit: this is not a claim that every application Delete button has become recoverable. Supported types are shown on the bin page.

## Invariants and downstream map

- Migration 087 adds `deleted_at`, `deleted_by`, `delete_reason`, monotonic `delete_generation` to canonical draft tables; no duplicate record store. MySQL 5.5-compatible metadata checks make partial deployment/reruns safe. No triggers/SUPER/JSON SQL.
- Existing delete state/permission/lock order is retained. Shipment deletion validates a revision including cargo membership. Old requests cannot delete a newly restored generation.
- Bin viewing requires administrator role, bin page, and underlying read capability. Restoration additionally requires `recycle-bin.restore` (SuperAdmin by default; explicitly grantable to an approved ChinaAdmin/LebanonAdmin through existing user permission overrides), plus the underlying write capability. Purge additionally requires SuperAdmin, recent authentication, exact typed confirmation, a known creation date at least ten years old, no hold, no tracking history and no child FK references. Audit is never purged by the feature. Null/zero creation dates never qualify.
- Restore locks the row in a transaction. Concurrent/stale restores conflict. Original IDs remain reserved, preventing reuse; procurement parents must exist. Audit creation and state change commit/roll back together.
- Normal procurement list/detail/export/update/convert/legacy migration, shipment list/detail/update/assignment/finalization/document actions, creation replay, notification links, tracking service and dashboard counters exclude deleted headers.
- Container lists/details/CSV-XLSX/packing data/capacity/destination checks, warehouse filters, order references, expense validation, HS reports and receipt-reversal checks use active shipment joins. Deletion removes reservation rows and clears container ID; received stock remains real stock, but no deleted draft contributes to container totals.
- Audit/user-history views intentionally retain historical deletion and creation events. Supplier deletion protects references from deleted procurement drafts until approved permanent disposal.
- Deploy schema 087 before changed PHP. Deploy consolidation JS and PHP together (existing filemtime cache busting).

## Calendar event sources and meanings

| Label | Canonical source |
|---|---|
| Order / draft created | `orders.created_at`, distinguished by `order_type` |
| Approved | `audit_log` order `approve` event |
| Expected ready / receipt | `orders.expected_ready_date`; planned readiness, not a claimed physical receipt |
| Received / Warehouse | Non-void `warehouse_receipts.received_at`, including separate partial receipts/condition |
| Fully received | Receiving audit `quantity_totals.remaining=0`, positive ordered quantity, linked active receipt; use receipt timestamp |
| Container assigned | Shipment `assign_orders`, `assign_container`, or `add_orders` audit with actual container/order IDs |
| Planned departure / Departed / ETA / Arrived | `containers.expected_ship_date`, `actual_departure_date`, `eta_date`, `actual_arrival_date` respectively |
| Shipment finalized | Existing shipment `finalize` audit |

Receiving writes the warehouse stock ledger in the same operation. Therefore Received/Warehouse is one labelled event, not two invented milestones. Assignment is reservation. There is no separate physical Loaded action/timestamp, so none is fabricated. No warehouse/location dimension exists in these canonical records; no fictional warehouse filter. Current container context is explicitly distinguished from event container context.

One normalized read API drives month/week/day/timeline and per-order filtering. Order/customer filters project transport dates through genuine current shipment membership; they never infer historical cargo on a container after membership was removed. Dates are displayed as recorded in the application timezone without browser UTC conversion. Null/zero dates do not become events. Voided receipts and soft-deleted drafts are excluded. No calendar edits write business data.

Only visible date ranges (exclusive end, max 62 days) are queried. Context is resolved in batches, not per-event queries. Limits fail explicitly with a narrower-range instruction rather than truncating. Calendar page access alone grants no order/container payloads: underlying page/read capabilities and customer scope are checked. No financial amounts, secrets or raw audit JSON are returned.

Migration 088 indexes the date-range predicates (previous index inventory had no relevant creation/receipt/audit/container date paths). Final disposable audit experiment, 5,000 old events: full scan 5,010 estimated rows / 2.024 ms mean → `idx_calendar_event` range / 2 estimated rows / 0.086 ms mean (30 repetitions). Complete calendar service query over the targeted fixtures: 1.26 ms. These are local query measurements, not production latency claims.

## QA inventory

- Bin: delete supported draft; active list/detail/export exclusion; bin metadata, literal search/type/user/date filters/pagination; restore confirmation, empty shipment warning, refresh/reopen persistence; stronger purge restrictions; audit and stock/container totals.
- Calendar: each canonical type, expected vs actual, null/void exclusion; month/week/day/timeline; previous/next/today; retained customer/order/container/status/type filters; more-events day action; popup metadata and correct authorized record link; no fabricated Loaded event.
- Negative/exploratory: stale delete/restore, concurrent restore, ordinary-user denial, hold/retention restrictions; invalid/oversized date range, stale async response/failing request, unauthorized calendar-only role.
- Visual: desktop viewport fit, readable legend and event labels, popup/confirmation dismissal, empty/error/loading states.

## Verification

- PASS `tests/recycle_calendar_test.php`: **67 checks**, including delete/exclusion/export denial, metadata and filters, cross-type pagination, generation conflicts, concurrent restore, rollback on audit failure, current permissions, separate restore grant, expired-record purge/hold/re-auth/audit, warehouse stock and container capacity, all 11 event types, null/void handling, chronology, date ranges, filtered transport links, calendar-only and container-only no-leak cases, MySQL-5.5-rejecting PDO adapter.
- PASS `tests/calendar_range_index_test.php`: rerunnable 088 plus measured range plan. Migration 087 is applied twice by the feature suite, preserving rows.
- PASS affected existing PHP suites: containers, shipments, assignments (updated delete revision contract), warehouse, exports, receiving, page capabilities (**577 assertions**), production SQL compatibility (**185 checks**). No full application regression run.
- PASS JS: new recycle/calendar interaction regression, filters (updated obsolete calendar test), shipment UI, capacity UI. Covers stale async results, retained filters, failure clearing/retry, escaped content, date navigation, more-events/day navigation, duplicate submission prevention and revision binding.
- PASS syntax: 30 affected PHP files and three application JS files; `git diff --check` clean.
- PASS persistent headed Chromium at 1600×900: create disposable shipment draft → delete with reason → active list exclusion → bin search/type/user/date filters → restore → reload/reopen in Consolidation. Permanently delete an explicitly expired child-free fixture only after rejecting wrong confirmation; reopen verifies absence.
- PASS headed calendar: month/week/day/timeline, customer/order/event filters retained across view switches, receipt popup and exact order navigation, filtered departure popup and exact container navigation, empty Unicode search, simulated 503 clears old results and retry recovers. Separate screenshots checked month/day/bin/popup readability and viewport fit; zero JS page errors.
- Browser QA caught and fixed unknown creation dates incorrectly offering the purge button. Server retention already rejected null dates; it now also explicitly excludes zero dates. Roleless/calendar-only access and stale/concurrent failures are permanent API regression cases.

All fixtures, temporary administrator grants and QA server/browser belong only to `clms_hardening_20260919` and are removed after verification. No real notifications/tracking sent.

## Deployment / limitations

- No production DB changes performed. Apply 087 then 088, deploy feature files and the listed consumers together. Earlier compatibility changes remain separate in `docs/production-compatibility.md`.
- SuperAdmin receives bin navigation automatically. For customized role sidebar policies, explicitly grant `recycle_bin` to approved ChinaAdmin/LebanonAdmin users; neither a page override nor generic admin page access lets other roles restore or purge.
- Historical events absent from source data are not reconstructed. Warehouse identity and physical loading are unavailable. Other record types retain the deletion/archival behavior classified above.

### Exact feature deployment list

Apply these additive migrations in order before the PHP release:

- `backend/migrations/087_recycle_bin.sql`
- `backend/migrations/088_calendar_range_indexes.sql`

Deploy these **30 runtime files together**:

- Pages/navigation: `calendar.php`, `recycle_bin.php`, `consolidation.php`, `includes/sidebar_permissions.php`, `backend/config/rbac.php`.
- JS: `frontend/js/calendar.js`, `frontend/js/recycle-bin.js`, `frontend/js/consolidation.js`.
- New services: `backend/services/RecycleBinService.php`, `backend/services/CargoCalendarService.php`.
- API handlers: `backend/api/handlers/calendar.php`, `recycle-bin.php`, `procurement-drafts.php`, `draft-orders.php`, `shipment-drafts.php`, `containers.php`, `orders.php`, `dashboard.php`, `warehouse-stock.php`, `hs-code-tax.php`, `internal-messages.php` (all under the same handlers directory).
- Existing services: `backend/services/CargoStateService.php`, `ShipmentWriteService.php`, `ShipmentAssignmentService.php`, `TrackingPushService.php`, `NotificationTargetService.php`, `ContainerCapacityService.php`, `ContainerWriteService.php`, `ExpenseWriteService.php`, `OrderReceiptWorkflowService.php` (all under the same services directory).

Overlapping files contain earlier compatibility fixes. Deploy their existing dependencies from `docs/production-compatibility.md` if that release is not already installed. Tests/documentation are repository artifacts, not additional production routes. Real production MySQL 5.5 execution remains unverified; tests used local MariaDB with rejection of modern JSON SQL, and migrations avoid triggers, generated columns, oversized indexes, and unsupported conditional ALTER syntax.
