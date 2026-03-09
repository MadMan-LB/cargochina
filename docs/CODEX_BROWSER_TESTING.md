# Codex ↔ Cursor Browser Testing Protocol

**Purpose:** Codex opens a browser and tests the app; Cursor does the coding. Codex reports findings; Cursor fixes issues. This file is the shared checklist and handoff.

---

## 1. How We Work Together

| Role | Responsibility |
|------|----------------|
| **Codex** | Open browser, navigate, click, type, observe. Report: "X works" / "Y fails: [description]". |
| **Cursor** | Read reports, fix code, run migrations/tests. Update this file with fixes. |

**Flow:**
1. Codex runs through the test checklist below.
2. Codex reports: pass/fail per item, plus any bugs (screenshots, error text, steps).
3. Cursor fixes issues and updates this file.
4. Codex re-tests affected areas.
5. Repeat until green.

---

## 2. Environment

- **Base URL:** `http://localhost/cargochina/`
- **Login:** `admin@salameh.com` / (password from `.env` or seed)
- **Role:** SuperAdmin (full access)

Ensure XAMPP Apache + MySQL are running before testing.

---

## 3. Test Checklist (Recent Features)

### 3.1 Customers Page

| # | Test | Steps | Expected | Status |
|---|------|-------|----------|--------|
| C1 | Page loads | Go to `customers.php` | No syntax error; table visible | PASS |
| C2 | Add customer | Click "+ Add Customer", fill Code + Name, Save | Modal closes; customer appears in list | PASS |
| C3 | Payment links | Add customer; in modal, click "+ Add payment link"; enter Name (e.g. weeecha) and Value (e.g. xxx xx xxxx xx); Save | Saved; on Edit, payment links shown | PASS |
| C4 | Edit customer | Click Edit on a customer | Modal opens with data; payment links if any | PASS |

### 3.2 Products Page

| # | Test | Steps | Expected | Status |
|---|------|-------|----------|--------|
| P1 | Page loads | Go to `products.php` | Product list visible; no errors | PASS |
| P2 | Add product modal | Click "+ Add Product" | Modal opens with description fields, CBM, weight, supplier, etc. | PASS |
| P3 | Description "+" button | Click "+" next to Description | New empty description field appears | PASS |
| P4 | Multiple descriptions | Add 2+ description fields; type in one (e.g. Chinese); blur | Translated text appears next to field (if Chinese) | PASS |
| P5 | Supplier autocomplete | In Supplier field, type "tel" or part of a supplier name | Dropdown shows matching suppliers | PASS |
| P6 | Select supplier | Type, then click a supplier in dropdown | Field shows supplier name; selection stored | PASS |
| P7 | Pieces per carton + unit price | Enter pieces (e.g. 24) and unit price (e.g. 0.50) | "Carton total" shows 12.00 | PASS |
| P8 | Save product | Fill required fields (CBM, weight), Save | Product created; appears in list | PASS |
| P9 | Edit product | Click Edit on a product | Modal opens with data; descriptions, supplier, pieces, price shown | PASS |

### 3.3 Smoke / Regression

| # | Test | Steps | Expected | Status |
|---|------|-------|----------|--------|
| S1 | Login | Go to `login.php`, enter credentials | Redirect to dashboard | PASS |
| S2 | Dashboard | After login | Stats / summary visible | PASS |
| S3 | Orders | Go to `orders.php` | Order list or empty state | PASS |
| S4 | Suppliers | Go to `suppliers.php` | Supplier list | PASS |
| S5 | Pipeline | Go to `pipeline.php` | Stage summary visible | PASS |

---

## 4. Bug Report Template (for Codex)

When something fails, report like this:

```
**Page:** customers.php
**Test:** C3 (Payment links)
**Steps:** 1. Add customer 2. Click "+ Add payment link" 3. Enter weeecha / xxx
**Expected:** Saves and shows on Edit
**Actual:** [Error message / blank / wrong behavior]
**Screenshot / console:** [if available]
```

---

## 5. Cursor Fix Log

When Cursor fixes something, add a line here:

| Date | Issue | Fix |
|------|-------|-----|
| 2026-02-19 | PHP syntax error (fn=>) on customers | Replaced arrow functions with function() in customers.php |
| 2026-02-19 | Product descriptions: Add button | Changed to "+" and multiple fields |
| 2026-02-19 | Supplier autocomplete not showing | API_BASE + z-index 1060 for modal |
| 2026-03-08 | Duplicate `API_BASE` declaration on pages using `autocomplete.js` | Renamed the autocomplete-local constant to avoid colliding with `frontend/js/app.js` |
| 2026-03-08 | Audit log page JS syntax error | Fixed the missing `)` in `frontend/js/admin_audit_log.js` |
| 2026-03-08 | Orders modal template API returned 500 locally | Applied migration `023_order_templates.sql` so `order_templates` and `order_template_items` exist |
| 2026-03-08 | Codex browser retest | Re-ran C1-C4, P1-P9, S1-S5 plus orders modal/autocomplete, receiving, consolidation, notifications, and admin pages; all passed |
| 2026-03-08 | Optional polish | login.php: autocomplete="email", autocomplete="current-password", label for attributes |

---

## 6. Quick Commands (for Cursor)

```bash
# Run smoke test
php tests/smoke_test.php

# Run migrations
php backend/migrations/run.php
```

---

## 7. Files Touched by Recent Work

- `backend/api/handlers/customers.php` — payment_links, PHP 7.3 compat
- `backend/api/handlers/products.php` — description_entries, pieces_per_carton, unit_price
- `products.php` — product form UI
- `frontend/js/products.js` — descriptions, supplier autocomplete, pricing
- `customers.php` — payment links UI
- `frontend/js/customers.js` — payment links
- `frontend/js/autocomplete.js` — API_BASE, z-index
- `frontend/js/app.js` — window.API_BASE
- `backend/migrations/026_products_customers_enhancements.sql`

---

---

## 8. Last Browser Run

**Date:** 2026-03-08  
**Result:** All green (C1–C4, P1–P9, S1–S5; orders modal/template/autocomplete; receiving; consolidation; notifications; admin pages). No blocking issues.

*Last updated: 2026-03-08*
