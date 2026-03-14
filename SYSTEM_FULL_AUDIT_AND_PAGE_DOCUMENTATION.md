# System Full Audit and Page Documentation

**CLMS — China Logistics Management System**  
Salameh Cargo's China Operations platform.  
*Document generated for handoff, change planning, and feature development.*

---

## 1. Executive Overview

CLMS replaces Excel-based China upstream operations with a structured, validated pipeline. The system manages:

- **Master Data:** Customers, suppliers, products (with translations, images, HS codes)
- **Orders:** Creation, item entry, validation, attachments, approval workflow
- **Warehouse Receiving:** Actual CBM/weight/cartons, condition, photo evidence, variance detection
- **Customer Confirmation:** Token-based variance acceptance (public link)
- **Consolidation:** Shipment drafts, container assignment, capacity tracking
- **Tracking Integration:** Push finalized shipments to external tracking system
- **Notifications:** Dashboard, email, WhatsApp (configurable channels)

**Tech Stack:** PHP 8.0+, MySQL 8, Bootstrap 5, vanilla JS, REST API (`/api/v1/`).

**Dual Navigation:** The system has **legacy root-level pages** (e.g. `/orders.php`) and **area-based routes** (e.g. `/warehouse/`, `/buyers/`, `/admin/`, `/superadmin/`). The sidebar in `layout.php` points to root paths. Area dashboards link to area paths where implemented; many area sub-pages redirect to root equivalents.

---

## 2. Project Structure Overview

```
cargochina/
├── backend/
│   ├── api/
│   │   ├── index.php          # API router (path-based)
│   │   ├── helpers.php        # jsonResponse, getAuthUserId, hasAnyRole, etc.
│   │   └── handlers/          # One file per resource (orders, customers, etc.)
│   ├── config/
│   │   ├── config.php         # App config loader
│   │   ├── database.php        # PDO connection (getDb)
│   │   └── rbac.php           # Endpoint → required roles
│   ├── migrations/            # SQL migrations (001–032)
│   ├── services/              # OrderStateService, NotificationService, etc.
│   ├── cron/                  # Stale order alerts
│   └── uploads/               # Attachments (gitignored)
├── frontend/
│   ├── css/style.css
│   └── js/                    # Page-specific + shared (app.js, autocomplete.js, etc.)
├── includes/
│   ├── auth_check.php         # Session check; redirect to login if not authenticated
│   ├── page_guard.php         # requireRoleForPage()
│   ├── layout.php             # Main layout + sidebar (root paths)
│   ├── area_layout.php        # Wraps layout.php for area pages
│   ├── area_bootstrap.php     # Auth + area role validation
│   ├── area_footer.php        # Includes footer.php
│   └── footer.php             # Scripts, notification badge
├── warehouse/                 # Warehouse area (WarehouseStaff, SuperAdmin)
├── buyers/                    # Buyers area (ChinaAdmin, ChinaEmployee, SuperAdmin)
├── admin/                     # Admin area (ChinaAdmin, LebanonAdmin, SuperAdmin)
├── superadmin/                # SuperAdmin area
├── docs/                      # API.md, ROUTES.md, etc.
├── tests/                     # PHP tests
├── login.php                  # Public login
├── confirm.php                # Public confirmation (token-based)
├── 403.php                    # Access denied
└── [root pages]               # orders, customers, suppliers, products, receiving, etc.
```

---

## 3. Architecture Summary

| Layer | Technology |
|-------|------------|
| Web Server | Apache (XAMPP) with mod_rewrite |
| Routing | Direct PHP includes; API via `.htaccess` → `backend/api/index.php?path=...` |
| Auth | Session-based (`$_SESSION['user_id']`, `user_roles`) |
| RBAC | `backend/config/rbac.php` + per-endpoint checks in `api/index.php` |
| DB | MySQL 8, PDO |
| Frontend | Bootstrap 5, vanilla JS, no framework |
| API | REST JSON, `Content-Type: application/json` |

**API Base:** `/cargochina/api/v1/` (or `/api/v1/` if at document root). Path format: `resource`, `resource/id`, `resource/id/action`.

---

## 4. Authentication and Authorization Model

### 4.1 Authentication

- **Entry:** `login.php` — POST to self with `email`, `password`
- **Storage:** `users` table; `password_hash` via `password_verify()`
- **Session:** `$_SESSION['user_id']`, `$_SESSION['user_name']`, `$_SESSION['user_roles']` (array of role codes)
- **Logout:** `login.php?logout=1` — clears session, redirects to login
- **Post-login redirect:** Role-based:
  - SuperAdmin → `/superadmin/`
  - WarehouseStaff (only) → `/warehouse/`
  - ChinaAdmin/ChinaEmployee → `/buyers/`
  - LebanonAdmin → `/admin/`
  - Default → `/warehouse/`

### 4.2 Authorization Layers

1. **auth_check.php:** Ensures `user_id` in session; redirects to `login.php` if not. Used by `layout.php` (all layout pages).
2. **page_guard.php:** `requireRoleForPage($allowedRoles)` — includes `403.php` and exits if user lacks any allowed role.
3. **area_bootstrap.php:** Validates `$area` (warehouse|buyers|admin|superadmin) and checks `$areaRoles[$area]` against `$_SESSION['user_roles']`.
4. **API RBAC:** In `backend/api/index.php` — resource/action-specific checks (e.g. `orders/approve`, `containers` write, `config`, `users`).

