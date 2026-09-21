# Production hardening ledger

## Current continuation — 2026-09-19

**NOT READY.** The current gate inventory, exact business-input records, additional fixes, measured performance and final verification are in [final-stabilization.md](final-stabilization.md). This continuation supersedes the historical deployment/administrator-verification limitations below: migrations 082/083 are now deployed after a verified 68-table database restore; authorized disposable administrator UI, catalog/settings concurrency and local tracking HTTP transport have been verified. Historical cargo/credentials/classification decisions and real remote-service/retention evidence remain open. Earlier domain findings and their original test counts below are retained as audit history, not repeated acceptance claims.

Demo stopped. No domain is passed until backend, downstream and real browser persistence checks complete.

## 1. Receiving — implementation verified; historical data limitation

Canonical invariants:

- Ordered quantity is the procurement item quantity (or its declared cartons × pieces/carton fallback). Receipts record a positive **delta**, never a cumulative replacement.
- Received quantity is the sum of active, non-voided item receipts. Remaining = ordered − received. A delta cannot exceed remaining. Repacking can change cartons; it cannot increase ordered goods.
- Completion is computed across every order item. A condition label cannot bypass remaining quantities. Damage is independent of completion.
- All new receipts allocate quantities, cartons, CBM and weight to items; header totals must equal their sum. Single-item legacy requests can be allocated unambiguously. Multi-item requests must identify their items.
- Declared measurements remain procurement estimates. Active actual measurements drive physical stock and shipping totals. Missing legacy measurements remain an explicit limitation, never inferred as new stock. Existing configured review thresholds concern CBM; no weight threshold is configured. Actual weight is nevertheless validated and used for every physical weight calculation.
- A receiving operation key identifies one payload on one order. Retries return that receipt; changed payloads and voided operations conflict. Order locking precedes cumulative reads and writes.
- Receipt rows are immutable. Reset after decline voids their physical effect atomically and retains history. Assigned/finalized cargo cannot be reversed through a stale confirmation token.
- Imports invoke the same receipt rules; preview commit is locked and replayable. Invalid batches roll back completely.
- Evidence must be an existing uploaded image. Damage always requires evidence; completion variance uses cumulative CBM.

Paths traced: orders/{id}/receive (both receiving UIs), receiving/import preview+commit (CSV/XLSX existing order and direct intake), OrderReceivingService, OrderReceiptWorkflowService reset/accept/decline, CargoMetricsService, receipt history/detail, warehouse stock, assignment/capacity, order/receipt exports and tracking payload generation.

Baseline: eight selected regression suites passed before these hardening changes. Existing broad tests are not proof of the new invariants.

Verification inventory and results:

| Rule / surface | Evidence |
| --- | --- |
| Ordered/current/cumulative/remaining, partial/final, over-receipt, duplicate item, numeric limits | `receiving_quantity_test.php`, rollback-safe service cases in `receiving_domain_test.php` |
| Main receiving UI | Cherry: 30 of 100 received; reload showed 70 remaining; 80 rejected; 70 completed; reload removed completed order from queue |
| Separate receiving UI | One of two items partially received; zeroed second item excluded; saved receipt reopened and reloaded |
| Damage/evidence | Real upload in disposable environment; save rejected before photo; saved with image, Confirmed state persisted; thumbnail loaded at 120 pixels |
| Large order and packing splits | 80→81 visible items retained typed quantities, CBM and split row |
| CSV / XLSX | Real preview, commit and reload for both formats; invalid excess CSV blocked and Commit disabled; retry returns same receipt |
| Concurrent operators | Two processes: one 60-unit receipt accepted, competing 60-unit receipt rejected against 100 ordered |
| Concurrent direct imports | Two processes commit same preview: exactly one order and receipt |
| Batch rollback | Database fault on second receipt: first receipt and both status updates rolled back |
| Reversal | Active quantity becomes zero, operation replay conflicts, posted fees reversed with history retained |
| Permissions | Anonymous receiving HTTP 401; authenticated unprivileged receiving/history/import HTTP 403; Cherry allowed |
| History / filter / pagination | 51-record fixture: 50 + 1 rows, deterministic ordering; changed filter resets page; receipt detail reload preserves totals |
| Exports | CSV/XLSX selected receipt actual quantity, cartons, CBM and weight asserted; formula-like description is inert |
| Notification safety | Intent queued inside transaction; rollback removes intent; no external dispatch during tests |
| Visual QA | 1280×800 receipt summary readable, document width equals viewport; screenshot `artifacts/cargo-demo/qa-receiving-completed.png` |

Safety: real UI writes run in disposable database `clms_hardening_20260919`, with copied schema/constraints and reference records, no operational cargo copied, dashboard-only delivery, and tracking endpoints/tokens cleared. Main database receives only rollback-only regression fixtures. The original five demo orders/container are untouched. Sandbox and uploaded QA image must be removed after all domain audits.

Historical receiving audit (read-only, main database): active receipts 28, 30, 34, 211–215 lack item allocations (orders 24, 34, 23, 734–738 respectively). Order item 55 on order 28 records 180 received against 168 ordered. Five affected orders are finalized. No historical quantities were invented or rewritten. New receipts reject ambiguous historical item allocation. These records require verified reconciliation or an explicit decision that they are disposable. This is a release limitation, so Receiving is not marked unconditional PASS.

No live tracking, email, WhatsApp, payment or financial external action was executed. Financial assertions only write the disposable database / rollback fixtures.

## 2. Warehouse stock — implementation verified; historical reconciliation BLOCKED

Canonical invariants under review:

