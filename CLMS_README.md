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
- **Phase 3 Tracking:** TRACKING_API_BASE_URL, TRACKING_API_TOKEN, TRACKING_API_PATH (/api/import/clms), TRACKING_API_TIMEOUT_SEC (15), TRACKING_API_RETRY_COUNT (3), TRACKING_API_RETRY_BACKOFF_MS (800), TRACKING_PUSH_ENABLED (0), TRACKING_PUSH_DRY_RUN (1)

---

## 12.1) Phase 3 — Tracking Integration Contract

**Payload shape (POST to tracking API):**
```json
{
  "header": { "shipment_draft_id", "container_id", "container_code", "order_ids" },
  "items": [ { "order_id", "product_id", "item_no", "quantity", "unit", "declared_cbm", "declared_weight", "unit_price", "total_amount", "description_cn", "description_en", "image_paths" } ],
  "documents": [ { "file_path", "type", "order_id" } ],
  "pushed_at": "ISO8601"
}
```

**Headers:** `Authorization: Bearer {TOKEN}`, `Idempotency-Key: clms-draft-{id}`, `Content-Type: application/json`

**Decision B:** Finalize always succeeds locally. Push can fail; admin retries via Consolidation "Retry Push" or Admin → Tracking Push Log.

**Operational runbook — If push fails:**
1. Check Admin → Tracking Push Log for last_error.
2. Verify TRACKING_API_BASE_URL and TRACKING_API_TOKEN in Admin → Configuration.
3. If 4xx: fix payload/contract. If 5xx: retry later.
4. Use "Retry Push" on the draft or from the push log.
5. Logs: `logs/tracking_push.log`; DB: `tracking_push_log` table.

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

- 2025-02-19 — Migration 018 (Production Hardening config)
  - Change: system_config WHATSAPP_PROVIDER, WHATSAPP_TWILIO_ACCOUNT_SID, WHATSAPP_TWILIO_AUTH_TOKEN, WHATSAPP_TWILIO_FROM, WHATSAPP_TWILIO_TO, NOTIFICATION_MAX_ATTEMPTS, NOTIFICATION_RETRY_SECONDS
  - Reason: Configurable WhatsApp provider (generic|twilio); notification retry policy
  - Rollback: DELETE FROM system_config WHERE key_name IN ('WHATSAPP_PROVIDER','WHATSAPP_TWILIO_ACCOUNT_SID','WHATSAPP_TWILIO_AUTH_TOKEN','WHATSAPP_TWILIO_FROM','WHATSAPP_TWILIO_TO','NOTIFICATION_MAX_ATTEMPTS','NOTIFICATION_RETRY_SECONDS');

- 2025-02-19 — Migrations 015–017 (Phase 2 Notification Hardening)
  - Change: warehouse_receipt_items, warehouse_receipt_item_photos; user_notification_preferences; notification_delivery_log (channel, payload_hash, status, attempts, last_error); notifications + channel; system_config EMAIL_*, WHATSAPP_*, ITEM_LEVEL_RECEIVING_ENABLED, PHOTO_EVIDENCE_PER_ITEM
  - Reason: Item-level receiving; configurable email/WhatsApp; user preferences; delivery logging
  - Rollback: DROP TABLE notification_delivery_log; DROP TABLE user_notification_preferences; DROP TABLE warehouse_receipt_item_photos; DROP TABLE warehouse_receipt_items; ALTER TABLE notifications DROP COLUMN channel; DELETE FROM system_config WHERE key_name IN ('EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','WHATSAPP_API_URL','WHATSAPP_API_TOKEN','ITEM_LEVEL_RECEIVING_ENABLED','PHOTO_EVIDENCE_PER_ITEM');
  - See: docs/PHASE2_NOTIFICATION_HARDENING.md

- 2025-02-19 — Migrations 012–013 (Tracking integration)
  - Change: system_config TRACKING_* keys; tracking_push_log table (idempotency, status, retries)
  - Reason: Phase 3 — configurable tracking API, idempotent push, retries, admin tools
  - Rollback: DELETE FROM system_config WHERE key_name LIKE 'TRACKING_%'; DROP TABLE tracking_push_log;

- 2025-02-19 — Migration 011 (Notification channels)
  - Change: system_config NOTIFICATION_CHANNELS (dashboard, email, whatsapp)
  - Reason: Phase 2 — configurable notification channels
  - Rollback: DELETE FROM system_config WHERE key_name='NOTIFICATION_CHANNELS';

- 2025-02-19 — Migrations 009–010 (Item capture, supplier store/payments)
  - Change: order_items + item_no, shipping_code, cartons, qty_per_carton, unit_price, total_amount, notes, image_paths; suppliers + store_id; supplier_payments, supplier_interactions; MIN_PHOTOS_PER_ITEM
  - Reason: Phase 1 — Excel-aligned item capture, supplier store ID, payment ledger, hunting process
  - Rollback: ALTER TABLE order_items DROP COLUMN item_no, DROP COLUMN shipping_code, ...; ALTER TABLE suppliers DROP COLUMN store_id; DROP TABLE supplier_interactions, supplier_payments; DELETE FROM system_config WHERE key_name='MIN_PHOTOS_PER_ITEM';

