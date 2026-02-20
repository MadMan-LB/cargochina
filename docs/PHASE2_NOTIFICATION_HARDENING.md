# Phase 2: Notification Hardening — PR-Ready Design & Implementation Plan

**Version:** 1.0  
**Date:** 2025-02-19  
**Status:** Design — Ready for Implementation

---

## Executive Summary

This document provides a PR-ready design for Phase 2 notification hardening. It covers:

1. **Item-level receiving** — Per-item actual CBM/weight/cartons, condition, photo evidence, variance detection
2. **Configurable email and WhatsApp notifications** — For key events, respecting RBAC and user preferences
3. **Resolution of open questions** — Variance thresholds, photo visibility, WhatsApp availability, partial shipments

---

## 1. Architect — Data Flow, Invariants, Boundaries, Risks

### 1.1 Data Flow Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ ORDER RECEIVING (current: order-level)                                       │
│ warehouse_receipts (order_id, actual_cbm, actual_weight, actual_cartons)     │
│ warehouse_receipt_photos (receipt_id, file_path)                             │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ ITEM-LEVEL RECEIVING (new)                                                   │
│ warehouse_receipt_items (receipt_id, order_item_id, actual_cbm, actual_weight│
│   actual_cartons, condition, variance_detected)                              │
│ warehouse_receipt_item_photos (receipt_item_id, file_path)                   │
│                                                                              │
│ Invariant: Sum(warehouse_receipt_items.actual_*) = warehouse_receipts.*     │
│            OR receipt stores order-level totals; items are per-line detail   │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│ NOTIFICATIONS                                                                │
│ notifications (user_id, type, title, body, channel, delivered_at)            │
│ user_notification_preferences (user_id, channel, event_type, enabled)        │
│ notification_delivery_log (notification_id, channel, status, external_id)    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Invariants

| Invariant | Rule | Enforcement |
|-----------|------|-------------|
| **Receipt totals** | Order-level `actual_cbm` = sum of item-level `actual_cbm` (or allow override with flag) | Backend validation on receive |
| **Variance detection** | Item variance if `\|actual - declared\| / declared >= threshold_pct` OR `\|actual - declared\| >= threshold_abs` | Config-driven; computed per item and aggregated |
| **Photo evidence** | If any item has variance or condition ≠ good → at least one photo per affected item (or order-level fallback) | Backend validation; config `PHOTO_EVIDENCE_PER_ITEM` |
| **Notification delivery** | User must have `enabled=1` for channel+event to receive | NotificationService checks preferences before send |

### 1.3 Boundaries

- **Item-level receiving is additive** — Existing order-level receiving remains; item-level is an optional detail layer. Receipt can be submitted with order-level only (backward compatible) or with item-level breakdown.
- **Email/WhatsApp are best-effort** — Failures are logged; dashboard notification always created. No retry queue in Phase 2 (deferred to Phase 2.5 if needed).
- **Customer-facing** — Confirmation page shows actuals; photo visibility controlled by `CUSTOMER_PHOTO_VISIBILITY`.

### 1.4 Naming Conventions

| Concept | Table/Field | Rationale |
|---------|-------------|-----------|
| Receipt line item | `warehouse_receipt_items` | Mirrors `order_items`; clear parent-child |
| Item photo | `warehouse_receipt_item_photos` | Same pattern as `warehouse_receipt_photos` |
| User prefs | `user_notification_preferences` | Explicit; avoids JSON in users table |
| Delivery log | `notification_delivery_log` | Audit trail for email/WhatsApp |

### 1.5 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Item-level migration breaks existing receipts | High | Migration adds new tables only; existing receipts unchanged; API accepts both modes |
| Email/WhatsApp credentials leak | High | Env vars only; never log tokens; use dedicated config keys |
| Variance threshold too strict/loose | Medium | Configurable; SuperAdmin can tune; document defaults |
| WhatsApp API rate limits | Medium | Log failures; optional circuit breaker; document provider requirements |
| Partial shipment confusion | Low | Document: internal drafts only; tracking receives full shipments |
| Message duplication | Medium | payload_hash in delivery_log; idempotency checks |
| Delivery failures | Medium | Log to notification_delivery_log; structured logs; no silent failures |
| PII in logs | Medium | Never log tokens; log only order_id, receipt_id, notification_id |

