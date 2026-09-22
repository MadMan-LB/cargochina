# Container creation and non-draft downloads — 2026-09-22

Scope: reported Add Container failure and `export.json` failures for Approved, Confirmed and shipment orders. No broad hardening or production data changes.

## Causes and changes

- Container creation queried JSON audit metadata with `JSON_EXTRACT`, unavailable on the reported production MySQL 5.5.62. `ContainerWriteService::findCreateRequest` now selects candidate audit rows with portable SQL and decodes/compares the exact top-level request key in PHP. Named locks, transaction rollback, actor/payload binding, duplicate-code rejection and retry after renaming remain intact. No migration/SUPER privilege is needed.
- Excel image handling invokes `UploadAccessService`. Its unconditional JSON SQL made image-bearing downloads fail on MySQL 5.5. This explains a data-dependent failure across statuses: local modern-MariaDB downloads of the reported records already worked before editing. Legacy paths now decode reference/audit JSON in PHP; modern paths retain native SQL. Invalid JSON is ignored safely. Customer document tombstones, upload ownership, receipt-token scope and void revocation remain enforced; no image-access check was bypassed.
- The same media fix covers originals, thumbnails, receipt evidence, shared-carton photos and every Excel layout that uses the common image authorization service.
- An individual procurement-order export now requires `orders.read`, matching the Orders page and selected download. It does not require access to the draft editor; all other draft routes retain their existing restrictions.
- API download links now validate HTTP status and XLSX/CSV content type before saving. A JSON error or login page becomes a visible error message, not `export.json`. Double-clicks share one pending download. Existing bulk-download behavior remains intact.
- Quantities, weights, CBM, prices, statuses and both saved item identifiers are unchanged by exports. No renumbering, financial posting, shipment/tracking push or external notification was performed.

## QA inventory and evidence

| Check | Evidence |
|---|---|
| Container save → reopen → replay | Headed Chromium/Cherry, isolated schema: UI returned 201; reopened Consolidation shows exact code/capacities; same-key replay returned 200 and the same container ID. |
| Container concurrency and failures | `containers_domain_test.php` PASS: concurrent identical creation, changed payload, validation, stale edits, rename/replay, audit-failure rollback, filters and permissions. |
| MySQL 5.5 path | `legacy_container_download_test.php`: **24 assertions PASS** using a PDO adapter which reports 5.5.62 and rejects every SQL JSON function; real SQL and workbook generation still execute on local MariaDB. |
| Image-bearing status downloads | XLSX/CSV and bulk tests cover Draft, Approved, Confirmed, InShipmentDraft, ConsolidatedIntoShipmentDraft, AssignedToContainer and Finalized. Saved identifiers and cargo remain unchanged; images remain embedded. |
| Actual UI links | Local existing orders **2 (Confirmed), 5 (Approved), 8 (ConsolidatedIntoShipmentDraft)** downloaded valid XLSX through normal links, with no download failure. |
| Safe error/retry | Browser-injected HTTP 500 showed a readable error toast and created **zero files**. Removing the injected failure allowed a successful Excel retry. Final browser view restored to clean Orders. |
| Media permissions | `media_access_test.php` PASS in modern and legacy modes: anonymous/roleless denial, private-document tombstones, escaped Unicode paths, receipt-token scoping and void revocation. |
| Export permissions/totals | Expanded `exports_domain_test.php` PASS, including Orders-only download grant without builder access, ordinary/selected exports, financial totals, repeated identifiers and import/export measurement parity. |
| JS controls | `export_download_links_test.cjs`, `exports_ui_regression_test.cjs`, `consolidation_interaction_test.cjs` PASS: JSON/login errors, double clicks, selection preservation, picker keyboard/pointer and stale responses. |
| Syntax | Changed PHP and new JS syntax plus `git diff --check` PASS. |

Fixtures are confined to the disposable schema; generated fixture PNG/XLSX files are removed by tests. The temporary server and schema are removed after verification. Browser read-only downloads used existing local records. Full regression was not rerun.

## Deploy together

Seven changed application files:

- `backend/services/ContainerWriteService.php`
- `backend/services/UploadAccessService.php`
- `backend/api/handlers/containers.php`
- `backend/api/handlers/draft-orders.php`
- `backend/api/authorization.php`
- `frontend/js/export_download.js` **(new file)**
- `includes/footer.php`

The preceding search fix supplies `clmsSupportsJsonSearch`. If that deployment is uncertain, include the current `backend/services/PackingListItemNumber.php` and `backend/api/helpers.php` too. See [previous search deployment](search-export-compatibility.md).

**No SQL migration is required.** Keep the new JS file and footer change together. Invalidate PHP opcode caches if the hosting configuration requires it.

Production has not been accessed or changed. An actual MySQL 5.5 post-deployment smoke check is still required: create/reopen a uniquely named container, then download an image-bearing Approved, Confirmed and shipment order. The screenshot's error reference was not correlated with production logs; if a failure persists, retain its exact JSON message/reference for diagnosis rather than treating this as proof that all production errors are closed.