- Warehouse rows represent order items once; receipt aggregation precedes joins. Assignment reserves goods and cannot erase their warehouse record. Shipment finalization transfers them out of operational warehouse stock.
- Actual quantity/CBM/weight/cartons come from the active receipt ledger. Unreceived items contribute zero; unknown legacy allocations remain unknown and require reconciliation. Procurement quantities are displayed separately.
- A partially received item is in warehouse with a remaining balance; untouched items on the same inbound order remain in transit. Preserve the existing two operational filters (In transit / In warehouse).
- Customer rejection does not itself remove physical goods. Their active receipts remain visible until void/reversal; they remain ineligible for assignment.
- Filters, pagination, info dialogs and CSV/XLSX exports share the same item projection. Mixed carton dimensions must not be displayed as one invented maximum-size carton.
- Reservation/container joins must not multiply stock rows. Finalized shipping custody is excluded; reserved draft/container cargo remains visible with its order status.

Fixed: item-level partial/untouched state, zero actual quantities, reserved and declined active stock visibility, container filtering, actual quantities in info and all stock downloads, stale-response/error cleanup, spreadsheet-safe CSV/XLSX, consistent dimensions across receipts, and distinct-order stale-feedback dashboard counts. Unallocated or missing historical ledgers are explicit reconciliation flags, never fabricated item stock. Container summaries preserve unknown quantity/amount; packing-list export and tracking payload generation reject unknown allocations. Receiving views no longer present unknown quantities as zero.

Verification: `warehouse_domain_test.php` rollback-only cases cover partial/untouched, duplicate reservation joins, finalized exclusion, unknown/missing ledgers, void exclusion, both state filters, search, pagination, malformed IDs, anonymous denial, CSV/XLSX formula safety and actual totals. `warehouse_ui_regression_test.cjs` covers zero, declared-dimension fallback, stock-specific downloads, response races and failed-load cleanup. Cargo and receiving regressions rerun successfully. Read-only source audit found no additional post-receiving orders lacking an active ledger.

Browser QA (Cherry, disposable DB): order 1304 displays 51 received / 100 ordered / 49 remaining, 0.51 CBM and 51 kg in list and Info; search survives reload; In transit excludes its received item; selected XLSX inspected and matches those totals; controlled HTTP 503 visibly clears stale rows and retry recovers; roleless stock and export requests return HTTP 403. 1280×800 and 1600×900 layouts checked (table scrolls within its own region, document does not overflow). No browser exceptions. Historical records listed in Receiving remain a release limitation, preserved without mutation.

## 3. Container capacity — PASS

Canonical invariant: each container's distinct active order load plus newly added cargo must stay within both configured CBM and weight limits, checked under a container lock. All assignment paths and capacity edits share this rule. Unknown cargo cannot count as free capacity. Capacity inputs are finite positive decimals; exact capacity is allowed; bypass flags cannot override the limits. Declared estimates never replace known actual cargo. Container list/detail/filter/export totals must match the same projection.

Fixed: `ContainerCapacityService` reads current active receipt measurements under row locks and compares fixed-scale decimals. Direct assignment, draft add/assignment, capacity edits and pre-finalization share this check. Container-before-draft locking avoids the previous opposing lock order. Reads after waiting do not rely on an earlier repeatable-read snapshot. Numeric limits match database precision. Server force bypass and both UI override prompts removed. Draft preview includes existing load and does not count an already assigned draft twice. Exact-full is valid. Full/partial/almost filters and high-utilization KPI consider both weight and CBM. Missing/unmeasured cargo cannot count as free capacity. Detail/search/list expose consistent usage and remaining capacity.

Tests: `container_capacity_test.php` covers exact-full, multiple orders/deduplication, over-CBM including one micro-CBM, over-weight, force bypass, existing unknown load, all three assignment paths, edit bounds, malformed/nonfinite limits, pre-finalization rejection, weight-full filters and unknown-load reads. Committed concurrency fixtures run only in the isolated database: mixed direct/draft assignment, edit versus assignment, and an explicitly established stale snapshot followed by another committed assignment. All pass. `capacity_ui_regression_test.cjs` covers existing+new, exact-full/exceeded, same-container deduplication, unknown load, and weight utilization. Warehouse/cargo/receiving regressions pass.

Browser QA: sandbox container 368 (`SG-BEY-CAPACITY-368`), initial order 1556 (0.4 CBM/40 kg), real UI assignment of 1557 (0.6 CBM/60 kg), persisted 2 orders/200 units/20 cartons/1 CBM/100 kg. Additional 1558 rejected HTTP 409, unchanged membership. Lowering capacity to 0.9 rejected; valid change to 2 CBM persisted; weight remains exactly 100/100 and Full filter/KPI retain the record. Draft 712 preview shows 1/2 CBM and 100/100 kg, “Full — within capacity”. Roleless edit/assignment HTTP 403. Native headed 1920×953 summary visually checked, no page exceptions. Two js_repl timeouts terminated tool-owned Chromium; recovery reused the existing Cherry session. The QA browser now runs independently and is attached with Playwright CDP, so tool recovery can preserve it. No external push executed.

## 4. Order ↔ container assignment — PASS

Canonical invariants to verify: one active reservation per order; only fully measured, fully received eligible cargo with resolved customer feedback can be reserved; retries to the same destination are no-ops; moves/removals are explicit and atomic; locked containers/finalized drafts cannot change membership. Order state, warehouse visibility, container load, filters, exports and audit records must follow membership. UI selection must survive repaint, search and suggested-container changes.

Fixed: shared `ShipmentAssignmentService` validates current locked receipt allocations, exact completed quantities, feedback, single reservation and container availability. Direct and draft assignment reject alternate reservations; retries are no-ops; explicit moves/removals/deletions update order state and transactional audit records. Received/reserved orders cannot be rewritten by the destructive procurement PUT; corresponding edit actions are suppressed. Server-side eligible filtering precedes pagination, and all assignment order/container selectors fetch subsequent pages. Persistent selection survives repaint/search/suggestion. Autocomplete remains inside modal focus boundaries, fits the viewport and ignores late search responses.