---

## 2. Backend — Migrations, Endpoints, Validation, Logging

### 2.1 Database Migrations

#### Migration 015: Item-level receiving

```sql
-- warehouse_receipt_items: per-item actuals
CREATE TABLE warehouse_receipt_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  order_item_id INT UNSIGNED NOT NULL,
  actual_cartons INT UNSIGNED NULL,
  actual_cbm DECIMAL(10,4) NULL,
  actual_weight DECIMAL(10,4) NULL,
  receipt_condition VARCHAR(20) NOT NULL DEFAULT 'good',
  variance_detected TINYINT(1) NOT NULL DEFAULT 0,
  notes TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (receipt_id) REFERENCES warehouse_receipts(id) ON DELETE CASCADE,
  FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
  CONSTRAINT chk_receipt_item_condition CHECK (receipt_condition IN ('good','damaged','partial'))
);

CREATE TABLE warehouse_receipt_item_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_item_id INT UNSIGNED NOT NULL,
  file_path VARCHAR(500) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (receipt_item_id) REFERENCES warehouse_receipt_items(id) ON DELETE CASCADE
);

CREATE INDEX idx_receipt_items_receipt ON warehouse_receipt_items(receipt_id);
```

**Rollback:** `DROP TABLE warehouse_receipt_item_photos, warehouse_receipt_items;`

#### Migration 016: Notification preferences & delivery

```sql
-- user_notification_preferences
CREATE TABLE user_notification_preferences (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  channel VARCHAR(20) NOT NULL,
  event_type VARCHAR(50) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_user_channel_event (user_id, channel, event_type),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_channel CHECK (channel IN ('dashboard','email','whatsapp')),
  CONSTRAINT chk_event CHECK (event_type IN ('order_submitted','order_approved','order_received','variance_confirmation','shipment_finalized'))
);

-- notification_delivery_log (for email/WhatsApp audit)
ALTER TABLE notifications ADD COLUMN channel VARCHAR(20) NOT NULL DEFAULT 'dashboard' AFTER type;
ALTER TABLE notifications ADD COLUMN delivery_status VARCHAR(20) NULL AFTER read_at;
ALTER TABLE notifications ADD COLUMN external_id VARCHAR(255) NULL;

CREATE TABLE notification_delivery_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  notification_id INT UNSIGNED NOT NULL,
  channel VARCHAR(20) NOT NULL,
  status VARCHAR(20) NOT NULL,
  external_id VARCHAR(255) NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  INDEX idx_ndl_notification (notification_id)
);

-- system_config: email/WhatsApp
INSERT IGNORE INTO system_config (key_name, key_value) VALUES
('EMAIL_FROM_ADDRESS', 'noreply@example.com'),
('EMAIL_FROM_NAME', 'CLMS'),
('WHATSAPP_API_URL', ''),
('WHATSAPP_API_TOKEN', '');
```

**Rollback:** Drop new tables; revert notifications columns.

#### Migration 017: Config keys for item-level & photo

```sql
INSERT IGNORE INTO system_config (key_name, key_value) VALUES
('ITEM_LEVEL_RECEIVING_ENABLED', '1'),
('PHOTO_EVIDENCE_PER_ITEM', '0');
```

- `ITEM_LEVEL_RECEIVING_ENABLED`: 1 = require item breakdown; 0 = order-level only (backward compat)
- `PHOTO_EVIDENCE_PER_ITEM`: 1 = require photo per item when variance; 0 = order-level photos suffice

### 2.2 API Endpoints

#### Modified: `POST /orders/{id}/receive`

**Request (extended):**

```json
{
  "actual_cartons": 10,
  "actual_cbm": 5.2,
  "actual_weight": 120,
  "condition": "good",
  "notes": null,
  "photo_paths": ["uploads/photo1.jpg"],
  "items": [
    {
      "order_item_id": 1,
      "actual_cartons": 4,
      "actual_cbm": 2.1,
      "actual_weight": 48,
      "condition": "good",
      "photo_paths": ["uploads/item1.jpg"]
    },
    {
      "order_item_id": 2,
      "actual_cartons": 6,
      "actual_cbm": 3.1,
      "actual_weight": 72,
      "condition": "good",
      "photo_paths": []
    }
  ]
}
```

