# Cron Jobs

## Stale Order Alerts

Sends dashboard notifications to admins when orders are stuck beyond the configured threshold.

**Script:** `backend/cron/stale_order_alerts.php`

**Schedule:** Run daily (e.g. 8:00 AM)

**Linux/Mac cron example:**
```bash
0 8 * * * cd /path/to/cargochina && php backend/cron/stale_order_alerts.php
```

**Windows Task Scheduler:** Create a daily task that runs:
```
php C:\xampp\htdocs\cargochina\backend\cron\stale_order_alerts.php
```

**Config:** `STALE_ORDER_THRESHOLD_DAYS` in Admin → Configuration (default: 3)
