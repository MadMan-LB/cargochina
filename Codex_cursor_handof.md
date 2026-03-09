# Codex to Cursor Handoff

Initial handoff written by Codex on 2026-03-08 after a Playwright MCP audit, targeted fixes, DB repair, and retest pass on the local CLMS environment.

## 1. Executive Summary
- Overall product quality: partially functional, but still in a pre-production state with meaningful workflow duplication and migration drift.
- Biggest risks before this pass: broken consolidation draft management, broken receiving history, broken ChinaEmployee routing, and environment config forcing every receipt into variance mode.
- Biggest wins from this pass: core consolidation draft modal restored, warehouse receiving unblocked, ChinaEmployee login/access repaired, consolidation API permissions hardened, and key admin/warehouse/buyers flows retested.
- Current maturity level: workable internal test environment for several main flows, but not yet production-ready or internationally ready.

## 2. Scope Covered
- Roles tested:
  - SuperAdmin
  - ChinaAdmin
  - LebanonAdmin
  - WarehouseStaff
  - ChinaEmployee
  - Accountant/Sales roles were not found in the current roles table.
- Modules tested:
  - login/logout
  - superadmin dashboard
  - admin users
  - configuration
  - diagnostics
  - tracking push log
  - audit log
  - buyers dashboard
  - orders
  - customers
  - suppliers
  - products
  - notifications
  - notification preferences
  - pipeline
  - consolidation
  - receiving
- Viewport coverage:
  - desktop baseline across the main audit
  - tablet spot checks at `768x1024`
  - mobile spot checks at `390x844`
  - responsive checks focused on receiving and consolidation, where operational density is highest
- Key flows tested:
  - auth redirect by role
  - access-denied boundaries
  - admin configuration save
  - tracking push log modal/filter
  - users edit modal
  - buyers CRUD modal validation for orders/customers/suppliers/products
  - consolidation draft open/manage/save carrier refs
  - warehouse receiving validation and successful receipt recording
  - notification preference toggle/save

## 3. Test Method
- Playwright MCP approach used:
  - direct navigation to known and code-discovered routes
  - role-by-role login sessions
  - interaction with buttons, tabs, filters, validation paths, modals, and protected routes
  - code inspection to discover hidden routes, RBAC gaps, and schema drift behind UI failures
- Navigation strategy:
  - use sidebar routes where stable
  - use direct URLs for hidden or area-based routes
  - verify both root pages and area pages where both exist
- Responsive strategy:
  - desktop used as default audit baseline
  - tablet/mobile passes concentrated on receiving and consolidation
- Interaction strategy:
  - invalid submissions first
  - then valid submissions where safe
  - direct permission boundary checks
  - retest after fixes

## 4. Findings by Severity
### Critical

#### QA-001 ChinaEmployee login redirected to an unauthorized area
- Role: ChinaEmployee
- Page/module: `login.php`, `/buyers/`, area access
- Reproduction:
  - log in as a ChinaEmployee user
  - observe redirect to `/warehouse/`
  - warehouse immediately returns access denied
- Expected vs actual:
  - expected: ChinaEmployee should land in buyers and access buyers pages
  - actual: user was routed to warehouse and denied
- Recommendation:
  - route ChinaEmployee to buyers and allow buyers area access for that role
- Fixed/not fixed:
  - Fixed

#### QA-002 Shipment draft management failed with HTTP 500
- Role: LebanonAdmin, SuperAdmin, ChinaAdmin
- Page/module: consolidation, shipment draft modal
- Reproduction:
  - open `consolidation.php`
  - click `Manage` on a shipment draft
- Expected vs actual:
  - expected: draft modal opens with carrier refs and documents
  - actual: API failed because `shipment_draft_documents` table and related carrier-ref columns were missing in the local DB
- Recommendation:
  - repair local schema to match migration 025 and ensure migration state is recorded
- Fixed/not fixed:
  - Fixed

### High

#### QA-003 Receiving history failed with HTTP 500
- Role: WarehouseStaff, SuperAdmin
- Page/module: `/warehouse/receiving/` history
- Reproduction:
  - log in as WarehouseStaff
  - open warehouse receiving history tab
