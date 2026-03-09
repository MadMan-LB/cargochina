# CODEX ↔ CURSOR COLLABORATION HANDOFF

This file was initially written by Codex as a shared collaboration and continuity document for Cursor. Cursor should append notes, corrections, implementation findings, architectural decisions, risks, and open questions directly into this file as work progresses.

## 1. Project Identity
- Project name: Salameh Cargo China Logistics Management System (CLMS)
- Business purpose: Replace the upstream China Excel workflow with a controlled operational system that produces shipment-ready data.
- Operational purpose: Manage master data, order capture, warehouse verification, variance handling, consolidation, container assignment, and shipment handoff into the existing tracking platform.
- Why it exists: The current Excel-based process creates inconsistent data, duplicate work, translation noise, CBM and weight disputes, and downstream correction effort in Lebanon.
- What problem it solves: It moves error-prone manual logistics preparation into a validated system with auditability, reusable records, warehouse evidence, and a safer push boundary into tracking.

## 2. Executive Understanding of the System
- The legacy upstream workflow is Excel-driven: teams collect customer, supplier, product, order, and shipment preparation data in spreadsheets, then manually clean, regroup, translate, and re-enter it before a shipment can be finalized.
- That workflow is broken because it allows missing fields, duplicate products, inconsistent units, wrong CBM and weight declarations, manual translation drift, and operational state changes without strong enforcement.
- The new system must replace that Excel process with structured data capture, validation at input time, reusable master data, warehouse measurement capture with evidence, controlled variance confirmation, and standardized consolidation into shipment drafts and containers.
- The intended end-to-end pipeline is: master data setup, order creation, item capture, internal approval, goods movement to warehouse, warehouse receiving with actual measurements and photos, customer confirmation when variance requires it, readiness for consolidation, shipment draft preparation, container assignment, and final push into the existing tracking system.
- Current repo reality: this is already a partially implemented plain PHP + MySQL + Bootstrap + vanilla JS application running under XAMPP, not a blank project. Stages 0 through 3 and the tracking push boundary are represented in code, schema, docs, and tests, but they still require targeted validation and cleanup.

## 3. Core Business Goals
- Eliminate Excel dependency for upstream China logistics operations.
- Reduce manual correction work before shipment creation.
- Create reusable master data for customers, suppliers, products, and translations.
- Verify actual warehouse measurements instead of trusting declared values blindly.
- Prevent CBM and weight disputes by recording evidence and capturing confirmation when needed.
- Support consolidation and container-based operations as a first-class workflow.
- Push finalized shipment data into the existing tracking system without manual re-entry.
- Support scale without operational chaos or uncontrolled data duplication.

## 4. Staged System Scope
- Stage 0: Master data foundation for customers, suppliers, products, translations, reusable item definitions, and operational settings.
- Stage 1: Order and supplier management covering order intake, structured item capture, attachments, supplier metadata, and approval flow.
- Stage 2: Warehouse receiving and evidence capture covering actual cartons, CBM, weight, condition, and photo evidence.
- Stage 2.5: Customer confirmation for variance covering notifications, responsibility-relieve confirmation, and state changes when actual warehouse measurements differ materially from declared data.
- Stage 3: Consolidation and shipment preparation covering shipment drafts, order grouping, container assignment, capacity awareness, and finalization controls.
- Stage 4-5: Push and finalization into the existing tracking system, with CLMS acting as the upstream preparation and validation layer rather than replacing tracking itself.
- Current repo signal: schema, handlers, pages, and services suggest that stages 0-3 and the tracking push boundary are already partially implemented, but not all UX and operational seams are fully validated.

## 5. Main Operational Lifecycle
- `Draft`
- `Submitted`
- `Approved`
- `In Transit to Warehouse`
- `Received at Warehouse`
- `Awaiting Customer Confirmation`
- `Confirmed`
- `Ready for Consolidation`
- `Consolidated into Shipment Draft`
- `Assigned to Container`
- `Finalized and Pushed to Tracking`
- Current code note: `backend/services/OrderStateService.php` already defines a lifecycle/state machine close to this flow and should be treated as a current source of truth until replaced intentionally.

