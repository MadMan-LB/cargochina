# Salameh Cargo — China Logistics Management System (CLMS)
**Purpose:** Replace Excel-based China upstream operations with a structured, validated pipeline that feeds the existing customer-facing tracking platform.

> This README is written to be used as a **master prompt + living specification** for any AI/engineer working on the CLMS.
> It must be continuously updated as decisions are made, features are added, or the database changes.

---

## 0) One-sentence mission
Build a China Operations Logistics Platform that **prevents bad data at the source**, manages **orders → warehouse receiving → consolidation → container assignment**, and then **pushes finalized shipments** into the existing tracking system.

---

## 1) Problem statement (what we are fixing)
China operations currently rely on **manually prepared Excel files** that are inconsistent and error-prone (translation errors, wrong CBM/weight, duplicates, missing fields, incorrect grouping). This forces the Lebanon team to spend hours fixing data before shipments can be created.

The CLMS eliminates Excel entirely and replaces it with a controlled system where:
- data is structured and validated at input time
- translations are automated and cached
- warehouse receives and verifies actual measures with photo evidence
- customer confirmation is captured when CBM/weight variances exist
- consolidation becomes container-based and standardized
- finalized shipments are pushed to tracking (no manual re-entry)

---

## 2) Scope boundaries (to prevent scope creep)
### In scope (Stages 0–3 + integration)
- Stage 0: Master Data Foundation (customers, suppliers, products, translations, images)
- Stage 1: Order & Supplier Management (order creation, item entry, validation, attachments, audit trail)
- Stage 2: Warehouse Receiving (actual CBM/weight/cartons, condition, photos, variance detection)
- Stage 2.5: Customer Notification + Confirmation (responsibility relieve on variances)
- Stage 3: Consolidation + Shipment Preparation (order grouping, shipment draft, container assignment)
- Integration: Push finalized shipment + items into existing tracking system (Stage 4–5 unchanged)

### Out of scope (unless explicitly approved later)
- Replacing the existing tracking platform
- Full accounting/billing automation
- Supplier portal / customer self-service ordering
- AI optimization (prediction, smart consolidation) beyond basic matching/duplicate detection

---

## 3) System stages (pipeline model)
- **Stage 0 — Master Data:** persistent profiles & reusable dataset
- **Stage 1 — Orders:** structured order intake and item management
- **Stage 2 — Receiving:** warehouse verification + evidence capture
- **Stage 2.5 — Confirmation:** notify customer + capture acceptance on variance
- **Stage 3 — Consolidation:** shipment drafts + container assignment
- **Stage 4–5 — Tracking:** existing system remains customer-facing layer (unchanged)

**Integration rule:** Data is pushed into tracking only after shipment draft is **finalized & validated**.

---

## 4) Core feature set (what CLMS provides)
### 4.1 Master Data (Stage 0)
- Customers: shipping codes, contacts, addresses, payment terms
- Suppliers: contacts, factory locations, reliability notes/metrics, history
- Products: persistent records (CBM, weight, packaging, HS code, images, translations)
- Translation cache: store original + approved English once, reuse forever
- Duplicate/similarity matching: suggest existing product definitions to reuse

### 4.2 Orders (Stage 1)
- Create order by customer & supplier, with expected ready date
- Add items with structured fields (qty, cartons, CBM, weight, description, packaging)
- Automatic translation + suggested matches + validation
- Document attachments (supplier invoice, packing list, photos)
- Audit trail: who changed what and when
- Role-based restrictions: employees cannot bypass validation rules

### 4.3 Warehouse Receiving (Stage 2)
- Record actual cartons, actual CBM, actual weight at warehouse arrival
- Condition tracking + photo evidence per item/order
- Variance detection (declared vs. actual) with escalation workflow

### 4.4 Customer Confirmation (Stage 2.5)
- Notify customer when order arrives to warehouse
- If CBM/weight differs beyond a threshold, require customer confirmation
- Confirmation captures “responsibility relieve” to reduce disputes and margin leakage

### 4.5 Consolidation (Stage 3)
- Group orders into shipment drafts
- Assign shipments to containers (container-based management)
- Capacity control (CBM/weight) by container
- Finalize shipment draft and push to tracking system

