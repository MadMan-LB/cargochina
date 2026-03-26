# CLMS System Changelog and Implementation Notes

**Enhancement Phase — Cross-Border Logistics Platform**  
*Document tracks all changes, decisions, and implementation status.*

---

## 2026-03-18 Draft an Order Builder

- Replaced the old single-supplier procurement-draft workflow with a real **Draft an Order** builder at `procurement_drafts.php`, while keeping the route itself for compatibility.
- New draft orders now save directly into `orders` with `order_type='draft_procurement'` and line items in `order_items`; legacy `procurement_drafts` remain only as a migration source and legacy reference surface.
- Added `backend/api/handlers/draft-orders.php` with authenticated endpoints for list/get/create/update/export and guided legacy migration via `POST /draft-orders/legacy/{legacyId}/migrate`.
- Added migration `047_draft_order_builder_fields.sql` for `order_items.hs_code`, `order_items.custom_design_required`, and `order_items.custom_design_note`.
- Builder behavior now supports one customer across multiple supplier sections, customer shipping-code driven item numbering, supplier-scoped product autocomplete, autofill from existing products, free-text items that auto-create products on save, custom-design note/file capture, and live per-section plus grand totals.
- Draft-order print/export now read from real orders: `procurement_draft_print.php?order_id=` prints grouped supplier sections and `GET /draft-orders/{id}/export` returns grouped CSV with supplier subtotals and grand totals.
- Orders page downstream updates:
  - `orders.php` + `frontend/js/orders.js` now expose an `order_type` filter, show a Draft Order badge, route edit/open back into the dedicated builder, and route draft-order exports to the grouped draft CSV instead of the standard order Excel export.
  - Search/list filtering now accepts `order_type`, supplier summaries stay correct for multi-supplier draft orders, and draft-order rows remain in the normal lifecycle/status flow after submit/approve/receive/consolidation.
- Product and receiving downstream updates:
  - `backend/api/handlers/products.php` product search accepts `supplier_id` so each supplier section can keep its autocomplete scoped correctly.
  - `backend/api/handlers/orders.php`, `backend/api/handlers/receiving.php`, and HS-code estimation/search paths now respect item-level `order_items.hs_code` with fallback to product HS code, and keep derived quantity/CBM/weight behavior aligned with `dimensions_scope`.
- Supplier and navigation downstream updates:
  - `frontend/js/suppliers.js` adds `Draft Order` deep-link entry points from the supplier page.
  - `includes/layout.php` renames the page label from Procurement Drafts to Draft an Order.
- Permissions and visibility review:
  - New builder/API surface remains limited to `ChinaAdmin`, `ChinaEmployee`, and `SuperAdmin`.
  - No new customer-facing or warehouse-facing exposure was introduced; legacy procurement-draft activity links remain resolvable until migration is complete, while all new builder actions log against `order`.
- Verification completed:
  - PHP lint passed on all touched PHP entry points and handlers.
  - Migration runner applies the new order-item columns.
  - Added schema and handler coverage in `tests/draft_order_builder_test.php` and extended `tests/smoke_test.php`.
  - Orders-page export routing was updated after downstream review exposed the standard Excel export mismatch for draft-procurement rows.

## 2026-03-18 Expected Ready Date Optional

- `orders.expected_ready_date` is now nullable via migration `050_orders_expected_ready_nullable.sql`.
- `orders.php` and `procurement_drafts.php` now treat the date as optional in the form UI and show a confirmation popup before saving or migrating without it.
- `backend/api/handlers/orders.php`, `backend/api/handlers/draft-orders.php`, and the legacy `backend/api/handlers/procurement-drafts.php` convert path now accept missing expected-ready dates and persist `NULL` instead of failing validation.
- Downstream date-ordered queues were reviewed and updated to keep blank-date orders at the end instead of bubbling to the top:
  - `backend/api/handlers/orders.php`
  - `backend/api/handlers/receiving.php`
  - `backend/api/handlers/warehouse-stock.php`
- Date-driven dashboard/cron/report logic already safely ignores null dates because overdue comparisons only match rows with a real date.

---

## Implementation Plan Overview

### Phase 1: Schema & Business Rules Foundation
- Supplier commissions (rate, type, applied_on)
- Buy price vs sell price (products, order_items)
- Customer priority levels
- Business settings table (ETA offsets, notification thresholds)
- High-alert notes structure
- Design attachments support
- Expense categories and expenses table
- Container destination
- Customer decline flow (decline_reason)
- Fix 20HQ CBM (28 not 68)

### Phase 2: Order Flow Enhancements
- Draft orders / procurement drafts
- Multi-supplier: relax/remove top-level supplier
- Order quantity flexibility (pieces, carton override)
- Shipping code auto-insert from customer
- Duplicate shipping code check on customer

### Phase 3: Expenses, Profit, Balances
- Expenses page
- Salaries, order expenses, container expenses
- Profit reporting
- Outstanding balances (receivables, payables)
- Commission impact in calculations

### Phase 4: Customer Portal & Communication
- Customer portal (secure: one-time link / impersonation)
- Order status timeline
- Confirm/decline with reason
- Internal chat / communication center

### Phase 5: Warehouse & Planning
- Warehouse stock page
- CBM / warehouse-direct customers
- Calendar / timeline planning
- Arrival / near-arrival notifications

### Phase 6: Polish & Professionalization
- Exports (PDF, Excel) with role-based field visibility
- Multi-status checkbox filtering
- Admin price-difference notifications
- HS code / import tax page (architecture)
- General UX and validation improvements

---

## Security Decisions

### A) Customer Portal Access
**Decision:** One-time login link + optional admin impersonation with audit.
- **Rationale:** No plaintext passwords. One-time links sent via notification; admin can "View as customer" with full audit trail.
- **Implementation:** `customer_portal_tokens` table (token, customer_id, expires_at, used_at); admin action logs impersonation.

### B) Master Password
**Decision:** NOT implemented. Support access via secure impersonation with reason field and audit.

### C) Price Visibility
**Decision:** All customer-facing views, exports, PDFs, Excel show only `sell_price`. `buy_price` and margin data are internal-only. Role check before including internal fields.

---

## Phase 1 Implementation

### Migration 033: Foundation Schema (Phase 1)

**File:** `backend/migrations/033_phase1_foundation.sql`

**Changes:**

1. **Suppliers — Commission**
   - `commission_rate` DECIMAL(6,4) NULL — percentage or fixed amount
   - `commission_type` ENUM('percentage','fixed') DEFAULT 'percentage'
   - `commission_applied_on` ENUM('buy_value','sell_value','net_value') DEFAULT 'buy_value'

2. **Customers — Priority & Shipping**
   - `priority_level` ENUM('normal','priority','high_priority','critical') DEFAULT 'normal'
   - `priority_note` TEXT NULL
   - `default_shipping_code` VARCHAR(100) NULL — auto-insert into orders

3. **Products — Buy/Sell Pricing**
   - `buy_price` DECIMAL(12,4) NULL — internal cost
   - `sell_price` DECIMAL(12,4) NULL — customer-facing (existing unit_price remains for backward compat; sell_price preferred)

4. **Order Items — Buy/Sell Override**
   - `buy_price` DECIMAL(12,4) NULL
   - `sell_price` DECIMAL(12,4) NULL — overrides product; customer-facing
   - `order_cartons` INT NULL — override cartons for this order
   - `order_qty_per_carton` DECIMAL(12,4) NULL — override qty per carton

5. **Orders — Draft & High-Alert**
   - `high_alert_notes` TEXT NULL — special handling, fragile, etc.
   - `order_type` ENUM('standard','draft_procurement','cbm_direct') DEFAULT 'standard'

6. **Containers**
   - `destination_country` VARCHAR(100) NULL
   - `destination` VARCHAR(255) NULL
   - Fix 20HQ preset: 28 CBM (in consolidation.php)

7. **Customer Confirmations**
   - `declined_at` TIMESTAMP NULL
   - `decline_reason` TEXT NULL

8. **Business Settings Table**
   - `business_settings` (key_name, key_value, updated_at)
   - ETA offsets per country, notification thresholds, etc.

9. **Expense Categories**
   - `expense_categories` (id, code, name, type: operational|salary|fixed|variable|order|container)

10. **Expenses**
    - `expenses` (id, category_id, amount, currency, expense_date, payee, notes, order_id, container_id, customer_id, created_by, created_at)

11. **Design Attachments**
    - `design_attachments` (id, entity_type, entity_id, file_path, file_type, uploaded_by, uploaded_at, internal_note)
    - entity_type: 'product'|'order_item'

12. **Draft Procurement**
    - `procurement_drafts` (id, name, supplier_id, status, created_by, created_at)
    - status: draft|pending_review|sent_to_supplier|converted|cancelled
    - `procurement_draft_items` (draft_id, product_id, quantity, notes, sort_order)

13. **Customer Portal Tokens**
    - `customer_portal_tokens` (id, customer_id, token_hash, expires_at, used_at, created_by)

14. **Internal Messages (Chat)**
    - `internal_messages` (id, customer_id, order_id, container_id, sender_id, body, read_at, created_at)

15. **HS Code Tax (Architecture)**
    - `hs_code_tax_rates` (id, hs_code, country_code, rate_percent, effective_from, notes)

---

## Database Migration Notes

- All migrations use idempotent ADD COLUMN where possible (check information_schema before adding).
- Rollback notes included in migration file comments.
- Preserve existing data; new columns nullable or with defaults.

---

## Business Rule Decisions

| Topic | Decision |
|-------|----------|
| Commission applied on | Default: buy_value. Configurable per supplier. |
| 20HQ CBM | 28 m³ (standard 20ft high cube). 40HQ=68, 45HQ=78. |
| Order supplier | Keep supplier_id on orders for "primary/default"; line-level supplier_id is source of truth for multi-supplier. |
| Customer portal auth | One-time link; no stored customer passwords. |
| Sell price default | If sell_price null, fall back to unit_price for backward compat. |

