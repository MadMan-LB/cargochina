# CLMS Release Checklist

**Use this checklist for every production deploy.** Phase 2 + Production Hardening (migrations 015–018).

---

## Pre-deploy

- [ ] **Backup DB** — Full dump before any migration
  ```bash
  mysqldump -u user -p clms_db > backup_pre_$(date +%Y%m%d_%H%M).sql
  ```
- [ ] **Confirm env vars** — No secrets in git; verify `.env` or server env has:
  - `EMAIL_FROM_ADDRESS`, `EMAIL_FROM_NAME` (if using email)
  - `WHATSAPP_*` (if using WhatsApp: generic or Twilio keys)
  - `TRACKING_API_*` (if pushing to tracking)
- [ ] **Confirm config keys exist** — After migration 018, `system_config` should have:
  - `WHATSAPP_PROVIDER`, `NOTIFICATION_MAX_ATTEMPTS`, `NOTIFICATION_RETRY_SECONDS`
  - Plus Phase 2 keys: `EMAIL_FROM_ADDRESS`, `WHATSAPP_API_URL`, `ITEM_LEVEL_RECEIVING_ENABLED`, etc.

---

## Deploy

1. **Git pull** — `git pull origin main` (or your branch)
2. **Run migrations** — `run-migrations.bat` or `php backend/migrations/run.php`
3. **Warm up** — Hit `/cargochina/` and `/cargochina/api/v1/config/receiving` once
4. **Verify permissions** — `logs/` writable; `backend/uploads/` writable; DB user has INSERT/UPDATE on new tables

---

## Post-deploy verification

| Flow | Steps | Expected |
|------|-------|----------|
| **Order lifecycle** | Create order → Submit → Approve → Receive | Status transitions correct |
| **Variance** | Receive with CBM variance >10% or 0.1 | AwaitingCustomerConfirmation; notification created |
| **Confirm** | Confirm order with variance | Status → Confirmed |
| **Consolidation** | Add orders to draft → Finalize | Push logged (dry-run or real) |
| **Diagnostics** | Admin → Diagnostics | Config Health + Notification Delivery Log (filters, retry for failed) |

---

## Rollback plan

### Code rollback

```bash
git checkout <previous-tag-or-commit>
# Restart PHP/web server if needed
```

### DB rollback (reverse order: 018 → 017 → 016 → 015)

```sql
-- 018
DELETE FROM system_config WHERE key_name IN ('WHATSAPP_PROVIDER','WHATSAPP_TWILIO_ACCOUNT_SID','WHATSAPP_TWILIO_AUTH_TOKEN','WHATSAPP_TWILIO_FROM','WHATSAPP_TWILIO_TO','NOTIFICATION_MAX_ATTEMPTS','NOTIFICATION_RETRY_SECONDS');

-- 017
DROP TABLE IF EXISTS notification_delivery_log;
ALTER TABLE notifications DROP COLUMN IF EXISTS channel;

-- 016
DROP TABLE IF EXISTS user_notification_preferences;
DELETE FROM system_config WHERE key_name IN ('EMAIL_FROM_ADDRESS','EMAIL_FROM_NAME','WHATSAPP_API_URL','WHATSAPP_API_TOKEN');

-- 015
DROP TABLE IF EXISTS warehouse_receipt_item_photos;
DROP TABLE IF EXISTS warehouse_receipt_items;
```

---

## Known limitations

- **Dashboard-only** — If email/WhatsApp not configured, notifications appear only in dashboard
- **WhatsApp generic** — Expects JSON `{to, message}`; provider-specific APIs may differ
- **WhatsApp Twilio** — Requires `WHATSAPP_TWILIO_TO` (single recipient for all admins)
- **Retries** — In-request only; no async job queue; max attempts from config
- **Photo visibility** — `CUSTOMER_PHOTO_VISIBILITY=internal-only` hides photos on confirm page
- **Item-level** — When `ITEM_LEVEL_RECEIVING_ENABLED=0`, per-item UI hidden; order-level only