- Expected vs actual:
  - expected: receipt history table or empty state
  - actual: API failed because code expected `warehouse_receipts.receipt_condition` but live schema still had `condition`
- Recommendation:
  - align schema to current code or add schema compatibility layer
- Fixed/not fixed:
  - Fixed

#### QA-004 Receiving configuration forced every receipt into variance mode
- Role: WarehouseStaff
- Page/module: receiving flow, `system_config`
- Reproduction:
  - attempt to record a receipt with matching actuals and no photos
  - system still requires evidence photos
- Expected vs actual:
  - expected: matching receipts with good condition should not require forced variance handling
  - actual: `VARIANCE_THRESHOLD_PERCENT=0`, `VARIANCE_THRESHOLD_ABS_CBM=0`, and `CONFIRMATION_REQUIRED=always-on-arrival` turned every receipt into a variance case
- Recommendation:
  - restore safe operational defaults or document intended deviation explicitly
- Fixed/not fixed:
  - Fixed in local `system_config`

#### QA-005 Consolidation page had API/UI permission mismatch for containers
- Role: LebanonAdmin, ChinaAdmin
- Page/module: consolidation
- Reproduction:
  - log in as LebanonAdmin
  - open consolidation page
- Expected vs actual:
  - expected: consolidation users can open the page, manage drafts, and at least read available containers
  - actual: page loaded but `/api/v1/containers` returned forbidden and the UI still exposed `+ Add Container`
- Recommendation:
  - split container permissions into read vs write, and hide creation UI for non-SuperAdmin users
- Fixed/not fixed:
  - Fixed

#### QA-006 Shipment draft API was under-protected
- Role: any authenticated non-consolidation role
- Page/module: `/api/v1/shipment-drafts`
- Reproduction:
  - inspect router/handler
  - most shipment-draft actions were not blocked at the handler level
- Expected vs actual:
  - expected: shipment draft endpoints should require consolidation roles at the API layer
  - actual: only some actions were guarded in the router; several draft actions relied mainly on page access
- Recommendation:
  - require consolidation roles in the shipment-drafts handler in addition to action-specific checks
- Fixed/not fixed:
  - Fixed

#### QA-007 Root and area receiving implementations diverge
- Role: WarehouseStaff
- Page/module: `/receiving.php` vs `/warehouse/receiving/`
- Reproduction:
  - open `/warehouse/receiving/` pending queue
  - compare with `/receiving.php`
- Expected vs actual:
  - expected: both routes reflect the same operational queue
  - actual: area page showed no pending orders while root page still listed receivable orders
- Recommendation:
  - consolidate the two implementations or make one the clear source of truth
- Fixed/not fixed:
  - Not fixed

#### QA-008 Customer confirmation flow exists in status logic but customer-facing UX is still unclear
- Role: customer-facing flow, admin/buyers
- Page/module: confirmation workflow
- Reproduction:
  - inspect routes and codebase after warehouse variance flow
- Expected vs actual:
  - expected: a dedicated customer-facing confirmation step or clearly defined operator-facing substitute
  - actual: backend and statuses support confirmation, but a dedicated customer-facing page was not found during this pass
- Recommendation:
  - define and implement the confirmation UX boundary explicitly
- Fixed/not fixed:
  - Not fixed

### Medium

#### QA-009 User management hardcodes `FieldStaff` in the UI while current DB roles do not include it
- Role: SuperAdmin
- Page/module: `admin_users.php`, `frontend/js/admin_users.js`
- Reproduction:
  - open edit user modal in superadmin users page
- Expected vs actual:
  - expected: assignable roles in UI should reflect actual roles in DB
  - actual: UI includes `FieldStaff`, but current DB roles table in this environment does not
- Recommendation:
  - load roles dynamically or align migrations/UI consistently
- Fixed/not fixed:
  - Not fixed

#### QA-010 SuperAdmin user management still lacks a create-user flow
- Role: SuperAdmin
- Page/module: user management
- Reproduction:
  - open users page
- Expected vs actual:
  - expected: create, edit, disable, and assign roles/departments
  - actual: edit exists; create does not
- Recommendation:
  - add user creation flow with initial role/department assignment
