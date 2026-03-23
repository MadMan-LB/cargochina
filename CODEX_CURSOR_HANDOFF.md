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
- Draft an Order builder: `procurement_drafts.php` now saves real `orders` with `order_type='draft_procurement'`; current builder behavior is one visible description input per item, with server-side CN/EN auto-fill via `TranslationService`, and `Custom design` staying off by default unless the saved draft item already had it enabled.
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

## 13. Strategic Benchmark Report — CargoWise and Alibaba

> **Scope of this section:** Documentation and strategy only. No code, schema, route, or API changes in this pass. This section is decision-support for the CEO and engineering team — not an implementation ticket.
>
> **Sources consulted:**
> - CargoWise: [cargowise.com](https://www.cargowise.com/), [Warehouse](https://www.cargowise.com/solutions/cargowise-warehouse/), [Customs](https://www.cargowise.com/solutions/cargowise-customs/), [Workflow & Productivity](https://www.cargowise.com/solutions/cargowise-enterprise/workflow-and-productivity/)
> - Alibaba: [How it Works](https://buyer.alibaba.com/page/HowItWorks/Page.html), [Trade Assurance](https://tradeassurance.alibaba.com/ta/shippingandlogistics.htm), Verified Supplier program documentation (2025)
> - CLMS repo: `CODEX_CURSOR_HANDOFF.md`, `CLMS_README.md`, `docs/STAGED_PLAN.md`, `docs/VISION_REFERENCE_SYSTEMS.md`, `docs/API.md`

---

### Executive Framing

CargoWise is the most complete logistics workflow platform in the market: 17,000+ organizations, 193 countries, 30 languages. It covers freight forwarding, customs compliance, warehouse management, document generation, milestone tracking, operational accounting, and customer/vendor portals on a single database. It is the standard for what mature internal logistics operations look like.

Alibaba is the dominant China sourcing marketplace. Its supplier-trust model (Trade Assurance, Gold Supplier, Verified Supplier badges), RFQ/quote workflow, and logistics coordination layer are the reference for how sourcing confidence and supplier communication can be structured at scale.

**Neither should be copied wholesale.** CargoWise is enterprise freight-forwarder infrastructure — designed for large MNCs managing global multi-modal operations with carrier integrations, customs automation across 193 countries, and payroll. Salameh Cargo is an upstream China operations team feeding a specific tracking system. Importing CargoWise's full complexity would destroy the internal simplicity that makes CLMS valuable.

Alibaba is a public marketplace. It cannot be cloned internally. But its trust patterns — visibility into supplier verification status, order protection milestones, structured communication history — contain lightweight ideas CLMS can adapt.

The right question for each capability is: **does Salameh Cargo's specific problem justify the cost and complexity of this feature at this scale?** If not, the answer is "defer" or "ignore" — not "eventually build it."

---

### Salameh Operating Model to Preserve

Before benchmarking, state what must not be disrupted:

| Principle | Why it must stay |
|---|---|
| **CLMS is internal upstream operations only** | Tracking is already customer-facing; CLMS should never attempt to replace it |
| **Sourcing support** | Field staff + buyers manage supplier visits, quotes, and orders; CLMS is their tool |
| **Order control and evidence** | Every order is captured, approved, warehouse-verified with photos, and variant-confirmed before consolidation |
| **Warehouse proof** | Physical receipt with actual CBM/weight/cartons + photo evidence is the core value proposition against Excel |
| **Consolidation and container assignment** | Multiple orders may share a container; the system must support grouping without losing order-level traceability |
| **Push to tracking** | The final handoff is a controlled, idempotent, auditable push — not a manual re-entry or an API fire-and-forget |
| **Operational simplicity over enterprise bloat** | The system is operated by a small China-based team; complexity that requires training, maintenance, or configuration overhead beyond this scale is harmful |

---

### CargoWise Capability Map Relevant to Salameh

#### 1. Execution Workflow Orchestration

**What CargoWise provides:** Step-by-step workflow engine with configurable milestones, exception alerts, task assignment, dynamic routing, SOP-embedded training material per job step, and an exception dashboard showing current and historical deviations.

**CLMS status:** CLMS has a lifecycle state machine (`OrderStateService.php`) covering Draft → Finalized. The dashboard exposes "My tasks" per role. There are no configurable workflow steps, no automatic exception escalation, and no exception dashboard beyond the audit log.

**Recommendation:** **Partially adapt.** The lifecycle model is sufficient for now. What is genuinely missing is exception visibility: when an order has been in "Awaiting Customer Confirmation" for 3+ days, there is no alert. A lightweight "stale orders" dashboard widget or notification rule would capture 80% of CargoWise's exception value at 5% of the complexity. Do not build a full workflow engine.

---

#### 2. Customs / Compliance / Document Control

**What CargoWise provides:** Automated HS code classification, tariff lookups, country-by-country customs declaration templates, bonded warehouse support, permit tracking, automatic document generation (HBL, MBL, AWB, BOL), and compliance workflow automation across 193 countries.

**CLMS status:** HS codes are stored per product (`hs_code` column). Shipping codes per order item are captured. Shipment draft documents (BOL, booking confirmation, invoice) can be attached via file upload. **Lebanon tariff catalog** (`hs_code_tariff_catalog`) can be imported from `hs codes/` folder; Products page and HS Code Tax page use catalog search for HS code autocomplete. Manual tax rates remain in `hs_code_tax_rates`. No automated customs declarations.

**Recommendation:** **Ignore for now, defer selectively.** Salameh is a China-to-Lebanon forwarder, not a customs broker. The carrier handles declarations. CLMS should continue to capture HS codes as metadata for the tracking push, but should not build customs automation. The one genuine gap is structured document attachment to shipments (BOL, packing list, commercial invoice as labeled types). This is already partially implemented via `shipment_draft_documents` and should be made more visible in the UI — this is a "now" improvement, not a customs automation project.

---

#### 3. Warehouse and Transport Coordination

**What CargoWise provides:** Transit warehouse with receipt by waybill/SSCC label, barcode scanning, quarantine and quality-assurance workflows, inventory location management, inbound/outbound task queues, and pick/pack/dispatch management.

**CLMS status:** Warehouse receiving exists: actual cartons/CBM/weight, condition, photo evidence, optional per-item granularity, variance detection, and customer confirmation flow. There is no barcode/SSCC scanning, no location management, and no quarantine workflow.

**Recommendation:** **Partially adapt over time.** The current receiving flow is well-matched to Salameh's scale (one China warehouse hub, not a multi-site inventory business). Barcode scanning and location management are overkill for the current operation. What is genuinely missing: a clear warehouse task queue (orders pending receipt, sorted by expected date), and better per-item condition tracking for damaged goods. Both can be done without adopting CargoWise's warehouse complexity.

---

#### 4. Milestone Visibility

**What CargoWise provides:** Real-time milestone tracking from booking through delivery. Milestones have estimated and actual dates. Stakeholders receive automated notifications at each milestone. Exception alerts fire when milestones are missed.

**CLMS status:** The pipeline page (`pipeline.php`) provides a stage-count overview. Order-level lifecycle state is well-tracked. There are no milestone estimated/actual date pairs beyond `expected_ready_date`. Notifications fire at submission, approval, receipt, and finalization — but not at variance confirmation or consolidation readiness.

**Recommendation:** **Partially adapt.** Add `expected_ready_date` discipline (already exists), and add two lightweight milestone alerts: (a) order stuck in `AwaitingCustomerConfirmation` beyond a configurable threshold, and (b) shipment draft finalized but push not yet attempted. These are high operational value with low complexity. Full milestone date-pair management is enterprise-grade overhead for Salameh's current scale.

---

#### 5. Customer / Vendor Collaboration Surfaces

**What CargoWise provides:** Customer Service Portal with ticket/case management, CRM module for vendor relationships, automated status emails and document sharing with external parties, and self-service visibility portals for customers and partners.

**CLMS status:** Supplier interactions (visits, quotes, notes) are implemented. Customer deposits are tracked. Order-level notifications fire to configured channels (dashboard, email, WhatsApp). There is no customer-facing portal. Customer confirmation for variance is handled admin-side (admin confirms on behalf of customer) — a dedicated customer confirmation UX is explicitly missing per the architecture notes.

**Recommendation:** **Partially adapt.** Customer confirmation UX is the highest-value gap here. It does not require a full portal — a secure, link-based confirmation page per order (accessible via a token in the notification) would close the business-process gap at minimal cost. A full self-service customer portal is deferred. Supplier interaction logging already covers the most valuable part of the vendor collaboration story.

---

#### 6. Operational Accounting / Reporting Discipline

**What CargoWise provides:** Integrated operational accounting, AR/AP automation, rate and charge management, cost allocation per shipment, profit and loss per job, multi-currency ledgers, Xero/SAP/Oracle integrations, and scheduled report exports.

**CLMS status:** Customer deposits exist (`customer_deposits`). Supplier payments exist (`supplier_payments`). Items have `unit_price` and `total_amount`. There are no ledger views, no balance calculations surfaced in the UI, no per-order P&L report, and no accounting export.

**Recommendation:** **Partially adapt in the medium term.** Salameh needs ledger visibility (customer balance, supplier balance) and basic per-order margin visibility. It does not need AR/AP automation or ERP integrations at this stage. `VISION_REFERENCE_SYSTEMS.md` Section 3 and 5 already describe this gap accurately. The accounting schema pieces are already in place — the gap is presentation and reporting, not new tables.

---

### Alibaba Reference-Only Capability Map

#### 1. Supplier Discovery and Trust Signals

**What Alibaba provides:** Gold Supplier badge (paid membership with business license verification), Verified Supplier certification (on-site audit), Trade Assurance eligibility, transaction history, response rate, and aggregated buyer reviews.

**Relevant to Salameh?** Partially. Salameh already has established supplier relationships; this is not primarily a discovery problem. However, the concept of a per-supplier trust/reliability signal — combining visit history, payment history, and order quality — is genuinely useful.

**CLMS recommendation:** **Lightweight internal version, defer formalization.** Supplier visits (`supplier_interactions`), payment history (`supplier_payments`), and order items already provide the raw data for a supplier reliability score. The pieces are in the database. A formal "supplier score" dashboard widget is deferred until enough transaction history exists. Do not build Alibaba-style public ratings — this is purely internal.

---

#### 2. RFQ / Quote-Request Style Workflow

**What Alibaba provides:** Structured RFQ posting, multiple sellers bidding, quote comparison, and integrated negotiation before order placement.

**Relevant to Salameh?** No. Salameh field staff negotiate in person, via WhatsApp, or by visiting stores. An RFQ workflow would change the sourcing process more than it would improve it at this scale.

**CLMS recommendation:** **Ignore.** Do not build an RFQ module. The supplier interaction log (visit/quote/note) already captures what is meaningful. If the business grows into remote sourcing at scale, revisit.

---

#### 3. Communication and Negotiation Traceability

**What Alibaba provides:** In-platform messaging with full history, auto-translated communication, integrated with order state, and attached to supplier profile.

**Relevant to Salameh?** The communication history concept is relevant. Currently, negotiation and visit notes are captured via `supplier_interactions` but there is no structured message thread or negotiation record attached to a specific order.

**CLMS recommendation:** **Lightweight adaptation, deferred.** The current supplier interaction log covers visits and notes. If the team needs to trace negotiation around a specific order, a notes/comments field on orders could satisfy this without building a messaging platform. Defer structured messaging.

---

#### 4. Logistics Service Coordination

**What Alibaba provides:** Alibaba Logistics (formerly OneTouch): a freight booking layer integrated into the platform for sea/air/express shipping from China, with carrier comparison, booking, and tracking.

**Relevant to Salameh?** No. Salameh has its own carrier/shipping arrangement and a dedicated tracking system. Adding Alibaba-style carrier booking would duplicate and conflict with the existing downstream tracking workflow.

**CLMS recommendation:** **Ignore.** CLMS is the upstream preparation layer. The carrier and tracking system are the logistics execution layer. Do not import carrier booking or freight quoting into CLMS.

---

#### 5. Trade-Assurance Style Confidence Patterns

**What Alibaba provides:** Escrow payment hold, delayed release pending receipt confirmation, order protection milestones (payment confirmed → production → shipment → delivery), and dispute mediation.

**Relevant to Salameh?** The core concept — money is not fully released until confirmed receipt — maps directly to Salameh's customer confirmation and supplier payment workflow. The pattern of "evidence → confirmation → payment release" is exactly what CLMS already supports at the operational level.

**CLMS recommendation:** **Already adopted in spirit.** The warehouse receipt + variance confirmation + supplier payment ledger effectively implements this pattern internally. What is missing is making the payment-release signal visible: after a receipt is confirmed and the supplier invoice is marked full payment, a dashboard indicator should show this state clearly. No new architecture needed — this is a UI/reporting gap.

---

### Current CLMS Baseline by Workflow

#### Supplier / Master Data
**Status: Strong.** Suppliers, customers, products, and translations are all present with search, contacts, factory location, payments, interaction logs, and HS codes. `supplier_interactions` covers field staff visit capture. Gaps: no supplier reliability score, no address/fax display in supplier detail view (recently added to DB via migration 029 but UI may lag).

#### Orders / Item Capture
**Status: Strong.** Full item capture including cartons, CBM, weight, unit price, shipping code, HS code, images, description (CN/EN). Template system exists. Per-item supplier assignment added. Per-order export to CSV. Approval lifecycle enforced.

#### Receiving / Variance
**Status: Strong.** Actual CBM/weight/cartons with L×W×H auto-calculation, condition, photo evidence, per-item optional, variance detection, and automatic state transition to `AwaitingCustomerConfirmation`. Evidence photo requirement enforced. Gap: searchable order selection in receiving now improved (just implemented). Per-item item-level receiving is implemented but feature-flagged.

#### Confirmation
**Status: Partial.** API and state machine support `POST /orders/{id}/confirm`. Admin can confirm from orders UI. A dedicated customer-facing confirmation page does not exist. This is explicitly flagged in the architecture notes as a gap.

#### Consolidation / Container Handling
**Status: Good.** Shipment drafts, order grouping, container assignment, capacity awareness (CBM/weight running totals), carrier refs, document attachments, tracking push. Gap: no auto-suggestion of which orders fit a container; no visual container fill indicator beyond running totals.

#### Documents
**Status: Partial.** Order-level attachments exist. Shipment draft documents (BOL, booking confirmation, invoice) exist via `shipment_draft_documents`. No document template generation (unlike CargoWise's HBL/BOL auto-generation). No labeled, searchable document archive. Gap is manageable at current scale.

#### Notifications / Tasks
**Status: Good.** Dashboard, email, and WhatsApp channel support. Role-scoped "My tasks" on dashboard. Notification preferences per user/event. Delivery log. Gap: no escalation alerts for stale-state orders; notification channel delivery beyond dashboard is architected but real-world delivery is not verified.

#### Reporting / Finance
**Status: Weak.** CSV exports exist for orders and receiving queue. Customer deposits and supplier payments are in the database. No balance views, no per-order P&L, no ledger UI. This is the single largest gap relative to operational maturity.

#### Internationalization / Admin Controls
**Status: Basic.** Translation cache for product descriptions exists. Interface is English-only. Multiple currencies (USD/RMB) supported. Multi-country routing not implemented. RBAC is in place. Configuration is admin-editable via superadmin UI. Diagnostics page exists.

---

### Gap Analysis Table

| Capability Area | Have Now | Partially Have | Missing but High-Value | Not Needed / Out of Scope | Recommended Action |
|---|---|---|---|---|---|
| **Workflow control** | Lifecycle state machine, role enforcement, audit log | Exception visibility (stale orders), task assignment | Stale-order alerts, configurable escalation thresholds | Full workflow engine à la CargoWise | Add stale-order alerts as config-driven notifications |
| **Supplier trust and sourcing support** | Visit log, payment history, interaction notes | Reliability signal (data exists, not surfaced) | Supplier score dashboard widget | Public ratings, RFQ marketplace | Defer score widget; wire existing data |
| **Document and evidence handling** | Photo evidence per receipt, shipment draft docs, order attachments | Document labeling and search | BOL/packing list labeled archive, doc completeness indicator | Auto-generated customs docs, HBL/MBL templates | Add doc type labels and completeness flag to shipment draft UI |
| **Visibility and dashboards** | Pipeline stage counts, My tasks | Per-order milestone history | Stale-order dashboard, per-customer/supplier summary | Real-time vessel tracking, IoT integrations | Add stale-order widget and ledger summary cards |
| **Tasking and ownership** | Role-scoped My tasks on dashboard | No assignment of tasks to specific users | Per-user assignable task with due date | Full CRM task management, HR workflows | Defer; dashboard "My tasks" is sufficient at current scale |
| **Reporting and financial clarity** | CSV export, customer deposits, supplier payments in DB | No balance views | Customer balance, supplier balance, per-order P&L, accounting export | AR/AP automation, ERP integration | Build ledger view and P&L query — highest-priority gap |
| **International readiness** | Multi-currency (USD/RMB), translation cache | English-only UI | Arabic/Chinese UI toggle, multi-destination routing | Full i18n framework, vessel/port integrations | Defer unless new country added; document the intent |
| **Admin / config / governance** | RBAC, superadmin config UI, diagnostics, audit log | Config precedence (system_config vs .env validated) | Role-scoped config sections, config change audit trail | Enterprise governance modules | Add config change entries to audit_log |

---

### Recommended Roadmap for Salameh

#### Now — Highest-Value Internal Upgrades (No Core Change)

1. **Customer confirmation UX.** Build a secure, token-based confirmation page accessible from the notification. This closes the biggest known business-process gap. API is ready; only the UX is missing. See `POST /orders/{id}/confirm` and `customer_confirmations` table.

2. **Ledger views.** Customer balance (deposits vs. order totals), supplier balance (payments vs. declared costs). Data is in the database. This is a reporting/UI task, not a schema task. Directly enables finance visibility without building an accounting system.

3. **Stale-order escalation notifications.** Add a configurable rule: if an order remains in `AwaitingCustomerConfirmation` beyond N days, fire a dashboard notification to ChinaAdmin. Low implementation cost, high operational value.

4. **Supplier address/fax in UI.** Migration 029 added these columns. Ensure the supplier form and detail view expose them — needed for the order CSV export header.

5. **Document completeness indicator on shipment draft.** Flag whether a draft has all expected document types (BOL, booking confirmation) before finalization. Prevents pushing an incomplete shipment to tracking.

#### Next — Meaningful Maturity Upgrades

6. **Per-order P&L view.** Supplier cost (sum of `unit_price × quantity` per item) vs. customer total (order-level charge or deposit) = margin per order. Add as a tab in the order detail view and as a filterable report.

7. **Supplier reliability score widget.** Aggregate existing data: number of orders, on-time delivery rate (declared ready date vs. confirmed date), dispute/variance rate. Show as a simple score on the supplier detail page. No new data collection needed.

8. **Notification channel verification.** Confirm that email and WhatsApp notifications actually deliver in the production environment. This is an ops validation task, not a development task, but it must happen before Salameh relies on them for customer confirmation.

9. **Stale-order dashboard widget.** A card on the dashboard showing how many orders are currently in each long-dwell state (AwaitingCustomerConfirmation, Approved, ReadyForConsolidation) with links to the relevant page. CargoWise's "exception dashboard" distilled to Salameh's scale.

10. **Container fill visualization.** In the consolidation draft modal, show a progress bar: current draft CBM / container capacity. Already have CBM totals and container capacity. Visualization only.

#### Later — Optional Strategic Capabilities

11. **Accounting export.** Periodic CSV/Excel by customer, supplier, or date range showing deposits, payments, balances, and margins. Useful for the accountant. Deferred because manual export is workable at current volume.

12. **Supplier scoring formal rules.** Define the metric formula and threshold triggers (e.g., score below 3.5 = flag for review). Requires sufficient transaction history before scoring is meaningful.

13. **Arabic/Chinese UI.** Relevant if the customer base or warehouse team needs it. The translation cache infrastructure exists. A UI language toggle requires frontend i18n work and is non-trivial. Defer until explicitly needed.

14. **Multi-destination routing.** If Salameh expands beyond Lebanon as a destination, CLMS will need destination-aware consolidation and push rules. Defer; document the intent so schema decisions do not accidentally block it.

15. **Customer self-service portal.** After the confirmation UX is built and validated, a broader portal (order history, deposit history, document access) is the natural extension. This is a strategic capability, not an operational necessity at current scale.

---

### Guardrails

State these explicitly so no future implementation pass ignores them:

- **Do not replace tracking.** CLMS is the upstream preparation layer. The existing tracking platform is the customer-facing layer. CLMS pushes to it; it does not absorb it.
- **Do not build public marketplace features.** No RFQ marketplace, no supplier discovery portal, no public product catalog. Alibaba is a reference, not a blueprint.
- **Do not overbuild customs/rate management** unless the CEO explicitly approves Salameh entering customs brokerage or freight rate negotiation as a core business service. Capturing HS codes as metadata is sufficient. Tariff catalog import (Lebanon reference data for HS search/autocomplete) is supported; full customs automation is not.
- **Do not copy CargoWise enterprise ERP complexity.** CargoWise's HR module, full AR/AP automation, and multi-modal carrier booking are not relevant to a China-to-Lebanon upstream operations team.
- **Do not import Alibaba marketplace concepts** (buyer/seller ratings, public storefronts, escrow payment rails) into CLMS. The internal equivalents (supplier interaction log, payment ledger, receipt confirmation) are the correct abstractions.

---

### CEO Decisions Still Needed

These are business decisions, not engineering decisions. Engineering should not proceed with assumptions on these items:

| Decision | Options | Why It Matters |
|---|---|---|
| **Customer confirmation policy** | Token-link UX vs. admin-confirms-on-behalf vs. no dedicated UX | Affects whether variance resolution requires customer contact at all, or whether admin absorbs the responsibility |
| **Photo visibility policy** | Warehouse evidence internal only vs. shared with customer on confirmation | Affects whether the confirmation page shows photos, and whether customers can download them |
| **Notification channel commitments** | Dashboard only vs. email vs. WhatsApp, and per-event granularity | Determines whether WhatsApp/email delivery must be validated before go-live |
| **Accounting / reporting depth** | Ledger views only vs. export vs. full P&L vs. accountant integration | Determines scope of Phase 3 financial build from `VISION_REFERENCE_SYSTEMS.md` |
| **Supplier scoring priority** | Defer until sufficient history vs. build now | If built too early with thin data, scores will mislead rather than inform |
| **International / multi-country rollout timing** | Lebanon only for the foreseeable future vs. next country within 12 months | Affects whether schema and routing need to be destination-aware now |

---

### Cursor / Codex Working Instructions

#### Classify as Product Tickets
- Customer confirmation token-link page (blocked on CEO decision above, but implementable once decided)
- Customer balance view and supplier balance view
- Stale-order notification rule (configurable threshold in `system_config`)
- Document completeness indicator on shipment draft finalization
- Per-order P&L view in order detail tab

#### Classify as Architecture Decisions
- Config change audit trail: should `UPDATE system_config` entries write to `audit_log`?
- Customer confirmation token security model: short-lived JWT vs. opaque DB token vs. session-based
- Ledger data model: derived from existing tables on read, or materialized via a separate ledger table?

#### Classify as UX Improvements
- Container fill progress bar in consolidation draft modal
- Supplier reliability summary card on supplier detail page
- Stale-order dashboard widget
- Document type labels and completeness badge on shipment draft

#### Classify as CEO / Business Decisions
- All items in the "CEO Decisions Still Needed" table above

---

### Cursor Implementation Starting Points

These are the specific files and modules to inspect when implementing the items above:

| Recommendation | Starting Points |
|---|---|
| Customer confirmation UX | `backend/api/handlers/orders.php` (`POST /orders/{id}/confirm`), `customer_confirmations` table in schema, `frontend/js/orders.js` (confirm flow), `backend/services/NotificationService.php` (notification on variance) |
| Ledger views | `backend/api/handlers/customers.php` (deposits), `backend/api/handlers/suppliers.php` (payments), `customer_deposits` table, `supplier_payments` table |
| Stale-order notification | `backend/services/NotificationService.php`, `backend/config/rbac.php`, `system_config` table (add configurable threshold key), `backend/api/handlers/orders.php` (receiving flow that sets `AwaitingCustomerConfirmation`) |
| Document completeness on shipment draft | `backend/api/handlers/shipment-drafts.php` (`finalize` action), `shipment_draft_documents` table, `consolidation.php` + `frontend/js/consolidation.js` |
| Per-order P&L | `backend/api/handlers/orders.php` (GET single order), `order_items` (`unit_price`, `total_amount`), `customer_deposits` |
| Supplier score widget | `backend/api/handlers/suppliers.php`, `supplier_interactions` table, `supplier_payments` table, `orders` table (filter by supplier_id) |
| Config change audit trail | `backend/api/handlers/config.php` (PUT handler), `audit_log` table |

---

*Benchmark section written 2026-02-19 by Cursor. Sources: CargoWise (cargowise.com), Alibaba (alibaba.com), CLMS repo as of this date. Update when business decisions resolve open items.*

---

## 14. Cursor Notes Section
### Cursor Notes
- [ ] Findings
- [ ] Risks

### Codex Task: Excel Export Test Data (2026-02-19)
**For Codex:** Create seed data to test the order/container Excel export feature.

1. **Containers:** Create 3 containers (e.g. 20HQ, 40HQ, 45HQ with appropriate max CBM/weight).

2. **Orders:** Create enough orders so that each container can be filled with **10–20 orders** (30–60 orders total). Each order should:
   - Have a customer, supplier, items with `description_en`, `description_cn`, cartons, qty, CBM, weight, unit_price, etc.
   - Be in a state that allows consolidation (e.g. `ReadyForConsolidation` or `Confirmed`).

3. **Fill containers:** Create shipment drafts, add orders to drafts, assign each draft to a container. Finalize so orders reach `AssignedToContainer` or `FinalizedAndPushedToTracking`.

4. **Images:** Import/attach images to order items (`order_items.image_paths`). Use real image files in `backend/uploads/` so the Excel export PHOTO column shows actual images. You can use placeholder images (e.g. small JPG/PNG) if needed.

5. **Verification:** After seeding, test:
   - Order export: `GET /api/v1/orders/{id}/export` → Excel with images, Arial, center align.
   - Containers page: Download each container → Excel with all orders separated by `##` and empty row.

Add a migration or seed script (e.g. `backend/migrations/` or `tests/` or `scripts/`) that can be run to populate this data.
- [ ] Architecture corrections
- [ ] Missing modules
- [ ] Broken modules
- [ ] Next actions

### 2026-03-13 — Codex Export/Container Seed + Browser Verification
- Seed automation added: `scripts/seed_consolidation_export_dataset.py`
- Seed output snapshot: `output/codex_seed_export_summary.json`
- Image assets created for export embedding: `backend/uploads/codex_seed/box-blue.png`, `backend/uploads/codex_seed/box-red.png`, `backend/uploads/codex_seed/box-green.png`
- Generated seed scope: 3 containers (`CODX26-20HQ`, `CODX26-40HQ`, `CODX26-45HQ`), 36 orders, 72 order items, 3 finalized shipment drafts, all seeded orders in `FinalizedAndPushedToTracking`
- Export verification artifacts:
  - `output/exports/order_84_export.xlsx`
  - `output/exports/container_7_export.xlsx`
  - `output/exports/container_8_export.xlsx`
  - `output/exports/container_9_export.xlsx`
  - `output/exports/playwright_order_84_download.xlsx`
  - `output/exports/playwright_container_download.xlsx`
- Verified in generated files: supplier header rows, Arial + centered alignment, embedded item images, supplier column for mixed-supplier orders, `##` separators and empty spacer rows in container exports.
- Playwright MCP findings/fixes:
  - Fixed runtime error on `containers.php`: `Identifier 'API_BASE' has already been declared` by updating `frontend/js/containers.js`.
  - Initial export failure due Composer platform mismatch (`zipstream-php` requiring PHP 8.3) resolved by pinning `maennchen/zipstream-php:^2.4`.
  - Main root pages and area entry points loaded successfully after fix.

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

## 15. Codex Initial Recommendations
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

## 16. Progress Ledger
This ledger should be maintained over time so both Codex and Cursor can see execution status without reconstructing context from chat history.

### Completed
- [x] 2025-02-19: Architecture audit (auth, routes, schema, lifecycle, receiving, consolidation, tracking, config)
- [x] 2025-02-19: Root page role enforcement (page_guard.php, role checks on all root pages)
- [x] 2025-02-19: Warehouse entry point fix (link to receiving/)
- [x] 2025-02-19: L/H/W support in warehouse receive.php
- [x] 2025-02-19: Departments table + user-department assignment (migration 022)
- [x] 2025-02-19: Enhanced user management (roles, departments, Edit modal)
- [x] 2025-02-19: Role-aware dashboard
- [x] 2026-03-13: Added deterministic seed script `scripts/seed_consolidation_export_dataset.py` to create 3 containers (`CODX26-20HQ`, `CODX26-40HQ`, `CODX26-45HQ`), 36 finalized orders (12 per container), item images under `backend/uploads/codex_seed/`, and shipment drafts with finalized statuses.
- [x] 2026-03-13: Verified export flow end-to-end after seeding. Downloaded and validated `GET /api/v1/orders/84/export` and `GET /api/v1/containers/{7,8,9}/export` as `.xlsx` with Arial + centered styling, embedded images, and `##` + empty-row separators in container exports.
- [x] 2026-03-13: Completed Playwright MCP click-through across main root pages and area entry points (superadmin/admin/buyers/warehouse), plus desktop/tablet/mobile overflow checks on core operational pages.
- [x] 2026-03-13: Fixed UI JS runtime error on containers page (`Identifier 'API_BASE' has already been declared`) by renaming local constant in `frontend/js/containers.js`.
- [x] 2026-03-13: Fixed Composer runtime mismatch blocking exports on PHP 8.2 by pinning `maennchen/zipstream-php` to `^2.4` (lockfile + vendor updated), removing the PHP 8.3 platform gate.
- [x] 2026-03-14: HS Code Tariff Catalog — migration 042, `hs_code_tariff_catalog` table, import from `hs codes/` CSV, Products + HS Code Tax pages use catalog autocomplete, Admin config importer, RBAC read/write, full downstream impact review.

### In Progress
- [ ] User creation flow (SuperAdmin)
- [ ] Wire customer/supplier filters in warehouse queue

### Blocked
- [ ] phase2_integration_test: PDO driver / DB unavailable in CLI context; duplicate user_notification_preferences when run

### Deferred
- [ ] Customer-facing confirmation page
- [ ] Full multi-role deep browser regression (admin/chinaadmin/warehouse/lebanonadmin/chinaemployee) with CRUD edge-cases and destructive-flow retesting

### Needs Decision
- [ ] Root vs area deprecation strategy
- [ ] Warehouse index: link to queue.php (stub) or receiving/index.php (full UI)

### For Codex (pending)
- [x] **Excel export test data:** Create 3 containers, 10–20 orders per container, fill containers via shipment drafts, import images for order items. Implemented via `scripts/seed_consolidation_export_dataset.py` and verified with Playwright/API exports.