Tests: `assignment_domain_test.php` covers retries, competing destinations/drafts, incomplete/excess/unallocated/mismatched receipts, pending feedback, locked/finalized membership, release/move/delete, order detail/container linkage, stock continuity, direct PUT protection, concurrent operators and injected mid-batch rollback including audit. Selection and >100 eligible / >200 container pagination regressions pass. Same persistent browser executes `consolidation_interaction_test.cjs` covering mouse/keyboard/modal picker, stale results, and modal refresh races. Capacity, warehouse and downstream CSV/XLSX regressions pass. Read-only historical duplicate-reservation audit found none.

Browser QA: orders 1929/1930 selected across searches and assigned to container 517; exact replay added zero orders. Draft 902 moved through UI to 516; preview and reopened data show its 0.4 CBM/40 kg plus existing 0.2 CBM/20 kg. Order 1930 removed and re-added through UI, then reopened after reload. Final container 516 has three orders, 0.6 CBM/60 kg, 1.4 CBM/140 kg remaining; its reference appears in order detail. Warehouse retains 10 received/0 remaining and Assigned to Container status. Real mouse picker verified after focus/race fix. Roleless direct/add/move/remove HTTP requests all return 403. Received-order edit controls are absent. No external action executed.

Follow-up in Orders domain: declined/reset orders with voided receipt history need a safe amendment policy; the old destructive item replacement is deliberately blocked to preserve history.

## 5. Status/state transitions — PASS

Canonical invariants: transitions follow explicit graphs and domain preconditions under locks; finalized cargo and shipment metadata are immutable through every endpoint; finalization is atomic and repeatable; tracking delivery outcome is separate from cargo finalization. Warehouse location/state derives from receipt and shipment custody rather than arbitrary labels.

Canonical mapping:
- Order: Draft → Submitted → Approved; receipt deltas yield InTransitToWarehouse while incomplete, then ReadyForConsolidation or Confirmed with pending feedback. Pending Confirmed/legacy AwaitingCustomerConfirmation may accept → Ready or decline → CustomerDeclinedAfterAutoConfirm; declined reset → Submitted after reversal/void. Ready/resolved Confirmed reserve → ConsolidatedIntoShipmentDraft / AssignedToContainer; explicit release → Ready. Assigned → terminal FinalizedAndPushedToTracking (legacy stored name denotes local finalization; display says Finalized, delivery state is separate).
- Receipt: active immutable delta → voided by permitted reversal; no reactivation. Warehouse: derived item InTransit/InWarehouse, reserved still InWarehouse, finalized shipment excluded from operational warehouse.
- Container: planning ↔ to_go; to_go → on_route only when all drafts finalized; on_route → arrived → available (terminal). Finalization freezes cargo identity/capacity/destination/vessel and prevents reopening planning; schedule/ETA/arrival/notes and forward transport milestones remain operationally editable.
- Shipment: draft → finalized only with complete measured/resolved cargo, valid reservation, container and capacity. All cargo/refs/document writes are guarded under locks. Same finalization retry is a no-op. Existing sibling drafts in the same frozen container can still finalize without changing membership.
- Tracking: finalized shipments only; pending/disabled/dry_run/failed can attempt delivery, success is terminal. Per-database/draft advisory lock serializes retries. No external delivery inside a transaction. Remote idempotency contract remains an integration limitation for the tracking domain.

Fixed: stale state map, unlocked submit, post-commit finalization audit, mutable finalized carrier/document endpoints, invalid container jumps/creation, sibling-finalization dead end, partial/pending cargo finalization, review display using last receipt instead of cumulative total, and token replacement race. UI presents allowed transitions and disables frozen fields; stale client attempts still fail server-side.

Tests: `state_domain_test.php` verifies all listed transitions, blocked alternate writes, exact finalization/no-op, partial/pending rejection, cumulative review, stale token, concurrent submit/finalize, tracking claim/success state, and injected finalization audit failure rolling back draft/order/delivery. `state_ui_regression_test.cjs` covers transition controls, late responses, failed loads and locked edit fields. Receiving, capacity, assignment, downstream exports and tracking UI regressions pass; persistent-browser interaction regression rerun.

Browser: container 726/draft 1133/order 2266 in disposable DB. Planning → To Go persisted. Simulated stale UI enabled On Route before finalization; real button action received HTTP 409. UI finalization persisted and returned tracking disabled/no request sent; reopen locked membership/refs/docs. To Go → On Route → Arrived → Available persisted. Order removed from active warehouse but container load retained. Roleless finalize 403; finalized alternate PUT 409. No external delivery or production mutations.

## 6. Orders — PASS

Canonical rules being implemented: standard procurement can change only in Draft/Submitted before any receipt history or reservation; approved procurement/financial identity are protected. A received/voided order is copied to a new Draft for changed procurement, preserving the original receipt history. Rich draft orders use their builder. Creation keys are required and bound to actor/type/payload; updates require matching lock_version. Numbers must be finite, within storage range, non-negative, with integer cartons and coherent cartons × packing = quantity. Packed piece counts must not be labeled as carton counts. References, dates, pricing currency and item identity must remain consistent.

Changes underway: new OrderWriteService; standard and builder creation request hashes recorded with audit; strict standard inputs/dates and builder raw numeric validation; stable standard item IDs preserve design/classification links; scoped removal/re-reservation; approved/received design attachment mutation guards; fresh copy numbering and bilingual description preservation; unified locked legacy convert/migrate path with number reservations. Cargo read/shipping projection derives piece units for legacy carton-labeled packed piece quantities without rewriting historical rows.

Current evidence: new orders_domain_test.php passes creation/replay, invalid numbers/packing/dates, version conflicts, item/design retention, currency edit, approved protection, concurrent standard/builder creation, alternate endpoint protection, and concurrent legacy conversion paths. Existing draft_order_builder_test.php: 28/28 pass; numbering: 11/11. Test harness supplies newly mandatory keys and uses valid future timestamps (2098 exceeded MySQL TIMESTAMP range). Sandbox missing item_types/draft_order_cost_types/customer_country_shipping references were added without copying operational data.

