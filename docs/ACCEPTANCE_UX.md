# CLMS UX Acceptance Checklist

Use this checklist to verify production-ready UX and structure before release.

## 1. Role-based structure + RBAC

| Check | How to verify |
|-------|---------------|
| Warehouse area `/warehouse/` | Log in as WarehouseStaff → redirect to `/warehouse/`, sidebar shows Receiving |
| Buyers area `/buyers/` | Log in as ChinaAdmin → redirect to `/buyers/`, sidebar shows Orders, Master Data |
| Admin area `/admin/` | Log in as LebanonAdmin → redirect to `/admin/`, sidebar shows Consolidation, Notifications |
| SuperAdmin area `/superadmin/` | Log in as SuperAdmin → redirect to `/superadmin/`, sidebar shows Users, Config, Diagnostics |
| RBAC denied | Log in as WarehouseStaff, visit `/superadmin/users.php` → 403 page (nice UI), not raw die |
| RBAC denied | Log in as WarehouseStaff, visit `/admin/consolidation.php` → 403 if role not allowed |

## 2. Receiving operational

| Check | How to verify |
|-------|---------------|
| Queue page | `/warehouse/receiving/` → Pending Queue tab shows Approved/InTransit orders |
| Filters | Use order ID, date range filters → results update |
| Receive button | Click "Receive" on queue row → redirect to `/warehouse/receiving/receive.php?order_id=X` |
| Receive flow | On receive page: order overview, actual totals, per-item (if enabled), photos, variance |
| History tab | Receiving history tab shows list of receipts |

| Check | How to verify |
|-------|---------------|
| Receipt detail | After completing receive → redirect to `/warehouse/receiving/receipt.php?id=X` |
| Receipt detail | From history, click "View" → receipt shows order, customer, totals, items, photos |

## 3. Upload fixes

| Check | How to verify |
|-------|---------------|
| Config endpoint | `GET /api/v1/config/upload` → returns `max_upload_mb`, `allowed_types` |
| Oversize file | Upload 12MB image → toast shows "File too large (12.4 MB). Max allowed 8 MB." with ref ID |
| Auto-compress | Upload 10MB image → client compresses, upload succeeds |
| Progress | During upload, button shows "Uploading 1/1 (45%)…" |
| Submit disabled | While uploads in progress, "Record Receipt" disabled |

## 4. UI polish

| Check | How to verify |
|-------|---------------|
| Sidebar + topbar | All area pages show sidebar (left) + topbar (right) |
| Breadcrumbs | Receiving page shows Warehouse > Receiving |
| Status badges | Order list shows status as colored badge |
| Empty states | Consolidation empty: "No containers yet" + hint to add |
| Empty states | Receiving queue empty: "No pending orders" |
| Loading skeletons | Receiving page shows placeholder while loading |
| Toasts | Success/error toasts appear on actions |
| Error toast | API error shows message + request_id (ref: abc123) |

## 5. Orders modal

| Check | How to verify |
|-------|---------------|
| Sticky header | Items table header stays visible when scrolling |
| Totals row | Footer shows totals (CBM, weight, $) |
| Column widths | Description, CBM, weight columns are readable |

## 6. Consolidation

| Check | How to verify |
|-------|---------------|
| Error handling | Trigger error (e.g. invalid container) → toast shows useful message + ref ID |
| Empty state | "No containers yet" with hint |

## 7. Notifications

| Check | How to verify |
|-------|---------------|
| Admin layout | `/admin/notifications.php` uses sidebar layout |
| Preferences | `/admin/notification_preferences.php` uses sidebar layout |
| Dropdown | Admin sidebar Notifications dropdown: View all, Preferences |

## Screenshots to capture

1. Warehouse receiving queue (with data)
2. Receiving receive flow (sections A–E visible)
3. Receipt detail page
4. Upload progress toast
5. Order modal with sticky header and totals
6. 403 page (access denied)
7. Consolidation empty state
