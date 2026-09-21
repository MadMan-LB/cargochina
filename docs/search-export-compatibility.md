# Search and identifier export fix — 2026-09-21

Scope: the reported production Orders search failures, page-authorized lookups, and both item identifiers in downloads. No demo, broad hardening, data renumbering, schema migration or external delivery.

## Root cause and downstream changes

- Production evidence provided by Houssein: MySQL **5.5.62-log**. Shared-carton searches called `JSON_VALID`/`JSON_SEARCH`, unavailable there. The same predicate fed Orders list/autocomplete, receiving lookup/queue, draft lists, warehouse stock and container content search. Shared-carton supplier filtering also used unsupported JSON functions.
- Search now selects a compatible path by database version. Modern servers retain native JSON predicates. Legacy servers decode shared-carton references in PHP and apply matching item IDs inside the original SQL query. Invalid historical JSON is ignored. Literal `%`, `_`, backslashes, Unicode, leading zeros and repeated references are covered. No persisted cache, DB changes, post-pagination filtering or permission bypass is introduced.
- Supplier filtering uses the same compatibility decision and recognizes both numeric and legacy string supplier IDs. Parent supplier matching remains unchanged.
- Reproduced a second failure locally: container list and picker returned HTTP 500 / MySQL **1253**, applying an utf8mb4 collation directly to legacy utf8 columns. Both now use one search predicate with explicit charset conversion. Container selection in assignment, consolidation and expenses inherits this fix. The list and picker now agree on container code/ID/notes, customer details, order ID and ordinary/contained item identifiers. Existing capacity/status/assignment checks remain unchanged.
- Warehouse search now treats the literal `0` as a real search rather than an empty filter.
- Page-capability review covered the operational autocomplete dependencies. Added the missing product lookup grant for **HS Code Tax** page access. Page access already grants the other normal lookup dependencies; administrative pages, mutations and record eligibility remain protected. Five operational roles are tested against the page/dependency matrix.
- Orders-list and receiving-queue CSV/XLSX now have separate **I.I.N** (`item_no`) and **Item Number** (`item_number`) columns. Receipt CSV/XLSX now include them too. Receiving's query now actually selects both fields and retains contained identifiers for reporting.
- Existing single-order, selected-order, draft, warehouse and container exports already have both columns. Downloads do not reserve, renumber or reject repeated identifiers. Added tests for repeated *automatic* identifiers as well as manual references. Receiving/summary reports retain contained references without adding cargo/financial rows.
- Numeric-looking identifiers remain Excel text; formula-looking content stays inert. Canonical actual/declared quantities, CBM, weight, costs, statuses, import disambiguation and numbering rules are unchanged. Ambiguous imports still require an I.I.N/item identity; permission and unreconciled-cargo protections remain active.

## Verification

Targeted checks only:

- `legacy_shared_carton_search_test.php`: **118 assertions**, real SQL over connection-local temporary fixtures; exercises version-selected MySQL 5.5/5.6 fallback and modern MySQL/MariaDB paths, invalid JSON, literals, duplicate references, supplier filters, outer restrictions and pagination.
- `packing_list_item_number_test.php`: **27 assertions**.
- `packing_list_item_number_exports_test.php`: **21 assertions**, including repeated I.I.N values, manual references, leading zeros, shared contents, formula safety and receiving import disambiguation.
- `page_capabilities_test.php`: **577 assertions**; page lookup grants across five operational roles, revoked-page denial and no unintended administrator/write access.
- `filters_domain_test.php`: PASS — affected list/filter execution, shared suppliers, totals/counts/pagination, compact/full projection parity, malformed input rejection, container list/picker charset/identifier parity and literal-zero warehouse filtering.
- `exports_domain_test.php`: PASS — filtered CSV/XLSX, both identifiers in new report columns, contained duplicates, canonical totals, permissions, no download renumbering/translation, import/conversion measurement consistency.
- JS: `exports_ui_regression_test.cjs`, `filters_ui_regression_test.cjs`, `receiving_ui_regression_test.cjs`, `warehouse_ui_regression_test.cjs`, `consolidation_interaction_test.cjs`: PASS. Includes stale responses, double downloads, selection across filtering/pagination, failed-download cleanup, receiving/warehouse behavior and picker pointer/keyboard interactions.
- Changed PHP syntax: **14 files PASS**.
- Headed Chromium, local Cherry account: `tv` returns five Orders; automatic identifier search returns four Orders; filtered list XLSX and selected four-order XLSX downloaded and reopened successfully. All four identical stored I.I.N values remain present, with separate manual numbers. Warehouse manual-reference search returns the matching cargo; supplier autocomplete returns HTTP 200 under page access.
- Headed Chromium: container list and suggestions changed from HTTP 500 to **200** for `SG-CHN-2402`, returning the same container with four orders, 29.1 CBM and 4,090 kg. Its downloaded XLSX was reopened and retained all four repeated I.I.Ns and four entered references. Literal/unmatched search returns a clean empty result, not an error. Finished on the clean five-result Orders search. Browser automation was recovered after tool timeouts; no application success was inferred from those timeouts.

Tests use a disposable database or connection-local temporary tables. No production records were modified. The disposable database is removed after verification.

## Production deployment / limits

Deploy these **nine application files together**; they share the updated predicate/parameter contract:

1. `backend/services/PackingListItemNumber.php`
2. `backend/api/helpers.php`
3. `backend/api/handlers/orders.php`
4. `backend/api/handlers/draft-orders.php`
5. `backend/api/handlers/receiving.php`
6. `backend/api/handlers/warehouse-stock.php`
7. `backend/api/handlers/containers.php`
8. `backend/services/OrderExcelService.php`
9. `includes/page_capabilities.php`

**No SQL migration or SUPER privilege is required for this fix.** Do not deploy a handler without the corresponding shared helpers. Normal deployment should invalidate server PHP opcode caches if required by hosting configuration.

Local execution uses MariaDB 10.4.32. Legacy predicates are exercised through version-reporting adapters and executed as real SQL locally; an actual MySQL 5.5 production smoke check remains necessary after deployment. Production was not changed or accessed, and its screenshot error references were not correlated with server logs. This change does not claim compatibility of unrelated MySQL-JSON write/integration paths or close unrelated release-readiness gates.