Browser in progress: copied order 1930 → new standard order 2769, item 3658, independent number ASSIGNMENT-QA-1-1, 10 pieces/2 cartons/0.2 CBM/20 kg. Real UI edit retained item ID, changed currency RMB and sell total to 110. Concurrent API edit won; stale UI edit returned 409. Current browser is still on the stale order edit form and must be closed/reopened to verify winner persistence. No external actions.

Completed verification: legacy CRUD revision and state guards, supplier/item validation, unified country/currency conversion, cross-customer creation-key race (one persisted order), failed edit audit rollback (header/items/number reservations), linked financial identity, cost payload/version/range checks, malformed cost input and concurrent archive (one history entry). `orders_domain_test.php` and new `orders_ui_regression_test.cjs` pass. Full bilingual descriptions, item IDs, piece quantities, pricing and distinct card metadata survive serialization; copy uses fresh numbering. Cargo and warehouse read units use the same projection. Builder suite 28/28 and downstream CSV/XLSX/warehouse suites pass.

Browser completion: standard order 2769 reopened with winning date 2026-10-04, 110 RMB and retained item 3658 after stale edit rejection. Draft 2867 changed expected date to 2026-10-05 through UI, persisted after reload. Linked-cost currency change rejected HTTP 409; precise server reason now appears in validation summary/toast; reopened USD and saved date remained intact. Roleless HTTP standard create/update, builder update, legacy create and cost create each returned 403. No real financial/external action occurred. Legacy creation keys without a recorded payload hash cannot be safely replayed and return a conflict directing users to the existing record. Historical received/voided procurement is preserved; changed procurement uses a new copied Draft. Domains 1/2 reconciliation limitation remains.

## 7. Containers — PASS

Invariant: unique container identity, valid schedule/destination and bounded capacity; all edits honor the state/membership guards. Distinct canonical cargo drives detail/list/export counts, quantities and physical totals, preserving unknowns and currency boundaries. Concurrent retries/edits must not silently overwrite or duplicate records. Authorized operational reads and writes must match UI controls.

Fixed: `ContainerWriteService` validates text lengths, real calendar dates, schedule ordering and recognized destinations; assigned cargo prevents incompatible country changes. Header revisions protect both editors and status updates, with stale/failed response guards. Required creation keys bind actor and canonical payload; retries serialize and still return the same container after rename. Creation/update audit is transactional. Direct handler permissions added. Detail preserves unknown quantities/measurements and six-decimal CBM/four-decimal weight, and separates monetary totals by currency. CSV includes currency and neutralizes spreadsheet formulas; finalized refs disabled in container detail. Container filters validate input and use shared literal wildcard escaping.

Tests: `containers_domain_test.php` covers concurrent create/edit, request payload conflict, rename/replay, invalid dates/capacity/country/code, missing revision/key, audit rollback, mixed currencies, unknown quantity, assigned destination guard, filters/search/pagination and roleless direct calls. Capacity/state and CSV/XLSX regressions pass. Shared search change was checked against warehouse, receiving and all 28 builder cases. UI regression covers delayed editor responses and failed loads; persistent-browser interaction suite rerun.

Browser: disposable container 1137 ETA edited to 2026-10-26 and persisted after reload; ETA before ship date rejected 422. Created 1161 `SG-BEY-OCT-2026-QA` using Consolidation form, reopened after reload, then alternate editor saved Lebanon/Beirut Port/ETA 2026-10-28 and reopened with identical values. No external actions or source cargo mutations.

## 8. Shipments/tracking — implementation verified; remote integration BLOCKED

Invariant: shipment creation and metadata changes are authorized, repeatable and auditable; finalized membership and payload identity remain immutable. Tracking is a separate delivery state, with bounded validated transport, stable idempotency/payload across retries, no duplicate concurrent delivery and explicit local-versus-remote outcomes. Test payload generation and transport fakes only; no real push.

Fixed: `ShipmentWriteService` enforces creation keys, transactional creation audit, reference revisions and bounded safe HTTP(S) links. Document adds deduplicate under draft locks; removal retries are no-ops. Finalization atomically captures the immutable delivery snapshot (zero attempts), including canonical actual cargo, sell pricing/currency, buyer/supplier/destination, carrier refs and shipment documents. Tracking sends the exact stored bytes/key on every retry; success remains terminal. Claimed concurrent pushes return 409, while local finalization remains successful if another delivery is running. Pending snapshots remain retryable after a process interruption. HTTP acceptance must be verifiable JSON, retries/timeouts/backoff/response size are bounded, protocols and redirects constrained, and diagnostic file failures cannot cause resends. Shipment and tracking lists now expose stable pagination/filter metadata and reject malformed input; shipment pickers load all open pages. Direct push/finalize/log permissions enforced; unknown physical totals remain unknown in UI.

Tests: `shipment_domain_test.php`, `tracking_domain_test.php`, `shipment_ui_regression_test.cjs`, 16 tracking UI checks, state/assignment/downstream suites and persistent browser interaction regression pass. Covers concurrent create/ref edit/document add, deleted-document replay, audit rollback, unsafe URLs/paths, frozen payload across mutable legacy changes, same bytes/key on 503→accepted retry, timeout/429 bounds, HTML/empty/rejected responses, protocol rejection, successful replay skip, permission denial and filters/pagination. Transport fakes override the network and file logger; no real socket or tracking push.

Browser: draft 1861 booking reference saved/reopened; javascript URL rejected 422. Finalized draft 1863 searched; real Retry Push returned disabled/no send and persisted after reload (log 112, zero attempts). Created shipment 1889 through UI; uploaded document 19, reopened to verify it, removed it and reopened to verify absence. Generated upload `uploads/20260919_145153_08a51cb4.png` is recorded in `artifacts/cargo-demo/qa-shipment-upload-paths.json` for cleanup. Cherry correctly gets 403 for administrative tracking logs. Admin list rendering/filter/race behavior has DOM regression coverage, but no authorized admin browser session was substituted for Cherry.