## 6. Core Modules Expected in the Codebase
- Auth and roles: present now via `login.php`, session-based auth, `includes/area_bootstrap.php`, `includes/layout.php`, and `backend/config/rbac.php`.
- Customers: present now in root pages, buyers area pages, and `backend/api/handlers/customers.php`.
- Suppliers: present now in root pages, buyers area pages, supplier payments/interactions schema, and `backend/api/handlers/suppliers.php`.
- Products: present now in UI and `backend/api/handlers/products.php`, with translation-related support nearby.
- Translation cache: present conceptually and in schema/service form via `translations` and `backend/services/TranslationService.php`.
- Orders: present now in UI, handlers, schema, and tests.
- Order items: present now in schema and handlers, including item-level capture fields such as cartons, shipping code, pricing, notes, and image paths.
- Attachments: present now via `order_attachments`, upload handling, and order-level file references.
- Warehouse receiving: present now in root and area routes, `backend/api/handlers/orders.php`, `backend/api/handlers/receiving.php`, and warehouse pages.
- Evidence/photos: present now via upload utilities, receipt photo tables, and frontend receiving scripts.
- Variance detection: present now in receiving flow and lifecycle transitions, but still needs operational QA.
- Notifications: present now in dashboard notification UI, preferences, `NotificationService`, and notification delivery logging.
- Customer confirmation: business-critical and partially present in API/state flow; a dedicated customer-facing confirmation page is not obvious in the current repo.
- Shipment drafts: present now in schema, handler, UI, and consolidation tests.
- Container assignment: present now in schema, handler, and consolidation UI.
- Integration bridge to tracking: present now in `backend/services/TrackingPushService.php`, config, logs, and admin push-log tooling.
- Audit trail: present now via `audit_log`, but coverage across all operational changes should be validated.
- Settings/configuration: present now via `.env`, `system_config`, and superadmin configuration pages and handlers.
- Current implementation shape: no `composer.json`, `package.json`, or framework build setup is present; the repo uses direct PHP pages, REST-like handlers, frontend JS files, CSS, and SQL migrations.

## 7. Architecture Assumptions to Validate
- The repo is partially implemented and operationally meaningful, but it is not yet clean enough to assume architecture is settled.
- Session-based authentication and area-based RBAC are the active access model and should be treated as current behavior until proven otherwise.
- A strong relational MySQL schema already exists and appears to carry the main operational workflow; entity boundaries and foreign-key assumptions should still be checked against real usage.
- Audit logging exists via `audit_log`, but actual write coverage for approvals, receiving, confirmation, consolidation, config changes, and tracking retries should be validated.
- A reusable status model exists and should remain centralized; `OrderStateService` is a current lifecycle anchor that should not be bypassed casually.
- Configuration precedence appears split between `.env` and `system_config`; validate which values are authoritative in production and whether page/API behavior is consistent with admin-edited settings.
- Docs describe a `backend/models` layer in the project structure, but the repo currently appears handler/service driven with SQL close to handlers; validate whether that is intentional or a gap between docs and implementation.
- Legacy root routes and area-based routes both exist; validate whether legacy pages are temporary compatibility paths or an accidental duplicate surface that needs deprecation.
- File upload handling exists, but security, retention, access control, storage layout, and operational cleanup rules still need manual validation.
- Approval flow, receiving validation, and push finalization logic appear to exist, but the exact enforcement boundaries need to be confirmed against real workflows and test coverage.
- Responsive behavior is intended and documented, but real browser and mobile validation is still required before relying on UX assumptions.
- Tracking integration should remain separated from upstream operational preparation; CLMS should finalize and validate data before pushing to the existing tracking platform rather than leaking upstream draft behavior into tracking.

## 8. Critical Unknowns / Decisions Still Open
- Playwright MCP is not available in this session, so no real browser click-through or visual regression audit was executed yet.
- Only the seeded `admin@salameh.com` SuperAdmin account was verified through the running app and API; no seeded lower-role users were discovered during this pass.
- A dedicated customer-facing confirmation page was not found in the current repo; the confirmation flow is clearly present in the API and admin-side UI, but customer-facing implementation is unclear.
- The tracking integration contract exists in docs and code, but live remote integration behavior against the real tracking target is not verified.
- The boundary between legacy root pages and area-based pages is still unclear from the current implementation and may create duplication or inconsistent maintenance paths.
- `run-tests.bat` is mostly green, but `tests/phase2_integration_test.php` currently fails on duplicate insertion of `user_notification_preferences` (`1-email-order_received`); this may indicate test-isolation weakness, pre-seeded state assumptions, or a real idempotency/data-seeding issue.
- Variance thresholds and confirmation policy have defaults in docs/config, but business ownership should confirm whether `10%`, `0.1 CBM`, and `variance-only` are final operational rules or temporary defaults.
- Photo visibility policy exists in config/docs, but business signoff is still needed on whether warehouse evidence remains internal or becomes customer-visible in some flows.
- Supplier reliability scoring is described as useful business context, but its intended rules, metrics, and implementation phase remain unclear.
- Mandatory notification channels beyond dashboard are not fully locked; email and WhatsApp support exist in architecture but not all real-world delivery assumptions are verified.
- Multi-destination support beyond Lebanon is referenced as a scaling concern, but early-phase geographic scope is still a product decision.