---

## Assumptions

- Existing `unit_price` on products/order_items is treated as sell/customer price until migration.
- Orders with single supplier: supplier_id remains; multi-supplier orders use line-level supplier_id.
- Expense categories seeded with common types (transport, handling, customs, salary, etc.).
- ETA offset rules stored as JSON in business_settings (e.g. `{"LB":{"groupage":15,"full_container":0}}`).

---

## Deferred / Risky Items

- **Full real-time chat:** Implement as threaded message center first; structure for WebSocket later.
- **HS code data:** Architecture only; actual tariff data to be added later.
- **CBM direct customers:** Separate flow; may need dedicated tables for warehouse-direct intake.
- **Split shipments across containers:** Complex; requires order_item_container_allocation table; deferred to later phase.

---

## Admin / User Impact

| Role | New Access |
|------|------------|
| SuperAdmin | Business settings, expense categories, all financial data |
| ChinaAdmin | Customer priority, expenses (view/add), profit reports (internal) |
| LebanonAdmin | Container destination, expenses, financial visibility |
| WarehouseStaff | High-alert notes visibility, design badges |
| ChinaEmployee | Customer priority display, draft orders |

---

## Testing Notes

- Run `php backend/migrations/run.php` after each migration.
- Smoke test: create order, add expense, view customer with priority.
- Verify 20HQ preset shows 28 CBM in Add Container modal.
- Customer-facing export must NOT include buy_price.

---

## Completed Features

### Phase 1 (Implemented)
- **Migration 033:** Supplier commission (rate, type, applied_on), customer priority (level, note, default_shipping_code), products buy_price/sell_price, order_items buy_price/sell_price/order_cartons/order_qty_per_carton, orders high_alert_notes/order_type, containers destination_country/destination, customer_confirmations declined_at/decline_reason, business_settings, expense_categories, expenses, design_attachments, procurement_drafts, customer_portal_tokens, internal_messages, hs_code_tax_rates
- **20HQ CBM fix:** consolidation.php preset changed from 68 to 28 CBM
- **Expenses page:** Full CRUD, filters (date, category, order, container), summary by currency
- **Expenses API:** GET list/categories, POST, PUT, DELETE
- **Customer decline flow:** confirm.php + confirm API support decline with reason; CustomerDeclined status; NotificationService::notifyOrderDeclined
- **Order status:** CustomerDeclined added to OrderStateService, app.js, orders filter, CSS

### Phase 2 (Implemented)
- **Migration 034:** Orders supplier_id nullable; containers eta_date, actual_arrival_date; container_arrival_notifications
- **Procurement drafts:** Full CRUD API + procurement_drafts.php page; POST /procurement-drafts/{id}/convert creates order
- **Business settings API:** GET/PUT for SuperAdmin
- **Orders API:** Multi-status filter support (status[] array), LEFT JOIN suppliers for nullable supplier_id
- **Phase 2A:** Procurement convert (modal + API), shipping code auto-fill from customer default, nullable supplier_id
- **Phase 2B:** Buy/sell enforcement in Excel export (sell_price ?? unit_price), customer priority badge, high_alert_notes (form, API POST/PUT, list badge, order info modal)
- **Phase 2C:** Design attachments API (GET/POST/DELETE), product form design attachments UI, multi-supplier (optional default supplier)

### Phase 3 (Implemented)
- **Financials page:** Profit by order (sell/buy/margin), customer balances (deposits vs receivable), supplier payables
- **Financials API:** GET /financials/profit, GET /financials/balances

### Phase 4 (Implemented)
- **Customer portal:** customer_portal.php (token-based, one-time), customer-portal-tokens API (POST generate, GET list)
- **Internal messages:** internal-messages API (GET by customer_id, POST send); Messages modal on customers page
- **Portal link button:** Customers page → Generate one-time link for customer to view order status

### Phase 5 (Implemented)
- **Warehouse stock page:** warehouse_stock.php + warehouse-stock API; filter by customer, supplier, status
- **Calendar page:** calendar.php — orders by expected_ready_date, containers by eta_date

### Phase 6 (Implemented)
- **Business settings page:** business_settings.php for ETA offsets, container CBM, arrival notify days
- **Multi-status filter:** Orders API accepts status[] array for multiple statuses

### Consolidation UX (Implemented)
- **Migration 041:** `countries` table (id, code, name) with seed of 50+ ISO country codes for destination country dropdown
- **Countries API:** GET /countries, GET /countries/search?q= for autocomplete; RBAC: ChinaAdmin, LebanonAdmin, SuperAdmin
- **Consolidation Edit Container:** Suggest button sets ETA to 70 days from today; Destination Country uses type-to-search autocomplete from countries table (stores code for consistent filtering)

### Expenses Category Create-on-Save (Implemented)
- **Expenses API:** POST/PUT accept `category_name` when `category_id` is missing; new categories are auto-created in `expense_categories` (find-or-create by name, code slug, type operational)
- **Filter refresh:** After save, category filter dropdown is refreshed so newly created categories appear immediately for filtering on the same page
- **Audit:** `logClms('expense_category_create')` when a new category is created; race-condition handling (retry SELECT on duplicate code)
- **Tests:** `tests/expense_test.php` — schema, create-on-save flow, code uniqueness
- **API docs:** `docs/API.md` — Expenses section with `category_name` request/response
- **Connections:** Financials API aggregates expenses by currency only (no category filter); Super Admin has no category management UI; Expenses page is the only consumer of category filter

#### Impact Analysis (Expenses Category Create-on-Save)

| # | Check | Result |
|---|-------|--------|
| 1 | **Callers of findOrCreateExpenseCategory** | Only `backend/api/handlers/expenses.php` POST and PUT — no other callers |
| 2 | **Related pages/forms/filters** | Expenses page: form (expenseCategory), filter (filterCategory), summary; Financials page: profitExpenseSummary (currency totals only); no exports/reports use category |
| 3 | **Dependent logic** | GET /expenses/categories returns all active; list/summary JOIN expense_categories; filter uses category_id; Financials aggregates by currency — no change |
| 4 | **Permissions** | RBAC unchanged: ChinaAdmin, LebanonAdmin, SuperAdmin; no customer-facing; no role visibility change |
| 5 | **DB schema/migrations** | No migration; uses existing expense_categories; new rows get code, name, category_type=operational; seed data unchanged |
| 6 | **Totals/balances/counts** | Expenses list summary (by currency) includes new categories; Financials profit summary unchanged; no derived values broken |
| 7 | **Impacted files** | `backend/api/handlers/expenses.php` (findOrCreateExpenseCategory, POST, PUT), `frontend/js/expenses.js` (category_name, refreshFilterCategories), `expenses.php` (placeholder), `docs/API.md`, `tests/expense_test.php`, `run-tests.bat` |

### HS Code Tariff Catalog (Implemented)
- **Migration 042:** `hs_code_tariff_catalog` table (id, hs_code, name, category, tariff_rate, vat, parent_directory_*, section_*, source_file, imported_at)
- **Data source:** `hs codes/lebanon_customs_tariffs.csv` (CSV chosen for compatibility; no extra PHP libs)
- **API:** `GET /hs-code-catalog?q=` — search for Products page HS autocomplete; `GET /hs-code-catalog/files` — list CSV files; `POST /hs-code-catalog/import` — import from `hs codes/` folder (SuperAdmin only)
- **Products page:** Filter and product form HS code fields use catalog autocomplete (hs_code + name); filter still sends `hs_code` to `GET /products`; products table `hs_code` unchanged
- **Admin config:** HS Code Tariff Catalog section with file selector and Import/Update button
- **RBAC:** read = ChinaAdmin, ChinaEmployee, LebanonAdmin, SuperAdmin; write/import = SuperAdmin
- **Downstream:** `hs_code_tax` uses `hs_code_tax_rates` (manual rates); orders, receiving, procurement use `product.hs_code` from products; no exports changed
- **HS Code Tax page:** Added "Lebanon Tariff Catalog" section — search imported catalog; Estimator HS Code and Add Rate HS Code fields use catalog autocomplete. Tax Rate Library remains for manual rates (`hs_code_tax_rates`); catalog data is separate

#### Downstream Impact Review (HS Code Tariff Catalog)

| Area | Status | Notes |
|------|--------|-------|
| **Products API** | Unchanged | `hs_code` filter on `GET /products` uses `products.hs_code`; `GET /products/hs-codes` legacy endpoint kept |
| **Products page** | Updated | Filter + Add Product HS fields use catalog autocomplete; filter still sends `hs_code` to list API |
| **HS Code Tax page** | Updated | Catalog search section; Estimator + Add Rate HS fields use catalog autocomplete |
| **Orders, receiving, procurement** | Unchanged | Use `product.hs_code` from products; order_items has no hs_code column |
| **Order/container export** | Unchanged | OrderExcelService Template.xlsx layout has no HS column; no change |
| **Exports/reports** | None | No HS code exports found; no change |
| **Permissions** | RBAC updated | `hs-code-catalog` read: ChinaAdmin, ChinaEmployee, LebanonAdmin, SuperAdmin; write/import: SuperAdmin |
| **index.php** | No special case | Generic resource RBAC; handler enforces SuperAdmin for files/import |
| **CODEX_CURSOR_HANDOFF** | Updated | Customs section + guardrails note tariff catalog support |
| **Tests** | Added | smoke_test checks hs_code_tariff_catalog; hs_code_catalog_test schema + search |

---

## Phase 2A/2B/2C Completion Proof

### Phase 2A: Procurement Drafts, Shipping Auto-Fill, Nullable Supplier
> Superseded on 2026-03-18 by the real Draft an Order builder backed by `orders`/`order_items`; legacy `procurement_drafts` are now migration-only for new work.