- Fixed/not fixed:
  - Not fixed

#### QA-011 `run-tests.bat` still fails one integration test
- Role: engineering quality
- Page/module: `tests/phase2_integration_test.php`
- Reproduction:
  - run `cmd /c run-tests.bat`
- Expected vs actual:
  - expected: green suite
  - actual: duplicate `user_notification_preferences` insert on key `1-email-order_received`
- Recommendation:
  - fix test isolation or make notification preference seeding/idempotency more explicit
- Fixed/not fixed:
  - Not fixed

#### QA-012 Root and area route duplication remains an architecture risk
- Role: all
- Page/module: route layer
- Reproduction:
  - compare root pages with area pages and redirects
- Expected vs actual:
  - expected: one coherent route model
  - actual: both coexist, which increases drift risk
- Recommendation:
  - choose a canonical route layer and retire or thin the duplicate one
- Fixed/not fixed:
  - Not fixed

### Low

#### QA-013 Configuration page emits a form-structure warning in the browser console
- Role: SuperAdmin
- Page/module: `admin_config.php`
- Reproduction:
  - load configuration page and interact with save flow
- Expected vs actual:
  - expected: clean console and valid form structure
  - actual: warning indicates multiple forms are not structured cleanly
- Recommendation:
  - clean up nested/adjacent form markup
- Fixed/not fixed:
  - Not fixed

#### QA-014 Consolidation page needed cache-busting to ensure updated JS loaded after changes
- Role: LebanonAdmin, ChinaAdmin, SuperAdmin
- Page/module: `consolidation.php`
- Reproduction:
  - modify consolidation JS and reload in the same browser session
- Expected vs actual:
  - expected: latest client code loads consistently
  - actual: stale cached script persisted until a versioned script URL was used
- Recommendation:
  - use asset versioning for mutable local scripts
- Fixed/not fixed:
  - Fixed for consolidation page

## 5. Findings by Role
### Admin
- LebanonAdmin redirect and access were correct.
- Superadmin area was correctly denied.
- Consolidation page was broken before fixes because containers API access and missing draft schema were inconsistent with the role.
- After fixes, LebanonAdmin could open the draft modal and save carrier refs.
- Container creation is now hidden for non-SuperAdmin users.

### Warehouse
- Warehouse dashboard and access boundaries behaved correctly.
- Receiving history and receipt recording were blocked before schema/config repair.
- After repair, history empty state worked and a real receipt could be recorded successfully.
- `/warehouse/receiving/` pending queue still does not match `/receiving.php`, which is a remaining workflow inconsistency.

### Accountant
- No Accountant role was found in the current roles table.
- Nothing to test for this role in the current environment.

### Sales
- No Sales role was found in the current roles table.
- Nothing to test for this role in the current environment.

### Other discovered roles
- SuperAdmin:
  - users edit flow works
  - config save works
  - diagnostics, tracking push log, audit log, pipeline pages load
  - missing user creation flow remains
- ChinaAdmin:
  - buyers redirect works
  - buyers/root orders, customers, suppliers, products pages loaded earlier in the audit
  - invalid modal submissions produced correct validation toasts
- ChinaEmployee:
  - was broken before fixes
  - now redirects to buyers and can access buyers dashboard

## 6. UX/UI Audit
- Stronger points:
  - sidebar layout is coherent across roles
  - validation toasts exist in most CRUD flows
  - consolidation draft modal organizes carrier refs, orders, and documents reasonably
  - receiving root page works better operationally than the area page
- Weak points:
  - duplicated route layers create user confusion and inconsistent behavior
  - some pages still depend too heavily on hidden implementation assumptions rather than explicit empty states and permission-aware UI
  - user management is incomplete for a real admin workflow
  - consolidation and receiving both rely on dense tables/cards that need broader mobile regression coverage beyond this pass
  - customer confirmation remains under-exposed in the UI
- Accessibility red flags:
  - no focused accessibility audit was completed
  - console warning on config page suggests markup cleanup is still needed

## 7. Missing Features / Product Gaps
- By module:
  - user management: missing create flow and dynamic role sourcing
  - confirmation workflow: missing explicit customer-facing confirmation interface
  - receiving: duplicated queue implementations
  - consolidation: container lifecycle is still light, with no broader tracking/document management workflow
  - reporting: no serious KPI/reporting layer yet
