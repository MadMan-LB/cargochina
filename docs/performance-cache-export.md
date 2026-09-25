# Performance, cache and export revision ? 2026-09-25

Scope: authenticated freshness, public static assets, measured list-query duplication and workbook image reuse. No schema changes or production deployment performed.

## QA inventory
- Content-versioned shared/page assets: inspect rendered URLs, Apache headers, warm navigation, and changed-content/same-mtime regression.
- Operational reads/errors/permissions: HTTP no-store, repeated reads across mutations and denied/recovered requests; no response cache in the shared client.
- Supplier create/search, delete/restore: visible browser save and immediate filtered list, plus current server permission gates.
- Draft ? order ? receipt ? warehouse ? assignment ? container: targeted canonical service/API tests; headed Orders, Warehouse and Container inspection with freshness after assignment.
- Customer/procurement pagination, filters, revisions and classification: batched output equals original response and per-record canonical reads.
- Calendar/recycle bin: visible range/filter loading and restore freshness, plus unauthorized/filter/range tests.
- XLSX/CSV: canonical quantities, precision, both item identifiers, duplicates, Unicode, filtered scope, permissions, MIME/filename, actual reopened workbook and embedded media/anchors.
- Image failures: missing/corrupt candidate fallback; changed image at the same path with preserved mtime and reused service.
- Separate headed visual pass: readable list/results, modal closure, no overflow/error; exploratory empty search and denied resource.


## Runtime and measurement scope

Local: PHP 8.2.12, PDO/mysqlnd, MariaDB 10.4, Apache 2.4.58 for real static headers; isolated PHP HTTP server for authenticated Chromium QA. Disposable DB `clms_hardening_20260919`; external delivery disabled. Production target remains MySQL 5.5.62/Antelope and the existing PHP baseline in `production-compatibility.md`. No migrations, indexes, dependency upgrades, server setting changes or production data writes. The production nginx/PHP configuration was not accessible for live verification.

Three fresh PHP processes per endpoint, median handler wall time excluding process startup, identical database before/after. Query count uses MySQL session `Questions`; response byte count is uncompressed JSON. All response hashes matched exactly, including child ordering, revisions, identifiers, totals, metadata and permission-dependent fields. Fixtures: 60 new draft orders ? 3 items, 60 procurement drafts ? 3 items, copied customer/supplier reference data. Receiving, Warehouse, Containers and Recycle Bin were initially empty; their numbers are overhead only, not production load claims.

| API | Before ms | After ms | Queries before ? after | Payload bytes (unchanged) |
|---|---:|---:|---:|---:|
| dashboard/stats | 6.58 | 6.74 | 13 ? 13 | 420 |
| draft-orders | 107.96 | 104.66 | 274 ? 234 | 80,717 |
| orders | 69.74 | 74.53 | 46 ? 46 | 315,918 |
| procurement-drafts | 19.48 | 7.45 | 184 ? 5 | 75,636 |
| receiving/queue | 15.34 | 15.51 | 6 ? 6 | 69 |
| warehouse-stock | 26.61 | 25.41 | 14 ? 14 | 70 |
| customers | 13.54 | 7.80 | 106 ? 8 | 22,825 |
| suppliers | 14.91 | 15.25 | 61 ? 61 | 27,623 |
| containers | 22.25 | 21.58 | 11 ? 11 | 69 |
| calendar | 5.74 | 5.60 | 14 ? 14 | 24,769 |
| recycle-bin | 7.21 | 6.72 | 7 ? 7 | 137 |

Orders above measures the full API payload. The Orders page already requests `view=list`; that existing optimization is preserved. Assignment's actual string-valued `shipment_eligible=1` request was separately measured after workflow fixtures: 85.44 ms, 46 queries, 26,536 bytes, 9 eligible orders. An initial probe sent an integer instead of HTTP's string value; that non-representative assignment result is deliberately excluded from the before/after table. The permanent probe now normalizes scalar query values to strings.

The meaningful list improvements are fewer round trips, not cached data: procurement 184 ? 5 queries, customers 106 ? 8, draft item classifications 274 ? 234. Single-digit timing changes on unchanged endpoints are noise. No claim of improving every page's elapsed time. Draft builder still loads per-order detail/costs, and supplier scores still use per-supplier reads; further restructuring was not justified by this local measurement (about 105 ms and 15 ms respectively). No speculative indexes were added.