| Feature | Complete because |
|---------|------------------|
| Draft an Order builder | `procurement_drafts.php` opens a real multi-supplier Draft an Order builder; `/draft-orders` CRUD persists real orders with `order_type=draft_procurement`; `/draft-orders/legacy/{id}/migrate` handles the remaining legacy queue without duplicating already-converted drafts |
| Shipping auto-fill | customers search returns default_shipping_code; orders.js applyCustomerDefaultShippingCode() fills first empty shipping code on customer select; addOrderItem() pre-fills from selected customer |
| Nullable supplier | Migration 034 makes orders.supplier_id nullable; POST/PUT accept null; all queries use LEFT JOIN suppliers |

### Phase 2B: Buy/Sell, Customer Priority, High-Alert Notes
| Feature | Complete because |
|---------|------------------|
| Buy/sell enforcement | OrderExcelService uses sell_price ?? unit_price for unit price in Excel export |
| Customer priority | customers.js shows priority badge (non-normal) with tooltip for priority_note |
| High-alert notes | orders.php has orderHighAlertNotes textarea; orders.js includes in save payload, populates on edit/copy; order info modal shows warning alert; orders API POST/PUT persist high_alert_notes; list shows ⚠️ badge when set |

### Phase 2C: Design Attachments, Multi-Supplier
| Feature | Complete because |
|---------|------------------|
| Design attachments | design-attachments API: GET ?entity_type&entity_id, POST {entity_type, entity_id, file_path}, DELETE /{id}; RBAC for ChinaAdmin, ChinaEmployee, WarehouseStaff, SuperAdmin; products.php Design attachments section; products.js load/add/delete; upload → POST design-attachments flow |
| Multi-supplier | Order form "Default supplier" is optional (label + no required); backend accepts supplier_id null; per-item supplier_id remains source of truth |

### Files Changed (Phase 2A/2B/2C)
- backend/api/handlers/procurement-drafts.php, orders.php, customers.php, design-attachments.php
- backend/api/index.php (RBAC)
- backend/config/rbac.php
- backend/services/OrderExcelService.php
- frontend/js/orders.js, products.js, customers.js, procurement_drafts.js
- orders.php, products.php, procurement_drafts.php, customers.php
- frontend/css/style.css (status-declined)

### DB Changes
- Migration 033: design_attachments table (already present)
- Migration 034: orders.supplier_id nullable (already present)
- Migration 047: `order_items.hs_code`, `order_items.custom_design_required`, `order_items.custom_design_note`

### Role/Permission Impact
- design-attachments: ChinaAdmin, ChinaEmployee, WarehouseStaff, SuperAdmin
- procurement-drafts: ChinaAdmin, ChinaEmployee, SuperAdmin (existing)
- Orders: no new role changes; supplier optional for all order roles

### Manual QA Required
- [ ] Draft an Order: create a real multi-supplier draft order, verify numbering, grouped totals, edit/reopen, and submit
- [ ] Legacy migration: migrate one unmigrated procurement draft and verify already-converted drafts do not duplicate
- [ ] Shipping auto-fill: select customer with default_shipping_code, verify first item gets it
- [ ] Nullable supplier: create order without supplier, save; verify no error
- [ ] Excel export: order with sell_price on item → export shows sell_price
- [ ] Customer priority: set priority_level on customer, verify badge in list
- [ ] High-alert notes: add notes on order, save; verify badge in list, text in order info modal
- [ ] Design attachments: edit product, add design file, verify list; delete attachment
- [ ] Multi-supplier: create order with no default supplier, add items with per-item suppliers

### Phase 2D/2E (Additional Phases Completed)
| Feature | Complete because |
|---------|------------------|
| Consolidation presets from Business Settings | GET /config/container-presets returns CBM from business_settings; consolidation.js loads on init, preset buttons use fetched values |
| Order-item design attachments | Design button in item header (visible when editing saved order); modal to add/delete attachments for order_item |
| Buy/sell in product form | productBuyPrice, productSellPrice fields; products API POST/PUT accept buy_price, sell_price |
| Buy/sell in order item | item-sell-price field; product suggest pre-fills sell_price; orders API insert/update sell_price; Excel uses sell_price ?? unit_price |
| Duplicate shipping code check | checkDuplicateShippingCodes() in orders handler; warning in response when same customer has duplicate shipping codes |
| ETA offsets integration | Container PUT accepts eta_date, destination_country, destination; Consolidation: Edit modal, Suggest button uses GET /config/eta-offsets |

### Files Changed (Phase 2D/2E)
- backend/api/handlers/config.php (container-presets, eta-offsets), containers.php (PUT eta_date, destination_country, destination), orders.php (sell_price, duplicate check), products.php (buy_price, sell_price)
- backend/api/index.php (config/container-presets, eta-offsets RBAC)
- frontend/js/consolidation.js (loadContainers ETA/dest, openContainerEditModal, saveContainerEdit, suggestEtaFromOffsets), orders.js, products.js
- consolidation.php (container edit modal, ETA/Destination columns)

### Manual QA (Phase 2D/2E)
- [ ] Business Settings: change 20HQ CBM, verify consolidation Add Container preset uses it
- [ ] Edit draft order: Design button on items, add/delete design attachment
- [ ] Product form: set buy_price, sell_price; verify save and edit
- [ ] Order item: set sell_price; verify Excel export uses it
- [ ] Duplicate shipping code: create two orders same customer, same shipping code on item; verify warning on save
- [ ] Container ETA: Consolidation → Edit on container → set ETA, destination country, destination; Suggest uses ETA_OFFSETS_JSON

### Phase 3F (Financials + Customer Priority)
| Feature | Complete because |
|---------|------------------|
| Profit API sell_price | Uses sell_price ?? unit_price for sell total; buy_price ?? unit_price for buy |
| Supplier commission in profit | commission_rate/type/applied_on from suppliers; per-order commission; net_profit = gross - commission |
| Customer priority in orders | Orders list + confirmations show priority badge (non-normal) with tooltip |
| Balances resilient | SHOW TABLES check for customer_deposits, supplier_payments; graceful fallback |

### Files Changed (Phase 3F)
- backend/api/handlers/orders.php (customer_priority_level, customer_priority_note in list/single)
- backend/api/handlers/financials.php (sell_price, buy_price, commission, balances fallback)
- frontend/js/orders.js, confirmations.js (priority badge)
- frontend/js/financials.js (commission column, net profit in summary)
- financials.php (Commission column)

### Manual QA (Phase 3F)
- [ ] Customer priority: set priority_level on customer, verify badge in orders list and confirmations
- [ ] Profit: orders with sell_price/buy_price; supplier with commission_rate; verify Commission column and Net Profit
- [ ] Balances: verify loads when customer_deposits/supplier_payments exist or not

### Phase 4 (Customer Priority + Arrival Notifications)
| Feature | Complete because |
|---------|------------------|
| Customer priority in receiving | receiving API queue/receipts/search include priority; receiving_index.js, receiving.js, receiving_receive.js show badge |
| Customer priority in consolidation | consolidation.js draft add/remove order rows show badge |
| Customer priority in containers | containers API orders include priority; containers.js shows badge |
| Arrival notifications cron | backend/cron/container_arrival_notifications.php; ARRIVAL_NOTIFY_DAYS from business_settings; container_arrival_notifications table; notifyContainerArrival in NotificationService |

### Files Changed (Phase 4)
- backend/api/handlers/receiving.php (priority_level, LEFT JOIN suppliers)
- backend/api/handlers/containers.php (priority in orders, LEFT JOIN suppliers)
- backend/services/NotificationService.php (notifyContainerArrival)
- backend/cron/container_arrival_notifications.php (new)
- frontend/js/receiving_index.js, receiving.js, receiving_receive.js (priority badge)
- frontend/js/consolidation.js, containers.js (priority badge)

### Manual QA (Phase 4)
- [ ] Receiving queue/history: customer with priority shows badge
- [ ] Consolidation draft: add/remove order rows show priority badge
- [ ] Containers: order list shows priority badge
- [ ] Arrival cron: set container eta_date 7 days from today, ARRIVAL_NOTIFY_DAYS=7,3,1; run cron; verify notification and container_arrival_notifications row

### Deferred Items
- Order-item design attachments: Implemented in Phase 2D

### Known Risks
- Design attachments: upload config may restrict to images only; PDF support requires UPLOAD_ALLOWED_TYPES change

---

## Partially Completed Features

- **Supplier commission:** Schema added; integrated into profit API (per-order commission, net profit)
- **Customer priority:** Badge in customer list, orders, confirmations, receiving, consolidation, containers
- **Buy/sell price:** Excel export uses sell_price; product/order form UI done
- **Business settings:** Table created; business_settings.php exists; ETA offsets applied in container edit Suggest

---

## Migration Steps (Manual)

1. Backup database before running migrations.
2. `php backend/migrations/run.php` (or `c:\xampp\php\php.exe backend/migrations/run.php` on Windows)
3. Verify new columns exist: `DESCRIBE suppliers; DESCRIBE customers;` etc.
4. Seed expense categories if migration includes seed.

---

## Final Summary

### Completed Features
- **Migration 033** — Foundation schema for commissions, buy/sell pricing, customer priority, expenses, business settings, design attachments, procurement drafts, portal tokens, internal messages, HS code tax
- **20HQ CBM** — Fixed to 28 m³ in consolidation container presets
- **Expenses module** — Full page, API, filters, categories
- **Customer decline flow** — Decline button and reason on confirm.php; CustomerDeclined status; admin notifications

### Partially Completed
- Customer priority — full integration (list, orders, confirmations, receiving, consolidation, containers)
- Business settings — table ready; ETA offsets used in container edit Suggest
- Design attachments, procurement drafts — implemented