### 4.3 Roles (from `roles` table)

| Code | Description |
|------|-------------|
| SuperAdmin | Full access; config, users, containers CRUD, diagnostics, tracking push |
| ChinaAdmin | Orders, master data, consolidation, confirmations, pipeline |
| ChinaEmployee | Orders, master data (no approve); suppliers, customers, products |
| LebanonAdmin | Consolidation, pipeline, confirmations, tracking push, finalize |
| WarehouseStaff | Receiving, confirmations |
| FieldStaff | Suppliers (data section) |

---

## 5. Global Navigation / Menu Structure

The sidebar is defined in `includes/layout.php`. All links use root paths (`/cargochina/...`). Visibility is role-based:

| Section | Item | Destination | Visible For |
|---------|------|-------------|-------------|
| Main | Dashboard | `/index.php` | All |
| Main | Orders | `/orders.php` | isBuyer |
| Main | Receiving | `/receiving.php` | isWarehouse |
| Main | Confirmations | `/confirmations.php` | isAdmin \|\| isWarehouse |
| Main | Pipeline | `/pipeline.php` | isAdmin |
| Main | Consolidation | `/consolidation.php` | isAdmin |
| Main | Containers | `/containers.php` | isAdmin |
| Main | Assign to Container | `/assign_container.php` | isAdmin |
| Data | Suppliers | `/suppliers.php` | isBuyer \|\| isFieldStaff |
| Data | Customers | `/customers.php` | isBuyer |
| Data | Products | `/products.php` | isBuyer |
| Notifications | Notifications | `/notifications.php` | All |
| Notifications | Preferences | `/notification_preferences.php` | All |
| Administration | Configuration | `/admin_config.php` | isSuperAdmin |
| Administration | Users | `/admin_users.php` | isSuperAdmin |
| Administration | Diagnostics | `/admin_diagnostics.php` | isSuperAdmin |
| Administration | Tracking Push Log | `/admin_tracking_push.php` | isSuperAdmin |
| Administration | Audit Log | `/admin_audit_log.php` | isSuperAdmin |

**Variables:** `$isBuyer`, `$isAdmin`, `$isWarehouse`, `$isSuperAdmin`, `$isFieldStaff` are derived from `$_SESSION['user_roles']` in layout.php.

---

## 6. Page Inventory

### 6.1 Public Pages (No Auth)

| Page | File | URL | Purpose |
|------|------|-----|---------|
| Login | login.php | `/login.php` | Login form; POST to self |
| Logout | login.php | `/login.php?logout=1` | Destroy session |
| Customer Confirmation | confirm.php | `/confirm.php?token=...` | Token-based variance confirmation (customer-facing) |

### 6.2 Error Pages

| Page | File | URL | Purpose |
|------|------|-----|---------|
| Access Denied | 403.php | Included when role check fails | Role-based home link; no sidebar |

### 6.3 Root-Level Pages (Legacy, Auth + Page Guard)

| Page | File | Roles | Purpose |
|------|------|-------|---------|
| Dashboard | index.php | All | Stats, quick nav, stale alerts |
| Orders | orders.php | ChinaAdmin, ChinaEmployee, SuperAdmin | CRUD, submit, approve, filters, export |
| Customers | customers.php | ChinaAdmin, ChinaEmployee, SuperAdmin | CRUD, deposits, balance, import |
| Suppliers | suppliers.php | ChinaAdmin, ChinaEmployee, FieldStaff, SuperAdmin | CRUD, payments, interactions, import |
| Products | products.php | ChinaAdmin, ChinaEmployee, SuperAdmin | CRUD, suggest, import |
| Receiving | receiving.php | WarehouseStaff, SuperAdmin | List/Calendar/Schedule tabs, inline receive form |
| Confirmations | confirmations.php | ChinaAdmin, LebanonAdmin, WarehouseStaff, SuperAdmin | Bulk confirm AwaitingCustomerConfirmation |
| Pipeline | pipeline.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Stage counts, links to orders/receiving/consolidation |
| Consolidation | consolidation.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Containers, shipment drafts, finalize, push |
| Containers | containers.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Container list, status, assign orders |
| Assign to Container | assign_container.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Direct order→container assignment |
| Notifications | notifications.php | All (auth only) | Notification list |
| Notification Preferences | notification_preferences.php | All (auth only) | Channel toggles per event |
| Configuration | admin_config.php | SuperAdmin | System config (variance, email, WhatsApp, tracking, etc.) |
| Users | admin_users.php | SuperAdmin | User CRUD, roles, reset password |
| Diagnostics | admin_diagnostics.php | SuperAdmin | Config health, notification delivery log, retry |
| Tracking Push Log | admin_tracking_push.php | LebanonAdmin, SuperAdmin | Push history, retry |
| Audit Log | admin_audit_log.php | SuperAdmin, ChinaAdmin | Audit entries |

### 6.4 Area-Based Pages

