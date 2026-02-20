# CLMS Regression Matrix

**Full lifecycle + Phase 2 scenarios.** Run manually or via scripts.

---

## Lifecycle flow

```
Draft → Submitted → Approved → ReceivedAtWarehouse
  → (if variance) AwaitingCustomerConfirmation → Confirmed
  → ReadyForConsolidation → ShipmentDraft → Finalize → Push (dry-run / real)
```

---

## Scenarios

| # | Scenario | Steps | Assertions |
|---|----------|-------|------------|
| 1 | **Order-level receive, no variance** | Create order, submit, approve, receive with actuals matching declared | Status → ReadyForConsolidation; no confirmation required |
| 2 | **Order-level receive, variance** | Receive with CBM variance >10% or 0.1; add photo | Status → AwaitingCustomerConfirmation; variance_confirmation notification |
| 3 | **Item-level receive, variance on one item** | Receive with 2 items; item 1 variance, item 2 normal; add photos | Status → AwaitingCustomerConfirmation; receipt_items; delivery_log entries |
| 4 | **Item-level disabled** | Item-level OFF; receive order-level only | Per-item UI hidden; receive succeeds |
| 5 | **Customer photo visibility** | internal-only: confirm page no photos; customer-visible: photos shown | Confirm page behavior matches config |
| 6 | **Preferences toggled off** | Disable email for order_received; trigger receive | No email; dashboard only |
| 7 | **Retries** | Simulate WhatsApp failure; retry up to max attempts | delivery_log entries with attempts > 1; last_error set |
| 8 | **Idempotency** | Same notification+channel twice; payload_hash already sent | No duplicate send; delivery_log has one sent |
| 9 | **Finalize + push dry-run** | Create draft, add orders, finalize with TRACKING_PUSH_DRY_RUN=1 | tracking_push_log; no real push |
| 10 | **Finalize + push real** | Same with dry-run=0; valid tracking URL | Push to tracking; status updated |

---

## Integration test script

**Run:** `php tests/regression_receive_variance_test.php` (or via `run-tests.bat`)

**`tests/regression_receive_variance_test.php`** — Deterministic test:

- Inserts order + 2 items (one variance, one normal)
- Receives with item-level data
- Asserts:
  - Order status → AwaitingCustomerConfirmation
  - warehouse_receipt_items present
  - notification_delivery_log has correct statuses and attempt counts
  - payload_hash prevents duplicates when rerun
- Cleans up after

---

## Manual test checklist

- [ ] Order lifecycle (1–2)
- [ ] Item-level variance (3)
- [ ] Item-level disabled (4)
- [ ] Photo visibility (5)
- [ ] Preferences toggled (6)
- [ ] Diagnostics page loads
- [ ] Config health reflects settings