### Deferred / Risky Items
- Full customer portal (one-time link) — schema ready; implementation deferred
- Internal chat real-time — table ready; UI deferred
- CBM direct customers — complex flow; deferred
- Split shipments across containers — requires allocation logic; deferred

### Migration Steps
1. Backup database
2. Run `php backend/migrations/run.php` (or `c:\xampp\php\php.exe backend/migrations/run.php` on Windows)
3. Verify new tables: `expenses`, `expense_categories`, `business_settings`, `design_attachments`, `procurement_drafts`, `procurement_draft_items`, `customer_portal_tokens`, `internal_messages`, `hs_code_tax_rates`

### Manual QA Checklist
- [ ] Expenses page loads; add/edit/delete expense
- [ ] 20HQ preset shows 28 CBM in Add Container modal
- [ ] confirm.php shows Decline option; decline with reason works; internal team receives notification
- [ ] Orders filter includes CustomerDeclined
- [ ] CustomerDeclined badge displays correctly

### Future Recommended Upgrades
- Implement business settings page for notification thresholds (ETA offsets done)
- Implement procurement draft flow (draft → convert to order)
- Build profit/balances/outstanding pages
- Implement customer portal with one-time link
- Add internal message center UI

---

## Additional Ideas From Cursor

1. **Exchange rate support** — Add `exchange_rates` table (from_currency, to_currency, rate, effective_date) for multi-currency normalization in profit and balance reports.

2. **Status history table** — `order_status_history` (order_id, from_status, to_status, changed_by, changed_at) for full audit trail of order lifecycle transitions.

3. **Approval workflows** — Extend beyond single-step approve; support multi-level approval for high-value orders with configurable thresholds.

4. **Container ETA tracking** — `containers.eta_date`, `destination_country`, `destination` added; edit UI in Consolidation; ETA_OFFSETS_JSON used for Suggest. Cron `container_arrival_notifications.php` sends notifications at ARRIVAL_NOTIFY_DAYS (e.g. 7,3,1).

5. **Customer credit limit** — Add `customers.credit_limit` and validation before order submission to prevent over-exposure.

6. **Batch operations** — Bulk status change, bulk assign to container, bulk export with consistent permission checks.

7. **Dashboard widgets** — Configurable dashboard with role-based widgets (expenses this month, orders by status, container fill rates, etc.).

8. **API versioning** — Prepare for `/api/v2/` when breaking changes are needed; keep v1 stable.

9. **Staged rollout** — Feature flags in `system_config` (e.g. `FEATURE_DRAFT_ORDERS`) to enable new flows gradually.

10. **Stronger validation** — Central validation service for order items (CBM/weight sanity, unit consistency) used by both API and future imports.

---

## Independent Review Checklist

Independent review pass completed on 2026-03-14. This section is the authoritative review record for the current codebase and should be treated as higher-confidence than earlier self-reported completion notes when they conflict.

1. Supplier commissions
- Status: Complete
- Evidence: `backend/migrations/033_phase1_foundation.sql` adds supplier commission columns; `backend/api/handlers/suppliers.php` now persists `commission_rate`, `commission_type`, and `commission_applied_on`; `backend/api/handlers/financials.php` applies commission in margin calculations.
- Problem: The schema and finance math existed, but the supplier maintenance flow did not expose or validate commission settings.
- Action taken: Added supplier form/API support, validation for percentage vs fixed commissions, and hid commission data from non-finance readers.
- Manual QA needed: Create one percentage-commission supplier and one fixed-commission supplier, then verify order profit results in Financials.

2. Draft orders / draft procurement lists
- Status: Complete
- Evidence: `procurement_drafts.php` is now the Draft an Order builder, `backend/api/handlers/draft-orders.php` saves directly into real `orders` / `order_items` with `order_type='draft_procurement'`, and legacy `procurement_drafts` now serve only as a guided migration queue.
- Problem: The older procurement-draft model was single-supplier and required a second conversion step before real lifecycle handling.
- Action taken: Replaced new-save behavior with a real multi-supplier order builder, added grouped export/print, kept the old tables only for guided migration, and routed downstream order-page edit/export behavior back into the builder.
- Manual QA needed: Create and edit multi-supplier Draft an Order records, verify grouped totals/export/print, then migrate at least one unmigrated legacy procurement draft.

3. Custom product design attachments
- Status: Partially Complete
- Evidence: `backend/api/handlers/design-attachments.php`, `products.php`, `orders.php`, `frontend/js/products.js`, and `frontend/js/orders.js` support product and order-item design files.
- Problem: Attachment handling accepted arbitrary stored paths, and the UI/docs said PDFs were supported while the upload policy only allowed image types.
- Action taken: Locked attachment paths to validated uploaded files under `uploads/`, enabled PDF support in upload policy, and updated design-file inputs to accept images and PDFs.
- Manual QA needed: Upload image and PDF attachments on a product and an order item, open them back from the UI, and verify invalid/manual path injection is rejected.

4. Customer priority notifications
- Status: Complete
- Evidence: Customer priority columns exist in migration 033; priority badges are rendered in customers, orders, confirmations, receiving, consolidation, and containers; `backend/api/handlers/customers.php` now saves `priority_level` and `priority_note`.
- Problem: Priority existed in schema and downstream displays, but the customer maintenance UI/API did not fully manage it.
- Action taken: Added customer form/API support for priority level and priority note so the business can actually maintain the feature.
- Manual QA needed: Set `high` and `critical` customers and verify badge visibility and hover note across the main operational screens.

5. Order quantity flexibility
- Status: Partially Complete
- Evidence: Orders and products support cartons, pieces, and qty-per-carton; migration 033 adds `order_cartons` and `order_qty_per_carton`; `backend/api/handlers/orders.php` now persists those override columns when available.
- Problem: The order flow relied mostly on legacy `cartons` and `qty_per_carton` fields, so the "default product packing vs order override" distinction was not consistently persisted.
- Action taken: Persisted order-level packing override columns during order create/update while keeping existing UI calculations intact.
- Manual QA needed: Create orders that use product defaults, then override cartons and qty-per-carton at order level and confirm totals stay correct after save/reload.

6. Expenses module
- Status: Complete
- Evidence: `expenses.php`, `frontend/js/expenses.js`, and `backend/api/handlers/expenses.php` provide the module, categories, linking to orders/containers/customers/suppliers, and filtering.
- Problem: Updating an existing expense could not persist `supplier_id` because the editable field list omitted it.
- Action taken: Fixed the update path to include `supplier_id` alongside the other linked dimensions.
- Manual QA needed: Edit an existing expense and change the linked supplier to confirm the update persists.

7. Profit / balances / outstanding / payables / receivables
- Status: Partially Complete
- Evidence: `financials.php`, `frontend/js/financials.js`, and `backend/api/handlers/financials.php` provide profit and balances views.
- Problem: Sell-price reporting was partially overridden by `total_amount`, customer receivables could misstate sell-side totals, and expenses are not yet allocated back into order/container profitability.
- Action taken: Corrected sell-side profit and balance calculations to respect `sell_price` when present and re-verified commission handling.
- Manual QA needed: Compare a sample order using buy/sell prices against the Financials UI, then validate balances with deposits and supplier payments in place.

8. Buy price vs sell price
- Status: Complete
- Evidence: Product and order models carry `buy_price` and `sell_price`; `OrderExcelService.php` exports customer-facing amounts from `sell_price`; customer portal/confirmation pages do not render buy cost.
- Problem: The biggest leak risk came from API-level RBAC gaps and sell-side financial calculations preferring `total_amount`.
- Action taken: Hardened API permissions for key internal resources and corrected financial calculations so sell-side data is used where intended without exposing buy-side data externally.
- Manual QA needed: Verify products/orders/financial endpoints as different roles and confirm customer-facing exports still only show sell values.

9. Customer portal
- Status: Partially Complete
- Evidence: `customer_portal.php` now shows order cards with status, dates, receipt details, a simple timeline, and direct links into the existing `confirm.php` acceptance/decline flow; `backend/api/handlers/customer-portal-tokens.php` uses hashed one-time tokens.
- Problem: The portal previously acted as a thin read-only list and did not surface the pending confirmation flow in a useful way.
- Action taken: Upgraded the portal to show timelines, receipt actuals, dates, confirmation state, and direct confirm/decline handoff links; added audit logging for portal-token issuance.
- Manual QA needed: Generate a portal link, open it once, verify orders/dates/timeline, and confirm that an awaiting-confirmation order can be reviewed via the linked confirmation page.

10. Arrival / near-arrival notifications
- Status: Complete
- Evidence: `backend/cron/container_arrival_notifications.php`, `container_arrival_notifications` tracking, `business_settings.ARRIVAL_NOTIFY_DAYS`, and `NotificationService::notifyContainerArrival()` implement thresholded arrival alerts.
- Problem: None found in the main duplicate-prevention path; the cron is already keyed to prevent re-sending the same threshold notification.
- Action taken: Re-verified the duplicate-safe arrival-notification behavior during the audit.
- Manual QA needed: Set ETA and thresholds, run the cron manually, and verify one notification per threshold per container.

11. Country-specific ETA display rules
- Status: Implemented but Incorrect
- Evidence: `business_settings.php` and `ETA_OFFSETS_JSON` exist; container/consolidation logic reads ETA offsets.
- Problem: The current ETA logic is still too generic and does not fully model per-country plus per-shipment-mode behavior, especially the requested Lebanon groupage handling.
- Action taken: Re-validated the current settings path and documented the remaining logic gap rather than hardcoding a risky partial rule.
- Manual QA needed: Validate Lebanon groupage, Lebanon full-container, and non-Lebanon examples against expected ETA calculations before treating the feature as done.