- By role:
  - SuperAdmin lacks complete user administration
  - ChinaEmployee support existed only partially until this pass
  - Accountant/Sales are absent from current implementation
- By production-readiness impact:
  - migration discipline is weak
  - route duplication creates regression risk
  - permission logic still needs a broader API-level audit outside shipment-drafts
  - internationalization, timezone, and broader currency handling are still minimal
  - operational reporting, audit surfacing, and bulk workflows are not mature enough for a world-class logistics platform

## 8. Fixes Applied by Codex
### Fix 1: ChinaEmployee routing and buyers area access
- What was fixed:
  - ChinaEmployee now routes to `/buyers/`
  - buyers area access and 403 return-home logic now recognize ChinaEmployee
- Why:
  - role was unusable
- Files changed:
  - `login.php`
  - `includes/area_bootstrap.php`
  - `403.php`
- Risk level:
  - Low
- Anything still incomplete:
  - ChinaEmployee still needs a deeper CRUD/permission audit across all buyers pages

### Fix 2: Container permission split and consolidation UI cleanup
- What was fixed:
  - container API now distinguishes read vs write
  - non-SuperAdmin consolidation users can read containers
  - container creation UI is hidden for non-SuperAdmin users
  - consolidation script URL is cache-busted
- Why:
  - page and API permissions were inconsistent
- Files changed:
  - `backend/config/rbac.php`
  - `backend/api/index.php`
  - `consolidation.php`
  - `frontend/js/consolidation.js`
- Risk level:
  - Low to medium
- Anything still incomplete:
  - broader asset versioning is still not standardized across the app

### Fix 3: Shipment-drafts API hardening
- What was fixed:
  - shipment-drafts handler now requires consolidation roles
- Why:
  - direct API access was under-protected
- Files changed:
  - `backend/api/handlers/shipment-drafts.php`
- Risk level:
  - Low
- Anything still incomplete:
  - other API handlers should be reviewed with the same rigor

### Fix 4: Local DB repair for shipment draft schema
- What was fixed:
  - added `shipment_drafts.container_number`
  - added `shipment_drafts.booking_number`
  - added `shipment_drafts.tracking_url`
  - created `shipment_draft_documents`
  - recorded `025_shipment_documents.sql` in `_migrations`
- Why:
  - consolidation draft management was crashing
- Files changed:
  - no repo file changed; local DB schema updated
- Risk level:
  - Medium
- Anything still incomplete:
  - migration drift across the repo still needs a systematic review

### Fix 5: Local DB repair for warehouse receipts schema
- What was fixed:
  - renamed `warehouse_receipts.condition` to `receipt_condition`
- Why:
  - receiving history and future receipt writes were incompatible with current code
- Files changed:
  - no repo file changed; local DB schema updated
- Risk level:
  - Medium
- Anything still incomplete:
  - check whether any other local tables diverge from recorded migrations

### Fix 6: Local config normalization for receiving
- What was fixed:
  - set `VARIANCE_THRESHOLD_PERCENT=10`
  - set `VARIANCE_THRESHOLD_ABS_CBM=0.1`
  - set `CONFIRMATION_REQUIRED=variance-only`
- Why:
  - receiving could not be exercised meaningfully with zero thresholds
- Files changed:
  - no repo file changed; local `system_config` data updated
- Risk level:
  - Low to medium
- Anything still incomplete:
  - config precedence between `.env` and `system_config` still deserves a dedicated review

## 9. Recommended Next Improvements
### Quick wins
- make role options in user management dynamic instead of hardcoded
- add user creation flow
- standardize empty-state and permission-aware messaging across duplicate pages
- fix the config page form warning
- investigate and fix the failing notification preference integration test

### Medium effort upgrades
- consolidate root and area route implementations
- run a full API-level RBAC audit beyond shipment-drafts
- build explicit customer confirmation UI
- standardize migration verification and startup health checks
- broaden responsive fixes for dense operational pages