| Area | Page | File | Roles | Purpose |
|------|------|------|-------|---------|
| warehouse | Dashboard | warehouse/index.php | WarehouseStaff, SuperAdmin | Links to receiving |
| warehouse | Receiving | warehouse/receiving/index.php | WarehouseStaff, SuperAdmin | Queue + History tabs |
| warehouse | Receive Order | warehouse/receiving/receive.php?order_id=X | WarehouseStaff, SuperAdmin | Receive form |
| warehouse | Receipt | warehouse/receiving/receipt.php?id=X | WarehouseStaff, SuperAdmin | Receipt detail |
| warehouse | Queue (orphan) | warehouse/receiving/queue.php | — | Placeholder; redirects to receiving.php |
| warehouse | History (orphan) | warehouse/receiving/history.php | — | Placeholder; redirects to receiving.php |
| buyers | Dashboard | buyers/index.php | ChinaAdmin, ChinaEmployee, SuperAdmin | Links to orders, master data |
| buyers | Orders | buyers/orders.php | — | **Redirect** → /orders.php |
| buyers | Customers | buyers/customers.php | — | **Redirect** → /customers.php |
| buyers | Suppliers | buyers/suppliers.php | — | **Redirect** → /suppliers.php |
| buyers | Products | buyers/products.php | — | **Redirect** → /products.php |
| admin | Dashboard | admin/index.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Links to consolidation, notifications |
| admin | Consolidation | admin/consolidation.php | — | **Redirect** → /consolidation.php |
| admin | Notifications | admin/notifications.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Notifications list (area layout) |
| admin | Notification Preferences | admin/notification_preferences.php | ChinaAdmin, LebanonAdmin, SuperAdmin | Preferences (area layout) |
| superadmin | Dashboard | superadmin/index.php | SuperAdmin | Links to users, config, diagnostics, tracking |
| superadmin | Users | superadmin/users.php | — | **Redirect** → /admin_users.php |
| superadmin | Configuration | superadmin/configuration.php | — | **Redirect** → /admin_config.php |
| superadmin | Diagnostics | superadmin/diagnostics.php | — | **Redirect** → /admin_diagnostics.php |
| superadmin | Tracking Push Log | superadmin/tracking-push-log.php | — | **Redirect** → /admin_tracking_push.php |

---

## 7. Detailed Page Documentation

### 7.1 Login (login.php)

| Attribute | Value |
|-----------|-------|
| **File** | `login.php` |
| **URL** | `/cargochina/login.php` |
| **Purpose** | Authenticate user; set session; redirect by role |
| **Access** | Public |
| **UI** | Card with email, password inputs; error alert |
| **Actions** | POST login; GET logout |
| **Backend** | Direct DB: `users` (email, password_hash), `user_roles` + `roles` |
| **Validation** | Email/password required; `is_active=1` |
| **Redirect** | SuperAdmin→superadmin; WarehouseStaff→warehouse; China*→buyers; LebanonAdmin→admin; else warehouse |

---

### 7.2 Dashboard (index.php)

| Attribute | Value |
|-----------|-------|
| **File** | `index.php` |
| **URL** | `/cargochina/index.php` |
| **Purpose** | Central dashboard with stats and quick navigation |
| **Access** | All authenticated |
| **UI** | Stats cards (To receive, Awaiting confirm, Ready to consolidate, Notifications); stale alert banner; My tasks (hidden by default); quick nav cards by role |
| **Actions** | Read stats via API; navigate to orders, receiving, consolidation, etc. |
| **Backend** | `GET /api/v1/dashboard/stats` |
| **DB** | Orders (counts by status), notifications (unread) |
| **Script** | `frontend/js/dashboard.js` |

---

### 7.3 Orders (orders.php)

| Attribute | Value |
|-----------|-------|
| **File** | `orders.php` |
| **URL** | `/cargochina/orders.php` |
| **Purpose** | Create, edit, list, filter, submit, approve orders; assign to shipment draft; export |
| **Access** | ChinaAdmin, ChinaEmployee, SuperAdmin |
| **UI** | Table with filters (status, search); Bulk Submit, Bulk Approve, Export CSV, New Order; modals: orderModal, orderInfoModal, financeModal, assignDraftModal |
| **Actions** | Create, Read, Update (Draft only), Submit, Approve, Confirm, Export, Assign to draft |
| **Backend** | `GET/POST/PUT /orders`; `POST /orders/{id}/submit`, `/approve`, `/confirm`; `GET /orders/{id}/export`; `POST /shipment-drafts`, `/add-orders` |
| **DB** | orders, order_items, order_attachments, order_templates, order_template_items |
| **Validation** | Min 1 photo per item (configurable); required fields per OrderStateService |
| **Script** | `frontend/js/orders.js`, `autocomplete.js` |

---

### 7.4 Customers (customers.php)

| Attribute | Value |
|-----------|-------|
| **File** | `customers.php` |
| **URL** | `/cargochina/customers.php` |
| **Purpose** | CRUD customers; deposits; balance; payment links; import |
| **Access** | ChinaAdmin, ChinaEmployee, SuperAdmin |
| **UI** | Table, filters; modals: customerModal, depositModal, balanceModal, ordersModal, importModal |
| **Actions** | Create, Read, Update, Delete; Add deposit; View balance; View orders; Import CSV |
| **Backend** | `GET/POST/PUT/DELETE /customers`; `GET/POST /customers/{id}/deposits`, `/balance`; `POST /customers/import` |
| **DB** | customers, customer_deposits |
| **Script** | `frontend/js/customers.js`, `autocomplete.js` |

