# Codex Handoff: HS Code Tariff Catalog — What Was Requested, What Was Done, What Still Fails

## What I Asked Cursor to Do

1. **Products page** — Make HS code searchable using Lebanon customs tariff data.
2. **Import to DB** — Load Excel/CSV from the `hs codes/` folder into a new table.
3. **Importer in settings** — Import/update from `hs codes/` in Admin config.
4. **Format** — Use CSV (no extra PHP libs).
5. **Impact** — Review and update all affected logic, pages, calculations, filters, exports, permissions, and workflows.

## Data Source

- **Path:** `c:\xampp\htdocs\cargochina\hs codes\`
- **Files:** `lebanon_customs_tariffs.csv` (primary), plus `.json`, `.xlsx` variants
- **CSV columns:** `hs_code`, `name`, `category`, `tariff_rate`, `vat`, `parent_directory_code`, `parent_directory_name`, `section_code`, `section_name`
- **Size:** ~6,000+ rows

## What Cursor Implemented

### 1. Migration 042
- **File:** `backend/migrations/042_hs_code_tariff_catalog.sql`
- **Table:** `hs_code_tariff_catalog` (id, hs_code, name, category, tariff_rate, vat, parent_directory_*, section_*, source_file, imported_at)
- **Indexes:** `idx_hs_code`, `idx_hs_code_like`

### 2. HS Code Catalog API
- **File:** `backend/api/handlers/hs-code-catalog.php`
- **Endpoints:**
  - `GET /hs-code-catalog?q=` — Search catalog for autocomplete (hs_code, name, category)
  - `GET /hs-code-catalog/files` — List CSV files in `hs codes/` (SuperAdmin only)
  - `POST /hs-code-catalog/import` — Import from `hs codes/` folder (SuperAdmin only)
- **Path fix:** Uses `dirname(__DIR__, 3)` to resolve project root for `hs codes/` folder
- **BOM:** Strips UTF-8 BOM from first CSV column header

### 3. Products Page
- **Files:** `products.php`, `frontend/js/products.js`
- Filter HS code and Add Product HS code fields use `hs-code-catalog` autocomplete (resource: `hs-code-catalog`)
- Filter still sends `hs_code` to `GET /products`; products table unchanged

### 4. HS Code Tax Page
- **Files:** `hs_code_tax.php`, `frontend/js/hs_code_tax.js`
- "Lebanon Tariff Catalog" section with search input
- Catalog search, Estimator HS code, and Add Rate HS code use `hs-code-catalog` autocomplete

### 5. Admin Config
- **Files:** `admin_config.php`, `frontend/js/admin_config.js`
- "HS Code Tariff Catalog" section with file selector and Import/Update button
- Calls `GET /hs-code-catalog/files` and `POST /hs-code-catalog/import`

### 6. RBAC
- **File:** `backend/config/rbac.php`
- Resource `hs-code-catalog`: read = ChinaAdmin, ChinaEmployee, LebanonAdmin, SuperAdmin; write = SuperAdmin

### 7. Autocomplete
- **File:** `frontend/js/autocomplete.js`
- Dropdown z-index set to 10600 so it appears above Bootstrap modals

### 8. Docs & Tests
- `docs/API.md` — HS Code Tariff Catalog section
- `SYSTEM_CHANGELOG_AND_IMPLEMENTATION_NOTES.md` — Downstream impact review
- `tests/smoke_test.php` — Checks `hs_code_tariff_catalog` table exists
- `tests/hs_code_catalog_test.php` — Schema + search query test

---

## What Still Doesn't Work

**The HS code feature is still not working as expected.** Please debug and fix.

### Likely Failure Points to Investigate

1. **Import fails**
   - Admin config → HS Code Tariff Catalog → Import/Update returns error or imports 0 rows
   - Check: `hs codes/` folder path, CSV format (BOM, column names), file permissions
   - API path: `POST /api/v1/hs-code-catalog/import` with `{ "source": "" }` or `{ "source": "lebanon_customs_tariffs.csv" }`

2. **Search/autocomplete returns empty**
   - Products page HS filter or Add Product HS field shows no results when typing
   - Check: Catalog table has data after import; `GET /api/v1/hs-code-catalog?q=0101` returns rows
   - Check: Autocomplete resource `hs-code-catalog` is wired correctly; response shape matches what autocomplete expects (`id`, `hs_code`, `name`, etc.)

3. **Path resolution wrong**
   - Handler uses `dirname(__DIR__, 3) . '/hs codes'` — verify this resolves to `c:\xampp\htdocs\cargochina\hs codes` when handler is at `backend/api/handlers/hs-code-catalog.php`

4. **CSV format mismatch**
   - First row must have `hs_code` column (case-sensitive)
   - BOM stripped from first column; other encoding issues?

5. **Permissions**
   - Import requires SuperAdmin; search requires ChinaAdmin/ChinaEmployee/LebanonAdmin/SuperAdmin
   - Non-SuperAdmin users may get 403 on import; verify role checks

6. **Frontend wiring**
   - Products page: filter `productFilterHsCode` and form `productHsCode` must use Autocomplete with resource `hs-code-catalog`
   - Verify `AUTOCOMPLETE_API_BASE` and path `/hs-code-catalog?q=...` are correct

---

## What Codex Should Do

1. **Reproduce the failure** — Run the app, try import, try HS search on Products page and HS Code Tax page. Capture exact error messages, network responses, and console logs.

2. **Fix the root cause** — Based on reproduction, fix the import path, CSV parsing, API response shape, or frontend wiring.

3. **Verify end-to-end** — After import, search for an HS code (e.g. `0101` or `8471`) on Products page and HS Code Tax page. Confirm autocomplete shows catalog results.

4. **Document the fix** — Add a brief note to `SYSTEM_CHANGELOG_AND_IMPLEMENTATION_NOTES.md` or `CODEX_CURSOR_HANDOFF.md` explaining what was broken and how it was fixed.

---

## Key File Locations

| Purpose | Path |
|---------|------|
| Handler | `backend/api/handlers/hs-code-catalog.php` |
| Migration | `backend/migrations/042_hs_code_tariff_catalog.sql` |
| RBAC | `backend/config/rbac.php` (resource `hs-code-catalog`) |
| Products JS | `frontend/js/products.js` (Autocomplete resource `hs-code-catalog`) |
| HS Tax JS | `frontend/js/hs_code_tax.js` |
| Admin config | `admin_config.php`, `frontend/js/admin_config.js` |
| Autocomplete lib | `frontend/js/autocomplete.js` |
| Data folder | `hs codes/` (project root) |
| CSV file | `hs codes/lebanon_customs_tariffs.csv` |

---

## API Contract (for reference)

**GET /hs-code-catalog?q=...&limit=15**
- Returns: `{ "data": [ { "id": "0101", "hs_code": "0101", "name": "...", "category": "...", "tariff_rate": "...", "vat": "...", "section_name": "..." } ] }`

**POST /hs-code-catalog/import**
- Body: `{ "source": "" }` (default) or `{ "source": "filename.csv" }`
- Returns: `{ "data": { "imported": 6000, "file": "lebanon_customs_tariffs.csv" } }`