### Major strategic improvements
- define a canonical module/route architecture and retire duplicates
- build production-grade reporting, import/export, and bulk operation capability
- harden international readiness: timezone handling, richer currency/localization, clearer terminology boundaries
- expand auditability for document uploads, tracking pushes, and role changes

## 10. International Production-Readiness Gap Analysis
- Enterprise-grade stability:
  - still blocked by migration drift and duplicate route layers
- Multi-role logistics operations:
  - improved, but current role set is incomplete and admin tooling is still thin
- International usability:
  - limited currency handling exists, but broader locale/timezone/i18n strategy is not mature
- Mobile readiness:
  - no critical responsive break was confirmed in receiving/consolidation spot checks, but coverage is not broad enough yet
- Auditability:
  - audit log exists, but critical workflow and config changes still need fuller operational surfacing
- Scalability:
  - plain PHP app can scale some distance, but architecture discipline and route duplication need cleanup first
- Permission model maturity:
  - improving, but still needs systematic API-level enforcement review
- Workflow completeness:
  - confirmation UX and complete admin user lifecycle are still missing
- Data quality and validation maturity:
  - good baseline validation exists in several forms, but config safety, migration safety, and role/data alignment still need hardening

## 11. Cursor Second-Pass Mission
- Audit the duplicate root-vs-area implementations and decide which route layer should survive.
- Verify every API handler for RBAC parity with the page layer.
- Resolve migration drift systematically instead of opportunistic DB repair.
- Replace hardcoded role lists with data-driven role handling.
- Build or clearly define the customer confirmation UX boundary.
- Expand responsive testing across the remaining operational pages, especially dense tables/forms/modals.
- Push the platform toward international production readiness: user lifecycle, auditability, reporting, imports/exports, and locale-aware behavior.

## 12. Prompt for Cursor
```text
You are taking over a second-pass architecture, QA, and production-readiness review for CLMS in:

c:\xampp\htdocs\cargochina

Start by reading:

c:\xampp\htdocs\cargochina\Codex_cursor_handof.md

Your job is not to repeat the first-pass audit blindly. Your job is to verify the fixes, close the remaining gaps, and push the system toward international production-ready quality.

Focus areas:
1. Audit root pages vs area pages and identify the canonical route/module structure that should survive.
2. Perform a full API-level RBAC review, not just page-level access checks.
3. Investigate migration drift and propose a clean, repeatable schema consistency strategy.
4. Verify the receiving/consolidation fixes and inspect adjacent regressions.
5. Replace hardcoded role assumptions with data-driven role handling where appropriate.
6. Define and/or implement the missing customer confirmation UX.
7. Review missing admin capabilities, especially user creation and role/department lifecycle management.
8. Expand responsive and operational UX review across remaining dense modules.
9. Identify what still blocks the platform from being international, scalable, auditable, and operationally world-class.

Be concrete.
Document findings in the same handoff file.
Separate:
- verified fixed issues
- remaining defects
- architectural risks
- missing features
- recommended implementation order

Challenge the system hard.
Prefer evidence over assumptions.
```

## Follow-up Audit Notes — 2026-03-09
- Additional live verification completed with Playwright MCP:
  - superadmin order modal still blocks empty submit correctly
  - customer balance modal opens correctly from the customers list
  - superadmin can create a container, add an eligible order to a shipment draft, and assign the draft to that container
  - notifications page can mark a notification as read
  - pipeline, diagnostics, tracking push log, audit log, products, and suppliers all loaded without visible PHP/SQL error text in a superadmin sweep
- New concrete issue confirmed:
  - mobile horizontal overflow exists on `admin_users.php` at `390px` width
  - measured via Playwright: `document.documentElement.scrollWidth = 789` with `window.innerWidth = 390`
  - impact: user management is not mobile-safe
- Additional suggestions:
  - wrap dense admin tables in a dedicated responsive container with preserved action usability
  - prioritize one canonical receiving UI and retire the duplicate path
  - add tests for ChinaEmployee routing, shipment-draft RBAC, and container read/write RBAC
  - add an automated migration/schema consistency check to admin diagnostics or startup health checks

---

## 13. Cursor Second-Pass Findings (2026-02-19)

### 13.1 Verified Codex Fixes