---

### 7.5 Suppliers (suppliers.php)

| Attribute | Value |
|-----------|-------|
| **File** | `suppliers.php` |
| **URL** | `/cargochina/suppliers.php` |
| **Purpose** | CRUD suppliers; payments; interactions; balance; import |
| **Access** | ChinaAdmin, ChinaEmployee, FieldStaff, SuperAdmin |
| **UI** | Table; modals: supplierModal, paymentModal, visitModal, payHistoryModal, importModal |
| **Actions** | Create, Read, Update, Delete; Add payment; Add interaction; View balance; Import |
| **Backend** | `GET/POST/PUT/DELETE /suppliers`; `POST /suppliers/{id}/payments`, `/interactions`; `GET /suppliers/{id}/balance`; `POST /suppliers/import` |
| **DB** | suppliers, supplier_payments, supplier_interactions |
| **Script** | `frontend/js/suppliers.js`, `autocomplete.js` |

---

### 7.6 Products (products.php)

| Attribute | Value |
|-----------|-------|
| **File** | `products.php` |
| **URL** | `/cargochina/products.php` |
| **Purpose** | CRUD products; suggest matches; translate; import |
| **Access** | ChinaAdmin, ChinaEmployee, SuperAdmin |
| **UI** | Table; modal: productModal, importModal |
| **Actions** | Create, Read, Update, Delete; Suggest; Translate; Import |
| **Backend** | `GET/POST/PUT/DELETE /products`; `GET /products/suggest`; `POST /translate`; `POST /products/import` |
| **DB** | products, product_description_entries, translations |
| **Script** | `frontend/js/products.js`, `autocomplete.js` |

---

### 7.7 Receiving (receiving.php) — Root

| Attribute | Value |
|-----------|-------|
| **File** | `receiving.php` |
| **URL** | `/cargochina/receiving.php` |
| **Purpose** | Warehouse receiving: list/calendar/schedule views; inline receive form |
| **Access** | WarehouseStaff, SuperAdmin |
| **UI** | Filters (supplier, customer, date, shipping code); Tabs: List, Calendar, Schedule; Receive Order card with order search + form (actuals, condition, per-item optional, photos) |
| **Actions** | Filter; Receive (record receipt); Export CSV |
| **Backend** | `GET /receiving/queue`, `/search`; `GET /orders/{id}`; `POST /orders/{id}/receive`; `GET /config/receiving` |
| **DB** | orders, warehouse_receipts, warehouse_receipt_photos, warehouse_receipt_items, warehouse_receipt_item_photos |
| **Validation** | Photo required when variance or damage; item-level optional (config) |
| **Script** | `frontend/js/receiving.js`, `autocomplete.js`, `photo_uploader.js` |

---

### 7.8 Warehouse Receiving (warehouse/receiving/index.php)

| Attribute | Value |
|-----------|-------|
| **File** | `warehouse/receiving/index.php` |
| **URL** | `/cargochina/warehouse/receiving/` |
| **Purpose** | Queue + History tabs; links to receive.php and receipt.php |
| **Access** | WarehouseStaff, SuperAdmin (area_bootstrap) |
| **UI** | Filters (order ID, customer, supplier, dates, shipping code); Queue table; History table; Export CSV |
| **Actions** | Filter queue/history; Receive (link to receive.php?order_id=X); View receipt (link to receipt.php?id=X) |
| **Backend** | `GET /receiving/queue`; `GET /receiving/receipts` (history); `GET /receiving/search` |
| **Script** | `frontend/js/receiving_index.js`, `autocomplete.js` |

---

### 7.9 Receive Order (warehouse/receiving/receive.php)

| Attribute | Value |
|-----------|-------|
| **File** | `warehouse/receiving/receive.php` |
| **URL** | `/cargochina/warehouse/receiving/receive.php?order_id=X` |
| **Purpose** | Dedicated receive form for order X |
| **Access** | WarehouseStaff, SuperAdmin |
| **UI** | Order overview; Actual totals (cartons, CBM, L×W×H, weight, condition, notes); Per-item actuals (if item-level enabled); Evidence photos; Variance results |
| **Actions** | Record receipt |
| **Backend** | `GET /orders/{id}`; `GET /config/receiving`; `POST /orders/{id}/receive` |
| **Script** | `frontend/js/receiving_receive.js`, `photo_uploader.js`, `upload-utils.js` |

---

### 7.10 Receipt Detail (warehouse/receiving/receipt.php)

| Attribute | Value |
|-----------|-------|
| **File** | `warehouse/receiving/receipt.php` |
| **URL** | `/cargochina/warehouse/receiving/receipt.php?id=X` |
| **Purpose** | View receipt details |
| **Access** | WarehouseStaff, SuperAdmin |
| **UI** | Receipt content card |
| **Backend** | `GET /receiving/receipts/{id}` |
| **Script** | `frontend/js/receiving_receipt.js` |

---

### 7.11 Confirmations (confirmations.php)