Browser: Orders navigation ? populated rows 665 ms on the populated QA database (post-change only). All shared and page-script URLs had content versions. Apache static JS cold ? warm: 2.6 ? 1.4 ms; 41,539 ? 0 transferred bytes, 41,239 decoded bytes. This is browser cache reuse, not an artificial forced-cache fetch.

## Cache inventory and invalidation

| Class | Policy / invalidation |
|---|---|
| Public frontend CSS/JS/fonts/icons/images | Content-versioned URLs: public, one year, immutable. New content creates a new SHA-256 URL even when deployment preserves mtime. Hash memoization lasts one PHP request only. |
| Unversioned frontend files, including relative font/image references inside CSS and standalone pages | Public `no-cache`: browser storage allowed, must revalidate via existing ETag/Last-Modified. No month-long immutable URL without a version. |
| Authenticated HTML | Shared layout explicitly private/no-store. No cached page containing old permissions or stale errors. |
| API / operational data / finance / permissions | No response TTL. Shared helper previously overrode no-store for dashboard, expenses, departments, roles and notifications; it now preserves no-store. Client roles/departments 60-second cache removed. Shared API, autocomplete, assignment lookups and download fetches bypass old HTTP cache entries. |
| Customer/supplier/product lookups | Fresh queries; no persistent list cache introduced. Existing autocomplete abort/request-version handling preserved. |
| Sessions / permissions | Existing per-request role refresh, visibility filtering and request-local schema/permission memoization retained. No cross-user cache. Session locking behavior unchanged. |
| Authorized uploads / UI thumbnails | Existing authorization on every request, private/no-store HTTP headers. Existing on-disk UI thumbnails use path/size/fit/source-mtime keys; uploads use generated filenames, so new upload paths invalidate naturally. No public TTL inherited from the root .htaccess. |
| Excel local thumbnails | Existing source-content hash plus target-size key; resized/letterboxed output rather than large originals. Repeated file drawing metadata now reused only within one workbook, with independent row anchors. All source/context/prototype caches reset for each new workbook, including reused service objects. |
| Excel remote images | Removed indefinite URL-only reuse. Fetch once per workbook through the existing restricted remote downloader, then content-hash the bytes. Source changes are seen on the next export. Failed downloads do not fall back to stale files. Publication uses temporary file + rename. No arbitrary host/path access added. |
| Database/opcode cache | No application query-result cache found in these paths. Local database query_cache_type OFF (configured size 1 MiB). No APCu layer found. No opcode cache settings changed; deployment must use the site's existing PHP reload policy. |
| Service worker / storage | No service-worker registration/cache layer found in frontend/includes. Locale and selected-filter preferences remain local storage, not business snapshots. |

Mutation rules: supplier/customer/product create/edit/delete/restore ? next lookup is a fresh authorized query; receipt save/void ? next order/warehouse read uses canonical receipts; assignment/remove/move ? next order/container/warehouse read recomputes current membership and capacity; permission changes ? next request revalidates current access. Existing UI save handlers reload affected lists; no manual cache clearing is required. Cached errors are never retained. Recycle restore and supplier creation were verified visibly without a browser reload; receive/assign freshness was verified through domain/API checks and headed assignment/warehouse navigation.

## Export completeness and image contract

Retained canonical producers and existing row granularity; no business-row deduplication or new calculated totals. I.I.N and manual Item Number remain separate text columns/summaries. Duplicate manual numbers and shared-carton identifiers remain present; leading zeros survive. Summary reports intentionally summarize an order, while detail exports retain each item. Legacy procurement lines have generated `PD-?-L?` identifiers; fields that do not exist in that legacy source remain blank rather than invented.