12. Settings page
- Status: Partially Complete
- Evidence: `business_settings.php` and `backend/api/handlers/business-settings.php` provide a SuperAdmin-only business rules page; `admin_config.php` provides system-level configuration.
- Problem: Important rules were split across two pages, and duplicate shipping-code behavior was configurable in DB but not surfaced in the business settings UI.
- Action taken: Added duplicate shipping-code handling to Business Settings and upload-policy management to System Configuration.
- Manual QA needed: Change duplicate shipping handling and upload policy from the UI, save, reload, and verify the values are respected by the relevant flows.

13. Exports / printing / PDF / Excel
- Status: Needs Manual Verification
- Evidence: `backend/services/OrderExcelService.php`, `backend/api/handlers/orders.php`, and `backend/api/handlers/containers.php` provide order and container Excel export paths.
- Problem: The export logic exists, but invoice/warehouse-invoice layout and print fidelity still need human output verification; nullable top-level supplier orders also risked being skipped in container export.
- Action taken: Fixed container export to use `LEFT JOIN suppliers` so supplier-null orders are not dropped and re-checked sell-price-only export behavior.
- Manual QA needed: Generate single-order and container exports, plus any invoice/warehouse-invoice prints, and inspect both layout and pricing columns manually.

14. Multi-status checkbox filtering
- Status: Complete
- Evidence: `orders.php` / `frontend/js/orders.js`, `containers.php` / `frontend/js/containers.js`, and `warehouse_stock.php` / `frontend/js/warehouse_stock.js` now expose checkbox-based multi-status filters with include/exclude modes; `backend/api/handlers/orders.php`, `backend/api/handlers/containers.php`, and `backend/api/handlers/warehouse-stock.php` now honor `status[]` plus `status_mode`.
- Problem: The earlier implementation only allowed single-status filtering on key list screens.
- Action taken: Replaced the main single-select filters with real checkbox-based include/exclude filters and aligned the backend query handling.
- Manual QA needed: Verify include and exclude combinations on orders, containers, and warehouse stock against expected counts/results.

15. Container destination + money tracking
- Status: Partially Complete
- Evidence: Container destination fields exist and are editable; containers show totals and capacity usage.
- Problem: Destination metadata is present, but container-level money/cost tracking is not yet deeply integrated into the workflow or reporting.
- Action taken: Re-validated destination handling and documented the incomplete money-tracking portion.
- Manual QA needed: Confirm whether container-level cost capture belongs in Expenses only or also requires native container financial fields/UI.

16. High-alert comments / special handling notes
- Status: Partially Complete
- Evidence: `orders.php` and `frontend/js/orders.js` already surfaced high-alert notes; `backend/api/handlers/receiving.php`, `backend/api/handlers/containers.php`, `frontend/js/receiving.js`, `frontend/js/receiving_receive.js`, and `frontend/js/containers.js` now surface them in more operational screens.
- Problem: High-alert notes previously existed but were too easy to miss outside the main orders UI.
- Action taken: Added high-alert visibility in receiving and container assignment/detail views.
- Manual QA needed: Create a high-alert order and verify it is visible in orders, receiving queue, receiving detail, and container screens.

17. Auto-insert shipping code from customer
- Status: Complete
- Evidence: Orders UI already called `applyCustomerDefaultShippingCode()` from the selected customer; `backend/api/handlers/customers.php` plus `customers.php`/`frontend/js/customers.js` now let staff maintain `default_shipping_code`.
- Problem: The auto-fill logic existed, but there was no stable maintenance path for the customer default shipping code.
- Action taken: Added UI/API support for maintaining customer default shipping code.
- Manual QA needed: Set a customer default shipping code, create a new order, pick that customer, and confirm the item row auto-fills it.

18. 20HQ container CBM fix
- Status: Complete
- Evidence: Runtime settings and consolidation presets use `28`; search re-check found one stray `20HQ` seed constant in `scripts/seed_consolidation_export_dataset.py`.
- Problem: A non-runtime seed script still used `33.2`, which could reintroduce confusion in test data.
- Action taken: Updated the seed script to use `28.0` for `20HQ` as well.
- Manual QA needed: Re-run the seed script if it is still used and verify seeded 20HQ containers are created at `28.0` CBM.

19. Removal/redesign of top-level supplier field
- Status: Partially Complete
- Evidence: `orders.supplier_id` is nullable and `order_items.supplier_id` exists; `backend/api/handlers/orders.php` uses item-level suppliers; confirm/container export joins were re-checked.
- Problem: Some flows still assume a top-level supplier for summaries and historical reporting, so nullable supplier compatibility is improved but not fully universal.
- Action taken: Fixed `confirm.php` and container export joins to stop excluding supplier-null orders and re-checked the main order-item supplier fallback logic.
- Manual QA needed: Push a supplier-null / multi-supplier order through confirmation, export, and container assignment to identify remaining top-level supplier assumptions.

20. Admin notifications for cross-supplier price differences
- Status: Complete
- Evidence: `backend/api/handlers/orders.php` now detects same-`product_id`, same-currency, still-not-received cross-supplier price differences; `NotificationService.php` sends an admin notification; audit-log signatures prevent duplicate repeats for the same match set.
- Problem: The feature was missing entirely.
- Action taken: Added a narrow, low-noise notifier that only compares open orders (`Draft`, `Submitted`, `Approved`, `InTransitToWarehouse`) and only when the same product carries different supplier prices.
- Manual QA needed: Save an order with a product already quoted under another supplier at a different unit price and confirm admins receive one alert only.

21. Internal communication channel/chat
- Status: Partially Complete
- Evidence: `backend/api/handlers/internal-messages.php` and the customer Messages modal in `frontend/js/customers.js` exist.
- Problem: The channel is still mostly customer-scoped in the UI rather than deeply tied to order/container context, and audit logging was missing.
- Action taken: Added audit-log entries for message creation; documented the remaining context/threading gap.
- Manual QA needed: Send messages for a customer and verify audit rows plus message visibility; decide whether order/container thread views are required next.

22. Duplicate shipping code checks
- Status: Complete
- Evidence: `backend/api/handlers/orders.php` already checked duplicate item shipping codes; `backend/api/handlers/customers.php` now checks duplicate customer default shipping codes; `business_settings.php` exposes `SHIPPING_CODE_DUPLICATE_ACTION`.
- Problem: Duplicate checks were warning-only, and customer create/edit did not participate.
- Action taken: Added configurable warn/block behavior for both customer default shipping codes and order-item shipping code checks.
- Manual QA needed: Set duplicate mode to `warn` and `block`, then verify customer save and order save behavior in both modes.

23. Editability of orders/products/containers
- Status: Partially Complete
- Evidence: Products and containers are editable; draft orders are editable; container/order actions are audited in several paths.
- **Resolved:** Order editability extended to all statuses (PUT /orders/{id} and editOrder() no longer restrict to Draft). Audit trail remains via audit_log.
- Action taken: Kept the draft-only guardrail, re-checked container write permissions, and preserved audit-log writes on key mutations.
- Manual QA needed: Attempt edits at each major status boundary and confirm blocked states fail safely without corrupting linked data.

24. Split large customer shipments across multiple containers/batches
- Status: Implemented but Unsafe
- Evidence: Orders can be added to multiple shipment drafts and the UI warns about it; however, allocation is still whole-order, not item- or quantity-level.
- Problem: The current approach allows operationally ambiguous multi-draft assignment without true partial allocation tracking.
- Action taken: Documented this as unsafe rather than overstating it as complete.
- Manual QA needed: Do not rely on this for true split-shipment operations until item-level allocation tables and status logic exist.

25. Calendar / timeline / planning / notifications
- Status: Partially Complete
- Evidence: `calendar.php` exists; notifications and arrival alerts exist; the portal now has a lightweight order timeline.
- Problem: Planning/timeline views are still basic and not yet a strong operational calendar with dense event coverage.
- Action taken: Upgraded the customer portal timeline and re-verified notification linkage, but did not rebuild the internal calendar UX in this pass.
- Manual QA needed: Review whether the existing calendar plus notifications meets planning needs or whether a richer event board is required.

26. Customer decline flow with reason
- Status: Complete
- Evidence: `backend/api/handlers/confirm.php` requires a minimum reason on decline, records audit data, and calls `NotificationService::notifyOrderDeclined()`.
- Problem: No blocking issue found in the main decline flow.
- Action taken: Re-verified the decline path and confirmed the internal notification and audit trail behavior.
- Manual QA needed: Decline a confirmation from `confirm.php` and verify the reason, order status, notification, and audit row.

27. CBM / warehouse-direct customers flow
- Status: Partially Complete
- Evidence: Supplier-null orders are supported more safely than before; receiving variance notifications already support customer discrepancy handling.
- Problem: There is still no dedicated warehouse-direct / supplier-free operational path or specialized UI section for this workflow.
- Action taken: Improved supplier-null compatibility in confirmation/export-related joins and documented the remaining dedicated-flow gap.
- Manual QA needed: Run a supplier-null order through create, receive, confirm, and export flows to validate current business acceptability.

28. HS code / import tax calculation page
- Status: Complete
- Evidence: `hs_code_tax.php`, `frontend/js/hs_code_tax.js`, and `backend/api/handlers/hs-code-tax.php` now provide an internal rate library plus an estimate tool for manual HS-code, product, order, and container contexts on top of `hs_code_tax_rates`.
- Problem: The platform previously had schema only, with no usable page or calculator.
- Action taken: Added an admin-only HS code tax module with searchable/editable rate records, country-specific lookup, and planning estimates using stored pricing with explicit valuation-mode control.
- Manual QA needed: Populate representative rates, run product/order/container estimates, and confirm the business wants the current buy-first default valuation mode.