## 9. What Cursor Should Inspect First
- Inspect repo documentation first: `README.md`, `CLMS_README.md`, `docs/STAGED_PLAN.md`, `docs/ROUTES.md`, `docs/API.md`, `docs/ACCEPTANCE_UX.md`, `docs/REGRESSION_MATRIX.md`, `docs/RELEASE_CHECKLIST.md`, and `docs/PHASE2_NOTIFICATION_HARDENING.md`.
- Inspect current routing and page duplication: compare root pages such as `orders.php`, `receiving.php`, and `consolidation.php` against area-based pages under `buyers/`, `warehouse/`, `admin/`, and `superadmin/`.
- Inspect authentication and access control: review `login.php`, `includes/auth_check.php`, `includes/area_bootstrap.php`, `includes/layout.php`, and `backend/config/rbac.php`.
- Inspect schema and migrations: review `backend/migrations/` and `clms (1).sql` to confirm actual table coverage, lifecycle assumptions, and config keys.
- Inspect order lifecycle and workflow enforcement: review `backend/services/OrderStateService.php`, `backend/api/handlers/orders.php`, and related frontend order/receiving scripts.
- Inspect upload handling and evidence capture: review `backend/api/handlers/upload.php`, `backend/uploads/`, `docs/UPLOADS.md`, and frontend upload utilities.
- Inspect receiving and variance logic: review `backend/api/handlers/receiving.php`, `warehouse/receiving/`, `frontend/js/receiving*.js`, and receipt photo/item tables.
- Inspect shipment and tracking integration boundaries: review `backend/api/handlers/shipment-drafts.php`, `backend/api/handlers/containers.php`, `backend/services/TrackingPushService.php`, `admin_tracking_push.php`, and superadmin tracking log pages.
- Inspect responsive layout quality and duplication: review `includes/layout.php`, `includes/area_layout.php`, `frontend/css/style.css`, and the main admin/warehouse/buyers pages for layout divergence.
- Inspect test coverage and current failures: review `tests/` with special attention to lifecycle, consolidation, receiving, upload, notification, and tracking tests.
- Identify broken or unfinished modules, duplicated code paths, and missing workflow states before adding net-new features.

## 10. Immediate Implementation Priorities
1. Understand current architecture before adding new behavior.
2. Inspect database and entities before assuming any workflow gaps.
3. Verify auth and roles across root pages, area pages, and API handlers.
4. Verify master data structure for customers, suppliers, products, and translations.
5. Verify order lifecycle support from draft through approval and consolidation readiness.
6. Verify warehouse receiving, evidence capture, and variance detection behavior.
7. Verify variance confirmation logic, including whether current UX actually supports the intended business process.
8. Verify consolidation and container model before expanding shipment planning behavior.
9. Verify tracking integration boundary, push logging, retry behavior, and local-versus-remote finalization assumptions.
10. Verify responsive usability on admin and warehouse pages with real browser testing when Playwright MCP or equivalent browser automation becomes available.

## 11. Code Quality Expectations
- Preserve or move toward modularity instead of expanding duplicated page logic.
- Preserve or move toward maintainability in handlers, services, SQL usage, and frontend scripts.
- Keep validation explicit and close to lifecycle transitions rather than relying on UI-only checks.
- Keep status transitions clean, centralized, and resistant to bypass.
- Reduce duplication between legacy root pages and area-based pages.
- Preserve responsive layouts and tablet-first usability for operational pages.
- Keep uploads safe, controlled, and operationally manageable.
- Preserve auditability for data changes, approvals, receipts, notifications, and tracking pushes.
- Prefer operational clarity over clever abstractions.
- Keep database design scalable and relationally coherent as scope expands.