- 2025-02-19 — Migration 008 (Suppliers contact)
  - Change: suppliers.phone VARCHAR(50) NULL, suppliers.additional_ids JSON NULL
  - Reason: Responsive UI & data enhancements — supplier phone and flexible external IDs (Tax ID, VAT, etc.)
  - Affected tables: suppliers
  - Migration steps: Run `php backend/migrations/run.php` or `run-migrations.bat`
  - Rollback: ALTER TABLE suppliers DROP COLUMN phone, DROP COLUMN additional_ids;

- 2025-02-19 — Migration 003 fix: `condition` → `receipt_condition` (MySQL reserved word)
  - Change: Renamed column in warehouse_receipts
  - Reason: `condition` is reserved in MySQL/MariaDB
  - Rollback: ALTER TABLE warehouse_receipts CHANGE receipt_condition condition VARCHAR(20);

- 2025-02-19 — Migrations 006–007 (Seed admin, system_config)
  - Change: Seed SuperAdmin user (admin@salameh.com / password); system_config table for editable thresholds
  - Reason: RBAC requires default admin; SuperAdmin needs to edit variance/confirmation settings
  - Affected tables: users, user_roles, system_config
  - Migration steps: Run `php backend/migrations/run.php`
  - Rollback: DELETE FROM user_roles WHERE user_id IN (SELECT id FROM users WHERE email='admin@salameh.com'); DELETE FROM users WHERE email='admin@salameh.com'; DROP TABLE system_config;

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

- 2025-02-19 — Production hardening decisions
  - Default notification preferences: Option B — lazy seed on first GET when no rows exist; deterministic, documented in docs/API.md
  - WhatsApp provider: generic (default) or twilio; config keys WHATSAPP_TWILIO_* for Twilio; payload format differs per provider
  - Admin config UI: all Phase 2 keys (EMAIL_*, WHATSAPP_*, ITEM_LEVEL_RECEIVING_ENABLED, PHOTO_EVIDENCE_PER_ITEM, CUSTOMER_PHOTO_VISIBILITY, VARIANCE_*, NOTIFICATION_MAX_ATTEMPTS, NOTIFICATION_RETRY_SECONDS) editable in admin_config.php
  - Item-level receiving UX: when ITEM_LEVEL_RECEIVING_ENABLED=0, per-item section hidden via GET /config/receiving

- 2025-02-19 — Phase 2 open questions resolved
  - Q1 Variance thresholds: Use VARIANCE_THRESHOLD_PERCENT=10, VARIANCE_THRESHOLD_ABS_CBM=0.1; apply per item when item-level receiving used
  - Q2 Photo visibility: CUSTOMER_PHOTO_VISIBILITY = internal-only | customer-visible; default internal-only
  - Q3 WhatsApp: Implement as optional channel in Phase 2; require WHATSAPP_API_URL + token
  - Q4 Partial shipments: Internal drafts may be partial; tracking receives only finalized full shipments (no partial push)
  - Rationale: See docs/PHASE2_NOTIFICATION_HARDENING.md
  - Impacted modules: receiving, config, NotificationService, consolidation

- 2025-02-19 — Default variance thresholds and confirmation policy
  - Decision: VARIANCE_THRESHOLD_PERCENT=10, VARIANCE_THRESHOLD_ABS_CBM=0.1, CONFIRMATION_REQUIRED=variance-only
  - Rationale: Placeholder values until CEO locks; config editable by SuperAdmin in admin UI
  - Impacted modules: receiving, config, admin_config

- 2025-02-19 — RBAC enforcement
  - Decision: Approve orders (ChinaAdmin, LebanonAdmin, SuperAdmin); Receive (WarehouseStaff, SuperAdmin); Containers/Users/Config (SuperAdmin only)
  - Rationale: Align with spec section 9
  - Impacted modules: api/index.php, rbac.php, all handlers

- YYYY-MM-DD — (TBD)
  - Decision:
  - Rationale:
  - Impacted modules/states:

---

## 17) OPEN_QUESTIONS (keep updating)
- ~~Q1: Variance thresholds?~~ → Resolved: use config defaults; per-item when item-level
- ~~Q2: Customer sees photos or not?~~ → Resolved: CUSTOMER_PHOTO_VISIBILITY config
- ~~Q3: WhatsApp notifications in Phase 1 or later?~~ → Resolved: Phase 2, optional
- ~~Q4: Are partial shipments allowed internally (draft-only) before finalization?~~ → Resolved: yes, internal only; no partial push to tracking

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