LIMITATIONS: remote endpoint contract, cross-instance idempotency namespace and remote exactly-once acceptance cannot be proven without an isolated integration endpoint. No live delivery was attempted. Network/TLS behavior is constrained in code and transport semantics tested with fakes, not an external server. Existing successful logs remain untouched; existing invalid/missing legacy snapshot data requires reconciliation. Source historical cargo reconciliation from domains 1/2 remains outstanding.

## 9. Buyers/customers — PASS

Invariant: unique buyer identity, authorized ownership and financial access, valid contact/country/shipping metadata, atomic versioned writes and imports; existing cargo, numbering, balances and immutable tracking snapshots must retain their relationships when customer details change.

Fixed: customer create keys/revision checks, shared bounded metadata/country/email validation, serialized duplicate policy, atomic strict UTF-8 CSV batches with quoted multiline support, transactional audit and history-protected deletion. Direct receiving intake uses the same metadata rules and an atomic unique-code fallback. Both deposit paths share positive bounded decimal/currency/order-owner validation, serialize order/customer locks, and protect retries with transactional audits. Unified financial history excludes linked deposit duplicates. Financial identity now also recognizes legacy deposits/payments. Customer portal tokens consume atomically; portal physical totals accumulate active receipts. Attachment writes serialize/deduplicate with ownership and transactional audit; internal messages validate buyer/order/container relationships and retry safely. UI uses safe name arguments, independent finance permissions, stable request keys, stale response guards and complete customer order pagination. The deposit summary is labeled as deposits rather than net balance.

Tests: `customers_domain_test.php` covers concurrent create/edit/import/deposit/message/attachment/token use, malformed CSV and metadata, partial-update preservation, cross-buyer rejection, no duplicate ledger totals, failed audit rollback, roleless direct calls, cumulative portal totals and preserved historic cargo identifiers. `customers_ui_regression_test.cjs`, orders regression, 242 capability assertions and 11 financial checks pass.

Browser: buyer 183 edit saved/reopened; competing edit rejected stale form HTTP 409. UI created buyer 191 `BCF-BEY-OCT26`, recorded a local 240 USD deposit in the disposable database, then reopened its history with exactly that entry. CSV created `HARBOUR-BEY-OCT26` and survived reload. UI generated a one-time portal link; first visit identified the buyer and reload correctly rejected reuse. No actual payment, notification or external delivery occurred.

File-delivery defect found and fixed before closing this domain: originals and thumbnails bypassed authentication. Shared `UploadAccessService` now protects both, enforces passport ownership (including removal history), and permits customer receipt photos only through a matching active review token. Apache routes existing upload URLs through `backend/media.php`; thumbnail responses and originals are private/no-store. Upload writes require explicit workflow permission. `media_access_test.php` covers anonymous/roleless denial, receipt-token scope, void revocation and passport tombstones. HTTP checks against both isolated routing and real Apache returned 401 anonymously; Cherry originals/thumbnails return 200 with private/no-store. Receipt review image URLs carry their existing scoped token. Page capability suite now has 269 assertions. Remote tracking consumers of uploaded images need the isolated integration verification already recorded in domain 8; no public-file assumption is retained.

## 10. Imports — implementation verified; deployment/legacy measurement limitations

Invariant: imports share interactive validation, permissions and identity rules. Persisted batches are atomic and retries cannot duplicate records. Previews must not silently coerce malformed values or truncate worksheets. Measurement scope is an order snapshot, independent of subsequent product edits.

Fixed: shared product/supplier validation and atomic master imports; strict multiline UTF-8 CSV; required product classification; workbook size/expansion/external-link guards; cached formula values only; row/column overflow rejection. Draft previews deduplicate image bytes and clean newly created files on failure. HS catalog prevalidation prevents empty/malformed/duplicate replacement; serialized transactional replacement preserves translations and rolls back on audit failure. Classic CSV preserves bilingual text, packing and measurements; templates preserve scope/customer pricing/full descriptions, clear buyer numbering and replay safely. Migration 082 snapshots order measurement scope across standard, builder and direct receiving writes. Edit/copy/export consumers prefer that snapshot. UI errors persist, keys follow payload changes, totals derive from numeric state and fast preview completion closes its guide before opening the builder.

Tests: imports_domain_test.php verifies concurrent master/template/catalog retries, duplicate handling, atomic rollback, permissions, full template metadata, historical scope after product edits, malformed CSV/numbers and cached/external XLSX handling. imports_ui_regression_test.cjs covers strict classic CSV, totals, retry keys and the modal transition race. Orders/receiving/downstream regressions, 28 builder tests and 270 capability assertions pass.

Browser: product 1260 imported, negative weight rejected 422, reload retained 0.08 CBM/12 kg/24 packing/Normal classification. Standard CSV → template 8 → order 3779 persisted 12 pieces/0.24 CBM/16 kg with fresh BCF-BEY-OCT26-1-1 numbering; invalid numeric CSV left its single card unchanged. Builder CSV → order 3780 persisted 36 pieces/0.12 CBM/21 kg/234 RMB after reopening. Malformed upload rejected; corrected retry left one visible dialog. Supplier NB-COAST-OCT26 imported and found after reload. Customer and receiving import UI coverage is recorded in domains 9 and 1.

LIMITATIONS: migration 082 applied only to disposable QA; deployment must apply it before enabling the changed writes. Legacy measurement scope was never recorded and remains NULL with the existing product fallback; history cannot be reconstructed without evidence. Cherry is correctly denied catalog administration; catalog writes have backend regression coverage but no authorized SuperAdmin browser verification. Retention of successfully previewed but abandoned uploads remains part of the global upload lifecycle review. No source catalog or external service changed.

## 11. Exports/reports — PASS

Invariant: procurement exports preserve declared quantities/measurements; receiving, warehouse, container and operational summaries use canonical active cargo. Unknown history remains unknown. Currency totals stay separate; CSV/XLSX cells are inert data. Filtered exports include the complete authorized result, selected exports include exactly the validated IDs, and download reads do not mutate procurement or invoke translation services.