## 12. Collaboration Protocol for Cursor
- Append findings instead of overwriting established project context unless the existing text is clearly wrong.
- Leave implementation notes under dated subsections so long-run changes remain traceable.
- Record architecture decisions as they are made, especially if they change route structure, lifecycle rules, config precedence, or integration boundaries.
- Record discovered risks, regressions, and operational mismatches immediately.
- Record mismatches between code and business requirements explicitly rather than silently working around them.
- Record recommended refactors when duplication, dead paths, or unclear ownership becomes visible.
- Record what has been implemented versus what is still pending so this file remains a usable project memory.
- Keep this file as the persistent memory between Codex and Cursor during the long development cycle.
- When browser QA eventually happens, append findings here instead of creating a parallel memory document.
- **Browser testing protocol:** Codex uses `docs/CODEX_BROWSER_TESTING.md` to run browser tests and report findings; Cursor fixes issues. See that file for the checklist, bug report template, and fix log.

## 13. Cursor Notes Section
### Cursor Notes
- [ ] Findings
- [ ] Risks
- [ ] Architecture corrections
- [ ] Missing modules
- [ ] Broken modules
- [ ] Next actions

### 2025-02-19 — Cursor Architecture Audit

**Architecture summary:**
- Plain PHP + MySQL + Bootstrap + vanilla JS. No composer, no package.json. Direct PHP pages, REST handlers under `backend/api/handlers/`, frontend JS in `frontend/js/`, migrations in `backend/migrations/`.
- **Auth:** Session-based via `login.php`, `auth_check.php` (checks `user_id` only). Root pages use `layout.php` which includes `auth_check.php` — **no role enforcement at page level**. Area pages use `area_bootstrap.php` which enforces roles per area. API enforces RBAC in `backend/api/index.php` for orders approve/receive, containers, shipment-drafts, users, config, receiving, tracking-push-log, diagnostics.
- **Config precedence:** `backend/config/config.php` loads `.env` first (only if key not already in `$_ENV`), then `system_config` overwrites. **system_config is authoritative when present.**
- **Order lifecycle:** `OrderStateService.php` defines transitions; matches handoff §5. Receiving flow uses `Approved` / `InTransitToWarehouse` → `ReceivedAtWarehouse` or `AwaitingCustomerConfirmation`. Variance detection uses `variance_threshold_percent`, `variance_threshold_abs_cbm`, `confirmation_required` from config.
- **Audit trail:** `audit_log` written for order create/update, submit, approve, receive, confirm; shipment_draft create/update. `logClms()` used for order_received.
- **Tracking:** `TrackingPushService` — idempotent push, dry-run default, retries, `tracking_push_log` table. Local finalize first; push can fail and retry.

**Highest-risk issues (prioritized):**
1. **Root pages have no role enforcement.** `auth_check.php` only verifies `user_id`. Any authenticated user can access `orders.php`, `consolidation.php`, `receiving.php`, etc. Sidebar hides links by role, but direct URL access works. **Recommendation:** Add role checks to root pages or migrate all traffic to area-based routes.
2. **Duplicate receiving implementations with feature drift.** Root `receiving.php` (uses `receiving.js`) has filters, calendar, schedule, L/H/W for CBM. Warehouse area `warehouse/receiving/receive.php` (uses `receiving_receive.js`) has no L/H/W option — CBM only. `warehouse/receiving/queue.php` and `history.php` are **stubs** that redirect to root receiving. `warehouse/index.php` links to `queue.php` (stub) instead of `warehouse/receiving/index.php` (which has full queue+history tabs). **Recommendation:** Unify on one receiving flow; fix warehouse index link; align L/H/W support.
3. **phase2_integration_test failure.** Test inserts `(user_id, channel, event_type) = (1, 'email', 'order_received')` into `user_notification_preferences` (UNIQUE on those columns). Failure indicates row already exists — from seed, notification-preferences UI, or prior test run. **Recommendation:** Use `INSERT ... ON DUPLICATE KEY UPDATE` or delete before insert; or run in isolated DB.

**Other findings:**
- **admin/consolidation.php** and **buyers/orders.php** are redirects to root pages. Root pages are canonical.
- **warehouse/receiving/index.php** has full queue+history UI with `receiving_index.js`; `filterCustomer` exists in HTML but `loadQueue()` does not pass `customer_id` to API — customer filter is non-functional.
- **Customer confirmation:** API supports `POST /orders/{id}/confirm`; `customer_confirmations` table exists. No dedicated customer-facing confirmation page found. Admin can confirm from orders UI.
- **Docs vs code:** `docs/ROUTES.md` lists area routes as primary; root as "legacy to phase out." Implementation: root is primary, area mostly redirects except warehouse receiving.
- **receiving_index.js** uses `filterOrderId`, `filterDateFrom`, `filterDateTo`; API `/receiving/queue` supports `customer_id`, `supplier_id`, `order_id`, `date_from`, `date_to`, `shipping_code`. Customer and supplier filters not wired in warehouse queue UI.

