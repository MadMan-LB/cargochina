# CLMS Staged Implementation Plan

Tablet-first (iPad) operational system for China operations—replacing Excel chaos with structured capture, validation, and controlled handoff to Lebanon tracking.

---

## Gap Analysis (Current vs Spec)

| Spec Requirement | Current State | Gap |
|------------------|---------------|-----|
| **Supplier: Store ID** | `code` only | Add `store_id` (official China store identifier) |
| **Supplier: Phones** | ✓ `phone` | Done |
| **Supplier: Payment history** | None | New `supplier_payments` table |
| **Supplier: Hunting process** | None | New `supplier_interactions` (visits, quotes, notes) |
| **Item: ItemNo** | None | Add `item_no` to order_items |
| **Item: Shipping code** | None | Add `shipping_code` (sourcing agent code) |
| **Item: Unit price** | None | Add `unit_price` |
| **Item: Cartons / Qty per carton** | `quantity` + `unit` | Add `cartons`, `qty_per_carton` for Excel parity |
| **Item: Total amount** | None | Add `total_amount` (computed) |
| **Item: Photos per item** | Order-level only | Add `image_paths` JSON to order_items |
| **Evidence photos config** | Hardcoded | Add `MIN_PHOTOS_PER_ITEM` to system_config |
| **Computed totals live** | Partial | Enhance UI with live calc |
| **Customer: Destination** | `addresses` JSON | Document; optional enhancement |

---

## Phase 1: Supplier/store IDs + Payments + Item Capture

**Goal:** Align item capture with Excel structure; add supplier store ID and payment ledger.

### 1.1 Database (Migrations 009–010)

**009_item_capture.sql**
- `order_items`: add `item_no` VARCHAR(100), `shipping_code` VARCHAR(100), `cartons` INT, `qty_per_carton` DECIMAL, `unit_price` DECIMAL, `total_amount` DECIMAL, `notes` TEXT, `image_paths` JSON
- Backfill: `quantity` remains; `cartons`/`qty_per_carton` nullable for existing rows

**010_supplier_store_payments.sql**
- `suppliers`: add `store_id` VARCHAR(100) NULL (official China store identifier)
- `supplier_payments`: id, supplier_id, order_id NULL, amount, currency, type (partial/full), notes, created_at
- `supplier_interactions`: id, supplier_id, type (visit/quote/note), content JSON, created_by, created_at
- `system_config`: insert `MIN_PHOTOS_PER_ITEM` = 1

### 1.2 Backend API

- **Suppliers:** Read/write `store_id`; validate uniqueness if needed
- **Orders:** Accept new item fields; validate `MIN_PHOTOS_PER_ITEM` on submit
- **New:** `GET/POST /suppliers/{id}/payments`, `GET/POST /suppliers/{id}/interactions`

### 1.3 Frontend

- **Orders item form:** Add ItemNo, Shipping code, Cartons, Qty/ctn, Unit price, Notes, Photos (multi-upload)
- **Live computed:** Total Qty, Total Amount, Total CBM, Total GW
- **Suppliers:** Store ID field; Payments & Interactions tabs/sections

---

## Phase 2: Warehouse Verification + Notifications ✓

**Goal:** Harden receiving workflow; ensure notifications at key points.

### 2.1 Implemented

- NotificationService: notifyAdmins for order_submitted, order_approved, order_received, shipment_finalized
- NOTIFICATION_CHANNELS in system_config (dashboard, email, whatsapp) — Admin configurable
- Container presets: 20HQ, 25HQ, 40HQ, 45HQ in Add Container modal
- Consolidation: running totals (CBM/weight) for Ready orders; draft totals in modal

### 2.2 Deferred

- Item-level receiving (optional)
- Email/WhatsApp actual delivery (dashboard only for now)

---

## Phase 3: Consolidation + Integration Hardening ✓

**Goal:** Auto-planning suggestions; idempotent push to tracking.

### 3.1 Implemented

- Container presets (20HQ/25HQ/40HQ/45HQ)
- Running totals for Ready orders
- **Tracking handoff:** configurable API, idempotent push, retries, dry-run
- tracking_push_log table; Admin → Tracking Push Log; Retry Push
- Decision B: finalize always succeeds; push can fail and be retried

---

## Implementation Order

1. **Now:** Phase 1 migrations, API, UI
2. **Next:** Phase 2 notification hardening
3. **Later:** Phase 3 consolidation UI + tracking integration