| Export family | Verification |
|---|---|
| Draft/normal single order, selected orders, approved/confirmed/shipment/assigned/finalized | Canonical items, bilingual descriptions, both identifiers, dimensions, quantity/cartons, prices, CBM/weight, notes/charges, status/header dates; photos embedded. Selection permission enforced per record. |
| Orders list, receiving queue, warehouse summary | Current filters, full filtered result independent of UI pagination, canonical physical versus declared metrics, both identifier summaries, existing Photo column uses primary candidate. |
| Individual receipt | Receipt-specific actual quantities/cartons/CBM/weight/condition/date, both identifiers. Existing textual receipt report has no Photo column; no new image requirement invented. |
| Container packing list | Current assigned cargo/actual quantities, identifiers and photos; unreconciled quantities still reject export instead of inventing values. |
| Shared cartons | Existing summary/content structure and auxiliary identifier sheet retained; repeated identifiers preserved; explicit/product/receipt fallback images checked. |
| Legacy procurement | Existing quantity basis preserved through CSV/XLSX ? import/conversion; no new database assumptions. |
| Financial generic table consumer | Precision and literal string safety unchanged; permission checks remain upstream. |

Image rule is the existing first usable/primary image per exported row, not every attachment. For an order-summary row this means the first usable item photo. Missing/corrupt candidates fall back through the existing canonical order/product/receipt sources, otherwise the row says No photo. Shared-carton content rows keep their existing precedence. Drawing aspect ratio is preserved even when a resized thumbnail cannot be produced. No new image URLs were added to CSV and no binary image data goes into CSV.

Verification opened the generated files, not just HTTP responses: PhpSpreadsheet reload, ZIP integrity, openpyxl independently loaded media/anchors, and installed Microsoft Excel opened both 180-image and final 1,800-image workbooks in normal read-only mode. The larger workbook has 1,810 used rows and 1,800 Excel shapes. Six distinct images repeat in the correct six-row sequence; all row anchors are unique. Both Arabic/Chinese strings and 1,800 duplicate manual `007` numbers remain. A real browser download also reopened successfully, with server filename and XLSX MIME, no-store, and 401 for an unauthenticated export.

### Image-heavy benchmark

Five interleaved original-HEAD/current runs on the same warm thumbnail cache; 1,800 rows, six 1800?1200 source JPEGs reused. Time measures generation only; reopening/validation is outside the timer. Temporary baseline copies were removed after comparison.

- Before: median **4.086 seconds**, 224,453?224,454 bytes, peak PHP allocation 50 MiB.
- After: median **3.680 seconds**, 224,453?224,454 bytes, peak PHP allocation 50 MiB.

About 10% faster generation; workbook size remains about 219.2 KiB because the original pipeline already resized and deduplicated embedded media. The one-byte size variation is ZIP metadata, not image growth. A smaller 180-image workbook remains roughly 0.35?0.40 seconds / 51 KiB; at that size timing noise is material. Remote-network latency and real production export timeout/memory limits were not benchmarked.

## Downstream review and tests

- Customer page enrichment batches only IDs already selected by existing visibility/search/pagination. Individual lookup, mutation, imports and exports retain canonical readers. Country shipping/POR ordering and write revisions matched original bytes; customer concurrency, updates, CSV imports, deposit precision and portal permissions passed.
- Procurement list batches child rows in chunks of 200; revision generation preserves canonical header fields and ID ordering separately from display sort. Create/edit/delete/conversion/recycle restore all passed with these revisions. No response caching or active/deleted predicate changes.
- ItemClassificationService batches with native placeholders (200 IDs) and existing type join. Single-item readers delegate to the same data shape; draft read/detail/export classification behavior is identical. Product and classification endpoint permissions remain in their callers; no write/classification inference changes.
- Shared export image reset/prototype path covers single/selected/container and all generic summary consumers. Independent anchor and same-path/same-mtime replacement regression passed with the same reused service instance. Financial calculations and export data sources were not changed.
- Manual-number regression exposed a pre-existing missing audit timestamp: reproduced on original HEAD handler with `created_at=NULL`. The existing transactional item-number audit now explicitly records NOW(), independent of a database default. Its create/edit/submit/approve/number-preservation test passed afterward; audit test lookup/cleanup no longer use MySQL JSON_EXTRACT. This is the only non-performance runtime correction found during verification.

Passing targeted PHP tests: performance_cache_export_test; exports_domain_test; excel_image_data_integration_test; shared_carton_excel_image_test; order_multi_excel_test; packing_list_item_number_exports_test (21 assertions); customers_domain_test; orders_domain_test; receiving_domain_test; warehouse_domain_test; assignment_domain_test; containers_domain_test; recycle_calendar_test (76 checks); filters_domain_test; production_compatibility_test (187 checks); page_capabilities_test (598 assertions). Draft builder: initial 27/28; the one failing audit case was reproduced on baseline, fixed and rerun alone successfully. No full application suite was run.