Fixed: safe explicit string cells in XLSX and shared inert CSV writer; exact declared procurement totals and canonical actual operational totals; four-decimal financial values; aligned draft subtotal columns; complete filtered and validated selected downloads; snapshot reads without translation writes; selected IDs survive repaint. Dashboard pending feedback obeys valid states and latest receipt timestamps. Legacy procurement converts catalog carton values to per-piece values consistently in detail, print, CSV/XLSX and conversion, rejecting missing packing. Order/builder round-trips retain unrounded per-unit values until totals; aggregate UI totals use numeric state. Product backfill uses catalog packing/basis and preserves full order descriptions without overflowing catalog fields.

Tests: exports_domain_test.php and exports_ui_regression_test.cjs cover exact CSV/XLSX values, malicious formula text, complete filtered exports, selected identity validation, permissions, immutable downloads, financial precision, unknown legacy cargo refusal, legacy carton conversion and edit round-trip. Orders/import suites and 28 builder cases pass; UI regression covers numeric summaries and per-unit precision. Browser downloaded filtered orders, individual and selected orders, and a filtered financial transaction retaining 1234.5678. Stale selected ID returned 404 and no file. Print view reopened with matching totals. Converted order 3912 saved through UI and reopened after reload at 0.333333 CBM / 33.3333 kg. Existing historical measurement and external-integration limitations remain documented above.

## 12. Filters/search/pagination — PASS

Invariant: list/detail/export filters share validated predicates and authorization; literal user search cannot expand through wildcard characters. Stable ordering and complete metadata prevent missing/duplicate pages; delayed responses cannot repaint a newer search. Invalid dates, IDs and enumerations fail explicitly.

Fixed: shared QueryFilterService validates scalar/list shapes, bounded IDs/paging, real dates, ranges and enums across operational list/export paths. Searches escape literal percent/underscore consistently. Supplier filtering includes contained shared-carton suppliers before pagination; supplier payment predicates no longer use invalid correlated derived tables. Pending review filters obey review states. Stable tie ordering, last-page recovery and stale-response/error guards protect list views. Calendar/confirmations fetch all pages; pipeline container summaries include every page. Calendar timelines sort dates and link exact records. Filtered message reads update only returned messages.

Tests: filters_domain_test.php verifies malformed inputs across 11 resources, enum rejection, literal searches, shared suppliers, stable page totals, out-of-range pages, supplier payment filters and scoped message-read effects. filters_ui_regression_test.cjs verifies delayed response/error suppression, complete deduplicated pages, stalled-page failure and date sorting. Export/warehouse/container/shipment/import/order regressions and 28 builder cases pass. Browser literal search returned one exact record after reload; next order page had 50 distinct records with no overlap; reversed dates returned 422 with visible error and no unhandled exception after fix; clear recovered. October calendar contains 52 orders (more than one API page), sorted by date, with a working exact-order link.

LIMITATION: operational lists are live offset-based views; membership may change between separately fetched pages. Server-side mutations revalidate/lock canonical records, and exports use a single consistent transaction snapshot. Stable snapshots across multiple browser requests would require a separate pagination contract.

## 13. Authorization/security - implementation verified; historical ownership limitation

Invariant: every entry point authenticates active users and applies live permissions, ownership, record locks and upload protections. Invalid identities, alternate endpoints and cross-origin writes cannot bypass those rules.

Fixed: centralized API authorization is applied by the router and every handler; active-account and role checks refresh from the database. Alternate classification/design writes share procurement locks. IDs, JSON shapes and credential inputs are validated. Login/logout forms use session-bound CSRF tokens; cookies use strict mode/HttpOnly/SameSite. Production debug output is disabled and API 5xx details are logged privately. Migration 083 records upload ownership/content hashes; pending files are owner-only and customer attachments remain private through reuse and workbook embedding. Image dimensions and remote image transport are bounded; remote images require DNS-pinned cURL.

Tests: security_domain_test.php covers every private handler anonymously and with a disabled forged-admin session, roleless mutations, malformed IDs, creator restrictions, explicit shared-page visibility, locked classification, pending uploads, passport reuse and workbook bypasses. security_http_test.cjs verifies cookies, JSON/path validation, cross-origin rejection and login/logout tokens. Browser Cherry direct admin access is denied; malformed image returns 400, valid image saves to QA product 1260 and reload/reopen renders the protected thumbnail.

LIMITATIONS: existing customer page grants intentionally confer shared customer visibility; creator isolation applies to narrower action grants. This is not tenant isolation. Legacy uploads have no trustworthy owner manifest; historical operational visibility is retained with private attachment history enforced. Migration 083 is QA-only. Successful abandoned previews need a retention policy. Authorized administrator UI verification was unavailable.

## 14. Concurrency/idempotency - operational regression verified; limitations remain

Invariant: the same actor/payload operation produces one committed result; retries cannot duplicate mutations and versioned edits reject stale forms. Business data and audit records share transactions and failures roll back.

Fixed: direct/unified supplier payments share exact decimal validation, shared-carton ownership checks and consistent order/supplier locks. Direct payments have actor/payload replay keys and transactional audit. Expenses have operation keys, revisions, strict dates/currencies/relationships and atomic audit; repeated deletion is safe. Expense lists retain four-decimal totals, complete pagination and stale-response guards. Preferences no longer write on GET; saves validate flags/duplicates, serialize on the user, reject stale edits, replay identical saves and preserve mandatory dashboard delivery. Cost/classification services own transactions when called independently. Notification reads retain the first timestamp.

Tests: concurrency_domain_test.php covers parallel payment/ledger retries, exact settlement and deduplication, malformed values, shared supplier membership, parallel preference reads/saves, stale values, parallel expense creation/edit/deletion, filtered totals and injected audit failures. concurrency_ui_regression_test.cjs covers double clicks and lost responses. Browser lost a payment response after commit, then retry returned the same payment 57; reload/history showed one 25 RMB payment and 5 RMB settlement. Expense 73 retained 18.1234 USD after reload and rejected a stale form with 409. Prior domain suites cover receiving, capacity, assignment, finalization, shipments, imports and tracking retries.