29. Warehouse stock page
- Status: Partially Complete
- Evidence: `warehouse_stock.php`, `frontend/js/warehouse_stock.js`, and `backend/api/handlers/warehouse-stock.php` provide the page plus customer/supplier/status/search filters.
- Problem: The page is useful, but filtering/reporting is still fairly basic and not yet country-aware or deeply warehouse-analytics-focused.
- Action taken: Re-verified the page and documented it as useful-but-not-finished rather than overstating completion.
- Manual QA needed: Confirm the current columns and filters are sufficient for warehouse operations, especially around country/container slicing.

30. Professionalization / international-grade improvements
- Status: Partially Complete
- Evidence: The codebase now has stronger audit logs, safer API permissions for major internal resources, duplicate-safe arrival notifications, improved finance correctness, and better field management for business rules.
- Problem: Major enterprise-grade gaps still remain, especially full status-history tables, richer finance traceability, and safer true partial-allocation shipping logic.
- Action taken: Prioritized substantive backend/business-rule fixes over cosmetic UI changes and documented the remaining structural upgrades clearly.
- Manual QA needed: Treat the remaining risks section below as the operational follow-up list before calling the platform fully production-hardened.

## Independent Review Findings

1. The largest real risk was not missing screens; it was trust gaps between schema, UI, and permissions. Several requested fields already existed in the database but were not truly manageable or safely exposed.
2. API RBAC was too permissive for key internal resources. The review pass hardened `orders`, `customers`, `products`, and container writes at the router level and tightened supplier-role handling in the handler itself.
3. Finance had a material correctness issue: sell-side reporting could prefer `total_amount` even when `sell_price` existed. That is now corrected in Financials.
4. Supplier commissions and customer priority/default shipping code were present structurally but not operationally maintainable. That gap is now closed.
5. Attachment handling needed a security correction. Design and shipment document references are now constrained to validated uploaded files under `uploads/`.
6. The customer portal was too thin for the requested behavior. It now surfaces dates, receipt details, a basic timeline, and direct handoff into the existing confirm/decline flow.
7. Several items remain genuinely incomplete, not merely unverified: robust ETA mode logic, true split-shipment allocation, and deeper finance allocation/reporting completeness.

## Fixes Applied In Review Pass

- Hardened API permissions in `backend/api/index.php` and `backend/config/rbac.php` for major internal resources and aligned container write roles with the actual admin UI.
- Added supplier commission maintenance and validation in `backend/api/handlers/suppliers.php`, `suppliers.php`, and `frontend/js/suppliers.js`.
- Added customer priority/default shipping code maintenance plus duplicate-check policy enforcement in `backend/api/handlers/customers.php`, `customers.php`, `frontend/js/customers.js`, `backend/api/handlers/orders.php`, and `business_settings.php`.
- Added duplicate shipping warn/block validation to Business Settings and surfaced upload policy fields in System Configuration.
- Fixed supplier-linked expense editing in `backend/api/handlers/expenses.php`.
- Corrected financial sell-side and receivable calculations in `backend/api/handlers/financials.php`.
- Locked design/shipment attachment paths to validated uploads in `backend/api/helpers.php`, `backend/api/handlers/design-attachments.php`, and `backend/api/handlers/shipment-drafts.php`.
- Enabled safe PDF attachment support through `backend/config/config.php`, `backend/api/handlers/upload.php`, `backend/api/handlers/config.php`, `frontend/js/upload-utils.js`, `products.php`, `orders.php`, and `admin_config.php` / `frontend/js/admin_config.js`.
- Improved customer portal behavior in `customer_portal.php` and added audit logging in `backend/api/handlers/customer-portal-tokens.php`.
- Fixed nullable-supplier compatibility in `backend/api/handlers/confirm.php` and `backend/api/handlers/containers.php` export logic.
- Expanded high-alert visibility in receiving and container operations via `backend/api/handlers/receiving.php`, `backend/api/handlers/containers.php`, `frontend/js/receiving.js`, `frontend/js/receiving_receive.js`, and `frontend/js/containers.js`.
- Added audit logging for internal messages in `backend/api/handlers/internal-messages.php`.
- Added narrow, duplicate-safe cross-supplier price-difference admin alerts in `backend/api/handlers/orders.php` and `backend/services/NotificationService.php`.
- Removed the remaining stray 20HQ seed constant mismatch in `scripts/seed_consolidation_export_dataset.py`.
- Replaced single-select status filters with checkbox include/exclude filters across orders, containers, and warehouse stock, and aligned the corresponding backend handlers.
- Added a real internal HS code tax module in `hs_code_tax.php`, `frontend/js/hs_code_tax.js`, and `backend/api/handlers/hs-code-tax.php`, with RBAC and navigation integration.

## Remaining Risks / Manual QA Needed

1. ETA offsets are still not strong enough for the requested per-country plus per-shipment-mode rules, especially Lebanon groupage logic.
2. Split shipments across multiple containers remain unsafe because assignment is still whole-order and not allocation-based.
3. Financial reporting is improved, but expenses are still not allocated back into order/container profitability in a business-complete way.
4. Exports and print layouts need human verification, especially any invoice or warehouse-invoice outputs beyond the Excel code paths reviewed here.
5. The customer portal is much better aligned now, but it still uses single-use tokens and hands confirmation/decline off to the dedicated confirmation page rather than keeping the entire flow in one surface.
6. Supplier-null / warehouse-direct workflows need business QA before being called complete.
7. The new HS code tax module still needs real tariff data entry and business validation of valuation assumptions before it should be used operationally.

## Final Compliance Against Requested Features

- Complete: 12 / 30
- Partially Complete: 15 / 30
- Missing: 0 / 30
- Implemented but Incorrect: 1 / 30
- Implemented but Unsafe: 1 / 30
- Needs Manual Verification: 1 / 30

High-confidence completed items after this review pass:
- Supplier commissions
- Customer priority management
- Expenses module core flow
- Buy vs sell price separation
- Arrival notifications
- Multi-status checkbox filtering
- Auto-insert shipping code from customer
- 20HQ CBM fix
- Cross-supplier price-difference admin alerts
- Duplicate shipping code warn/block handling
- Customer decline flow with reason
- HS code / import tax module

## Additional Ideas From Codex Review

1. Add an `order_status_history` table and write every status transition there. The current audit log is useful, but a first-class status history table would make workflow reviews, SLA tracking, and dispute resolution much stronger.
2. Extend the new HS code tax module with landed-cost rules per destination country once the business confirms the customs valuation method and tariff maintenance process.
3. Replace the current one-time portal page with a short-lived signed session portal if customers are expected to revisit status multiple times before shipment completion.
4. Introduce true item-level shipment allocation tables before expanding split-shipment operations. This is the most important remaining structural workflow gap.

## 2026-03-16 HS Code Tariff Catalog Fix

- Reproduced the failure end-to-end: Admin Config import succeeded, but Products page HS code autocomplete and HS Code Tax catalog search both failed with `500 Internal Server Error` on `GET /api/v1/hs-code-catalog/search?q=...`.
- Root cause 1: `backend/api/handlers/hs-code-catalog.php` was binding `LIMIT ?` as a normal execute parameter, which MariaDB rejected and logged as a SQL syntax error.
- Root cause 2: `frontend/js/autocomplete.js` treated `searchPath: ""` as falsy and silently changed HS catalog lookups to `/search`, even though the intended contract is `GET /hs-code-catalog?q=...`.
- Fix applied: the HS catalog handler now safely supports both `/hs-code-catalog` and `/hs-code-catalog/search` for backward compatibility and uses a validated integer `LIMIT` in SQL; the shared autocomplete helper now honors an explicitly empty `searchPath`.
- Downstream consistency updates: `admin_config.php` now states the importer feeds both Products and HS Code Tax, `hs_code_tax.php` now cache-busts the shared autocomplete and page script, and UI smoke coverage now imports the catalog then verifies autocomplete on both Products and HS Code Tax.
- Verification: imported `6131` rows from `hs codes/lebanon_customs_tariffs.csv`, then verified autocomplete results for `0101` on Products and HS Code Tax after the fix.

## 2026-03-16 HS Code Catalog Prefix-Matching Follow-up

- Reproduced the ranking issue after the import fix: short numeric queries such as `96`, `85`, and `59` were still using broad `LIKE %query%` matching, so the dropdowns were filled with unrelated codes like `0304.96` or `0302.59` before the actual `96xx` / `85xx` / `59xx` chapters.
- Root cause: `backend/api/handlers/hs-code-catalog.php` still searched `hs_code`, `name`, and `category` with generic contains matching and then limited early, so relevant prefix matches were pushed out of view.
- Fix applied: the shared HS catalog endpoint now detects numeric HS-code searches, normalizes codes by removing dots/spaces/hyphens, and returns prefix matches from the opening digits first. Text searches now rank prefix matches ahead of contains matches. The response also returns `meta.total`, `meta.returned`, `meta.limit`, `meta.truncated`, and `meta.match_mode`.
- Downstream updates from the impact review:
  - `frontend/js/autocomplete.js` now supports per-field result limits so HS-code consumers can request deeper dropdowns without changing every autocomplete in the system.
  - `frontend/js/products.js` now requests deeper HS catalog result sets for both the Products filter and the Add Product modal.
  - `backend/api/handlers/products.php` now applies prefix-style HS filtering for numeric HS-code filters so the Products list behaves consistently when a user types a code fragment and clicks Apply without selecting from the dropdown first.
  - `backend/api/handlers/hs-code-tax.php` now applies the same prefix-first rule to the Tax Rate Library HS-code search, so the two search surfaces on the HS Code Tax page no longer disagree.
  - `hs_code_tax.php` and `frontend/js/hs_code_tax.js` now show a catalog-search summary and request a much larger result window for the table view, so users can see whether results are truncated instead of silently missing matches.
  - `docs/API.md`, `tests/hs_code_catalog_test.php`, and `scripts/ui_smoke_checks.mjs` were updated to reflect and guard the new prefix-first behavior.
