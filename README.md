# CLMS — China Logistics Management System

Salameh Cargo's China Operations platform. Replaces Excel-based workflows with validated, structured data entry and integration with the tracking system.

## Requirements

- PHP 8.0+
- MySQL 8
- XAMPP (or Apache + PHP + MySQL)

## Quick Start

### 1. Database setup

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS clms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Environment

```bash
cp .env.example .env
# Edit .env with your DB credentials
```

### 3. Run migrations

**Windows (XAMPP):**
```batch
run-migrations.bat
```
Or: `c:\xampp\php\php.exe backend/migrations/run.php`

**Linux/Mac:**
```bash
php backend/migrations/run.php
```

> **Note:** If you see "could not find driver", enable the MySQL PDO extension in `php.ini` (XAMPP: uncomment `extension=pdo_mysql`).

### 4. Login

Migration 006 seeds a default SuperAdmin: `admin@salameh.com` / `password`. Log in at `login.php`.

### 5. Run locally

- **XAMPP**: Start Apache and MySQL. Open `http://localhost/cargochina/`
- **PHP built-in server**: `php -S localhost:8080 -t .` then open `http://localhost:8080/`

### 6. Responsive testing

The UI is responsive for tablets (e.g. iPad). Test on iPad or use Chrome DevTools device emulation to verify layouts, scrollable tables, and stacked forms on smaller screens.

## Project Structure

```
cargochina/
├── backend/           # PHP API & business logic
│   ├── config/        # DB, thresholds
│   ├── migrations/    # SQL migrations
│   ├── models/       # Data access
│   ├── services/     # State machine, validation
│   ├── api/          # REST endpoints
│   └── uploads/      # Attachments (gitignored)
├── frontend/          # Web UI (Bootstrap)
├── docs/              # API docs, schema
└── tests/             # Tests
```

## API Base URL

`/api/v1/` — All REST endpoints are versioned under v1.

## Configuration

See `.env.example` for:
- `VARIANCE_THRESHOLD_PERCENT` — % difference to trigger customer confirmation
- `VARIANCE_THRESHOLD_ABS_CBM` — Absolute CBM difference threshold
- `CONFIRMATION_REQUIRED` — variance-only | always-on-arrival
- `CUSTOMER_PHOTO_VISIBILITY` — internal-only | customer-visible

## Operational Customer Visibility

Customer owner/creator visibility rules are intentionally limited to the full customer management surface (`customers.php` and full customer-management API actions). The Customers page is visible to operational users, and operational users can add customers. The records inside the Customers page are filtered by the customer visibility rules: full-visibility roles see all, other users see unassigned legacy customers, their own customers, and any selected creators configured by an admin. Operational workflows such as Orders, Draft an Order, Receiving, Warehouse Stock, Consolidation, Containers, Expenses, Financials, and Balances should show the full operational data allowed by the user's page/module permission.

Customer selectors in operational workflows use the safe lookup API (`/api/v1/customers/lookup` and `/api/v1/customers/{id}/lookup`), which returns only minimal selection fields and must not expose full customer profiles, balances, private notes, contacts, addresses, or payment links.

## Draft Order Excel Import

`procurement_drafts.php` uses `/api/v1/draft-orders/import` as a preview import. The importer reads normalized header names rather than fixed column positions, tolerates missing optional fields, ignores exported subtotal/grand-total rows, reports skipped rows and warnings, and extracts embedded XLSX photos from the detected Photo column when the server supports workbook drawings. The import fills the draft form for review; it does not save the order until the user saves the draft.

## Receiving Excel Import

`receiving.php` uses the same generated procurement import template as Draft an Order, available from the receiving import modal. The preview step reads normalized procurement headers instead of fixed column positions, supports reordered columns, and maps columns such as Customer, Item No, SKU / Item Code, Express Number, Cartons, CBM/Unit or Total CBM, Weight/Unit or Total Weight, and Supplier. When no `Order ID` is supplied, the import is treated as a direct warehouse intake: it creates the order/items, records receiving, and places the goods into warehouse stock through the normal receiving service after final confirmation. If the template has no customer and the user does not select one in the modal, the import assigns the receipt to the controlled fallback customer `Direct Warehouse Intake` and shows a warning in preview. If `Order ID` is supplied, it still supports receiving against existing approved/in-transit orders. The final import revalidates the preview token server-side and saves in one transaction.

## SuperAdmin Training Reset

`admin_config.php` includes a SuperAdmin-only Training Data Reset section. It can delete selected training data groups such as draft orders, orders/receiving, containers, customers, suppliers, products, financial rows, expenses, notifications, non-SuperAdmin users, and logs. The reset requires the configured reset password, runs server-side through `/api/v1/config/training-reset`, keeps schema/config/roles/countries/departments intact, always protects SuperAdmin/admin users, and writes an audit row after completion.

## Full Specification

See [CLMS_README.md](CLMS_README.md) for the complete specification, state machine, RBAC, and DB_CHANGELOG.

## Phase 2 Design

See [docs/PHASE2_NOTIFICATION_HARDENING.md](docs/PHASE2_NOTIFICATION_HARDENING.md) for the PR-ready design and implementation plan for item-level receiving, email/WhatsApp notifications, and open-question resolutions.