**Validation:**
- If `ITEM_LEVEL_RECEIVING_ENABLED=1` and `items` provided: validate sum(items.actual_cbm) ≈ actual_cbm (tolerance 0.01); sum(items.actual_weight) ≈ actual_weight; sum(items.actual_cartons) = actual_cartons
- If any item has variance or condition ≠ good and `PHOTO_EVIDENCE_PER_ITEM=1`: require at least one photo in that item's `photo_paths`
- Otherwise: existing order-level photo requirement applies

**Response:** Unchanged `{ data: { status, receipt_id, variance_detected } }`

#### New: `GET /users/me/notification-preferences`

**Response:**
```json
{
  "data": [
    { "channel": "dashboard", "event_type": "order_received", "enabled": true },
    { "channel": "email", "event_type": "order_received", "enabled": true }
  ]
}
```

**Auth:** Current user only.

#### New: `PUT /users/me/notification-preferences`

**Request:**
```json
{
  "preferences": [
    { "channel": "email", "event_type": "order_received", "enabled": true }
  ]
}
```

**Auth:** Current user only.

#### New: `GET /config` (extended)

Expose `notification_channels` (from NOTIFICATION_CHANNELS), `email_from_address`, `whatsapp_available` (non-empty WHATSAPP_API_URL). Mask tokens.

### 2.3 Auth & RBAC

| Endpoint | Roles | Notes |
|----------|-------|-------|
| `POST /orders/{id}/receive` | WarehouseStaff, SuperAdmin | Unchanged |
| `GET/PUT /users/me/notification-preferences` | All authenticated | Self-service only |
| `GET /config` | SuperAdmin (sensitive keys) | Existing |

### 2.4 Structured Logging

```php
// On receive (item-level)
error_log(json_encode([
  'event' => 'order_received',
  'order_id' => $id,
  'receipt_id' => $receiptId,
  'item_level' => !empty($items),
  'variance_detected' => $hasVariance,
  'user_id' => $userId,
]));
// On notification delivery
error_log(json_encode([
  'event' => 'notification_delivery',
  'notification_id' => $nid,
  'channel' => $channel,
  'status' => $status,
  'user_id' => $userId,
]));
```

Log to `logs/clms.log` (new) or append to existing `logs/php_errors.log` with structured format.

---

## 3. Frontend — UI Changes, Responsive, Accessibility

### 3.1 Item-Level Receiving UI

**Location:** `receiving.php` / `receiving.js`

**Changes:**
- When order selected, load order items. Show expandable/collapsible section "Record per-item actuals (optional)".
- Per item: inputs for Actual Cartons, Actual CBM, Actual Weight, Condition (good/damaged/partial), Add Photo button.
- Live validation: if item-level filled, sum must match order-level totals (or auto-fill order-level from sum).
- Variance alert per item: when `|actual - declared|` exceeds threshold, show inline warning + require photo for that item if `PHOTO_EVIDENCE_PER_ITEM=1`.
- Submit: send `items` array if any item has data; otherwise order-level only.

**Responsive:** Stack item rows on mobile; table on desktop. Touch-friendly Add Photo.

### 3.2 Notification Preferences UI

**Location:** New `notification_preferences.php` or section in user profile/settings.

**UI:**
- Table/matrix: Rows = event types (Order submitted, Order approved, Order received, Variance confirmation, Shipment finalized); Columns = Dashboard, Email, WhatsApp.
- Checkboxes per cell. Dashboard always enabled (read-only). Email/WhatsApp toggles per event.
- Save button → `PUT /users/me/notification-preferences`.
- Link from navbar user dropdown or Notifications page.

### 3.3 Confirmation Page (Customer-Facing)

**Location:** `confirm_order.php` (or similar) — may exist or be new.

**Changes:**
- Show actual CBM, weight, cartons per item (from `warehouse_receipt_items`).
- If `CUSTOMER_PHOTO_VISIBILITY=customer-visible`: show thumbnails of receipt photos (order-level and optionally item-level).
- If `internal-only`: no photos; text only.
- Confirm button → `POST /orders/{id}/confirm`.