Passing JS: cache_freshness_test; exports_ui_regression_test; export_download_links_test; filters_ui_regression_test; recycle_calendar_ui_test; state_ui_regression_test; capacity_ui_regression_test. Changed PHP/JS files linted; git diff whitespace check clean.

Headed Chromium: supplier save ? immediate search ? recycle ? restore ? immediate search; Orders list and valid XLSX download; empty search ? clear filters recovery; Calendar event filter retained in timeline view; received order 200 assigned to container 49, immediately removed from eligibility, warehouse shows Assigned to Container, reopened container shows 2 cartons / 10 pieces / 0.2 CBM / 20 kg and 10% utilization. Destination mismatch correctly hid incompatible cargo rather than allowing an unsafe assignment. Separate visual inspection found readable container totals and no horizontal viewport overflow. The browser driver required recovery once; no application state was inferred from the failed automation step. No production mutations or external delivery.

## Minimal deployment

Deploy these **12 runtime files together** to nginx/PHP production, maintaining the existing directory layout:

```
backend/api/handlers/customers.php
backend/api/handlers/draft-orders.php
backend/api/handlers/procurement-drafts.php
backend/api/helpers.php
backend/services/ItemClassificationService.php
backend/services/OrderExcelService.php
frontend/js/app.js
frontend/js/autocomplete.js
frontend/js/export_download.js
includes/asset_url.php
includes/layout.php
includes/footer.php
```

Apache environments also need `.htaccess` and `frontend/.htaccess` (14 runtime files total). Tests/docs are not required on the public server. No database migration is needed. Production nginx ignores .htaccess: merge the following into its existing configuration; do not replace the server block, root, PHP routing, upload authorization or deny rules.

At `http` scope:

```nginx
map $arg_v $clms_public_asset_cache {
    default "public, no-cache";
    ~^[a-f0-9]{16}$ "public, max-age=31536000, immutable";
}
```

In the CargoChina server, before an existing generic static-file regex location:

```nginx
location ~* ^/cargochina/frontend/.+\.(css|js|png|jpg|jpeg|gif|webp|svg|ico|woff|woff2|ttf)$ {
    try_files $uri =404;
    expires off;
    add_header Cache-Control $clms_public_asset_cache;
}
```

Keep authenticated PHP/API/media/thumbnail responses outside nginx/proxy/CDN response caches. If the existing PHP location enables FastCGI caching, disable it there with `fastcgi_cache off;` and retain PHP's Cache-Control header. Remove any location-level public expiry that overrides authenticated response headers. Do not add a public `/backend/uploads/` static location; those files still require the existing media authorization route. Validate the merged site with `nginx -t`, then reload through the site's normal deployment mechanism. Reload the site's PHP workers if its existing opcode-cache deployment policy requires it. Neither server configuration nor worker processes were changed by this revision.

After deployment verify: versioned frontend JS 200 has immutable; unversioned JS requires revalidation; authenticated Dashboard/roles/expenses plus failed requests and media say no-store; newly edited data is immediately readable. No user clear-cache instruction is needed. Live nginx headers, production PHP extensions/limits and MySQL 5.5 execution still require verification on that host; local compatible SQL checks are not a substitute for that final deployment check.

## Reproduce

Create the disposable sandbox using `tests/support/hardening_sandbox.php`; set DB_NAME to `clms_hardening_20260919`; run `tests/performance_export_probe.php setup` once, then `api procurement-drafts {}` or `xlsx sample 1800`. Run `tests/performance_cache_export_test.php` and `node tests/cache_freshness_test.cjs`. The probe refuses the application database. Generated workbooks are written to the OS temp directory. Remove the disposable database/fixtures after verification. The historical before/after table is tied to the specified fixture state; later workflow tests intentionally change that state.

Cleanup status: automatic approval review rejected the combined QA cleanup command with only “blocked by policy”; it did not execute. The QA HTTP server was subsequently stopped through its own execution session and the headed browser closed. The isolated database, `backend/uploads/qa-performance-export/` synthetic fixtures, temp workbooks, and generated `tests/support/performance-*.json` / `recycle-calendar-fixtures.json` remain for review. They are not deployment files. Do not rerun sandbox setup or the benchmark setup over this existing fixture state.