### 4.6 Notifications
Triggers (minimum):
- Order created
- Order received at warehouse
- Variance detected → confirmation required
- Shipment draft created/finalized
- Container assigned / shipped / arrived (via tracking milestones)

Channels:
- Dashboard (mandatory)
- Email (optional)
- WhatsApp (future optional)

---

## 5) Order lifecycle (state machine)
**CLMS must enforce the lifecycle; users cannot skip required transitions.**

### 5.1 High-level states
1. `Draft`
2. `Submitted` (ready for internal review)
3. `Approved` (validated)
4. `InTransitToWarehouse` (optional)
5. `ReceivedAtWarehouse` (actual measures + photos captured)
6. `AwaitingCustomerConfirmation` (only if variance triggers)
7. `Confirmed` (customer accepted actual measures)
8. `ReadyForConsolidation`
9. `ConsolidatedIntoShipmentDraft`
10. `AssignedToContainer`
11. `FinalizedAndPushedToTracking`

### 5.2 Transition rules (critical)
- Draft → Submitted: required fields complete (min fields defined below)
- Submitted → Approved: admin validation passes (duplicate checks resolved)
- ReceivedAtWarehouse: warehouse staff must enter actual CBM/weight/cartons + evidence
- Variance rule: if (Actual − Declared) exceeds threshold → `AwaitingCustomerConfirmation`
- Push rule: only `FinalizedAndPushedToTracking` creates records in tracking DB

---

## 6) Data integrity (non-negotiable rules)
### 6.1 Required fields (minimum)
Order:
- customer, supplier, expected_ready_date, status

Order item:
- product reference OR “new product candidate”
- quantity + unit type (cartons/pieces) **must be consistent**
- declared CBM + weight (numeric sanity checks)
- description (CN allowed; EN generated)

Receiving:
- actual cartons, actual CBM, actual weight, condition
- evidence photos when variance or damage is present

### 6.2 Validation rules
- No missing required fields
- No negative or zero CBM/weight where not logical
- Unit consistency per item (cartons vs pieces)
- Duplicate products require explicit resolution (reuse existing vs create new)
- Shipment draft cannot be finalized if any included order is not eligible

---

## 7) Container-based operating model (reality mapping)
CLMS models actual logistics:
- Customers → Orders → Items
- Orders → Shipment Drafts
- Shipment Drafts → Containers

Container events drive operational control and customer notifications.

---

## 8) Integration contract with existing Tracking System
**Tracking system remains final visibility layer.** CLMS only pushes finalized data.

### 8.1 What CLMS pushes
- Shipment header + linkage to container
- Shipment items (products, quantities, CBM/weight, descriptions)
- Attached documents if required (optional)

### 8.2 What CLMS must NOT do
- Modify tracking milestone logic without explicit approval
- Create partial shipments or drafts in tracking
- Break existing customer-facing tracking UX

---

## 9) Roles & permissions (RBAC)
- **China Employee:** create orders, add items, upload docs/photos; cannot override rules
- **China Admin:** approve orders, manage master data, resolve duplicates
- **Warehouse Staff:** receive goods, record actuals, upload evidence; cannot edit commercial master
- **Lebanon Admin:** oversee consolidation readiness and integration
- **Super Admin:** configuration, thresholds, user management, audits

---

## 10) KPIs / Success metrics
- Correction time per shipment: **-90%**
- Missing fields rate: near zero
- Duplicate product rate: reduced via matching + governance
- CBM dispute incidents: reduced via evidence + confirmation
- Shipment creation lead time: reduced via standardized pipeline
- Scalability: supports 10x volume without chaos

---

## 11) CEO decision checklist (must be locked early)
1. Confirmation policy:
   - Only when variance triggers? Or always on warehouse arrival?
2. Variance threshold:
   - % difference (e.g., 10%) and/or absolute difference (e.g., 0.1 CBM)
3. Photo visibility:
   - Customer can see warehouse photos? (Yes/No)
4. Supplier reliability scoring:
   - Required in phase 1 or later?
5. Mandatory notification channels:
   - Dashboard only vs Email vs WhatsApp
6. Multi-destination support:
   - Beyond Lebanon in phase 1?

**When these are decided, update the section below.**

---