| Attribute | Value |
|-----------|-------|
| **File** | `confirmations.php` |
| **URL** | `/cargochina/confirmations.php` |
| **Purpose** | List and bulk-confirm orders in AwaitingCustomerConfirmation |
| **Access** | ChinaAdmin, LebanonAdmin, WarehouseStaff, SuperAdmin |
| **UI** | Filters (date, order ID, customer, supplier); Table with checkboxes; Bulk Confirm |
| **Actions** | Filter; Bulk confirm |
| **Backend** | `GET /orders` (status=AwaitingCustomerConfirmation); `POST /orders/{id}/confirm` |
| **DB** | orders, customer_confirmations |
| **Script** | `frontend/js/confirmations.js`, `autocomplete.js` |

---

### 7.12 Pipeline (pipeline.php)

| Attribute | Value |
|-----------|-------|
| **File** | `pipeline.php` |
| **URL** | `/cargochina/pipeline.php` |
| **Purpose** | Stage counts and links (Draft, Submitted, To receive, Await confirm, Ready, Finalized) |
| **Access** | ChinaAdmin, LebanonAdmin, SuperAdmin |
| **UI** | Stage cards with counts; table summary |
| **Backend** | `GET /dashboard/stats` |
| **Script** | `frontend/js/pipeline.js` |

---

### 7.13 Consolidation (consolidation.php)

| Attribute | Value |
|-----------|-------|
| **File** | `consolidation.php` |
| **URL** | `/cargochina/consolidation.php` |
| **Purpose** | Containers + shipment drafts; add/remove orders; assign container; finalize; push |
| **Access** | ChinaAdmin, LebanonAdmin, SuperAdmin |
| **UI** | Ready orders summary; Containers table (+ Add Container if SuperAdmin); Shipment drafts list; Modals: containerModal, draftModal, finalizeConfirmModal, deleteDraftConfirmModal, containerViewModal, statusModal, assignOrdersModal |
| **Actions** | Create container (SuperAdmin); Create draft; Add/remove orders; Assign container; Finalize; Push; Delete draft |
| **Backend** | `GET/POST/PUT /containers`; `GET/POST/PUT/DELETE /shipment-drafts`; `POST add-orders`, `remove-orders`, `assign-container`, `finalize`, `push`, `documents` |
| **DB** | containers, shipment_drafts, shipment_draft_orders, shipment_draft_documents |
| **Script** | `frontend/js/consolidation.js` |

---

### 7.14 Containers (containers.php)

| Attribute | Value |
|-----------|-------|
| **File** | `containers.php` |
| **URL** | `/cargochina/containers.php` |
| **Purpose** | Container list; search; status filter; view orders; assign orders; export |
| **Access** | ChinaAdmin, LebanonAdmin, SuperAdmin |
| **UI** | Search, status filter, fill filter; Table; Modals: containerViewModal, statusModal, assignOrdersModal |
| **Actions** | Search; Filter; View container; Change status; Assign orders; Export |
| **Backend** | `GET /containers`, `/containers/{id}`, `/containers/{id}/orders`; `PUT /containers/{id}`; `POST /containers/{id}/assign-orders`; `GET /containers/{id}/export` |
| **Script** | `frontend/js/containers.js` |

---

### 7.15 Assign to Container (assign_container.php)

| Attribute | Value |
|-----------|-------|
| **File** | `assign_container.php` |
| **URL** | `/cargochina/assign_container.php` |
| **Purpose** | Direct assignment of eligible orders to a container |
| **Access** | ChinaAdmin, LebanonAdmin, SuperAdmin |
| **UI** | Eligible orders table; container select; capacity bars; Assign button |
| **Actions** | Select orders; Select container; Assign |
| **Backend** | `GET /orders` (ReadyForConsolidation, Confirmed); `GET /containers`; `POST /containers/{id}/assign-orders` |
| **Script** | `frontend/js/assign_container.js` |

---

### 7.16 Notifications (notifications.php)

| Attribute | Value |
|-----------|-------|
| **File** | `notifications.php` |
| **URL** | `/cargochina/notifications.php` |
| **Purpose** | List notifications; mark read |
| **Access** | All authenticated (no page guard) |
| **UI** | List |
| **Backend** | `GET /notifications`; `POST /notifications/{id}/read` |
| **Script** | `frontend/js/notifications.js` |

---

### 7.17 Notification Preferences (notification_preferences.php)

| Attribute | Value |
|-----------|-------|
| **File** | `notification_preferences.php` |
| **URL** | `/cargochina/notification_preferences.php` |
| **Purpose** | Toggle channels (dashboard, email, whatsapp) per event |
| **Access** | All authenticated |
| **UI** | Table (event × channel); Save |
| **Backend** | `GET /notification-preferences`; `PUT /notification-preferences` |
| **Script** | `frontend/js/notification_preferences.js` |

---

### 7.18 Configuration (admin_config.php)

| Attribute | Value |
|-----------|-------|
| **File** | `admin_config.php` |
| **URL** | `/cargochina/admin_config.php` |
| **Purpose** | System configuration (variance, confirmation, email, WhatsApp, tracking, stale alerts) |
| **Access** | SuperAdmin |
| **UI** | Form: variance thresholds, confirmation required, photo visibility, min photos, notification channels, item-level receiving, email, WhatsApp (generic/twilio), retry, tracking API, stale threshold, app URL |
| **Actions** | Save |
| **Backend** | `GET /config`; `PUT /config` |
| **DB** | system_config |
| **Script** | `frontend/js/admin_config.js` |