LIMITATIONS: remote acceptance remains an integration gate. Cherry cannot perform the administrative preference UI workflow. Ancillary catalog/admin metadata editors retain last-writer behavior where no revision contract exists; full optimistic cross-tab protection is not claimed.

## 15. Audit logging - implementation verified; restricted browser limitation

Invariant: consequential mutations record actor, target, action and committed before/after state in the same transaction; failures and retries cannot fabricate successful history. Authorized audit reads are stable and redact secrets.

Fixed: AuditService enforces transactional durable history and recursive secret redaction. Product/supplier creation, edits and deletion, supplier interactions, user roles/departments, sidebar policy, business settings and configuration now record before/after data atomically. Config extension validation no longer overwrites its setting allowlist; write responses mask tokens. Audit filters are validated, timestamp ties use ID ordering and historical secrets are redacted. The viewer guards delayed requests and duplicate pagination, escapes labels and opens exact orders. Production training reset is blocked before mutation and hidden in the UI.

Tests: audit_domain_test.php verifies actor/before/after records, product/supplier/config rollback on audit failure, config allowlist behavior, secret masking, user role snapshots, historical redaction, stable pages and production reset rejection. audit_ui_regression_test.cjs covers stale results/errors, duplicate loads, escaping and exact order links. Cherry's real browser receives Access Denied for administrative audit and returns to the operational page.

LIMITATIONS: populated administrator audit UI was not verified with an authorized account. Historical missing audit records cannot be reconstructed. Database-administrator tamper resistance and off-host immutable retention require deployment evidence.

## 16. Full cross-domain regression - tests pass; production gate BLOCKED

27 PHP suites passed: receiving quantity/domain, warehouse, capacity, downstream cargo, assignment, states, orders, containers, shipments, tracking (fake transport), customers, media access, imports, exports, filters, security, concurrency, audit, builder, financial, page capability, numbering, packing numbers, production critical audit, runtime hardening and release preflight. Includes 28 builder cases and 272 permission assertions. Thirteen JavaScript suites passed. Syntax checked 126 PHP and 42 JavaScript files with no errors. Two initial failures were obsolete lock-message matching and duplicate-product fixture reuse; tests now verify persisted state and explicitly declare the disposable product fixture.

Persistent headed Chromium: assignment repaint/search/suggestion and autocomplete interaction regressions pass. Independent HTTP security checks pass without replacing Cherry's cookies. Final container 368 reopened with 2 orders, 2 items, 20 cartons, 200 units, 1/2 CBM and 100/100 kg; order 1557 shows 100 received, 0.6 CBM, 60 kg and the same container reference. These are QA fixtures, not a resumed demo.

Read-only SOURCE preflight is BLOCKED: migrations 082/083 are not deployed, historical receipts and measurement basis require reconciliation, active historical seed-password accounts require rotation, legacy item-number conflicts need an approved cleanup map, and backup/restore evidence is missing. Translation provider/classification review and remote tracking integration remain unverified. Original five demo orders remain present. No real notifications, tracking pushes or financial external actions occurred.

Cleanup completed: disposable database dropped; QA server stopped; three tracked uploads and three thumbnail derivatives removed; temporary reader session and PHP inspection scripts removed. Harmless QA evidence remains under ignored, web-blocked artifacts. Cherry's persistent headed browser remains on the source Containers list with no modal or error. Source operational records and configuration were not changed by committed test writes.

Production result: NOT READY. Passing isolated regressions does not resolve historical or deployment gates above.

## Modified files grouped by domain

Shared files may affect additional domains; each path is listed once under its main responsibility. Includes the existing in-progress hardening diff retained at Phase 0; no unrelated changes were reverted.

### 1 Receiving

- `backend/api/handlers/confirm.php`
- `backend/api/handlers/receiving.php`
- `backend/services/NotificationService.php`
- `backend/services/OrderReceiptWorkflowService.php`
- `backend/services/OrderReceivingService.php`
- `backend/services/ReceivingExcelImportService.php`
- `backend/services/ReceivingQuantityService.php`
- `confirm.php`
- `docs/receiving-downstream-review.md`
- `frontend/js/receiving.js`
- `frontend/js/receiving_index.js`
- `frontend/js/receiving_receipt.js`
- `frontend/js/receiving_receive.js`
- `tests/receiving_domain_test.php`
- `tests/receiving_quantity_test.php`
- `tests/receiving_ui_regression_test.cjs`
- `warehouse/receiving/index.php`
- `warehouse/receiving/receipt.php`
- `warehouse/receiving/receive.php`

### 2 Warehouse stock

- `backend/api/handlers/warehouse-stock.php`
- `backend/services/CargoMetricsService.php`
- `frontend/js/warehouse_stock.js`
- `tests/warehouse_domain_test.php`
- `tests/warehouse_ui_regression_test.cjs`
- `warehouse_stock.php`

### 3 Container capacity

- `backend/services/ContainerCapacityService.php`
- `tests/capacity_ui_regression_test.cjs`
- `tests/container_capacity_test.php`

### 4 Assignment

- `backend/services/ShipmentAssignmentService.php`
- `consolidation.php`
- `frontend/js/assign_container.js`
- `frontend/js/autocomplete.js`
- `frontend/js/consolidation.js`
- `tests/assignment_domain_test.php`
- `tests/consolidation_interaction_test.cjs`

### 5 State transitions

- `backend/services/CargoStateService.php`
- `backend/services/OrderStateService.php`
- `tests/state_domain_test.php`
- `tests/state_ui_regression_test.cjs`

### 6 Orders