## 12) Configuration (single source of truth)
- VARIANCE_THRESHOLD_PERCENT: TBD
- VARIANCE_THRESHOLD_ABS_CBM: TBD
- CONFIRMATION_REQUIRED: (variance-only | always-on-arrival)
- CUSTOMER_PHOTO_VISIBILITY: (internal-only | customer-visible)
- NOTIFICATION_CHANNELS: [dashboard, email?, whatsapp?]

---

## 13) AI/Engineer collaboration protocol (MANDATORY)
Any AI/engineer working on this system must follow these operating rules:

### 13.1 Output expectations
- No generic explanations.
- Provide concrete deliverables: workflow, pages, endpoints, DB changes, and edge cases.

### 13.2 Notes for future programming
- Every time you propose a new feature, also add:
  - affected modules
  - affected tables/fields
  - lifecycle impact
  - migration steps
  - test cases

### 13.3 Database change discipline
- **Never** change schema without adding a migration note.
- Maintain `DB_CHANGELOG` with:
  - date
  - what changed
  - why
  - backward compatibility impact
  - rollback plan

### 13.4 Keep expanding context
- If new operational facts are discovered (warehouse workflow, customer constraints, supplier behavior),
  append them to:
  - `CONTEXT_APPENDIX`
  - and update `REQUIREMENTS` if it changes validation/lifecycle.

---

## 14) Module map (high-level)
- Master Data: customers / suppliers / products / translations / images
- Orders: order intake + item entry + validation + attachments
- Receiving: actual measures + condition + evidence + variance detection
- Confirmation: customer notification + acceptance capture + audit proof
- Consolidation: shipment drafts + container assignment + capacity tracking
- Integration: push-to-tracking job + mapping + error handling
- Notifications: event-driven trigger engine + delivery channels
- Audit: immutable activity log + admin override records

---

## 15) DB_CHANGELOG (keep updating)
> Add entries as changes happen. Newest on top.

- 2025-02-19 — Migrations 003–005 (Warehouse, Notifications, Consolidation)
  - Change: warehouse_receipts, warehouse_receipt_photos, notifications, customer_confirmations, containers, shipment_drafts, shipment_draft_orders
  - Reason: Full pipeline Stages 2–3 + notifications
  - Rollback: DROP TABLE shipment_draft_orders, shipment_drafts, containers, customer_confirmations, notifications, warehouse_receipt_photos, warehouse_receipts

- 2025-02-19 — Initial schema (Phase 1–2)
  - Change: Created users, roles, user_roles, customers, suppliers, products, translations, orders, order_items, order_attachments, audit_log
  - Reason: Bootstrap CLMS with master data and order management foundation
  - Affected tables: (new) all above
  - Migration steps: Run `php backend/migrations/run.php` after creating DB
  - Rollback plan: DROP TABLE audit_log, order_attachments, order_items, orders, translations, products, suppliers, customers, user_roles, users, roles, _migrations

- YYYY-MM-DD — (TBD)
  - Change:
  - Reason:
  - Affected tables:
  - Migration steps:
  - Rollback plan:

---

## 16) DECISION_LOG (keep updating)
> Capture CEO/ops decisions. Newest on top.

- YYYY-MM-DD — (TBD)
  - Decision:
  - Rationale:
  - Impacted modules/states:

---

## 17) OPEN_QUESTIONS (keep updating)
- Q1: Variance thresholds?
- Q2: Customer sees photos or not?
- Q3: WhatsApp notifications in Phase 1 or later?
- Q4: Are partial shipments allowed internally (draft-only) before finalization?

---

## 18) CONTEXT_APPENDIX (keep expanding)
- Excel replacement is mandatory; no fallback workflow.
- Factories may misdeclare CBM; warehouse verification is the source of truth.
- Customer confirmation is required to reduce disputes and protect margins.
- System must scale internationally and remain compatible with existing tracking platform.

---

## 19) Prompt footer (copy/paste into any AI chat)
**You are working on the Salameh Cargo CLMS.**
Your goals:
1) Replace Excel with validated structured data entry.
2) Enforce the order lifecycle and prevent bypassing.
3) Implement warehouse verification with evidence and variance detection.
4) Implement customer notification + confirmation when variance triggers.
5) Implement consolidation into shipment drafts and container assignment.
6) Push finalized shipments into the existing tracking system only after validation.

While working:
- Always propose DB changes with migrations and add to DB_CHANGELOG.
- Always leave NOTES for future programming and edge cases.
- Update this README with any new decisions or context.