---

### 7.19 Users (admin_users.php)

| Attribute | Value |
|-----------|-------|
| **File** | `admin_users.php` |
| **URL** | `/cargochina/admin_users.php` |
| **Purpose** | User management; roles; departments; reset password |
| **Access** | SuperAdmin |
| **UI** | Table; modal: userEditModal, resetPwForm |
| **Actions** | Update user; Reset password |
| **Backend** | `GET /users`, `/roles`, `/departments`; `PUT /users/{id}`; `POST /users/{id}/reset-password` |
| **DB** | users, user_roles, roles, user_departments, departments |
| **Script** | `frontend/js/admin_users.js` |

---

### 7.20 Diagnostics (admin_diagnostics.php)

| Attribute | Value |
|-----------|-------|
| **File** | `admin_diagnostics.php` |
| **URL** | `/cargochina/admin_diagnostics.php` |
| **Purpose** | Config health; notification delivery log; retry failed delivery |
| **Access** | SuperAdmin |
| **UI** | Config health; delivery log table; Retry button |
| **Backend** | `GET /diagnostics/config-health`, `/notification-delivery-log`; `POST /diagnostics/retry-delivery/{id}` |
| **Script** | `frontend/js/admin_diagnostics.js` |

---

### 7.21 Tracking Push Log (admin_tracking_push.php)

| Attribute | Value |
|-----------|-------|
| **File** | `admin_tracking_push.php` |
| **URL** | `/cargochina/admin_tracking_push.php` |
| **Purpose** | View tracking push history; retry failed push |
| **Access** | LebanonAdmin, SuperAdmin |
| **UI** | Table; filters; Retry; error modal |
| **Backend** | `GET /tracking-push-log`; `POST /shipment-drafts/{id}/push` |
| **DB** | tracking_push_log |
| **Script** | `frontend/js/admin_tracking_push.js` |

---

### 7.22 Audit Log (admin_audit_log.php)

| Attribute | Value |
|-----------|-------|
| **File** | `admin_audit_log.php` |
| **URL** | `/cargochina/admin_audit_log.php` |
| **Purpose** | View audit entries with filters |
| **Access** | SuperAdmin, ChinaAdmin |
| **UI** | Filters; table |
| **Backend** | `GET /audit-log` |
| **DB** | audit_log |
| **Script** | `frontend/js/admin_audit_log.js` |

---

### 7.23 Customer Confirmation (confirm.php)

| Attribute | Value |
|-----------|-------|
| **File** | `confirm.php` |
| **URL** | `/cargochina/confirm.php?token=...` |
| **Purpose** | Public page for customer to confirm variance (token-based) |
| **Access** | Public (no auth) |
| **UI** | Order summary; variance banner; actual measurements; photos; Confirm button |
| **Actions** | Load order; Confirm |
| **Backend** | `GET /confirm?token=`; `POST /confirm` (body: `{token}`) |
| **DB** | orders, warehouse_receipts, customer_confirmations, audit_log |
| **Validation** | Token must match; status must be AwaitingCustomerConfirmation |
| **Note** | Token single-use; cleared after confirmation |

---

## 8. Roles and Permissions Matrix

| Role | Dashboard | Orders | Receiving | Confirmations | Pipeline | Consolidation | Containers | Assign | Data (C/S/P) | Notifications | Config | Users | Diagnostics | Tracking Push | Audit |
|------|------------|--------|-----------|---------------|----------|---------------|------------|--------|--------------|---------------|-------|-------|--------------|---------------|------|
| SuperAdmin | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ CRUD | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| ChinaAdmin | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ read | ✓ | ✓ | ✓ | — | — | — | — | ✓ |
| ChinaEmployee | ✓ | ✓* | — | — | — | — | — | — | ✓ | ✓ | — | — | — | — | — |
| LebanonAdmin | ✓ | — | — | ✓ | ✓ | ✓ | ✓ read | ✓ | — | ✓ | — | — | — | ✓ | — |
| WarehouseStaff | ✓ | — | ✓ | ✓ | — | — | — | — | — | ✓ | — | — | — | — | — |
| FieldStaff | ✓ | — | — | — | — | — | — | — | Suppliers | ✓ | — | — | — | — | — |

*ChinaEmployee: create/edit orders, no approve.

**Container CRUD:** SuperAdmin only. **Config, Users, Diagnostics:** SuperAdmin only. **Tracking Push, Finalize:** LebanonAdmin, SuperAdmin.

---

## 9. Core Workflows

### 9.1 Login Flow

1. User visits `login.php`
2. POST email + password
3. Lookup `users` by email, `is_active=1`
4. `password_verify()`
5. Load roles from `user_roles` + `roles`
6. Set `$_SESSION['user_id','user_name','user_roles']`
7. Redirect by role (superadmin/warehouse/buyers/admin)

### 9.2 Order Lifecycle