- Verification:
  - Typing `96` in the HS Code Tax estimator now starts with `9601.10`, `9601.90`, `9603.10`, ... rather than unrelated `...96` codes.
  - Typing `85` in the Products HS filter now starts with `8501.10`, `8501.31`, `8501.33`, ... rather than unrelated `...85` codes.
  - The HS Code Tax catalog table now reports what it is showing, for example: `Showing 1 match for "8501.10". Showing HS code matches from the first digits.`

## 2026-03-16 Tablet Landscape Sidebar Shell Fix

- Reproduced the layout issue in a real browser at `1024x768`: the shared sidebar shell treated tablet landscape as desktop collapsed mode, leaving a thin dark rail with clipped labels instead of properly hiding the sidebar.
- Root cause: the shared sidebar breakpoint logic was split around Bootstrap's `lg` breakpoint (`992px`), so widths like `1024px` still used the desktop collapsed-rail behavior. The close button in `includes/layout.php` also used `d-lg-none`, which hid the close control exactly where the tablet overlay behavior was needed.
- Fix applied:
  - `frontend/js/sidebar.js` now switches the shell to overlay-sidebar mode below `1200px`, keeps full desktop collapse behavior above that breakpoint, updates `aria-expanded` / `aria-hidden`, supports `Esc` to close, and locks body scroll while the overlay is open.
  - `frontend/css/style.css` now styles overlay mode through the shared `body.sidebar-overlay-mode` class so tablet landscape and smaller screens get a true off-canvas drawer with full-width content behind it instead of the clipped desktop rail.
  - `includes/layout.php` removes the Bootstrap-only `d-lg-none` dependency from the shared close button and cache-busts the shared layout stylesheet.
  - `includes/footer.php` cache-busts the shared sidebar script so the fix reaches every page immediately.
- Downstream impact review:
  - Verified all root pages using `includes/layout.php` inherit the change: dashboard, orders, receiving, confirmations, pipeline, consolidation, containers, assign to container, expenses, financials, HS Code Tax, calendar, warehouse stock, procurement drafts, suppliers, customers, products, notifications, preferences, and superadmin pages.
  - No backend handlers, DB queries, totals, exports, notifications, or permissions required updates because this is a shared presentation-shell fix, not a workflow/data change.
- Verification:
  - At `1024x768`, the sidebar now hides completely and the page content uses the full width.
  - Opening the sidebar at `1024x768` now shows a proper overlay drawer with backdrop and a visible close button.
  - At `1366x768`, the normal desktop collapsed sidebar still works as before.
  - Browser verification passed on the dashboard and orders page with no console errors.

## 2026-03-16 Desktop Collapsed Sidebar Follow-up

- Follow-up issue reproduced on `admin_users.php` at full desktop width after the tablet fix: the desktop collapsed rail still showed clipped label text (`Exp...`, `Fin...`, `HS...`) because most sidebar links in `includes/layout.php` use raw text nodes instead of wrapped label spans.
- Root cause: the existing collapsed CSS only hid `.sidebar-link span`, `.badge`, and `.sidebar-user-name`, so plain link text and the `CLMS` brand text remained visible and leaked outside the intended icon-only rail.
- Fix applied in the shared shell CSS:
  - `frontend/css/style.css` now zeroes desktop collapsed link text with `font-size: 0`, preserving the icons while preventing text-node bleed.
  - The collapsed brand area now centers and renders a compact `C` monogram instead of leaving the full `CLMS` text clipped in the narrow rail.
- Downstream impact review:
  - The fix applies to every internal page using the shared layout, including admin pages such as `admin_users.php`, because the sidebar is rendered once in `includes/layout.php`.
  - No backend, DB, export, notification, or permission logic changed because this is still a shared presentation-shell correction only.
- Verification:
  - At `1920x1080`, `admin_users.php` now collapses to a clean icon rail with no clipped text.
  - At `1024x768`, the overlay sidebar behavior from the tablet fix still works correctly after the desktop follow-up.

## 2026-03-16 User Management System (UMS)

- **Create User:** POST /users with email, full_name, password, roles[], department_ids[]. + Create User button and modal on admin_users.php.
- **User Activity:** GET /users/{id}/activity — filterable audit_log entries for a user. Activity panel on admin_users.php (collapsible, filters: entity_type, action, date).
- **Migration 043:** idx_audit_user on audit_log (user_id) for efficient user filtering.
- **Audit Log enhancements:** action filter; GET /audit-log/users for user dropdown; expanded entity_type options (user, system_config, internal_message, procurement_draft, customer_portal_token).
- **Downstream:** RBAC unchanged (users, audit-log); docs/API.md updated; admin_audit_log.php user dropdown and action filter.

## 2026-03-16 UMS Phase 4: Activity Sources & created_by

- **customer_confirmations:** Admin confirmations (confirmed_by = user_id) now appear in user activity as order+confirm events. Audit_log order+confirm rows excluded to avoid duplicates.
- **created_by/uploaded_by synthetic events:** Orders, expenses, supplier_interactions, procurement_drafts, order_templates, customer_deposits, customer_portal_tokens, design_attachments — rows where created_by or uploaded_by = user_id appear as action=create in the activity feed. Shown when entity_type/action filters allow (e.g. entity_type=order, action=create).
- **Activity panel:** Entity type filter extended with expense, order_template, customer_deposit, supplier_interaction. Entity links added for all types (orders.php, expenses.php, procurement_drafts.php, financials.php, suppliers.php).
- **Downstream:** docs/API.md updated; no RBAC, migration, or export changes.

## 2026-03-16 UMS Create User Button Fix

- **Issue:** "+ Create User" button did not respond to clicks on admin_users.php.
- **Root cause:** Page script used relative path `frontend/js/admin_users.js`, which could 404 or load from wrong base in some deployments; no cache busting caused stale script.
- **Fix:** Use absolute path `/cargochina/frontend/js/admin_users.js` with filemtime cache busting; attach click handler via `addEventListener` in DOMContentLoaded instead of inline onclick for reliability. Same pattern applied to admin_audit_log.js.

## 2026-03-16 Full QA Reset + Seed Dataset

- Implemented a new safe reset-and-seed tool at `scripts/reset_and_seed_full_test_dataset.py`.
- The tool does a full downstream-safe reseed instead of an isolated table patch:
  - creates a SQL backup with `mysqldump`
  - archives `backend/uploads`
  - preserves auth/system/reference tables only: `users`, `roles`, `user_roles`, `departments`, `user_departments`, `user_notification_preferences`, `_migrations`, `system_config`, `business_settings`, `countries`, `expense_categories`, `hs_code_tariff_catalog`
  - wipes business/workflow data and repopulates realistic test records
- Seeded dataset coverage:
  - `15` customers with priority levels, notes, contacts, addresses, payment links, and default shipping codes
  - `12` suppliers with commissions, contacts, additional IDs, and notes
  - `60` products with varied dimensions, measurement scopes, packaging, buy/sell pricing, HS codes, images, alerts, description entries, and design attachments
  - `30` orders spanning every important workflow status
  - `9` containers:
    - `4` full/on-route
    - `3` almost filled / `to_go`
    - `2` planning / early fill
  - `10` shipment drafts, `26` warehouse receipts, `5` procurement drafts, `6` order templates
  - finance coverage via expenses, customer deposits, supplier payments
  - customer-facing coverage via portal tokens and confirmation links
  - internal coverage via notifications, delivery logs, internal messages, supplier interactions, audit rows, tax rates, arrival notifications, and tracking push logs
- Seed verification after run:
  - `customers=15`
  - `suppliers=12`
  - `products=60`
  - `orders=30`
  - `order_items=60`
  - `containers=9`
  - `shipment_drafts=10`
  - `warehouse_receipts=26`
  - `expenses=28`
  - `customer_deposits=10`
  - `supplier_payments=12`
  - `procurement_drafts=5`
  - `order_templates=6`
  - `notifications=10`
  - `customer_portal_tokens=5`
- Workflow distribution after run:
  - `FinalizedAndPushedToTracking=10`
  - `AssignedToContainer=10`
  - `ConsolidatedIntoShipmentDraft=1`
  - `Draft=1`
  - `Submitted=1`
  - `Approved=1`
  - `InTransitToWarehouse=1`
  - `ReceivedAtWarehouse=1`
  - `AwaitingCustomerConfirmation=1`
  - `Confirmed=1`
  - `ReadyForConsolidation=1`
  - `CustomerDeclined=1`
- Container fill verification after run:
  - `FULLQA-CTR-001` `94.5%`
  - `FULLQA-CTR-002` `88.6%`
  - `FULLQA-CTR-003` `90.2%`
  - `FULLQA-CTR-004` `87.7%`
  - `FULLQA-CTR-005` `86.0%`
  - `FULLQA-CTR-006` `82.7%`
  - `FULLQA-CTR-007` `79.8%`
  - `FULLQA-CTR-008` `28.2%`
  - `FULLQA-CTR-009` `21.4%`
- Generated outputs:
  - backup directory under `output/reset_seed_backups/`
  - manifest under `output/seed_manifests/` with raw portal links and confirmation links for manual QA
- Validation:
  - `npm run smoke:ui` passed after reseeding
  - portal token flow and public confirmation flow were manually verified in-browser using the generated manifest links

## 2026-03-16 Item-Level Commission Calculation (Multi-Supplier Orders)

- **Requirement:** Commission is received from suppliers. Orders can have multiple products and multiple suppliers with different commission rates; some suppliers have no commission.
- **Change:** Financials API now calculates commission at **item level** instead of order level:
  - For each order_item: effective supplier = COALESCE(oi.supplier_id, p.supplier_id)
  - Base value = buy_value (quantity × buy_price) or sell_value (quantity × sell_price) per supplier's commission_applied_on
  - Percentage: commission = base × rate / 100 per item
  - Fixed: commission added once per supplier per order (no per-item multiplication)
