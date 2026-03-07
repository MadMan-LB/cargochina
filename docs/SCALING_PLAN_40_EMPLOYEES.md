# CLMS Scaling Plan — 40 Employees, International Level

## Implemented (2025-02-19)

### 1. Role enforcement on root pages
- **page_guard.php** — `requireRoleForPage($roles)` redirects to 403 when user lacks role
- **Orders, Customers, Suppliers, Products** — ChinaAdmin, ChinaEmployee, SuperAdmin
- **Receiving** — WarehouseStaff, SuperAdmin
- **Consolidation** — ChinaAdmin, LebanonAdmin, SuperAdmin
- **Admin (Users, Config, Diagnostics)** — SuperAdmin
- **Tracking Push Log** — LebanonAdmin, SuperAdmin
- Sidebar shows/hides links by role (`$isBuyer`, `$isWarehouse`, `$isAdmin`)

### 2. Warehouse entry point fix
- `warehouse/index.php` now links to `warehouse/receiving/` (full queue+history) instead of stub `queue.php`

### 3. L/H/W dimension support (warehouse receive)
- `warehouse/receiving/receive.php` — Added L/W/H (cm) inputs; auto-calculates CBM when all three filled
- `receiving_receive.js` — Same logic as root receiving; validates CBM or L/H/W required

### 4. Departments and user profiles
- **Migration 022** — `departments` table (warehouse, buyers, consolidation, admin, tracking)
- **user_departments** — Many-to-many with `is_primary` for primary department
- **GET /departments** — List departments (SuperAdmin, ChinaAdmin)
- **Users API** — Extended with `departments` per user; **PUT /users/{id}** for roles, department_ids, is_active
- **User Management UI** — Edit modal: roles (checkboxes), departments (multi-select), active toggle

### 5. Role-aware dashboard
- Dashboard cards shown by role: Master Data + Orders (buyers), Receiving (warehouse), Consolidation (admin), Notifications (all)

### 6. Minimum input, maximum output (2025-02-19)
- **Dashboard actionable counts** — Zero-click: "To receive", "Awaiting confirm", "Ready to consolidate", "Unread notifications" with direct links. `GET /dashboard/stats`.
- **Copy order** — One-click duplicate: copies customer, supplier, items, CBM, weight into new draft. Saves re-entry for repeat orders.
- **Export to CSV** — One-click export of current filtered order list (respects status + customer filter). UTF-8 BOM for Excel.

### 7. Additional efficiency features (2025-02-19)
- **Export receiving queue to CSV** — Receiving page: Export CSV button exports current filtered queue (order ID, customer, supplier, shipping codes, cartons, CBM, weight, items summary).
- **Recent customers/suppliers** — Order form: "Recent:" quick-select chips for last-used customer/supplier (localStorage, max 8). Saves on autocomplete select.
- **Bulk approve orders** — Orders table: checkbox column for Submitted orders; "Bulk Approve" button approves all selected in one action.
- **Order templates** — Save current items as template; load template to pre-fill items. Migration 023: `order_templates`, `order_template_items`. API: `GET/POST /order-templates`, `GET /order-templates/{id}`.

---

## Recommended next steps

### Phase 1 — Vision reference systems (2025-02-19)
- **My tasks** — Dashboard shows role-scoped actionable items (orders to approve, to receive, ready to consolidate).
- **Audit log** — Admin → Audit Log; filter by entity, user, date.
- **Field staff role** — New role; suppliers page with "Log visit" (visit/quote/note); migration 024.

### Phase A — User onboarding and profiles
- [ ] Add user creation flow (SuperAdmin) — email, name, initial roles, departments
- [ ] Add phone/extension to users for contact
- [ ] Seed department assignments for existing users

### Phase B — Activity and visibility
- [ ] Department-scoped dashboard widgets (e.g. "Pending receiving: 12")
- [ ] "My tasks" or "Assigned to me" views
- [ ] Audit log viewer (who did what, when) — filtered by department or user

### Phase C — Internationalization
- [ ] Language toggle (EN/ZH) — session or user preference
- [ ] Translate UI strings via translation cache or locale files
- [ ] Date/number formatting by locale

### Phase D — Operational hardening
- [ ] Session timeout and concurrent-session policy
- [ ] Password reset flow
- [ ] Rate limiting on login
- [ ] Structured logging for security events

### Phase E — Receiving flow unification
- [ ] Decide canonical flow: root receiving.php vs warehouse/receiving/
- [ ] Remove duplication; single code path for receive form
- [ ] Wire customer/supplier filters in warehouse queue (receiving_index.js)

---

## Department mapping to roles

| Department   | Typical roles                    |
|-------------|-----------------------------------|
| warehouse   | WarehouseStaff                   |
| buyers      | ChinaAdmin, ChinaEmployee        |
| consolidation | ChinaAdmin, LebanonAdmin        |
| admin       | SuperAdmin                        |
| tracking    | LebanonAdmin, SuperAdmin         |
| field       | FieldStaff (visits, suppliers)   |

Users can belong to multiple departments; first selected = primary.