1. **Draft** → User creates/edits order
2. **Submitted** → `POST /orders/{id}/submit` (ChinaAdmin/Employee)
3. **Approved** → `POST /orders/{id}/approve` (ChinaAdmin, LebanonAdmin, SuperAdmin)
4. **InTransitToWarehouse** (optional)
5. **ReceivedAtWarehouse** → `POST /orders/{id}/receive` (WarehouseStaff)
6. **AwaitingCustomerConfirmation** — if variance exceeds threshold (config)
7. **Confirmed** → Customer token or `POST /orders/{id}/confirm` (admin)
8. **ReadyForConsolidation**
9. **ConsolidatedIntoShipmentDraft** — add to draft
10. **AssignedToContainer** — assign draft to container
11. **FinalizedAndPushedToTracking** — finalize + push (LebanonAdmin, SuperAdmin)

*OrderStateService* enforces valid transitions.

### 9.3 Receiving Flow

1. Warehouse selects order (Approved/InTransit)
2. Enters actual cartons, CBM, weight, condition, notes
3. Optionally per-item actuals (if ITEM_LEVEL_RECEIVING_ENABLED)
4. Photos required if variance or damage
5. `POST /orders/{id}/receive`
6. Backend: variance check → AwaitingCustomerConfirmation or ReadyForConsolidation
7. If variance: notification with confirmation link; customer visits confirm.php?token=...

### 9.4 Consolidation Flow

1. Create shipment draft
2. Add orders (ReadyForConsolidation, Confirmed)
3. Assign container (SuperAdmin creates containers)
4. Finalize draft → pushes to tracking if TRACKING_PUSH_ENABLED
5. Retry push from Tracking Push Log if failed

### 9.5 Import Flow

- Customers, suppliers, products: `POST /{resource}/import` with CSV
- Roles: ChinaAdmin, ChinaEmployee, SuperAdmin

---

## 10. Shared Components and Core Functions

### 10.1 PHP Includes

| File | Purpose |
|------|---------|
| auth_check.php | Session check; redirect to login |
| page_guard.php | requireRoleForPage($roles) |
| layout.php | HTML shell, sidebar, topbar, content wrapper |
| area_layout.php | Sets vars; requires layout.php |
| area_bootstrap.php | Auth + area role check |
| footer.php | Bootstrap JS, app.js, sidebar.js, desc-lang, notif badge, page scripts |

### 10.2 API Helpers (backend/api/helpers.php)

| Function | Purpose |
|----------|---------|
| jsonResponse($data, $status) | Output JSON, exit |
| jsonError($msg, $status, $errors, $requestId) | Error response |
| getAuthUserId() | Session user_id |
| getUserRoles() | Session user_roles |
| hasRole($role), hasAnyRole($roles) | Role checks |
| requireAuth() | 401 if no user |
| requireRole($roles) | 403 if no role |
| logClms($event, $context) | Structured log to logs/clms.log |

### 10.3 Frontend (frontend/js/app.js)

| Export | Purpose |
|--------|---------|
| API_BASE | `/cargochina/api/v1` |
| api(method, path, body) | Fetch wrapper; cache for departments, roles, config/receiving |
| statusLabel(s), statusBadgeClass(s) | Order status display |
| descLang(), descText(item), setDescLang(lang) | Description language (en/cn) |
| uploadFile(file, onProgress) | POST to /upload |

### 10.4 Autocomplete (autocomplete.js)

- Search customers, suppliers, products via `/{resource}/search?q=`
- Used on orders, receiving, confirmations, etc.

---

## 11. Database Interaction Overview

### 11.1 Core Tables

| Table | Purpose |
|-------|---------|
| users, roles, user_roles | Auth, RBAC |
| customers, customer_deposits | Customer master, deposits |
| suppliers, supplier_payments, supplier_interactions | Supplier master, payments |
| products, product_description_entries | Product catalog |
| translations | Translation cache |
| orders, order_items, order_attachments | Orders |
| order_templates, order_template_items | Order templates |
| warehouse_receipts, warehouse_receipt_photos | Receipts |
| warehouse_receipt_items, warehouse_receipt_item_photos | Item-level receipts |
| notifications, notification_delivery_log, user_notification_preferences | Notifications |
| customer_confirmations | Confirmation records |
| containers, shipment_drafts, shipment_draft_orders, shipment_draft_documents | Consolidation |
| tracking_push_log | Push history |
| audit_log | Audit trail |
| system_config | Key-value config |
| departments, user_departments | Departments |

### 11.2 Order Status Values

Draft, Submitted, Approved, InTransitToWarehouse, ReceivedAtWarehouse, AwaitingCustomerConfirmation, Confirmed, ReadyForConsolidation, ConsolidatedIntoShipmentDraft, AssignedToContainer, FinalizedAndPushedToTracking

---

## 12. Findings, Risks, Gaps, and Technical Debt

### 12.1 Duplicate / Redundant Pages

- **Receiving:** Two UIs — root `receiving.php` (List/Calendar/Schedule + inline form) vs `warehouse/receiving/index.php` (Queue/History tabs). Different JS (`receiving.js` vs `receiving_index.js`). Root is more feature-rich (calendar, schedule).
- **Orphan files:** `warehouse/receiving/queue.php`, `warehouse/receiving/history.php` — placeholders that redirect to receiving.php; not linked from index.
- **Area redirects:** buyers/orders, customers, suppliers, products; admin/consolidation; superadmin/users, configuration, diagnostics, tracking-push-log — all redirect to root. Area structure is partially implemented.