### 3.4 Accessibility

- All new form controls: proper `<label>`, `aria-describedby` for validation messages.
- Variance alerts: `role="alert"`, `aria-live="polite"`.
- Loading states: `aria-busy="true"`, disable buttons.
- Keyboard: Tab order logical; Enter submits forms.

---

## 4. QA — Tests, Edge Cases, Rollback, Monitoring

### 4.1 Automated Tests

| Test File | Cases |
|-----------|-------|
| `tests/item_level_receiving_test.php` | Create receipt with items; validate sum; variance detection per item; photo required per item when config on |
| `tests/notification_preferences_test.php` | GET/PUT preferences; default values; invalid channel/event rejected |
| `tests/notification_delivery_test.php` | NotificationService creates dashboard; email/WhatsApp mocked; delivery log written |
| `tests/receiving_integration_test.php` | Full flow: order → receive with items → variance → confirm |

### 4.2 Manual Test Matrix

| Scenario | Steps | Expected |
|----------|-------|----------|
| Item-level receive, no variance | Fill per-item actuals; submit | Receipt saved; ReadyForConsolidation |
| Item-level receive, variance | One item over threshold; add photo | AwaitingCustomerConfirmation; notification |
| Order-level only (backward compat) | Submit without items when ITEM_LEVEL_RECEIVING_ENABLED=0 | Works as today |
| Email preference off | Disable email for order_received; trigger event | No email; dashboard only |
| WhatsApp unavailable | WHATSAPP_API_URL empty | No WhatsApp attempt; logged |
| Photo visibility customer | CUSTOMER_PHOTO_VISIBILITY=customer-visible; confirm page | Photos visible |
| Photo visibility internal | internal-only | No photos on confirm page |

### 4.3 Edge Cases

- **Empty items array:** Treated as order-level only.
- **Partial items:** If only some items have data, backend can either reject or accept (recommend: accept; treat missing as null/not recorded).
- **Variance on one item, good on others:** Order-level variance = true if any item varies; status AwaitingCustomerConfirmation.
- **Email bounce:** Log in delivery_log; no retry in Phase 2.
- **WhatsApp rate limit:** Log; optionally disable channel for user temporarily (Phase 2.5).

### 4.4 Rollback Procedures

1. **Migrations:** Run rollback SQL in reverse order (017 → 016 → 015).
2. **Code:** Deploy previous version; API ignores `items` if present.
3. **Config:** Set `ITEM_LEVEL_RECEIVING_ENABLED=0` to disable item-level without code rollback.

### 4.5 Monitoring Hooks

- **Metric:** `notification_delivery_failures` — count by channel from `notification_delivery_log` where status=failed.
- **Alert:** If > N failures in 1h: notify SuperAdmin.
- **Log:** Structured JSON to `logs/clms.log` for all notification attempts.

---

## 5. Open Questions — Resolved

### Q1: Variance thresholds?

**Resolution:** Use existing config `VARIANCE_THRESHOLD_PERCENT` (default 10), `VARIANCE_THRESHOLD_ABS_CBM` (default 0.1). Apply per item when item-level present; else order-level. Document in DECISION_LOG.

### Q2: Customer sees photos or not?

**Resolution:** `CUSTOMER_PHOTO_VISIBILITY` (internal-only | customer-visible). Default internal-only. Admin configurable. Document in DECISION_LOG.

### Q3: WhatsApp notifications in Phase 1 or later?

**Resolution:** Phase 2. Implement as optional channel. If `WHATSAPP_API_URL` empty, skip WhatsApp. Document provider requirements (e.g. Twilio, official Business API) in README.

### Q4: Partial shipments allowed internally?

**Resolution:** Yes, internally. Shipment drafts can contain subset of orders; consolidation is draft-only until finalize. Tracking receives only finalized, full shipments. No partial push to tracking. Document in DECISION_LOG.

---

## 6. Implementation Order

1. **Migration 015** — Item-level tables
2. **Migration 016** — Notification preferences & delivery
3. **Migration 017** — Config keys
4. **Backend:** Extend receive handler; NotificationService email/WhatsApp; preferences API
5. **Frontend:** Item-level receiving form; notification preferences page
6. **Tests:** Unit + integration
7. **Docs:** API.md, README DB_CHANGELOG, DECISION_LOG