| Fix | Verification | Evidence |
|-----|--------------|----------|
| **ChinaEmployee routing** | ✓ Verified | `login.php` line 32: ChinaAdmin or ChinaEmployee routes to buyers. `area_bootstrap.php` includes ChinaEmployee in buyers area. `403.php` line 13 sends ChinaEmployee to buyers home. |
| **Container read/write split** | ✓ Verified | `backend/api/index.php` lines 59–67: GET uses `containers.read`, POST/PUT/DELETE use `containers.write`. `rbac.php` defines read: ChinaAdmin, LebanonAdmin, SuperAdmin; write: SuperAdmin only. |
| **Container creation UI hidden** | ✓ Verified | `consolidation.php` line 5: `$canManageContainers = in_array('SuperAdmin', $_SESSION['user_roles'])`. Add Container button and modal only rendered when true. |
| **Shipment-drafts API hardening** | ✓ Verified | `backend/api/handlers/shipment-drafts.php` line 12: `requireRole(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin'])` at handler entry. Blocks all non-consolidation roles. |
| **Warehouse index link** | ✓ Verified | `warehouse/index.php` line 19: links to `$areaBase/receiving/` (i.e. `/cargochina/warehouse/receiving/`), not stub queue.php. |
| **Warehouse customer/supplier filter** | ✓ Verified | `receiving_index.js` lines 213–242: `setupFilterAutocomplete()` wires filterCustomer/filterSupplier via `Autocomplete.init` with `onSelect` that sets `filterCustomerId`/`filterSupplierId`. `loadQueue()` passes these to API (lines 28–29). |
| **Migration 003 schema** | ✓ Verified | `003_warehouse_receiving.sql` defines `receipt_condition` (not `condition`). Codex’s local DB repair aligned drift; migration was correct. |
| **Migration 025** | ✓ Verified | `025_shipment_documents.sql` exists in repo: adds `shipment_draft_documents`, `container_number`, `booking_number`, `tracking_url`. |

### 13.2 Root vs Area Route Audit

**Canonical structure (evidence-based):**

| Module | Root page | Area page | Canonical | Notes |
|--------|-----------|-----------|-----------|-------|
| Receiving | `receiving.php` (receiving.js) | `warehouse/receiving/index.php` (receiving_index.js) | **Both active, same API** | Both call `/receiving/queue` and `/receiving/receipts`. Root has calendar/schedule; area has customer/supplier autocomplete. `warehouse/receiving/queue.php` and `history.php` are stubs redirecting to root. |
| Orders | `orders.php` | `buyers/orders.php` → redirect to root | Root | Area redirects. |
| Consolidation | `consolidation.php` | `admin/consolidation.php` → redirect to root | Root | Area redirects. |
| Customers/Suppliers/Products | Root only | `buyers/*.php` (own pages) | Mixed | Buyers area has its own CRUD pages; root has parallel pages. |
| Dashboard | `index.php` | `warehouse/`, `buyers/`, `admin/`, `superadmin/` | Area for role-specific | Root dashboard is generic; areas have role-specific dashboards. |
| Users/Config/Diagnostics/Audit | Root `admin_*.php` | `superadmin/*.php` (some) | Root primary | Superadmin area has users, config, diagnostics, tracking-push-log. |

**Recommendation:** Treat root as canonical for orders, consolidation, receiving, master data until a deliberate migration. Warehouse receiving should standardize on `warehouse/receiving/index.php` (full queue+history) and deprecate stub `queue.php`/`history.php`. Sidebar (`layout.php`) links to root `receiving.php`; warehouse area layout links to `warehouse/receiving/`. Inconsistent entry points.

### 13.3 Full API RBAC Review

**Router-level checks (`backend/api/index.php`):**
- `containers`: read vs write by method
- `orders` approve/receive: action-specific roles
- `users`: SuperAdmin
- `config`: SuperAdmin (except config/receiving, config/upload — WarehouseStaff for receiving)
- `shipment-drafts` push/finalize: action-specific
- `tracking-push-log`: LebanonAdmin, SuperAdmin
- `diagnostics`: SuperAdmin
- `receiving`: WarehouseStaff, SuperAdmin