- `backend/api/handlers/draft-order-costs.php`
- `backend/api/handlers/draft-orders.php`
- `backend/api/handlers/order-templates.php`
- `backend/api/handlers/orders.php`
- `backend/api/handlers/procurement-drafts.php`
- `backend/services/DraftOrderCostService.php`
- `backend/services/LegacyProcurementMetricsService.php`
- `backend/services/OrderWriteService.php`
- `frontend/js/orders.js`
- `frontend/js/procurement_drafts.js`
- `procurement_draft_print.php`
- `tests/draft_order_builder_test.php`
- `tests/orders_domain_test.php`
- `tests/orders_ui_regression_test.cjs`

### 7 Containers

- `backend/api/handlers/containers.php`
- `backend/services/ContainerWriteService.php`
- `containers.php`
- `frontend/js/containers.js`
- `tests/containers_domain_test.php`

### 8 Shipments/tracking

- `admin_tracking_push.php`
- `backend/api/handlers/shipment-drafts.php`
- `backend/api/handlers/tracking-push-log.php`
- `backend/services/ShipmentWriteService.php`
- `backend/services/TrackingPushService.php`
- `frontend/js/admin_tracking_push.js`
- `tests/shipment_domain_test.php`
- `tests/shipment_ui_regression_test.cjs`
- `tests/tracking_domain_test.php`
- `tests/tracking_push_ui_test.cjs`

### 9 Buyers/customers

- `backend/api/handlers/customer-portal-tokens.php`
- `backend/api/handlers/customers.php`
- `backend/api/handlers/design-attachments.php`
- `backend/api/handlers/internal-messages.php`
- `backend/services/CustomerDepositService.php`
- `backend/services/CustomerPortalService.php`
- `backend/services/CustomerWriteService.php`
- `customer_portal.php`
- `customers.php`
- `frontend/js/customers.js`
- `tests/customers_domain_test.php`
- `tests/customers_ui_regression_test.cjs`

### 10 Imports and shared master validation

- `backend/api/handlers/hs-code-catalog.php`
- `backend/migrations/082_order_template_metrics.sql`
- `backend/services/CsvTableService.php`
- `backend/services/HsCatalogImportService.php`
- `backend/services/MasterDataImportService.php`
- `backend/services/ProductWriteService.php`
- `backend/services/SupplierWriteService.php`
- `backend/services/WorkbookImportGuard.php`
- `tests/imports_domain_test.php`
- `tests/imports_ui_regression_test.cjs`

### 11 Exports/reports and ledger projections

- `backend/api/handlers/balances.php`
- `backend/api/handlers/financials.php`
- `backend/services/OrderExcelService.php`
- `frontend/js/balances.js`
- `frontend/js/bulk_excel_download.js`
- `frontend/js/financials.js`
- `tests/exports_domain_test.php`
- `tests/exports_ui_regression_test.cjs`

### 12 Filters/search/pagination

- `backend/api/handlers/dashboard.php`
- `backend/services/QueryFilterService.php`
- `frontend/js/calendar.js`
- `frontend/js/confirmations.js`
- `frontend/js/pipeline.js`
- `tests/filters_domain_test.php`
- `tests/filters_ui_regression_test.cjs`

### 13 Authorization/security and shared request infrastructure

- `.htaccess`
- `backend/api/authorization.php`
- `backend/api/handlers/auth.php`
- `backend/api/handlers/countries.php`
- `backend/api/handlers/departments.php`
- `backend/api/handlers/diagnostics.php`
- `backend/api/handlers/hs-code-tax.php`
- `backend/api/handlers/item-classifications.php`
- `backend/api/handlers/notifications.php`
- `backend/api/handlers/roles.php`
- `backend/api/handlers/translate.php`
- `backend/api/handlers/translations.php`
- `backend/api/handlers/upload.php`
- `backend/api/helpers.php`
- `backend/api/index.php`
- `backend/config/runtime.php`
- `backend/media.php`
- `backend/migrations/083_upload_asset_ownership.sql`
- `backend/services/UploadAccessService.php`
- `backend/thumb.php`
- `frontend/js/admin_diagnostics.js`
- `frontend/js/app.js`
- `includes/area_bootstrap.php`
- `includes/auth_check.php`
- `includes/layout.php`
- `includes/page_capabilities.php`
- `includes/page_guard.php`
- `includes/session_roles.php`
- `login.php`
- `tests/media_access_test.php`
- `tests/security_domain_test.php`
- `tests/security_http_test.cjs`
- `tests/support/container_test_handler.php`

### 14 Concurrency/idempotency

- `backend/api/handlers/expenses.php`
- `backend/api/handlers/notification-preferences.php`
- `backend/services/ExpenseWriteService.php`
- `backend/services/OperationReplayService.php`
- `backend/services/SupplierPaymentService.php`
- `expenses.php`
- `frontend/js/expenses.js`
- `frontend/js/notification_preferences.js`
- `tests/concurrency_domain_test.php`
- `tests/concurrency_ui_regression_test.cjs`

### 15 Audit and consequential metadata writes

- `admin_config.php`
- `backend/api/handlers/audit-log.php`
- `backend/api/handlers/business-settings.php`
- `backend/api/handlers/config.php`
- `backend/api/handlers/products.php`
- `backend/api/handlers/suppliers.php`
- `backend/api/handlers/users.php`
- `backend/services/AuditService.php`
- `backend/services/ItemClassificationService.php`
- `backend/services/TrainingDataResetService.php`
- `frontend/js/admin_audit_log.js`
- `frontend/js/admin_config.js`
- `frontend/js/products.js`
- `frontend/js/suppliers.js`
- `products.php`
- `tests/audit_domain_test.php`
- `tests/audit_ui_regression_test.cjs`
- `tests/production_critical_audit_test.php`

### 16 Cross-domain regression/release gate

- `backend/services/ProductionReleasePreflightService.php`
- `docs/production-hardening.md`
- `tests/cargo_downstream_regression_test.php`
- `tests/production_hardening_test.php`
- `tests/production_release_preflight_test.php`
- `tests/support/hardening_router.php`
- `tests/support/hardening_sandbox.php`