---

## 7. DB_CHANGELOG Entry (for CLMS_README)

```
- 2025-02-19 — Migrations 015–017 (Phase 2 Notification Hardening)
  - Change: warehouse_receipt_items, warehouse_receipt_item_photos; user_notification_preferences; notification_delivery_log; notifications + channel, delivery_status, external_id; system_config EMAIL_*, WHATSAPP_*, ITEM_LEVEL_RECEIVING_ENABLED, PHOTO_EVIDENCE_PER_ITEM
  - Reason: Item-level receiving; configurable email/WhatsApp; user preferences
  - Rollback: DROP TABLE notification_delivery_log, user_notification_preferences, warehouse_receipt_item_photos, warehouse_receipt_items; ALTER TABLE notifications DROP COLUMN channel, delivery_status, external_id; DELETE FROM system_config WHERE key_name IN ('EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','WHATSAPP_API_URL','WHATSAPP_API_TOKEN','ITEM_LEVEL_RECEIVING_ENABLED','PHOTO_EVIDENCE_PER_ITEM');
```

---

## 8. DECISION_LOG Entries (for CLMS_README)

```
- 2025-02-19 — Variance thresholds (Q1)
  - Decision: Use VARIANCE_THRESHOLD_PERCENT=10, VARIANCE_THRESHOLD_ABS_CBM=0.1; apply per item when item-level receiving used
  - Rationale: Existing defaults; per-item gives finer granularity
  - Impacted: receiving, config

- 2025-02-19 — Photo visibility (Q2)
  - Decision: CUSTOMER_PHOTO_VISIBILITY = internal-only | customer-visible; default internal-only
  - Rationale: Privacy/liability; configurable per deployment
  - Impacted: confirmation UI, config

- 2025-02-19 — WhatsApp in Phase 2 (Q3)
  - Decision: Implement WhatsApp as optional channel; require WHATSAPP_API_URL + token
  - Rationale: Staged plan lists as deferred; Phase 2 hardening includes it
  - Impacted: NotificationService, config

- 2025-02-19 — Partial shipments (Q4)
  - Decision: Internal drafts may be partial; tracking receives only finalized full shipments
  - Rationale: CLMS spec "Create partial shipments or drafts in tracking" = must NOT do; internal drafts are pre-finalize
  - Impacted: consolidation, tracking push
```

---

## Release Checklist (Production Hardening)

Before deploying Phase 2 + Production Hardening:

1. **Migrations:** Run `run-migrations.bat` or `php backend/migrations/run.php` (015–018).
2. **Config:** Set EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME if using email. For WhatsApp: choose generic (WHATSAPP_API_URL + WHATSAPP_API_TOKEN) or twilio (WHATSAPP_TWILIO_ACCOUNT_SID, WHATSAPP_TWILIO_AUTH_TOKEN, WHATSAPP_TWILIO_FROM, WHATSAPP_TWILIO_TO).
3. **Secrets:** Never commit tokens; use env vars or Admin → Configuration (values masked in UI).
4. **Tests:** Run `run-tests.bat`; all tests must pass.
5. **Notification preferences:** Defaults are seeded lazily on first GET when empty (Option B).
6. **Item-level receiving:** When ITEM_LEVEL_RECEIVING_ENABLED=0, per-item UI is hidden on receiving page.
7. **Rollback:** See CLMS_README DB_CHANGELOG for 015–018 rollback SQL.

---

## Appendix A: Notification Event Types

| Event | Recipients | Channels |
|-------|------------|----------|
| order_submitted | Admins | dashboard, email?, whatsapp? |
| order_approved | Admins | dashboard, email?, whatsapp? |
| order_received | Admins | dashboard, email?, whatsapp? |
| variance_confirmation | Admins + customer (if applicable) | dashboard, email?, whatsapp? |
| shipment_finalized | Admins | dashboard, email?, whatsapp? |

---

## Appendix B: Email Template Placeholders

- `{order_id}`, `{customer_name}`, `{supplier_name}`, `{event_type}`, `{actual_cbm}`, `{actual_weight}`, `{variance_message}`