**Architecture assumptions validated:**
- Config: system_config overwrites .env when present ✓
- OrderStateService is lifecycle anchor ✓
- audit_log written for order submit, approve, receive, confirm ✓
- No `backend/models` layer; handler/service + SQL ✓

**Architecture assumptions to re-validate:**
- Root vs area: root is canonical; area is redirect or separate (warehouse). Deprecation path unclear.
- Customer confirmation: API exists; dedicated customer UX missing.

**Recommended next implementation step:**
1. ~~Fix warehouse entry point~~ — Done 2025-02-19
2. ~~Add role checks to root pages~~ — Done 2025-02-19
3. ~~Align warehouse receive with root (L/H/W)~~ — Done 2025-02-19
4. **Next:** User creation flow, department-scoped dashboard, i18n (EN/ZH)

## 14. Codex Initial Recommendations
- Audit lifecycle and state integrity first. The system already has a defined state machine, shipment flow, and receiving transitions; correctness matters more than feature count at this stage.
- Audit config precedence and RBAC next. `.env`, `system_config`, session auth, route gating, and handler-level role checks need a single coherent interpretation.
- Do not rush visual or structural rewrites before workflow correctness is confirmed. Root-page versus area-page duplication should be understood before it is consolidated.
- Design customer confirmation UX carefully before assuming that API support equals a complete business flow.
- Design the tracking push boundary carefully before expanding shipment logic. Local finalization, retry behavior, idempotency, and the real tracking contract must remain controlled and observable.
- Use the current repo as evidence, not just the high-level brief. This project is already partially built and should be stabilized through verification before broad expansion.

### Current Verification Snapshot
- Reviewed documentation: `README.md`, `CLMS_README.md`, `docs/STAGED_PLAN.md`, `docs/ROUTES.md`, `docs/API.md`, `docs/ACCEPTANCE_UX.md`, `docs/REGRESSION_MATRIX.md`, `docs/RELEASE_CHECKLIST.md`, and `docs/PHASE2_NOTIFICATION_HARDENING.md`.
- Confirmed local reachability of `http://localhost/cargochina/login.php`.
- Confirmed API login works using the seeded SuperAdmin credentials documented in the repo.
- Confirmed authenticated HTTP 200 responses for the main superadmin, admin, buyers, and warehouse entry pages.
- Confirmed `tests/smoke_test.php` passed.
- Confirmed `tests/lifecycle_test.php` passed.
- Confirmed `run-tests.bat` is mostly green, but `tests/phase2_integration_test.php` fails because it inserts a duplicate `user_notification_preferences` row (`1-email-order_received`).
- Treat that failing test as an investigation item: either the test is not isolated enough for an existing seeded state, or there is a real idempotency/preference-seeding issue.
- These checks are useful grounding only. They are not a replacement for the requested Playwright browser audit.
- Playwright MCP was not available in this session, so visual click-through validation remains pending.

## 15. Progress Ledger
This ledger should be maintained over time so both Codex and Cursor can see execution status without reconstructing context from chat history.

### Completed
- [x] 2025-02-19: Architecture audit (auth, routes, schema, lifecycle, receiving, consolidation, tracking, config)
- [x] 2025-02-19: Root page role enforcement (page_guard.php, role checks on all root pages)
- [x] 2025-02-19: Warehouse entry point fix (link to receiving/)
- [x] 2025-02-19: L/H/W support in warehouse receive.php
- [x] 2025-02-19: Departments table + user-department assignment (migration 022)
- [x] 2025-02-19: Enhanced user management (roles, departments, Edit modal)
- [x] 2025-02-19: Role-aware dashboard

### In Progress
- [ ] User creation flow (SuperAdmin)
- [ ] Wire customer/supplier filters in warehouse queue

### Blocked
- [ ] phase2_integration_test: PDO driver / DB unavailable in CLI context; duplicate user_notification_preferences when run

### Deferred
- [ ] Customer-facing confirmation page
- [ ] Playwright/browser QA

### Needs Decision
- [ ] Root vs area deprecation strategy
- [ ] Warehouse index: link to queue.php (stub) or receiving/index.php (full UI)
