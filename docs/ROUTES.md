# CLMS Routes

Base path: `/cargochina/`

## Authentication

| Route | Description | Auth |
|-------|-------------|------|
| `/cargochina/login.php` | Login form | Public |
| `/cargochina/login.php?logout=1` | Logout | Any |

## Area-Based Routes (Primary Entry Points)

### Warehouse (`/cargochina/warehouse/`)

**Roles:** WarehouseStaff, SuperAdmin

| Route | Description |
|-------|-------------|
| `/cargochina/warehouse/` | Dashboard |
| `/cargochina/warehouse/receiving/` | Receiving (queue + history tabs) |
| `/cargochina/warehouse/receiving/receive.php?order_id=X` | Receive order |
| `/cargochina/warehouse/receiving/receipt.php?id=X` | Receipt detail |

### Buyers (`/cargochina/buyers/`)

**Roles:** ChinaAdmin, SuperAdmin

| Route | Description |
|-------|-------------|
| `/cargochina/buyers/` | Dashboard |
| `/cargochina/buyers/orders.php` | Orders |
| `/cargochina/buyers/customers.php` | Customers (Master Data) |
| `/cargochina/buyers/suppliers.php` | Suppliers (Master Data) |
| `/cargochina/buyers/products.php` | Products (Master Data) |

### Admin (`/cargochina/admin/`)

**Roles:** ChinaAdmin, LebanonAdmin, SuperAdmin

| Route | Description |
|-------|-------------|
| `/cargochina/admin/` | Dashboard |
| `/cargochina/admin/consolidation.php` | Consolidation |
| `/cargochina/admin/notifications.php` | Notifications |
| `/cargochina/admin/notification_preferences.php` | Notification Preferences |

### Super Admin (`/cargochina/superadmin/`)

**Roles:** SuperAdmin only

| Route | Description |
|-------|-------------|
| `/cargochina/superadmin/` | Dashboard |
| `/cargochina/superadmin/users.php` | Users |
| `/cargochina/superadmin/configuration.php` | Configuration |
| `/cargochina/superadmin/diagnostics.php` | Diagnostics |
| `/cargochina/superadmin/tracking-push-log.php` | Tracking Push Log |

## Error Pages

| Route | Description |
|-------|-------------|
| `/cargochina/403.php` | Access Denied (included when user lacks role for area) |

## Legacy Root-Level Routes (To Be Phased Out)

| Route | Description |
|-------|-------------|
| `/cargochina/` | Legacy dashboard |
| `/cargochina/index.php` | Legacy dashboard |
| `/cargochina/orders.php` | Orders |
| `/cargochina/customers.php` | Customers |
| `/cargochina/suppliers.php` | Suppliers |
| `/cargochina/products.php` | Products |
| `/cargochina/receiving.php` | Receiving |
| `/cargochina/consolidation.php` | Consolidation |
| `/cargochina/notifications.php` | Notifications |
| `/cargochina/notification_preferences.php` | Notification preferences |
| `/cargochina/admin_users.php` | Users (admin) |
| `/cargochina/admin_config.php` | Configuration |
| `/cargochina/admin_diagnostics.php` | Diagnostics |
| `/cargochina/admin_tracking_push.php` | Tracking Push Log |

## API

| Route | Description |
|-------|-------------|
| `/cargochina/api/v1/*` | REST API (see API.md) |
