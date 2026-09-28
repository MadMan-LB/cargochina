# Consolidation container presets — 2026-09-28

## Behavior
- Add Container separates size (20GP, 40GP, 45GP) from the required, staff-entered unique container code. Switching size never replaces the code.
- Max CBM and Max Weight are read-only. CBM uses existing Business Settings (defaults 28/68/78); weight retains the existing 28,000 kg preset.
- The existing HQ-named configuration keys remain internal compatibility keys; their saved values are not reset or renamed. Existing container codes are business identities and are not automatically renamed.
- Opening the modal fetches current settings. A failed settings request disables saving; no guessed capacity fallback. The API validates the chosen preset and rejects submitted capacities that differ from current settings.
- Size is a creation preset, not a new database field. The resolved capacities and manually entered code are persisted using the existing container schema and audit transaction. Existing custom-capacity API callers remain supported.

## Downstream review
- Shared preset service is used by config GET and preset-based container POST; config retains legacy response keys for compatibility.
- Consolidation is the only frontend consumer of the preset endpoint. Existing read/write authorization remains enforced; no role grants changed.
- List/detail, edit revision, filters, search, assignment selectors and exports continue using the same persisted code and capacities. Existing codes, cargo, financial data and assignments are untouched.
- ContainerCapacityService, assignment previews and container totals continue using persisted max_cbm/max_weight. Later edits retain existing revision, destination and loaded-capacity validation. Business Settings changes affect future creations, not existing capacities.
- Existing uppercasing and uniqueness rules still apply to manually entered codes. Retry keys remain stable for the same payload and reset when staff correct the code or size.
- No migration, import change, modern SQL or cache configuration required. Existing versioned asset URLs invalidate the updated JavaScript automatically.

## Verification
- `tests/containers_domain_test.php` passed against disposable local database: all three GP sizes, multiple same-size containers, code persistence, readback, duplicate code, replay, invalid size/capacity, blank code, stale edits, audit rollback, assignment destination guard, filters, pagination and authorization.
- `node tests/container_presets_ui_test.cjs` passed: configured CBM, all size choices, preserved manual code, read-only markup, config failure disables save.
- `node tests/capacity_ui_regression_test.cjs` passed: existing plus additional cargo, exact full, excess, same-container deduplication, unknown cargo and weight utilization.
- PHP lint and JavaScript syntax checks passed. Headed Chromium: inspected modal, switched all three sizes, created two distinct 20GP references, reloaded/reopened saved records, rejected duplicate then corrected code successfully.
- Verified locally on PHP 8.2/MariaDB 10.4. New SQL uses MySQL 5.5-compatible SHOW/SELECT only; no production deployment or production database changes performed.

## Minimal production deployment
Deploy these five runtime files together:
1. `consolidation.php`
2. `frontend/js/consolidation.js`
3. `backend/services/ContainerPresetService.php` (new)
4. `backend/api/handlers/config.php`
5. `backend/api/handlers/containers.php`

No database migration required.