- **Supplier filter:** When filtering by supplier_id, orders are included if they have at least one item from that supplier (item-level or product-level supplier_id).
- **Fallback:** When order_items has no supplier_id column (legacy), commission falls back to order-level supplier (o.supplier_id).
- **Affected:** `backend/api/handlers/financials.php` — profit endpoint. Financials page, filters, and summary cards unchanged (API contract preserved).

## 2026-03-18 Draft Order Builder Description Simplification

- `frontend/js/procurement_drafts.js`
  - Draft an Order items now use a single visible description field instead of multi-row dual-language inputs.
  - Existing saved CN/EN description pairs are preserved on reopen; if the user edits the single field, the cached pair is cleared and rebuilt on save.
  - Product autofill no longer auto-checks `Custom design`; that checkbox now stays off by default unless the saved draft item already had it enabled.
- `backend/api/handlers/draft-orders.php`
  - Draft-order normalization now auto-completes missing Chinese/English description sides using `TranslationService`, so the simplified builder still stores `description_cn` and `description_en` consistently for order items, product creation, search, and export/print.
- `backend/services/TranslationService.php` and `backend/api/handlers/translations.php`
  - Translation stubs now respect the target language tag (`[EN]`, `[ZH]`, etc.) so server-side CN/EN auto-fill does not incorrectly label all generated values as English.

## 2026-03-23 Customer Portal / POR / Supplier Attachments / Container Totals / Customer Create RBAC

- Customer portal flow was completed from the existing token-based implementation instead of rebuilding it:
  - `customers.php` now exposes a clearer `Portal Link` action for eligible users and shows recent one-time portal links in the modal.
  - `frontend/js/customers.js` now loads portal-link history through the existing `customer-portal-tokens` API and keeps the generated link accessible for copy/open actions.
  - Existing `customer_portal.php` and `backend/api/handlers/customer-portal-tokens.php` were reused as the customer-facing status flow.
- Customers now support structured multi-value `por` data:
  - Added migration `backend/migrations/051_customer_pors.sql`
  - `backend/api/handlers/customers.php` now persists POR values in `customer_pors`, returns them on list/detail responses, and includes POR values in customer search.
  - `customers.php` / `frontend/js/customers.js` now provide repeatable POR inputs and show POR badges in the customer list.
- Customer create/import is now restricted to admin roles at both UI and API layers:
  - `customers.php` hides add/import actions for non-admin users.
  - `frontend/js/customers.js` blocks create/import actions client-side for non-admin users.
  - `backend/config/rbac.php` and `backend/api/index.php` now enforce `customers.create` and `customers.import` for `ChinaAdmin` and `SuperAdmin` only, without broadening or breaking the existing edit permissions.
- Supplier add/edit now supports safe documents/photos by reusing the existing validated upload and design-attachment flow:
  - `backend/api/handlers/design-attachments.php` now supports `entity_type = supplier`
  - `suppliers.php` / `frontend/js/suppliers.js` now show existing supplier attachments and allow upload/delete after the supplier exists.
- Container totals were corrected and deduplicated:
  - `backend/api/handlers/containers.php` now derives per-order totals from order items using order-level carton/packing overrides where available, returns a modal totals payload, deduplicates container usage/order aggregation across shipment drafts, and fixes the container list/export/assignment math to use the same source-of-truth.
  - `frontend/js/containers.js` now renders totals from the API instead of re-summing mixed legacy fields in the browser.
- Validation:
  - PHP syntax checks passed for all changed PHP files.
  - JS syntax checks passed for all changed JS files.
  - `npm run smoke:ui` passed after updating smoke coverage for customers, suppliers, containers, and the current procurement-drafts UI.

## 2026-03-25 Orders / Destination Filtering / Auto-Confirm Follow-Up / Financial Settlement Pass

- Customer countries remain stored in `customer_country_shipping`, and `orders.destination_country_id` remains the canonical persisted destination field.
  - `backend/services/OrderCountryService.php` is now the reusable source of truth for validating customer destination-country choices, deriving shipping code from customer + country, and resolving text-only container destinations into canonical `countries` rows.
  - Orders UI now reflects the new customer-follow-up model and supports direct `customer_feedback` filtering.
- Canonical item numbering is now centralized in `backend/services/OrderItemNumberingService.php` and applied by both orders and draft-order handlers, while frontend previews in `frontend/js/orders.js` and `frontend/js/procurement_drafts.js` mirror the same `shipping-supplierSequence-itemSequence` format.
- Procurement Drafts quick-add supplier reuses the existing supplier master-data and upload pipeline instead of introducing a draft-only supplier model.
  - It saves through `/suppliers`, defaults payment facility to 30 days when left empty, stores payment links on the supplier record, and uploads supplier card/photos through the existing validated upload + attachment flow.
- Receiving no longer blocks on manual confirmation when damaged or variance receipts are recorded.
  - Orders are auto-confirmed into stock, receive a customer follow-up token, and can later move to `CustomerDeclinedAfterAutoConfirm`.
  - Internal UI, dashboard, pipeline, notifications, portal, and the retired confirmations page were updated to reflect customer follow-up rather than the old blocking confirmation queue.
  - Resetting a declined-after-auto-confirm order moves it back to `Submitted` and operationally voids the receipt when the schema supports receipt void columns.
- Container assignment and suggestion flows now respect destination country on both the backend and frontend.
  - Containers API responses now include resolved destination-country metadata even though container records still store text destination fields.
  - Assign-to-container screens only surface destination-compatible orders and no longer auto-suggest mixed-country fills.
- Financial balances/profit views now consistently treat `CustomerDeclinedAfterAutoConfirm` as a declined state in default scope messaging, while settlement-aware supplier payments continue to record the expected invoice amount, actual paid amount, `settlement_delta`, `settlement_mode`, and `settlement_note`.

## Delta Patch Notes — 2026-03-25
- Froze automatic order item renumbering once a real order leaves Draft status. Orders in Submitted and later statuses now keep incoming item_no values on normal edit/resave; canonical renumbering remains active for Draft flows only.
- Hardened supplier duplicate prevention in the shared suppliers create/update handler with likely-duplicate blocking on exact store_id and strong name+phone matches, while keeping unique supplier code enforcement intact.
- Centralized the frontend interpretation of auto-confirmed pending-review orders so Confirmed orders with a live confirmation_token are excluded from shipment/consolidation assignment queues unless the customer follow-up is complete.
- Kept the current warehouse stock model as workflow visibility rather than introducing a new inventory-ledger reversal path in this patch.

## Delta Patch Notes — 2026-03-25 Procurement Draft Destination Country
- `procurement_drafts.php` now includes a destination-country field in the Draft Order builder and uses the same customer-country source of truth as Orders.
- `frontend/js/procurement_drafts.js` now auto-loads customer countries after customer selection:
  - one customer country: auto-selects and locks it
  - multiple customer countries: shows a restricted dropdown of only that customer’s countries
  - no mapped customer countries: falls back to the existing country search field
- `backend/api/handlers/draft-orders.php` now validates and persists `destination_country_id` for draft-procurement orders through `OrderCountryService`, and canonical draft item numbering now derives shipping code from the selected destination country when present.
- Draft order reload/edit and CSV export now include the saved destination country so the builder, persistence, and exported record stay aligned.

## Delta Patch Notes — 2026-03-25 Procurement Draft Visual Grouping
- Added soft repeating background palettes for supplier sections inside the Draft Order builder so each supplier block is easier to separate visually during long drafting sessions.
- Added lighter nested item-card surfaces inside each supplier section so product lines read as grouped within their supplier while staying distinct from adjacent sections.
- This was implemented as a CSS-only UX pass in `frontend/css/style.css` to avoid changing draft save, collapse, totals, or numbering behavior.

### 2026-03-25 - Warehouse Stock order info button
- Added an `Info` action to [warehouse_stock.php](/c:/xampp/htdocs/cargochina/warehouse_stock.php) rows so operators can inspect the full order without leaving the stock view.
- Reused the existing `/orders/{id}` detail payload pattern already used elsewhere instead of creating a warehouse-only endpoint.
- Included order header details, item lines, attachments, and receipt photos in the stock modal.

- 2026-03-25: Strengthened Draft an Order visual grouping so each supplier section has a clearer soft-tint panel and each item card has a nested tinted surface with an accent edge for faster scanning in large drafts.

## Delta Patch Notes — 2026-03-25 Customer Visibility and Containers Staff
- Restricted the root Customers page to ChinaAdmin and SuperAdmin with matching sidebar/dashboard visibility changes, so non-admin users no longer see Customers in navigation and direct /customers.php access returns Access Denied.
- Hid Notification Preferences from non-admin roles at both the sidebar and direct-page level by adding a page guard to 
otification_preferences.php and removing the sidebar link unless the user is an admin.
- Added the ContainersStaff role through migration  54_containers_staff_role.sql, exposed it in User Management, and granted it access only to the container-operations pages and supporting APIs needed for Consolidation, Containers, Assign to Container, and Warehouse Stock.


## Delta Patch Notes — 2026-03-26 APP_URL Runtime Fix
- Fixed the production URL propagation gap by loading APP_URL into ackend/config/config.php as pp_url, with a normalized fallback to the current request URL or http://localhost/cargochina only when no explicit value exists.
- Updated ackend/api/handlers/config.php so Admin Config now reads back the stored APP_URL into the pp_url field and validates that saved values are proper HTTP(S) URLs.
- Updated ackend/api/handlers/customer-portal-tokens.php to use the shared runtime config instead of raw $_ENV, so portal links and notification links stay aligned with the same saved production base URL.