**Handler-level `requireRole`:**
- `receiving.php`: WarehouseStaff, SuperAdmin
- `config.php`: SuperAdmin (except receiving/upload paths)
- `shipment-drafts.php`: ChinaAdmin, LebanonAdmin, SuperAdmin
- `users.php`: SuperAdmin
- `audit-log.php`: SuperAdmin, ChinaAdmin
- `departments.php`: SuperAdmin, ChinaAdmin
- `diagnostics.php`: SuperAdmin

**No role restriction (auth only):**
- `orders`, `customers`, `suppliers`, `products`: any authenticated user can full CRUD
- `upload`: any authenticated user
- `translate`, `translations`: any authenticated user
- `dashboard`, `notifications`, `notification-preferences`: any authenticated user
- `order-templates`: any authenticated user (uses getAuthUserId for ownership)

**RBAC gaps:**
1. **Master data (customers, suppliers, products):** Page layer restricts to ChinaAdmin/ChinaEmployee; API allows any authenticated user. WarehouseStaff could read/write customers via API.
2. **Orders:** Same — page restricts; API allows any authenticated user.
3. **Upload:** No role check. Receiving needs it; buyers may upload attachments. Consider restricting to WarehouseStaff + buyers roles.
4. **Translate:** No role check. Used for product/customer translation; consider buyers-only.

### 13.4 Migration Drift

- **024_field_staff_role.sql:** Adds `FieldStaff` to `roles`. If not applied, `FieldStaff` appears in UI (`admin_users.js`, `suppliers.php`, `layout.php`) but not in DB — runtime mismatch.
- **025_shipment_documents.sql:** In repo. Codex applied locally; ensure all envs run it.
- **003:** Uses `receipt_condition`; no drift in migration file. Local DB may have had older `condition` column.

**Recommendation:** Add migration verification at startup or in diagnostics: compare `SHOW TABLES` / `DESCRIBE` against expected schema from migrations. Document migration run order and record applied migrations in a table.

### 13.5 Remaining Defects (Prioritized)

| ID | Severity | Issue | Status |
|----|----------|-------|--------|
| QA-007 | High | Root vs area receiving — two UIs, same API; sidebar vs area links diverge | Open |
| QA-008 | High | No dedicated customer-facing confirmation page; API supports confirm | Open |
| QA-009 | Medium | `admin_users.js` hardcodes roles including FieldStaff; roles should be loaded from API | Open |
| QA-010 | Medium | No user creation flow for SuperAdmin | Open |
| QA-011 | Medium | phase2_integration_test fails (duplicate user_notification_preferences) | Open |
| QA-012 | Medium | Root/area route duplication; no canonical deprecation path | Open |

### 13.6 Architectural Risks

1. **API vs page RBAC mismatch:** Master data and orders APIs allow any authenticated user; pages restrict by role. Direct API access bypasses page restrictions.
2. **Route duplication:** Two receiving UIs, root vs area pages for same modules. Increases regression risk and user confusion.
3. **Migration discipline:** No automated verification; local drift possible. FieldStaff role may be in migration but not in DB.
4. **Hardcoded roles:** `admin_users.js`, `layout.php`, `rbac.php`, page guards all hardcode role strings. Adding a role requires code changes in many places.

### 13.7 Missing Features

- User creation flow (SuperAdmin)
- Customer-facing confirmation UX
- Dynamic role loading in user management
- API-level role checks for orders, customers, suppliers, products (align with page layer)
- Migration verification / health check
- i18n, timezone, locale handling for international use

### 13.8 Recommended Implementation Order

1. **Quick:** Load roles from API in `admin_users.js`; remove hardcoded list. Ensures UI matches DB.
2. **Quick:** Add API role checks for customers, suppliers, products, orders — restrict to buyers roles (ChinaAdmin, ChinaEmployee, SuperAdmin) for write; read may stay broader for receiving context.
3. **Medium:** Implement user creation flow (SuperAdmin).
4. **Medium:** Fix phase2_integration_test (idempotent preference insert or test isolation).
5. **Medium:** Define canonical receiving route — either root or warehouse/receiving — and update all links. Deprecate the other.
6. **Medium:** Design and implement customer confirmation UX (or document operator-only confirmation as the chosen approach).
7. **Strategic:** Define canonical route architecture; migrate traffic to area-based or root-based; retire duplicates.