### 12.2 Navigation Inconsistency

- Sidebar uses root paths only. Area dashboards link to area paths (e.g. `/warehouse/receiving/`) but some of those redirect to root.
- ROUTES.md describes area routes as primary and root as "legacy to be phased out," but implementation is mixed.

### 12.3 Permission Gaps

- `notifications.php` and `notification_preferences.php` have no page_guard — any authenticated user can access (likely intentional).
- `admin_audit_log.php` allows ChinaAdmin (not just SuperAdmin).

### 12.4 Potential Issues

- **confirm.php:** Uses `GET /confirm?token=` — API path may be `confirm` with token in query; verify routing.
- **API path:** Router uses `path` from URL; handler file is `confirm.php` for resource `confirm`.
- **Base path:** Hardcoded `/cargochina` in many places; may break if deployed at different path.

### 12.5 Missing / Unclear

- No explicit "FieldStaff" area; FieldStaff sees Suppliers in sidebar only.
- LebanonAdmin has no dedicated area dashboard link to buyers/master data (by design).
- Container status values: planning, to_go, on_route, arrived, available (migration 032).

### 12.6 Security Considerations

- Passwords in admin_config (API token, WhatsApp token) — placeholder "Leave blank to keep current" is good; ensure PUT does not overwrite with empty.
- confirm.php is public; token must be unguessable and single-use (implemented).

### 12.7 Recommended Cleanup

1. Consolidate receiving: choose root or warehouse as canonical; remove duplicate.
2. Remove or implement `warehouse/receiving/queue.php`, `history.php`.
3. Decide area vs root strategy: either full area routing or remove area redirects and use root everywhere.
4. Add page_guard to notifications if restricted access is desired.

---

## 13. Assumptions and Areas Needing Verification

| Item | Assumption | Evidence | Confidence | Needs Verification |
|------|------------|----------|------------|---------------------|
| API path for confirm | `path=confirm` maps to handlers/confirm.php | Router uses `$parts[0]` as resource | High | Confirm .htaccess passes path correctly |
| FieldStaff scope | Suppliers only | layout.php: isFieldStaff shows Suppliers | Medium | Confirm with product owner |
| Area vs root | Root is primary; area is partial | Many redirects; sidebar root | Medium | Align with migration plan |
| Container create | SuperAdmin only | rbac.php containers write | High | — |
| Notification badge | All pages | footer.php fetch | High | — |

---

## Menu-to-Page Map

| Sidebar Section | Menu Item | Destination | Visible For | Purpose |
|-----------------|------------|-------------|-------------|---------|
| Main | Dashboard | /index.php | All | Central dashboard |
| Main | Orders | /orders.php | Buyer | Order CRUD, submit, approve |
| Main | Receiving | /receiving.php | Warehouse | Warehouse receiving |
| Main | Confirmations | /confirmations.php | Admin, Warehouse | Bulk confirm variance |
| Main | Pipeline | /pipeline.php | Admin | Stage overview |
| Main | Consolidation | /consolidation.php | Admin | Drafts, containers |
| Main | Containers | /containers.php | Admin | Container management |
| Main | Assign to Container | /assign_container.php | Admin | Direct order→container |
| Data | Suppliers | /suppliers.php | Buyer, FieldStaff | Supplier master |
| Data | Customers | /customers.php | Buyer | Customer master |
| Data | Products | /products.php | Buyer | Product catalog |
| Notifications | Notifications | /notifications.php | All | Notification list |
| Notifications | Preferences | /notification_preferences.php | All | Channel toggles |
| Administration | Configuration | /admin_config.php | SuperAdmin | System config |
| Administration | Users | /admin_users.php | SuperAdmin | User management |
| Administration | Diagnostics | /admin_diagnostics.php | SuperAdmin | Health, delivery log |
| Administration | Tracking Push Log | /admin_tracking_push.php | SuperAdmin | Push history |
| Administration | Audit Log | /admin_audit_log.php | SuperAdmin, ChinaAdmin | Audit trail |

---

## Ready for Next Phase

### System Maturity

CLMS is a functional operations platform with a clear order lifecycle, RBAC, and tracking integration. The codebase is structured with migrations, API, and tests. Areas of duplication and legacy/area mix indicate an in-progress migration.

### Most Critical Weak Areas

1. **Dual receiving UIs** — Confusing; different features (calendar/schedule vs queue/history).
2. **Area vs root inconsistency** — Redirects and mixed entry points.
3. **Orphan placeholder files** — queue.php, history.php in warehouse.

### Modules Most Ready for Improvement

- Orders, customers, suppliers, products — well-defined CRUD and API.
- Consolidation — clear workflow; container management.
- Notifications — configurable channels and preferences.

### Modules Needing Caution

- Receiving — two implementations; variance logic and photo requirements are critical.
- Config — many keys; changes affect behavior system-wide.
- Tracking push — external integration; retry and idempotency matter.

### Best Starting Points for Future Enhancements

1. Unify receiving into one UI (root or warehouse).
2. Complete area routing or remove it.
3. Add integration tests for order lifecycle and confirmation flow.
4. Document API contracts for tracking push.
5. Consider extracting config keys into a schema/doc for maintainability.