---

## 14. Cursor Second-Pass Update (2026-02-19 — Continued)

### 14.1 Re-verification of Codex Fixes

All fixes in §13.1 remain verified in code. Additional spot checks:
- **API routing:** `.htaccess` rewrites `/api/v1/receiving/queue` → `backend/api/index.php?path=receiving/queue`. Both root `receiving.js` and warehouse `receiving_index.js` call the same endpoint.
- **Root receiving** `getFilterParams()` does not pass `status`; API handler defaults to `['Approved','InTransitToWarehouse']` (line 22–24). **Warehouse** explicitly passes `status=Approved&status=InTransitToWarehouse`. Same effective query → same data.

### 14.2 Receiving Divergence (QA-007) — Root Cause

**Finding:** Both UIs call the same `/receiving/queue` API. Data should be identical for the same filters. Observed divergence (area empty, root populated) was likely due to:
- Different filter state (e.g. warehouse customer/supplier filter applied)
- Stale cache or timing

**UI differences (not data):**
- Root: List | Calendar | Schedule tabs; card-based layout; L/H/W dimension inputs in receive flow
- Warehouse: Pending Queue | History tabs; table layout; customer/supplier autocomplete in queue filters

**Recommendation:** Unchanged — pick one canonical receiving UI. Root has richer features (calendar, schedule, L/H/W); warehouse has cleaner queue+history. Consolidate or document which is primary.

### 14.3 Mobile Horizontal Overflow (QA-015) — Fixed

**Issue:** `admin_users.php` at 390px width caused horizontal overflow (scrollWidth 789 vs innerWidth 390). Playwright confirmed in Codex 2026-03-09 notes.

**Root cause:** 
1. User table had no `table-responsive` wrapper.
2. Global `.table-responsive` in `style.css` used `overflow: hidden`, preventing horizontal scroll.

**Fix applied:**
- Wrapped user table in `admin_users.php` with `<div class="table-responsive">`.
- Changed `style.css` `.table-responsive` from `overflow: hidden` to `overflow-x: auto` and added `-webkit-overflow-scrolling: touch` for mobile.

**Files changed:** `admin_users.php`, `frontend/css/style.css`

### 14.4 Hardcoded Roles (QA-009) — Evidence

`frontend/js/admin_users.js` lines 10–16: `allRoles` is a hardcoded array including `FieldStaff`.
- Migration `024_field_staff_role.sql` adds `FieldStaff` to `roles` table.
- If migration not run, UI shows FieldStaff but DB has no such role → assignment fails or is ignored.
- **Fix:** Load roles from `GET /roles` or extend `GET /users` to include available roles. No such endpoint exists; would need new `roles` API or include in config.

### 14.5 Production-Readiness Blockers (Summary)

| Blocker | Impact |
|---------|--------|
| API RBAC gaps (orders, customers, suppliers, products) | WarehouseStaff can CRUD master data via API |
| No user creation flow | SuperAdmin cannot onboard new users |
| No customer confirmation UX | Variance flow incomplete for customer-facing step |
| Migration drift risk | FieldStaff, shipment_documents, receipt_condition may be missing in envs |
| Route duplication | Two receiving UIs; root vs area pages; maintenance burden |
| Hardcoded roles | Adding role requires code changes in 6+ files |
| Minimal i18n/locale | Not internationally usable |
| phase2_integration_test fails | CI/deploy confidence reduced |

### 14.6 Updated Recommended Implementation Order

1. **Done:** Fix admin_users mobile overflow (table-responsive).
2. **Quick:** Add API role checks for customers, suppliers, products, orders (buyers roles for write).
3. **Quick:** Load roles from API in admin_users.js (add `GET /roles` or include in existing response).
4. **Medium:** User creation flow (SuperAdmin).
5. **Medium:** Fix phase2_integration_test.
6. **Medium:** Canonical receiving route decision and link consolidation.
7. **Medium:** Customer confirmation UX (or document operator-only path).
8. **Strategic:** Migration verification in diagnostics; canonical route architecture.
